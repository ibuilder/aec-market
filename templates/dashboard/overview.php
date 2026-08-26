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

// --- Getting-started onboarding state ---
$wpaec_store_name = (string) get_user_meta( $wpaec_vendor_id, '_wpaec_store_name', true );
$wpaec_store_bio  = (string) get_user_meta( $wpaec_vendor_id, '_wpaec_store_bio', true );
$wpaec_paypal     = (string) get_user_meta( $wpaec_vendor_id, '_wpaec_paypal_email', true );
$wpaec_stripe     = (string) get_user_meta( $wpaec_vendor_id, '_wpaec_stripe_id', true );

$wpaec_step_profile = ( '' !== $wpaec_store_name && '' !== $wpaec_store_bio );
$wpaec_step_payout  = ( '' !== $wpaec_paypal || '' !== $wpaec_stripe );
$wpaec_step_product = ( (int) $wpaec_product_counts > 0 );
$wpaec_steps_done   = (int) $wpaec_step_profile + (int) $wpaec_step_payout + (int) $wpaec_step_product;

$wpaec_settings_url = AEC_Market_Dashboard::tab_url( 'settings' );
$wpaec_edit_url     = AEC_Market_Dashboard::tab_url( 'edit' );
$wpaec_guide_url    = home_url( '/help/vendor-guide/' );
$wpaec_display_name = wp_get_current_user()->display_name;
?>
<h2><?php esc_html_e( 'Overview', 'aec-market' ); ?></h2>

<?php if ( $wpaec_steps_done < 3 ) : ?>
	<div class="wpaec-onboarding">
		<div class="wpaec-onboarding__head">
			<h3><?php
				/* translators: %s: vendor name */
				printf( esc_html__( 'Welcome, %s — let’s get your store live 🚀', 'aec-market' ), esc_html( '' !== $wpaec_store_name ? $wpaec_store_name : $wpaec_display_name ) );
			?></h3>
			<span class="wpaec-onboarding__count"><?php echo esc_html( sprintf( /* translators: 1: done 2: total */ __( '%1$d of %2$d done', 'aec-market' ), $wpaec_steps_done, 3 ) ); ?></span>
		</div>
		<div class="wpaec-progress"><span class="wpaec-progress__bar" style="width:<?php echo esc_attr( round( $wpaec_steps_done / 3 * 100 ) ); ?>%"></span></div>
		<ol class="wpaec-checklist">
			<li class="<?php echo $wpaec_step_profile ? 'is-done' : ''; ?>">
				<span class="wpaec-check"></span>
				<a href="<?php echo esc_url( $wpaec_settings_url ); ?>"><strong><?php esc_html_e( 'Set up your store profile', 'aec-market' ); ?></strong></a>
				<span class="wpaec-checklist__hint"><?php esc_html_e( 'Add your store name and a short bio so buyers know who you are.', 'aec-market' ); ?></span>
			</li>
			<li class="<?php echo $wpaec_step_payout ? 'is-done' : ''; ?>">
				<span class="wpaec-check"></span>
				<a href="<?php echo esc_url( $wpaec_settings_url ); ?>"><strong><?php esc_html_e( 'Add your payout details', 'aec-market' ); ?></strong></a>
				<span class="wpaec-checklist__hint"><?php esc_html_e( 'PayPal email or Stripe account ID — this is how you get paid.', 'aec-market' ); ?></span>
			</li>
			<li class="<?php echo $wpaec_step_product ? 'is-done' : ''; ?>">
				<span class="wpaec-check"></span>
				<a href="<?php echo esc_url( $wpaec_edit_url ); ?>"><strong><?php esc_html_e( 'Add your first listing', 'aec-market' ); ?></strong></a>
				<span class="wpaec-checklist__hint"><?php esc_html_e( 'A licensed program (digital download) or a tiered service. You keep 100% at launch.', 'aec-market' ); ?></span>
			</li>
		</ol>
		<p class="wpaec-onboarding__foot">
			<a class="button wpaec-button" href="<?php echo esc_url( 0 === (int) $wpaec_step_profile ? $wpaec_settings_url : ( ! $wpaec_step_payout ? $wpaec_settings_url : $wpaec_edit_url ) ); ?>"><?php esc_html_e( 'Continue setup', 'aec-market' ); ?></a>
			<a href="<?php echo esc_url( $wpaec_guide_url ); ?>"><?php esc_html_e( 'Read the Vendor Guide', 'aec-market' ); ?> &rarr;</a>
		</p>
	</div>
<?php endif; ?>

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
