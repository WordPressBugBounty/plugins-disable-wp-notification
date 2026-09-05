<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * When populating this file, consider the following flow
 * of control:
 *
 * - This method should be static
 * - Check if the $_REQUEST content actually is the plugin name
 * - Run an admin referrer check to make sure it goes through authentication
 * - Verify the output of $_GET makes sense
 * - Repeat with other user roles. Best directly by using the links/query string parameters.
 * - Repeat things for multisite. Once for a single site in the network, once sitewide.
 *
 * @link       https://sourabhagrawal.com/
 * @since      1.0.0
 *
 * @package    Disable_Wp_Notification
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete options
delete_option( 'disable_notifications' );
delete_site_option( 'disable_notifications' );

// Delete user meta across all users
delete_metadata( 'user', 0, 'dwpn_dismissed_notices', '', true );

// Clear transients for users
global $wpdb;
if ( isset( $wpdb->options ) ) {
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dwpn_blocked_%' OR option_name LIKE '_transient_timeout_dwpn_blocked_%'" );
}
