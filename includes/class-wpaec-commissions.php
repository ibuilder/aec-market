<?php
/**
 * Commission recording and payout tracking.
 *
 * @package AEC_Market
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records a commission row per vendor line item when orders complete.
 */
class AEC_Market_Commissions {

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'record_for_order' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'void_for_order' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'void_for_order' ) );
	}

	/**
	 * Table name helper.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpaec_commissions';
	}

	/**
	 * Record commissions for every vendor-owned line item on a completed order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function record_for_order( $order_id ) {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_product_id();

			// First-party AEC Forge Tools credit packs are not vendor sales — skip them.
			if ( '' !== (string) get_post_meta( $product_id, '_aec_tools_credits', true ) ) {
				continue;
			}

			$vendor_id = (int) get_post_field( 'post_author', $product_id );

			if ( ! $vendor_id || ! wpaec_is_vendor( $vendor_id ) ) {
				continue;
			}

			// Idempotency: never double-record an order item.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE order_item_id = %d', self::table(), $item_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $exists ) {
				continue;
			}

			$line_total = (float) $item->get_total();
			$rate       = wpaec_get_commission_rate( $vendor_id );
			$commission = round( $line_total * ( $rate / 100 ), 4 );
			$earning    = round( $line_total - $commission, 4 );

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array(
					'order_id'          => $order_id,
					'order_item_id'     => $item_id,
					'product_id'        => $product_id,
					'vendor_id'         => $vendor_id,
					'line_total'        => $line_total,
					'commission_rate'   => $rate,
					'commission_amount' => $commission,
					'vendor_earning'    => $earning,
					'status'            => 'pending',
					'created_at'        => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%d', '%d', '%f', '%f', '%f', '%f', '%s', '%s' )
			);

			/**
			 * Fires after a commission row is recorded.
			 *
			 * @since 1.0.0
			 *
			 * @param int   $commission_id Commission row ID.
			 * @param int   $vendor_id     Vendor user ID.
			 * @param float $earning       Vendor net earning.
			 * @param int   $order_id      Order ID.
			 */
			do_action( 'wpaecmarket_commission_recorded', (int) $wpdb->insert_id, $vendor_id, $earning, $order_id );
		}
	}

	/**
	 * Void commissions when an order is refunded or cancelled.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function void_for_order( $order_id ) {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE %i SET status = 'refunded' WHERE order_id = %d AND status != 'paid'",
				self::table(),
				$order_id
			)
		);
	}

	/**
	 * Mark a set of commission rows as paid.
	 *
	 * @param int[] $ids Commission row IDs.
	 * @return int Number of rows updated.
	 */
	public static function mark_paid( $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- $placeholders is a counted list of %d placeholders.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wpaec_commissions SET status = 'paid', paid_at = %s WHERE status = 'pending' AND id IN ($placeholders)",
				array_merge( array( current_time( 'mysql', true ) ), $ids )
			)
		);
		// phpcs:enable

		return (int) $updated;
	}

	/**
	 * Earnings summary for a vendor.
	 *
	 * @param int $vendor_id Vendor user ID.
	 * @return object { total_sales, total_earning, pending_earning, paid_earning }
	 */
	public static function get_vendor_totals( $vendor_id ) {
		global $wpdb;

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT
					COALESCE( SUM( CASE WHEN status != 'refunded' THEN line_total END ), 0 ) AS total_sales,
					COALESCE( SUM( CASE WHEN status != 'refunded' THEN vendor_earning END ), 0 ) AS total_earning,
					COALESCE( SUM( CASE WHEN status = 'pending' THEN vendor_earning END ), 0 ) AS pending_earning,
					COALESCE( SUM( CASE WHEN status = 'paid' THEN vendor_earning END ), 0 ) AS paid_earning
				FROM %i WHERE vendor_id = %d",
				self::table(),
				absint( $vendor_id )
			)
		);
	}

	/**
	 * Paginated commission rows.
	 *
	 * @param array $args { vendor_id, status, page, per_page }.
	 * @return array { rows: object[], total: int }
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'vendor_id' => 0,
				'status'    => '',
				'page'      => 1,
				'per_page'  => 20,
			)
		);

		$vendor_id = absint( $args['vendor_id'] );
		$status    = sanitize_key( $args['status'] );
		$per_page  = max( 1, absint( $args['per_page'] ) );
		$offset    = ( max( 1, absint( $args['page'] ) ) - 1 ) * $per_page;

		// A zero vendor_id / empty status disables that filter clause.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wpaec_commissions WHERE ( %d = 0 OR vendor_id = %d ) AND ( %s = '' OR status = %s )",
				$vendor_id,
				$vendor_id,
				$status,
				$status
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wpaec_commissions WHERE ( %d = 0 OR vendor_id = %d ) AND ( %s = '' OR status = %s ) ORDER BY id DESC LIMIT %d OFFSET %d",
				$vendor_id,
				$vendor_id,
				$status,
				$status,
				$per_page,
				$offset
			)
		);
		// phpcs:enable

		return array(
			'rows'  => $rows,
			'total' => (int) $total,
		);
	}
}
