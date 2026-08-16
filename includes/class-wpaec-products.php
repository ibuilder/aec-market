<?php
/**
 * Listing types (programs vs services) and tiered service packages.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product-level marketplace behaviour.
 */
class AEC_Market_Products {

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function init() {
		// Admin product metabox.
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_meta_box' ), 10, 2 );

		// Service tier selection on the product page.
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_tier_selector' ) );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_tier_price' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_item_tier' ), 10, 3 );

		// Storefront filtering by listing type (?listing_type=program|service).
		add_filter( 'woocommerce_product_query_meta_query', array( __CLASS__, 'filter_by_listing_type' ) );
	}

	/**
	 * Register the marketplace metabox on the product edit screen.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		add_meta_box(
			'wpaec_listing',
			__( 'AEC Market Listing', 'aec-market' ),
			array( __CLASS__, 'render_meta_box' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Render the listing-type metabox.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		$type  = wpaec_get_listing_type( $post->ID );
		$limit = get_post_meta( $post->ID, '_wpaec_activation_limit', true );
		if ( '' === $limit ) {
			$limit = wpaec_get_setting( 'license_activation_limit', 1 );
		}
		wp_nonce_field( 'wpaec_save_listing', 'wpaec_listing_nonce' );
		?>
		<p>
			<label for="wpaec_listing_type"><strong><?php esc_html_e( 'Listing type', 'aec-market' ); ?></strong></label><br />
			<select name="wpaec_listing_type" id="wpaec_listing_type" style="width:100%">
				<option value="program" <?php selected( $type, 'program' ); ?>>
					<?php esc_html_e( 'Program / Script (digital download)', 'aec-market' ); ?>
				</option>
				<option value="service" <?php selected( $type, 'service' ); ?>>
					<?php esc_html_e( 'Service (tiered packages)', 'aec-market' ); ?>
				</option>
			</select>
		</p>
		<p>
			<label>
				<input type="checkbox" name="wpaec_license_enabled" value="yes" <?php checked( get_post_meta( $post->ID, '_wpaec_license_enabled', true ), 'yes' ); ?> />
				<?php esc_html_e( 'Generate license keys on purchase', 'aec-market' ); ?>
			</label>
		</p>
		<p>
			<label for="wpaec_activation_limit"><?php esc_html_e( 'Activation limit per license', 'aec-market' ); ?></label>
			<input type="number" min="1" name="wpaec_activation_limit" id="wpaec_activation_limit" style="width:100%"
				value="<?php echo esc_attr( $limit ); ?>" />
		</p>
		<?php
	}

	/**
	 * Persist the listing metabox.
	 *
	 * @param int     $post_id Product ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save_meta_box( $post_id, $post ) {
		if ( 'product' !== $post->post_type ) {
			return;
		}
		if ( ! isset( $_POST['wpaec_listing_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wpaec_listing_nonce'] ) ), 'wpaec_save_listing' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$type = isset( $_POST['wpaec_listing_type'] ) ? sanitize_key( wp_unslash( $_POST['wpaec_listing_type'] ) ) : 'program';
		update_post_meta( $post_id, '_wpaec_listing_type', in_array( $type, array( 'program', 'service' ), true ) ? $type : 'program' );

		update_post_meta( $post_id, '_wpaec_license_enabled', isset( $_POST['wpaec_license_enabled'] ) ? 'yes' : 'no' );

		$limit = isset( $_POST['wpaec_activation_limit'] ) ? max( 1, absint( wp_unslash( $_POST['wpaec_activation_limit'] ) ) ) : 1;
		update_post_meta( $post_id, '_wpaec_activation_limit', $limit );
	}

	/**
	 * Render the Basic/Standard/Premium selector on service products.
	 *
	 * @return void
	 */
	public static function render_tier_selector() {
		global $product;

		if ( ! $product instanceof WC_Product || 'service' !== wpaec_get_listing_type( $product->get_id() ) ) {
			return;
		}

		$tiers = wpaec_get_service_tiers( $product->get_id() );
		if ( empty( $tiers ) ) {
			return;
		}

		wpaec_get_template( 'service-tiers.php', array( 'tiers' => $tiers ) );
	}

	/**
	 * Attach the chosen tier to the cart item.
	 *
	 * @param array $cart_item_data Existing cart item data.
	 * @param int   $product_id     Product being added.
	 * @return array
	 */
	public static function add_cart_item_data( $cart_item_data, $product_id ) {
		if ( 'service' !== wpaec_get_listing_type( $product_id ) || ! isset( $_POST['wpaec_tier'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo add-to-cart request; product/price validated below.
			return $cart_item_data;
		}

		$index = absint( wp_unslash( $_POST['wpaec_tier'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$tiers = wpaec_get_service_tiers( $product_id );

		if ( isset( $tiers[ $index ] ) ) {
			$cart_item_data['wpaec_tier'] = array(
				'index'         => $index,
				'name'          => $tiers[ $index ]['name'],
				'price'         => $tiers[ $index ]['price'],
				'delivery_days' => $tiers[ $index ]['delivery_days'],
			);
		}

		return $cart_item_data;
	}

	/**
	 * Show the chosen tier in the cart/checkout line item.
	 *
	 * @param array $item_data Displayed data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function display_cart_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['wpaec_tier']['name'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Package', 'aec-market' ),
				'value' => $cart_item['wpaec_tier']['name'],
			);

			if ( ! empty( $cart_item['wpaec_tier']['delivery_days'] ) ) {
				$item_data[] = array(
					'key'   => __( 'Delivery', 'aec-market' ),
					/* translators: %d: number of days. */
					'value' => sprintf( _n( '%d day', '%d days', (int) $cart_item['wpaec_tier']['delivery_days'], 'aec-market' ), (int) $cart_item['wpaec_tier']['delivery_days'] ),
				);
			}
		}
		return $item_data;
	}

	/**
	 * Re-validate and apply the tier price before totals are calculated.
	 *
	 * @param WC_Cart $cart Cart object.
	 * @return void
	 */
	public static function apply_tier_price( $cart ) {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['wpaec_tier'] ) || empty( $cart_item['data'] ) ) {
				continue;
			}

			$product_id = $cart_item['data']->get_id();
			$tiers      = wpaec_get_service_tiers( $product_id );
			$index      = absint( $cart_item['wpaec_tier']['index'] );

			// Always price from stored meta, never from the submitted value.
			if ( isset( $tiers[ $index ] ) ) {
				$cart_item['data']->set_price( (float) $tiers[ $index ]['price'] );
			}
		}
	}

	/**
	 * Persist the chosen tier onto the order line item.
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 * @return void
	 */
	public static function save_order_item_tier( $item, $cart_item_key, $values ) {
		if ( ! empty( $values['wpaec_tier']['name'] ) ) {
			$item->add_meta_data( __( 'Package', 'aec-market' ), $values['wpaec_tier']['name'], true );
			if ( ! empty( $values['wpaec_tier']['delivery_days'] ) ) {
				$item->add_meta_data( '_wpaec_delivery_days', absint( $values['wpaec_tier']['delivery_days'] ), true );
			}
		}
	}

	/**
	 * Allow shop archives to be filtered with ?listing_type=program|service.
	 *
	 * @param array $meta_query Meta query.
	 * @return array
	 */
	public static function filter_by_listing_type( $meta_query ) {
		if ( is_admin() ) {
			return $meta_query;
		}

		$type = isset( $_GET['listing_type'] ) ? sanitize_key( wp_unslash( $_GET['listing_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only filter.

		if ( in_array( $type, array( 'program', 'service' ), true ) ) {
			$meta_query[] = array(
				'relation' => 'program' === $type ? 'OR' : 'AND',
				array(
					'key'   => '_wpaec_listing_type',
					'value' => $type,
				),
			);

			// Products saved before this plugin have no meta; treat them as programs.
			if ( 'program' === $type ) {
				$meta_query[ count( $meta_query ) - 1 ][] = array(
					'key'     => '_wpaec_listing_type',
					'compare' => 'NOT EXISTS',
				);
			}
		}

		return $meta_query;
	}
}
