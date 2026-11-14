<?php
/**
 * Tests for RPW_Extractor. Run: php tests/test-extractor.php
 *
 * @package RivalPriceWatch
 */

define( 'RPW_TESTING', true );
require __DIR__ . '/../includes/class-rpw-extractor.php';

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

// Number parsing.
$numbers = array(
	array( '1,299.99', 1299.99 ),
	array( '1.299,99 €', 1299.99 ),
	array( '1.299.000', 1299000.0 ),
	array( '12,500,000 تومان', 12500000.0 ),
	array( '۱۲٬۵۰۰٬۰۰۰ تومان', 12500000.0 ),
	array( '٤٥٫٥', 45.5 ),
	array( '$ 49', 49.0 ),
	array( '99,90', 99.9 ),
	array( '1 299,00', 1299.0 ),
	array( "CHF 1'299.50", 1299.5 ),
	array( 'free', null ),
);
foreach ( $numbers as $n ) {
	rpw_eq( $n[1], RPW_Extractor::normalize_number( $n[0] ), 'number ' . $n[0] );
}
rpw_eq( 1299.0, RPW_Extractor::normalize_number( '1299.00', true ), 'machine form 1299.00' );
rpw_eq( 1.299, RPW_Extractor::normalize_number( '1.299', true ), 'machine form 1.299 stays decimal' );

// Page fixtures.
$cases = array(
	'jsonld-basic.html'     => array( 129.99, 'USD', true, 'json-ld' ),
	'jsonld-graph.html'     => array( 45900000.0, 'IRR', true, 'json-ld' ),
	'jsonld-offers.html'    => array( 899.0, 'EUR', true, 'json-ld' ),
	'jsonld-aggregate.html' => array( 19.5, 'GBP', null, 'json-ld' ),
	'meta-og.html'          => array( 349.0, 'USD', null, 'meta' ),
	'microdata.html'        => array( 2499000.0, 'IRR', false, 'microdata' ),
	'none.html'             => array( null, null, null, 'none' ),
	'iran-toman-labelled-irr.html' => array( 365165000.0, 'IRT', null, 'json-ld+unit-from-page' ),
	'iran-real-rial.html'   => array( 24990000.0, 'IRR', null, 'json-ld' ),
	'iran-no-currency.html' => array( 12500000.0, 'IRT', null, 'meta+unit-from-page' ),
);
foreach ( $cases as $file => $want ) {
	$r = RPW_Extractor::extract( file_get_contents( __DIR__ . '/fixtures/' . $file ) );
	rpw_eq( $want[0], $r['price'], "$file price" );
	rpw_eq( $want[1], $r['currency'], "$file currency" );
	rpw_eq( $want[2], $r['in_stock'], "$file in_stock" );
	rpw_eq( $want[3], $r['source'], "$file source" );
}
rpw_eq( null, RPW_Extractor::extract( '' )['price'], 'empty html' );

echo "\n{$count} checks, {$failures} failed\n";
exit( $failures ? 1 : 0 );
