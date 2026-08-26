<?php
/**
 * AEC Forge Tools orchestrator.
 *
 * Wires the pay-per-use AI tools subsystem (credits wallet, WooCommerce credit
 * packs, service framework, shortcodes and AJAX) into the AEC Market plugin.
 * WooCommerce is guaranteed active here — AEC Market itself requires it.
 *
 * @package AEC_Forge_Tools
 */

namespace AEC_Forge_Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that boots every AEC Forge Tools subsystem.
 */
final class Tools {

	/**
	 * Singleton instance.
	 *
	 * @var Tools|null
	 */
	private static $instance = null;

	/**
	 * Service registry.
	 *
	 * @var Service_Registry
	 */
	public $services;

	/**
	 * Settings accessor.
	 *
	 * @var Settings
	 */
	public $settings;

	/**
	 * Get the singleton.
	 *
	 * @return Tools
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->services = new Service_Registry();
	}

	/**
	 * Register hooks for every subsystem.
	 *
	 * @return void
	 */
	public function run() {
		// One-time install (schema, default settings, product-sync flag). This
		// runs on first load after the tools ship, so no activation hook needed.
		$this->maybe_install();

		// Core, always-on subsystems.
		$this->services->boot();

		( new Admin\Admin() )->register();
		( new Ajax() )->register();
		( new Shortcodes() )->register();

		// WooCommerce credit packs (AEC Market requires WooCommerce, so it is active).
		( new Woo() )->register();

		// Ensure the credits schema exists (covers updates without reactivation).
		add_action( 'admin_init', array( Credits::class, 'maybe_upgrade_schema' ) );

		// Give new accounts their free trial credits.
		add_action( 'user_register', array( $this, 'grant_trial' ) );
	}

	/**
	 * First-run install: build the ledger table, seed settings, flag product sync.
	 *
	 * @return void
	 */
	private function maybe_install() {
		if ( get_option( 'aec_tools_installed' ) ) {
			return;
		}
		Credits::install_schema();
		Settings::install_defaults();
		update_option( 'aec_tools_needs_product_sync', 1, false );
		update_option( 'aec_tools_installed', 1, false );
	}

	/**
	 * Grant signup trial credits, once per user.
	 *
	 * @param int $user_id New user ID.
	 * @return void
	 */
	public function grant_trial( $user_id ) {
		$trial = (int) Settings::value( 'free_trial_credits', 0 );
		if ( $trial > 0 && ! Credits::ref_exists( 'signup-' . $user_id ) ) {
			Credits::grant( $user_id, $trial, 'signup trial', 'signup-' . $user_id );
		}
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}
}
