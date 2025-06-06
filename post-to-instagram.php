<?php
/**
 * Plugin Name:       Post to Instagram
 * Plugin URI:        https://example.com/plugins/post-to-instagram/
 * Description:       Allows WordPress users to easily publish images from their posts directly to a connected Instagram account.
 * Version:           1.0.0
 * Author:            Your Name
 * Author URI:        https://example.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       post-to-instagram
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Define Core Plugin Constants
 */
define( 'PTI_VERSION', '1.0.0' );
define( 'PTI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PTI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PTI_PLUGIN_FILE', __FILE__ );
define( 'PTI_MIN_WP_VERSION', '5.0' );
define( 'PTI_MIN_PHP_VERSION', '7.4' );

/**
 * The code that runs during plugin activation.
 */
function pti_activate_plugin() {
	// Add any activation specific code here.
    // For example, set default options, check for dependencies.
    if ( version_compare( get_bloginfo( 'version' ), PTI_MIN_WP_VERSION, '<' ) ) {
		wp_die(
			esc_html__( 'Post to Instagram requires WordPress version ', 'post-to-instagram' ) . esc_html( PTI_MIN_WP_VERSION ) . esc_html__( ' or higher.', 'post-to-instagram' ),
			esc_html__( 'Plugin Activation Error', 'post-to-instagram' ),
			array( 'back_link' => true )
		);
	}

	if ( version_compare( PHP_VERSION, PTI_MIN_PHP_VERSION, '<' ) ) {
		wp_die(
			esc_html__( 'Post to Instagram requires PHP version ', 'post-to-instagram' ) . esc_html( PTI_MIN_PHP_VERSION ) . esc_html__( ' or higher.', 'post-to-instagram' ),
			esc_html__( 'Plugin Activation Error', 'post-to-instagram' ),
			array( 'back_link' => true )
		);
	}
}
register_activation_hook( PTI_PLUGIN_FILE, 'pti_activate_plugin' );

/**
 * The code that runs during plugin deactivation.
 */
function pti_deactivate_plugin() {
	// Add any deactivation specific code here.
    // For example, remove options, unregister cron jobs.
    PTI_Temp_Cleanup::on_deactivation();
}
register_deactivation_hook( PTI_PLUGIN_FILE, 'pti_deactivate_plugin' );

/**
 * Autoloader for plugin classes.
 *
 * @param string $class_name The name of the class to load.
 */
function pti_autoloader( $class_name ) {


	$class_file_base = strtolower( $class_name );

    // Remove PTI_ prefix first
    $class_file_prefixed_removed = str_replace( 'pti_', '', $class_file_base );

    // Then replace underscores with hyphens
    $class_file_hyphenated = str_replace( '_', '-', $class_file_prefixed_removed );

	$final_class_filename_part = $class_file_hyphenated; // This should be 'auth-handler' for PTI_Auth_Handler

	$directories = array( 'admin/', 'auth/', 'schedule/', 'includes/' );
	foreach ( $directories as $dir ) {
		$file_path = PTI_PLUGIN_DIR . $dir . 'class-' . $final_class_filename_part . '.php';
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
			return;
		}
	}
}
spl_autoload_register( 'pti_autoloader' );

// Register REST API endpoints
require_once PTI_PLUGIN_DIR . 'includes/class-pti-rest-api.php';
new PTI_REST_API();

/**
 * Initialize the plugin.
 *
 * Loads all the main plugin classes and features.
 */
function pti_init_plugin() {
    new PTI_Auth_Handler(); // Always needed for OAuth redirect!
    if ( is_admin() ) {
        new PTI_Admin_UI();
    }
    new PTI_Temp_Cleanup();
}
add_action( 'plugins_loaded', 'pti_init_plugin' );

/**
 * Helper function to get plugin options.
 *
 * @param string $key The option key.
 * @param mixed  $default The default value if option not found.
 * @return mixed The option value.
 */
function pti_get_option( $key, $default = false ) {
    $options = get_option( 'pti_settings' );
    return isset( $options[ $key ] ) ? $options[ $key ] : $default;
}

/**
 * Helper function to update plugin options.
 *
 * @param string $key The option key.
 * @param mixed  $value The value to set.
 * @return bool True if option was updated, false otherwise.
 */
function pti_update_option( $key, $value ) {
    $options = get_option( 'pti_settings', array() );
    $options[ $key ] = $value;
    return update_option( 'pti_settings', $options );
}

// Register rewrite rule on activation
function pti_register_oauth_rewrite() {
    add_rewrite_rule('^pti-oauth/?$', 'index.php?pti_oauth=1', 'top');
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'pti_register_oauth_rewrite');

// Remove rewrite rule on deactivation
function pti_remove_oauth_rewrite() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'pti_remove_oauth_rewrite');

// Add query var
function pti_add_query_vars($vars) {
    $vars[] = 'pti_oauth';
    return $vars;
}
add_filter('query_vars', 'pti_add_query_vars');

// Route /pti-oauth/ to the handler
add_action('init', function() {
    if (get_query_var('pti_oauth') == '1') {
        $handler = new PTI_Auth_Handler();
        $handler->handle_oauth_redirect();
        exit;
    }
});

