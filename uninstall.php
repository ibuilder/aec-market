<?php
/**
 * Uninstall routine: removes plugin data when the plugin is deleted.
 *
 * Data is only removed when the site owner opts in via the
 * `remove_data_on_uninstall` key of the `wpaecmarket_settings` option,
 * following the principle of never destroying commerce records silently.
 *
 * @package AEC_Market
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
defined( 'ABSPATH' ) || exit;

$wpaec_settings = get_option( 'wpaecmarket_settings', array() );

if ( empty( $wpaec_settings['remove_data_on_uninstall'] ) ) {
	// Keep commissions, licenses and vendor data unless explicitly opted in.
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Uninstall cleanup of plugin-owned tables and rows.
foreach ( array( 'wpaec_license_activations', 'wpaec_licenses', 'wpaec_commissions', 'aec_tools_ledger' ) as $wpaec_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $wpaec_table ) );
}

// AEC Forge Tools: credits balance (user meta) and options.
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key = %s', $wpdb->usermeta, 'aec_tools_credits' ) );
$wpaec_tools_opt_like = $wpdb->esc_like( 'aec_tools_' ) . '%';
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpaec_tools_opt_like ) );

$wpaec_meta_like = $wpdb->esc_like( '_wpaec_' ) . '%';

$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $wpaec_meta_like ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, $wpaec_meta_like ) );
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( '_transient_wpaec_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wpaec_' ) . '%'
	)
);
// phpcs:enable

remove_role( 'wpaec_vendor' );

$wpaec_admin_role = get_role( 'administrator' );
if ( $wpaec_admin_role ) {
	$wpaec_admin_role->remove_cap( 'wpaec_manage_marketplace' );
}
$wpaec_shop_manager_role = get_role( 'shop_manager' );
if ( $wpaec_shop_manager_role ) {
	$wpaec_shop_manager_role->remove_cap( 'wpaec_manage_marketplace' );
}

delete_option( 'wpaecmarket_settings' );
delete_option( 'wpaecmarket_version' );
