<?php
/**
 * Currencies and exchange rates.
 *
 * @package RivalPriceWatch
 */

defined( 'ABSPATH' ) || defined( 'RPW_TESTING' ) || exit;

/**
 * Currency list, code detection and conversion to the shop currency.
 *
 * Rates are stored as "1 unit of that currency = X units of the shop currency".
 * Rial ⇄ toman is built in (1 toman = 10 rials) and needs no rate.
 */
class RPW_Currency {

	const OPTION     = 'rpw_rates';
	const RATES_API  = 'https://open.er-api.com/v6/latest/USD';

	/**
	 * Well-known currencies: code => English name.
	 *
	 * IRT (Iranian toman) is not an ISO code, but Iranian WooCommerce shops use it.
	 *
	 * @return array<string, string>
	 */
	public static function codes() {
		return array(
			'USD' => 'US dollar',
			'EUR' => 'Euro',
			'GBP' => 'British pound',
			'JPY' => 'Japanese yen',
			'CNY' => 'Chinese yuan',
			'CHF' => 'Swiss franc',
			'CAD' => 'Canadian dollar',
			'AUD' => 'Australian dollar',
			'NZD' => 'New Zealand dollar',
			'HKD' => 'Hong Kong dollar',
			'SGD' => 'Singapore dollar',
			'KRW' => 'South Korean won',
			'INR' => 'Indian rupee',
			'PKR' => 'Pakistani rupee',
			'AFN' => 'Afghan afghani',
			'IRR' => 'Iranian rial',
			'IRT' => 'Iranian toman',
			'IQD' => 'Iraqi dinar',
			'AED' => 'UAE dirham',
			'SAR' => 'Saudi riyal',
			'QAR' => 'Qatari riyal',
			'KWD' => 'Kuwaiti dinar',
			'BHD' => 'Bahraini dinar',
			'OMR' => 'Omani rial',
			'TRY' => 'Turkish lira',
			'AZN' => 'Azerbaijani manat',
			'AMD' => 'Armenian dram',
			'GEL' => 'Georgian lari',
			'RUB' => 'Russian ruble',
			'UAH' => 'Ukrainian hryvnia',
			'ILS' => 'Israeli shekel',
			'EGP' => 'Egyptian pound',
			'SEK' => 'Swedish krona',
			'NOK' => 'Norwegian krone',
			'DKK' => 'Danish krone',
			'PLN' => 'Polish zloty',
			'CZK' => 'Czech koruna',
			'HUF' => 'Hungarian forint',
			'MYR' => 'Malaysian ringgit',
			'THB' => 'Thai baht',
			'IDR' => 'Indonesian rupiah',
			'PHP' => 'Philippine peso',
			'BRL' => 'Brazilian real',
			'MXN' => 'Mexican peso',
			'ZAR' => 'South African rand',
		);
	}

	/**
	 * Translated currency name.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function name( $code ) {
		$names = array(
			'USD' => __( 'US dollar', 'rival-price-watch' ),
			'EUR' => __( 'Euro', 'rival-price-watch' ),
			'GBP' => __( 'British pound', 'rival-price-watch' ),
			'JPY' => __( 'Japanese yen', 'rival-price-watch' ),
			'CNY' => __( 'Chinese yuan', 'rival-price-watch' ),
			'CHF' => __( 'Swiss franc', 'rival-price-watch' ),
			'CAD' => __( 'Canadian dollar', 'rival-price-watch' ),
			'AUD' => __( 'Australian dollar', 'rival-price-watch' ),
			'NZD' => __( 'New Zealand dollar', 'rival-price-watch' ),
			'HKD' => __( 'Hong Kong dollar', 'rival-price-watch' ),
			'SGD' => __( 'Singapore dollar', 'rival-price-watch' ),
			'KRW' => __( 'South Korean won', 'rival-price-watch' ),
			'INR' => __( 'Indian rupee', 'rival-price-watch' ),
			'PKR' => __( 'Pakistani rupee', 'rival-price-watch' ),
			'AFN' => __( 'Afghan afghani', 'rival-price-watch' ),
			'IRR' => __( 'Iranian rial', 'rival-price-watch' ),
			'IRT' => __( 'Iranian toman', 'rival-price-watch' ),
			'IQD' => __( 'Iraqi dinar', 'rival-price-watch' ),
			'AED' => __( 'UAE dirham', 'rival-price-watch' ),
			'SAR' => __( 'Saudi riyal', 'rival-price-watch' ),
			'QAR' => __( 'Qatari riyal', 'rival-price-watch' ),
			'KWD' => __( 'Kuwaiti dinar', 'rival-price-watch' ),
			'BHD' => __( 'Bahraini dinar', 'rival-price-watch' ),
			'OMR' => __( 'Omani rial', 'rival-price-watch' ),
			'TRY' => __( 'Turkish lira', 'rival-price-watch' ),
			'AZN' => __( 'Azerbaijani manat', 'rival-price-watch' ),
			'AMD' => __( 'Armenian dram', 'rival-price-watch' ),
			'GEL' => __( 'Georgian lari', 'rival-price-watch' ),
			'RUB' => __( 'Russian ruble', 'rival-price-watch' ),
			'UAH' => __( 'Ukrainian hryvnia', 'rival-price-watch' ),
			'ILS' => __( 'Israeli shekel', 'rival-price-watch' ),
			'EGP' => __( 'Egyptian pound', 'rival-price-watch' ),
			'SEK' => __( 'Swedish krona', 'rival-price-watch' ),
			'NOK' => __( 'Norwegian krone', 'rival-price-watch' ),
			'DKK' => __( 'Danish krone', 'rival-price-watch' ),
			'PLN' => __( 'Polish zloty', 'rival-price-watch' ),
			'CZK' => __( 'Czech koruna', 'rival-price-watch' ),
			'HUF' => __( 'Hungarian forint', 'rival-price-watch' ),
			'MYR' => __( 'Malaysian ringgit', 'rival-price-watch' ),
			'THB' => __( 'Thai baht', 'rival-price-watch' ),
			'IDR' => __( 'Indonesian rupiah', 'rival-price-watch' ),
			'PHP' => __( 'Philippine peso', 'rival-price-watch' ),
			'BRL' => __( 'Brazilian real', 'rival-price-watch' ),
			'MXN' => __( 'Mexican peso', 'rival-price-watch' ),
			'ZAR' => __( 'South African rand', 'rival-price-watch' ),
		);
		return isset( $names[ $code ] ) ? $names[ $code ] : $code;
	}

	/**
	 * Turn whatever a page says ("usd", "€", "تومان", "Rial") into a currency code.
	 *
	 * @param string|null $raw Raw currency text.
	 * @return string|null Upper-case code, or null when unknown.
	 */
	public static function normalize( $raw ) {
		if ( null === $raw ) {
			return null;
		}
		$s = trim( (string) $raw );
		if ( '' === $s ) {
			return null;
		}
		$map = array(
			'$'      => 'USD',
			'US$'    => 'USD',
			'€'      => 'EUR',
			'£'      => 'GBP',
			'¥'      => 'JPY',
			'₹'      => 'INR',
			'₺'      => 'TRY',
			'₽'      => 'RUB',
			'₩'      => 'KRW',
			'₪'      => 'ILS',
			'TOMAN'  => 'IRT',
			'TOMANS' => 'IRT',
			'TOM'    => 'IRT',
			'تومان'  => 'IRT',
			'ریال'   => 'IRR',
			'RIAL'   => 'IRR',
			'RIALS'  => 'IRR',
			'درهم'   => 'AED',
			'RMB'    => 'CNY',
			'TL'     => 'TRY',
		);
		$upper = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $s, 'UTF-8' ) : strtoupper( $s );
		if ( isset( $map[ $upper ] ) ) {
			return $map[ $upper ];
		}
		if ( isset( $map[ $s ] ) ) {
			return $map[ $s ];
		}
		if ( preg_match( '/^[A-Z]{3}$/', $upper ) ) {
			return $upper;
		}
		return null;
	}

	/**
	 * Convert an amount between currencies.
	 *
	 * @param float       $amount Amount.
	 * @param string|null $from   Source currency (null = assume shop currency).
	 * @param string      $to     Shop currency.
	 * @param array       $rates  code => units of $to per 1 unit of code.
	 * @return float|null Converted amount, or null when no rate is known.
	 */
	public static function convert( $amount, $from, $to, array $rates ) {
		$amount = (float) $amount;
		$from   = $from ? strtoupper( $from ) : $to;
		$to     = strtoupper( $to );
		if ( $from === $to ) {
			return (float) $amount;
		}
		// Rial ⇄ toman needs no rate.
		if ( 'IRR' === $from && 'IRT' === $to ) {
			return $amount / 10;
		}
		if ( 'IRT' === $from && 'IRR' === $to ) {
			return $amount * 10;
		}
		if ( ! empty( $rates[ $from ] ) && $rates[ $from ] > 0 ) {
			return $amount * (float) $rates[ $from ];
		}
		// A rate entered for the other Iranian unit also works.
		if ( 'IRR' === $from && ! empty( $rates['IRT'] ) ) {
			return $amount / 10 * (float) $rates['IRT'];
		}
		if ( 'IRT' === $from && ! empty( $rates['IRR'] ) ) {
			return $amount * 10 * (float) $rates['IRR'];
		}
		return null;
	}

	/**
	 * Saved rates, but only if they were saved for the current shop currency.
	 *
	 * @return array{base: string, rates: array<string, float>, updated: int, source: string}
	 */
	public static function stored() {
		$data = get_option( self::OPTION, array() );
		$data = wp_parse_args(
			is_array( $data ) ? $data : array(),
			array(
				'base'    => '',
				'rates'   => array(),
				'updated' => 0,
				'source'  => '',
			)
		);
		if ( $data['base'] !== self::shop() ) {
			$data['rates'] = array();
		}
		return $data;
	}

	/**
	 * Rates for conversion.
	 *
	 * @return array<string, float>
	 */
	public static function rates() {
		$data = self::stored();
		return (array) $data['rates'];
	}

	/**
	 * Shop currency code.
	 *
	 * @return string
	 */
	public static function shop() {
		return function_exists( 'get_woocommerce_currency' ) ? strtoupper( get_woocommerce_currency() ) : 'USD';
	}

	/**
	 * Convert to the shop currency using saved rates.
	 *
	 * @param float       $amount Amount.
	 * @param string|null $from   Currency.
	 * @return float|null
	 */
	public static function to_shop( $amount, $from ) {
		return self::convert( $amount, $from, self::shop(), self::rates() );
	}

	/**
	 * Show a rate as a plain decimal with up to 10 significant digits
	 * (very small rates such as 0.0000215 keep their precision, no "2.15E-5").
	 *
	 * @param float $rate Rate.
	 * @return string
	 */
	public static function format_rate( $rate ) {
		$rate = (float) $rate;
		if ( $rate <= 0 ) {
			return '';
		}
		$decimals = min( 20, max( 0, 9 - (int) floor( log10( $rate ) ) ) );
		$s        = number_format( $rate, $decimals, '.', '' );
		return false !== strpos( $s, '.' ) ? rtrim( rtrim( $s, '0' ), '.' ) : $s;
	}

	/**
	 * Read a typed exchange rate ("105,000", "۱۰۵٬۰۰۰", "0.92", "1.105").
	 *
	 * @param mixed $raw Typed value.
	 * @return float|null
	 */
	public static function parse_rate( $raw ) {
		if ( is_int( $raw ) || is_float( $raw ) ) {
			return (float) $raw;
		}
		$s = trim( (string) $raw );
		// A single dot with no comma is always a decimal point here ("1.105" is 1.105, not 1105).
		$single_dot = 1 === substr_count( $s, '.' ) && false === strpos( $s, ',' ) && false === strpos( $s, '٬' );
		return RPW_Extractor::normalize_number( $s, $single_dot );
	}

	/**
	 * Save exchange rates.
	 *
	 * @param array  $rates  code => rate.
	 * @param string $source 'manual' or 'auto'.
	 */
	public static function save( array $rates, $source = 'manual' ) {
		$clean = array();
		foreach ( $rates as $code => $rate ) {
			$code = strtoupper( sanitize_key( $code ) );
			$rate = self::parse_rate( $rate );
			if ( isset( self::codes()[ $code ] ) && null !== $rate && $rate > 0 ) {
				$clean[ $code ] = $rate;
			}
		}
		update_option(
			self::OPTION,
			array(
				'base'    => self::shop(),
				'rates'   => $clean,
				'updated' => time(),
				'source'  => $source,
			),
			false
		);
	}

	/**
	 * Can rates be fetched automatically for this shop? Not for rial/toman shops,
	 * where official rates differ a lot from the market rate.
	 *
	 * @return bool
	 */
	public static function can_fetch() {
		return ! in_array( self::shop(), array( 'IRR', 'IRT' ), true );
	}

	/**
	 * Download current official rates (free service, no key) and save them.
	 *
	 * Keeps any rate the API does not know (e.g. IRT). Iranian rial/toman
	 * rates from this service are official rates, not free-market rates.
	 *
	 * @return true|WP_Error
	 */
	public static function fetch() {
		$response = wp_safe_remote_get( self::RATES_API, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['rates'] ) || ! is_array( $data['rates'] ) ) {
			return new WP_Error( 'rpw_rates', __( 'The exchange-rate service returned no rates.', 'rival-price-watch' ) );
		}
		$per_usd = $data['rates'];
		if ( isset( $per_usd['IRR'] ) ) {
			$per_usd['IRT'] = $per_usd['IRR'] / 10;
		}
		$shop = self::shop();
		if ( ! self::can_fetch() ) {
			return new WP_Error( 'rpw_rates', __( 'Automatic rates are official rates, which are far from the free-market rate for the rial and toman. Enter your rates by hand.', 'rival-price-watch' ) );
		}
		if ( empty( $per_usd[ $shop ] ) ) {
			/* translators: %s: currency code. */
			return new WP_Error( 'rpw_rates', sprintf( __( 'The exchange-rate service does not know your shop currency (%s). Enter rates by hand.', 'rival-price-watch' ), $shop ) );
		}
		$rates = self::rates();
		foreach ( array_keys( self::codes() ) as $code ) {
			if ( $code === $shop || empty( $per_usd[ $code ] ) ) {
				continue;
			}
			$rates[ $code ] = (float) sprintf( '%.10g', $per_usd[ $shop ] / $per_usd[ $code ] );
		}
		self::save( $rates, 'auto' );
		return true;
	}
}
