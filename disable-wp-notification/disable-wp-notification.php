<?php
/**
 * @link              https://sourabhagrawal.com/
 * @since             1.0.0
 * @package           Disable_Wp_Notification
 *
 * @wordpress-plugin
 * Plugin Name:       Disable WP Notification
 * Plugin URI:        https://sourabhagrawal.com/disable-wp-notification
 * Description:       Best wordpress plugin to remove all the admin panel notifications in just one click. Including the theme and plugin update notification.
 * Version:           3.2
 * Author:            Sourabh Agrawal
 * Author URI:        https://sourabhagrawal.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       disable-wp-notification
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 */
define( 'DISABLE_WP_NOTIFICATION_VERSION', '3.2' );
define( 'DISABLE_WP_NOTIFICATION', plugin_basename( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-disable-wp-notification-activator.php
 */
function activate_disable_wp_notification() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-disable-wp-notification-activator.php';
	Disable_Wp_Notification_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-disable-wp-notification-deactivator.php
 */
function deactivate_disable_wp_notification() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-disable-wp-notification-deactivator.php';
	Disable_Wp_Notification_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_disable_wp_notification' );
register_deactivation_hook( __FILE__, 'deactivate_disable_wp_notification' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-disable-wp-notification.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_disable_wp_notification() {

	$plugin = new Disable_Wp_Notification();
	$plugin->run();

}
run_disable_wp_notification();
