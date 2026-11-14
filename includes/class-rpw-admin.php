<?php
/**
 * Admin page: overview and settings.
 *
 * @package RivalPriceWatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → Rival Prices.
 */
class RPW_Admin {

	const PAGE = 'rpw-prices';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_rpw_check_product', array( __CLASS__, 'handle_check_product' ) );
		add_action( 'admin_post_rpw_run_all', array( __CLASS__, 'handle_run_all' ) );
		add_action( 'admin_post_rpw_save_rates', array( __CLASS__, 'handle_save_rates' ) );
		add_action( 'admin_post_rpw_fetch_rates', array( __CLASS__, 'handle_fetch_rates' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RPW_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Add the submenu.
	 */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Rival Prices', 'rival-price-watch' ),
			__( 'Rival Prices', 'rival-price-watch' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Open', 'rival-price-watch' ) . '</a>' );
		return $links;
	}

	/**
	 * Register the option.
	 */
	public static function register_settings() {
		register_setting(
			'rpw_settings_group',
			RPW_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'RPW_Settings', 'sanitize' ),
				'default'           => RPW_Settings::defaults(),
			)
		);
	}

	/**
	 * CSS/JS on our page and on product edit screens.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function assets( $hook ) {
		$screen = get_current_screen();
		$ours   = false !== strpos( $hook, self::PAGE );
		$edit   = $screen && 'product' === $screen->post_type && in_array( $screen->base, array( 'post' ), true );
		if ( ! $ours && ! $edit ) {
			return;
		}
		wp_enqueue_style( 'rpw-admin', RPW_URL . 'assets/admin.css', array(), RPW_VERSION );
		wp_enqueue_script( 'rpw-admin', RPW_URL . 'assets/admin.js', array(), RPW_VERSION, true );
		wp_localize_script(
			'rpw-admin',
			'rpwAdmin',
			array(
				'remove' => __( 'Remove', 'rival-price-watch' ),
				'max'    => RPW_Metabox::MAX_LINKS,
			)
		);
	}

	/**
	 * Check one product now.
	 */
	public static function handle_check_product() {
		$pid = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0;
		if ( ! $pid || ! current_user_can( 'edit_post', $pid ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'rival-price-watch' ) );
		}
		check_admin_referer( 'rpw_check_' . $pid );
		$done = RPW_Checker::check_product( $pid );
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . self::PAGE );
		wp_safe_redirect(
			add_query_arg(
				array(
					'rpw_checked' => $done['checked'],
					'rpw_queued'  => $done['queued'],
				),
				$back
			)
		);
		exit;
	}

	/**
	 * Start a full run now.
	 */
	public static function handle_run_all() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'rival-price-watch' ) );
		}
		check_admin_referer( 'rpw_run_all' );
		RPW_Checker::start_run();
		wp_safe_redirect( add_query_arg( 'rpw_started', 1, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * Save exchange rates entered by hand.
	 */
	public static function handle_save_rates() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'rival-price-watch' ) );
		}
		check_admin_referer( 'rpw_save_rates' );
		$rates = isset( $_POST['rates'] ) ? (array) wp_unslash( $_POST['rates'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in save().
		RPW_Currency::save( array_map( 'sanitize_text_field', $rates ), 'manual' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&tab=settings&rpw_rates=saved#rpw-rates' ) );
		exit;
	}

	/**
	 * Download official exchange rates.
	 */
	public static function handle_fetch_rates() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'rival-price-watch' ) );
		}
		check_admin_referer( 'rpw_fetch_rates' );
		$result = RPW_Currency::fetch();
		$arg    = is_wp_error( $result ) ? 'failed' : 'fetched';
		if ( is_wp_error( $result ) ) {
			set_transient( 'rpw_rates_error', $result->get_error_message(), MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&tab=settings&rpw_rates=' . $arg . '#rpw-rates' ) );
		exit;
	}

	/**
	 * Notices after actions.
	 */
	public static function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['rpw_rates'] ) ) {
			$state = sanitize_key( wp_unslash( $_GET['rpw_rates'] ) );
			if ( 'failed' === $state ) {
				$err = get_transient( 'rpw_rates_error' );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ? $err : __( 'Could not download exchange rates.', 'rival-price-watch' ) ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( 'fetched' === $state ? __( 'Exchange rates updated.', 'rival-price-watch' ) : __( 'Exchange rates saved.', 'rival-price-watch' ) ) . '</p></div>';
			}
		}
		if ( isset( $_GET['rpw_checked'] ) ) {
			$n = absint( $_GET['rpw_checked'] );
			$q = isset( $_GET['rpw_queued'] ) ? absint( $_GET['rpw_queued'] ) : 0;
			/* translators: %d: number of competitor links. */
			$msg = sprintf( _n( 'Checked %d competitor link.', 'Checked %d competitor links.', $n, 'rival-price-watch' ), $n );
			if ( $q ) {
				/* translators: %d: number of competitor links still to check. */
				$msg .= ' ' . sprintf( _n( '%d more is being checked in the background; refresh in a minute.', '%d more are being checked in the background; refresh in a minute.', $q, 'rival-price-watch' ), $q );
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( isset( $_GET['rpw_started'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Checking started. Large catalogues are checked in small batches in the background; refresh this page in a few minutes.', 'rival-price-watch' ) . '</p></div>';
		}
		// phpcs:enable
	}

	/**
	 * URL of the "Check now" action for a product.
	 *
	 * @param int $pid Product ID.
	 * @return string
	 */
	public static function check_url( $pid ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=rpw_check_product&product=' . (int) $pid ), 'rpw_check_' . (int) $pid );
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) && 'settings' === $_GET['tab'] ? 'settings' : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		?>
		<div class="wrap rpw-wrap">
			<h1><?php esc_html_e( 'Rival Prices', 'rival-price-watch' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo 'overview' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Overview', 'rival-price-watch' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $url ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'rival-price-watch' ); ?></a>
			</nav>
			<?php
			if ( 'settings' === $tab ) {
				self::render_settings();
			} else {
				self::render_overview();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Overview table.
	 */
	private static function render_overview() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$only_cheaper = ! empty( $_GET['cheaper'] );
		$paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$cur          = isset( $_GET['cur'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['cur'] ) ) ) : '';
		// phpcs:enable
		$ids      = RPW_Checker::watched_products();
		$per_page = 30;

		$summaries  = array();
		$currencies = array();
		foreach ( $ids as $pid ) {
			$markets    = RPW_Checker::markets( $pid );
			$currencies = array_merge( $currencies, array_keys( $markets ) );
			if ( '' !== $cur ) {
				// One market: compare in that currency only.
				if ( ! isset( $markets[ $cur ] ) ) {
					continue;
				}
				$m = $markets[ $cur ];
				$s = array(
					'product'  => wc_get_product( $pid ),
					'own'      => (float) $m['own'],
					'rows'     => $m['rows'],
					'lowest'   => $m['lowest'],
					'diff'     => $m['diff'],
					'gap'      => $m['gap'],
					'checked'  => $m['checked'],
					'currency' => $cur,
					'approx'   => 'converted' === $m['own_source'],
				);
			} else {
				$s = RPW_Checker::summary( $pid );
			}
			if ( ! $s['product'] ) {
				continue;
			}
			if ( $only_cheaper && ( null === $s['diff'] || $s['diff'] >= 0 ) ) {
				continue;
			}
			$summaries[] = $s;
		}
		// Most undercut first.
		usort(
			$summaries,
			static function ( $a, $b ) {
				$da = null === $a['diff'] ? PHP_INT_MAX : $a['diff'];
				$db = null === $b['diff'] ? PHP_INT_MAX : $b['diff'];
				return $da <=> $db;
			}
		);
		$total     = count( $summaries );
		$pages     = max( 1, (int) ceil( $total / $per_page ) );
		$summaries = array_slice( $summaries, ( $paged - 1 ) * $per_page, $per_page );

		$next  = wp_next_scheduled( RPW_Checker::HOOK_RUN );
		$queue = get_option( RPW_Checker::QUEUE, array() );
		$base  = admin_url( 'admin.php?page=' . self::PAGE );
		?>
		<div class="rpw-bar">
			<p>
				<?php
				if ( ! empty( $queue ) ) {
					/* translators: %d: number of links left. */
					echo esc_html( sprintf( __( 'Checking in progress: %d links left.', 'rival-price-watch' ), count( $queue ) ) );
				} elseif ( $next ) {
					/* translators: %s: date and time. */
					echo esc_html( sprintf( __( 'Next automatic check: %s', 'rival-price-watch' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) ) );
				} else {
					esc_html_e( 'Automatic checks are off.', 'rival-price-watch' );
				}
				?>
			</p>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rpw_run_all' ), 'rpw_run_all' ) ); ?>"><?php esc_html_e( 'Check all now', 'rival-price-watch' ); ?></a>
			<form method="get" class="rpw-cur-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php if ( $only_cheaper ) : ?><input type="hidden" name="cheaper" value="1"><?php endif; ?>
				<select name="cur" onchange="this.form.submit()" aria-label="<?php esc_attr_e( 'Market', 'rival-price-watch' ); ?>">
					<option value=""><?php esc_html_e( 'All markets (converted to shop currency)', 'rival-price-watch' ); ?></option>
					<?php foreach ( array_unique( $currencies ) as $c ) : ?>
						<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $cur, $c ); ?>><?php echo esc_html( $c . ' · ' . RPW_Currency::name( $c ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
			<?php if ( $only_cheaper ) : ?>
				<a class="button" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Show all products', 'rival-price-watch' ); ?></a>
			<?php else : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( array_filter( array( 'cheaper' => 1, 'cur' => $cur ) ), $base ) ); ?>"><?php esc_html_e( 'Only where a rival is cheaper', 'rival-price-watch' ); ?></a>
			<?php endif; ?>
		</div>

		<?php if ( empty( $ids ) ) : ?>
			<div class="rpw-empty">
				<h2><?php esc_html_e( 'No competitor links yet', 'rival-price-watch' ); ?></h2>
				<p><?php esc_html_e( 'Open any product, find the "Rival prices" box, and paste the links of the same product on competitor sites. Prices are then checked automatically.', 'rival-price-watch' ); ?></p>
			</div>
			<?php
			return;
		endif;
		?>

		<table class="widefat striped rpw-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'rival-price-watch' ); ?></th>
					<th><?php esc_html_e( 'Your price', 'rival-price-watch' ); ?></th>
					<th><?php esc_html_e( 'Cheapest rival', 'rival-price-watch' ); ?></th>
					<th><?php esc_html_e( 'Difference', 'rival-price-watch' ); ?></th>
					<th><?php esc_html_e( 'All rivals', 'rival-price-watch' ); ?></th>
					<th><?php esc_html_e( 'Last checked', 'rival-price-watch' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $summaries as $s ) : ?>
				<?php $pid = $s['product']->get_id(); ?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_post_link( $pid ) ); ?>"><strong><?php echo esc_html( $s['product']->get_name() ); ?></strong></a></td>
					<td><?php echo $s['own'] > 0 ? ( ! empty( $s['approx'] ) ? '≈ ' : '' ) . wp_kses_post( RPW_Checker::money( $s['own'], $s['currency'] ?? null ) ) : '&mdash;'; ?></td>
					<td>
						<?php if ( $s['lowest'] ) : ?>
							<?php echo wp_kses_post( RPW_Checker::money( $s['lowest']['price'], $s['currency'] ?? null ) ); ?>
							<br><a href="<?php echo esc_url( $s['lowest']['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $s['lowest']['name'] ); ?></a>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td><?php echo self::diff_badge( $s['diff'], $s['gap'], $s['currency'] ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></td>
					<td class="rpw-rivals"><?php self::rival_list( $s['rows'] ); ?></td>
					<td><?php echo $s['checked'] ? esc_html( self::ago( $s['checked'] ) ) : esc_html__( 'Never', 'rival-price-watch' ); ?></td>
					<td><a class="button button-small" href="<?php echo esc_url( self::check_url( $pid ) ); ?>"><?php esc_html_e( 'Check now', 'rival-price-watch' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * Coloured difference label with the amount underneath.
	 *
	 * @param float|null $diff Percent difference (negative = rival cheaper).
	 * @param float|null $gap  Amount difference in shop currency (rival minus yours).
	 * @return string HTML.
	 */
	public static function diff_badge( $diff, $gap = null, $currency = null ) {
		if ( null === $diff ) {
			return '&mdash;';
		}
		$amount = '';
		if ( null !== $gap && abs( $diff ) > 0.5 ) {
			$amount = '<br><span class="rpw-gap">' . ( $gap < 0 ? '&minus;' : '+' ) . wp_kses_post( RPW_Checker::money( abs( $gap ), $currency ) ) . '</span>';
		}
		$class = $diff < -0.5 ? 'rpw-bad' : ( $diff > 0.5 ? 'rpw-good' : 'rpw-even' );
		if ( $diff < -0.5 ) {
			/* translators: %s: percent. */
			$text = sprintf( __( 'Rival %s%% cheaper', 'rival-price-watch' ), number_format_i18n( abs( $diff ), 1 ) );
		} elseif ( $diff > 0.5 ) {
			/* translators: %s: percent. */
			$text = sprintf( __( 'You are %s%% cheaper', 'rival-price-watch' ), number_format_i18n( $diff, 1 ) );
		} else {
			$text = __( 'Same price', 'rival-price-watch' );
		}
		return '<span class="rpw-badge ' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>' . $amount;
	}

	/**
	 * List of rivals with their latest price or problem.
	 *
	 * @param array $rows Rows from summary().
	 */
	public static function rival_list( array $rows ) {
		echo '<ul>';
		foreach ( $rows as $r ) {
			$name = RPW_Settings::link_name( $r['link'] );
			echo '<li><a href="' . esc_url( $r['link']['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $name ) . '</a>: ';
			$last = $r['last'];
			if ( ! $last ) {
				echo '<span class="rpw-muted">' . esc_html__( 'not checked yet', 'rival-price-watch' ) . '</span>';
			} elseif ( 'ok' === $last->status ) {
				echo wp_kses_post( wc_price( (float) $last->price ) );
				$original = self::original_price( $last );
				if ( $original ) {
					echo ' <span class="rpw-muted">(' . esc_html( $original ) . ')</span>';
				}
				if ( '0' === (string) $last->in_stock ) {
					echo ' <span class="rpw-muted">(' . esc_html__( 'out of stock', 'rival-price-watch' ) . ')</span>';
				}
			} elseif ( 'norate' === $last->status ) {
				echo esc_html( (string) self::original_price( $last ) ) . ' <a class="rpw-error" title="' . esc_attr( $last->message ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=settings#rpw-rates' ) ) . '">' . esc_html__( 'needs an exchange rate', 'rival-price-watch' ) . '</a>';
			} else {
				echo '<span class="rpw-error" title="' . esc_attr( $last->message ) . '">' . esc_html( 'noprice' === $last->status ? __( 'no price found', 'rival-price-watch' ) : __( 'error', 'rival-price-watch' ) ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * The rival's own price and currency, when it differs from the shop currency.
	 *
	 * @param object $last Result row.
	 * @return string Empty when the price was already in the shop currency.
	 */
	public static function original_price( $last ) {
		if ( empty( $last->raw_currency ) || null === $last->raw_price || strtoupper( $last->raw_currency ) === RPW_Currency::shop() ) {
			return '';
		}
		$raw      = (float) $last->raw_price;
		$decimals = floor( $raw ) === $raw ? 0 : 2;
		return number_format_i18n( $raw, $decimals ) . ' ' . strtoupper( $last->raw_currency );
	}

	/**
	 * "5 minutes ago" from a UTC MySQL date.
	 *
	 * @param string $mysql_utc Date.
	 * @return string
	 */
	public static function ago( $mysql_utc ) {
		$ts = strtotime( $mysql_utc . ' UTC' );
		/* translators: %s: time span such as "5 mins". */
		return sprintf( __( '%s ago', 'rival-price-watch' ), human_time_diff( $ts, time() ) );
	}

	/**
	 * Settings form.
	 */
	private static function render_settings() {
		$s = RPW_Settings::get();
		$o = RPW_Settings::OPTION;
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'rpw_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="rpw-frequency"><?php esc_html_e( 'Check prices', 'rival-price-watch' ); ?></label></th>
					<td>
						<select id="rpw-frequency" name="<?php echo esc_attr( $o ); ?>[frequency]">
							<?php foreach ( RPW_Settings::frequencies() as $k => $label ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['frequency'], $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Uses WP-Cron, which runs when someone visits your site. On quiet sites, set up a real server cron job for exact timing.', 'rival-price-watch' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email alerts', 'rival-price-watch' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[alerts]" value="1" <?php checked( $s['alerts'], 1 ); ?>> <?php esc_html_e( 'Email me when a competitor becomes cheaper than me', 'rival-price-watch' ); ?></label>
						<p><input type="email" class="regular-text" name="<?php echo esc_attr( $o ); ?>[alert_email]" value="<?php echo esc_attr( $s['alert_email'] ); ?>" aria-label="<?php esc_attr_e( 'Alert email address', 'rival-price-watch' ); ?>"></p>
						<p class="description"><?php esc_html_e( 'One email per check run, listing every product that changed. You are not emailed again until the price changes.', 'rival-price-watch' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rpw-delay"><?php esc_html_e( 'Pause between requests', 'rival-price-watch' ); ?></label></th>
					<td>
						<input id="rpw-delay" type="number" min="1" max="30" name="<?php echo esc_attr( $o ); ?>[delay]" value="<?php echo esc_attr( $s['delay'] ); ?>" class="small-text"> <?php esc_html_e( 'seconds', 'rival-price-watch' ); ?>
						<p class="description"><?php esc_html_e( 'A pause between pages keeps checks gentle on competitor sites and lowers the chance of being blocked.', 'rival-price-watch' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rpw-keep"><?php esc_html_e( 'Keep price history for', 'rival-price-watch' ); ?></label></th>
					<td><input id="rpw-keep" type="number" min="7" max="3650" name="<?php echo esc_attr( $o ); ?>[keep_days]" value="<?php echo esc_attr( $s['keep_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days', 'rival-price-watch' ); ?></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
		self::render_rates();
	}

	/**
	 * Exchange-rate form.
	 */
	private static function render_rates() {
		$shop   = RPW_Currency::shop();
		$stored = RPW_Currency::stored();
		$rates  = $stored['rates'];
		$iran   = array( 'IRR', 'IRT' );
		?>
		<hr>
		<h2 id="rpw-rates"><?php esc_html_e( 'Exchange rates', 'rival-price-watch' ); ?></h2>
		<p class="description" style="max-width:720px">
			<?php
			/* translators: %s: shop currency code and name. */
			echo esc_html( sprintf( __( 'Rival prices in another currency are converted to your shop currency (%s) before comparing. Fill in only the currencies your competitors use; leave the rest empty.', 'rival-price-watch' ), $shop . ' · ' . RPW_Currency::name( $shop ) ) );
			echo ' ';
			esc_html_e( 'Rial and toman are converted automatically (1 toman = 10 rials).', 'rival-price-watch' );
			?>
		</p>
		<?php if ( $stored['updated'] ) : ?>
			<p class="rpw-muted">
				<?php
				$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stored['updated'] );
				/* translators: %s: date. */
				echo esc_html( 'auto' === $stored['source'] ? sprintf( __( 'Official rates downloaded on %s.', 'rival-price-watch' ), $when ) : sprintf( __( 'Last saved on %s.', 'rival-price-watch' ), $when ) );
				?>
			</p>
		<?php endif; ?>

		<?php if ( RPW_Currency::can_fetch() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rpw-inline-form">
				<input type="hidden" name="action" value="rpw_fetch_rates">
				<?php wp_nonce_field( 'rpw_fetch_rates' ); ?>
				<button class="button"><?php esc_html_e( 'Fill in today\'s official rates', 'rival-price-watch' ); ?></button>
				<span class="rpw-muted"><?php esc_html_e( 'From the free open.er-api.com service. Only sent when you click.', 'rival-price-watch' ); ?></span>
			</form>
		<?php else : ?>
			<p class="notice notice-info inline" style="padding:8px 12px"><?php esc_html_e( 'Your shop uses the rial or toman. Official rates are far from the free-market rate, so enter the market rate for each currency yourself.', 'rival-price-watch' ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="rpw_save_rates">
			<?php wp_nonce_field( 'rpw_save_rates' ); ?>
			<div class="rpw-rates">
				<?php foreach ( array_keys( RPW_Currency::codes() ) as $code ) : ?>
					<?php
					if ( $code === $shop || ( in_array( $code, $iran, true ) && in_array( $shop, $iran, true ) ) ) {
						continue;
					}
					?>
					<label class="rpw-rate">
						<span class="rpw-rate-name"><b><?php echo esc_html( $code ); ?></b> <?php echo esc_html( RPW_Currency::name( $code ) ); ?></span>
						<span class="rpw-rate-input">1 <?php echo esc_html( $code ); ?> =
							<input type="text" inputmode="decimal" name="rates[<?php echo esc_attr( $code ); ?>]" value="<?php echo isset( $rates[ $code ] ) ? esc_attr( RPW_Currency::format_rate( (float) $rates[ $code ] ) ) : ''; ?>">
							<?php echo esc_html( $shop ); ?>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
			<?php submit_button( __( 'Save exchange rates', 'rival-price-watch' ) ); ?>
		</form>
		<?php
	}
}
