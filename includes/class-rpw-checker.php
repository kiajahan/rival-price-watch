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
 * Scheduled checks run as a queue of jobs (see RPW_Jobs) in small batches, so a
 * big catalogue never hits the PHP time limit, with a pause between requests to
 * stay polite to the sites being checked.
 */
class RPW_Checker {

	const HOOK_RUN      = 'rpw_run_checks';
	const HOOK_CONTINUE = 'rpw_continue_checks';
	const HOOK_ALERTS   = 'rpw_send_alerts';
	const LOCK          = 'rpw_lock';
	const MAIL_LOCK     = 'rpw_mail_lock';
	const BATCH         = 15;

	/**
	 * Largest price accepted from a page, in its own currency (after the multiplier).
	 */
	const MAX_PRICE = 1e15;

	/**
	 * Seconds one background batch may take (filter: rpw_batch_seconds).
	 */
	const BATCH_SECONDS = 25;

	/**
	 * How many times a failed alert email is tried again on its own schedule.
	 * After that it is tried again at the end of every check run.
	 */
	const MAIL_RETRIES = 5;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK_RUN, array( __CLASS__, 'start_run' ) );
		add_action( self::HOOK_CONTINUE, array( __CLASS__, 'process_queue' ) );
		add_action( self::HOOK_ALERTS, array( __CLASS__, 'send_alerts' ) );
		add_action( 'update_option_' . RPW_Settings::OPTION, array( __CLASS__, 'settings_changed' ), 10, 2 );
		add_action( 'add_option_' . RPW_Settings::OPTION, array( __CLASS__, 'schedule' ) );
		add_action( 'delete_post', array( __CLASS__, 'forget_product' ) );
	}

	/**
	 * Settings were saved: reschedule only when the check frequency changed, so saving
	 * an alert email or a threshold does not move the next run (or start an extra one).
	 *
	 * @param mixed $old Previous settings.
	 * @param mixed $new New settings.
	 */
	public static function settings_changed( $old, $new ) {
		$was = is_array( $old ) && isset( $old['frequency'] ) ? $old['frequency'] : null;
		$now = is_array( $new ) && isset( $new['frequency'] ) ? $new['frequency'] : null;
		if ( $was !== $now || ( 'manual' !== $now && ! wp_next_scheduled( self::HOOK_RUN ) ) ) {
			self::schedule();
		}
	}

	/**
	 * A product is being deleted for good: remove its price history and queued checks.
	 * (Moving it to the trash keeps everything, so restoring it brings its history back.)
	 *
	 * @param int $post_id Post ID.
	 */
	public static function forget_product( $post_id ) {
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		RPW_DB::delete_product( (int) $post_id );
		RPW_Jobs::clear( (int) $post_id );
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
		wp_clear_scheduled_hook( self::HOOK_ALERTS );
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
	 * One link of a product, found by its hash.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $hash       Link hash.
	 * @return array|null
	 */
	public static function link_by_hash( $product_id, $hash ) {
		foreach ( RPW_Settings::links( $product_id ) as $link ) {
			if ( RPW_DB::hash( $link['url'] ) === $hash ) {
				return $link;
			}
		}
		return null;
	}

	/**
	 * Start a full run: queue every link of every watched product.
	 */
	public static function start_run() {
		if ( self::run_in_progress() ) {
			// A full run is still going; just keep it moving.
			self::process_queue();
			return;
		}
		// Jobs that are already waiting (a retry after "Check now", a link whose settings
		// changed) stay as they are; every other link is added.
		$jobs = array();
		foreach ( self::watched_products() as $pid ) {
			foreach ( RPW_Settings::links( $pid ) as $link ) {
				$jobs[] = array( $pid, RPW_DB::hash( $link['url'] ) );
			}
		}
		RPW_Jobs::enqueue_many( $jobs );
		update_option( 'rpw_last_run_started', time(), false );
		self::process_queue();
	}

	/**
	 * Is a full run still working through its queue?
	 *
	 * Only a run that was started and has not finished counts, and only for a day:
	 * a few jobs left waiting by "Check now" or by edited links are not a run.
	 *
	 * @return bool
	 */
	public static function run_in_progress() {
		$started  = (int) get_option( 'rpw_last_run_started', 0 );
		$finished = (int) get_option( 'rpw_last_run_finished', 0 );
		return RPW_Jobs::count() > 0 && $started > $finished && time() - $started < DAY_IN_SECONDS;
	}

	/**
	 * Make sure a waiting queue has a batch scheduled (a run whose next batch was lost picks up again).
	 *
	 * @return int Links still waiting.
	 */
	public static function ensure_scheduled() {
		$left = RPW_Jobs::count();
		if ( $left > 0 && ! wp_next_scheduled( self::HOOK_CONTINUE ) ) {
			$next = RPW_Jobs::next_time();
			wp_schedule_single_event( max( time() + 30, (int) $next ), self::HOOK_CONTINUE );
		}
		return $left;
	}

	/**
	 * Longest time one request to a competitor page may take, in seconds.
	 *
	 * @return int
	 */
	private static function request_timeout() {
		$args = apply_filters( 'rpw_request_args', array( 'timeout' => 15 ), array(), 0 );
		return isset( $args['timeout'] ) ? max( 1, (int) $args['timeout'] ) : 15;
	}

	/**
	 * Process one batch of the queue, then schedule the next batch.
	 */
	public static function process_queue() {
		/**
		 * Filter how many seconds one background batch may take.
		 *
		 * @param int $seconds Default 25.
		 */
		$budget  = max( 5, (int) apply_filters( 'rpw_batch_seconds', self::BATCH_SECONDS ) );
		$delay   = max( 0, (int) RPW_Settings::get( 'delay' ) );
		$timeout = self::request_timeout();

		// One batch at a time, so competitor sites never get parallel requests from this shop.
		$lock = RPW_Jobs::lock( self::LOCK, $budget + $timeout + 60 );
		if ( ! $lock ) {
			self::ensure_scheduled();
			return;
		}
		RPW_Jobs::release_stalled();

		// Watchdog, set before any work: if this worker dies mid-batch (fatal error, timeout,
		// server restart), this event still fires after its claimed jobs count as stalled,
		// so the queue continues without waiting for another visit or the next scheduled run.
		if ( RPW_Jobs::count() > 0 ) {
			wp_clear_scheduled_hook( self::HOOK_CONTINUE );
			wp_schedule_single_event( time() + RPW_Jobs::STALL_SECONDS + MINUTE_IN_SECONDS, self::HOOK_CONTINUE );
		}

		$started = microtime( true );
		$done    = 0;
		while ( $done < self::BATCH ) {
			// Start another check only if its pause and its slowest possible answer still fit.
			if ( $done > 0 && ( microtime( true ) - $started ) + $delay + $timeout > $budget ) {
				break;
			}
			$job = RPW_Jobs::claim();
			if ( ! $job ) {
				break;
			}
			$pid  = (int) $job->product_id;
			$link = self::link_by_hash( $pid, $job->link_hash );
			if ( ! $link ) {
				// The link was removed while it was waiting.
				RPW_Jobs::complete( $job );
				continue;
			}
			if ( $done > 0 && $delay ) {
				sleep( $delay );
			}
			$row = self::check_link( $pid, $link );
			++$done;
			// Each result is final as soon as it is saved: the job is removed (or put back for a retry) now, not at the end of the batch.
			// A result that could not be saved is not acknowledged; it is tried again like a temporary failure.
			if ( self::needs_retry( $row ) ) {
				RPW_Jobs::retry( $job, 5 * MINUTE_IN_SECONDS * ( 1 + 2 * (int) $job->attempts ) );
			} else {
				RPW_Jobs::complete( $job );
			}
			self::collect_alert( $pid );
		}
		RPW_Jobs::unlock( self::LOCK, $lock );

		// The batch ended normally: replace the watchdog with the real next batch.
		wp_clear_scheduled_hook( self::HOOK_CONTINUE );
		if ( RPW_Jobs::count() > 0 ) {
			$next = RPW_Jobs::next_time();
			wp_schedule_single_event( max( time() + MINUTE_IN_SECONDS, (int) $next ), self::HOOK_CONTINUE );
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
		$product_id = (int) $product_id;
		$links      = RPW_Settings::links( $product_id );
		$delay      = min( 3, (int) RPW_Settings::get( 'delay' ) );
		$checked    = 0;
		$queued     = 0;
		$started    = time();
		// Shorter timeout while the admin waits; background checks keep the normal one.
		$short = static function ( $args ) {
			$args['timeout'] = min( 8, (int) $args['timeout'] );
			return $args;
		};
		// The same lock as the background batches: competitor sites never get two requests
		// from this shop at once. While a batch holds it, every link goes to the queue instead.
		$lock = RPW_Jobs::lock( self::LOCK, 60 );
		add_filter( 'rpw_request_args', $short, 99 );
		foreach ( $links as $link ) {
			$hash = RPW_DB::hash( $link['url'] );
			// Stop starting new requests after ~12 seconds so the page never hits a 30 s PHP limit.
			if ( ! $lock || $checked >= self::NOW_LIMIT || ( $checked > 0 && time() - $started > 12 ) ) {
				RPW_Jobs::enqueue( $product_id, $hash );
				++$queued;
				continue;
			}
			if ( $checked > 0 ) {
				sleep( $delay );
			}
			$row = self::check_link( $product_id, $link );
			if ( self::needs_retry( $row ) ) {
				// Temporary failure, or the result was not saved: keep (or add) a queued job so the background tries again.
				RPW_Jobs::enqueue( $product_id, $hash, 5 * MINUTE_IN_SECONDS );
			} else {
				// This link is fresh now; a copy still waiting in the queue is not needed.
				RPW_Jobs::clear( $product_id, $hash );
			}
			++$checked;
		}
		remove_filter( 'rpw_request_args', $short, 99 );
		if ( $lock ) {
			RPW_Jobs::unlock( self::LOCK, $lock );
		}

		if ( $checked ) {
			self::collect_alert( $product_id );
		}
		if ( self::ensure_scheduled() < 1 ) {
			// Nothing is left for the background, so no batch will send the alert: send it now.
			self::send_alerts();
		}
		return array(
			'checked' => $checked,
			'queued'  => $queued,
		);
	}

	/**
	 * Fetch one competitor page and read its price, without saving anything.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $link       url, label, factor, currency, match.
	 * @return array{row: array, found: array|null} The row that would be saved, and what the extractor saw.
	 */
	public static function inspect_link( $product_id, array $link ) {
		$args = array(
			'timeout'             => 15,
			'redirection'         => 3,
			'limit_response_size' => 10 * MB_IN_BYTES,
			// Says what is visiting, but not whose shop: the address of your site is never sent.
			'user-agent'          => sprintf( 'Mozilla/5.0 (compatible; RivalPriceWatch/%s)', RPW_VERSION ),
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

		$row   = array(
			'price'    => null,
			'currency' => null,
			'in_stock' => null,
			'status'   => 'ok',
			'message'  => '',
			'retry'    => false,
		);
		$found = null;

		if ( is_wp_error( $response ) ) {
			// Timeouts and connection problems often pass: worth another try later.
			$row['status']  = 'error';
			$row['message'] = $response->get_error_message();
			$row['retry']   = true;
			return compact( 'row', 'found' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$row['status'] = 'error';
			/* translators: %d: HTTP status code. */
			$row['message'] = sprintf( __( 'The page answered with HTTP %d.', 'rival-price-watch' ), $code );
			if ( in_array( $code, array( 403, 429, 503 ), true ) ) {
				$row['message'] .= ' ' . __( 'The site may be blocking automatic checks.', 'rival-price-watch' );
			}
			$row['retry'] = 408 === $code || 429 === $code || $code >= 500;
			return compact( 'row', 'found' );
		}

		$match = isset( $link['match'] ) ? (string) $link['match'] : '';
		$found = RPW_Extractor::extract( wp_remote_retrieve_body( $response ), $match, isset( $link['currency'] ) ? (string) $link['currency'] : '' );

		/**
		 * Filter the extracted result, e.g. to add support for a site
		 * that does not publish structured price data.
		 *
		 * @param array  $found Result: price, currency, in_stock, source, name, sku, candidates, matched.
		 * @param string $html  Page HTML.
		 * @param array  $link  The competitor link.
		 */
		$found = apply_filters( 'rpw_extracted_price', $found, wp_remote_retrieve_body( $response ), $link );

		if ( null === $found['price'] ) {
			if ( '' !== $match && isset( $found['matched'] ) && false === $found['matched'] ) {
				$row['status'] = 'nomatch';
				/* translators: 1: the variant text the shop owner typed, 2: number of offers found on the page. */
				$row['message'] = sprintf( __( 'None of the %2$d offers on the page matches "%1$s". Check the variant text for this link.', 'rival-price-watch' ), $match, (int) $found['candidates'] );
			} else {
				$row['status']  = 'noprice';
				$row['message'] = __( 'No price found on the page. The site does not publish price data that can be read automatically.', 'rival-price-watch' );
			}
			return compact( 'row', 'found' );
		}

		// Currency: the one chosen for this link, else what the page says. Nothing is assumed.
		$from = '' !== $link['currency'] ? $link['currency'] : RPW_Currency::normalize( $found['currency'] );
		$raw  = round( (float) $found['price'] * (float) $link['factor'], 4 );

		// No real price is this large (the database column holds up to about 10^16), so a page
		// claiming one is broken or hostile. Report it instead of failing to save it.
		if ( ! is_finite( $raw ) || $raw > self::MAX_PRICE ) {
			$row['status']  = 'noprice';
			$row['message'] = __( 'The page shows a price that cannot be real, so it was not used.', 'rival-price-watch' );
			return compact( 'row', 'found' );
		}

		// With a variant hint, only a price that was shown to belong to that variant is compared.
		if ( '' !== trim( $match ) && true !== ( $found['matched'] ?? null ) ) {
			$row['status']    = 'unverified';
			$row['raw_price'] = $raw;
			if ( $from ) {
				$row['raw_currency'] = $from;
			}
			$row['in_stock'] = $found['in_stock'];
			$row['message']  = sprintf(
				/* translators: %s: the variant text the shop owner typed. */
				__( 'A price was found, but the page does not show that it belongs to "%s", so it is not compared. Clear the variant text if this page only sells one version.', 'rival-price-watch' ),
				$match
			);
			return compact( 'row', 'found' );
		}
		if ( ! $from ) {
			// Strict: a price whose currency is unknown is never compared or used for alerts.
			$row['status']    = 'nocurrency';
			$row['raw_price'] = $raw;
			$row['in_stock']  = $found['in_stock'];
			$row['message']   = sprintf(
				/* translators: %s: the number found on the competitor page. */
				__( 'The page shows the price %s but does not say which currency it is in. Choose the currency for this link to compare it.', 'rival-price-watch' ),
				rtrim( rtrim( number_format( $raw, 4, '.', ',' ), '0' ), '.' )
			);
			return compact( 'row', 'found' );
		}

		$row['raw_price']    = $raw;
		$row['raw_currency'] = $from;
		$row['currency']     = RPW_Currency::shop();
		$row['in_stock']     = $found['in_stock'];
		$row['message']      = $found['source'];
		if ( ! empty( $found['name'] ) ) {
			$row['message'] .= ' | ' . $found['name'] . ( ! empty( $found['sku'] ) ? ' [' . $found['sku'] . ']' : '' );
		}

		$converted = RPW_Currency::to_shop( $raw, $from );
		if ( null === $converted ) {
			$row['status'] = 'norate';
			/* translators: 1: competitor currency code, 2: shop currency code. */
			$row['message'] = sprintf( __( 'This site sells in %1$s. Add a %1$s to %2$s exchange rate in Settings to compare it.', 'rival-price-watch' ), $from, RPW_Currency::shop() );
		} else {
			$row['price'] = round( $converted, 4 );
		}
		return compact( 'row', 'found' );
	}

	/**
	 * Fetch one competitor page and save its price.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $link       url, label, factor, currency, match.
	 * @return array Saved row. "retry" is true when the failure may pass (timeout, HTTP 429 / 5xx).
	 *               "saved" is false when the result could not be written to the database.
	 */
	public static function check_link( $product_id, array $link ) {
		$result       = self::inspect_link( $product_id, $link );
		$row          = $result['row'];
		$row['saved'] = RPW_DB::add( $product_id, $link['url'], $row );
		return $row;
	}

	/**
	 * Should this result be checked again later instead of being treated as done?
	 *
	 * @param array $row Row from check_link().
	 * @return bool True for temporary failures and for results that were not saved.
	 */
	private static function needs_retry( array $row ) {
		return ! empty( $row['retry'] ) || ( isset( $row['saved'] ) && false === $row['saved'] );
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
		$out     = self::summary_from( $own, RPW_Settings::links( $product_id ), RPW_DB::latest_for_product( $product_id ) );

		$out['product'] = $product;
		return $out;
	}

	/**
	 * Summary worked out from data already in memory (no database access).
	 *
	 * @param float $own    Your price in the shop currency.
	 * @param array $links  Links of the product.
	 * @param array $latest Latest rows keyed by link hash.
	 * @return array own, rows, lowest, diff, gap, checked.
	 */
	public static function summary_from( $own, array $links, array $latest ) {
		$rows    = array();
		$lowest  = null;
		$checked = null;

		foreach ( $links as $link ) {
			$hash   = RPW_DB::hash( $link['url'] );
			$last   = isset( $latest[ $hash ] ) ? $latest[ $hash ] : null;
			$rows[] = array(
				'link' => $link,
				'last' => $last,
			);
			if ( $last ) {
				$checked = ( null === $checked || $last->checked_at > $checked ) ? $last->checked_at : $checked;
				$p = self::current_price( $last );
				if ( null !== $p && '0' !== (string) $last->in_stock ) {
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
			'own'     => $own,
			'rows'    => $rows,
			'lowest'  => $lowest,
			'diff'    => $diff,
			'gap'     => $gap,
			'checked' => $checked,
		);
	}

	/**
	 * A result's price in the shop currency, converted with today's exchange rates.
	 *
	 * The rival's own price and currency are kept with every result, so changing or
	 * adding a rate is used at once, the same way the markets and alerts use it.
	 * Results saved without them (older versions) fall back to the stored amount.
	 *
	 * @param object|null $last Result row.
	 * @return float|null Null when the result has no comparable price or no rate is known.
	 */
	public static function current_price( $last ) {
		if ( ! $last || ! in_array( $last->status, array( 'ok', 'norate' ), true ) ) {
			return null;
		}
		if ( null !== $last->raw_price && ! empty( $last->raw_currency ) ) {
			$p = RPW_Currency::to_shop( (float) $last->raw_price, strtoupper( $last->raw_currency ) );
		} elseif ( 'ok' === $last->status && null !== $last->price && ( empty( $last->currency ) || strtoupper( $last->currency ) === RPW_Currency::shop() ) ) {
			$p = (float) $last->price;
		} else {
			$p = null;
		}
		return null !== $p && $p > 0 ? round( (float) $p, 4 ) : null;
	}

	/**
	 * Which market (currency) a rival belongs to.
	 *
	 * Rial and toman are the same money (1 toman = 10 rials), so for a rial or toman
	 * shop a rival in the other unit is compared in the shop's own market, exactly.
	 *
	 * @param array       $link Link.
	 * @param object|null $last Latest result.
	 * @return string Currency code.
	 */
	public static function market_of( array $link, $last ) {
		$shop = RPW_Currency::shop();
		$cur  = $last && ! empty( $last->raw_currency ) ? strtoupper( $last->raw_currency )
			: ( '' !== $link['currency'] ? $link['currency'] : $shop );
		$iran = array( 'IRR', 'IRT' );
		if ( $cur !== $shop && in_array( $cur, $iran, true ) && in_array( $shop, $iran, true ) ) {
			return $shop;
		}
		return $cur;
	}

	/**
	 * A result's price in the currency of its market (see market_of()).
	 *
	 * @param object $last   Result row.
	 * @param string $market Market currency.
	 * @return float|null
	 */
	public static function price_in_market( $last, $market ) {
		if ( null !== $last->raw_price ) {
			$from = ! empty( $last->raw_currency ) ? strtoupper( $last->raw_currency ) : $market;
			if ( $from === $market ) {
				return (float) $last->raw_price;
			}
			// Only rial ⇄ toman lands here (market_of() merges nothing else): exact, no rate needed.
			return RPW_Currency::convert( (float) $last->raw_price, $from, $market, array() );
		}
		return 'ok' === $last->status && null !== $last->price ? (float) $last->price : null;
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
		$shop_p  = $product ? (float) $product->get_price() : 0.0;
		return self::markets_from( $shop_p, RPW_Settings::links( $product_id ), RPW_DB::latest_for_product( $product_id ), RPW_Settings::market_prices( $product_id ) );
	}

	/**
	 * Markets worked out from data already in memory (no database access).
	 *
	 * @param float $shop_p Your price in the shop currency.
	 * @param array $links  Links of the product.
	 * @param array $latest Latest rows keyed by link hash.
	 * @param array $set    Your own prices per foreign currency.
	 * @return array<string, array>
	 */
	public static function markets_from( $shop_p, array $links, array $latest, array $set ) {
		$shop  = RPW_Currency::shop();
		$rates = RPW_Currency::rates();
		$out   = array();

		foreach ( $links as $link ) {
			$last = $latest[ RPW_DB::hash( $link['url'] ) ] ?? null;
			$cur  = self::market_of( $link, $last );
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
			$m            = &$out[ $cur ];
			$m['checked'] = ( null === $m['checked'] || $last->checked_at > $m['checked'] ) ? $last->checked_at : $m['checked'];
			// Price in this market's currency (any check that found a price, even without an exchange rate).
			$native = self::price_in_market( $last, $cur );
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
	 * Everything the overview needs to filter and sort all watched products,
	 * read with two queries however many products there are.
	 *
	 * Your price comes straight from the product's price field here; the rows
	 * that are shown are worked out again with the full WooCommerce product.
	 *
	 * @return array<int, array{own: float, links: array, latest: array, set: array}>
	 */
	public static function index() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type = 'product' AND p.post_status IN ('publish','private','draft','pending')
			AND pm.meta_key IN ('_rpw_links','_rpw_market_prices','_price')
			AND pm.post_id IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_rpw_links' )"
		);
		$latest = RPW_DB::latest_all();
		$out    = array();
		foreach ( (array) $rows as $r ) {
			$pid = (int) $r->post_id;
			if ( ! isset( $out[ $pid ] ) ) {
				$out[ $pid ] = array(
					'own'    => 0.0,
					'links'  => array(),
					'latest' => isset( $latest[ $pid ] ) ? $latest[ $pid ] : array(),
					'set'    => array(),
				);
			}
			if ( '_price' === $r->meta_key ) {
				// A variable product has one row per variation price: the lowest is its "from" price.
				$price = (float) $r->meta_value;
				if ( $price > 0 && ( $out[ $pid ]['own'] <= 0 || $price < $out[ $pid ]['own'] ) ) {
					$out[ $pid ]['own'] = $price;
				}
			} elseif ( '_rpw_links' === $r->meta_key ) {
				$out[ $pid ]['links'] = RPW_Settings::clean_links( maybe_unserialize( $r->meta_value ) );
			} else {
				$out[ $pid ]['set'] = RPW_Settings::clean_market_prices( maybe_unserialize( $r->meta_value ) );
			}
		}
		foreach ( $out as $pid => $item ) {
			if ( ! $item['links'] ) {
				unset( $out[ $pid ] );
			}
		}
		return $out;
	}

	/**
	 * How long a link's last real answer stands in for a failed fetch when deciding alerts.
	 */
	const ALERT_GRACE = 604800; // 7 days.

	/**
	 * Is a link of this market waiting to be read again after its settings changed?
	 *
	 * @param array $m Market from markets_from().
	 * @return bool
	 */
	private static function awaiting_check( array $m ) {
		foreach ( $m['rows'] as $r ) {
			if ( $r['last'] && 'changed' === $r['last']->status ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Markets for alert decisions.
	 *
	 * A fetch that failed (timeout, HTTP error) says nothing about the rival's price,
	 * so for such a link the last result where its page really answered is used
	 * (up to ALERT_GRACE old). A temporary failure therefore neither clears the
	 * "already emailed" record nor drops an alert that is waiting to be sent.
	 *
	 * @param int $product_id Product ID.
	 * @return array See markets_from().
	 */
	public static function alert_markets( $product_id ) {
		$product = wc_get_product( $product_id );
		$shop_p  = $product ? (float) $product->get_price() : 0.0;
		$links   = RPW_Settings::links( $product_id );
		$latest  = RPW_DB::latest_for_product( $product_id );
		$since   = gmdate( 'Y-m-d H:i:s', time() - self::ALERT_GRACE );
		foreach ( $links as $link ) {
			$hash = RPW_DB::hash( $link['url'] );
			if ( isset( $latest[ $hash ] ) && 'error' === $latest[ $hash ]->status ) {
				$answered = RPW_DB::last_answered( $product_id, $hash, $since );
				if ( $answered ) {
					$latest[ $hash ] = $answered;
				}
			}
		}
		return self::markets_from( $shop_p, $links, $latest, RPW_Settings::market_prices( $product_id ) );
	}

	/**
	 * Remember a product for the alert email if a rival is cheaper and the
	 * situation changed since the last alert that was really sent.
	 *
	 * The waiting alert is stored on the product (_rpw_pending) and stays there
	 * until the email has gone out. Only then is it written to _rpw_alerted.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function collect_alert( $product_id ) {
		if ( ! RPW_Settings::get( 'alerts' ) ) {
			return;
		}
		$alerted = get_post_meta( $product_id, '_rpw_alerted', true );
		$alerted = is_array( $alerted ) ? $alerted : array();
		$pending = get_post_meta( $product_id, '_rpw_pending', true );
		$pending = is_array( $pending ) ? $pending : array();
		$was     = array( $alerted, $pending );
		$min     = (float) RPW_Settings::get( 'alert_percent' );
		$markets = self::alert_markets( $product_id );

		// One check per market (currency): compare in that currency.
		foreach ( $markets as $cur => $m ) {
			$cheaper = $m['lowest'] && $m['own'] > 0 && $m['lowest']['price'] < $m['own']
				&& ( $m['own'] - $m['lowest']['price'] ) / $m['own'] * 100 >= $min;
			if ( ! $cheaper ) {
				unset( $pending[ $cur ] );
				// While a link waits to be read again after its settings changed, nothing is known
				// about this market yet: keep the record of what was emailed, so the same
				// situation is not emailed again when the new check finds it unchanged.
				if ( ! self::awaiting_check( $m ) ) {
					unset( $alerted[ $cur ] );
				}
				continue;
			}
			$key = md5( $m['lowest']['url'] . '|' . $m['lowest']['price'] . '|' . $m['own'] );
			if ( ( $alerted[ $cur ] ?? '' ) === $key ) {
				// This exact situation was already emailed.
				unset( $pending[ $cur ] );
				continue;
			}
			$pending[ $cur ] = array(
				'key'    => $key,
				'approx' => 'converted' === $m['own_source'],
				'own'    => $m['own'],
				'lowest' => $m['lowest'],
			);
		}
		// Markets that no longer exist (link removed, currency changed).
		foreach ( array_keys( $pending ) as $cur ) {
			if ( ! isset( $markets[ $cur ] ) ) {
				unset( $pending[ $cur ] );
			}
		}
		foreach ( array_keys( $alerted ) as $cur ) {
			if ( ! isset( $markets[ $cur ] ) ) {
				unset( $alerted[ $cur ] );
			}
		}

		if ( $alerted !== $was[0] ) {
			if ( $alerted ) {
				update_post_meta( $product_id, '_rpw_alerted', $alerted );
			} else {
				delete_post_meta( $product_id, '_rpw_alerted' );
			}
		}
		if ( $pending !== $was[1] ) {
			if ( $pending ) {
				update_post_meta( $product_id, '_rpw_pending', $pending );
			} else {
				delete_post_meta( $product_id, '_rpw_pending' );
			}
		}
	}

	/**
	 * Send one email listing every product where a rival became cheaper.
	 *
	 * An alert counts as sent only when wp_mail() reports success. When sending
	 * fails, the alerts stay waiting and sending is tried again later.
	 *
	 * @return bool|null True when sent, false when sending failed, null when there was nothing to send.
	 */
	public static function send_alerts() {
		if ( ! RPW_Settings::get( 'alerts' ) ) {
			return null;
		}
		// Two requests finishing at the same moment must not both send the email.
		$lock = RPW_Jobs::lock( self::MAIL_LOCK, 2 * MINUTE_IN_SECONDS );
		if ( ! $lock ) {
			return null;
		}
		$result = self::send_alerts_locked();
		RPW_Jobs::unlock( self::MAIL_LOCK, $lock );
		return $result;
	}

	/**
	 * The work of send_alerts(), run while holding the mail lock.
	 *
	 * @return bool|null
	 */
	private static function send_alerts_locked() {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_rpw_pending', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare'   => 'EXISTS',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		if ( empty( $ids ) ) {
			return null;
		}

		// Group lines by market currency.
		$groups = array();
		$sent   = array();
		foreach ( $ids as $pid ) {
			$pid = (int) $pid;
			// The alert may have waited (email retry): check it again against today's own price,
			// links, rival prices and threshold, so the email never reports a comparison that is no longer true.
			self::collect_alert( $pid );
			$pending = get_post_meta( $pid, '_rpw_pending', true );
			$product = wc_get_product( $pid );
			if ( ! $product || ! is_array( $pending ) || ! $pending ) {
				delete_post_meta( $pid, '_rpw_pending' );
				continue;
			}
			foreach ( $pending as $cur => $a ) {
				if ( empty( $a['lowest']['url'] ) || empty( $a['key'] ) ) {
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
				$sent[ $pid ][ $cur ] = $a['key'];
			}
		}
		if ( ! $sent ) {
			// Every waiting alert turned out to be no longer true: nothing is left to retry.
			delete_option( 'rpw_alert_retries' );
			delete_option( 'rpw_alert_error' );
			wp_clear_scheduled_hook( self::HOOK_ALERTS );
			return null;
		}

		$to = RPW_Settings::get( 'alert_email' );
		if ( ! is_email( $to ) ) {
			update_option( 'rpw_alert_error', __( 'The alert email address in Settings is not valid.', 'rival-price-watch' ), false );
			return false;
		}

		$sections = array();
		foreach ( $groups as $cur => $lines ) {
			$sections[] = $cur . ' · ' . RPW_Currency::name( $cur ) . "\n" . implode( "\n", $lines );
		}

		/* translators: %d: number of products. */
		$subject = sprintf( _n( 'Rival Price Watch: a competitor is cheaper on %d product', 'Rival Price Watch: competitors are cheaper on %d products', count( $sent ), 'rival-price-watch' ), count( $sent ) );
		$body    = __( 'These products are now cheaper at a competitor:', 'rival-price-watch' ) . "\n\n" . implode( "\n\n", $sections ) . "\n\n" . admin_url( 'admin.php?page=rpw-prices' );

		$reason  = '';
		$catcher = static function ( $error ) use ( &$reason ) {
			if ( is_wp_error( $error ) ) {
				$reason = $error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $catcher );
		$ok = wp_mail( $to, $subject, $body );
		remove_action( 'wp_mail_failed', $catcher );

		if ( ! $ok ) {
			// Keep every alert waiting and try again: after 15, 30, 45… minutes, then at the end of each run.
			$tries = (int) get_option( 'rpw_alert_retries', 0 ) + 1;
			update_option( 'rpw_alert_retries', $tries, false );
			update_option( 'rpw_alert_error', '' !== $reason ? $reason : __( 'WordPress could not send the email.', 'rival-price-watch' ), false );
			if ( $tries <= self::MAIL_RETRIES && ! wp_next_scheduled( self::HOOK_ALERTS ) ) {
				wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS * $tries, self::HOOK_ALERTS );
			}
			return false;
		}

		// Sent. Mark exactly what was in the email; anything that changed meanwhile stays waiting.
		foreach ( $sent as $pid => $keys ) {
			$alerted = get_post_meta( $pid, '_rpw_alerted', true );
			$alerted = is_array( $alerted ) ? $alerted : array();
			$pending = get_post_meta( $pid, '_rpw_pending', true );
			$pending = is_array( $pending ) ? $pending : array();
			foreach ( $keys as $cur => $key ) {
				$alerted[ $cur ] = $key;
				if ( isset( $pending[ $cur ]['key'] ) && $pending[ $cur ]['key'] === $key ) {
					unset( $pending[ $cur ] );
				}
			}
			update_post_meta( $pid, '_rpw_alerted', $alerted );
			if ( $pending ) {
				update_post_meta( $pid, '_rpw_pending', $pending );
			} else {
				delete_post_meta( $pid, '_rpw_pending' );
			}
		}
		delete_option( 'rpw_alert_retries' );
		delete_option( 'rpw_alert_error' );
		wp_clear_scheduled_hook( self::HOOK_ALERTS );
		return true;
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
			if ( in_array( $currency, array( 'KWD', 'BHD', 'OMR', 'JOD', 'TND' ), true ) ) {
				$decimals = 3;
			}

			return wc_price(
				$amount,
				array(
					'currency' => $currency,
					'decimals' => $currency === RPW_Currency::shop() ? wc_get_price_decimals() : $decimals,
				)
			);
		}

		$amount = (float) $amount;
		return esc_html( number_format_i18n( $amount, floor( $amount ) === $amount ? 0 : ( round( $amount, 2 ) === $amount ? 2 : 3 ) ) . ' ' . $currency );
	}
}
