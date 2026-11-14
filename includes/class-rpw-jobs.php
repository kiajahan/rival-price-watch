<?php
/**
 * Background job queue: one row per competitor link waiting to be checked.
 *
 * @package RivalPriceWatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Custom table {prefix}rpw_jobs.
 *
 * A job is removed only after its check is saved, so a batch that dies half-way
 * loses nothing. Workers take a job with a compare-and-set update, so two
 * workers can never check the same link, and a link can be queued only once.
 */
class RPW_Jobs {

	/**
	 * A link is tried this many times in one run before it is given up.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * A job taken by a worker that has not finished after this long is given back.
	 */
	const STALL_SECONDS = 600;

	/**
	 * Table name with prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rpw_jobs';
	}

	/**
	 * Create or update the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			link_hash char(32) NOT NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			available_at datetime NOT NULL,
			claim varchar(32) NOT NULL DEFAULT '',
			claimed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job (product_id,link_hash),
			KEY ready (claim,available_at)
		) {$charset};"
		);
	}

	/**
	 * UTC time as a MySQL date.
	 *
	 * @param int $offset Seconds from now.
	 * @return string
	 */
	private static function now( $offset = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + (int) $offset );
	}

	/**
	 * Queue links. A link that is already queued is left as it is.
	 *
	 * @param array<int, array{0: int, 1: string}> $jobs Pairs of product ID and link hash.
	 * @return int How many were added.
	 */
	public static function enqueue_many( array $jobs ) {
		global $wpdb;
		$table = self::table();
		$added = 0;
		$now   = self::now();
		foreach ( array_chunk( $jobs, 200 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $job ) {
				$values[] = $wpdb->prepare( '(%d, %s, 0, %s, %s)', (int) $job[0], (string) $job[1], $now, '' );
			}
			if ( $values ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- every value is prepared above.
				$added += (int) $wpdb->query( "INSERT IGNORE INTO {$table} (product_id, link_hash, attempts, available_at, claim) VALUES " . implode( ', ', $values ) );
			}
		}
		return $added;
	}

	/**
	 * Queue one link.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $hash       Link hash.
	 * @return bool True when it was added, false when it was already queued.
	 */
	public static function enqueue( $product_id, $hash ) {
		return 1 === self::enqueue_many( array( array( (int) $product_id, (string) $hash ) ) );
	}

	/**
	 * How many links are waiting (including ones being checked right now).
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * When the next waiting job may run.
	 *
	 * @return int|null Unix time, or null when nothing is waiting.
	 */
	public static function next_time() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$at = $wpdb->get_var( "SELECT MIN(available_at) FROM {$table}" );
		return $at ? (int) strtotime( $at . ' UTC' ) : null;
	}

	/**
	 * Give back jobs whose worker stopped (timeout, crash, server restart),
	 * and drop jobs that failed too many times.
	 */
	public static function release_stalled() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET claim = '', claimed_at = NULL, attempts = attempts + 1, available_at = %s WHERE claim <> '' AND claimed_at < %s", self::now(), self::now( -self::STALL_SECONDS ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE claim = '' AND attempts >= %d", self::MAX_ATTEMPTS ) );
	}

	/**
	 * Take the next job that is ready. Safe when several workers run at once:
	 * the job is ours only if the update that marks it changed exactly one row.
	 *
	 * @return object|null Job with id, product_id, link_hash, attempts.
	 */
	public static function claim() {
		global $wpdb;
		$table = self::table();
		$token = md5( uniqid( (string) wp_rand(), true ) );
		for ( $try = 0; $try < 5; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$job = $wpdb->get_row( $wpdb->prepare( "SELECT id, product_id, link_hash, attempts FROM {$table} WHERE claim = '' AND available_at <= %s ORDER BY id ASC LIMIT 1", self::now() ) );
			if ( ! $job ) {
				return null;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$got = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET claim = %s, claimed_at = %s WHERE id = %d AND claim = ''", $token, self::now(), (int) $job->id ) );
			if ( 1 === $got ) {
				$job->claim = $token;
				return $job;
			}
		}
		return null;
	}

	/**
	 * The job is done: remove it.
	 *
	 * @param object $job Job from claim().
	 */
	public static function complete( $job ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $job->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * The check failed for a reason that may pass (timeout, HTTP 429 / 5xx): try again later.
	 *
	 * @param object $job   Job from claim().
	 * @param int    $delay Seconds to wait.
	 * @return bool False when the job used up its attempts and was removed.
	 */
	public static function retry( $job, $delay ) {
		global $wpdb;
		$attempts = (int) $job->attempts + 1;
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			self::complete( $job );
			return false;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table(),
			array(
				'claim'        => '',
				'claimed_at'   => null,
				'attempts'     => $attempts,
				'available_at' => self::now( $delay ),
			),
			array( 'id' => (int) $job->id )
		);
		return true;
	}

	/**
	 * Remove the jobs of a product (or of one of its links).
	 *
	 * @param int    $product_id Product ID.
	 * @param string $hash       Optional link hash.
	 */
	public static function clear( $product_id, $hash = '' ) {
		global $wpdb;
		$where = array( 'product_id' => (int) $product_id );
		if ( $hash ) {
			$where['link_hash'] = $hash;
		}
		$wpdb->delete( self::table(), $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Take a named lock, or fail at once when someone else holds it.
	 *
	 * The lock is one row in the options table. Creating it is atomic (the option
	 * name is unique), and an expired lock is taken over with a compare-and-set
	 * update, so two requests can never both get it.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds after which a lock left behind by a dead request expires.
	 * @return string|false Value to pass to unlock(), or false.
	 */
	public static function lock( $name, $ttl ) {
		global $wpdb;
		$value = ( time() + (int) $ttl ) . '|' . md5( uniqid( (string) wp_rand(), true ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$made = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
		if ( $made ) {
			return $value;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( null === $held || (int) $held > time() ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$took = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $held ) );
		return 1 === $took ? $value : false;
	}

	/**
	 * Release a lock taken with lock(). A lock that expired and was taken over by someone else is left alone.
	 *
	 * @param string $name  Lock name.
	 * @param string $value Value returned by lock().
	 */
	public static function unlock( $name, $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $value ) );
	}
}
