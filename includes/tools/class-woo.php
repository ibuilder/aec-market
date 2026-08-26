<?php
/**
 * WooCommerce bridge: credit packs become products; completed orders grant credits.
 *
 * @package AEC_Forge_Tools
 */

namespace AEC_Forge_Tools;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce integration. Only instantiated when WooCommerce is active.
 */
class Woo {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_sync_products' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'grant_for_order' ), 10, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'grant_for_order' ), 10, 1 );

		// Show the buyer's credit balance on the My Account dashboard.
		add_action( 'woocommerce_account_dashboard', array( $this, 'account_balance' ) );
	}

	/**
	 * Create/refresh pack products when the sync flag is set.
	 *
	 * @return void
	 */
	public function maybe_sync_products() {
		if ( ! get_option( 'aec_tools_needs_product_sync' ) ) {
			return;
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Settings::get();
		$packs    = isset( $settings['packs'] ) ? $settings['packs'] : array();
		$map      = isset( $settings['product_map'] ) ? $settings['product_map'] : array();
		$cat_id   = $this->credits_category_id();

		foreach ( $packs as $pack ) {
			$product_id = isset( $map[ $pack['id'] ] ) ? (int) $map[ $pack['id'] ] : 0;
			$product    = $product_id ? wc_get_product( $product_id ) : null;

			if ( ! $product ) {
				$product = new \WC_Product_Simple();
			}
			$product->set_name( sprintf( '%s — %d credits', $pack['name'], $pack['credits'] ) );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'visible' );
			$product->set_virtual( true );
			$product->set_sold_individually( false );
			$product->set_regular_price( (string) $pack['price'] );
			$product->set_sku( 'aec-forge-tools-' . $pack['id'] );
			$product->update_meta_data( '_aec_tools_pack_id', $pack['id'] );
			$product->update_meta_data( '_aec_tools_credits', (int) $pack['credits'] );
			if ( $cat_id ) {
				$product->set_category_ids( array( $cat_id ) );
			}
			$new_id = $product->save();

			if ( $new_id ) {
				$map[ $pack['id'] ] = $new_id;
			}
		}

		$settings['product_map'] = $map;
		update_option( Settings::OPTION, $settings );
		delete_option( 'aec_tools_needs_product_sync' );
	}

	/**
	 * Get (creating if needed) the "AI Credits" product category term ID so the
	 * credit packs sit in their own shop category, separate from vendor products.
	 *
	 * @return int Term ID, or 0 on failure.
	 */
	private function credits_category_id() {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return 0;
		}
		$term = get_term_by( 'slug', 'ai-credits', 'product_cat' );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		$created = wp_insert_term( __( 'AI Credits', 'aec-market' ), 'product_cat', array( 'slug' => 'ai-credits' ) );
		if ( is_wp_error( $created ) ) {
			return 0;
		}
		return isset( $created['term_id'] ) ? (int) $created['term_id'] : 0;
	}

	/**
	 * Grant credits for the items in a paid order — exactly once.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function grant_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( 'yes' === $order->get_meta( '_aec_tools_credits_granted' ) ) {
			return; // Idempotent — already granted.
		}
		// Second idempotency guard: this hook fires on both payment_complete and
		// status_completed. The order ref is unique per order, so if the ledger
		// already recorded this grant, don't grant again — just flag the order.
		if ( Credits::ref_exists( 'order-' . $order_id ) ) {
			$order->update_meta_data( '_aec_tools_credits_granted', 'yes' );
			$order->save();
			return;
		}
		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			$order->add_order_note( __( 'AEC Forge Tools: order has no user account, so credits could not be granted.', 'aec-market' ) );
			return;
		}

		$total = 0;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$credits = (int) $product->get_meta( '_aec_tools_credits' );
			if ( $credits > 0 ) {
				$total += $credits * (int) $item->get_quantity();
			}
		}

		if ( $total <= 0 ) {
			return;
		}

		Credits::grant( $user_id, $total, sprintf( 'purchase order #%d', $order_id ), 'order-' . $order_id );
		$order->update_meta_data( '_aec_tools_credits_granted', 'yes' );
		$order->add_order_note( sprintf( /* translators: %d credits */ __( 'AEC Forge Tools: granted %d credits.', 'aec-market' ), $total ) );
		$order->save();
	}

	/**
	 * Show credit balance on the WooCommerce account dashboard.
	 *
	 * @return void
	 */
	public function account_balance() {
		$balance = Credits::get_balance( get_current_user_id() );
		echo '<p>' . esc_html( sprintf( /* translators: %d credits */ _n( 'You have %d AEC Forge Tools credit.', 'You have %d AEC Forge Tools credits.', $balance, 'aec-market' ), $balance ) ) . '</p>';
	}
}
