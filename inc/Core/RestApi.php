<?php
/**
 * Instagram REST API Integration
 *
 * Centralized REST API endpoint management for Instagram integration.
 * Provides secure endpoints for authentication, image upload, posting, and scheduling
 * using action-based architecture patterns.
 *
 * @package PostToInstagram\Core
 */

namespace PostToInstagram\Core;

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * RestApi Class
 *
 * Complete REST API endpoint system via WordPress action hooks.
 */
class RestApi {

    const REST_API_NAMESPACE = 'pti/v1';

    /**
     * Register all REST API endpoints.
     */
    public static function register() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    /**
     * Register all REST API routes.
     */
    public static function register_routes() {
        // Authentication endpoints
        register_rest_route(
            self::REST_API_NAMESPACE,
            '/auth/status',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'get_auth_status' ],
                'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            )
        );

        register_rest_route(
            self::REST_API_NAMESPACE,
            '/auth/credentials',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'save_app_credentials' ],
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
            '/disconnect',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'disconnect_account' ],
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
                'args'                => array(
                    '_wpnonce' => array(
                        'required' => true,
                        'type' => 'string',
                    ),
                ),
            )
        );

        // Posting endpoints
        register_rest_route(
            self::REST_API_NAMESPACE,
            '/post-now',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'handle_post_now_proxy' ],
                'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
                'args'                => array(
                    'post_id' => array(
                        'required' => true,
                        'type' => 'integer',
                    ),
                    'image_urls' => array(
                        'required' => true,
                        'type' => 'array',
                        'items' => array( 'type' => 'string' ),
                    ),
                    'image_ids' => array(
                        'required' => true,
                        'type' => 'array',
                        'items' => array( 'type' => 'integer' ),
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

        // Upload endpoint
        register_rest_route(
            self::REST_API_NAMESPACE,
            '/upload-cropped-image',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'handle_upload_cropped_image' ],
                'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
            )
        );

        // Scheduling endpoints
        register_rest_route(
            self::REST_API_NAMESPACE,
            '/schedule-post',
            array(
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'handle_schedule_post' ],
                'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
                'args'                => array(
                    'post_id' => array(
                        'required' => true,
                        'type' => 'integer',
                    ),
                    'image_ids' => array(
                        'required' => true,
                        'type' => 'array',
                        'items' => array( 'type' => 'integer' ),
                    ),
                    'crop_data' => array(
                        'required' => true,
                        'type' => 'array'
                    ),
                    'caption' => array(
                        'required' => false,
                        'type' => 'string',
                    ),
                    'schedule_time' => array(
                        'required' => true,
                        'type' => 'string',
                    ),
                ),
            )
        );

        register_rest_route(
            self::REST_API_NAMESPACE,
            '/scheduled-posts',
            array(
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'handle_get_scheduled_posts' ],
                'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
                'args'                => array(
                    'post_id' => array(
                        'required' => false,
                        'type' => 'integer',
                    ),
                ),
            )
        );
    }

    /**
     * Get Instagram authentication status.
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Authentication status data
     */
    public static function get_auth_status( $request ) {
        $username = null;
        $options = get_option( 'pti_settings' );
        $auth_details = isset( $options['auth_details'] ) ? $options['auth_details'] : null;
        if ( isset( $auth_details['username'] ) ) {
            $username = $auth_details['username'];
        }
        return new \WP_REST_Response( array(
            'is_configured'    => Auth::is_configured(),
            'is_authenticated' => Auth::is_authenticated(),
            'auth_url'         => Auth::is_configured() && ! Auth::is_authenticated() ? Auth::get_authorization_url() : '#',
            'app_id'           => isset( $options['app_id'] ) ? $options['app_id'] : '',
            'username'         => $username,
        ), 200 );
    }

    /**
     * Save Instagram app credentials.
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error Response or error
     */
    public static function save_app_credentials( $request ) {
        $app_id = sanitize_text_field( $request['app_id'] );
        $app_secret = isset( $request['app_secret'] ) ? sanitize_text_field( $request['app_secret'] ) : '';
        if ( empty( $app_id ) ) {
            return new \WP_Error( 'pti_missing_creds', __( 'App ID is required.', 'post-to-instagram' ), array( 'status' => 400 ) );
        }
        $options = get_option( 'pti_settings', array() );
        $options['app_id'] = $app_id;
        update_option( 'pti_settings', $options );
        if ( ! empty( $app_secret ) ) {
            $options = get_option( 'pti_settings', array() );
            $options['app_secret'] = $app_secret;
            update_option( 'pti_settings', $options );
        }
        $options = get_option( 'pti_settings', array() );
        $options['auth_details'] = array(); // Clear old auth details
        update_option( 'pti_settings', $options );
        return new \WP_REST_Response( array( 'success' => true, 'message' => __( 'Credentials saved.', 'post-to-instagram' ) ), 200 );
    }

    /**
     * Disconnect Instagram account.
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error Response or error
     */
    public static function disconnect_account( $request ) {
        if ( ! wp_verify_nonce( $request['_wpnonce'], 'pti_disconnect_nonce' ) ) {
            return new \WP_Error( 'invalid_nonce', __( 'Invalid nonce.', 'post-to-instagram' ), array( 'status' => 403 ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( 'forbidden', __( 'You do not have permission to disconnect this account.', 'post-to-instagram' ), array( 'status' => 403 ) );
        }
        $options = get_option( 'pti_settings', array() );
        $options['auth_details'] = array();
        update_option( 'pti_settings', $options );
        return new \WP_REST_Response( array( 'success' => true, 'message' => __( 'Account disconnected successfully.', 'post-to-instagram' ) ), 200 );
    }

    /**
     * Handle immediate Instagram posting via action system.
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error Response or error
     */
    public static function handle_post_now_proxy( $request ) {
        $post_id = $request->get_param( 'post_id' );
        $image_urls = $request->get_param( 'image_urls' );
        $image_ids = $request->get_param( 'image_ids' );
        $caption = $request->get_param( 'caption' );

        if ( empty( $post_id ) || empty( $image_urls ) || empty( $image_ids ) ) {
            return new \WP_Error(
                'pti_missing_params',
                __( 'Missing post ID, image URLs, or image IDs.', 'post-to-instagram' ),
                array( 'status' => 400 )
            );
        }

        // Validate URLs (basic validation)
        foreach ( $image_urls as $url ) {
            if ( filter_var( $url, FILTER_VALIDATE_URL ) === FALSE ) {
                return new \WP_Error(
                    'pti_invalid_url',
                    sprintf( __( 'Invalid image URL provided: %s', 'post-to-instagram' ), esc_url( $url ) ),
                    array( 'status' => 400 )
                );
            }
        }

        // Set up result containers
        $result = null;
        $error = null;

        // Set up event listeners for success/error
        $success_handler = function( $success_result ) use ( &$result ) {
            $result = $success_result;
        };
        $error_handler = function( $error_result ) use ( &$error ) {
            $error = $error_result;
        };

        add_action( 'pti_post_success', $success_handler );
        add_action( 'pti_post_error', $error_handler );

        // Trigger the posting action
        do_action( 'pti_post_to_instagram', [
            'post_id' => $post_id,
            'image_urls' => $image_urls,
            'caption' => $caption,
            'image_ids' => $image_ids
        ] );

        // Clean up event listeners
        remove_action( 'pti_post_success', $success_handler );
        remove_action( 'pti_post_error', $error_handler );

        // Return response based on results
        if ( $result ) {
            return new \WP_REST_Response( [
                'success' => true,
                'message' => $result['message'],
                'permalink' => isset( $result['permalink'] ) ? $result['permalink'] : null,
                'media_id' => isset( $result['media_id'] ) ? $result['media_id'] : null,
                'warning' => isset( $result['warning'] ) ? $result['warning'] : null,
                'response' => isset( $result['response'] ) ? $result['response'] : null,
            ], 200 );
        } elseif ( $error ) {
            return new \WP_Error( 'pti_instagram_error', $error['message'] ?? 'Failed to post to Instagram.', [ 'status' => 500 ] );
        } else {
            return new \WP_Error( 'pti_no_response', 'No response from posting action.', [ 'status' => 500 ] );
        }
    }

    /**
     * Handle cropped image uploads.
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error Response or error
     */
    public static function handle_upload_cropped_image( $request ) {
        if ( ! isset( $_FILES['cropped_image'] ) ) {
            return new \WP_Error(
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

        if ( ! file_exists( $temp_dir_path ) ) {
            wp_mkdir_p( $temp_dir_path );
            // Add an index.html file to prevent directory listing if server is misconfigured
            if ( ! file_exists( $temp_dir_path . '/index.html' ) ) {
                @file_put_contents( $temp_dir_path . '/index.html', '<!DOCTYPE html><html><head><title>Forbidden</title></head><body><p>Directory access is forbidden.</p></body></html>' );
            }
        }

        // Override the uploads dir for this one operation
        $upload_overrides = array(
            'test_form' => false,
            'action' => 'wp_handle_sideload',
            'unique_filename_callback' => function( $dir, $name, $ext ) {
                return 'cropped-' . uniqid() . '-' . sanitize_file_name( $name );
            }
        );

        // Define custom upload directory for this operation
        add_filter( 'upload_dir', [ __CLASS__, 'custom_temp_upload_dir' ] );
        $moved_file = wp_handle_upload( $file, $upload_overrides );
        remove_filter( 'upload_dir', [ __CLASS__, 'custom_temp_upload_dir' ] );

        if ( $moved_file && ! isset( $moved_file['error'] ) ) {
            return new \WP_REST_Response( array(
                'success' => true,
                'message' => __( 'Image cropped and saved temporarily.', 'post-to-instagram' ),
                'url' => $moved_file['url'],
                'file_path' => $moved_file['file']
            ), 200 );
        } else {
            return new \WP_Error(
                'pti_upload_error',
                isset( $moved_file['error'] ) ? $moved_file['error'] : __( 'Error saving cropped image.', 'post-to-instagram' ),
                array( 'status' => 500 )
            );
        }
    }

    /**
     * Helper to change upload directory temporarily.
     *
     * @param array $param Upload directory parameters
     * @return array Modified parameters
     */
    public static function custom_temp_upload_dir( $param ) {
        $mydir = '/pti-temp';
        $param['path'] = $param['basedir'] . $mydir;
        $param['url']  = $param['baseurl'] . $mydir;
        return $param;
    }

    /**
     * Handle post scheduling via action system.
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error Response or error
     */
    public static function handle_schedule_post( $request ) {
        $post_id = $request->get_param( 'post_id' );
        $params = $request->get_params();

        // Validate required parameters
        if ( empty( $post_id ) || empty( $params['image_ids'] ) || empty( $params['schedule_time'] ) ) {
            return new \WP_Error(
                'pti_missing_params',
                __( 'Missing required parameters: post_id, image_ids, or schedule_time.', 'post-to-instagram' ),
                array( 'status' => 400 )
            );
        }

        // Set up result containers
        $result = null;
        $error = null;

        // Set up event listeners for success/error
        $success_handler = function( $success_result ) use ( &$result ) {
            $result = $success_result;
        };
        $error_handler = function( $error_result ) use ( &$error ) {
            $error = $error_result;
        };

        add_action( 'pti_schedule_success', $success_handler );
        add_action( 'pti_schedule_error', $error_handler );

        // Trigger the scheduling action
        do_action( 'pti_schedule_instagram_post', [
            'post_id' => $post_id,
            'image_ids' => $params['image_ids'],
            'crop_data' => $params['crop_data'] ?? [],
            'caption' => $params['caption'] ?? '',
            'schedule_time' => $params['schedule_time']
        ] );

        // Clean up event listeners
        remove_action( 'pti_schedule_success', $success_handler );
        remove_action( 'pti_schedule_error', $error_handler );

        // Return response based on results
        if ( $result ) {
            return new \WP_REST_Response( [
                'success' => true,
                'message' => $result['message'],
                'scheduled_post' => isset( $result['scheduled_post'] ) ? $result['scheduled_post'] : null,
            ], 200 );
        } elseif ( $error ) {
            return new \WP_Error( 'pti_schedule_error', $error['message'] ?? 'Failed to schedule post.', [ 'status' => 500 ] );
        } else {
            return new \WP_Error( 'pti_no_response', 'No response from scheduling action.', [ 'status' => 500 ] );
        }
    }

    /**
     * Get scheduled posts for a post or all posts.
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response Response with scheduled posts
     */
    public static function handle_get_scheduled_posts( $request ) {
        $post_id = $request->get_param( 'post_id' );

        if ( ! empty( $post_id ) ) {
            // Get scheduled posts for a specific post
            $scheduled_posts = get_post_meta( $post_id, '_pti_instagram_scheduled_posts', true );
            if ( ! is_array( $scheduled_posts ) ) {
                $scheduled_posts = array();
            }
            return new \WP_REST_Response( $scheduled_posts, 200 );
        } else {
            // Get all scheduled posts across all WP posts
            global $wpdb;
            $meta_key = '_pti_instagram_scheduled_posts';
            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = %s",
                $meta_key
            ) );

            $all_scheduled_posts = array();
            foreach ( $results as $result ) {
                $posts = maybe_unserialize( $result->meta_value );
                if ( is_array( $posts ) ) {
                    foreach ( $posts as &$post ) {
                        $post['parent_post_id'] = $result->post_id;
                    }
                    $all_scheduled_posts = array_merge( $all_scheduled_posts, $posts );
                }
            }
            return new \WP_REST_Response( $all_scheduled_posts, 200 );
        }
    }
}