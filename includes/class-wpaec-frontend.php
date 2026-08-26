<?php
/**
 * Public-facing marketplace output.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Sold by" attribution and vendor store pages.
 */
class AEC_Market_Frontend {

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'wpaec_vendor_store', array( __CLASS__, 'store_shortcode' ) );
		add_shortcode( 'wpaec_vendors', array( __CLASS__, 'vendors_shortcode' ) );
		add_action( 'woocommerce_product_meta_start', array( __CLASS__, 'sold_by_single' ) );
		add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'sold_by_loop' ), 20 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'item_facts' ), 25 );
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'changelog_tab' ) );
	}

	/**
	 * "Sold by" line on the single product page.
	 *
	 * @return void
	 */
	public static function sold_by_single() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$vendor_id = (int) get_post_field( 'post_author', $product->get_id() );
		if ( ! wpaec_is_vendor( $vendor_id ) ) {
			return;
		}

		printf(
			'<span class="wpaec-sold-by">%s <a href="%s">%s</a></span>',
			esc_html__( 'Sold by:', 'aec-market' ),
			esc_url( wpaec_get_store_url( $vendor_id ) ),
			esc_html( wpaec_get_store_name( $vendor_id ) )
		);
	}

	/**
	 * "By {store}" line under product titles in shop loops.
	 *
	 * @return void
	 */
	public static function sold_by_loop() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$vendor_id = (int) get_post_field( 'post_author', $product->get_id() );
		if ( ! wpaec_is_vendor( $vendor_id ) ) {
			return;
		}

		printf(
			'<div class="wpaec-loop-vendor"><a href="%s">%s</a></div>',
			esc_url( wpaec_get_store_url( $vendor_id ) ),
			esc_html( wpaec_get_store_name( $vendor_id ) )
		);
	}

	/**
	 * Render a vendor's public store: profile header plus their products.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function store_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'vendor' => 0 ), $atts, 'wpaec_vendor_store' );

		$vendor_id = absint( $atts['vendor'] );
		if ( ! $vendor_id && isset( $_GET['vendor'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only page.
			$vendor_id = absint( wp_unslash( $_GET['vendor'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( ! $vendor_id || ! wpaec_is_vendor( $vendor_id ) ) {
			return '<p>' . esc_html__( 'Vendor not found.', 'aec-market' ) . '</p>';
		}

		$paged = max( 1, absint( get_query_var( 'paged' ) ? get_query_var( 'paged' ) : get_query_var( 'page' ) ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'author'         => $vendor_id,
				'posts_per_page' => 12,
				'paged'          => $paged,
			)
		);

		ob_start();
		wpaec_get_template(
			'vendor-store.php',
			array(
				'vendor_id' => $vendor_id,
				'query'     => $query,
			)
		);
		wp_reset_postdata();

		return ob_get_clean();
	}

	/**
	 * [wpaec_vendors] — a directory of approved vendors, each linking to their store.
	 *
	 * @param array $atts Shortcode attributes: number (max vendors), columns.
	 * @return string
	 */
	public static function vendors_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'number' => 100 ), $atts, 'wpaec_vendors' );

		$vendors = get_users(
			array(
				'role'       => 'wpaec_vendor',
				'meta_key'   => '_wpaec_vendor_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'approved',             // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'    => 'display_name',
				'order'      => 'ASC',
				'number'     => absint( $atts['number'] ),
			)
		);

		if ( empty( $vendors ) ) {
			return '<div class="wpaec-vendors-empty"><p>' . esc_html__( 'No vendors yet — be the first to list on AEC Forge.', 'aec-market' ) . '</p>'
				. '<a class="button" href="' . esc_url( home_url( '/become-a-vendor/' ) ) . '">' . esc_html__( 'Become a vendor', 'aec-market' ) . '</a></div>';
		}

		ob_start();
		echo '<div class="wpaec-vendors row g-4">';
		foreach ( $vendors as $vendor ) {
			$vid   = (int) $vendor->ID;
			$name  = wpaec_get_store_name( $vid );
			$bio   = (string) get_user_meta( $vid, '_wpaec_store_bio', true );
			$url   = wpaec_get_store_url( $vid );
			$count = count(
				get_posts(
					array(
						'post_type'   => 'product',
						'author'      => $vid,
						'post_status' => 'publish',
						'fields'      => 'ids',
						'numberposts' => -1,
					)
				)
			);
			echo '<div class="col-md-6 col-lg-4">';
			echo '<div class="wpaec-vendor-card card h-100 border-0 shadow-sm p-4">';
			echo '<div class="wpaec-vendor-avatar">' . esc_html( strtoupper( mb_substr( $name, 0, 1 ) ) ) . '</div>';
			$since = wpaec_vendor_since( $vid );
			$sales = wpaec_vendor_sales_count( $vid );
			echo '<h3 class="h5 fw-bold mb-1 mt-3">' . esc_html( $name ) . '</h3>';
			echo '<p class="text-muted small mb-2">'
				. esc_html( sprintf( /* translators: %d listings */ _n( '%d listing', '%d listings', $count, 'aec-market' ), $count ) )
				. ( $sales > 0 ? ' · ' . esc_html( sprintf( /* translators: %d sales */ _n( '%d sale', '%d sales', $sales, 'aec-market' ), $sales ) ) : '' )
				. ( '' !== $since ? ' · ' . esc_html( sprintf( /* translators: %s year */ __( 'since %s', 'aec-market' ), $since ) ) : '' )
				. '</p>';
			if ( '' !== $bio ) {
				echo '<p class="mb-3">' . esc_html( wp_trim_words( $bio, 26 ) ) . '</p>';
			}
			if ( '' !== $url ) {
				echo '<a class="btn btn-outline-primary btn-sm mt-auto align-self-start" href="' . esc_url( $url ) . '">' . esc_html__( 'Visit store', 'aec-market' ) . ' &rarr;</a>';
			}
			echo '</div></div>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Item facts (version, last updated, live preview) on the single product page.
	 *
	 * @return void
	 */
	public static function item_facts() {
		global $product;
		if ( ! $product || 'program' !== wpaec_get_listing_type( $product->get_id() ) ) {
			return;
		}
		$pid     = $product->get_id();
		$version = (string) get_post_meta( $pid, '_wpaec_version', true );
		$demo    = (string) get_post_meta( $pid, '_wpaec_demo_url', true );
		$updated = get_the_modified_date( get_option( 'date_format' ), $pid );

		echo '<div class="wpaec-item-facts">';
		if ( '' !== $version ) {
			echo '<span class="wpaec-fact"><strong>' . esc_html__( 'Version', 'aec-market' ) . '</strong> ' . esc_html( $version ) . '</span>';
		}
		if ( $updated ) {
			echo '<span class="wpaec-fact"><strong>' . esc_html__( 'Updated', 'aec-market' ) . '</strong> ' . esc_html( $updated ) . '</span>';
		}
		if ( '' !== $demo ) {
			echo '<a class="wpaec-demo-btn" href="' . esc_url( $demo ) . '" target="_blank" rel="noopener nofollow">' . esc_html__( 'Live preview', 'aec-market' ) . ' &#8599;</a>';
		}
		echo '</div>';
	}

	/**
	 * Add a Changelog tab to the single product page when a changelog exists.
	 *
	 * @param array $tabs Product tabs.
	 * @return array
	 */
	public static function changelog_tab( $tabs ) {
		global $product;
		if ( $product && '' !== (string) get_post_meta( $product->get_id(), '_wpaec_changelog', true ) ) {
			$tabs['wpaec_changelog'] = array(
				'title'    => __( 'Changelog', 'aec-market' ),
				'priority' => 25,
				'callback' => array( __CLASS__, 'changelog_tab_content' ),
			);
		}
		return $tabs;
	}

	/**
	 * Render the Changelog tab content.
	 *
	 * @return void
	 */
	public static function changelog_tab_content() {
		global $product;
		$log = (string) get_post_meta( $product->get_id(), '_wpaec_changelog', true );
		echo '<pre class="wpaec-changelog">' . esc_html( $log ) . '</pre>';
	}
}
