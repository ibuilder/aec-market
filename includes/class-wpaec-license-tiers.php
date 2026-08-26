<?php
/**
 * Regular vs Extended license tiers (Envato-style) for program listings.
 *
 * A vendor can set an optional Extended price + description. Buyers pick a tier
 * on the product page; the cart is priced accordingly, the choice is stored on
 * the order line item, and the issued license reflects the tier (Extended grants
 * a firm-wide activation allowance).
 *
 * Implemented with custom cart pricing rather than WooCommerce variations — more
 * robust for products created through the front-end vendor dashboard.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

/**
 * License-tier pricing + capture.
 */
class AEC_Market_License_Tiers {

	const EXTENDED_ACTIVATIONS = 999; // Practically unlimited / firm-wide.

	/**
	 * Hook the cart/product/order flow.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_selector' ) );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_price' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_cart_item' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_item' ), 10, 4 );
	}

	/**
	 * Extended price for a product, or 0 when no extended tier is offered.
	 *
	 * @param int $product_id Product ID.
	 * @return float
	 */
	public static function extended_price( $product_id ) {
		$price = get_post_meta( $product_id, '_wpaec_extended_price', true );
		return ( '' === $price ) ? 0.0 : (float) $price;
	}

	/**
	 * Render the license-type selector above the add-to-cart button.
	 *
	 * @return void
	 */
	public static function render_selector() {
		global $product;
		if ( ! $product ) {
			return;
		}
		$ext = self::extended_price( $product->get_id() );
		if ( $ext <= 0 ) {
			return;
		}
		$reg  = (float) $product->get_price();
		$desc = (string) get_post_meta( $product->get_id(), '_wpaec_extended_desc', true );
		if ( '' === $desc ) {
			$desc = __( 'Use in a product you sell, firm-wide, or redistribute.', 'aec-market' );
		}
		?>
		<div class="wpaec-license-tiers">
			<label class="wpaec-tier-opt">
				<input type="radio" name="wpaec_license_type" value="regular" checked="checked" />
				<span class="wpaec-tier-opt__body">
					<span class="wpaec-tier-opt__title"><?php esc_html_e( 'Regular license', 'aec-market' ); ?> — <?php echo wp_kses_post( wc_price( $reg ) ); ?></span>
					<span class="wpaec-tier-opt__desc"><?php esc_html_e( 'Single end product / single-seat use.', 'aec-market' ); ?></span>
				</span>
			</label>
			<label class="wpaec-tier-opt">
				<input type="radio" name="wpaec_license_type" value="extended" />
				<span class="wpaec-tier-opt__body">
					<span class="wpaec-tier-opt__title"><?php esc_html_e( 'Extended license', 'aec-market' ); ?> — <?php echo wp_kses_post( wc_price( $ext ) ); ?></span>
					<span class="wpaec-tier-opt__desc"><?php echo esc_html( $desc ); ?></span>
				</span>
			</label>
		</div>
		<?php
	}

	/**
	 * Capture the chosen tier into the cart item.
	 *
	 * @param array $data       Cart item data.
	 * @param int   $product_id Product ID.
	 * @return array
	 */
	public static function add_cart_item_data( $data, $product_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce add-to-cart is nonce/flow protected.
		if ( isset( $_POST['wpaec_license_type'] ) && 'extended' === sanitize_key( wp_unslash( $_POST['wpaec_license_type'] ) ) && self::extended_price( $product_id ) > 0 ) {
			$data['wpaec_license_type'] = 'extended';
		}
		return $data;
	}

	/**
	 * Price the cart line for the chosen tier.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return void
	 */
	public static function set_price( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['wpaec_license_type'] ) && 'extended' === $item['wpaec_license_type'] ) {
				$ext = self::extended_price( $item['product_id'] );
				if ( $ext > 0 && isset( $item['data'] ) ) {
					$item['data']->set_price( $ext );
				}
			}
		}
	}

	/**
	 * Show the tier in cart/checkout.
	 *
	 * @param array $items Item data.
	 * @param array $item  Cart item.
	 * @return array
	 */
	public static function display_cart_item( $items, $item ) {
		if ( ! empty( $item['wpaec_license_type'] ) && 'extended' === $item['wpaec_license_type'] ) {
			$items[] = array(
				'key'   => __( 'License', 'aec-market' ),
				'value' => __( 'Extended', 'aec-market' ),
			);
		}
		return $items;
	}

	/**
	 * Persist the tier on the order line item.
	 *
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 * @param WC_Order              $order         Order.
	 * @return void
	 */
	public static function save_order_item( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['wpaec_license_type'] ) && 'extended' === $values['wpaec_license_type'] ) {
			$item->add_meta_data( '_wpaec_license_type', 'extended', true );
			$item->add_meta_data( __( 'License', 'aec-market' ), __( 'Extended', 'aec-market' ), true );
		}
	}
}
