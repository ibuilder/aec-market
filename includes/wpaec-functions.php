<?php
/**
 * Shared helper functions.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get a marketplace setting.
 *
 * @param string $key           Setting key.
 * @param mixed  $default_value Default value when the key is missing.
 * @return mixed
 */
function wpaec_get_setting( $key, $default_value = '' ) {
	$settings = get_option( 'wpaecmarket_settings', array() );
	return isset( $settings[ $key ] ) ? $settings[ $key ] : $default_value;
}

/**
 * Whether a user is an approved vendor.
 *
 * @param int $user_id User ID. Defaults to the current user.
 * @return bool
 */
function wpaec_is_vendor( $user_id = 0 ) {
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}
	$user = get_userdata( $user_id );
	return $user && $user->has_cap( 'wpaec_vendor' ) && 'approved' === wpaec_get_vendor_status( $user_id );
}

/**
 * Get a user's vendor application status.
 *
 * @param int $user_id User ID.
 * @return string One of '', 'pending', 'approved', 'rejected', 'suspended'.
 */
function wpaec_get_vendor_status( $user_id ) {
	return (string) get_user_meta( absint( $user_id ), '_wpaec_vendor_status', true );
}

/**
 * Get the public store name for a vendor.
 *
 * @param int $vendor_id Vendor user ID.
 * @return string
 */
function wpaec_get_store_name( $vendor_id ) {
	$name = get_user_meta( absint( $vendor_id ), '_wpaec_store_name', true );
	if ( '' === $name ) {
		$user = get_userdata( $vendor_id );
		$name = $user ? $user->display_name : '';
	}
	return (string) $name;
}

/**
 * Get the URL of a vendor's public store page.
 *
 * @param int $vendor_id Vendor user ID.
 * @return string
 */
function wpaec_get_store_url( $vendor_id ) {
	$page_id = absint( wpaec_get_setting( 'vendor_store_page' ) );
	if ( ! $page_id ) {
		return '';
	}
	return add_query_arg( 'vendor', absint( $vendor_id ), get_permalink( $page_id ) );
}

/**
 * Locate a plugin template, allowing theme overrides in `aec-market/`.
 *
 * @param string $template Relative template path, e.g. 'dashboard/overview.php'.
 * @param array  $args     Variables made available to the template.
 * @return void
 */
function wpaec_get_template( $template, $args = array() ) {
	$template = ltrim( $template, '/' );

	$theme_file = locate_template( array( trailingslashit( 'aec-market' ) . $template ) );
	$file       = $theme_file ? $theme_file : AEC_MARKET_PLUGIN_DIR . 'templates/' . $template;

	/**
	 * Filter the resolved template file path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file     Absolute file path.
	 * @param string $template Relative template name.
	 * @param array  $args     Template arguments.
	 */
	$file = apply_filters( 'wpaecmarket_template', $file, $template, $args );

	if ( ! file_exists( $file ) ) {
		return;
	}

	load_template( $file, false, $args );
}

/**
 * Get the listing type of a product.
 *
 * @param int $product_id Product ID.
 * @return string 'program' or 'service'.
 */
function wpaec_get_listing_type( $product_id ) {
	$type = get_post_meta( absint( $product_id ), '_wpaec_listing_type', true );
	return in_array( $type, array( 'program', 'service' ), true ) ? $type : 'program';
}

/**
 * Get the service tiers configured for a product.
 *
 * @param int $product_id Product ID.
 * @return array[] List of tiers: { name, price, description, delivery_days }.
 */
function wpaec_get_service_tiers( $product_id ) {
	$tiers = get_post_meta( absint( $product_id ), '_wpaec_service_tiers', true );
	if ( ! is_array( $tiers ) ) {
		return array();
	}

	$clean = array();
	foreach ( $tiers as $tier ) {
		if ( empty( $tier['name'] ) || ! isset( $tier['price'] ) || '' === $tier['price'] ) {
			continue;
		}
		$clean[] = array(
			'name'          => sanitize_text_field( $tier['name'] ),
			'price'         => wc_format_decimal( $tier['price'] ),
			'description'   => sanitize_textarea_field( isset( $tier['description'] ) ? $tier['description'] : '' ),
			'delivery_days' => absint( isset( $tier['delivery_days'] ) ? $tier['delivery_days'] : 0 ),
		);
	}

	return $clean;
}

/**
 * Get the commission rate (platform percentage) for a vendor.
 *
 * @param int $vendor_id Vendor user ID.
 * @return float Percentage between 0 and 100.
 */
function wpaec_get_commission_rate( $vendor_id ) {
	$rate = get_user_meta( absint( $vendor_id ), '_wpaec_commission_rate', true );
	if ( '' === $rate || null === $rate ) {
		$rate = wpaec_get_setting( 'commission_rate', 10 );
	}

	$rate = (float) $rate;
	$rate = max( 0, min( 100, $rate ) );

	/**
	 * Filter the commission rate applied to a vendor's sales.
	 *
	 * @since 1.0.0
	 *
	 * @param float $rate      Commission percentage.
	 * @param int   $vendor_id Vendor user ID.
	 */
	return (float) apply_filters( 'wpaecmarket_commission_rate', $rate, $vendor_id );
}

/**
 * Total number of sales across a vendor's published products.
 *
 * @param int $vendor_id Vendor user ID.
 * @return int
 */
function wpaec_vendor_sales_count( $vendor_id ) {
	$ids = get_posts(
		array(
			'post_type'   => 'product',
			'author'      => (int) $vendor_id,
			'post_status' => 'publish',
			'fields'      => 'ids',
			'numberposts' => -1,
		)
	);
	$sum = 0;
	foreach ( $ids as $pid ) {
		$sum += (int) get_post_meta( $pid, 'total_sales', true );
	}
	return $sum;
}

/**
 * The year a vendor joined.
 *
 * @param int $vendor_id Vendor user ID.
 * @return string
 */
function wpaec_vendor_since( $vendor_id ) {
	$user = get_userdata( (int) $vendor_id );
	return $user ? mysql2date( 'Y', $user->user_registered ) : '';
}
