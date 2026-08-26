<?php
/**
 * License key generation, activation and validation.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generates license keys for licensed products and tracks activations.
 */
class AEC_Market_Licenses {

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'generate_for_order' ), 20 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_order_licenses' ) );
	}

	/**
	 * Table name helper.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpaec_licenses';
	}

	/**
	 * Activations table name helper.
	 *
	 * @return string
	 */
	public static function activations_table() {
		global $wpdb;
		return $wpdb->prefix . 'wpaec_license_activations';
	}

	/**
	 * Generate license keys for licensed products on a completed order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function generate_for_order( $order_id ) {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_product_id();

			if ( 'yes' !== get_post_meta( $product_id, '_wpaec_license_enabled', true ) ) {
				continue;
			}

			// Idempotency: count existing keys for this order/product pair.
			$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d AND product_id = %d', self::table(), $order_id, $product_id )
			);

			$needed = max( 0, $item->get_quantity() - (int) $existing );

			// Extended license grants a firm-wide activation allowance.
			$limit = max( 1, (int) get_post_meta( $product_id, '_wpaec_activation_limit', true ) );
			if ( 'extended' === $item->get_meta( '_wpaec_license_type' ) && class_exists( 'AEC_Market_License_Tiers' ) ) {
				$limit = AEC_Market_License_Tiers::EXTENDED_ACTIVATIONS;
			}

			for ( $i = 0; $i < $needed; $i++ ) {
				self::create(
					array(
						'order_id'         => $order_id,
						'product_id'       => $product_id,
						'user_id'          => $order->get_customer_id(),
						'vendor_id'        => (int) get_post_field( 'post_author', $product_id ),
						'activation_limit' => $limit,
					)
				);
			}
		}
	}

	/**
	 * Create a license row with a unique key.
	 *
	 * @param array $data { order_id, product_id, user_id, vendor_id, activation_limit }.
	 * @return string The generated license key.
	 */
	public static function create( $data ) {
		global $wpdb;

		do {
			$key    = self::generate_key();
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE license_key = %s', self::table(), $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} while ( $exists );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'license_key'      => $key,
				'order_id'         => absint( $data['order_id'] ),
				'product_id'       => absint( $data['product_id'] ),
				'user_id'          => absint( $data['user_id'] ),
				'vendor_id'        => absint( $data['vendor_id'] ),
				'activation_limit' => absint( $data['activation_limit'] ),
				'status'           => 'active',
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		/**
		 * Fires after a license key is generated.
		 *
		 * @since 1.0.0
		 *
		 * @param string $key  License key.
		 * @param array  $data License data.
		 */
		do_action( 'wpaecmarket_license_created', $key, $data );

		return $key;
	}

	/**
	 * Generate a random key in the form XXXX-XXXX-XXXX-XXXX.
	 *
	 * @return string
	 */
	private static function generate_key() {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$segments = array();

		for ( $s = 0; $s < 4; $s++ ) {
			$segment = '';
			for ( $c = 0; $c < 4; $c++ ) {
				$segment .= $alphabet[ wp_rand( 0, strlen( $alphabet ) - 1 ) ];
			}
			$segments[] = $segment;
		}

		return implode( '-', $segments );
	}

	/**
	 * Look up a license row by key.
	 *
	 * @param string $key License key.
	 * @return object|null
	 */
	public static function get_by_key( $key ) {
		global $wpdb;

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE license_key = %s', self::table(), strtoupper( sanitize_text_field( $key ) ) )
		);
	}

	/**
	 * Count active activations for a license.
	 *
	 * @param int $license_id License row ID.
	 * @return int
	 */
	public static function count_activations( $license_id ) {
		global $wpdb;

		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE license_id = %d AND deactivated_at IS NULL', self::activations_table(), absint( $license_id ) )
		);

		return (int) $count;
	}

	/**
	 * Activate a license on an instance (site URL, machine ID, etc.).
	 *
	 * @param string $key      License key.
	 * @param string $instance Instance identifier.
	 * @return true|WP_Error
	 */
	public static function activate( $key, $instance ) {
		global $wpdb;

		$license = self::get_by_key( $key );
		if ( ! $license ) {
			return new WP_Error( 'wpaec_invalid_license', __( 'License key not found.', 'aec-market' ), array( 'status' => 404 ) );
		}
		if ( 'active' !== $license->status ) {
			return new WP_Error( 'wpaec_license_inactive', __( 'This license is not active.', 'aec-market' ), array( 'status' => 403 ) );
		}
		if ( $license->expires_at && strtotime( $license->expires_at ) < time() ) {
			return new WP_Error( 'wpaec_license_expired', __( 'This license has expired.', 'aec-market' ), array( 'status' => 403 ) );
		}

		$instance = substr( sanitize_text_field( $instance ), 0, 191 );
		if ( '' === $instance ) {
			return new WP_Error( 'wpaec_missing_instance', __( 'An instance identifier is required.', 'aec-market' ), array( 'status' => 400 ) );
		}

		// Already active on this instance: succeed idempotently.
		$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT id FROM %i WHERE license_id = %d AND instance = %s AND deactivated_at IS NULL', self::activations_table(), $license->id, $instance )
		);
		if ( $existing ) {
			return true;
		}

		if ( self::count_activations( $license->id ) >= (int) $license->activation_limit ) {
			return new WP_Error( 'wpaec_activation_limit', __( 'Activation limit reached for this license.', 'aec-market' ), array( 'status' => 403 ) );
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::activations_table(),
			array(
				'license_id'   => $license->id,
				'instance'     => $instance,
				'activated_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s' )
		);

		return true;
	}

	/**
	 * Deactivate a license on an instance.
	 *
	 * @param string $key      License key.
	 * @param string $instance Instance identifier.
	 * @return true|WP_Error
	 */
	public static function deactivate( $key, $instance ) {
		global $wpdb;

		$license = self::get_by_key( $key );
		if ( ! $license ) {
			return new WP_Error( 'wpaec_invalid_license', __( 'License key not found.', 'aec-market' ), array( 'status' => 404 ) );
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::activations_table(),
			array( 'deactivated_at' => current_time( 'mysql', true ) ),
			array(
				'license_id'     => $license->id,
				'instance'       => substr( sanitize_text_field( $instance ), 0, 191 ),
				'deactivated_at' => null,
			),
			array( '%s' ),
			array( '%d', '%s', null )
		);

		return true;
	}

	/**
	 * List a customer's licenses on the order confirmation / view-order screen.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public static function render_order_licenses( $order ) {
		global $wpdb;

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$licenses = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', self::table(), $order->get_id() )
		);

		if ( empty( $licenses ) ) {
			return;
		}

		echo '<section class="wpaec-order-licenses"><h2>' . esc_html__( 'License keys', 'aec-market' ) . '</h2><table class="woocommerce-table shop_table"><thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'aec-market' ) . '</th><th>' . esc_html__( 'License key', 'aec-market' ) . '</th><th>' . esc_html__( 'Activations', 'aec-market' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $licenses as $license ) {
			printf(
				'<tr><td>%s</td><td><code>%s</code></td><td>%d / %d</td></tr>',
				esc_html( get_the_title( (int) $license->product_id ) ),
				esc_html( $license->license_key ),
				(int) self::count_activations( $license->id ),
				(int) $license->activation_limit
			);
		}

		echo '</tbody></table></section>';
	}
}
