<?php
/**
 * Dashboard: overview tab.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

$wpaec_vendor_id = get_current_user_id();
$wpaec_totals    = AEC_Market_Commissions::get_vendor_totals( $wpaec_vendor_id );

$wpaec_product_counts = count_user_posts( $wpaec_vendor_id, 'product', true );
?>
<h2><?php esc_html_e( 'Overview', 'aec-market' ); ?></h2>

<div class="wpaec-stats">
	<div class="wpaec-stat">
		<span class="wpaec-stat__value"><?php echo wp_kses_post( wc_price( $wpaec_totals ? $wpaec_totals->total_sales : 0 ) ); ?></span>
		<span class="wpaec-stat__label"><?php esc_html_e( 'Total sales', 'aec-market' ); ?></span>
	</div>
	<div class="wpaec-stat">
		<span class="wpaec-stat__value"><?php echo wp_kses_post( wc_price( $wpaec_totals ? $wpaec_totals->total_earning : 0 ) ); ?></span>
		<span class="wpaec-stat__label"><?php esc_html_e( 'Net earnings', 'aec-market' ); ?></span>
	</div>
	<div class="wpaec-stat">
		<span class="wpaec-stat__value"><?php echo wp_kses_post( wc_price( $wpaec_totals ? $wpaec_totals->pending_earning : 0 ) ); ?></span>
		<span class="wpaec-stat__label"><?php esc_html_e( 'Pending payout', 'aec-market' ); ?></span>
	</div>
	<div class="wpaec-stat">
		<span class="wpaec-stat__value"><?php echo esc_html( number_format_i18n( (int) $wpaec_product_counts ) ); ?></span>
		<span class="wpaec-stat__label"><?php esc_html_e( 'Listings', 'aec-market' ); ?></span>
	</div>
</div>

<p>
	<a class="button wpaec-button" href="<?php echo esc_url( AEC_Market_Dashboard::tab_url( 'edit' ) ); ?>"><?php esc_html_e( 'Add a new listing', 'aec-market' ); ?></a>
	<a class="button" href="<?php echo esc_url( wpaec_get_store_url( $wpaec_vendor_id ) ); ?>"><?php esc_html_e( 'View my public store', 'aec-market' ); ?></a>
</p>
