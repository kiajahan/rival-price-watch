<?php
/**
 * Reads a product price from a competitor's product page HTML.
 *
 * Plain PHP with no WordPress calls, so it can be unit-tested on its own.
 *
 * @package RivalPriceWatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Price extractor.
 *
 * Looks, in order, at:
 * 1. schema.org Product data in JSON-LD (what Google uses for rich results),
 * 2. Open Graph / product meta tags (product:price:amount, og:price:amount),
 * 3. schema.org microdata (itemprop="price").
 *
 * When a page lists several offers or variants, pass a variant hint to extract()
 * so the price of the right one is read.
 */
class RPW_Extractor {

	/**
	 * Extract a price from HTML.
	 *
	 * @param string $html  Page HTML.
	 * @param string $match Optional variant hint: a SKU / GTIN / MPN, or words that must all
	 *                      appear in the offer (for example "256GB black"). Only JSON-LD can be
	 *                      matched; for meta tags and microdata "matched" stays null (not verified).
	 * @param string $currency Optional currency the link is expected in. When a page lists offers in
	 *                         several currencies, only offers in this one are compared.
	 * @return array{price: float|null, currency: string|null, in_stock: bool|null, source: string, name: string, sku: string, candidates: int, matched: bool|null}
	 */
	public static function extract( $html, $match = '', $currency = '' ) {
		$result = self::extract_structured( $html, (string) $match, (string) $currency );
		if ( null !== $result['price'] && in_array( $result['currency'], array( 'IRR', 'IRT', null ), true ) ) {
			$shown = self::shown_iranian_unit( $html, $result['price'] );
			if ( $shown && $shown !== $result['currency'] ) {
				$result['currency'] = $shown;
				$result['source']  .= '+unit-from-page';
			}
		}
		return $result;
	}

	/**
	 * Many Iranian shops label toman prices as "IRR" in their product data.
	 * Look for the same number on the page and read the unit written after it.
	 *
	 * @param string $html  Page HTML.
	 * @param float  $price Price from structured data.
	 * @return string|null 'IRT', 'IRR', or null when the page does not say.
	 */
	public static function shown_iranian_unit( $html, $price ) {
		if ( $price <= 0 || floor( $price ) !== (float) $price ) {
			return null;
		}
		$want = number_format( $price, 0, '.', '' );

		// Visible text only: drop scripts, styles and tags (class names often contain digits).
		$text = preg_replace( '#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html );
		$text = preg_replace( '/<[^>]*>/', ' ', (string) $text );
		$text = preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );

		$toman = 0;
		$rial  = 0;
		// Any whole number (grouped with , ٬ . or spaces, or not grouped; Latin, Persian or Arabic
		// digits), then a few characters with no other digits, then the unit word.
		$d  = '[0-9۰-۹٠-٩]';
		$re = '/(?<![0-9۰-۹٠-٩])(' . $d . '{1,3}(?:[,٬.\x{066C}\s\x{00A0}\x{202F}]' . $d . '{3})+|' . $d . '+)(?![0-9۰-۹٠-٩])[^0-9۰-۹٠-٩]{0,40}?(تومان|ریال)/u';
		if ( preg_match_all( $re, (string) $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$digits = preg_replace( '/[^0-9]/', '', self::fold( $hit[1] ) );
				if ( ltrim( $digits, '0' ) !== $want ) {
					continue;
				}
				if ( 'تومان' === $hit[2] ) {
					++$toman;
				} else {
					++$rial;
				}
			}
		}
		if ( $toman > $rial ) {
			return 'IRT';
		}
		if ( $rial > $toman ) {
			return 'IRR';
		}
		return null;
	}

	/**
	 * Empty result.
	 *
	 * @return array
	 */
	private static function blank() {
		return array(
			'price'      => null,
			'currency'   => null,
			'in_stock'   => null,
			'source'     => 'none',
			'name'       => '',
			'sku'        => '',
			'candidates' => 0,
			'matched'    => null,
		);
	}

	/**
	 * Read price data from JSON-LD, meta tags or microdata.
	 *
	 * @param string $html  Page HTML.
	 * @param string $match    Optional variant hint (SKU, GTIN, or words such as "256GB black").
	 * @param string $currency Optional preferred currency code.
	 * @return array See extract().
	 */
	private static function extract_structured( $html, $match = '', $currency = '' ) {
		$result = self::blank();
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return $result;
		}

		// 1. JSON-LD.
		$candidates = self::jsonld_candidates( $html );
		if ( $candidates ) {
			return self::choose( $candidates, $match, $currency );
		}

		$dom = self::load_dom( $html );
		if ( ! $dom ) {
			return $result;
		}
		$xpath = new DOMXPath( $dom );

		// 2. Meta tags. The currency is read from the same family (product: or og:) first.
		foreach ( array( 'product', 'og' ) as $family ) {
			$q = sprintf( '//meta[@property="%1$s:price:amount" or @name="%1$s:price:amount"]/@content', $family );
			foreach ( $xpath->query( $q ) as $attr ) {
				$price = self::normalize_number( $attr->value, true );
				if ( null === $price || $price <= 0 ) {
					continue;
				}
				$result['price']      = $price;
				$result['source']     = 'meta';
				$result['candidates'] = 1;
				foreach ( array( $family, 'product' === $family ? 'og' : 'product' ) as $f ) {
					$cur = $xpath->query( sprintf( '//meta[@property="%1$s:price:currency" or @name="%1$s:price:currency"]/@content', $f ) );
					if ( $cur->length && '' !== trim( $cur->item( 0 )->value ) ) {
						$result['currency'] = strtoupper( trim( $cur->item( 0 )->value ) );
						break;
					}
				}
				$avail = $xpath->query( '//meta[@property="product:availability" or @name="product:availability" or @property="og:availability" or @name="og:availability"]/@content' );
				if ( $avail->length ) {
					$result['in_stock'] = self::availability_in_stock( str_replace( array( ' ', '_', '-' ), '', $avail->item( 0 )->value ) );
				}
				$title = $xpath->query( '//meta[@property="og:title" or @name="og:title"]/@content' );
				if ( $title->length ) {
					$result['name'] = trim( $title->item( 0 )->value );
				}
				// A variant hint can only be checked against the page title here.
				if ( '' !== trim( $match ) ) {
					if ( '' === $result['name'] ) {
						$result['matched'] = null;
					} else {
						$result['matched'] = self::matches( array_merge( self::no_identity(), array( 'name' => $result['name'] ) ), $match );
						if ( ! $result['matched'] ) {
							$result['price']    = null;
							$result['currency'] = null;
							$result['in_stock'] = null;
						}
					}
				}
				return $result;
			}
		}

		// 3. Microdata. Every value is read from the price's own Offer or an item that
		// contains it, never from another offer on the page. The page is read in one
		// pass (each property is filed under the item it belongs to), so a page with
		// thousands of offers cannot make a check slow.
		$wanted = array_fill_keys( array( 'priceCurrency', 'availability', 'name', 'sku', 'gtin', 'gtin14', 'gtin13', 'gtin12', 'gtin8', 'isbn', 'mpn', 'color', 'size' ), true );
		$props  = array(); // Item key => property => first non-empty value.
		$seen   = 0;
		foreach ( $xpath->query( '//*[@itemprop]' ) as $node ) {
			if ( ++$seen > self::MAX_MICRODATA_PROPS ) {
				break;
			}
			$prop = trim( $node->getAttribute( 'itemprop' ) );
			if ( ! isset( $wanted[ $prop ] ) ) {
				continue;
			}
			$key = self::item_key( self::owner_item( $node ) );
			if ( isset( $props[ $key ][ $prop ] ) ) {
				continue;
			}
			$v = self::prop_value( $node );
			if ( '' !== $v ) {
				$props[ $key ][ $prop ] = $v;
			}
		}

		$candidates = array();
		$roots      = array();
		foreach ( $xpath->query( '//*[@itemprop="price"]' ) as $el ) {
			if ( count( $candidates ) >= self::MAX_OFFERS ) {
				break;
			}
			$price = $el->hasAttribute( 'content' ) ? self::normalize_number( $el->getAttribute( 'content' ), true ) : self::normalize_number( $el->textContent );
			if ( null === $price || $price <= 0 ) {
				continue;
			}
			// Items around the price, nearest first; with none, the page itself.
			$keys = array();
			for ( $item = self::owner_item( $el ); $item; $item = self::owner_item( $item ) ) {
				$keys[] = self::item_key( $item );
			}
			$root = $keys ? end( $keys ) : '';
			if ( ! $keys ) {
				$keys[] = self::item_key( null );
			}
			$pick = static function ( array $names ) use ( $props, $keys ) {
				foreach ( $names as $n ) {
					foreach ( $keys as $k ) {
						if ( isset( $props[ $k ][ $n ] ) ) {
							return $props[ $k ][ $n ];
						}
					}
				}
				return '';
			};
			// The offer's own currency. Kept apart from $currency (the link's chosen currency).
			$c         = self::no_identity();
			$offer_cur = $pick( array( 'priceCurrency' ) );
			$avail     = $pick( array( 'availability' ) );
			$names    = array();
			foreach ( array_reverse( $keys ) as $k ) {
				if ( isset( $props[ $k ]['name'] ) && ! in_array( $props[ $k ]['name'], $names, true ) ) {
					$names[] = $props[ $k ]['name'];
				}
			}
			$c['name']  = implode( ' ', $names );
			$c['sku']   = $pick( array( 'sku' ) );
			$c['gtin']  = $pick( array( 'gtin', 'gtin14', 'gtin13', 'gtin12', 'gtin8', 'isbn' ) );
			$c['mpn']   = $pick( array( 'mpn' ) );
			$c['color'] = $pick( array( 'color' ) );
			$c['size']  = $pick( array( 'size' ) );

			// Offers inside the same top-level item belong to one product.
			if ( '' !== $root && ! isset( $roots[ $root ] ) ) {
				$roots[ $root ] = count( $roots );
			}

			$candidates[] = array_merge(
				$c,
				array(
					'price'    => $price,
					'currency' => '' !== $offer_cur ? strtoupper( $offer_cur ) : null,
					'in_stock' => '' !== $avail ? self::availability_in_stock( $avail ) : null,
					'group'    => '' === $root ? -1 - count( $candidates ) : $roots[ $root ], // A price outside every item is its own product.
				)
			);
		}
		if ( $candidates ) {
			$identified = false;
			foreach ( $candidates as $c ) {
				if ( '' !== $c['name'] . $c['sku'] . $c['gtin'] . $c['mpn'] . $c['color'] . $c['size'] ) {
					$identified = true;
					break;
				}
			}
			if ( '' !== trim( $match ) && ! $identified ) {
				// Nothing on the page describes the offers: the hint cannot be checked.
				$result            = self::choose( $candidates, '', $currency, 'microdata' );
				$result['matched'] = null;
				return $result;
			}
			return self::choose( $candidates, $match, $currency, 'microdata' );
		}

		return $result;
	}

	/**
	 * At most this many priced offers are read from microdata, and at most this many
	 * microdata properties are looked at. Real product pages stay far below both;
	 * the limits keep a huge or hostile page from making a check slow.
	 */
	const MAX_OFFERS          = 500;
	const MAX_MICRODATA_PROPS = 20000;

	/**
	 * The item (element with itemscope) a node belongs to: its nearest ancestor item.
	 *
	 * @param DOMNode $node Node.
	 * @return DOMElement|null Null when the node is outside every item.
	 */
	private static function owner_item( $node ) {
		for ( $p = $node->parentNode; $p instanceof DOMElement; $p = $p->parentNode ) {
			if ( $p->hasAttribute( 'itemscope' ) ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * A key that identifies an item element ('' for the page outside every item).
	 *
	 * @param DOMElement|null $item Item.
	 * @return string
	 */
	private static function item_key( $item ) {
		return $item ? (string) $item->getNodePath() : '';
	}

	/**
	 * An offer with no identifying details.
	 *
	 * @return array<string, string>
	 */
	private static function no_identity() {
		return array(
			'name'  => '',
			'sku'   => '',
			'gtin'  => '',
			'mpn'   => '',
			'color' => '',
			'size'  => '',
			'url'   => '',
		);
	}

	/**
	 * Trimmed text, at most 300 characters (never cut inside a UTF-8 character).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function cut( $s ) {
		$s = trim( (string) $s );
		if ( strlen( $s ) <= 300 ) {
			return $s;
		}
		return trim( function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 300, 'UTF-8' ) : (string) preg_replace( '/^(.{0,300}).*$/su', '$1', $s ) );
	}

	/**
	 * Value of a microdata property element (text values are cut to 300 characters).
	 *
	 * @param DOMElement $node Element.
	 * @return string
	 */
	private static function prop_value( $node ) {
		foreach ( array( 'content', 'href' ) as $attr ) {
			if ( $node->hasAttribute( $attr ) ) {
				return self::cut( $node->getAttribute( $attr ) );
			}
		}
		return self::cut( $node->textContent );
	}

	/**
	 * Every priced offer published in the page's JSON-LD.
	 *
	 * Read with a fast text scan so big pages are not parsed into a DOM.
	 *
	 * @param string $html Page HTML.
	 * @return array<int, array> Offers with price, currency, in_stock, name, sku, gtin, mpn, color, size, url, group.
	 */
	public static function jsonld_candidates( $html ) {
		$out   = array();
		$group = 0;
		if ( ! preg_match_all( '#<script\b[^>]*type\s*=\s*["\']?application/ld\+json["\']?[^>]*>(.*?)</script>#is', (string) $html, $blocks ) ) {
			return $out;
		}
		foreach ( $blocks[1] as $json ) {
			$json = trim( $json );
			// Some sites wrap JSON-LD in HTML comments or CDATA.
			$json = preg_replace( '#^(<!--|/\*<!\[CDATA\[\*/|<!\[CDATA\[)|(-->|/\*\]\]>\*/|\]\]>)$#', '', $json );
			$data = json_decode( trim( (string) $json ), true );
			if ( null === $data ) {
				$data = json_decode( html_entity_decode( trim( (string) $json ), ENT_QUOTES, 'UTF-8' ), true );
			}
			if ( null !== $data ) {
				self::walk( $data, $out, $group, array(), 0 );
			}
		}
		return $out;
	}

	/**
	 * Is this a JSON list (keys 0..n)?
	 *
	 * @param array $a Array.
	 * @return bool
	 */
	private static function is_list( array $a ) {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	/**
	 * A scalar JSON value as trimmed text ('' for arrays and missing values).
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function text( $v ) {
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	}

	/**
	 * Identifying details of a Product or Offer node.
	 *
	 * @param array $node JSON-LD node.
	 * @return array<string, string>
	 */
	private static function identity( array $node ) {
		$gtin = '';
		foreach ( array( 'gtin', 'gtin14', 'gtin13', 'gtin12', 'gtin8', 'isbn' ) as $k ) {
			if ( isset( $node[ $k ] ) && '' !== self::text( $node[ $k ] ) ) {
				$gtin = self::text( $node[ $k ] );
				break;
			}
		}
		return array(
			'name'  => self::text( $node['name'] ?? '' ),
			'sku'   => self::text( $node['sku'] ?? '' ),
			'gtin'  => $gtin,
			'mpn'   => self::text( $node['mpn'] ?? '' ),
			'color' => self::text( $node['color'] ?? '' ),
			'size'  => self::text( $node['size'] ?? '' ),
			'url'   => self::text( $node['url'] ?? '' ),
		);
	}

	/**
	 * Walk decoded JSON-LD (handles @graph, lists, variants) and collect priced offers.
	 *
	 * @param mixed $data    Decoded JSON.
	 * @param array $out     Collected offers.
	 * @param int   $group   Counter: offers of one product share a group number.
	 * @param array $inherit Details inherited from a parent ProductGroup.
	 * @param int   $depth   Recursion depth.
	 */
	private static function walk( $data, array &$out, &$group, array $inherit, $depth ) {
		if ( ! is_array( $data ) || $depth > 8 ) {
			return;
		}
		if ( self::is_list( $data ) ) {
			foreach ( $data as $item ) {
				self::walk( $item, $out, $group, $inherit, $depth + 1 );
			}
			return;
		}
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			self::walk( $data['@graph'], $out, $group, $inherit, $depth + 1 );
		}

		$types = isset( $data['@type'] ) ? (array) $data['@type'] : array();
		$types = array_map( 'strtolower', array_map( 'strval', array_filter( $types, 'is_scalar' ) ) );
		if ( array_intersect( $types, array( 'product', 'productgroup', 'individualproduct', 'productmodel' ) ) ) {
			$info = self::identity( $data );
			foreach ( $inherit as $k => $v ) {
				if ( '' === $info[ $k ] ) {
					$info[ $k ] = $v;
				}
			}
			if ( isset( $data['offers'] ) ) {
				$g = $group++;
				foreach ( self::flatten_offers( $data['offers'], array() ) as $offer ) {
					$own_name = $offer['name'];
					foreach ( $info as $k => $v ) {
						if ( '' === $offer[ $k ] ) {
							$offer[ $k ] = $v;
						}
					}
					// Keep the product name next to an offer's own name ("256 GB") so both can be matched.
					if ( '' !== $own_name && '' !== $info['name'] && false === strpos( $own_name, $info['name'] ) ) {
						$offer['name'] = $info['name'] . ' ' . $own_name;
					}
					$offer['group'] = $g;
					$out[]          = $offer;
				}
			}
			if ( isset( $data['hasVariant'] ) && is_array( $data['hasVariant'] ) ) {
				$variants = self::is_list( $data['hasVariant'] ) ? $data['hasVariant'] : array( $data['hasVariant'] );
				foreach ( $variants as $variant ) {
					if ( is_array( $variant ) && ! isset( $variant['@type'] ) ) {
						$variant['@type'] = 'Product';
					}
					self::walk( $variant, $out, $group, array( 'name' => $info['name'] ), $depth + 1 );
				}
			}
			return;
		}

		foreach ( array( 'mainEntity', 'hasVariant' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				self::walk( $data[ $key ], $out, $group, $inherit, $depth + 1 );
			}
		}
	}

	/**
	 * Turn an Offer, AggregateOffer or list of offers (also nested) into a flat list.
	 * A nested offer keeps the currency and availability of its parent when it has none.
	 *
	 * @param mixed $offers  Offers data.
	 * @param array $inherit currency / in_stock from the parent offer.
	 * @param int   $depth   Recursion depth.
	 * @return array<int, array>
	 */
	private static function flatten_offers( $offers, array $inherit, $depth = 0 ) {
		$out = array();
		if ( ! is_array( $offers ) || $depth > 4 ) {
			return $out;
		}
		$list = self::is_list( $offers ) ? $offers : array( $offers );

		foreach ( $list as $offer ) {
			if ( ! is_array( $offer ) ) {
				continue;
			}
			$currency = self::text( $offer['priceCurrency'] ?? '' );
			$in_stock = isset( $offer['availability'] ) ? self::availability_in_stock( self::text( $offer['availability'] ) ) : null;

			$raw = null;
			foreach ( array( 'price', 'lowPrice', 'highPrice' ) as $key ) {
				if ( isset( $offer[ $key ] ) && is_scalar( $offer[ $key ] ) && '' !== $offer[ $key ] ) {
					$raw = $offer[ $key ];
					break;
				}
			}
			if ( null === $raw && isset( $offer['priceSpecification'] ) && is_array( $offer['priceSpecification'] ) ) {
				$specs = self::is_list( $offer['priceSpecification'] ) ? $offer['priceSpecification'] : array( $offer['priceSpecification'] );
				foreach ( $specs as $spec ) {
					if ( is_array( $spec ) && isset( $spec['price'] ) && is_scalar( $spec['price'] ) && '' !== $spec['price'] ) {
						$raw = $spec['price'];
						if ( '' === $currency ) {
							$currency = self::text( $spec['priceCurrency'] ?? '' );
						}
						break;
					}
				}
			}
			if ( '' === $currency && ! empty( $inherit['currency'] ) ) {
				$currency = $inherit['currency'];
			}
			if ( null === $in_stock && isset( $inherit['in_stock'] ) ) {
				$in_stock = $inherit['in_stock'];
			}

			// Detailed offers inside an AggregateOffer come first: they carry each variant's
			// identifiers and stock. lowPrice / highPrice are used only when there are none.
			if ( isset( $offer['offers'] ) && is_array( $offer['offers'] ) ) {
				$nested = self::flatten_offers(
					$offer['offers'],
					array(
						'currency' => $currency,
						'in_stock' => $in_stock,
					),
					$depth + 1
				);
				if ( $nested ) {
					$out = array_merge( $out, $nested );
					continue;
				}
			}
			if ( null === $raw ) {
				continue;
			}

			$price = is_int( $raw ) || is_float( $raw ) ? (float) $raw : self::normalize_number( (string) $raw, true );
			if ( null === $price || $price <= 0 ) {
				continue;
			}
			$out[] = array_merge(
				self::identity( $offer ),
				array(
					'price'    => $price,
					'currency' => '' !== $currency ? strtoupper( $currency ) : null,
					'in_stock' => $in_stock,
				)
			);
		}
		return $out;
	}

	/**
	 * Pick one offer: the lowest in-stock price of the first product on the page,
	 * or, with a variant hint, of the offers that match the hint.
	 *
	 * @param array  $candidates Offers from jsonld_candidates().
	 * @param string $match      Variant hint.
	 * @param string $currency   Preferred currency code, or '' for the page's own.
	 * @param string $source     Where the offers were read from.
	 * @return array See extract().
	 */
	private static function choose( array $candidates, $match, $currency = '', $source = 'json-ld' ) {
		$best               = self::blank();
		$best['source']     = $source;
		$best['candidates'] = count( $candidates );

		$pool = array();
		if ( '' !== trim( (string) $match ) ) {
			// An exact SKU / GTIN / MPN wins over words: "ABC" must not pick "ABC-XL" because it is cheaper.
			foreach ( $candidates as $c ) {
				if ( self::matches_identifier( $c, $match ) ) {
					$pool[] = $c;
				}
			}
			if ( ! $pool ) {
				foreach ( $candidates as $c ) {
					if ( self::matches( $c, $match ) ) {
						$pool[] = $c;
					}
				}
			}
			$best['matched'] = ! empty( $pool );
		} else {
			foreach ( $candidates as $c ) {
				if ( $c['group'] === $candidates[0]['group'] ) {
					$pool[] = $c;
				}
			}
		}

		$pool = self::one_currency( $pool, $currency );

		foreach ( $pool as $c ) {
			$better = null === $best['price']
				|| ( false === $best['in_stock'] && false !== $c['in_stock'] )
				|| ( ( false !== $c['in_stock'] || false === $best['in_stock'] ) && $c['price'] < $best['price'] );
			if ( $better ) {
				$best['price']    = $c['price'];
				$best['currency'] = $c['currency'];
				$best['in_stock'] = $c['in_stock'];
				$best['name']     = $c['name'];
				$best['sku']      = '' !== $c['sku'] ? $c['sku'] : ( '' !== $c['gtin'] ? $c['gtin'] : $c['mpn'] );
			}
		}
		return $best;
	}

	/**
	 * Keep only offers in one currency, so prices are never compared as bare numbers
	 * across currencies (90 GBP is not cheaper than 100 USD).
	 *
	 * The preferred currency is used when some offers are in it; otherwise the currency
	 * of the first offer that has one. When some offers state a currency, offers without
	 * one are left out: nothing shows they are in the same currency. Only when no offer
	 * states a currency are they all kept (the caller then decides which currency applies).
	 *
	 * @param array  $pool      Offers.
	 * @param string $preferred Preferred currency code, or ''.
	 * @return array
	 */
	private static function one_currency( array $pool, $preferred = '' ) {
		$codes = array();
		foreach ( $pool as $c ) {
			if ( null !== $c['currency'] ) {
				$codes[ $c['currency'] ] = true;
			}
		}
		if ( ! $codes ) {
			return $pool;
		}
		$preferred = strtoupper( trim( (string) $preferred ) );
		$keep      = isset( $codes[ $preferred ] ) ? $preferred : (string) array_key_first( $codes );
		return array_values(
			array_filter(
				$pool,
				static function ( $c ) use ( $keep ) {
					return $keep === $c['currency'];
				}
			)
		);
	}

	/**
	 * Does the hint equal the offer's SKU, GTIN or MPN exactly (ignoring case)?
	 *
	 * @param array  $c     Offer.
	 * @param string $match Hint.
	 * @return bool
	 */
	public static function matches_identifier( array $c, $match ) {
		$needle = self::fold( $match );
		if ( '' === $needle ) {
			return false;
		}
		foreach ( array( 'sku', 'gtin', 'mpn' ) as $k ) {
			if ( '' !== $c[ $k ] && self::fold( $c[ $k ] ) === $needle ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does an offer fit the variant hint?
	 *
	 * True when the hint equals the SKU, GTIN or MPN, or when every word of the
	 * hint appears in the offer's name, colour, size, identifiers or URL.
	 *
	 * @param array  $c     Offer.
	 * @param string $match Hint.
	 * @return bool
	 */
	public static function matches( array $c, $match ) {
		$needle = self::fold( $match );
		if ( '' === $needle ) {
			return true;
		}
		if ( self::matches_identifier( $c, $match ) ) {
			return true;
		}
		$hay = self::fold( implode( ' ', array( $c['name'], $c['color'], $c['size'], $c['sku'], $c['gtin'], $c['mpn'], rawurldecode( $c['url'] ) ) ) );
		foreach ( preg_split( '/\s+/u', $needle ) as $word ) {
			if ( '' !== $word && false === strpos( $hay, $word ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Lower-case text with Persian/Arabic digits turned into Latin ones, for comparing.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function fold( $s ) {
		$s = strtr(
			trim( (string) $s ),
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
				'ي' => 'ی', 'ك' => 'ک',
			)
		);
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/**
	 * Is a schema.org availability value "in stock"?
	 *
	 * @param string $value e.g. https://schema.org/InStock.
	 * @return bool|null
	 */
	private static function availability_in_stock( $value ) {
		$v = strtolower( (string) $value );
		if ( '' === $v ) {
			return null;
		}
		foreach ( array( 'outofstock', 'soldout', 'discontinued' ) as $no ) {
			if ( false !== strpos( $v, $no ) ) {
				return false;
			}
		}
		foreach ( array( 'instock', 'limitedavailability', 'onlineonly', 'instoreonly', 'preorder', 'backorder' ) as $yes ) {
			if ( false !== strpos( $v, $yes ) ) {
				return true;
			}
		}
		return null;
	}

	/**
	 * Turn a price string into a number.
	 *
	 * Understands Persian/Arabic digits, Persian separators (٬ ٫), currency words,
	 * and both 1,234.56 and 1.234,56 styles.
	 *
	 * @param string $raw          Raw text such as "۱۲٬۵۰۰٬۰۰۰ تومان" or "1.299,00 €".
	 * @param bool   $machine_form True when the value comes from structured data, where a single
	 *                             dot is always a decimal point (e.g. "1299.00").
	 * @return float|null
	 */
	public static function normalize_number( $raw, $machine_form = false ) {
		$s = (string) $raw;
		$s = strtr(
			$s,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
				'٬' => ',', '٫' => '.', '،' => ',',
			)
		);
		// Machine-readable data holding one plain number (".99", "1e3", "-0.5"): read it whole.
		if ( $machine_form ) {
			$t = str_replace( "\u{2212}", '-', trim( $s ) );
			if ( preg_match( '/^[-+]?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?$/', $t ) ) {
				return (float) $t;
			}
		}
		// Otherwise keep the first number-looking run, with a minus sign written right before it
		// ("-1" is a negative price, which callers reject, not a price of 1).
		if ( ! preg_match( '/([-\x{2212}]?)(\d[\d.,\s\x{00A0}\x{202F}\x{2009}\']*)/u', $s, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$before = substr( $s, 0, $m[0][1] );
		$after  = substr( $s, $m[0][1] + strlen( $m[0][0] ) );
		// A number cut out of a longer one is not a price: ".99" or "$.99" (a decimal point
		// before the digits; "Rs.1,299" is fine) and "1e3" (an exponent after them).
		if ( '' === $m[1][0] && preg_match( '/(^|[^\p{L}])[.,]$/u', $before ) ) {
			return null;
		}
		if ( preg_match( '/^[eE][-+]?\d/', $after ) ) {
			return null;
		}
		$m   = array( $m[0][0], $m[1][0], $m[2][0] );
		$neg = '' !== $m[1];
		$n   = preg_replace( '/[\s\x{00A0}\x{202F}\x{2009}\']+/u', '', $m[2] );
		$n = rtrim( $n, '.,' );

		$has_comma = false !== strpos( $n, ',' );
		$has_dot   = false !== strpos( $n, '.' );

		if ( $has_comma && $has_dot ) {
			// The separator that comes last is the decimal point.
			if ( strrpos( $n, ',' ) > strrpos( $n, '.' ) ) {
				$n = str_replace( '.', '', $n );
				$n = str_replace( ',', '.', $n );
			} else {
				$n = str_replace( ',', '', $n );
			}
		} elseif ( $has_comma ) {
			$n = preg_match( '/^\d{1,3}(,\d{3})+$/', $n ) ? str_replace( ',', '', $n ) : str_replace( ',', '.', $n );
		} elseif ( $has_dot ) {
			// "1.299.000" is always thousands. A single dot ("1.234") is thousands in text
			// written for people, but a decimal point in machine-readable data (1.234 KWD).
			if ( preg_match( '/^\d{1,3}(\.\d{3})+$/', $n ) && ( ! $machine_form || substr_count( $n, '.' ) > 1 ) ) {
				$n = str_replace( '.', '', $n );
			}
		}

		if ( ! is_numeric( $n ) ) {
			return null;
		}
		return $neg ? -(float) $n : (float) $n;
	}

	/**
	 * Parse HTML into a DOMDocument without warnings, keeping UTF-8.
	 *
	 * @param string $html HTML.
	 * @return DOMDocument|null
	 */
	private static function load_dom( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$ok   = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $ok ? $dom : null;
	}
}
