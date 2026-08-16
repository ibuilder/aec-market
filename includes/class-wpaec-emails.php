<?php
/**
 * Transactional email notifications.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends marketplace notification emails via wp_mail().
 */
class AEC_Market_Emails {

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wpaecmarket_vendor_applied', array( __CLASS__, 'vendor_applied' ) );
		add_action( 'wpaecmarket_vendor_approved', array( __CLASS__, 'vendor_approved' ) );
		add_action( 'wpaecmarket_commission_recorded', array( __CLASS__, 'new_sale' ), 10, 4 );
	}

	/**
	 * Notify the site admin about a new vendor application.
	 *
	 * @param int $user_id Applicant user ID.
	 * @return void
	 */
	public static function vendor_applied( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		wp_mail(
			get_option( 'admin_email' ),
			/* translators: %s: site name. */
			sprintf( __( '[%s] New vendor application', 'aec-market' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf(
				/* translators: 1: store name, 2: user email, 3: admin URL. */
				__( 'A new vendor application was submitted by %1$s (%2$s). Review it here: %3$s', 'aec-market' ),
				wpaec_get_store_name( $user_id ),
				$user->user_email,
				admin_url( 'admin.php?page=wpaec-vendors' )
			)
		);
	}

	/**
	 * Notify a vendor that their application was approved.
	 *
	 * @param int $user_id Vendor user ID.
	 * @return void
	 */
	public static function vendor_approved( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		wp_mail(
			$user->user_email,
			/* translators: %s: site name. */
			sprintf( __( '[%s] Your vendor account is approved', 'aec-market' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf(
				/* translators: %s: dashboard URL. */
				__( 'Congratulations! Your vendor account has been approved. Start listing your programs and services here: %s', 'aec-market' ),
				get_permalink( absint( wpaec_get_setting( 'vendor_dashboard_page' ) ) )
			)
		);
	}

	/**
	 * Notify a vendor about a new sale.
	 *
	 * @param int   $commission_id Commission row ID.
	 * @param int   $vendor_id     Vendor user ID.
	 * @param float $earning       Net earning.
	 * @param int   $order_id      Order ID.
	 * @return void
	 */
	public static function new_sale( $commission_id, $vendor_id, $earning, $order_id ) {
		$user = get_userdata( $vendor_id );
		if ( ! $user ) {
			return;
		}

		wp_mail(
			$user->user_email,
			/* translators: %s: site name. */
			sprintf( __( '[%s] You made a sale!', 'aec-market' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf(
				/* translators: 1: order number, 2: formatted earning amount, 3: dashboard URL. */
				__( 'Order #%1$s just completed. Your net earning: %2$s. See details in your dashboard: %3$s', 'aec-market' ),
				$order_id,
				wp_strip_all_tags( wc_price( $earning ) ),
				AEC_Market_Dashboard::tab_url( 'earnings' )
			)
		);
	}
}
