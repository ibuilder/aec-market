<?php
/**
 * Dashboard: add/edit product tab.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

$wpaec_product_id = isset( $_GET['product'] ) ? absint( wp_unslash( $_GET['product'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$wpaec_product    = null;

if ( $wpaec_product_id ) {
	$wpaec_post = get_post( $wpaec_product_id );
	if ( ! $wpaec_post || 'product' !== $wpaec_post->post_type || get_current_user_id() !== (int) $wpaec_post->post_author ) {
		echo '<p>' . esc_html__( 'You are not allowed to edit this product.', 'aec-market' ) . '</p>';
		return;
	}
	$wpaec_product = wc_get_product( $wpaec_product_id );
}

$wpaec_type         = $wpaec_product_id ? wpaec_get_listing_type( $wpaec_product_id ) : 'program';
$wpaec_tiers        = $wpaec_product_id ? wpaec_get_service_tiers( $wpaec_product_id ) : array();
$wpaec_limit        = $wpaec_product_id ? max( 1, (int) get_post_meta( $wpaec_product_id, '_wpaec_activation_limit', true ) ) : (int) wpaec_get_setting( 'license_activation_limit', 1 );
$wpaec_terms        = get_terms(
	array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
	)
);
$wpaec_defaults     = array( __( 'Basic', 'aec-market' ), __( 'Standard', 'aec-market' ), __( 'Premium', 'aec-market' ) );
$wpaec_selected_cat = $wpaec_product_id ? (int) current( wp_get_object_terms( $wpaec_product_id, 'product_cat', array( 'fields' => 'ids' ) ) ) : 0;
?>
<h2><?php echo $wpaec_product_id ? esc_html__( 'Edit Listing', 'aec-market' ) : esc_html__( 'Add Listing', 'aec-market' ); ?></h2>

<p class="wpaec-form-intro"><?php
	printf(
		/* translators: %s: Vendor Guide URL. */
		wp_kses_post( __( 'List a <strong>Program</strong> (a digital download, optionally with license keys) or a <strong>Service</strong> (Basic / Standard / Premium packages). New listings go to review, then publish to your store — and you keep 100%% at launch. New here? <a href="%s">Read the Vendor Guide</a>.', 'aec-market' ) ),
		esc_url( home_url( '/help/vendor-guide/' ) )
	);
?></p>

<form method="post" enctype="multipart/form-data" class="wpaec-form">
	<?php wp_nonce_field( 'wpaec_save_product', 'wpaec_product_nonce' ); ?>
	<input type="hidden" name="wpaec_product_id" value="<?php echo esc_attr( $wpaec_product_id ); ?>" />

	<p class="wpaec-field">
		<label for="wpaec_title"><?php esc_html_e( 'Title', 'aec-market' ); ?> <span class="required">*</span></label>
		<input type="text" name="wpaec_title" id="wpaec_title" required value="<?php echo esc_attr( $wpaec_product_id ? get_the_title( $wpaec_product_id ) : '' ); ?>" />
	</p>

	<p class="wpaec-field">
		<label for="wpaec_description"><?php esc_html_e( 'Description', 'aec-market' ); ?></label>
		<textarea name="wpaec_description" id="wpaec_description" rows="8"><?php echo esc_textarea( $wpaec_product_id ? get_post_field( 'post_content', $wpaec_product_id ) : '' ); ?></textarea>
	</p>

	<p class="wpaec-field">
		<label for="wpaec_category"><?php esc_html_e( 'Category', 'aec-market' ); ?></label>
		<select name="wpaec_category" id="wpaec_category">
			<option value="0"><?php esc_html_e( '— Select —', 'aec-market' ); ?></option>
			<?php foreach ( $wpaec_terms as $wpaec_term ) : ?>
				<option value="<?php echo esc_attr( $wpaec_term->term_id ); ?>" <?php selected( $wpaec_selected_cat, $wpaec_term->term_id ); ?>>
					<?php echo esc_html( ( $wpaec_term->parent ? '— ' : '' ) . $wpaec_term->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>

	<?php $wpaec_compat_terms = class_exists( 'AEC_Market_Compat' ) ? AEC_Market_Compat::get_terms() : array(); ?>
	<?php if ( ! empty( $wpaec_compat_terms ) ) : ?>
		<p class="wpaec-field">
			<label><?php esc_html_e( 'Compatible with', 'aec-market' ); ?></label>
			<span class="wpaec-checks">
			<?php
			$wpaec_compat_selected = $wpaec_product_id ? wp_get_object_terms( $wpaec_product_id, AEC_Market_Compat::TAX, array( 'fields' => 'ids' ) ) : array();
			foreach ( $wpaec_compat_terms as $wpaec_ct ) :
				?>
				<label class="wpaec-check-inline"><input type="checkbox" name="wpaec_compat[]" value="<?php echo esc_attr( $wpaec_ct->term_id ); ?>" <?php checked( in_array( $wpaec_ct->term_id, (array) $wpaec_compat_selected, true ) ); ?> /> <?php echo esc_html( $wpaec_ct->name ); ?></label>
			<?php endforeach; ?>
			</span>
			<small class="wpaec-hint"><?php esc_html_e( 'Tag the software and versions your listing works with, so buyers can filter for it.', 'aec-market' ); ?></small>
		</p>
	<?php endif; ?>

	<p class="wpaec-field">
		<label for="wpaec_listing_type"><?php esc_html_e( 'Listing type', 'aec-market' ); ?></label>
		<select name="wpaec_listing_type" id="wpaec_listing_type">
			<option value="program" <?php selected( $wpaec_type, 'program' ); ?>>
				<?php esc_html_e( 'Program / Script (digital download)', 'aec-market' ); ?>
			</option>
			<option value="service" <?php selected( $wpaec_type, 'service' ); ?>>
				<?php esc_html_e( 'Service (tiered packages)', 'aec-market' ); ?>
			</option>
		</select>
		<small class="wpaec-hint"><?php esc_html_e( 'Program = a file buyers download (scripts, add-ins, templates, families). Service = work you deliver, priced in tiers.', 'aec-market' ); ?></small>
	</p>

	<div class="wpaec-type-fields" data-type="program">
		<p class="wpaec-field">
			<label for="wpaec_price"><?php esc_html_e( 'Price', 'aec-market' ); ?></label>
			<input type="number" step="0.01" min="0" name="wpaec_price" id="wpaec_price" value="<?php echo esc_attr( $wpaec_product ? $wpaec_product->get_regular_price() : '' ); ?>" />
			<small class="wpaec-hint"><?php esc_html_e( 'One-time price for the download.', 'aec-market' ); ?></small>
		</p>
		<p class="wpaec-field">
			<label for="wpaec_file"><?php esc_html_e( 'Deliverable file', 'aec-market' ); ?></label>
			<input type="file" name="wpaec_file" id="wpaec_file" />
			<small><?php echo esc_html( sprintf( /* translators: %s: allowed extensions. */ __( 'Allowed: %s', 'aec-market' ), (string) wpaec_get_setting( 'allowed_upload_types' ) ) ); ?></small>
		</p>
		<p class="wpaec-field">
			<label>
				<input type="checkbox" name="wpaec_license_enabled" value="yes" <?php checked( $wpaec_product_id && 'yes' === get_post_meta( $wpaec_product_id, '_wpaec_license_enabled', true ) ); ?> />
				<?php esc_html_e( 'Generate license keys on purchase', 'aec-market' ); ?>
			</label>
			<small class="wpaec-hint"><?php esc_html_e( 'Issues each buyer a unique key your tool can validate via our license API.', 'aec-market' ); ?></small>
		</p>
		<p class="wpaec-field">
			<label for="wpaec_activation_limit"><?php esc_html_e( 'Activation limit per license', 'aec-market' ); ?></label>
			<input type="number" min="1" name="wpaec_activation_limit" id="wpaec_activation_limit" value="<?php echo esc_attr( $wpaec_limit ); ?>" />
			<small class="wpaec-hint"><?php esc_html_e( 'How many machines/seats each key may activate.', 'aec-market' ); ?></small>
		</p>
		<p class="wpaec-field">
			<label for="wpaec_version"><?php esc_html_e( 'Version', 'aec-market' ); ?></label>
			<input type="text" name="wpaec_version" id="wpaec_version" value="<?php echo esc_attr( $wpaec_product_id ? get_post_meta( $wpaec_product_id, '_wpaec_version', true ) : '1.0.0' ); ?>" placeholder="1.0.0" />
			<small class="wpaec-hint"><?php esc_html_e( 'Buyers trust maintained tools — bump this each time you ship an update.', 'aec-market' ); ?></small>
		</p>
		<p class="wpaec-field">
			<label for="wpaec_demo_url"><?php esc_html_e( 'Live preview / demo URL (optional)', 'aec-market' ); ?></label>
			<input type="url" name="wpaec_demo_url" id="wpaec_demo_url" value="<?php echo esc_attr( $wpaec_product_id ? get_post_meta( $wpaec_product_id, '_wpaec_demo_url', true ) : '' ); ?>" placeholder="https://…" />
			<small class="wpaec-hint"><?php esc_html_e( 'A video, docs page, or interactive demo — adds a “Live preview” button to your listing.', 'aec-market' ); ?></small>
		</p>
		<p class="wpaec-field">
			<label for="wpaec_changelog"><?php esc_html_e( 'Changelog (optional)', 'aec-market' ); ?></label>
			<textarea name="wpaec_changelog" id="wpaec_changelog" rows="4" placeholder="1.0.0 — Initial release"><?php echo esc_textarea( $wpaec_product_id ? get_post_meta( $wpaec_product_id, '_wpaec_changelog', true ) : '' ); ?></textarea>
		</p>
	</div>

	<div class="wpaec-type-fields" data-type="service">
		<h3><?php esc_html_e( 'Service packages', 'aec-market' ); ?></h3>
		<p class="wpaec-hint"><?php esc_html_e( 'Fill only the tiers you want to offer. Each has its own price, scope, and delivery time. Leave a tier blank to hide it.', 'aec-market' ); ?></p>
		<?php for ( $wpaec_i = 0; $wpaec_i < 3; $wpaec_i++ ) : ?>
			<?php $wpaec_tier = isset( $wpaec_tiers[ $wpaec_i ] ) ? $wpaec_tiers[ $wpaec_i ] : array(); ?>
			<fieldset class="wpaec-tier-fields">
				<legend><?php echo esc_html( $wpaec_defaults[ $wpaec_i ] ); ?></legend>
				<p class="wpaec-field">
					<label><?php esc_html_e( 'Package name', 'aec-market' ); ?>
						<input type="text" name="wpaec_tier_name[]" value="<?php echo esc_attr( isset( $wpaec_tier['name'] ) ? $wpaec_tier['name'] : ( 0 === $wpaec_i ? $wpaec_defaults[0] : '' ) ); ?>" />
					</label>
				</p>
				<p class="wpaec-field">
					<label><?php esc_html_e( 'Price', 'aec-market' ); ?>
						<input type="number" step="0.01" min="0" name="wpaec_tier_price[]" value="<?php echo esc_attr( isset( $wpaec_tier['price'] ) ? $wpaec_tier['price'] : '' ); ?>" />
					</label>
				</p>
				<p class="wpaec-field">
					<label><?php esc_html_e( 'What is included', 'aec-market' ); ?>
						<textarea name="wpaec_tier_description[]" rows="3"><?php echo esc_textarea( isset( $wpaec_tier['description'] ) ? $wpaec_tier['description'] : '' ); ?></textarea>
					</label>
				</p>
				<p class="wpaec-field">
					<label><?php esc_html_e( 'Delivery (days)', 'aec-market' ); ?>
						<input type="number" min="0" name="wpaec_tier_delivery[]" value="<?php echo esc_attr( isset( $wpaec_tier['delivery_days'] ) ? $wpaec_tier['delivery_days'] : '' ); ?>" />
					</label>
				</p>
			</fieldset>
		<?php endfor; ?>
	</div>

	<p class="wpaec-field">
		<button type="submit" name="wpaec_save_product" value="1" class="button wpaec-button"><?php esc_html_e( 'Save listing', 'aec-market' ); ?></button>
	</p>
</form>
