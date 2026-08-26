<?php
/**
 * Front-end vendor dashboard.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the vendor dashboard shortcode and handles its form submissions.
 */
class AEC_Market_Dashboard {

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'wpaec_vendor_dashboard', array( __CLASS__, 'dashboard_shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_product_save' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_settings_save' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register and enqueue front-end assets on marketplace pages.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		wp_register_style( 'aec-market', AEC_MARKET_PLUGIN_URL . 'assets/css/aec-market.css', array(), AEC_MARKET_VERSION );
		wp_register_script( 'aec-market-dashboard', AEC_MARKET_PLUGIN_URL . 'assets/js/aec-market-dashboard.js', array(), AEC_MARKET_VERSION, true );

		$dashboard_id = absint( wpaec_get_setting( 'vendor_dashboard_page' ) );

		$ids = array_filter(
			array_map(
				'absint',
				array(
					$dashboard_id,
					wpaec_get_setting( 'vendor_register_page' ),
					wpaec_get_setting( 'vendor_store_page' ),
				)
			)
		);

		if ( ( $ids && is_page( $ids ) ) || is_product() ) {
			wp_enqueue_style( 'aec-market' );
		}

		if ( $dashboard_id && is_page( $dashboard_id ) ) {
			wp_enqueue_script( 'aec-market-dashboard' );
		}
	}

	/**
	 * Current dashboard tab, whitelisted.
	 *
	 * @return string
	 */
	public static function current_tab() {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$tabs = array_keys( self::tabs() );
		return in_array( $tab, $tabs, true ) ? $tab : 'overview';
	}

	/**
	 * Dashboard tabs.
	 *
	 * @return array<string,string> slug => label.
	 */
	public static function tabs() {
		return array(
			'overview' => __( 'Overview', 'aec-market' ),
			'products' => __( 'Products', 'aec-market' ),
			'edit'     => __( 'Add Product', 'aec-market' ),
			'orders'   => __( 'Orders', 'aec-market' ),
			'earnings' => __( 'Earnings', 'aec-market' ),
			'settings' => __( 'Store Settings', 'aec-market' ),
		);
	}

	/**
	 * Render the dashboard shortcode.
	 *
	 * @return string
	 */
	public static function dashboard_shortcode() {
		ob_start();

		if ( ! is_user_logged_in() ) {
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'Please log in to access the vendor dashboard.', 'aec-market' ),
				esc_url( wp_login_url( get_permalink() ) ),
				esc_html__( 'Log in', 'aec-market' )
			);
			return ob_get_clean();
		}

		if ( ! wpaec_is_vendor() ) {
			$register = get_permalink( absint( wpaec_get_setting( 'vendor_register_page' ) ) );
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'This area is for approved vendors.', 'aec-market' ),
				esc_url( $register ),
				esc_html__( 'Apply to become a vendor', 'aec-market' )
			);
			return ob_get_clean();
		}

		wpaec_get_template(
			'dashboard/dashboard.php',
			array(
				'tab'    => self::current_tab(),
				'tabs'   => self::tabs(),
				'notice' => AEC_Market_Vendor::get_notice(),
			)
		);

		return ob_get_clean();
	}

	/**
	 * URL for a dashboard tab.
	 *
	 * @param string $tab  Tab slug.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function tab_url( $tab, $args = array() ) {
		$base = get_permalink( absint( wpaec_get_setting( 'vendor_dashboard_page' ) ) );
		return add_query_arg( array_merge( array( 'tab' => sanitize_key( $tab ) ), $args ), $base );
	}

	/**
	 * Handle the create/edit product form.
	 *
	 * @return void
	 */
	public static function handle_product_save() {
		if ( ! isset( $_POST['wpaec_save_product'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() || ! wpaec_is_vendor() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'aec-market' ) );
		}

		if ( ! isset( $_POST['wpaec_product_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wpaec_product_nonce'] ) ), 'wpaec_save_product' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'aec-market' ) );
		}

		$vendor_id  = get_current_user_id();
		$product_id = isset( $_POST['wpaec_product_id'] ) ? absint( wp_unslash( $_POST['wpaec_product_id'] ) ) : 0;

		// Editing: the product must belong to this vendor.
		if ( $product_id ) {
			$post = get_post( $product_id );
			if ( ! $post || 'product' !== $post->post_type || (int) $post->post_author !== $vendor_id ) {
				wp_die( esc_html__( 'You are not allowed to edit this product.', 'aec-market' ) );
			}
		}

		$title        = isset( $_POST['wpaec_title'] ) ? sanitize_text_field( wp_unslash( $_POST['wpaec_title'] ) ) : '';
		$description  = isset( $_POST['wpaec_description'] ) ? wp_kses_post( wp_unslash( $_POST['wpaec_description'] ) ) : '';
		$price        = isset( $_POST['wpaec_price'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['wpaec_price'] ) ) ) : '';
		$listing_type = isset( $_POST['wpaec_listing_type'] ) && 'service' === sanitize_key( wp_unslash( $_POST['wpaec_listing_type'] ) ) ? 'service' : 'program';
		$category     = isset( $_POST['wpaec_category'] ) ? absint( wp_unslash( $_POST['wpaec_category'] ) ) : 0;

		if ( '' === $title ) {
			self::notice_redirect( 'error', __( 'A product title is required.', 'aec-market' ), 'edit' );
		}

		$status = $product_id ? get_post_status( $product_id ) : sanitize_key( wpaec_get_setting( 'product_status', 'pending' ) );
		$status = in_array( $status, array( 'pending', 'publish', 'draft' ), true ) ? $status : 'pending';

		$postarr = array(
			'post_title'   => $title,
			'post_content' => $description,
			'post_type'    => 'product',
			'post_status'  => $status,
			'post_author'  => $vendor_id,
		);

		if ( $product_id ) {
			$postarr['ID'] = $product_id;
			$result        = wp_update_post( $postarr, true );
		} else {
			$result = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $result ) ) {
			self::notice_redirect( 'error', $result->get_error_message(), 'edit' );
		}

		$product_id = (int) $result;

		// Core product data via CRUD.
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$product->set_regular_price( $price );
			$product->set_virtual( true );
			$product->set_downloadable( 'program' === $listing_type );
			$product->set_catalog_visibility( 'visible' );
			$product->save();
		}

		update_post_meta( $product_id, '_wpaec_listing_type', $listing_type );

		if ( $category && term_exists( $category, 'product_cat' ) ) {
			wp_set_object_terms( $product_id, $category, 'product_cat' );
		}

		// Compatibility tags (nonce verified above).
		if ( class_exists( 'AEC_Market_Compat' ) ) {
			$compat = isset( $_POST['wpaec_compat'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['wpaec_compat'] ) ) : array();
			wp_set_object_terms( $product_id, $compat, AEC_Market_Compat::TAX, false );
		}

		if ( 'program' === $listing_type ) {
			update_post_meta( $product_id, '_wpaec_license_enabled', isset( $_POST['wpaec_license_enabled'] ) ? 'yes' : 'no' );
			$limit = isset( $_POST['wpaec_activation_limit'] ) ? max( 1, absint( wp_unslash( $_POST['wpaec_activation_limit'] ) ) ) : 1;
			update_post_meta( $product_id, '_wpaec_activation_limit', $limit );

			// Item-depth fields (nonce verified above).
			update_post_meta( $product_id, '_wpaec_version', isset( $_POST['wpaec_version'] ) ? sanitize_text_field( wp_unslash( $_POST['wpaec_version'] ) ) : '' );
			update_post_meta( $product_id, '_wpaec_demo_url', isset( $_POST['wpaec_demo_url'] ) ? esc_url_raw( wp_unslash( $_POST['wpaec_demo_url'] ) ) : '' );
			update_post_meta( $product_id, '_wpaec_changelog', isset( $_POST['wpaec_changelog'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wpaec_changelog'] ) ) : '' );

			// License tiers + support (nonce verified above).
			$ext_price = isset( $_POST['wpaec_extended_price'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['wpaec_extended_price'] ) ) ) : '';
			update_post_meta( $product_id, '_wpaec_extended_price', $ext_price );
			update_post_meta( $product_id, '_wpaec_extended_desc', isset( $_POST['wpaec_extended_desc'] ) ? sanitize_text_field( wp_unslash( $_POST['wpaec_extended_desc'] ) ) : '' );
			update_post_meta( $product_id, '_wpaec_support_months', isset( $_POST['wpaec_support_months'] ) ? absint( wp_unslash( $_POST['wpaec_support_months'] ) ) : 0 );

			self::maybe_attach_upload( $product_id );
		} else {
			self::save_service_tiers( $product_id );
		}

		/**
		 * Fires after a vendor saves a product from the dashboard.
		 *
		 * @since 1.0.0
		 *
		 * @param int $product_id Product ID.
		 * @param int $vendor_id  Vendor user ID.
		 */
		do_action( 'wpaecmarket_vendor_product_saved', $product_id, $vendor_id );

		$message = 'pending' === get_post_status( $product_id )
			? __( 'Product saved. It will appear in the catalog once approved by the marketplace team.', 'aec-market' )
			: __( 'Product saved.', 'aec-market' );

		self::notice_redirect( 'success', $message, 'products' );
	}

	/**
	 * Validate and attach an uploaded deliverable file to a downloadable product.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private static function maybe_attach_upload( $product_id ) {
		if ( empty( $_FILES['wpaec_file'] ) || empty( $_FILES['wpaec_file']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_product_save().
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$allowed = array_map( 'trim', explode( ',', strtolower( (string) wpaec_get_setting( 'allowed_upload_types' ) ) ) );
		$name    = sanitize_file_name( wp_unslash( $_FILES['wpaec_file']['name'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ext     = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, $allowed, true ) ) {
			self::notice_redirect(
				'error',
				sprintf(
					/* translators: %s: comma-separated list of allowed file extensions. */
					__( 'That file type is not allowed. Allowed types: %s', 'aec-market' ),
					implode( ', ', $allowed )
				),
				'edit',
				array( 'product' => $product_id )
			);
		}

		// Extend WordPress' MIME whitelist with the marketplace's allowed AEC
		// formats (many, e.g. .rvt/.ifc/.gh, are unknown to core).
		$mimes = wp_get_mime_types();
		foreach ( $allowed as $allowed_ext ) {
			if ( ! self::mime_registered( $allowed_ext, $mimes ) ) {
				$mimes[ $allowed_ext ] = 'application/octet-stream';
			}
		}

		$file_data = isset( $_FILES['wpaec_file'] ) ? $_FILES['wpaec_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_product_save(); the raw file array is validated by wp_handle_upload().

		$upload = wp_handle_upload(
			$file_data,
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);

		if ( isset( $upload['error'] ) ) {
			self::notice_redirect( 'error', $upload['error'], 'edit', array( 'product' => $product_id ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		// Guarantee the uploads directory is an approved WooCommerce download
		// directory, otherwise set_downloads() rejects the vendor's file.
		self::ensure_approved_upload_dir();

		$download = new WC_Product_Download();
		$download->set_id( md5( $upload['url'] ) );
		$download->set_name( $name );
		$download->set_file( $upload['url'] );

		try {
			$product->set_downloads( array( $download ) );
		} catch ( \Throwable $e ) {
			self::notice_redirect( 'error', __( 'The file could not be attached as a download. Please contact support.', 'aec-market' ), 'edit', array( 'product' => $product_id ) );
		}
		$product->set_downloadable( true );
		$product->save();
	}

	/**
	 * Ensure the site uploads directory is registered as a WooCommerce
	 * "approved download directory". Without it, set_downloads() throws
	 * "the downloadable file is not located within an approved directory",
	 * which would block vendors from attaching their deliverables.
	 *
	 * @return void
	 */
	private static function ensure_approved_upload_dir() {
		$register_class = 'Automattic\\WooCommerce\\Internal\\ProductDownloads\\ApprovedDirectories\\Register';
		if ( ! function_exists( 'wc_get_container' ) || ! class_exists( $register_class ) ) {
			return;
		}
		try {
			$register = wc_get_container()->get( $register_class );
			$url      = wp_upload_dir()['baseurl'];
			if ( method_exists( $register, 'get_by_url' ) && ! $register->get_by_url( $url ) ) {
				$register->add_approved_directory( $url );
			}
		} catch ( \Throwable $e ) {
			return; // Non-fatal — WooCommerce's own validation still governs.
		}
	}

	/**
	 * Whether an extension is already covered by a WP MIME whitelist,
	 * where keys may be compound like 'jpg|jpeg|jpe'.
	 *
	 * @param string $ext   File extension.
	 * @param array  $mimes MIME map.
	 * @return bool
	 */
	private static function mime_registered( $ext, $mimes ) {
		foreach ( array_keys( $mimes ) as $key ) {
			if ( in_array( $ext, explode( '|', $key ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Persist tier fields from the dashboard product form.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private static function save_service_tiers( $product_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_product_save(); every array element is sanitized by the array_map callbacks.
		$names      = isset( $_POST['wpaec_tier_name'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['wpaec_tier_name'] ) ) : array();
		$prices     = isset( $_POST['wpaec_tier_price'] ) ? array_map( 'wc_format_decimal', array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['wpaec_tier_price'] ) ) ) : array();
		$descs      = isset( $_POST['wpaec_tier_description'] ) ? array_map( 'sanitize_textarea_field', wp_unslash( (array) $_POST['wpaec_tier_description'] ) ) : array();
		$deliveries = isset( $_POST['wpaec_tier_delivery'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['wpaec_tier_delivery'] ) ) : array();
		// phpcs:enable

		$tiers = array();
		foreach ( $names as $i => $name ) {
			if ( '' === $name || ! isset( $prices[ $i ] ) || '' === $prices[ $i ] ) {
				continue;
			}
			$tiers[] = array(
				'name'          => $name,
				'price'         => $prices[ $i ],
				'description'   => isset( $descs[ $i ] ) ? $descs[ $i ] : '',
				'delivery_days' => isset( $deliveries[ $i ] ) ? $deliveries[ $i ] : 0,
			);
		}

		update_post_meta( $product_id, '_wpaec_service_tiers', $tiers );

		// Keep the base price in sync with the cheapest tier so sorting works.
		if ( ! empty( $tiers ) ) {
			$min     = min( array_map( 'floatval', wp_list_pluck( $tiers, 'price' ) ) );
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$product->set_regular_price( (string) $min );
				$product->save();
			}
		}
	}

	/**
	 * Handle the store settings form.
	 *
	 * @return void
	 */
	public static function handle_settings_save() {
		if ( ! isset( $_POST['wpaec_save_settings'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() || ! wpaec_is_vendor() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'aec-market' ) );
		}

		if ( ! isset( $_POST['wpaec_settings_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wpaec_settings_nonce'] ) ), 'wpaec_save_settings' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'aec-market' ) );
		}

		$user_id = get_current_user_id();

		$fields = array(
			'_wpaec_store_name'   => isset( $_POST['wpaec_store_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wpaec_store_name'] ) ) : '',
			'_wpaec_store_bio'    => isset( $_POST['wpaec_store_bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wpaec_store_bio'] ) ) : '',
			'_wpaec_paypal_email' => isset( $_POST['wpaec_paypal_email'] ) ? sanitize_email( wp_unslash( $_POST['wpaec_paypal_email'] ) ) : '',
			'_wpaec_stripe_id'    => isset( $_POST['wpaec_stripe_id'] ) ? sanitize_text_field( wp_unslash( $_POST['wpaec_stripe_id'] ) ) : '',
		);

		foreach ( $fields as $key => $value ) {
			update_user_meta( $user_id, $key, $value );
		}

		self::notice_redirect( 'success', __( 'Store settings saved.', 'aec-market' ), 'settings' );
	}

	/**
	 * Store a one-time notice and redirect to a dashboard tab.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message text.
	 * @param string $tab     Destination tab.
	 * @param array  $args    Extra query args.
	 * @return void
	 */
	private static function notice_redirect( $type, $message, $tab, $args = array() ) {
		set_transient(
			'wpaec_notice_u' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS * 5
		);
		wp_safe_redirect( self::tab_url( $tab, $args ) );
		exit;
	}
}
