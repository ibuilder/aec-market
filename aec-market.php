<?php
/**
 * Plugin Name:       AEC Market – Skills & Programs Marketplace
 * Plugin URI:        https://github.com/ibuilder/aec-market
 * Description:       An Envato-style multi-vendor marketplace for AEC/BIM specialists, Excel/automation experts and AI tool authors. Sell digital products (scripts, templates, add-ins) with license keys and tiered services (Basic/Standard/Premium) side by side, with vendor dashboards, commissions and payout tracking. Includes AEC Forge Tools — pay-per-use AI tools for GC paperwork (RFIs, submittals, pay-apps, cost exposure) sold as WooCommerce credits. Requires WooCommerce.
 * Version:           1.3.0
 * Author:            AEC Market
 * Author URI:        https://github.com/ibuilder
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aec-market
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   9.9
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

define( 'AEC_MARKET_VERSION', '1.3.0' );
define( 'AEC_MARKET_PLUGIN_FILE', __FILE__ );
define( 'AEC_MARKET_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AEC_MARKET_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AEC_MARKET_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// AEC Forge Tools (pay-per-use AI tools) — merged subsystem constants.
define( 'AEC_FORGE_TOOLS_URL', AEC_MARKET_PLUGIN_URL );
define( 'AEC_FORGE_TOOLS_VERSION', AEC_MARKET_VERSION );

/**
 * Autoloader for the AEC_Forge_Tools namespace (includes/tools/).
 *
 * AEC_Forge_Tools\Service_Registry     -> includes/tools/class-service-registry.php
 * AEC_Forge_Tools\Services\Service_Rfi -> includes/tools/services/class-service-rfi.php
 * AEC_Forge_Tools\Files\Xlsx_Writer    -> includes/tools/files/class-xlsx-writer.php
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'AEC_Forge_Tools\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$class_nm = array_pop( $parts );
		$sub      = array_map(
			static function ( $p ) {
				return strtolower( str_replace( '_', '-', $p ) );
			},
			$parts
		);
		$file_nm = 'class-' . strtolower( str_replace( '_', '-', $class_nm ) ) . '.php';
		$path    = AEC_MARKET_PLUGIN_DIR . 'includes/tools/' . ( $sub ? implode( '/', $sub ) . '/' : '' ) . $file_nm;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

require_once AEC_MARKET_PLUGIN_DIR . 'includes/class-wpaec-install.php';

register_activation_hook( __FILE__, array( 'AEC_Market_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AEC_Market_Install', 'deactivate' ) );

/**
 * Main plugin bootstrap class.
 */
final class AEC_Market {

	/**
	 * Singleton instance.
	 *
	 * @var AEC_Market|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return AEC_Market
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Hooks bootstrap actions.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
		add_action( 'before_woocommerce_init', array( $this, 'declare_wc_compatibility' ) );
	}

	/**
	 * Initialise the plugin once all plugins are loaded.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		$this->includes();

		AEC_Market_Vendor::init();
		AEC_Market_Products::init();
		AEC_Market_Commissions::init();
		AEC_Market_Licenses::init();
		AEC_Market_Rest::init();
		AEC_Market_Dashboard::init();
		AEC_Market_Frontend::init();
		AEC_Market_Emails::init();

		if ( is_admin() ) {
			AEC_Market_Admin::init();
		}

		AEC_Market_Install::maybe_update();

		// Boot the AEC Forge Tools subsystem (credits wallet, credit packs, AI tools).
		\AEC_Forge_Tools\Tools::instance()->run();

		/**
		 * Fires after WP AEC Market is fully loaded.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wpaecmarket_loaded' );
	}

	/**
	 * Load class files.
	 *
	 * @return void
	 */
	private function includes() {
		require_once AEC_MARKET_PLUGIN_DIR . 'includes/wpaec-functions.php';
		require_once AEC_MARKET_PLUGIN_DIR . 'includes/class-wpaec-vendor.php';
		require_once AEC_MARKET_PLUGIN_DIR . 'includes/class-wpaec-products.php';
		require_once AEC_MARKET_PLUGIN_DIR . 'includes/class-wpaec-commissions.php';
		require_once AEC_MARKET_PLUGIN_DIR . 'includes/class-wpaec-licenses.php';
		require_once AEC_MARKET_PLUGIN_DIR . 'includes/class-wpaec-rest.php';
		require_once AEC_MARKET_PLUGIN_DIR . 'includes/class-wpaec-dashboard.php';
		require_once AEC_MARKET_PLUGIN_DIR . 'includes/class-wpaec-frontend.php';
		require_once AEC_MARKET_PLUGIN_DIR . 'includes/class-wpaec-emails.php';

		if ( is_admin() ) {
			require_once AEC_MARKET_PLUGIN_DIR . 'includes/class-wpaec-admin.php';
		}
	}

	/**
	 * Declare compatibility with WooCommerce High-Performance Order Storage.
	 *
	 * @return void
	 */
	public function declare_wc_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}

	/**
	 * Admin notice shown when WooCommerce is not active.
	 *
	 * @return void
	 */
	public function woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'WP AEC Market requires WooCommerce to be installed and active.', 'aec-market' )
		);
	}
}

AEC_Market::instance();
