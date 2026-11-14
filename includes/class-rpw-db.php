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

	const DB_VERSION = '2';

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
	 * Create or update the table.
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
	 * Hash used to identify a link.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function hash( $url ) {
		return md5( strtolower( trim( $url ) ) );
	}

	/**
	 * Save one check result.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $url        Competitor URL.
	 * @param array  $row        price, currency, in_stock, status, message.
	 */
	public static function add( $product_id, $url, array $row ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'product_id' => (int) $product_id,
				'link_hash'  => self::hash( $url ),
				'url'        => esc_url_raw( $url ),
				'price'      => isset( $row['price'] ) ? $row['price'] : null,
				'currency'     => isset( $row['currency'] ) ? substr( (string) $row['currency'], 0, 10 ) : null,
				'raw_price'    => isset( $row['raw_price'] ) ? $row['raw_price'] : null,
				'raw_currency' => isset( $row['raw_currency'] ) ? substr( (string) $row['raw_currency'], 0, 10 ) : null,
				'in_stock'   => isset( $row['in_stock'] ) ? (int) $row['in_stock'] : null,
				'status'     => isset( $row['status'] ) ? $row['status'] : 'ok',
				'message'    => isset( $row['message'] ) ? substr( (string) $row['message'], 0, 255 ) : '',
				'checked_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Latest result for each link of a product, keyed by link hash.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, object>
	 */
	public static function latest_for_product( $product_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.* FROM {$table} p INNER JOIN ( SELECT link_hash, MAX(id) AS max_id FROM {$table} WHERE product_id = %d GROUP BY link_hash ) l ON p.id = l.max_id", $product_id ) );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r->link_hash ] = $r;
		}
		return $out;
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
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT price, in_stock, checked_at FROM {$table} WHERE product_id = %d AND link_hash = %s AND status = 'ok' AND ( currency = %s OR currency IS NULL ) ORDER BY id DESC LIMIT %d", $product_id, self::hash( $url ), RPW_Currency::shop(), $limit ) );
	}

	/**
	 * Delete history older than N days.
	 *
	 * @param int $days Days to keep.
	 */
	public static function prune( $days ) {
		global $wpdb;
		$days = max( 7, (int) $days );
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE checked_at < ( UTC_TIMESTAMP() - INTERVAL %d DAY )", $days ) );
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
	}
}
