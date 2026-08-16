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
		add_action( 'woocommerce_product_meta_start', array( __CLASS__, 'sold_by_single' ) );
		add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'sold_by_loop' ), 20 );
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
}
