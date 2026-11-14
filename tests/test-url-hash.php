<?php
/**
 * Tests for link identity (RPW_DB::hash). Run: php tests/test-url-hash.php
 *
 * @package RivalPriceWatch
 */

define( 'RPW_TESTING', true );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' ); // Only so the class file loads; no WordPress function is called here.
}
require __DIR__ . '/../includes/class-rpw-db.php';

$failures = 0;
$count    = 0;

/**
 * Assert a condition.
 *
 * @param bool   $ok   Condition.
 * @param string $name Test name.
 */
function rpw_ok( $ok, $name ) {
	global $failures, $count;
	++$count;
	if ( $ok ) {
		echo "ok   {$name}\n";
		return;
	}
	++$failures;
	echo "FAIL {$name}\n";
}

$h = array( 'RPW_DB', 'hash' );

rpw_ok( $h( 'https://shop.example/p/AbC' ) !== $h( 'https://shop.example/p/abc' ), 'paths that differ only by case are different links' );
rpw_ok( $h( 'https://shop.example/p?id=XyZ' ) !== $h( 'https://shop.example/p?id=xyz' ), 'query values that differ only by case are different links' );
rpw_ok( $h( 'HTTPS://Shop.Example/p/AbC' ) === $h( 'https://shop.example/p/AbC' ), 'scheme and host are not case-sensitive' );
rpw_ok( $h( '  https://shop.example/p/1  ' ) === $h( 'https://shop.example/p/1' ), 'spaces around the link are ignored' );
rpw_ok( $h( 'https://SHOP.example' ) === $h( 'https://shop.example' ), 'host only' );
rpw_ok( $h( 'https://shop.example/a#Top' ) !== $h( 'https://shop.example/a#top' ), 'fragment case is kept' );
rpw_ok( RPW_DB::legacy_hash( 'https://shop.example/p/AbC' ) === md5( 'https://shop.example/p/abc' ), 'old hash is still available for upgrading' );
rpw_ok( 32 === strlen( $h( 'not a url' ) ), 'anything gives a hash' );

echo "\n{$count} checks, {$failures} failed\n";
exit( $failures ? 1 : 0 );
