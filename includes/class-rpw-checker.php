<?php
/**
 * Fetches competitor pages, saves prices and sends alerts.
 *
 * @package RivalPriceWatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checker.
 *
 * Scheduled checks run as a queue in small batches (so a big catalogue never
 * hits the PHP time limit), with a pause between requests to stay polite to
 * the sites being checked.
 */
class RPW_Checker {

	const HOOK_RUN      = 'rpw_run_checks';
	const HOOK_CONTINUE = 'rpw_continue_checks';
	const QUEUE         = 'rpw_queue';
	const ALERTS        = 'rpw_pending_alerts';
	const LOCK          = 'rpw_lock';
	const BATCH         = 15;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK_RUN, array( __CLASS__, 'start_run' ) );
		add_action( self::HOOK_CONTINUE, array( __CLASS__, 'process_queue' ) );
		add_action( 'update_option_' . RPW_Settings::OPTION, array( __CLASS__, 'schedule' ) );
		add_action( 'add_option_' . RPW_Settings::OPTION, array( __CLASS__, 'schedule' ) );
	}

	/**
	 * (Re)schedule the recurring check according to the frequency setting.
	 */
	public static function schedule() {
		wp_clear_scheduled_hook( self::HOOK_RUN );
		$freq = RPW_Settings::get( 'frequency' );
		if ( 'manual' === $freq ) {
			return;
		}
		wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, $freq, self::HOOK_RUN );
	}

	/**
	 * Remove scheduled events (on deactivation).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK_RUN );
		wp_clear_scheduled_hook( self::HOOK_CONTINUE );
		delete_transient( self::LOCK );
	}

	/**
	 * Product IDs that have competitor links.
	 *
	 * @return int[]
	 */
	public static function watched_products() {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_rpw_links', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_compare'   => 'EXISTS',
					'no_found_rows'  => true,
				)
			)
		);
	}

	/**
	 * Start a full run: queue every link of every watched product.
	 */
	public static function start_run() {
		$queue = get_option( self::QUEUE, array() );
		if ( ! empty( $queue ) ) {
			// A run is already going; just keep it moving.
			self::process_queue();
			return;
		}
		$queue = array();
		foreach ( self::watched_products() as $pid ) {
			foreach ( RPW_Settings::links( $pid ) as $i => $link ) {
				$queue[] = array( $pid, $i );
			}
		}
		update_option( self::QUEUE, $queue, false );
		update_option( 'rpw_last_run_started', time(), false );
		self::process_queue();
	}

	/**
	 * Process one batch of the queue, then schedule the next batch.
	 */
	public static function process_queue() {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$queue   = get_option( self::QUEUE, array() );
		$delay   = (int) RPW_Settings::get( 'delay' );
		$started = time();
		$done    = 0;
		$touched = array();

		while ( ! empty( $queue ) && $done < self::BATCH && ( time() - $started ) < 25 ) {
			list( $pid, $index ) = array_shift( $queue );
			$links               = RPW_Settings::links( $pid );
			if ( isset( $links[ $index ] ) ) {
				if ( $done > 0 ) {
					sleep( $delay );
				}
				self::check_link( $pid, $links[ $index ] );
				$touched[ $pid ] = true;
				++$done;
			}
		}

		update_option( self::QUEUE, $queue, false );
		foreach ( array_keys( $touched ) as $pid ) {
			self::collect_alert( $pid );
		}
		delete_transient( self::LOCK );

		if ( ! empty( $queue ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK_CONTINUE );
			return;
		}

		// Run finished.
		update_option( 'rpw_last_run_finished', time(), false );
		self::send_alerts();
		RPW_DB::prune( RPW_Settings::get( 'keep_days' ) );
	}

	/**
	 * How many links "Check now" fetches during the click. The rest go to the
	 * background queue, so the admin request never runs into the PHP time limit.
	 */
	const NOW_LIMIT = 3;

	/**
	 * Check one product now: the first links right away, the rest in the background.
	 *
	 * @param int $product_id Product ID.
	 * @return array{checked: int, queued: int}
	 */
	public static function check_product( $product_id ) {
		$links   = RPW_Settings::links( $product_id );
		$delay   = min( 3, (int) RPW_Settings::get( 'delay' ) );
		$checked = 0;
		$later   = array();
		$started = time();
		// Shorter timeout while the admin waits; background checks keep the normal one.
		$short = static function ( $args ) {
			$args['timeout'] = min( 8, (int) $args['timeout'] );
			return $args;
		};
		add_filter( 'rpw_request_args', $short, 99 );
		foreach ( $links as $i => $link ) {
			// Stop starting new requests after ~12 seconds so the page never hits a 30 s PHP limit.
			if ( $checked >= self::NOW_LIMIT || ( $checked > 0 && time() - $started > 12 ) ) {
				$later[] = array( (int) $product_id, $i );
				continue;
			}
			if ( $checked > 0 ) {
				sleep( $delay );
			}
			self::check_link( $product_id, $link );
			++$checked;
		}
		remove_filter( 'rpw_request_args', $short, 99 );
		if ( $later ) {
			$queue = get_option( self::QUEUE, array() );
			update_option( self::QUEUE, array_merge( (array) $queue, $later ), false );
			if ( ! wp_next_scheduled( self::HOOK_CONTINUE ) ) {
				wp_schedule_single_event( time() + 30, self::HOOK_CONTINUE );
			}
		}
		return array(
			'checked' => $checked,
			'queued'  => count( $later ),
		);
	}

	/**
	 * Fetch one competitor page and save its price.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $link       url, label, factor.
	 * @return array Saved row.
	 */
	public static function check_link( $product_id, array $link ) {
		$args = array(
			'timeout'             => 15,
			'redirection'         => 3,
			'limit_response_size' => 10 * MB_IN_BYTES,
			'user-agent'          => sprintf( 'Mozilla/5.0 (compatible; RivalPriceWatch/%s; +%s)', RPW_VERSION, home_url( '/' ) ),
			'headers'             => array(
				'Accept'          => 'text/html,application/xhtml+xml',
				'Accept-Language' => get_locale() ? str_replace( '_', '-', get_locale() ) . ',en;q=0.8' : 'en',
			),
		);

		/**
		 * Filter the HTTP request arguments for a competitor page.
		 *
		 * @param array $args       wp_remote_get() arguments.
		 * @param array $link       The competitor link.
		 * @param int   $product_id Product ID.
		 */
		$args     = apply_filters( 'rpw_request_args', $args, $link, $product_id );
		$response = wp_safe_remote_get( $link['url'], $args );

		$row = array(
			'price'    => null,
			'currency' => null,
			'in_stock' => null,
			'status'   => 'ok',
			'message'  => '',
		);

		if ( is_wp_error( $response ) ) {
			$row['status']  = 'error';
			$row['message'] = $response->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				$row['status'] = 'error';
				/* translators: %d: HTTP status code. */
				$row['message'] = sprintf( __( 'The page answered with HTTP %d.', 'rival-price-watch' ), $code );
				if ( in_array( $code, array( 403, 429, 503 ), true ) ) {
					$row['message'] .= ' ' . __( 'The site may be blocking automatic checks.', 'rival-price-watch' );
				}
			} else {
				$found = RPW_Extractor::extract( wp_remote_retrieve_body( $response ) );

				/**
				 * Filter the extracted result, e.g. to add support for a site
				 * that does not publish structured price data.
				 *
				 * @param array  $found Result: price, currency, in_stock, source.
				 * @param string $html  Page HTML.
				 * @param array  $link  The competitor link.
				 */
				$found = apply_filters( 'rpw_extracted_price', $found, wp_remote_retrieve_body( $response ), $link );

				if ( null === $found['price'] ) {
					$row['status']  = 'noprice';
					$row['message'] = __( 'No price found on the page. The site does not publish price data that can be read automatically.', 'rival-price-watch' );
				} else {
					// Currency: the one chosen for this link, else what the page says, else the shop's own.
					$from = '' !== $link['currency'] ? $link['currency'] : RPW_Currency::normalize( $found['currency'] );
					$from = $from ? $from : RPW_Currency::shop();
					$raw  = round( (float) $found['price'] * (float) $link['factor'], 4 );

					$row['raw_price']    = $raw;
					$row['raw_currency'] = $from;
					$row['currency']     = RPW_Currency::shop();
					$row['in_stock']     = $found['in_stock'];
					$row['message']      = $found['source'];

					$converted = RPW_Currency::to_shop( $raw, $from );
					if ( null === $converted ) {
						$row['status'] = 'norate';
						/* translators: 1: competitor currency code, 2: shop currency code. */
						$row['message'] = sprintf( __( 'This site sells in %1$s. Add a %1$s to %2$s exchange rate in Settings to compare it.', 'rival-price-watch' ), $from, RPW_Currency::shop() );
					} else {
						$row['price'] = round( $converted, 4 );
					}
				}
			}
		}

		RPW_DB::add( $product_id, $link['url'], $row );
		return $row;
	}

	/**
	 * Summary of a product: own price, latest rival prices, cheapest rival.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function summary( $product_id ) {
		$product = wc_get_product( $product_id );
		$own     = $product ? (float) $product->get_price() : 0.0;
		$latest  = RPW_DB::latest_for_product( $product_id );
		$rows    = array();
		$lowest  = null;
		$checked = null;

		foreach ( RPW_Settings::links( $product_id ) as $link ) {
			$hash = RPW_DB::hash( $link['url'] );
			$last = isset( $latest[ $hash ] ) ? $latest[ $hash ] : null;
			$rows[] = array(
				'link' => $link,
				'last' => $last,
			);
			if ( $last ) {
				$checked = ( null === $checked || $last->checked_at > $checked ) ? $last->checked_at : $checked;
				if ( 'ok' === $last->status && null !== $last->price && '0' !== (string) $last->in_stock && ( empty( $last->currency ) || strtoupper( $last->currency ) === RPW_Currency::shop() ) ) {
					$p = (float) $last->price;
					if ( null === $lowest || $p < $lowest['price'] ) {
						$lowest = array(
							'price' => $p,
							'name'  => RPW_Settings::link_name( $link ),
							'url'   => $link['url'],
						);
					}
				}
			}
		}

		$diff = null;
		$gap  = null;
		if ( $lowest && $own > 0 ) {
			$gap  = $lowest['price'] - $own;
			$diff = $gap / $own * 100;
		}

		return array(
			'product' => $product,
			'own'     => $own,
			'rows'    => $rows,
			'lowest'  => $lowest,
			'diff'    => $diff,
			'gap'     => $gap,
			'checked' => $checked,
		);
	}

	/**
	 * Rivals grouped by the currency they sell in, each compared in that currency.
	 *
	 * Your price for a market is, in order: the WooCommerce price (shop currency),
	 * the price you set for that currency on the product, or the shop price
	 * converted with your exchange rates (marked as approximate).
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, array> currency => [currency, rows, own, own_source, lowest, diff, gap, checked]
	 */
	public static function markets( $product_id ) {
		$product = wc_get_product( $product_id );
		$shop    = RPW_Currency::shop();
		$shop_p  = $product ? (float) $product->get_price() : 0.0;
		$set     = RPW_Settings::market_prices( $product_id );
		$rates   = RPW_Currency::rates();
		$latest  = RPW_DB::latest_for_product( $product_id );
		$out     = array();

		foreach ( RPW_Settings::links( $product_id ) as $link ) {
			$last = $latest[ RPW_DB::hash( $link['url'] ) ] ?? null;
			$cur  = $last && ! empty( $last->raw_currency ) ? strtoupper( $last->raw_currency )
				: ( '' !== $link['currency'] ? $link['currency'] : $shop );
			if ( ! isset( $out[ $cur ] ) ) {
				$out[ $cur ] = array(
					'currency' => $cur,
					'rows'     => array(),
					'lowest'   => null,
					'checked'  => null,
				);
			}
			$out[ $cur ]['rows'][] = array(
				'link' => $link,
				'last' => $last,
			);
			if ( ! $last ) {
				continue;
			}
			$m = &$out[ $cur ];
			$m['checked'] = ( null === $m['checked'] || $last->checked_at > $m['checked'] ) ? $last->checked_at : $m['checked'];
			// Native price: raw_price (any check that found a price, even without an exchange rate).
			$native = null !== $last->raw_price ? (float) $last->raw_price : ( 'ok' === $last->status ? (float) $last->price : null );
			if ( in_array( $last->status, array( 'ok', 'norate' ), true ) && $native > 0 && '0' !== (string) $last->in_stock ) {
				if ( null === $m['lowest'] || $native < $m['lowest']['price'] ) {
					$m['lowest'] = array(
						'price' => $native,
						'name'  => RPW_Settings::link_name( $link ),
						'url'   => $link['url'],
					);
				}
			}
			unset( $m );
		}

		foreach ( $out as $cur => &$m ) {
			if ( $cur === $shop ) {
				$m['own']        = $shop_p > 0 ? $shop_p : null;
				$m['own_source'] = 'shop';
			} elseif ( ! empty( $set[ $cur ] ) ) {
				$m['own']        = (float) $set[ $cur ];
				$m['own_source'] = 'set';
			} else {
				$m['own']        = null;
				$m['own_source'] = 'none';
				$rate            = RPW_Currency::convert( 1, $cur, $shop, $rates );
				if ( $shop_p > 0 && $rate > 0 ) {
					$m['own']        = $shop_p / $rate;
					$m['own_source'] = 'converted';
				}
			}
			$m['diff'] = null;
			$m['gap']  = null;
			if ( $m['lowest'] && $m['own'] > 0 ) {
				$m['gap']  = $m['lowest']['price'] - $m['own'];
				$m['diff'] = $m['gap'] / $m['own'] * 100;
			}
		}
		unset( $m );

		// Shop currency first, then the others alphabetically.
		uksort(
			$out,
			static function ( $a, $b ) use ( $shop ) {
				return ( $a === $shop ? -1 : ( $b === $shop ? 1 : strcmp( $a, $b ) ) );
			}
		);

		return $out;
	}

	/**
	 * Remember a product for the alert email if a rival is cheaper and the
	 * situation changed since the last alert.
	 *
	 * @param int $product_id Product ID.
	 */
	private static function collect_alert( $product_id ) {
		if ( ! RPW_Settings::get( 'alerts' ) ) {
			return;
		}
		$alerted = get_post_meta( $product_id, '_rpw_alerted', true );
		$alerted = is_array( $alerted ) ? $alerted : array();
		$pending = get_option( self::ALERTS, array() );
		$changed = false;

		// One check per market (currency): compare in that currency.
		foreach ( self::markets( $product_id ) as $cur => $m ) {
			if ( ! $m['lowest'] || ! ( $m['own'] > 0 ) || $m['lowest']['price'] >= $m['own'] ) {
				if ( isset( $alerted[ $cur ] ) ) {
					unset( $alerted[ $cur ] );
					$changed = true;
				}
				continue;
			}
			$key = md5( $m['lowest']['url'] . '|' . $m['lowest']['price'] . '|' . $m['own'] );
			if ( ( $alerted[ $cur ] ?? '' ) === $key ) {
				continue;
			}
			$alerted[ $cur ]                      = $key;
			$changed                              = true;
			$pending[ $product_id . '|' . $cur ] = array(
				'product'  => $product_id,
				'currency' => $cur,
				'approx'   => 'converted' === $m['own_source'],
				'own'      => $m['own'],
				'lowest'   => $m['lowest'],
			);
		}

		if ( $changed ) {
			update_post_meta( $product_id, '_rpw_alerted', $alerted );
		}
		update_option( self::ALERTS, $pending, false );
	}

	/**
	 * Send one email listing every product where a rival became cheaper.
	 */
	public static function send_alerts() {
		$pending = get_option( self::ALERTS, array() );
		if ( empty( $pending ) ) {
			return;
		}
		delete_option( self::ALERTS );
		$to = RPW_Settings::get( 'alert_email' );
		if ( ! is_email( $to ) ) {
			return;
		}

		// Group lines by market currency.
		$groups   = array();
		$count    = 0;
		$products = array();
		foreach ( $pending as $key => $a ) {
			$pid     = isset( $a['product'] ) ? (int) $a['product'] : (int) $key; // older entries were keyed by product id
			$cur     = $a['currency'] ?? RPW_Currency::shop();
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$groups[ $cur ][] = sprintf(
				/* translators: 1: product name, 2: your price, 3: competitor name, 4: competitor price, 5: competitor URL. */
				__( '- %1$s: your price %2$s, %3$s sells it for %4$s (%5$s)', 'rival-price-watch' ),
				$product->get_name(),
				( ! empty( $a['approx'] ) ? '≈ ' : '' ) . self::plain_price( $a['own'], $cur ),
				$a['lowest']['name'],
				self::plain_price( $a['lowest']['price'], $cur ),
				$a['lowest']['url']
			);
			++$count;
			$products[ $pid ] = true;
		}
		if ( ! $count ) {
			return;
		}

		$sections = array();
		foreach ( $groups as $cur => $lines ) {
			$sections[] = $cur . ' · ' . RPW_Currency::name( $cur ) . "\n" . implode( "\n", $lines );
		}

		/* translators: %d: number of products. */
		$subject = sprintf( _n( 'Rival Price Watch: a competitor is cheaper on %d product', 'Rival Price Watch: competitors are cheaper on %d products', count( $products ), 'rival-price-watch' ), count( $products ) );
		$body    = __( 'These products are now cheaper at a competitor:', 'rival-price-watch' ) . "\n\n" . implode( "\n\n", $sections ) . "\n\n" . admin_url( 'admin.php?page=rpw-prices' );
		wp_mail( $to, $subject, $body );
	}

	/**
	 * Price as plain text for emails.
	 *
	 * @param float       $amount   Amount.
	 * @param string|null $currency Currency code (default: shop currency).
	 * @return string
	 */
	public static function plain_price( $amount, $currency = null ) {
		return html_entity_decode( wp_strip_all_tags( self::money( $amount, $currency ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Formatted price in any currency (WooCommerce formatting for the shop currency,
	 * "1,234.50 USD" style for others WooCommerce does not know).
	 *
	 * @param float       $amount   Amount.
	 * @param string|null $currency Currency code.
	 * @return string HTML.
	 */
	public static function money( $amount, $currency = null ) {
		$currency = $currency ? strtoupper( $currency ) : RPW_Currency::shop();
		if ( $currency === RPW_Currency::shop() || array_key_exists( $currency, get_woocommerce_currencies() ) ) {
			$decimals = in_array( $currency, array( 'IRR', 'IRT', 'JPY', 'KRW', 'IDR', 'IQD', 'AFN', 'PKR', 'HUF' ), true ) ? 0 : 2;

			return wc_price( $amount, array( 'currency' => $currency, 'decimals' => $currency === RPW_Currency::shop() ? wc_get_price_decimals() : $decimals ) );
		}

		return esc_html( number_format_i18n( (float) $amount, floor( (float) $amount ) === (float) $amount ? 0 : 2 ) . ' ' . $currency );
	}
}
