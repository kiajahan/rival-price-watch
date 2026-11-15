<?php
/**
 * Price history storage.
 *
 * @package RivalPriceWatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Custom table {prefix}rpw_prices: one row per check of one competitor link.
 */
class RPW_DB {

	const DB_VERSION = '3';

	/**
	 * Latest rows per product, kept for the length of the request.
	 *
	 * @var array<int, array<string, object>>
	 */
	private static $latest = array();

	/**
	 * Table name with prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rpw_prices';
	}

	/**
	 * Create or update the tables, then move data kept in older formats.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			link_hash char(32) NOT NULL,
			url text NOT NULL,
			price decimal(20,4) DEFAULT NULL,
			currency varchar(10) DEFAULT NULL,
			raw_price decimal(20,4) DEFAULT NULL,
			raw_currency varchar(10) DEFAULT NULL,
			in_stock tinyint(1) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'ok',
			message varchar(255) NOT NULL DEFAULT '',
			checked_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY product_link (product_id,link_hash,checked_at)
		) {$charset};";
		dbDelta( $sql );
		RPW_Jobs::install();

		$from = (string) get_option( 'rpw_db_version', '' );
		if ( '' !== $from && version_compare( $from, '3', '<' ) ) {
			self::upgrade_to_3();
		}
		update_option( 'rpw_db_version', self::DB_VERSION, false );
	}

	/**
	 * Run install when the stored version is old.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'rpw_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Version 3: link hashes keep the case of the path and query, the queue is a
	 * table, and waiting alerts are stored on the product.
	 */
	private static function upgrade_to_3() {
		global $wpdb;
		$table = self::table();

		// 1. Re-key history rows whose URL has capital letters in the path or query.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$metas = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_rpw_links' ) );
		foreach ( (array) $metas as $meta ) {
			$links = maybe_unserialize( $meta->meta_value );
			foreach ( is_array( $links ) ? $links : array() as $link ) {
				if ( empty( $link['url'] ) ) {
					continue;
				}
				$old = self::legacy_hash( $link['url'] );
				$new = self::hash( $link['url'] );
				if ( $old !== $new ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( $wpdb->prepare( "UPDATE %i SET link_hash = %s WHERE product_id = %d AND link_hash = %s AND url = %s", $table, $new, (int) $meta->post_id, $old, esc_url_raw( $link['url'] ) ) );
				}
			}
		}

		// 2. The queue used to be one option holding [product, link number] pairs.
		$queue = get_option( 'rpw_queue', array() );
		$jobs  = array();
		foreach ( is_array( $queue ) ? $queue : array() as $item ) {
			if ( ! is_array( $item ) || ! isset( $item[0], $item[1] ) ) {
				continue;
			}
			$links = RPW_Settings::links( (int) $item[0] );
			if ( isset( $links[ (int) $item[1] ] ) ) {
				$jobs[] = array( (int) $item[0], self::hash( $links[ (int) $item[1] ]['url'] ) );
			}
		}
		RPW_Jobs::enqueue_many( $jobs );
		delete_option( 'rpw_queue' );
		delete_transient( 'rpw_lock' );

		// 3. Alerts that were waiting to be emailed were already marked as sent. Undo that so they still go out.
		$pending = get_option( 'rpw_pending_alerts', array() );
		foreach ( is_array( $pending ) ? $pending : array() as $key => $alert ) {
			$pid = isset( $alert['product'] ) ? (int) $alert['product'] : (int) $key;
			if ( ! $pid ) {
				continue;
			}
			$alerted = get_post_meta( $pid, '_rpw_alerted', true );
			if ( is_array( $alerted ) ) {
				delete_post_meta( $pid, '_rpw_alerted' );
			}
			RPW_Checker::collect_alert( $pid );
		}
		delete_option( 'rpw_pending_alerts' );
	}

	/**
	 * Hash used to identify a link.
	 *
	 * Only the scheme and host are lower-cased: paths and query strings are
	 * case-sensitive, so /p/AbC and /p/abc are different pages.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function hash( $url ) {
		$url = trim( (string) $url );
		if ( preg_match( '#^([a-z][a-z0-9+.\-]*://[^/?\#]*)(.*)$#is', $url, $m ) ) {
			$url = strtolower( $m[1] ) . $m[2];
		}
		return md5( $url );
	}

	/**
	 * The hash used before version 3 (whole URL lower-cased).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function legacy_hash( $url ) {
		return md5( strtolower( trim( (string) $url ) ) );
	}

	/**
	 * Save one check result.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $url        Competitor URL.
	 * @param array  $row        price, currency, in_stock, status, message.
	 * @return bool True when the row was written.
	 */
	public static function add( $product_id, $url, array $row ) {
		global $wpdb;
		$written = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'product_id'   => (int) $product_id,
				'link_hash'    => self::hash( $url ),
				'url'          => esc_url_raw( $url ),
				'price'        => isset( $row['price'] ) ? $row['price'] : null,
				'currency'     => isset( $row['currency'] ) ? substr( (string) $row['currency'], 0, 10 ) : null,
				'raw_price'    => isset( $row['raw_price'] ) ? $row['raw_price'] : null,
				'raw_currency' => isset( $row['raw_currency'] ) ? substr( (string) $row['raw_currency'], 0, 10 ) : null,
				'in_stock'     => isset( $row['in_stock'] ) ? (int) $row['in_stock'] : null,
				'status'       => isset( $row['status'] ) ? $row['status'] : 'ok',
				'message'      => isset( $row['message'] ) ? self::cut( (string) $row['message'], 255 ) : '',
				'checked_at'   => current_time( 'mysql', true ),
			)
		);
		unset( self::$latest[ (int) $product_id ] );
		return is_int( $written ) && $written > 0;
	}

	/**
	 * Shorten text to a number of characters without breaking a multi-byte letter.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	private static function cut( $text, $max ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max );
	}

	/**
	 * Latest result for each link of a product, keyed by link hash.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, object>
	 */
	public static function latest_for_product( $product_id ) {
		global $wpdb;
		$product_id = (int) $product_id;
		if ( isset( self::$latest[ $product_id ] ) ) {
			return self::$latest[ $product_id ];
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.* FROM %i p INNER JOIN ( SELECT link_hash, MAX(id) AS max_id FROM %i WHERE product_id = %d GROUP BY link_hash ) l ON p.id = l.max_id", $table, $table, $product_id ) );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r->link_hash ] = $r;
		}
		self::$latest[ $product_id ] = $out;
		return $out;
	}

	/**
	 * Load the latest result of every link of every product with one query,
	 * so a list of many products does not run one query per product.
	 *
	 * @return array<int, array<string, object>> product id => link hash => row.
	 */
	public static function latest_all() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.* FROM %i p INNER JOIN ( SELECT MAX(id) AS max_id FROM %i GROUP BY product_id, link_hash ) l ON p.id = l.max_id", $table, $table ) );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->product_id ][ $r->link_hash ] = $r;
		}
		foreach ( $out as $pid => $set ) {
			self::$latest[ $pid ] = $set;
		}
		return $out;
	}

	/**
	 * Forget the rows remembered for this request.
	 */
	public static function flush() {
		self::$latest = array();
	}

	/**
	 * Last successful prices of one link, newest first.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $url        URL.
	 * @param int    $limit      How many.
	 * @return array<object>
	 */
	public static function history( $product_id, $url, $limit = 30 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT price, in_stock, checked_at FROM %i WHERE product_id = %d AND link_hash = %s AND status = 'ok' AND ( currency = %s OR currency IS NULL ) ORDER BY id DESC LIMIT %d", $table, $product_id, self::hash( $url ), RPW_Currency::shop(), $limit ) );
	}

	/**
	 * The newest result of one link whose page really answered (any status except
	 * "error"), so a failed fetch does not hide what the link last showed.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $hash       Link hash.
	 * @param string $since      Oldest check time to accept (UTC, Y-m-d H:i:s).
	 * @return object|null Full row, or null.
	 */
	public static function last_answered( $product_id, $hash, $since ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE product_id = %d AND link_hash = %s AND status <> 'error' AND checked_at >= %s ORDER BY id DESC LIMIT 1", $table, (int) $product_id, (string) $hash, (string) $since ) );
		return $row ? $row : null;
	}

	/**
	 * Delete history older than N days.
	 *
	 * @param int $days Days to keep.
	 */
	public static function prune( $days ) {
		global $wpdb;
		$days  = max( 7, (int) $days );
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE checked_at < %s", $table, gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		self::flush();
	}

	/**
	 * Delete all rows of a product (used when its links are removed).
	 *
	 * @param int    $product_id Product ID.
	 * @param string $hash       Optional link hash.
	 */
	public static function delete_product( $product_id, $hash = '' ) {
		global $wpdb;
		$where = array( 'product_id' => (int) $product_id );
		if ( $hash ) {
			$where['link_hash'] = $hash;
		}
		$wpdb->delete( self::table(), $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deleting rows; nothing to cache.
		unset( self::$latest[ (int) $product_id ] );
		RPW_Jobs::clear( $product_id, $hash );
	}
}
