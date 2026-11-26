<?php
/**
 * Plugin Name:       Rival Price Watch
 * Description:       Track competitor prices for your WooCommerce products. Add competitor product links, get daily price checks, price history and email alerts when a rival is cheaper.
 * Version:           0.2.9
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Kiamars Jahangiry
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rival-price-watch
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.3
 *
 * @package RivalPriceWatch
 */

defined( 'ABSPATH' ) || exit;

define( 'RPW_VERSION', '0.2.9' );
define( 'RPW_FILE', __FILE__ );
define( 'RPW_DIR', plugin_dir_path( __FILE__ ) );
define( 'RPW_URL', plugin_dir_url( __FILE__ ) );

require_once RPW_DIR . 'includes/class-rpw-extractor.php';
require_once RPW_DIR . 'includes/class-rpw-currency.php';
require_once RPW_DIR . 'includes/class-rpw-db.php';
require_once RPW_DIR . 'includes/class-rpw-jobs.php';
require_once RPW_DIR . 'includes/class-rpw-settings.php';
require_once RPW_DIR . 'includes/class-rpw-checker.php';
require_once RPW_DIR . 'includes/class-rpw-admin.php';
require_once RPW_DIR . 'includes/class-rpw-metabox.php';

register_activation_hook( __FILE__, array( 'RPW_DB', 'install' ) );
register_activation_hook( __FILE__, array( 'RPW_Checker', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'RPW_Checker', 'unschedule' ) );

// Declare compatibility with WooCommerce High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Rival Price Watch needs WooCommerce to be installed and active.', 'rival-price-watch' ) . '</p></div>';
				}
			);
			return;
		}
		RPW_DB::maybe_upgrade();
		RPW_Checker::init();
		RPW_Admin::init();
		RPW_Metabox::init();
	}
);

// Loads the bundled translations (e.g. Persian) that ship inside the plugin.
// Translations from translate.wordpress.org load automatically and take priority.
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'rival-price-watch', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);
