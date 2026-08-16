<?php
/**
 * Vendor dashboard wrapper: navigation + active tab.
 *
 * Override by copying to yourtheme/aec-market/dashboard/dashboard.php
 *
 * @package AEC_Market
 *
 * @var array $args { tab: string, tabs: array, notice: ?array }
 */

defined( 'ABSPATH' ) || exit;

$wpaec_tab    = $args['tab'];
$wpaec_tabs   = $args['tabs'];
$wpaec_notice = isset( $args['notice'] ) ? $args['notice'] : null;
?>
<div class="wpaec-dashboard">
	<nav class="wpaec-dashboard__nav" aria-label="<?php esc_attr_e( 'Vendor dashboard', 'aec-market' ); ?>">
		<ul>
			<?php foreach ( $wpaec_tabs as $wpaec_slug => $wpaec_label ) : ?>
				<li class="<?php echo esc_attr( $wpaec_slug === $wpaec_tab ? 'is-active' : '' ); ?>">
					<a href="<?php echo esc_url( AEC_Market_Dashboard::tab_url( $wpaec_slug ) ); ?>"><?php echo esc_html( $wpaec_label ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>

	<div class="wpaec-dashboard__content">
		<?php if ( $wpaec_notice ) : ?>
			<div class="wpaec-notice wpaec-notice--<?php echo esc_attr( $wpaec_notice['type'] ); ?>">
				<?php echo esc_html( $wpaec_notice['message'] ); ?>
			</div>
		<?php endif; ?>

		<?php wpaec_get_template( 'dashboard/' . $wpaec_tab . '.php' ); ?>
	</div>
</div>
