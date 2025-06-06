<?php
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class PTI_REST_API {
    const REST_API_NAMESPACE = 'pti/v1';

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route(
            self::REST_API_NAMESPACE,
            '/auth/status',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_auth_status' ),
                'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            )
        );
        register_rest_route(
            self::REST_API_NAMESPACE,
            '/auth/credentials',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'save_app_credentials' ),
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
                'args'                => array(
                    'app_id' => array(
                        'required' => true,
                        'type' => 'string',
                    ),
                    'app_secret' => array(
                        'required' => true,
                        'type' => 'string',
                    ),
                ),
            )
        );
        register_rest_route(
            self::REST_API_NAMESPACE,
            '/post-now',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_post_now_proxy' ),
                'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
                'args'                => array(
                    'post_id' => array(
                        'required' => true,
                        'type' => 'integer',
                    ),
                    'image_urls' => array(
                        'required' => true,
                        'type' => 'array',
                        'items' => array('type' => 'string'),
                    ),
                    'image_ids' => array(
                        'required' => true,
                        'type' => 'array',
                        'items' => array('type' => 'integer'),
                    ),
                    'caption' => array(
                        'required' => false,
                        'type' => 'string',
                    ),
                    '_wpnonce' => array(
                        'required' => true,
                        'type' => 'string',
                    ),
                ),
            )
        );
        register_rest_route(
            self::REST_API_NAMESPACE,
            '/disconnect',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'disconnect_account' ),
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
                'args'                => array(
                    '_wpnonce' => array(
                        'required' => true,
                        'type' => 'string',
                    ),
                ),
            )
        );
        // Future endpoints (posting, scheduling) will be registered here.

        // New endpoint for uploading cropped images
        register_rest_route(
            self::REST_API_NAMESPACE,
            '/upload-cropped-image',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_upload_cropped_image' ),
                'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            )
        );
    }

    public function get_auth_status( $request ) {
        if ( ! class_exists( 'PTI_Auth_Handler' ) ) {
            return new WP_Error( 'pti_auth_handler_missing', __( 'Auth handler not loaded.', 'post-to-instagram' ), array( 'status' => 500 ) );
        }
        $username = null;
        $auth_details = pti_get_option('auth_details');
        if (isset($auth_details['username'])) {
            $username = $auth_details['username'];
        }
        return new WP_REST_Response( array(
            'is_configured'    => PTI_Auth_Handler::is_configured(),
            'is_authenticated' => PTI_Auth_Handler::is_authenticated(),
            'auth_url'         => PTI_Auth_Handler::is_configured() && !PTI_Auth_Handler::is_authenticated() ? PTI_Auth_Handler::get_authorization_url() : '#',
            'app_id'           => pti_get_option('app_id'),
            'username'         => $username,
        ), 200 );
    }

    public function save_app_credentials( $request ) {
        $app_id = sanitize_text_field( $request['app_id'] );
        $app_secret = isset($request['app_secret']) ? sanitize_text_field( $request['app_secret'] ) : '';
        if ( empty( $app_id ) ) {
            return new WP_Error( 'pti_missing_creds', __( 'App ID is required.', 'post-to-instagram' ), array( 'status' => 400 ) );
        }
        pti_update_option( 'app_id', $app_id );
        if ( ! empty( $app_secret ) ) {
        pti_update_option( 'app_secret', $app_secret );
        }
        pti_update_option( 'auth_details', array() ); // Clear old auth details
        return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Credentials saved.', 'post-to-instagram' ) ), 200 );
    }

    public function disconnect_account( $request ) {
        if ( ! wp_verify_nonce( $request['_wpnonce'], 'pti_disconnect_nonce' ) ) {
            return new WP_Error( 'invalid_nonce', __( 'Invalid nonce.', 'post-to-instagram' ), array( 'status' => 403 ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', __( 'You do not have permission to disconnect this account.', 'post-to-instagram' ), array( 'status' => 403 ) );
        }
        pti_update_option( 'auth_details', array() );
        return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Account disconnected successfully.', 'post-to-instagram' ) ), 200 );
    }

    // Method to handle cropped image uploads
    public function handle_upload_cropped_image( WP_REST_Request $request ) {
        if ( ! isset( $_FILES['cropped_image'] ) ) {
            return new WP_Error(
                'pti_missing_file',
                __( 'No image file found in request.', 'post-to-instagram' ),
                array( 'status' => 400 )
            );
        }

        $file = $_FILES['cropped_image'];

        // WordPress file upload handling
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Create pti-temp directory if it doesn't exist
        $wp_upload_dir = wp_upload_dir();
        $temp_dir_path = $wp_upload_dir['basedir'] . '/pti-temp';
        $temp_dir_url = $wp_upload_dir['baseurl'] . '/pti-temp';

        if ( ! file_exists( $temp_dir_path ) ) {
            wp_mkdir_p( $temp_dir_path );
            // Add an index.html file to prevent directory listing if server is misconfigured
            if ( ! file_exists( $temp_dir_path . '/index.html' ) ) {
                @file_put_contents( $temp_dir_path . '/index.html', '<!DOCTYPE html><html><head><title>Forbidden</title></head><body><p>Directory access is forbidden.</p></body></html>' );
            }
            // Optionally, add .htaccess to deny direct listing if on Apache
            // if ( ! file_exists( $temp_dir_path . '/.htaccess' ) ) {
            //    @file_put_contents( $temp_dir_path . '/.htaccess', "Options -Indexes\ndeny from all" );
            // }
        }

        // Override the uploads dir for this one operation
        $upload_overrides = array( 
            'test_form' => false, 
            'action' => 'wp_handle_sideload', // Using sideload for handling raw file data from JS
            'unique_filename_callback' => function( $dir, $name, $ext ) {
                // Generate a more unique name to avoid collisions and make it less guessable
                return 'cropped-' . uniqid() . '-' . sanitize_file_name( $name );
            }
        );

        // Move the uploaded file to the pti-temp directory
        // wp_handle_upload expects the file data in $_FILES format.
        // To use wp_handle_sideload, we need to pass the file path after it's temporarily saved, or its contents.
        // For files sent via FormData from JS, $_FILES should be populated directly.

        // Define custom upload directory for this operation
        add_filter( 'upload_dir', array( $this, 'custom_temp_upload_dir' ) );
        $moved_file = wp_handle_upload( $file, $upload_overrides );
        remove_filter( 'upload_dir', array( $this, 'custom_temp_upload_dir' ) );

        if ( $moved_file && ! isset( $moved_file['error'] ) ) {
            // $moved_file contains 'file' (path) and 'url'
            return new WP_REST_Response( array(
                'success' => true,
                'message' => __( 'Image cropped and saved temporarily.', 'post-to-instagram' ),
                'url' => $moved_file['url'],
                'file_path' => $moved_file['file'] // For potential server-side use or cleanup
            ), 200 );
        } else {
            return new WP_Error(
                'pti_upload_error',
                isset( $moved_file['error'] ) ? $moved_file['error'] : __( 'Error saving cropped image.', 'post-to-instagram' ),
                array( 'status' => 500 )
            );
        }
    }

    // Helper to change upload directory temporarily
    public function custom_temp_upload_dir( $param ){
        $mydir = '/pti-temp';
    
        $param['path'] = $param['basedir'] . $mydir;
        $param['url']  = $param['baseurl'] . $mydir;
    
        return $param;
    }

    // This is a proxy to the (new) actual posting logic, perhaps in the main plugin file or a dedicated class
    public function handle_post_now_proxy( WP_REST_Request $request ) {
        $post_id = $request->get_param( 'post_id' );
        $image_urls = $request->get_param( 'image_urls' );
        $image_ids = $request->get_param( 'image_ids' );
        $caption = $request->get_param( 'caption' );

        if ( empty( $post_id ) || empty( $image_urls ) || empty( $image_ids ) ) {
            return new WP_Error(
                'pti_missing_params',
                __( 'Missing post ID, image URLs, or image IDs.', 'post-to-instagram' ),
                array( 'status' => 400 )
            );
        }

        // Validate URLs (basic validation)
        foreach ($image_urls as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
                return new WP_Error(
                    'pti_invalid_url',
                    sprintf(__( 'Invalid image URL provided: %s', 'post-to-instagram' ), esc_url($url)),
                    array( 'status' => 400 )
                );
            }
        }

        // Call the actual Instagram posting logic using URLs
        if ( class_exists('PTI_Instagram_API') ) {
            $result = PTI_Instagram_API::post_now_with_urls( $post_id, $image_urls, $caption, $image_ids );
            if ( isset($result['success']) && $result['success'] ) {
                return new WP_REST_Response( array(
                    'success' => true,
                    'message' => $result['message'],
                    'permalink' => isset($result['permalink']) ? $result['permalink'] : null,
                    'media_id' => isset($result['media_id']) ? $result['media_id'] : null,
                    'warning' => isset($result['warning']) ? $result['warning'] : null,
                    'response' => isset($result['response']) ? $result['response'] : null,
                ), 200 );
            } else {
                return new WP_Error('pti_instagram_error', $result['message'] ?? 'Failed to post to Instagram.', array('status' => 500));
            }
        } else {
            return new WP_Error('pti_api_class_missing', 'PTI_Instagram_API class not found.', array('status' => 500));
        }
    }
} 