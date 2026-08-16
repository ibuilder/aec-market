<?php
/**
 * Installation, activation and upgrade routines.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles activation: custom tables, roles, pages, terms and defaults.
 */
class AEC_Market_Install {

	/**
	 * Plugin activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		self::create_roles();
		self::create_default_settings();
		self::create_pages();
		self::create_terms();

		update_option( 'wpaecmarket_version', AEC_MARKET_VERSION );

		/**
		 * Fires after the plugin has been activated and installed.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wpaecmarket_activated' );
	}

	/**
	 * Plugin deactivation callback. Intentionally leaves data in place;
	 * removal happens in uninstall.php.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// No-op. Data is only removed on uninstall.
	}

	/**
	 * Run upgrade routines when the stored version differs from the code version.
	 *
	 * @return void
	 */
	public static function maybe_update() {
		if ( version_compare( (string) get_option( 'wpaecmarket_version' ), AEC_MARKET_VERSION, '<' ) ) {
			self::create_tables();
			self::create_roles();
			update_option( 'wpaecmarket_version', AEC_MARKET_VERSION );
		}
	}

	/**
	 * Create custom database tables using dbDelta.
	 *
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();

		$tables = "
CREATE TABLE {$wpdb->prefix}wpaec_commissions (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	order_id BIGINT UNSIGNED NOT NULL,
	order_item_id BIGINT UNSIGNED NOT NULL,
	product_id BIGINT UNSIGNED NOT NULL,
	vendor_id BIGINT UNSIGNED NOT NULL,
	line_total DECIMAL(19,4) NOT NULL DEFAULT 0,
	commission_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
	commission_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
	vendor_earning DECIMAL(19,4) NOT NULL DEFAULT 0,
	status VARCHAR(20) NOT NULL DEFAULT 'pending',
	created_at DATETIME NOT NULL,
	paid_at DATETIME NULL DEFAULT NULL,
	PRIMARY KEY  (id),
	KEY order_id (order_id),
	KEY vendor_id (vendor_id),
	KEY status (status)
) $collate;
CREATE TABLE {$wpdb->prefix}wpaec_licenses (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	license_key VARCHAR(64) NOT NULL,
	order_id BIGINT UNSIGNED NOT NULL,
	product_id BIGINT UNSIGNED NOT NULL,
	user_id BIGINT UNSIGNED NOT NULL,
	vendor_id BIGINT UNSIGNED NOT NULL,
	activation_limit INT UNSIGNED NOT NULL DEFAULT 1,
	status VARCHAR(20) NOT NULL DEFAULT 'active',
	expires_at DATETIME NULL DEFAULT NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY license_key (license_key),
	KEY order_id (order_id),
	KEY product_id (product_id),
	KEY user_id (user_id)
) $collate;
CREATE TABLE {$wpdb->prefix}wpaec_license_activations (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	license_id BIGINT UNSIGNED NOT NULL,
	instance VARCHAR(191) NOT NULL,
	activated_at DATETIME NOT NULL,
	deactivated_at DATETIME NULL DEFAULT NULL,
	PRIMARY KEY  (id),
	KEY license_id (license_id),
	KEY instance (instance)
) $collate;
";

		dbDelta( $tables );
	}

	/**
	 * Register the vendor role and marketplace capabilities.
	 *
	 * @return void
	 */
	private static function create_roles() {
		add_role(
			'wpaec_vendor',
			__( 'Marketplace Vendor', 'aec-market' ),
			array(
				'read'                => true,
				'upload_files'        => true,
				'wpaec_vendor'        => true,
				'wpaec_sell_products' => true,
			)
		);

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'wpaec_manage_marketplace' );
		}

		$shop_manager = get_role( 'shop_manager' );
		if ( $shop_manager ) {
			$shop_manager->add_cap( 'wpaec_manage_marketplace' );
		}
	}

	/**
	 * Seed default settings without overwriting existing ones.
	 *
	 * @return void
	 */
	private static function create_default_settings() {
		$defaults = array(
			'commission_rate'          => 10,
			'vendor_approval'          => 'manual',
			'product_status'           => 'pending',
			'license_activation_limit' => 1,
			'allowed_upload_types'     => 'zip,xlsx,xlsm,csv,json,py,gh,ghx,dyn,rvt,rfa,ifc',
		);

		$existing = get_option( 'wpaecmarket_settings', array() );
		update_option( 'wpaecmarket_settings', wp_parse_args( $existing, $defaults ) );
	}

	/**
	 * Create the marketplace front-end pages if they do not exist yet.
	 *
	 * @return void
	 */
	private static function create_pages() {
		$pages = array(
			'vendor_dashboard_page' => array(
				'title'   => __( 'Vendor Dashboard', 'aec-market' ),
				'content' => '<!-- wp:shortcode -->[wpaec_vendor_dashboard]<!-- /wp:shortcode -->',
			),
			'vendor_register_page'  => array(
				'title'   => __( 'Become a Vendor', 'aec-market' ),
				'content' => '<!-- wp:shortcode -->[wpaec_vendor_registration]<!-- /wp:shortcode -->',
			),
			'vendor_store_page'     => array(
				'title'   => __( 'Vendor Store', 'aec-market' ),
				'content' => '<!-- wp:shortcode -->[wpaec_vendor_store]<!-- /wp:shortcode -->',
			),
		);

		$settings = get_option( 'wpaecmarket_settings', array() );

		foreach ( $pages as $key => $page ) {
			$page_id = isset( $settings[ $key ] ) ? absint( $settings[ $key ] ) : 0;

			if ( $page_id && 'page' === get_post_type( $page_id ) ) {
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_title'     => $page['title'],
					'post_content'   => $page['content'],
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
				)
			);

			if ( $page_id && ! is_wp_error( $page_id ) ) {
				$settings[ $key ] = $page_id;
			}
		}

		update_option( 'wpaecmarket_settings', $settings );
	}

	/**
	 * Create the default product category tree for the marketplace.
	 *
	 * @return void
	 */
	private static function create_terms() {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return;
		}

		$tree = array(
			__( 'Programs & Scripts', 'aec-market' ) => array(
				__( 'BIM & Revit Add-ins', 'aec-market' ),
				__( 'Excel Templates & Macros', 'aec-market' ),
				__( 'AI Tools & GPTs', 'aec-market' ),
				__( 'IFC & GIS Scripts', 'aec-market' ),
			),
			__( 'Services', 'aec-market' )           => array(
				__( 'BIM Modeling', 'aec-market' ),
				__( 'IFC Cleanup & Audits', 'aec-market' ),
				__( 'Excel Automation', 'aec-market' ),
				__( 'Custom Development', 'aec-market' ),
			),
		);

		foreach ( $tree as $parent_name => $children ) {
			$parent = term_exists( $parent_name, 'product_cat' );
			if ( ! $parent ) {
				$parent = wp_insert_term( $parent_name, 'product_cat' );
			}
			if ( is_wp_error( $parent ) ) {
				continue;
			}
			$parent_id = (int) $parent['term_id'];

			foreach ( $children as $child_name ) {
				if ( ! term_exists( $child_name, 'product_cat', $parent_id ) ) {
					wp_insert_term( $child_name, 'product_cat', array( 'parent' => $parent_id ) );
				}
			}
		}
	}
}
