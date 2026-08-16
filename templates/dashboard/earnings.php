<?php
/**
 * Dashboard: earnings tab.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

$wpaec_vendor_id = get_current_user_id();
$wpaec_totals    = AEC_Market_Commissions::get_vendor_totals( $wpaec_vendor_id );
$wpaec_rate      = wpaec_get_commission_rate( $wpaec_vendor_id );
?>
<h2><?php esc_html_e( 'Earnings', 'aec-market' ); ?></h2>

<div class="wpaec-stats">
	<div class="wpaec-stat">
		<span class="wpaec-stat__value"><?php echo wp_kses_post( wc_price( $wpaec_totals ? $wpaec_totals->pending_earning : 0 ) ); ?></span>
		<span class="wpaec-stat__label"><?php esc_html_e( 'Pending payout', 'aec-market' ); ?></span>
	</div>
	<div class="wpaec-stat">
		<span class="wpaec-stat__value"><?php echo wp_kses_post( wc_price( $wpaec_totals ? $wpaec_totals->paid_earning : 0 ) ); ?></span>
		<span class="wpaec-stat__label"><?php esc_html_e( 'Paid out', 'aec-market' ); ?></span>
	</div>
	<div class="wpaec-stat">
		<span class="wpaec-stat__value"><?php echo esc_html( $wpaec_rate . '%' ); ?></span>
		<span class="wpaec-stat__label"><?php esc_html_e( 'Platform commission', 'aec-market' ); ?></span>
	</div>
</div>

<p class="description">
	<?php esc_html_e( 'Payouts are processed by the marketplace team to the payout details saved in your Store Settings. Completed sales appear as "pending" until they are paid out.', 'aec-market' ); ?>
</p>
