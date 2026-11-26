<?php
/**
 * Plugin settings.
 *
 * @package RivalPriceWatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings stored in the rpw_settings option.
 */
class RPW_Settings {

	const OPTION = 'rpw_settings';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'frequency'   => 'daily',
			'alerts'      => 1,
			'alert_email' => get_option( 'admin_email' ),
			'alert_percent' => 0,
			'delay'       => 3,
			'keep_days'   => 180,
		);
	}

	/**
	 * Get one setting or all.
	 *
	 * @param string|null $key Key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$all = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( null === $key ) {
			return $all;
		}
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Allowed check frequencies.
	 *
	 * @return array<string, string>
	 */
	public static function frequencies() {
		return array(
			'twicedaily' => __( 'Twice a day', 'rival-price-watch' ),
			'daily'      => __( 'Once a day', 'rival-price-watch' ),
			'weekly'     => __( 'Once a week', 'rival-price-watch' ),
			'manual'     => __( 'Only when I click "Check now"', 'rival-price-watch' ),
		);
	}

	/**
	 * Clean submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		$freq             = isset( $input['frequency'] ) ? sanitize_key( $input['frequency'] ) : 'daily';
		$out['frequency'] = array_key_exists( $freq, self::frequencies() ) ? $freq : 'daily';
		$out['alerts']    = empty( $input['alerts'] ) ? 0 : 1;

		// A mistyped address is not silently replaced: keep the previous one and say so.
		$typed = isset( $input['alert_email'] ) ? trim( (string) $input['alert_email'] ) : '';
		$email = sanitize_email( $typed );
		if ( is_email( $email ) ) {
			$out['alert_email'] = $email;
		} else {
			$old                = get_option( self::OPTION );
			$out['alert_email'] = is_array( $old ) && ! empty( $old['alert_email'] ) && is_email( $old['alert_email'] ) ? $old['alert_email'] : get_option( 'admin_email' );
			if ( '' !== $typed && function_exists( 'add_settings_error' ) ) {
				/* translators: 1: the address that was typed, 2: the address that is kept. */
				add_settings_error( self::OPTION, 'rpw_alert_email', sprintf( __( '"%1$s" is not a valid email address. Alerts still go to %2$s.', 'rival-price-watch' ), $typed, $out['alert_email'] ) );
			}
		}

		// Only alert when the rival is at least this many percent cheaper (0 = any difference).
		$percent              = isset( $input['alert_percent'] ) ? (float) str_replace( ',', '.', (string) $input['alert_percent'] ) : 0;
		$out['alert_percent'] = min( 90, max( 0, round( $percent, 2 ) ) );

		$out['delay']     = isset( $input['delay'] ) ? min( 30, max( 1, (int) $input['delay'] ) ) : 3;
		$out['keep_days'] = isset( $input['keep_days'] ) ? min( 3650, max( 7, (int) $input['keep_days'] ) ) : 180;

		return $out;
	}

	/**
	 * Your own price for a product in other currencies (markets), e.g. ['USD' => 79, 'EUR' => 72].
	 * The shop currency always uses the normal WooCommerce price.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, float>
	 */
	public static function market_prices( $product_id ) {
		return self::clean_market_prices( get_post_meta( $product_id, '_rpw_market_prices', true ) );
	}

	/**
	 * Tidy a stored list of market prices.
	 *
	 * @param mixed $prices Stored value.
	 * @return array<string, float>
	 */
	public static function clean_market_prices( $prices ) {
		$out = array();
		foreach ( is_array( $prices ) ? $prices : array() as $cur => $price ) {
			if ( (float) $price > 0 ) {
				$out[ strtoupper( (string) $cur ) ] = (float) $price;
			}
		}

		return $out;
	}

	/**
	 * Competitor links of a product.
	 *
	 * @param int $product_id Product ID.
	 * @return array<int, array{url: string, label: string, factor: float, currency: string, match: string}>
	 */
	public static function links( $product_id ) {
		return self::clean_links( get_post_meta( $product_id, '_rpw_links', true ) );
	}

	/**
	 * Tidy a stored list of links.
	 *
	 * @param mixed $links Stored value.
	 * @return array<int, array{url: string, label: string, factor: float, currency: string, match: string}>
	 */
	public static function clean_links( $links ) {
		if ( ! is_array( $links ) ) {
			return array();
		}
		$out = array();
		foreach ( $links as $l ) {
			if ( empty( $l['url'] ) ) {
				continue;
			}
			$out[] = array(
				'url'      => (string) $l['url'],
				'label'    => isset( $l['label'] ) ? (string) $l['label'] : '',
				'factor'   => isset( $l['factor'] ) && (float) $l['factor'] > 0 ? (float) $l['factor'] : 1.0,
				'currency' => isset( $l['currency'] ) ? (string) $l['currency'] : '',
				'match'    => isset( $l['match'] ) ? (string) $l['match'] : '',
				'mode'     => isset( $l['mode'] ) && 'id' === $l['mode'] ? 'id' : 'words',
			);
		}
		return $out;
	}

	/**
	 * Short name for a link: its label, or the site name from the URL.
	 *
	 * @param array $link Link.
	 * @return string
	 */
	public static function link_name( array $link ) {
		if ( '' !== $link['label'] ) {
			return $link['label'];
		}
		$host = wp_parse_url( $link['url'], PHP_URL_HOST );
		return $host ? preg_replace( '/^www\./', '', $host ) : $link['url'];
	}
}
