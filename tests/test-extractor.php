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

// --- Regression tests for wrong or lost price data. ---

// 1. Three-decimal currencies: "1.234" in machine-readable data is 1.234, not 1234.
$r = RPW_Extractor::extract( '<html><head><meta property="product:price:amount" content="1.234"><meta property="product:price:currency" content="KWD"></head></html>' );
rpw_eq( 1.234, $r['price'], 'meta 1.234 KWD stays decimal' );
rpw_eq( 'KWD', $r['currency'], 'meta KWD currency' );
$r = RPW_Extractor::extract( '<div itemscope itemtype="https://schema.org/Offer"><span itemprop="price" content="1.234">1.234</span><meta itemprop="priceCurrency" content="KWD"></div>' );
rpw_eq( 1.234, $r['price'], 'microdata content 1.234 stays decimal' );
$r = RPW_Extractor::extract( '<script type="application/ld+json">{"@type":"Product","offers":{"@type":"Offer","price":"1.234","priceCurrency":"KWD"}}</script>' );
rpw_eq( 1.234, $r['price'], 'json-ld "1.234" stays decimal' );
rpw_eq( 1299000.0, RPW_Extractor::normalize_number( '1.299.000', true ), 'machine form 1.299.000 is thousands' );
rpw_eq( 1234.0, RPW_Extractor::normalize_number( '1.234' ), 'text for people: 1.234 is thousands' );
$r = RPW_Extractor::extract( '<div itemscope itemtype="https://schema.org/Offer"><span itemprop="price">۱٬۲۵۰٬۰۰۰</span></div>' );
rpw_eq( 1250000.0, $r['price'], 'microdata text price still read as written for people' );

// 2. A nested offer keeps its currency and stock status.
$nested = '<script type="application/ld+json">{"@type":"Product","name":"Phone","offers":{"@type":"AggregateOffer","priceCurrency":"EUR","offers":[{"@type":"Offer","price":"499","availability":"https://schema.org/OutOfStock"}]}}</script>';
$r      = RPW_Extractor::extract( $nested );
rpw_eq( 499.0, $r['price'], 'nested offer price' );
rpw_eq( 'EUR', $r['currency'], 'nested offer inherits parent currency' );
rpw_eq( false, $r['in_stock'], 'nested offer keeps out-of-stock' );
$nested2 = '<script type="application/ld+json">{"@type":"Product","offers":{"@type":"AggregateOffer","offers":[{"@type":"Offer","price":"10","priceCurrency":"USD","availability":"InStock"}]}}</script>';
$r       = RPW_Extractor::extract( $nested2 );
rpw_eq( 'USD', $r['currency'], 'nested offer own currency' );
rpw_eq( true, $r['in_stock'], 'nested offer own stock status' );

// 3. Currency meta written with name= instead of property=.
$r = RPW_Extractor::extract( '<meta name="product:price:amount" content="349.00"><meta name="product:price:currency" content="usd">' );
rpw_eq( 349.0, $r['price'], 'meta name= price' );
rpw_eq( 'USD', $r['currency'], 'meta name= currency' );
$r = RPW_Extractor::extract( '<meta property="og:price:amount" content="20"><meta name="og:price:currency" content="GBP"><meta property="product:availability" content="out of stock">' );
rpw_eq( 'GBP', $r['currency'], 'og currency with name=' );
rpw_eq( false, $r['in_stock'], 'meta availability' );

// 4. Variants: without a hint the first product is used; with a hint, the matching offer.
$variants = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"ProductGroup","name":"Galaxy S30","hasVariant":['
	. '{"@type":"Product","name":"Galaxy S30 128GB Black","sku":"S30-128-BK","gtin13":"8800000000011","color":"Black","offers":{"@type":"Offer","price":"700","priceCurrency":"EUR","availability":"InStock"}},'
	. '{"@type":"Product","name":"Galaxy S30 256GB Black","sku":"S30-256-BK","color":"Black","offers":{"@type":"Offer","price":"800","priceCurrency":"EUR","availability":"InStock"}},'
	. '{"@type":"Product","sku":"S30-256-BL","color":"Blue","size":"256GB","offers":{"@type":"Offer","price":"820","priceCurrency":"EUR","availability":"OutOfStock"}}'
	. ']}</script>'
	. '<script type="application/ld+json">{"@type":"Product","name":"Case for Galaxy S30","sku":"CASE-1","offers":{"@type":"Offer","price":"15","priceCurrency":"EUR"}}</script>';
$r        = RPW_Extractor::extract( $variants );
rpw_eq( 700.0, $r['price'], 'no hint: first product on the page' );
rpw_eq( 4, $r['candidates'], 'all offers are counted' );
rpw_eq( null, $r['matched'], 'no hint: matched is null' );
$r = RPW_Extractor::extract( $variants, 'S30-256-BK' );
rpw_eq( 800.0, $r['price'], 'hint by SKU' );
rpw_eq( true, $r['matched'], 'hint by SKU matched' );
rpw_eq( 'S30-256-BK', $r['sku'], 'matched SKU reported' );
$r = RPW_Extractor::extract( $variants, '8800000000011' );
rpw_eq( 700.0, $r['price'], 'hint by GTIN' );
$r = RPW_Extractor::extract( $variants, '256gb BLUE' );
rpw_eq( 820.0, $r['price'], 'hint by words (size + colour, any case)' );
rpw_eq( false, $r['in_stock'], 'matched variant stock status' );
rpw_eq( 'Galaxy S30', $r['name'], 'variant without a name takes the group name' );
$r = RPW_Extractor::extract( $variants, '۲۵۶gb black' );
rpw_eq( 800.0, $r['price'], 'hint with Persian digits' );
$r = RPW_Extractor::extract( $variants, '512GB' );
rpw_eq( null, $r['price'], 'hint that matches nothing gives no price' );
rpw_eq( false, $r['matched'], 'hint that matches nothing: matched is false' );
$r = RPW_Extractor::extract( '<meta property="og:price:amount" content="20">', '256GB' );
rpw_eq( 20.0, $r['price'], 'meta price with a hint is still read' );
rpw_eq( null, $r['matched'], 'meta price cannot be verified against a hint' );

// Offers with their own names inside one product.
$offers = '<script type="application/ld+json">{"@type":"Product","name":"Tablet","offers":[{"@type":"Offer","name":"64 GB","sku":"T-64","price":"300","priceCurrency":"USD"},{"@type":"Offer","name":"128 GB","sku":"T-128","price":"380","priceCurrency":"USD"}]}</script>';
rpw_eq( 300.0, RPW_Extractor::extract( $offers )['price'], 'offers of one product: lowest without a hint' );
rpw_eq( 380.0, RPW_Extractor::extract( $offers, 'tablet 128' )['price'], 'offer name and product name both match' );

// Odd data must not break the reader.
rpw_eq( null, RPW_Extractor::extract( '<script type="application/ld+json">{"@type":["Product"],"offers":{"price":{"x":1},"priceCurrency":["USD"]}}</script>' )['price'], 'non-scalar price ignored' );
rpw_eq( 5.0, RPW_Extractor::extract( '<script type="application/ld+json">{"@type":"WebPage","mainEntity":{"@type":"Product","offers":{"price":5}}}</script>' )['price'], 'product inside mainEntity' );

echo "\n{$count} checks, {$failures} failed\n";
exit( $failures ? 1 : 0 );
