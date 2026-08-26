<?php
/**
 * Credit ledger — the single choke point for every credit movement.
 *
 * The live balance is cached in user meta (`aec_tools_credits`) for fast reads;
 * every change also writes an append-only audit row to the ledger table.
 *
 * @package AEC_Forge_Tools
 */

namespace AEC_Forge_Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Credit accounting.
 */
class Credits {

	const DB_VERSION = '1.0.0';
	const META_KEY   = 'aec_tools_credits';

	/**
	 * Fully-qualified ledger table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'aec_tools_ledger';
	}

	/**
	 * Create/upgrade the ledger table.
	 *
	 * @return void
	 */
	public static function install_schema() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta() requires the raw table name inline; it cannot be parameterized.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			delta INT NOT NULL,
			balance_after INT NOT NULL,
			reason VARCHAR(255) NOT NULL DEFAULT '',
			ref VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY ref (ref)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( 'aec_tools_db_version', self::DB_VERSION, false );
	}

	/**
	 * Re-run the schema installer if the stored version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade_schema() {
		if ( get_option( 'aec_tools_db_version' ) !== self::DB_VERSION ) {
			self::install_schema();
		}
	}

	/**
	 * Current balance for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function get_balance( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return 0;
		}
		return (int) get_user_meta( $user_id, self::META_KEY, true );
	}

	/**
	 * Whether the user has at least $amount credits.
	 *
	 * @param int $user_id User ID.
	 * @param int $amount  Amount required.
	 * @return bool
	 */
	public static function has_credits( $user_id, $amount ) {
		return self::get_balance( $user_id ) >= (int) $amount;
	}

	/**
	 * Ensure the balance meta row exists so atomic UPDATEs have a target.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private static function ensure_row( $user_id ) {
		if ( ! metadata_exists( 'user', $user_id, self::META_KEY ) ) {
			add_user_meta( $user_id, self::META_KEY, 0, true );
		}
	}

	/**
	 * Write the append-only ledger row and fire the change action.
	 *
	 * @param int    $user_id       User ID.
	 * @param int    $delta         Signed amount.
	 * @param int    $balance_after Post-movement balance.
	 * @param string $reason        Reason.
	 * @param string $ref           Reference.
	 * @return void
	 */
	private static function write_ledger( $user_id, $delta, $balance_after, $reason, $ref ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::table(),
			array(
				'user_id'       => $user_id,
				'delta'         => $delta,
				'balance_after' => $balance_after,
				'reason'        => (string) $reason,
				'ref'           => (string) $ref,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		/**
		 * Fires after any credit movement.
		 *
		 * @param int    $user_id       User ID.
		 * @param int    $delta         Signed amount.
		 * @param int    $balance_after New balance.
		 * @param string $reason        Reason.
		 */
		do_action( 'aec_tools_credits_changed', $user_id, $delta, $balance_after, $reason );
	}

	/**
	 * Grant (or unconditionally adjust) a user's credits and audit it.
	 *
	 * The balance is changed with a single atomic SQL UPDATE so concurrent
	 * movements can't lose each other's writes.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $delta   Signed amount (positive grants; negative adjusts down).
	 * @param string $reason  Human-readable reason.
	 * @param string $ref     Optional external reference (order/run id).
	 * @return int New balance.
	 */
	public static function grant( $user_id, $delta, $reason = '', $ref = '' ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$delta   = (int) $delta;
		if ( $user_id <= 0 || 0 === $delta ) {
			return self::get_balance( $user_id );
		}

		self::ensure_row( $user_id );

		// Atomic increment. meta_value is longtext; MySQL coerces it to a number.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_value = CAST(meta_value AS SIGNED) + %d WHERE user_id = %d AND meta_key = %s",
				$delta,
				$user_id,
				self::META_KEY
			)
		);
		wp_cache_delete( $user_id, 'user_meta' );

		$balance = self::get_balance( $user_id );
		self::write_ledger( $user_id, $delta, $balance, $reason, $ref );

		return $balance;
	}

	/**
	 * Spend credits atomically — only if the balance is sufficient.
	 *
	 * A single guarded UPDATE performs the check and the debit together, so two
	 * concurrent runs can never both succeed on the last credit. The caller MUST
	 * honor the return value and not deliver a paid result when it is false.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $amount  Positive amount to spend.
	 * @param string $reason  Reason.
	 * @param string $ref     Optional reference.
	 * @return bool True if charged, false if insufficient.
	 */
	public static function spend( $user_id, $amount, $reason = '', $ref = '' ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$amount  = abs( (int) $amount );
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( 0 === $amount ) {
			return true; // Free run — nothing to charge.
		}

		self::ensure_row( $user_id );

		// Atomic conditional debit: the row is updated only when the balance is
		// still >= amount. Zero affected rows means insufficient funds.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_value = CAST(meta_value AS SIGNED) - %d WHERE user_id = %d AND meta_key = %s AND CAST(meta_value AS SIGNED) >= %d",
				$amount,
				$user_id,
				self::META_KEY,
				$amount
			)
		);
		if ( empty( $affected ) ) {
			return false;
		}
		wp_cache_delete( $user_id, 'user_meta' );

		$balance = self::get_balance( $user_id );
		self::write_ledger( $user_id, -$amount, $balance, $reason, $ref );

		return true;
	}

	/**
	 * Whether a ledger row already exists for a reference (idempotency).
	 *
	 * @param string $ref Reference key.
	 * @return bool
	 */
	public static function ref_exists( $ref ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is internal and cannot be parameterized.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE ref = %s LIMIT 1", $ref ) );
		return ! empty( $found );
	}

	/**
	 * Recent ledger rows for a user.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Max rows.
	 * @return array
	 */
	public static function recent( $user_id, $limit = 10 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is internal and cannot be parameterized.
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d", (int) $user_id, (int) $limit ) );
	}
}
