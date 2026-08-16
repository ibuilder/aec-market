<?php
/**
 * Vendor registration form template.
 *
 * Override by copying to yourtheme/aec-market/vendor-registration.php
 *
 * @package AEC_Market
 *
 * @var array $args { notice: ?array }
 */

defined( 'ABSPATH' ) || exit;

$wpaec_notice = isset( $args['notice'] ) ? $args['notice'] : null;
?>
<div class="wpaec-form-wrap">
	<?php if ( $wpaec_notice ) : ?>
		<div class="wpaec-notice wpaec-notice--<?php echo esc_attr( $wpaec_notice['type'] ); ?>">
			<?php echo esc_html( $wpaec_notice['message'] ); ?>
		</div>
	<?php endif; ?>

	<form method="post" class="wpaec-form">
		<?php wp_nonce_field( 'wpaec_register_vendor', 'wpaec_register_nonce' ); ?>

		<?php if ( ! is_user_logged_in() ) : ?>
			<p class="wpaec-field">
				<label for="wpaec_username"><?php esc_html_e( 'Username', 'aec-market' ); ?> <span class="required">*</span></label>
				<input type="text" name="wpaec_username" id="wpaec_username" required autocomplete="username" />
			</p>
			<p class="wpaec-field">
				<label for="wpaec_email"><?php esc_html_e( 'Email address', 'aec-market' ); ?> <span class="required">*</span></label>
				<input type="email" name="wpaec_email" id="wpaec_email" required autocomplete="email" />
			</p>
			<p class="wpaec-field">
				<label for="wpaec_password"><?php esc_html_e( 'Password', 'aec-market' ); ?> <span class="required">*</span></label>
				<input type="password" name="wpaec_password" id="wpaec_password" required autocomplete="new-password" />
			</p>
		<?php endif; ?>

		<p class="wpaec-field">
			<label for="wpaec_store_name"><?php esc_html_e( 'Store name', 'aec-market' ); ?> <span class="required">*</span></label>
			<input type="text" name="wpaec_store_name" id="wpaec_store_name" required />
		</p>
		<p class="wpaec-field">
			<label for="wpaec_store_bio"><?php esc_html_e( 'Tell us about your skills (BIM, Excel, AI tools…)', 'aec-market' ); ?></label>
			<textarea name="wpaec_store_bio" id="wpaec_store_bio" rows="5"></textarea>
		</p>

		<p class="wpaec-field">
			<button type="submit" name="wpaec_register_vendor" value="1" class="button wpaec-button">
				<?php esc_html_e( 'Apply to become a vendor', 'aec-market' ); ?>
			</button>
		</p>
	</form>
</div>
