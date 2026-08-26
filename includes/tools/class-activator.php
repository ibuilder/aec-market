<?php
/**
 * Activation routines.
 *
 * @package AEC_Forge_Tools
 */

namespace AEC_Forge_Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation.
 */
class Activator {

	/**
	 * Create tables, seed default options.
	 *
	 * @return void
	 */
	public static function activate() {
		Credits::install_schema();
		Settings::install_defaults();

		// Flag so we can register WooCommerce products on first admin load.
		update_option( 'aec_tools_needs_product_sync', 1, false );

		flush_rewrite_rules();
	}
}
