<?php
/**
 * Tests for RPW_Currency conversion and detection. Run: php tests/test-currency.php
 *
 * @package RivalPriceWatch
 */

define( 'RPW_TESTING', true );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' ); // Only so the class files load; no WordPress is needed.
}
// Minimal stand-in for the WordPress translation function used in name().
if ( ! function_exists( '__' ) ) {
	/**
	 * Return text unchanged.
	 *
	 * @param string $t Text.
	 * @return string
	 */
	function __( $t ) {
		return $t;
	}
}
require __DIR__ . '/../includes/class-rpw-extractor.php';
require __DIR__ . '/../includes/class-rpw-currency.php';

$failures = 0;
$count    = 0;

/**
 * Assert equal.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $name     Test name.
 */
function rpw_eq( $expected, $actual, $name ) {
	global $failures, $count;
	++$count;
	if ( $expected === $actual || ( is_float( $expected ) && is_float( $actual ) && abs( $expected - $actual ) < 0.0001 ) ) {
		echo "ok   {$name}\n";
		return;
	}
	++$failures;
	echo "FAIL {$name}: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

// Detection.
$detect = array(
	'usd'    => 'USD',
	'€'      => 'EUR',
	'£'      => 'GBP',
	'تومان'  => 'IRT',
	'ریال'   => 'IRR',
	'Toman'  => 'IRT',
	'IRR'    => 'IRR',
	' aed '  => 'AED',
	'TL'     => 'TRY',
	''       => null,
	'dollar' => null,
);
foreach ( $detect as $in => $want ) {
	rpw_eq( $want, RPW_Currency::normalize( $in ), 'detect "' . $in . '"' );
}
rpw_eq( null, RPW_Currency::normalize( null ), 'detect null' );

// Conversion. Rates: units of the shop currency per 1 unit of the source.
$toman_rates = array(
	'USD' => 105000.0,
	'EUR' => 118000.0,
	'AED' => 28600.0,
);
rpw_eq( 4590000.0, RPW_Currency::convert( 45900000, 'IRR', 'IRT', array() ), 'rial to toman, no rate needed' );
rpw_eq( 450.0, RPW_Currency::convert( 45, 'IRT', 'IRR', array() ), 'toman to rial, no rate needed' );
rpw_eq( 105000000.0, RPW_Currency::convert( 1000, 'USD', 'IRT', $toman_rates ), 'USD to toman' );
rpw_eq( 286000.0, RPW_Currency::convert( 10, 'AED', 'IRT', $toman_rates ), 'AED to toman' );
rpw_eq( null, RPW_Currency::convert( 10, 'GBP', 'IRT', $toman_rates ), 'GBP without rate gives null' );
rpw_eq( 99.0, RPW_Currency::convert( 99, null, 'EUR', array() ), 'unknown currency assumed shop currency' );
rpw_eq( 99.0, RPW_Currency::convert( 99, 'eur', 'EUR', array() ), 'same currency, lower case' );
rpw_eq( 1.0, round( RPW_Currency::convert( 1050000, 'IRR', 'USD', array( 'IRT' => 1 / 105000 ) ), 6 ), 'rial to USD via a toman rate' );
rpw_eq( 92.0, RPW_Currency::convert( 100, 'USD', 'EUR', array( 'USD' => 0.92 ) ), 'USD to EUR' );

rpw_eq( 45, count( RPW_Currency::codes() ), '45 currencies listed' );

// Typed rates.
$typed = array(
	'105,000'   => 105000.0,
	'۱۰۵٬۰۰۰'   => 105000.0,
	'0.92'      => 0.92,
	'1.105'     => 1.105,
	'0,92'      => 0.92,
	'1.250.000' => 1250000.0,
	'abc'       => null,
);
foreach ( $typed as $in => $want ) {
	rpw_eq( $want, RPW_Currency::parse_rate( (string) $in ), 'rate "' . $in . '"' );
}
rpw_eq( 0.00000952, RPW_Currency::parse_rate( 0.00000952 ), 'rate float' );

// Rate display keeps precision for tiny and big rates, and reads back the same.
$fmt = array(
	array( 0.0000215, '0.0000215' ),
	array( 0.000005, '0.000005' ),
	array( 105000.0, '105000' ),
	array( 0.903491, '0.903491' ),
	array( 1.1964407, '1.1964407' ),
	array( 0.00000002381234567, '0.00000002381234567' ),
);
foreach ( $fmt as $f ) {
	rpw_eq( $f[1], RPW_Currency::format_rate( $f[0] ), 'format rate ' . $f[1] );
	rpw_eq( $f[0], RPW_Currency::parse_rate( RPW_Currency::format_rate( $f[0] ) ), 'round trip ' . $f[1] );
}

echo "\n{$count} checks, {$failures} failed\n";
exit( $failures ? 1 : 0 );
