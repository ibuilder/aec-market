<?php
/**
 * Service tier selector shown above the add-to-cart button.
 *
 * Override by copying to yourtheme/aec-market/service-tiers.php
 *
 * @package AEC_Market
 *
 * @var array $args { tiers: array[] }
 */

defined( 'ABSPATH' ) || exit;

$wpaec_tiers = isset( $args['tiers'] ) ? $args['tiers'] : array();
?>
<fieldset class="wpaec-tiers">
	<legend><?php esc_html_e( 'Choose a package', 'aec-market' ); ?></legend>

	<?php foreach ( $wpaec_tiers as $wpaec_i => $wpaec_tier ) : ?>
		<label class="wpaec-tier">
			<input type="radio" name="wpaec_tier" value="<?php echo esc_attr( $wpaec_i ); ?>" <?php checked( 0 === $wpaec_i ); ?> required />
			<span class="wpaec-tier__body">
				<span class="wpaec-tier__name"><?php echo esc_html( $wpaec_tier['name'] ); ?></span>
				<span class="wpaec-tier__price"><?php echo wp_kses_post( wc_price( $wpaec_tier['price'] ) ); ?></span>
				<?php if ( ! empty( $wpaec_tier['description'] ) ) : ?>
					<span class="wpaec-tier__desc"><?php echo esc_html( $wpaec_tier['description'] ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $wpaec_tier['delivery_days'] ) ) : ?>
					<span class="wpaec-tier__delivery">
						<?php
						/* translators: %d: number of days. */
						printf( esc_html( _n( 'Delivery in %d day', 'Delivery in %d days', (int) $wpaec_tier['delivery_days'], 'aec-market' ) ), (int) $wpaec_tier['delivery_days'] );
						?>
					</span>
				<?php endif; ?>
			</span>
		</label>
	<?php endforeach; ?>
</fieldset>
