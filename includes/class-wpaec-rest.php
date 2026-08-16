<?php
/**
 * REST API endpoints for license activation and validation.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the wpaec/v1 REST namespace.
 */
class AEC_Market_Rest {

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register license endpoints. These are public by design: the license key
	 * itself is the credential, and no personal data is returned.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$key_args = array(
			'license_key' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'instance'    => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);

		register_rest_route(
			'wpaec/v1',
			'/license/activate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'activate' ),
				'permission_callback' => '__return_true',
				'args'                => $key_args,
			)
		);

		register_rest_route(
			'wpaec/v1',
			'/license/deactivate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'deactivate' ),
				'permission_callback' => '__return_true',
				'args'                => $key_args,
			)
		);

		register_rest_route(
			'wpaec/v1',
			'/license/validate',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'validate' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'license_key' => $key_args['license_key'],
				),
			)
		);
	}

	/**
	 * POST /wpaec/v1/license/activate
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function activate( WP_REST_Request $request ) {
		$result = AEC_Market_Licenses::activate( $request['license_key'], $request['instance'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'activated' => true,
				'message'   => __( 'License activated.', 'aec-market' ),
			)
		);
	}

	/**
	 * POST /wpaec/v1/license/deactivate
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function deactivate( WP_REST_Request $request ) {
		$result = AEC_Market_Licenses::deactivate( $request['license_key'], $request['instance'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'deactivated' => true,
				'message'     => __( 'License deactivated.', 'aec-market' ),
			)
		);
	}

	/**
	 * GET /wpaec/v1/license/validate
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function validate( WP_REST_Request $request ) {
		$license = AEC_Market_Licenses::get_by_key( $request['license_key'] );

		if ( ! $license ) {
			return new WP_Error( 'wpaec_invalid_license', __( 'License key not found.', 'aec-market' ), array( 'status' => 404 ) );
		}

		$expired = $license->expires_at && strtotime( $license->expires_at ) < time();

		return rest_ensure_response(
			array(
				'valid'            => 'active' === $license->status && ! $expired,
				'status'           => $expired ? 'expired' : $license->status,
				'product_id'       => (int) $license->product_id,
				'activation_limit' => (int) $license->activation_limit,
				'activations'      => AEC_Market_Licenses::count_activations( $license->id ),
				'expires_at'       => $license->expires_at,
			)
		);
	}
}
