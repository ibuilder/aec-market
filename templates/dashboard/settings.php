<?php
/**
 * Dashboard: store settings tab.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

$wpaec_user_id = get_current_user_id();
?>
<h2><?php esc_html_e( 'Store Settings', 'aec-market' ); ?></h2>

<form method="post" class="wpaec-form">
	<?php wp_nonce_field( 'wpaec_save_settings', 'wpaec_settings_nonce' ); ?>

	<p class="wpaec-field">
		<label for="wpaec_store_name"><?php esc_html_e( 'Store name', 'aec-market' ); ?></label>
		<input type="text" name="wpaec_store_name" id="wpaec_store_name" value="<?php echo esc_attr( get_user_meta( $wpaec_user_id, '_wpaec_store_name', true ) ); ?>" />
	</p>

	<p class="wpaec-field">
		<label for="wpaec_store_bio"><?php esc_html_e( 'Store bio', 'aec-market' ); ?></label>
		<textarea name="wpaec_store_bio" id="wpaec_store_bio" rows="5"><?php echo esc_textarea( get_user_meta( $wpaec_user_id, '_wpaec_store_bio', true ) ); ?></textarea>
	</p>

	<h3><?php esc_html_e( 'Payout details', 'aec-market' ); ?></h3>

	<p class="wpaec-field">
		<label for="wpaec_paypal_email"><?php esc_html_e( 'PayPal email', 'aec-market' ); ?></label>
		<input type="email" name="wpaec_paypal_email" id="wpaec_paypal_email" value="<?php echo esc_attr( get_user_meta( $wpaec_user_id, '_wpaec_paypal_email', true ) ); ?>" />
	</p>

	<p class="wpaec-field">
		<label for="wpaec_stripe_id"><?php esc_html_e( 'Stripe account ID (acct_…)', 'aec-market' ); ?></label>
		<input type="text" name="wpaec_stripe_id" id="wpaec_stripe_id" value="<?php echo esc_attr( get_user_meta( $wpaec_user_id, '_wpaec_stripe_id', true ) ); ?>" />
		<small><?php esc_html_e( 'Used by the marketplace team when routing payouts via Stripe Connect.', 'aec-market' ); ?></small>
	</p>

	<p class="wpaec-field">
		<button type="submit" name="wpaec_save_settings" value="1" class="button wpaec-button"><?php esc_html_e( 'Save settings', 'aec-market' ); ?></button>
	</p>
</form>
