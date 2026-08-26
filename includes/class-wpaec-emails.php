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

		$site_name     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$dashboard_url = get_permalink( absint( wpaec_get_setting( 'vendor_dashboard_page' ) ) );
		$guide_url     = home_url( '/help/vendor-guide/' );
		$name          = $user->display_name ? $user->display_name : $user->user_login;

		$body = sprintf(
			/* translators: 1: vendor name, 2: site name. */
			__( 'Hi %1$s,

Great news — your vendor account on %2$s is approved. You can start selling right away, and you keep 100%% of your sales at launch.

Three quick steps to get your store live:
  1. Set up your store profile (name and short bio)
  2. Add your payout details (PayPal or Stripe)
  3. Add your first listing — a program (digital download) or a tiered service

Your dashboard walks you through each step:
%3$s

New to selling on %2$s? The Vendor Guide covers licensing, uploads, and payouts:
%4$s

Welcome aboard,
The %2$s team', 'aec-market' ),
			$name,
			$site_name,
			$dashboard_url,
			$guide_url
		);

		wp_mail(
			$user->user_email,
			/* translators: %s: site name. */
			sprintf( __( '[%s] Your vendor account is approved 🎉', 'aec-market' ), $site_name ),
			$body
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
