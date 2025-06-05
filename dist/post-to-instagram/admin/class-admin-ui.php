<?php
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class PTI_Admin_UI {

    public function __construct() {
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
        // For Classic Editor, you might use a different hook like 'add_meta_boxes'
        // and then enqueue scripts via 'admin_enqueue_scripts' with a check for the current screen.
    }

    public function enqueue_editor_assets() {
        global $pagenow;

        // Only load on post edit screens (new or existing)
        if ( ! ( $pagenow == 'post.php' || $pagenow == 'post-new.php' ) ) {
            return;
        }

        // Enqueue main script
        $script_asset_path = PTI_PLUGIN_DIR . 'admin/assets/js/post-editor.asset.php';
        $script_asset = file_exists( $script_asset_path )
            ? require( $script_asset_path )
            : array( 'dependencies' => array('wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-hooks', 'wp-api-fetch', 'wp-media-utils', 'wp-i18n'), 'version' => filemtime( PTI_PLUGIN_DIR . 'admin/assets/js/post-editor.js' ) );

        wp_enqueue_script(
            'pti-post-editor-script',
            PTI_PLUGIN_URL . 'admin/assets/js/post-editor.js',
            $script_asset['dependencies'],
            $script_asset['version']
        );

        // Ensure all media scripts (including gallery) are loaded
        if ( function_exists( 'wp_enqueue_media' ) ) {
            wp_enqueue_media();
        }
        wp_enqueue_script('media-gallery');

        // Enqueue styles
        wp_enqueue_style(
            'pti-admin-styles',
            PTI_PLUGIN_URL . 'admin/assets/css/admin-styles.css',
            array(),
            filemtime( PTI_PLUGIN_DIR . 'admin/assets/css/admin-styles.css' )
        );

        // Collect all image IDs used in the post content and featured image
        $post_id = get_the_ID();
        $image_ids = array();
        if ( $post_id ) {
            $post = get_post( $post_id );
            if ( $post ) {
                // Featured image
                $featured_id = get_post_thumbnail_id( $post_id );
                if ( $featured_id ) {
                    $image_ids[] = $featured_id;
                }
                // Parse Gutenberg image blocks
                if ( has_blocks( $post->post_content ) ) {
                    $blocks = parse_blocks( $post->post_content );
                    foreach ( $blocks as $block ) {
                        if ( $block['blockName'] === 'core/image' && !empty($block['attrs']['id']) ) {
                            $image_ids[] = $block['attrs']['id'];
                        }
                        // Gallery block
                        if ( $block['blockName'] === 'core/gallery' && !empty($block['attrs']['ids']) && is_array($block['attrs']['ids']) ) {
                            $image_ids = array_merge($image_ids, $block['attrs']['ids']);
                        }
                    }
                }
                // Fallback: parse <img> tags for classic editor
                if ( preg_match_all( '/wp-image-([0-9]+)/', $post->post_content, $matches ) ) {
                    $image_ids = array_merge( $image_ids, $matches[1] );
                }
            }
        }
        $image_ids = array_unique( array_map( 'intval', $image_ids ) );

        // Get shared image IDs for this post
        $shared_meta = get_post_meta($post_id, '_pti_instagram_shared_images', true);
        $shared_image_ids = array();
        if (is_array($shared_meta)) {
            foreach ($shared_meta as $item) {
                if (isset($item['attachment_id'])) {
                    $shared_image_ids[] = intval($item['attachment_id']);
                }
            }
        }

        // Localize script with data
        $localized_data = array(
            'ajax_url'                  => admin_url( 'admin-ajax.php' ),
            'nonce_save_app_creds'      => wp_create_nonce( 'pti_save_app_creds_nonce' ),
            'nonce_auth_check'          => wp_create_nonce( 'pti_auth_check_nonce' ),
            'nonce_disconnect'          => wp_create_nonce( 'pti_disconnect_nonce' ),
            'nonce_post_media'          => wp_create_nonce( 'pti_post_media_nonce' ),
            'nonce_schedule_media'      => wp_create_nonce( 'pti_schedule_media_nonce' ), // To be used later
            'is_configured'             => PTI_Auth_Handler::is_configured(),
            'is_authenticated'          => PTI_Auth_Handler::is_authenticated(),
            'auth_url'                  => PTI_Auth_Handler::is_configured() && !PTI_Auth_Handler::is_authenticated() ? PTI_Auth_Handler::get_authorization_url() : '#',
            'admin_url'                 => admin_url(),
            'i18n'                      => array(
                'post_to_instagram'         => __( 'Post to Instagram', 'post-to-instagram' ),
                'settings_title'            => __( 'Instagram Configuration', 'post-to-instagram' ),
                'app_id_label'              => __( 'Instagram App ID', 'post-to-instagram' ),
                'app_secret_label'          => __( 'Instagram App Secret', 'post-to-instagram' ),
                'save_credentials'          => __( 'Save Credentials', 'post-to-instagram' ),
                'connect_instagram'         => __( 'Connect to Instagram', 'post-to-instagram' ),
                'disconnect_instagram'      => __( 'Disconnect Instagram Account', 'post-to-instagram' ),
                'select_images'             => __( 'Select Images for Instagram', 'post-to-instagram' ),
                'add_caption'               => __( 'Add Caption', 'post-to-instagram' ),
                'reorder_images'            => __( 'Reorder Images', 'post-to-instagram' ),
                'post_now'                  => __( 'Post Now', 'post-to-instagram' ),
                'schedule_post'             => __( 'Schedule Post', 'post-to-instagram' ),
                'max_images_reached'        => sprintf(__( 'You can select a maximum of %d images.', 'post-to-instagram' ), 10),
                'error_saving_creds'        => __( 'Error saving credentials. Please try again.', 'post-to-instagram' ),
                'creds_saved'               => __( 'Credentials saved.', 'post-to-instagram' ),
                'error_disconnecting'       => __( 'Error disconnecting account. Please try again.', 'post-to-instagram' ),
                'disconnected'              => __( 'Account disconnected.', 'post-to-instagram' ),
                'checking_auth'             => __( 'Checking authentication status...', 'post-to-instagram' ),
                'auth_successful'           => __( 'Authentication successful! You can now close this window and refresh the post editor.', 'post-to-instagram' ),
                'auth_failed'               => __( 'Authentication failed. Please try again or check your App ID/Secret.', 'post-to-instagram' ),
                'state_mismatch'            => __( 'Authentication failed due to a state mismatch (possible CSRF attempt). Please try again.', 'post-to-instagram' ),
                'not_configured_for_auth'   => __( 'App ID and Secret are not configured. Please save them before connecting.', 'post-to-instagram' ), 
                'token_exchange_error'      => __( 'Error during token exchange. Please try again.', 'post-to-instagram' ),
                'generic_auth_error'        => __( 'An unknown authentication error occurred. Please try again.', 'post-to-instagram' ),
                // Add more strings as needed
            ),
            // Pass any auth error/success messages from URL query params if present from OAuth redirect
            'auth_redirect_status' => isset($_GET['pti_auth_success']) ? 'success' : (isset($_GET['pti_auth_error']) ? sanitize_key($_GET['pti_auth_error']) : null),
            'post_id'                   => $post_id,
            'content_image_ids'         => $image_ids,
            'shared_image_ids'          => $shared_image_ids,
        );

        wp_localize_script( 'pti-post-editor-script', 'pti_data', $localized_data );

        // This will register our Gutenberg plugin sidebar
        // The actual sidebar component will be in post-editor.js
    }
} 