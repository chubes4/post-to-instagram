<?php
/**
 * Plugin Name:       Post to Instagram
 * Plugin URI:        https://github.com/chubes4/post-to-instagram
 * Description:       Allows WordPress users to easily publish images from their posts directly to a connected Instagram account.
 * Version:           1.0.0
 * Author:            Chris Huber
 * Author URI:        https://chubes.net
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
    PTI_Temp_Cleanup::on_deactivation();
    PTI_Scheduler::on_deactivation();
}
register_deactivation_hook( PTI_PLUGIN_FILE, 'pti_deactivate_plugin' );

/**
 * Autoloader for plugin classes.
 *
 * @param string $class_name The name of the class to load.
 */
function pti_autoloader( $class_name ) {
	if ( strpos( $class_name, 'PTI_' ) !== 0 ) {
		return;
	}

	$class_file = str_replace( array( 'PTI_', '_' ), array( '', '-' ), $class_name );
	$class_file = 'class-' . strtolower( $class_file ) . '.php';

	$directories = array( 'admin/', 'auth/', 'schedule/', 'includes/' );
	foreach ( $directories as $dir ) {
		$file_path = PTI_PLUGIN_DIR . $dir . $class_file;
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
			return;
		}
	}
}
spl_autoload_register( 'pti_autoloader' );

require_once PTI_PLUGIN_DIR . 'includes/class-pti-rest-api.php';
new PTI_REST_API();

/**
 * Initialize the plugin.
 *
 * Loads all the main plugin classes and features.
 */
function pti_init_plugin() {
    new PTI_Auth_Handler();
    if ( is_admin() ) {
        new PTI_Admin_UI();
    }
    new PTI_Temp_Cleanup();
    new PTI_Scheduler();
}
add_action( 'plugins_loaded', 'pti_init_plugin' );


/**
 * Setup OAuth routing.
 */
function pti_setup_oauth_routing() {
    add_rewrite_rule('^pti-oauth/?$', 'index.php?pti_oauth=1', 'top');
    add_filter('query_vars', function($vars) {
        $vars[] = 'pti_oauth';
        return $vars;
    });
    add_action('init', function() {
        if (get_query_var('pti_oauth') == '1') {
            $handler = new PTI_Auth_Handler();
            $handler->handle_oauth_redirect();
            exit;
        }
    });
}
add_action('init', 'pti_setup_oauth_routing');

register_activation_hook(__FILE__, function() {
    pti_setup_oauth_routing();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});

