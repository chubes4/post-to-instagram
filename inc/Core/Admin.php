<?php
/**
 * Gutenberg editor integration with asset enqueuing and data localization.
 *
 * @package PostToInstagram\Core
 */

namespace PostToInstagram\Core;

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Admin integration for Gutenberg block editor.
 */
class Admin {

    /**
     * Register WordPress action hooks.
     */
    public static function register() {
        add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_editor_assets' ] );
    }

    /**
     * Enqueue assets for post edit screens only.
     */
    public static function enqueue_editor_assets() {
        global $pagenow;

        if ( ! in_array( $pagenow, [ 'post.php', 'post-new.php' ] ) ) {
            return;
        }

        self::enqueue_scripts_and_styles();
        self::localize_editor_data();
    }

    private static function enqueue_scripts_and_styles() {
        $script_asset = require PTI_PLUGIN_DIR . 'inc/Assets/dist/js/post-editor.asset.php';

        wp_enqueue_script(
            'pti-post-editor-script',
            PTI_PLUGIN_URL . 'inc/Assets/dist/js/post-editor.js',
            $script_asset['dependencies'],
            $script_asset['version']
        );

        wp_enqueue_media();
        wp_enqueue_script( 'media-gallery' );

        wp_enqueue_style(
            'pti-admin-styles',
            PTI_PLUGIN_URL . 'inc/Assets/dist/css/admin-styles.css',
            array(),
            filemtime( PTI_PLUGIN_DIR . 'inc/Assets/dist/css/admin-styles.css' )
        );
    }

    private static function localize_editor_data() {
        $post_id = get_the_ID();
        $image_ids = self::extract_post_images( $post_id );
        $auth_status = self::get_auth_status();
        $shared_image_ids = self::get_shared_image_ids( $post_id );

        $localized_data = array_merge(
            [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'admin_url' => admin_url(),
                'post_id' => $post_id,
                'content_image_ids' => $image_ids,
                'shared_image_ids' => $shared_image_ids,
                'auth_redirect_status' => self::get_auth_redirect_status(),
            ],
            self::generate_nonces(),
            $auth_status,
            self::get_i18n_strings()
        );

        wp_localize_script( 'pti-post-editor-script', 'pti_data', $localized_data );
    }

    /**
     * Extract image IDs from post content (Gutenberg blocks + legacy).
     *
     * @param int $post_id WordPress post ID
     * @return array Array of image attachment IDs
     */
    private static function extract_post_images( $post_id ) {
        if ( ! $post_id ) {
            return [];
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return [];
        }

        $image_ids = [];

        $featured_id = get_post_thumbnail_id( $post_id );
        if ( $featured_id ) {
            $image_ids[] = $featured_id;
        }

        if ( has_blocks( $post->post_content ) ) {
            $blocks = parse_blocks( $post->post_content );
            foreach ( $blocks as $block ) {
                if ( $block['blockName'] === 'core/image' && ! empty( $block['attrs']['id'] ) ) {
                    $image_ids[] = $block['attrs']['id'];
                }
                if ( $block['blockName'] === 'core/gallery' &&
                     ! empty( $block['attrs']['ids'] ) &&
                     is_array( $block['attrs']['ids'] ) ) {
                    $image_ids = array_merge( $image_ids, $block['attrs']['ids'] );
                }
            }
        }

        if ( preg_match_all( '/wp-image-([0-9]+)/', $post->post_content, $matches ) ) {
            $image_ids = array_merge( $image_ids, $matches[1] );
        }

        return array_unique( array_map( 'intval', $image_ids ) );
    }

    /**
     * Get authentication status.
     *
     * @return array Authentication status data
     */
    private static function get_auth_status() {
        return [
            'is_configured' => Auth::is_configured(),
            'is_authenticated' => Auth::is_authenticated(),
            'auth_url' => Auth::is_configured() && ! Auth::is_authenticated()
                ? Auth::get_authorization_url()
                : '#'
        ];
    }

    /**
     * Get shared image IDs for current post.
     *
     * @param int $post_id WordPress post ID
     * @return array Array of shared image IDs
     */
    private static function get_shared_image_ids( $post_id ) {
        $shared_meta = get_post_meta( $post_id, '_pti_instagram_shared_images', true );
        return is_array( $shared_meta ) ? array_column( $shared_meta, 'image_id' ) : [];
    }

    /**
     * Generate security nonces for AJAX operations.
     *
     * @return array Associative array of nonce names and values
     */
    private static function generate_nonces() {
        return [
            'nonce_save_app_creds' => wp_create_nonce( 'pti_save_app_creds_nonce' ),
            'nonce_auth_check' => wp_create_nonce( 'pti_auth_check_nonce' ),
            'nonce_disconnect' => wp_create_nonce( 'pti_disconnect_nonce' ),
            'nonce_post_media' => wp_create_nonce( 'pti_post_media_nonce' ),
            'nonce_schedule_media' => wp_create_nonce( 'pti_schedule_media_nonce' ),
        ];
    }

    /**
     * Get internationalization strings for JavaScript.
     *
     * @return array Associative array of i18n strings
     */
    private static function get_i18n_strings() {
        return [
            'i18n' => [
                'post_to_instagram' => __( 'Post to Instagram', 'post-to-instagram' ),
                'settings_title' => __( 'Instagram Configuration', 'post-to-instagram' ),
                'app_id_label' => __( 'Instagram App ID', 'post-to-instagram' ),
                'app_secret_label' => __( 'Instagram App Secret', 'post-to-instagram' ),
                'save_credentials' => __( 'Save Credentials', 'post-to-instagram' ),
                'connect_instagram' => __( 'Connect to Instagram', 'post-to-instagram' ),
                'disconnect_instagram' => __( 'Disconnect Instagram Account', 'post-to-instagram' ),
                'select_images' => __( 'Select Images for Instagram', 'post-to-instagram' ),
                'add_caption' => __( 'Add Caption', 'post-to-instagram' ),
                'reorder_images' => __( 'Reorder Images', 'post-to-instagram' ),
                'post_now' => __( 'Post Now', 'post-to-instagram' ),
                'schedule_post' => __( 'Schedule Post', 'post-to-instagram' ),
                'max_images_reached' => sprintf(
                    __( 'You can select a maximum of %d images.', 'post-to-instagram' ),
                    10
                ),
                'error_saving_creds' => __( 'Error saving credentials. Please try again.', 'post-to-instagram' ),
                'creds_saved' => __( 'Credentials saved.', 'post-to-instagram' ),
                'error_disconnecting' => __( 'Error disconnecting account. Please try again.', 'post-to-instagram' ),
                'disconnected' => __( 'Account disconnected.', 'post-to-instagram' ),
                'checking_auth' => __( 'Checking authentication status...', 'post-to-instagram' ),
                'auth_successful' => __( 'Authentication successful! You can now close this window and refresh the post editor.', 'post-to-instagram' ),
                'auth_failed' => __( 'Authentication failed. Please try again or check your App ID/Secret.', 'post-to-instagram' ),
                'state_mismatch' => __( 'Authentication failed due to a state mismatch (possible CSRF attempt). Please try again.', 'post-to-instagram' ),
                'not_configured_for_auth' => __( 'App ID and Secret are not configured. Please save them before connecting.', 'post-to-instagram' ),
                'token_exchange_error' => __( 'Error during token exchange. Please try again.', 'post-to-instagram' ),
                'generic_auth_error' => __( 'An unknown authentication error occurred. Please try again.', 'post-to-instagram' ),
            ]
        ];
    }

    /**
     * Get OAuth redirect status from URL parameters.
     *
     * @return string|null Status string or null
     */
    private static function get_auth_redirect_status() {
        if ( isset( $_GET['pti_auth_success'] ) ) {
            return 'success';
        }
        if ( isset( $_GET['pti_auth_error'] ) ) {
            return sanitize_key( $_GET['pti_auth_error'] );
        }
        return null;
    }
}