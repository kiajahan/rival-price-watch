<?php
/**
 * Reads a product price from a competitor's product page HTML.
 *
 * Plain PHP with no WordPress calls, so it can be unit-tested on its own.
 *
 * @package RivalPriceWatch
 */

defined( 'ABSPATH' ) || defined( 'RPW_TESTING' ) || exit;

/**
 * Price extractor.
 *
 * Looks, in order, at:
 * 1. schema.org Product data in JSON-LD (what Google uses for rich results),
 * 2. Open Graph / product meta tags (product:price:amount, og:price:amount),
 * 3. schema.org microdata (itemprop="price").
 */
class RPW_Extractor {

	/**
	 * Extract a price from HTML.
	 *
	 * @param string $html Page HTML.
	 * @return array{price: float|null, currency: string|null, in_stock: bool|null, source: string}
	 */
	public static function extract( $html ) {
		$result = self::extract_structured( $html );
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
		$latin   = number_format( $price, 0, '.', ',' );
		$persian = strtr( $latin, array( ',' => '٬', '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
		$persian_comma = str_replace( '٬', ',', $persian );
		$numbers = array_unique( array( $latin, $persian, $persian_comma ) );

		// Visible text only: drop scripts, styles and tags (class names often contain digits).
		$text = preg_replace( '#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html );
		$text = preg_replace( '/<[^>]*>/', ' ', (string) $text );
		$text = preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );

		$toman = 0;
		$rial  = 0;
		foreach ( $numbers as $num ) {
			// The number, then a few characters with no other digits, then the unit word.
			$re = '/(?<![\d۰-۹])' . preg_quote( $num, '/' ) . '(?![\d۰-۹])[^\d۰-۹]{0,40}?(تومان|ریال)/u';
			if ( preg_match_all( $re, (string) $text, $m ) ) {
				foreach ( $m[1] as $unit ) {
					if ( 'تومان' === $unit ) {
						++$toman;
					} else {
						++$rial;
					}
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
	 * Read price data from JSON-LD, meta tags or microdata.
	 *
	 * @param string $html Page HTML.
	 * @return array{price: float|null, currency: string|null, in_stock: bool|null, source: string}
	 */
	private static function extract_structured( $html ) {
		$result = array(
			'price'    => null,
			'currency' => null,
			'in_stock' => null,
			'source'   => 'none',
		);
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return $result;
		}

		// 1. JSON-LD, read with a fast text scan so big pages are not parsed into a DOM.
		if ( preg_match_all( '#<script\b[^>]*type\s*=\s*["\']?application/ld\+json["\']?[^>]*>(.*?)</script>#is', $html, $blocks ) ) {
			foreach ( $blocks[1] as $json ) {
				$json = trim( $json );
				// Some sites wrap JSON-LD in HTML comments or CDATA.
				$json = preg_replace( '#^(<!--|/\*<!\[CDATA\[\*/|<!\[CDATA\[)|(-->|/\*\]\]>\*/|\]\]>)$#', '', $json );
				$data = json_decode( trim( (string) $json ), true );
				if ( null === $data ) {
					$data = json_decode( html_entity_decode( trim( (string) $json ), ENT_QUOTES, 'UTF-8' ), true );
				}
				if ( null === $data ) {
					continue;
				}
				$found = self::from_jsonld( $data );
				if ( null !== $found['price'] ) {
					$found['source'] = 'json-ld';
					return $found;
				}
			}
		}

		$dom = self::load_dom( $html );
		if ( ! $dom ) {
			return $result;
		}
		$xpath = new DOMXPath( $dom );

		// 2. Meta tags.
		$meta_names = array( 'product:price:amount', 'og:price:amount' );
		foreach ( $meta_names as $name ) {
			$q = sprintf( '//meta[@property="%1$s" or @name="%1$s"]/@content', $name );
			foreach ( $xpath->query( $q ) as $attr ) {
				$price = self::normalize_number( $attr->value );
				if ( null !== $price && $price > 0 ) {
					$result['price']  = $price;
					$result['source'] = 'meta';
					$cur              = $xpath->query( '//meta[@property="product:price:currency" or @property="og:price:currency"]/@content' );
					if ( $cur->length ) {
						$result['currency'] = strtoupper( trim( $cur->item( 0 )->value ) );
					}
					return $result;
				}
			}
		}

		// 3. Microdata.
		foreach ( $xpath->query( '//*[@itemprop="price"]' ) as $el ) {
			$raw   = $el->hasAttribute( 'content' ) ? $el->getAttribute( 'content' ) : $el->textContent;
			$price = self::normalize_number( $raw );
			if ( null !== $price && $price > 0 ) {
				$result['price']  = $price;
				$result['source'] = 'microdata';
				$cur              = $xpath->query( '//*[@itemprop="priceCurrency"]' );
				if ( $cur->length ) {
					$c                  = $cur->item( 0 );
					$result['currency'] = strtoupper( trim( $c->hasAttribute( 'content' ) ? $c->getAttribute( 'content' ) : $c->textContent ) );
				}
				$avail = $xpath->query( '//*[@itemprop="availability"]' );
				if ( $avail->length ) {
					$a                  = $avail->item( 0 );
					$result['in_stock'] = self::availability_in_stock( $a->hasAttribute( 'href' ) ? $a->getAttribute( 'href' ) : ( $a->hasAttribute( 'content' ) ? $a->getAttribute( 'content' ) : $a->textContent ) );
				}
				return $result;
			}
		}

		return $result;
	}

	/**
	 * Find a Product price in decoded JSON-LD (handles @graph, arrays and nested offers).
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array{price: float|null, currency: string|null, in_stock: bool|null, source: string}
	 */
	private static function from_jsonld( $data ) {
		$empty = array(
			'price'    => null,
			'currency' => null,
			'in_stock' => null,
			'source'   => 'json-ld',
		);
		if ( ! is_array( $data ) ) {
			return $empty;
		}

		// A list of items, or a @graph.
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			return self::from_jsonld( $data['@graph'] );
		}
		if ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			foreach ( $data as $item ) {
				$found = self::from_jsonld( $item );
				if ( null !== $found['price'] ) {
					return $found;
				}
			}
			return $empty;
		}

		$types = isset( $data['@type'] ) ? (array) $data['@type'] : array();
		$types = array_map( 'strtolower', array_map( 'strval', $types ) );
		if ( array_intersect( $types, array( 'product', 'productgroup', 'individualproduct', 'productmodel' ) ) && isset( $data['offers'] ) ) {
			return self::from_offers( $data['offers'] );
		}

		// ProductGroup with variants.
		if ( isset( $data['hasVariant'] ) && is_array( $data['hasVariant'] ) ) {
			return self::from_jsonld( $data['hasVariant'] );
		}

		return $empty;
	}

	/**
	 * Read price from an Offer, AggregateOffer or list of offers. Picks the lowest in-stock price.
	 *
	 * @param mixed $offers Offers data.
	 * @return array{price: float|null, currency: string|null, in_stock: bool|null, source: string}
	 */
	private static function from_offers( $offers ) {
		$best = array(
			'price'    => null,
			'currency' => null,
			'in_stock' => null,
			'source'   => 'json-ld',
		);
		if ( ! is_array( $offers ) ) {
			return $best;
		}
		$list = ( array_keys( $offers ) === range( 0, count( $offers ) - 1 ) ) ? $offers : array( $offers );

		foreach ( $list as $offer ) {
			if ( ! is_array( $offer ) ) {
				continue;
			}
			$raw = null;
			foreach ( array( 'price', 'lowPrice', 'highPrice' ) as $key ) {
				if ( isset( $offer[ $key ] ) && '' !== $offer[ $key ] ) {
					$raw = $offer[ $key ];
					break;
				}
			}
			if ( null === $raw && isset( $offer['priceSpecification'] ) ) {
				$spec = $offer['priceSpecification'];
				if ( isset( $spec[0] ) ) {
					$spec = $spec[0];
				}
				$raw = isset( $spec['price'] ) ? $spec['price'] : null;
				if ( empty( $offer['priceCurrency'] ) && ! empty( $spec['priceCurrency'] ) ) {
					$offer['priceCurrency'] = $spec['priceCurrency'];
				}
			}
			if ( null === $raw && isset( $offer['offers'] ) ) {
				$nested = self::from_offers( $offer['offers'] );
				$raw    = $nested['price'];
			}
			$price = is_int( $raw ) || is_float( $raw ) ? (float) $raw : self::normalize_number( (string) $raw, true );
			if ( null === $price || $price <= 0 ) {
				continue;
			}
			$in_stock = isset( $offer['availability'] ) ? self::availability_in_stock( $offer['availability'] ) : null;

			$better = null === $best['price']
				|| ( false === $best['in_stock'] && false !== $in_stock )
				|| ( ( false !== $in_stock || false === $best['in_stock'] ) && $price < $best['price'] );
			if ( $better ) {
				$best['price']    = $price;
				$best['currency'] = isset( $offer['priceCurrency'] ) ? strtoupper( (string) $offer['priceCurrency'] ) : null;
				$best['in_stock'] = $in_stock;
			}
		}
		return $best;
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
		// Keep the first number-looking run.
		if ( ! preg_match( '/\d[\d.,\s\x{00A0}\x{202F}\x{2009}\']*/u', $s, $m ) ) {
			return null;
		}
		$n = preg_replace( '/[\s\x{00A0}\x{202F}\x{2009}\']+/u', '', $m[0] );
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
		} elseif ( $has_dot && ! $machine_form ) {
			if ( preg_match( '/^\d{1,3}(\.\d{3})+$/', $n ) ) {
				$n = str_replace( '.', '', $n );
			}
		}

		if ( ! is_numeric( $n ) ) {
			return null;
		}
		return (float) $n;
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
