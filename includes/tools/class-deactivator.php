<?php
/**
 * Deactivation routines.
 *
 * @package AEC_Forge_Tools
 */

namespace AEC_Forge_Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin deactivation. Non-destructive: data is preserved.
 */
class Deactivator {

	/**
	 * Clear scheduled events and caches. Never drops user data.
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_transient( 'aec_tools_catalog' );
		delete_transient( 'aec_tools_update_manifest' );
		flush_rewrite_rules();
	}
}
