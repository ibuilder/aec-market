<?php
/**
 * Vendor registration and lifecycle management.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles vendor registration, approval and profile data.
 */
class AEC_Market_Vendor {

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'wpaec_vendor_registration', array( __CLASS__, 'registration_shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_registration' ) );
	}

	/**
	 * Render the vendor registration / application form.
	 *
	 * @return string
	 */
	public static function registration_shortcode() {
		ob_start();

		if ( is_user_logged_in() ) {
			$status = wpaec_get_vendor_status( get_current_user_id() );

			if ( 'approved' === $status ) {
				$dashboard = get_permalink( absint( wpaec_get_setting( 'vendor_dashboard_page' ) ) );
				printf(
					'<p>%s <a href="%s">%s</a></p>',
					esc_html__( 'You are already an approved vendor.', 'aec-market' ),
					esc_url( $dashboard ),
					esc_html__( 'Go to your dashboard', 'aec-market' )
				);
				return ob_get_clean();
			}

			if ( 'pending' === $status ) {
				printf( '<p>%s</p>', esc_html__( 'Your vendor application is awaiting review. We will email you once it has been processed.', 'aec-market' ) );
				return ob_get_clean();
			}

			if ( 'rejected' === $status || 'suspended' === $status ) {
				printf( '<p>%s</p>', esc_html__( 'Your vendor account is not active. Please contact the marketplace team.', 'aec-market' ) );
				return ob_get_clean();
			}
		}

		wpaec_get_template(
			'vendor-registration.php',
			array(
				'notice' => self::get_notice(),
			)
		);

		return ob_get_clean();
	}

	/**
	 * Handle the registration form submission.
	 *
	 * @return void
	 */
	public static function handle_registration() {
		if ( ! isset( $_POST['wpaec_register_vendor'] ) ) {
			return;
		}

		if ( ! isset( $_POST['wpaec_register_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wpaec_register_nonce'] ) ), 'wpaec_register_vendor' ) ) {
			self::redirect_with_notice( 'error', __( 'Security check failed. Please try again.', 'aec-market' ) );
		}

		$store_name = isset( $_POST['wpaec_store_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wpaec_store_name'] ) ) : '';
		$store_bio  = isset( $_POST['wpaec_store_bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wpaec_store_bio'] ) ) : '';

		if ( '' === $store_name ) {
			self::redirect_with_notice( 'error', __( 'Please provide a store name.', 'aec-market' ) );
		}

		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
		} else {
			$email    = isset( $_POST['wpaec_email'] ) ? sanitize_email( wp_unslash( $_POST['wpaec_email'] ) ) : '';
			$username = isset( $_POST['wpaec_username'] ) ? sanitize_user( wp_unslash( $_POST['wpaec_username'] ) ) : '';
			$password = isset( $_POST['wpaec_password'] ) ? (string) wp_unslash( $_POST['wpaec_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered.

			if ( ! is_email( $email ) ) {
				self::redirect_with_notice( 'error', __( 'Please provide a valid email address.', 'aec-market' ) );
			}
			if ( '' === $username || '' === $password ) {
				self::redirect_with_notice( 'error', __( 'Please choose a username and password.', 'aec-market' ) );
			}
			if ( username_exists( $username ) || email_exists( $email ) ) {
				self::redirect_with_notice( 'error', __( 'An account with that username or email already exists. Please log in first.', 'aec-market' ) );
			}

			$user_id = wp_create_user( $username, $password, $email );
			if ( is_wp_error( $user_id ) ) {
				self::redirect_with_notice( 'error', $user_id->get_error_message() );
			}

			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id );
		}

		update_user_meta( $user_id, '_wpaec_store_name', $store_name );
		update_user_meta( $user_id, '_wpaec_store_bio', $store_bio );

		if ( 'auto' === wpaec_get_setting( 'vendor_approval', 'manual' ) ) {
			self::approve( $user_id );
			self::redirect_with_notice( 'success', __( 'Your vendor account is ready. Welcome aboard!', 'aec-market' ) );
		}

		update_user_meta( $user_id, '_wpaec_vendor_status', 'pending' );

		/**
		 * Fires when a new vendor application is submitted.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id Applicant user ID.
		 */
		do_action( 'wpaecmarket_vendor_applied', $user_id );

		self::redirect_with_notice( 'success', __( 'Thanks! Your application has been received and is awaiting review.', 'aec-market' ) );
	}

	/**
	 * Approve a vendor: assign the role and mark as approved.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function approve( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		if ( ! $user ) {
			return;
		}

		$user->add_role( 'wpaec_vendor' );
		update_user_meta( $user_id, '_wpaec_vendor_status', 'approved' );

		/**
		 * Fires when a vendor is approved.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id Vendor user ID.
		 */
		do_action( 'wpaecmarket_vendor_approved', $user_id );
	}

	/**
	 * Reject or suspend a vendor.
	 *
	 * @param int    $user_id User ID.
	 * @param string $status  'rejected' or 'suspended'.
	 * @return void
	 */
	public static function deactivate_vendor( $user_id, $status = 'suspended' ) {
		$user = get_userdata( absint( $user_id ) );
		if ( ! $user ) {
			return;
		}

		$status = in_array( $status, array( 'rejected', 'suspended' ), true ) ? $status : 'suspended';

		$user->remove_role( 'wpaec_vendor' );
		update_user_meta( $user_id, '_wpaec_vendor_status', $status );

		/**
		 * Fires when a vendor is rejected or suspended.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $user_id Vendor user ID.
		 * @param string $status  New status.
		 */
		do_action( 'wpaecmarket_vendor_deactivated', $user_id, $status );
	}

	/**
	 * Redirect back to the referring page with a one-time notice.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message text.
	 * @return void
	 */
	private static function redirect_with_notice( $type, $message ) {
		set_transient(
			'wpaec_notice_' . self::notice_key(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS * 5
		);
		wp_safe_redirect( remove_query_arg( array( '_wpnonce' ), wp_get_referer() ? wp_get_referer() : home_url() ) );
		exit;
	}

	/**
	 * Fetch and clear the one-time notice for the current visitor.
	 *
	 * @return array|null { type, message } or null.
	 */
	public static function get_notice() {
		$key    = 'wpaec_notice_' . self::notice_key();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			return $notice;
		}
		return null;
	}

	/**
	 * A per-visitor key for transient notices.
	 *
	 * @return string
	 */
	private static function notice_key() {
		if ( is_user_logged_in() ) {
			return 'u' . get_current_user_id();
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return md5( $ip . wp_salt( 'nonce' ) );
	}
}
