<?php
/**
 * Integration tests: queue, retries, alert emails, upgrade from 0.1.0.
 *
 * These run inside a real WordPress + WooCommerce site with the plugin active.
 * Use a LOCAL TEST SITE ONLY: the script creates and deletes test products and
 * clears the plugin's queue and scheduled events. No competitor site is
 * contacted (HTTP is faked) and no email leaves the site (mail is faked).
 *
 *   php tests/integration/run.php /path/to/wordpress/wp-load.php
 *
 * @package RivalPriceWatch
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
$rpw_wp_load = isset( $argv[1] ) ? $argv[1] : getenv( 'RPW_WP_LOAD' );
if ( ! $rpw_wp_load || ! is_file( $rpw_wp_load ) ) {
	fwrite( STDERR, "Usage: php tests/integration/run.php /path/to/wordpress/wp-load.php\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST']      = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
require $rpw_wp_load;

if ( ! class_exists( 'RPW_Jobs' ) || ! class_exists( 'WooCommerce' ) ) {
	fwrite( STDERR, "Rival Price Watch and WooCommerce must be active on this site.\n" );
	exit( 2 );
}
$rpw_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
if ( ! in_array( $rpw_host, array( 'localhost', '127.0.0.1', '[::1]' ), true ) && ! preg_match( '/\.(test|local|localhost)$/', $rpw_host ) && ! in_array( '--force', $argv, true ) ) {
	fwrite( STDERR, "Refusing to run on {$rpw_host}: this is for local test sites. Add --force if you are sure.\n" );
	exit( 2 );
}
if ( RPW_Jobs::count() > 0 && ! in_array( '--force', $argv, true ) ) {
	fwrite( STDERR, "The plugin's queue is not empty (a check run is in progress). Let it finish, or add --force.\n" );
	exit( 2 );
}

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
	if ( $expected === $actual || ( is_float( $expected ) && is_numeric( $actual ) && abs( $expected - (float) $actual ) < 0.0001 ) ) {
		echo "ok   {$name}\n";
		return;
	}
	++$failures;
	echo "FAIL {$name}: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

// ---------------------------------------------------------------- test doubles.

$rpw_pages    = array(); // URL => HTML string, int HTTP code, or WP_Error.
$rpw_requests = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$rpw_pages, &$rpw_requests ) {
		if ( false === strpos( $url, 'rpw-test.invalid' ) ) {
			return $pre;
		}
		$rpw_requests[] = $url;
		$page           = isset( $rpw_pages[ $url ] ) ? $rpw_pages[ $url ] : 404;
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		return array(
			'headers'  => array(),
			'body'     => is_int( $page ) ? '' : $page,
			'response' => array(
				'code'    => is_int( $page ) ? $page : 200,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

$rpw_mail_ok = true;
$rpw_mails   = array();
remove_all_filters( 'pre_wp_mail' );
add_filter(
	'pre_wp_mail',
	static function ( $null, $atts ) use ( &$rpw_mail_ok, &$rpw_mails ) {
		if ( $rpw_mail_ok ) {
			$rpw_mails[] = $atts;
		}
		return $rpw_mail_ok;
	},
	10,
	2
);

$rpw_settings = array(
	'frequency'     => 'manual',
	'alerts'        => 1,
	'alert_email'   => 'owner@example.org',
	'alert_percent' => 0,
	'delay'         => 0,
	'keep_days'     => 180,
);
add_filter(
	'pre_option_' . RPW_Settings::OPTION,
	static function () use ( &$rpw_settings ) {
		return $rpw_settings;
	}
);

/**
 * A product page with one price in JSON-LD.
 *
 * @param float  $price    Price.
 * @param string $currency Currency.
 * @return string
 */
function rpw_page( $price, $currency ) {
	return '<html><head><script type="application/ld+json">' . wp_json_encode(
		array(
			'@type'  => 'Product',
			'name'   => 'Test product',
			'offers' => array(
				'@type'         => 'Offer',
				'price'         => (string) $price,
				'priceCurrency' => $currency,
				'availability'  => 'https://schema.org/InStock',
			),
		)
	) . '</script></head><body></body></html>';
}

/**
 * Create a test product with competitor links.
 *
 * @param float    $price Own price.
 * @param string[] $urls  Competitor URLs.
 * @return int Product ID.
 */
function rpw_product( $price, array $urls ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'RPW integration test ' . wp_generate_password( 6, false ) );
	$product->set_regular_price( (string) $price );
	$product->set_status( 'private' );
	$pid   = $product->save();
	$links = array();
	foreach ( $urls as $url ) {
		$links[] = array(
			'url'      => $url,
			'label'    => '',
			'factor'   => 1,
			'currency' => '',
			'match'    => '',
		);
	}
	update_post_meta( $pid, '_rpw_links', $links );
	return $pid;
}

/**
 * Number of jobs waiting for a product.
 *
 * @param int $pid Product ID.
 * @return int
 */
function rpw_jobs_of( $pid ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RPW_Jobs::table() . ' WHERE product_id = %d', $pid ) ); // phpcs:ignore
}

$shop    = RPW_Currency::shop();
$base    = 'https://rpw-test.invalid/' . wp_generate_password( 8, false ) . '/';
$created = array();

// Keep other products of this site out of the alert emails and restore them afterwards.
$rpw_saved = array();
foreach ( RPW_Checker::watched_products() as $other ) {
	$rpw_saved[ $other ] = array( get_post_meta( $other, '_rpw_alerted', true ), get_post_meta( $other, '_rpw_pending', true ) );
	delete_post_meta( $other, '_rpw_pending' );
}
$rpw_saved_options = array( get_option( 'rpw_alert_retries', null ), get_option( 'rpw_alert_error', null ), get_option( 'rpw_last_run_finished', null ), get_option( 'rpw_last_run_started', null ) );
wp_clear_scheduled_hook( RPW_Checker::HOOK_CONTINUE );
wp_clear_scheduled_hook( RPW_Checker::HOOK_ALERTS );
delete_option( 'rpw_alert_retries' );
delete_option( 'rpw_alert_error' );

try {
	global $wpdb;
	$jobs = RPW_Jobs::table();

	// ------------------------------------------------------------ 1. queue.
	echo "\n# Queue\n";
	$p1 = rpw_product( 100, array( $base . 'a', $base . 'b', $base . 'c' ) );
	$created[] = $p1;
	$ha = RPW_DB::hash( $base . 'a' );

	rpw_eq( true, RPW_Jobs::enqueue( $p1, $ha ), 'a link is queued' );
	rpw_eq( false, RPW_Jobs::enqueue( $p1, $ha ), 'the same link is not queued twice' );
	rpw_eq( 1, rpw_jobs_of( $p1 ), 'one job in the table' );

	$job = RPW_Jobs::claim();
	rpw_eq( $p1, $job ? (int) $job->product_id : 0, 'a worker takes the job' );
	rpw_eq( null, RPW_Jobs::claim(), 'a second worker cannot take the same job' );
	rpw_eq( 1, rpw_jobs_of( $p1 ), 'a job being checked is still stored (nothing is lost if the worker dies)' );

	RPW_Jobs::release_stalled();
	rpw_eq( null, RPW_Jobs::claim(), 'a job taken a moment ago is not treated as stalled' );
	$wpdb->update( $jobs, array( 'claimed_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * RPW_Jobs::STALL_SECONDS ) ), array( 'id' => (int) $job->id ) ); // phpcs:ignore
	RPW_Jobs::release_stalled();
	$again = RPW_Jobs::claim();
	rpw_eq( (int) $job->id, $again ? (int) $again->id : 0, 'a job whose worker died is given to the next worker' );
	rpw_eq( 1, $again ? (int) $again->attempts : -1, 'and the lost attempt is counted' );

	rpw_eq( true, RPW_Jobs::retry( $again, 300 ), 'a failed job is put back for later' );
	rpw_eq( null, RPW_Jobs::claim(), 'and is not taken before its waiting time is over' );
	$wpdb->update( $jobs, array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => (int) $job->id ) ); // phpcs:ignore
	$last = RPW_Jobs::claim();
	rpw_eq( 2, $last ? (int) $last->attempts : -1, 'second retry' );
	rpw_eq( false, RPW_Jobs::retry( $last, 300 ), 'after the last attempt the job is dropped' );
	rpw_eq( 0, rpw_jobs_of( $p1 ), 'the queue is empty again' );

	// ------------------------------------------------------------- 2. lock.
	echo "\n# Lock\n";
	$lock = RPW_Jobs::lock( 'rpw_test_lock', 60 );
	rpw_eq( true, is_string( $lock ), 'lock taken' );
	rpw_eq( false, RPW_Jobs::lock( 'rpw_test_lock', 60 ), 'a second request cannot take it' );
	RPW_Jobs::unlock( 'rpw_test_lock', 'someone-else' );
	rpw_eq( false, RPW_Jobs::lock( 'rpw_test_lock', 60 ), 'only the holder can release it' );
	RPW_Jobs::unlock( 'rpw_test_lock', $lock );
	$lock = RPW_Jobs::lock( 'rpw_test_lock', -10 );
	rpw_eq( true, is_string( $lock ), 'lock taken again after release' );
	$take = RPW_Jobs::lock( 'rpw_test_lock', 60 );
	rpw_eq( true, is_string( $take ), 'an expired lock (dead request) is taken over' );
	RPW_Jobs::unlock( 'rpw_test_lock', $lock );
	rpw_eq( false, RPW_Jobs::lock( 'rpw_test_lock', 60 ), 'the old holder cannot release the new holder\'s lock' );
	RPW_Jobs::unlock( 'rpw_test_lock', $take );

	// ---------------------------------------- 3. a run with a failing site.
	echo "\n# Background run, retry, alert email\n";
	$rpw_pages[ $base . 'a' ] = rpw_page( 90, $shop );  // cheaper than our 100.
	$rpw_pages[ $base . 'b' ] = 503;                    // temporary failure.
	$rpw_pages[ $base . 'c' ] = 404;                    // permanent failure.
	$rpw_mail_ok              = false;
	foreach ( RPW_Settings::links( $p1 ) as $l ) {
		RPW_Jobs::enqueue( $p1, RPW_DB::hash( $l['url'] ) );
	}
	RPW_Checker::process_queue();

	$latest = RPW_DB::latest_for_product( $p1 );
	rpw_eq( 'ok', $latest[ RPW_DB::hash( $base . 'a' ) ]->status ?? null, 'price saved for the working link' );
	rpw_eq( 90.0, (float) ( $latest[ RPW_DB::hash( $base . 'a' ) ]->price ?? 0 ), 'the right price' );
	rpw_eq( 'error', $latest[ RPW_DB::hash( $base . 'b' ) ]->status ?? null, 'HTTP 503 saved as an error' );
	rpw_eq( 'error', $latest[ RPW_DB::hash( $base . 'c' ) ]->status ?? null, 'HTTP 404 saved as an error' );
	rpw_eq( 1, rpw_jobs_of( $p1 ), 'only the temporary failure (503) stays queued for a retry' );
	rpw_eq( true, (bool) wp_next_scheduled( RPW_Checker::HOOK_CONTINUE ), 'the next batch is scheduled' );
	$pending = get_post_meta( $p1, '_rpw_pending', true );
	rpw_eq( true, is_array( $pending ) && isset( $pending[ $shop ] ), 'the cheaper rival is waiting to be emailed' );
	rpw_eq( '', get_post_meta( $p1, '_rpw_alerted', true ), 'nothing is marked as emailed before the email is sent' );

	// The site recovers; the email server does not.
	$rpw_pages[ $base . 'b' ] = rpw_page( 95, $shop );
	$wpdb->query( $wpdb->prepare( "UPDATE {$jobs} SET available_at = %s WHERE product_id = %d", gmdate( 'Y-m-d H:i:s', time() - 5 ), $p1 ) ); // phpcs:ignore
	wp_clear_scheduled_hook( RPW_Checker::HOOK_CONTINUE );
	RPW_Checker::process_queue();
	rpw_eq( 0, rpw_jobs_of( $p1 ), 'the retry worked and the run is finished' );
	rpw_eq( 'ok', RPW_DB::latest_for_product( $p1 )[ RPW_DB::hash( $base . 'b' ) ]->status ?? null, 'the retried link now has a price' );
	rpw_eq( 0, count( $rpw_mails ), 'the email failed' );
	$pending = get_post_meta( $p1, '_rpw_pending', true );
	rpw_eq( true, is_array( $pending ) && isset( $pending[ $shop ] ), 'a failed email keeps the alert waiting' );
	rpw_eq( '', get_post_meta( $p1, '_rpw_alerted', true ), 'a failed email is not marked as sent' );
	rpw_eq( true, (bool) wp_next_scheduled( RPW_Checker::HOOK_ALERTS ), 'sending is scheduled to be tried again' );
	rpw_eq( true, '' !== (string) get_option( 'rpw_alert_error', '' ), 'the failure is recorded for the admin page' );

	// The email server recovers.
	$rpw_mail_ok = true;
	rpw_eq( true, RPW_Checker::send_alerts(), 'the retry sends the email' );
	rpw_eq( 1, count( $rpw_mails ), 'exactly one email' );
	rpw_eq( true, false !== strpos( $rpw_mails[0]['message'] ?? '', $base . 'a' ), 'the email names the cheapest rival' );
	rpw_eq( '', get_post_meta( $p1, '_rpw_pending', true ), 'nothing is waiting any more' );
	rpw_eq( true, is_array( get_post_meta( $p1, '_rpw_alerted', true ) ), 'now it is marked as emailed' );
	rpw_eq( false, (bool) wp_next_scheduled( RPW_Checker::HOOK_ALERTS ), 'the retry schedule is cleared' );
	rpw_eq( '', (string) get_option( 'rpw_alert_error', '' ), 'the recorded failure is cleared' );
	rpw_eq( null, RPW_Checker::send_alerts(), 'nothing to send the second time' );
	RPW_Checker::collect_alert( $p1 );
	rpw_eq( '', get_post_meta( $p1, '_rpw_pending', true ), 'the same situation is not emailed twice' );

	// ------------------------------------- 4. "Check now" on one product.
	echo "\n# Check now (single product)\n";
	$rpw_pages[ $base . 'a' ] = rpw_page( 80, $shop );
	$rpw_pages[ $base . 'c' ] = rpw_page( 120, $shop );
	$done                     = RPW_Checker::check_product( $p1 );
	rpw_eq( 3, $done['checked'], 'all three links checked during the click' );
	rpw_eq( 0, $done['queued'], 'nothing left for the background' );
	rpw_eq( 2, count( $rpw_mails ), 'a check that finishes during the click still sends the alert' );
	rpw_eq( true, false !== strpos( $rpw_mails[1]['message'] ?? '', '80' ), 'with the new price' );

	$rpw_settings['alert_percent'] = 30;
	$rpw_pages[ $base . 'a' ]      = rpw_page( 75, $shop );
	RPW_Checker::check_product( $p1 );
	rpw_eq( 2, count( $rpw_mails ), 'no email when the rival is cheaper by less than the chosen percentage' );
	$rpw_pages[ $base . 'a' ] = rpw_page( 60, $shop );
	RPW_Checker::check_product( $p1 );
	rpw_eq( 3, count( $rpw_mails ), 'an email when the difference reaches the chosen percentage' );
	$rpw_settings['alert_percent'] = 0;

	// --------------------------- 5. manual checks while the queue has work.
	echo "\n# Queue is not overwritten by manual checks\n";
	$p2        = rpw_product( 50, array( $base . 'x' ) );
	$created[] = $p2;
	$many      = array();
	for ( $i = 1; $i <= 5; $i++ ) {
		$many[]                         = $base . 'm' . $i;
		$rpw_pages[ $base . 'm' . $i ] = rpw_page( 200 + $i, $shop );
	}
	$p3        = rpw_product( 150, $many );
	$created[] = $p3;
	RPW_Jobs::enqueue( $p2, RPW_DB::hash( $base . 'x' ) );
	$done = RPW_Checker::check_product( $p3 );
	rpw_eq( RPW_Checker::NOW_LIMIT, $done['checked'], 'the first links are checked during the click' );
	rpw_eq( 2, $done['queued'], 'the rest are queued' );
	rpw_eq( 1, rpw_jobs_of( $p2 ), 'work that was already queued is still there' );
	rpw_eq( 2, rpw_jobs_of( $p3 ), 'and the new work was added to it' );
	RPW_Checker::check_product( $p3 );
	rpw_eq( 2, rpw_jobs_of( $p3 ), 'clicking again does not queue the same links twice' );

	// The time budget: with a 5-second budget and a 15-second request timeout,
	// a batch may start only one check, however many are waiting.
	$rpw_pages[ $base . 'x' ] = rpw_page( 55, $shop );
	$budget                   = static function () {
		return 5;
	};
	add_filter( 'rpw_batch_seconds', $budget );
	$before = count( $rpw_requests );
	wp_clear_scheduled_hook( RPW_Checker::HOOK_CONTINUE );
	RPW_Checker::process_queue();
	rpw_eq( 1, count( $rpw_requests ) - $before, 'a batch never starts a check that could run past its time budget' );
	rpw_eq( true, (bool) wp_next_scheduled( RPW_Checker::HOOK_CONTINUE ), 'the rest is scheduled' );
	remove_filter( 'rpw_batch_seconds', $budget );
	RPW_Checker::process_queue();
	rpw_eq( 0, RPW_Jobs::count(), 'the next batch finishes the queue' );

	// A removed link is skipped, not checked.
	RPW_Jobs::enqueue( $p2, md5( 'a link that was removed' ) );
	$before = count( $rpw_requests );
	RPW_Checker::process_queue();
	rpw_eq( 0, count( $rpw_requests ) - $before, 'a queued link that no longer exists is skipped' );
	rpw_eq( 0, RPW_Jobs::count(), 'and removed from the queue' );

	// ------------------------------------------------ 6. link identity.
	echo "\n# Links that differ only by letter case\n";
	$rpw_pages[ $base . 'Item/AbC' ] = rpw_page( 10, $shop );
	$rpw_pages[ $base . 'Item/abc' ] = rpw_page( 20, $shop );
	$p4                               = rpw_product( 30, array( $base . 'Item/AbC', $base . 'Item/abc' ) );
	$created[]                        = $p4;
	RPW_Checker::check_product( $p4 );
	$latest = RPW_DB::latest_for_product( $p4 );
	rpw_eq( 2, count( $latest ), 'two separate histories' );
	rpw_eq( 10.0, (float) ( $latest[ RPW_DB::hash( $base . 'Item/AbC' ) ]->price ?? 0 ), 'first link has its own price' );
	rpw_eq( 20.0, (float) ( $latest[ RPW_DB::hash( $base . 'Item/abc' ) ]->price ?? 0 ), 'second link has its own price' );

	// ------------------------- 6b. retries, failed saves and the watchdog.
	echo "\n# Check now keeps retries, failed saves are not lost, watchdog\n";
	$p6                        = rpw_product( 70, array( $base . 'r1' ) );
	$created[]                 = $p6;
	$h6                        = RPW_DB::hash( $base . 'r1' );
	$rpw_pages[ $base . 'r1' ] = 503;
	RPW_Jobs::enqueue( $p6, $h6 );
	RPW_Checker::check_product( $p6 );
	rpw_eq( 1, rpw_jobs_of( $p6 ), 'Check now with HTTP 503 keeps the queued job for a retry' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$jobs} WHERE product_id = %d", $p6 ) ); // phpcs:ignore
	RPW_Checker::check_product( $p6 );
	rpw_eq( 1, rpw_jobs_of( $p6 ), 'Check now with HTTP 503 queues a retry when none was waiting' );
	rpw_eq( null, RPW_Jobs::claim(), 'the retry waits before it runs' );
	$rpw_pages[ $base . 'r1' ] = rpw_page( 65, $shop );
	RPW_Checker::check_product( $p6 );
	rpw_eq( 0, rpw_jobs_of( $p6 ), 'a successful Check now removes the waiting retry' );

	// The database refuses the insert: the check must not count as done.
	$prices_table = RPW_DB::table();
	$break_insert = static function ( $q ) use ( $prices_table ) {
		return ( 0 === stripos( ltrim( $q ), 'INSERT' ) && false !== strpos( $q, $prices_table ) ) ? 'INSERT INTO rpw_no_such_table_xyz (a) VALUES (1)' : $q;
	};
	$wpdb->suppress_errors( true );
	add_filter( 'query', $break_insert );
	$rpw_pages[ $base . 'r1' ] = rpw_page( 64, $shop );
	$row                       = RPW_Checker::check_link( $p6, RPW_Settings::links( $p6 )[0] );
	rpw_eq( false, $row['saved'], 'a failed database write is reported' );
	RPW_Checker::check_product( $p6 );
	rpw_eq( 1, rpw_jobs_of( $p6 ), 'Check now whose save failed leaves a job for the background' );
	$wpdb->query( $wpdb->prepare( "UPDATE {$jobs} SET available_at = %s WHERE product_id = %d", gmdate( 'Y-m-d H:i:s', time() - 5 ), $p6 ) ); // phpcs:ignore
	wp_clear_scheduled_hook( RPW_Checker::HOOK_CONTINUE );
	RPW_Checker::process_queue();
	$left = $wpdb->get_row( $wpdb->prepare( "SELECT attempts, claim FROM {$jobs} WHERE product_id = %d", $p6 ) ); // phpcs:ignore
	rpw_eq( 1, $left ? (int) $left->attempts : -1, 'a background check whose save failed is put back, not deleted' );
	rpw_eq( '', $left ? (string) $left->claim : 'gone', 'and is free for the next worker' );
	remove_filter( 'query', $break_insert );
	$wpdb->suppress_errors( false );
	rpw_eq( true, RPW_Checker::check_link( $p6, RPW_Settings::links( $p6 )[0] )['saved'], 'a working database write is reported as saved' );

	// The watchdog is scheduled before the batch does any work.
	$seen     = array();
	$watchdog = static function ( $pre, $args, $url ) use ( &$seen ) {
		if ( false !== strpos( $url, 'rpw-test.invalid' ) ) {
			$seen[] = wp_next_scheduled( RPW_Checker::HOOK_CONTINUE );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $watchdog, 5, 3 );
	$wpdb->query( $wpdb->prepare( "UPDATE {$jobs} SET available_at = %s WHERE product_id = %d", gmdate( 'Y-m-d H:i:s', time() - 5 ), $p6 ) ); // phpcs:ignore
	wp_clear_scheduled_hook( RPW_Checker::HOOK_CONTINUE );
	$t0 = time();
	RPW_Checker::process_queue();
	remove_filter( 'pre_http_request', $watchdog, 5 );
	rpw_eq( true, isset( $seen[0] ) && $seen[0] >= $t0 + RPW_Jobs::STALL_SECONDS, 'a recovery batch is already scheduled while a check runs (after the stall time)' );
	rpw_eq( 0, rpw_jobs_of( $p6 ), 'the batch finished the job' );
	rpw_eq( false, (bool) wp_next_scheduled( RPW_Checker::HOOK_CONTINUE ), 'a finished queue leaves no recovery batch behind' );
	RPW_Jobs::enqueue( $p6, $h6, HOUR_IN_SECONDS );
	RPW_Checker::process_queue();
	$next = wp_next_scheduled( RPW_Checker::HOOK_CONTINUE );
	rpw_eq( true, $next && $next >= time() + HOUR_IN_SECONDS - 5, 'with work waiting, the watchdog is replaced by the real next batch' );
	RPW_Jobs::clear( $p6 );
	wp_clear_scheduled_hook( RPW_Checker::HOOK_CONTINUE );

	// ------------------- 6c. alert state survives failures; stale alerts.
	echo "\n# Alerts: temporary failures and outdated waiting alerts\n";
	$p7                        = rpw_product( 100, array( $base . 'al' ) );
	$created[]                 = $p7;
	$rpw_pages[ $base . 'al' ] = rpw_page( 80, $shop );
	$mails_before              = count( $rpw_mails );
	RPW_Checker::check_product( $p7 );
	rpw_eq( $mails_before + 1, count( $rpw_mails ), 'cheaper rival: one email' );
	$rpw_pages[ $base . 'al' ] = 503;
	RPW_Checker::check_product( $p7 );
	rpw_eq( true, is_array( get_post_meta( $p7, '_rpw_alerted', true ) ) && get_post_meta( $p7, '_rpw_alerted', true ), 'a failed fetch keeps the "already emailed" record' );
	$rpw_pages[ $base . 'al' ] = rpw_page( 80, $shop );
	RPW_Checker::check_product( $p7 );
	rpw_eq( $mails_before + 1, count( $rpw_mails ), 'the same price after a failure is not emailed again' );

	// A waiting (unsent) alert survives a failed fetch.
	$rpw_mail_ok               = false;
	$rpw_pages[ $base . 'al' ] = rpw_page( 70, $shop );
	RPW_Checker::check_product( $p7 );
	rpw_eq( true, is_array( get_post_meta( $p7, '_rpw_pending', true ) ), 'new lower price waits to be emailed (mail is down)' );
	$rpw_pages[ $base . 'al' ] = 503;
	RPW_Checker::check_product( $p7 );
	rpw_eq( true, is_array( get_post_meta( $p7, '_rpw_pending', true ) ), 'a failed fetch keeps the waiting alert' );

	// The shop owner lowers the price before the email retry: the alert is no longer true.
	$own = wc_get_product( $p7 );
	$own->set_regular_price( '50' );
	$own->save();
	$rpw_mail_ok  = true;
	$mails_before = count( $rpw_mails );
	RPW_Checker::send_alerts();
	rpw_eq( $mails_before, count( $rpw_mails ), 'an alert that is no longer true is not emailed' );
	rpw_eq( '', get_post_meta( $p7, '_rpw_pending', true ), 'and it is no longer waiting' );
	rpw_eq( false, (bool) wp_next_scheduled( RPW_Checker::HOOK_ALERTS ), 'no email retry is left scheduled' );

	// Price goes back up while an alert waits: the email uses today's own price.
	$own->set_regular_price( '90' );
	$own->save();
	$rpw_mail_ok               = false;
	$rpw_pages[ $base . 'al' ] = rpw_page( 70, $shop );
	RPW_Checker::check_product( $p7 );
	$own->set_regular_price( '95' );
	$own->save();
	$rpw_mail_ok  = true;
	$mails_before = count( $rpw_mails );
	RPW_Checker::send_alerts();
	$msg = $rpw_mails[ $mails_before ]['message'] ?? '';
	rpw_eq( $mails_before + 1, count( $rpw_mails ), 'a still-true alert is emailed' );
	$own_part = preg_match( '/' . preg_quote( $own->get_name(), '/' ) . ': ([^,]+),/u', $msg, $mm ) ? $mm[1] : '';
	rpw_eq( true, false !== strpos( $own_part, '95' ) && false === strpos( $own_part, '90' ), 'with the current own price, not the one from when it was queued' );

	// ----------------------------- 6d. a page that states no currency.
	echo "\n# Strict currency: unknown currency is never compared\n";
	$p8                        = rpw_product( 100, array( $base . 'nc' ) );
	$created[]                 = $p8;
	$h8                        = RPW_DB::hash( $base . 'nc' );
	$rpw_pages[ $base . 'nc' ] = rpw_page( 10, '' );
	$mails_before              = count( $rpw_mails );
	RPW_Checker::check_product( $p8 );
	$last = RPW_DB::latest_for_product( $p8 )[ $h8 ] ?? null;
	rpw_eq( 'nocurrency', $last->status ?? null, 'no currency on the page and none chosen: status "currency unknown"' );
	rpw_eq( null, $last ? $last->price : 'no row', 'no comparable price is saved' );
	rpw_eq( 10.0, (float) ( $last->raw_price ?? 0 ), 'the number on the page is kept for information' );
	rpw_eq( null, RPW_Checker::summary( $p8 )['lowest'], 'it is not used as the cheapest rival' );
	rpw_eq( $mails_before, count( $rpw_mails ), 'and no alert is sent' );
	$links8                = RPW_Settings::links( $p8 );
	$links8[0]['currency'] = $shop;
	update_post_meta( $p8, '_rpw_links', $links8 );
	RPW_Checker::check_product( $p8 );
	$last = RPW_DB::latest_for_product( $p8 )[ $h8 ] ?? null;
	rpw_eq( 'ok', $last->status ?? null, 'after choosing the link currency the price is compared' );
	rpw_eq( $mails_before + 1, count( $rpw_mails ), 'and the cheaper rival is emailed' );

	// ------------------- 6e. 0.2.4: variant proof, changed links, rates.
	echo "\n# A variant hint must be proven; changed links; today's rates\n";
	$p9                        = rpw_product( 100, array( $base . 'uv' ) );
	$created[]                 = $p9;
	$h9                        = RPW_DB::hash( $base . 'uv' );
	$links9                    = RPW_Settings::links( $p9 );
	$links9[0]['match']        = '256GB';
	update_post_meta( $p9, '_rpw_links', $links9 );
	$rpw_pages[ $base . 'uv' ] = '<html><head><meta property="product:price:amount" content="50"><meta property="product:price:currency" content="' . $shop . '"></head></html>';
	$mails_before              = count( $rpw_mails );
	RPW_Checker::check_product( $p9 );
	$last = RPW_DB::latest_for_product( $p9 )[ $h9 ] ?? null;
	rpw_eq( 'unverified', $last->status ?? null, 'a price that cannot be tied to the variant is "variant not confirmed"' );
	rpw_eq( 50.0, (float) ( $last->raw_price ?? 0 ), 'the number found is kept for information' );
	rpw_eq( null, RPW_Checker::summary( $p9 )['lowest'], 'it is not used as the cheapest rival' );
	rpw_eq( $mails_before, count( $rpw_mails ), 'and no alert is sent for it' );
	$rpw_pages[ $base . 'uv' ] = '<html><head><meta property="product:price:amount" content="50"><meta property="product:price:currency" content="' . $shop . '"><meta property="og:title" content="Phone 128GB"></head></html>';
	RPW_Checker::check_product( $p9 );
	$last = RPW_DB::latest_for_product( $p9 )[ $h9 ] ?? null;
	rpw_eq( 'nomatch', $last->status ?? null, 'a page title naming another variant: "variant not found"' );
	$rpw_pages[ $base . 'uv' ] = '<html><head><meta property="product:price:amount" content="50"><meta property="product:price:currency" content="' . $shop . '"><meta property="og:title" content="Phone 256GB"></head></html>';
	RPW_Checker::check_product( $p9 );
	$last = RPW_DB::latest_for_product( $p9 )[ $h9 ] ?? null;
	rpw_eq( 'ok', $last->status ?? null, 'a page title naming the variant: compared' );
	rpw_eq( $mails_before + 1, count( $rpw_mails ), 'and the cheaper rival is emailed' );

	// The shop owner changes how the link is read: the old price must not stay current.
	// (The save form validates URLs by DNS, which the fake test domain cannot pass, so
	// the step that runs after the links are saved is called directly.)
	$old9 = array();
	foreach ( RPW_Settings::links( $p9 ) as $l ) {
		$old9[ RPW_DB::hash( $l['url'] ) ] = $l;
	}
	$new9              = RPW_Settings::links( $p9 );
	$new9[0]['factor'] = 2;
	update_post_meta( $p9, '_rpw_links', $new9 );
	RPW_Jobs::clear( $p9 );
	rpw_eq( 1, RPW_Metabox::links_changed( $p9, $old9, $new9 ), 'one link changed' );
	$last = RPW_DB::latest_for_product( $p9 )[ $h9 ] ?? null;
	rpw_eq( 'changed', $last->status ?? null, 'after changing the multiplier the old price is marked as read the old way' );
	rpw_eq( null, RPW_Checker::summary( $p9 )['lowest'], 'and it is no longer the cheapest rival' );
	rpw_eq( false, is_array( get_post_meta( $p9, '_rpw_pending', true ) ), 'and no alert waits for it' );
	rpw_eq( 1, rpw_jobs_of( $p9 ), 'the link is queued for a new check' );
	rpw_eq( 'ok', RPW_DB::history( $p9, $base . 'uv' ) ? 'ok' : 'gone', 'its price history is kept' );
	RPW_Checker::check_product( $p9 );
	$last = RPW_DB::latest_for_product( $p9 )[ $h9 ] ?? null;
	rpw_eq( 100.0, (float) ( $last->raw_price ?? 0 ), 'the next check uses the new multiplier' );
	rpw_eq( 0, rpw_jobs_of( $p9 ), 'and the queued job is done' );
	$renamed             = $new9;
	$renamed[0]['label'] = 'renamed';
	$keyed               = array( $h9 => $new9[0] );
	rpw_eq( 0, RPW_Metabox::links_changed( $p9, $keyed, $renamed ), 'renaming a link is not a change in how it is read' );
	rpw_eq( 'ok', RPW_DB::latest_for_product( $p9 )[ $h9 ]->status ?? null, 'so its price is kept' );
	$curr                = $new9;
	$curr[0]['currency'] = 'EUR' === $shop ? 'GBP' : 'EUR';
	rpw_eq( true, RPW_Metabox::reading_changed( $new9[0], $curr[0] ), 'changing the link currency is a change' );
	$hint                = $new9;
	$hint[0]['match']    = '512GB';
	rpw_eq( true, RPW_Metabox::reading_changed( $new9[0], $hint[0] ), 'changing the variant is a change' );

	// Changing an exchange rate: the overview and the markets agree.
	$foreign = 'EUR' === $shop ? 'GBP' : 'EUR';
	$rates   = array(
		'base'  => $shop,
		'rates' => array( $foreign => 1.0 ),
	);
	$rate_filter = static function () use ( &$rates ) {
		return $rates;
	};
	add_filter( 'pre_option_' . RPW_Currency::OPTION, $rate_filter );
	$p10                       = rpw_product( 100, array( $base . 'fx' ) );
	$created[]                 = $p10;
	$rpw_pages[ $base . 'fx' ] = rpw_page( 80, $foreign );
	RPW_Checker::check_product( $p10 );
	rpw_eq( 80.0, (float) RPW_Checker::summary( $p10 )['lowest']['price'], 'rival converted at the first rate' );
	$rates['rates'][ $foreign ] = 2.0;
	$sum                        = RPW_Checker::summary( $p10 );
	$mkt                        = RPW_Checker::markets( $p10 )[ $foreign ];
	rpw_eq( 160.0, (float) $sum['lowest']['price'], 'after the rate changes the overview uses the new rate' );
	rpw_eq( round( (float) $mkt['diff'], 2 ), round( (float) $sum['diff'], 2 ), 'overview and market give the same difference' );
	unset( $rates['rates'][ $foreign ] );
	rpw_eq( null, RPW_Checker::summary( $p10 )['lowest'], 'with the rate removed it is not compared' );
	remove_filter( 'pre_option_' . RPW_Currency::OPTION, $rate_filter );

	rpw_eq( 'manage_woocommerce', apply_filters( 'option_page_capability_rpw_settings_group', 'manage_options' ), 'shop managers who can open the settings can also save them' );

	// ---------------------- 6f. what a competitor site sees in the request.
	echo "\n# Privacy: the shop's address is not sent\n";
	$rpw_ua   = '';
	$ua_catch = static function ( $pre, $args, $url ) use ( &$rpw_ua ) {
		if ( false !== strpos( $url, 'rpw-test.invalid' ) ) {
			$rpw_ua = isset( $args['user-agent'] ) ? (string) $args['user-agent'] : '';
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $ua_catch, 1, 3 );
	RPW_Checker::check_product( $p10 );
	remove_filter( 'pre_http_request', $ua_catch, 1 );
	rpw_eq( true, false !== strpos( $rpw_ua, 'RivalPriceWatch/' . RPW_VERSION ), 'the request says it is Rival Price Watch' );
	rpw_eq( false, false !== stripos( $rpw_ua, $rpw_host ) || false !== stripos( $rpw_ua, home_url() ), 'the request does not contain the shop address' );

	// ------------------------------------------- 6g. 0.2.7 fixes.
	echo "\n# Runs, repeated alerts, cleanup and the lock\n";
	// A waiting retry is not a run: the scheduled run still queues every link.
	update_option( 'rpw_last_run_started', time() - 3600, false );
	update_option( 'rpw_last_run_finished', time() - 1800, false );
	RPW_Jobs::enqueue( $p10, RPW_DB::hash( $base . 'fx' ), 300 );
	rpw_eq( false, RPW_Checker::run_in_progress(), 'one waiting retry after a finished run is not a run in progress' );
	update_option( 'rpw_last_run_started', time() - 60, false );
	rpw_eq( true, RPW_Checker::run_in_progress(), 'a started, unfinished run with work left is in progress' );
	update_option( 'rpw_last_run_started', time() - 2 * DAY_IN_SECONDS, false );
	update_option( 'rpw_last_run_finished', time() - 3 * DAY_IN_SECONDS, false );
	rpw_eq( false, RPW_Checker::run_in_progress(), 'a run that never finished stops counting after a day' );
	RPW_Jobs::clear( $p10 );

	// Choosing the currency the page already states does not email the same alert again.
	$p11                       = rpw_product( 100, array( $base . 'dup' ) );
	$created[]                 = $p11;
	$rpw_mail_ok               = true;
	$rpw_pages[ $base . 'dup' ] = rpw_page( 60, $shop );
	$mails_before              = count( $rpw_mails );
	RPW_Checker::check_product( $p11 );
	rpw_eq( $mails_before + 1, count( $rpw_mails ), 'cheaper rival emailed once' );
	$old11                = RPW_Settings::links( $p11 );
	$new11                = $old11;
	$new11[0]['currency'] = $shop;
	update_post_meta( $p11, '_rpw_links', $new11 );
	RPW_Metabox::links_changed( $p11, array( RPW_DB::hash( $base . 'dup' ) => $old11[0] ), $new11 );
	rpw_eq( true, is_array( get_post_meta( $p11, '_rpw_alerted', true ) ) && get_post_meta( $p11, '_rpw_alerted', true ), 'while the link waits for its new check, the emailed record is kept' );
	RPW_Checker::check_product( $p11 );
	rpw_eq( $mails_before + 1, count( $rpw_mails ), 'the unchanged price is not emailed again' );
	$rpw_pages[ $base . 'dup' ] = rpw_page( 50, $shop );
	RPW_Checker::check_product( $p11 );
	rpw_eq( $mails_before + 2, count( $rpw_mails ), 'a new lower price is still emailed' );

	// Cleanup keeps the latest result of every link, however old.
	$prices_tbl = RPW_DB::table();
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . $prices_tbl . ' SET checked_at = %s WHERE product_id = %d', gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ), $p11 ) ); // phpcs:ignore
	RPW_DB::prune( 30 );
	$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $prices_tbl . ' WHERE product_id = %d', $p11 ) ); // phpcs:ignore
	rpw_eq( 1, $left, 'old history is removed but the latest result stays' );
	rpw_eq( 'ok', RPW_DB::latest_for_product( $p11 )[ RPW_DB::hash( $base . 'dup' ) ]->status ?? null, 'so the link still shows its last price' );

	// "Check now" while a background batch holds the lock: nothing is fetched, all is queued.
	$held   = RPW_Jobs::lock( RPW_Checker::LOCK, 60 );
	$before = count( $rpw_requests );
	$done   = RPW_Checker::check_product( $p11 );
	rpw_eq( 0, count( $rpw_requests ) - $before, 'no request while a batch is fetching' );
	rpw_eq( 1, $done['queued'], 'the link is queued instead' );
	RPW_Jobs::unlock( RPW_Checker::LOCK, $held );
	RPW_Jobs::clear( $p11 );
	$done = RPW_Checker::check_product( $p11 );
	rpw_eq( 1, $done['checked'], 'with the lock free, Check now fetches again' );
	rpw_eq( false, (bool) get_option( RPW_Checker::LOCK ), 'and gives the lock back' );

	// ------------------------------------------- 6h. 0.2.8 fixes.
	echo "\n# Settings saves, deleted products, impossible prices, job claims\n";
	// Saving settings without changing the frequency keeps the scheduled run where it is.
	wp_clear_scheduled_hook( RPW_Checker::HOOK_RUN );
	wp_schedule_event( time() + 20 * HOUR_IN_SECONDS, 'daily', RPW_Checker::HOOK_RUN );
	$next_before = wp_next_scheduled( RPW_Checker::HOOK_RUN );
	$old_s       = array_merge( RPW_Settings::defaults(), array( 'frequency' => 'daily' ) );
	$new_s       = array_merge( $old_s, array( 'alert_email' => 'someone-else@example.org' ) );
	RPW_Checker::settings_changed( $old_s, $new_s );
	rpw_eq( $next_before, wp_next_scheduled( RPW_Checker::HOOK_RUN ), 'changing only the alert email does not move the next run' );
	RPW_Checker::settings_changed( $old_s, array_merge( $old_s, array( 'frequency' => 'twicedaily' ) ) );
	rpw_eq( true, wp_next_scheduled( RPW_Checker::HOOK_RUN ) !== $next_before, 'changing the frequency reschedules it' );
	wp_clear_scheduled_hook( RPW_Checker::HOOK_RUN );

	// Deleting a product for good removes its history and queued checks; trashing keeps them.
	$p12  = rpw_product( 100, array( $base . 'del' ) );
	$h12  = RPW_DB::hash( $base . 'del' );
	$rpw_pages[ $base . 'del' ] = rpw_page( 90, $shop );
	RPW_Checker::check_product( $p12 );
	RPW_Jobs::enqueue( $p12, $h12, 3600 );
	wp_trash_post( $p12 );
	rpw_eq( true, isset( RPW_DB::latest_for_product( $p12 )[ $h12 ] ), 'a trashed product keeps its price history' );
	wp_untrash_post( $p12 );
	wp_delete_post( $p12, true );
	$left12 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RPW_DB::table() . ' WHERE product_id = %d', $p12 ) ); // phpcs:ignore
	rpw_eq( 0, $left12, 'a deleted product leaves no price rows' );
	rpw_eq( 0, rpw_jobs_of( $p12 ), 'and no queued checks' );

	// An impossible price is reported, not saved as a price.
	$p13                         = rpw_product( 100, array( $base . 'huge' ) );
	$created[]                   = $p13;
	$rpw_pages[ $base . 'huge' ] = rpw_page( '100000000000000000000000', $shop );
	RPW_Checker::check_product( $p13 );
	$last13 = RPW_DB::latest_for_product( $p13 )[ RPW_DB::hash( $base . 'huge' ) ] ?? null;
	rpw_eq( 'noprice', $last13->status ?? null, 'an impossibly large price is reported as no price' );

	// Only the worker holding the claim can finish or retry a job.
	RPW_Jobs::enqueue( $p13, 'claimtest' );
	$job = RPW_Jobs::claim();
	while ( $job && 'claimtest' !== $job->link_hash ) {
		RPW_Jobs::retry( $job, 3600 );
		$job = RPW_Jobs::claim();
	}
	$stale        = clone $job;
	$stale->claim = 'someone-elses-old-claim';
	RPW_Jobs::complete( $stale );
	rpw_eq( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RPW_Jobs::table() . ' WHERE product_id = %d AND link_hash = %s', $p13, 'claimtest' ) ), 'a worker with an old claim cannot remove the job' ); // phpcs:ignore
	RPW_Jobs::complete( $job );
	rpw_eq( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RPW_Jobs::table() . ' WHERE product_id = %d AND link_hash = %s', $p13, 'claimtest' ) ), 'the worker holding the claim can' ); // phpcs:ignore
	RPW_Jobs::clear( $p13 );

	// ------------------------------------------- 6i. 0.2.9 fixes.
	echo "\n# Rial and toman share one market; mistyped alert email\n";
	if ( in_array( $shop, array( 'IRR', 'IRT' ), true ) ) {
		$other                       = 'IRT' === $shop ? 'IRR' : 'IRT';
		$p14                         = rpw_product( 1000000, array( $base . 'ir' ) );
		$created[]                   = $p14;
		$rpw_pages[ $base . 'ir' ]   = rpw_page( 'IRT' === $shop ? 9000000 : 90000, $other ); // 900,000 toman either way
		RPW_Checker::check_product( $p14 );
		$mk14 = RPW_Checker::markets( $p14 );
		rpw_eq( array( $shop ), array_keys( $mk14 ), 'a rival in the other Iranian unit is in the shop market' );
		rpw_eq( 'shop', $mk14[ $shop ]['own_source'] ?? null, 'compared with the real shop price, not an approximation' );
		rpw_eq( -10.0, round( (float) ( $mk14[ $shop ]['diff'] ?? 0 ), 1 ), 'and the difference is exact (-10%)' );
	} else {
		echo "skip rial/toman checks: shop currency is {$shop}\n";
	}
	require_once ABSPATH . 'wp-admin/includes/template.php'; // Settings errors live here; the Settings page loads it.
	$before_s = RPW_Settings::get();
	$clean    = RPW_Settings::sanitize( array_merge( $before_s, array( 'alert_email' => 'name@shop' ) ) );
	rpw_eq( $before_s['alert_email'], $clean['alert_email'], 'a mistyped alert email keeps the previous address' );
	$errs = get_settings_errors( RPW_Settings::OPTION );
	rpw_eq( true, (bool) array_filter( $errs, static function ( $e ) { return 'rpw_alert_email' === $e['code']; } ), 'and Settings shows an error' );
	$clean2 = RPW_Settings::sanitize( array_merge( $before_s, array( 'alert_email' => 'new.owner@example.org' ) ) );
	rpw_eq( 'new.owner@example.org', $clean2['alert_email'], 'a valid new address is saved' );

	// ------------------------------------------- 6j. 0.2.10 fixes.
	echo "\n# Saved links survive a failed DNS lookup; settings changed during a check\n";
	$p15        = rpw_product( 100, array( $base . 'dns' ) );
	$created[]  = $p15;
	$h15        = RPW_DB::hash( $base . 'dns' );
	$rpw_pages[ $base . 'dns' ] = rpw_page( 90, $shop );
	RPW_Checker::check_product( $p15 );
	$admin_ids  = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$prev_user  = get_current_user_id();
	wp_set_current_user( (int) $admin_ids[0] );
	$new_url    = 'https://new-site.rpw-test.invalid/x';
	$_POST      = array(
		'rpw_nonce'    => wp_create_nonce( 'rpw_save_links' ),
		'rpw_url'      => array( $base . 'dns', $new_url ),
		'rpw_label'    => array( 'Kept', '' ),
		'rpw_factor'   => array( '1', '1' ),
		'rpw_match'    => array( '', '' ),
		'rpw_currency' => array( '', '' ),
	);
	RPW_Metabox::save( $p15, get_post( $p15 ) );
	$_POST = array();
	rpw_eq( array( $base . 'dns' ), wp_list_pluck( RPW_Settings::links( $p15 ), 'url' ), 'a saved link whose site cannot be looked up is kept, a new one is not added' );
	rpw_eq( 'Kept', RPW_Settings::links( $p15 )[0]['label'] ?? null, 'and the kept link takes the new label' );
	rpw_eq( 'ok', RPW_DB::latest_for_product( $p15 )[ $h15 ]->status ?? null, 'and its price history is kept' );
	rpw_eq( array( $new_url ), get_transient( 'rpw_rejected_' . get_current_user_id() ), 'the link that was not added is reported' );
	ob_start();
	RPW_Metabox::rejected_notice();
	rpw_eq( true, false !== strpos( (string) ob_get_clean(), $new_url ), 'and shown as an error after the save' );
	wp_set_current_user( $prev_user );

	// Someone changes a link's multiplier while its page is being fetched.
	$p16       = rpw_product( 100, array( $base . 'race' ) );
	$created[] = $p16;
	$h16       = RPW_DB::hash( $base . 'race' );
	$rpw_pages[ $base . 'race' ] = rpw_page( 90, $shop );
	$race_on   = true;
	$racer     = static function ( $pre, $args, $url ) use ( $base, $p16, &$race_on ) {
		if ( $race_on && $url === $base . 'race' ) {
			$race_on = false;
			$old     = array();
			foreach ( RPW_Settings::links( $p16 ) as $l ) {
				$old[ RPW_DB::hash( $l['url'] ) ] = $l;
			}
			$new              = array_values( $old );
			$new[0]['factor'] = 2;
			update_post_meta( $p16, '_rpw_links', $new );
			RPW_Metabox::links_changed( $p16, $old, $new );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $racer, 1, 3 );
	RPW_Checker::check_product( $p16 );
	rpw_eq( 'changed', RPW_DB::latest_for_product( $p16 )[ $h16 ]->status ?? null, '"Check now": a price read with the old settings is not saved' );
	rpw_eq( 1, rpw_jobs_of( $p16 ), 'and a fresh check stays queued' );
	RPW_Jobs::clear( $p16 );
	RPW_Checker::check_product( $p16 );
	rpw_eq( 180.0, (float) ( RPW_DB::latest_for_product( $p16 )[ $h16 ]->raw_price ?? 0 ), 'the next check reads it with the new multiplier' );

	// The same during a background batch.
	update_post_meta( $p16, '_rpw_links', array( array_merge( RPW_Settings::links( $p16 )[0], array( 'factor' => 1 ) ) ) );
	$race_on = true;
	RPW_Jobs::enqueue( $p16, $h16 );
	RPW_Checker::process_queue();
	remove_filter( 'pre_http_request', $racer, 1 );
	// The stale result is thrown away and the fresh job runs in the same batch.
	rpw_eq( 'ok', RPW_DB::latest_for_product( $p16 )[ $h16 ]->status ?? null, 'background: the link is checked again at once' );
	rpw_eq( 180.0, (float) ( RPW_DB::latest_for_product( $p16 )[ $h16 ]->raw_price ?? 0 ), 'with the new multiplier, not the old price' );
	rpw_eq( 0, rpw_jobs_of( $p16 ), 'and nothing is left in the queue' );
	RPW_Jobs::clear( $p16 );

	// ------------------------------------------------ 7. overview index.
	echo "\n# Overview data\n";
	$index = RPW_Checker::index();
	rpw_eq( true, isset( $index[ $p4 ] ), 'the product is in the index' );
	rpw_eq( 30.0, (float) ( $index[ $p4 ]['own'] ?? 0 ), 'own price read without loading the product' );
	$fast = RPW_Checker::summary_from( $index[ $p4 ]['own'], $index[ $p4 ]['links'], $index[ $p4 ]['latest'] );
	$full = RPW_Checker::summary( $p4 );
	rpw_eq( (float) $full['diff'], (float) $fast['diff'], 'the fast summary equals the full one' );
	rpw_eq( 10.0, (float) $fast['lowest']['price'], 'cheapest rival' );

	// ------------------------------------------ 8. upgrade from 0.1.0 data.
	echo "\n# Upgrade from version 0.1.0\n";
	$up_url    = $base . 'Old/Path?ID=7';
	$p5        = rpw_product( 40, array( $up_url ) );
	$created[] = $p5;
	$wpdb->insert( // phpcs:ignore
		RPW_DB::table(),
		array(
			'product_id' => $p5,
			'link_hash'  => RPW_DB::legacy_hash( $up_url ),
			'url'        => esc_url_raw( $up_url ),
			'price'      => 35,
			'currency'   => $shop,
			'raw_price'  => 35,
			'raw_currency' => $shop,
			'status'     => 'ok',
			'message'    => 'json-ld',
			'checked_at' => gmdate( 'Y-m-d H:i:s' ),
		)
	);
	update_post_meta( $p5, '_rpw_alerted', array( $shop => 'marked-before-sending' ) );
	update_option( 'rpw_queue', array( array( $p5, 0 ), array( $p5, 9 ) ), false );
	update_option( 'rpw_pending_alerts', array( $p5 . '|' . $shop => array( 'product' => $p5, 'currency' => $shop ) ), false );
	update_option( 'rpw_db_version', '2', false );
	RPW_DB::flush();
	RPW_DB::install();

	rpw_eq( RPW_DB::DB_VERSION, get_option( 'rpw_db_version' ), 'database version updated' );
	$latest = RPW_DB::latest_for_product( $p5 );
	rpw_eq( true, isset( $latest[ RPW_DB::hash( $up_url ) ] ), 'old history is found under the new link identity' );
	rpw_eq( 1, rpw_jobs_of( $p5 ), 'the old queue was moved to the jobs table (the entry for a missing link is dropped)' );
	rpw_eq( false, get_option( 'rpw_queue' ), 'the old queue option is gone' );
	rpw_eq( false, get_option( 'rpw_pending_alerts' ), 'the old waiting-alerts option is gone' );
	$pending = get_post_meta( $p5, '_rpw_pending', true );
	rpw_eq( true, is_array( $pending ) && isset( $pending[ $shop ] ), 'an alert that was waiting in the old format will still be sent' );
} finally {
	// ------------------------------------------------------------ clean up.
	foreach ( $created as $pid ) {
		RPW_DB::delete_product( $pid );
		wp_delete_post( $pid, true );
	}
	foreach ( $rpw_saved as $other => $meta ) {
		foreach ( array( '_rpw_alerted', '_rpw_pending' ) as $n => $key ) {
			if ( '' === $meta[ $n ] ) {
				delete_post_meta( $other, $key );
			} else {
				update_post_meta( $other, $key, $meta[ $n ] );
			}
		}
	}
	foreach ( array( 'rpw_alert_retries', 'rpw_alert_error', 'rpw_last_run_finished', 'rpw_last_run_started' ) as $n => $name ) {
		if ( null === $rpw_saved_options[ $n ] ) {
			delete_option( $name );
		} else {
			update_option( $name, $rpw_saved_options[ $n ], false );
		}
	}
	delete_option( 'rpw_queue' );
	delete_option( 'rpw_pending_alerts' );
	update_option( 'rpw_db_version', RPW_DB::DB_VERSION, false );
	wp_clear_scheduled_hook( RPW_Checker::HOOK_CONTINUE );
	wp_clear_scheduled_hook( RPW_Checker::HOOK_ALERTS );
}

echo "\n{$count} checks, {$failures} failed\n";
exit( $failures ? 1 : 0 );
