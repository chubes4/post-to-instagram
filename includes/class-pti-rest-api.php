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
                'callback'            => array( $this, 'post_now' ),
                'permission_callback' => function() { return current_user_can( 'edit_posts' ); },
                'args'                => array(
                    'post_id' => array(
                        'required' => true,
                        'type' => 'integer',
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
    }

    public function get_auth_status( $request ) {
        if ( ! class_exists( 'PTI_Auth_Handler' ) ) {
            return new WP_Error( 'pti_auth_handler_missing', __( 'Auth handler not loaded.', 'post-to-instagram' ), array( 'status' => 500 ) );
        }
        return new WP_REST_Response( array(
            'is_configured'    => PTI_Auth_Handler::is_configured(),
            'is_authenticated' => PTI_Auth_Handler::is_authenticated(),
            'auth_url'         => PTI_Auth_Handler::is_configured() && !PTI_Auth_Handler::is_authenticated() ? PTI_Auth_Handler::get_authorization_url() : '#',
            'app_id'           => pti_get_option('app_id'),
        ), 200 );
    }

    public function save_app_credentials( $request ) {
        $app_id = sanitize_text_field( $request['app_id'] );
        $app_secret = sanitize_text_field( $request['app_secret'] );
        if ( empty( $app_id ) || empty( $app_secret ) ) {
            return new WP_Error( 'pti_missing_creds', __( 'App ID and App Secret are required.', 'post-to-instagram' ), array( 'status' => 400 ) );
        }
        pti_update_option( 'app_id', $app_id );
        pti_update_option( 'app_secret', $app_secret );
        pti_update_option( 'auth_details', array() ); // Clear old auth details
        return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Credentials saved.', 'post-to-instagram' ) ), 200 );
    }

    public function post_now( $request ) {
        // Nonce check
        if ( ! wp_verify_nonce( $request['_wpnonce'], 'pti_post_media_nonce' ) ) {
            return new WP_Error( 'invalid_nonce', __( 'Invalid nonce.', 'post-to-instagram' ), array( 'status' => 403 ) );
        }
        // Permission check
        if ( ! current_user_can( 'edit_posts' ) ) {
            return new WP_Error( 'forbidden', __( 'You do not have permission to post.', 'post-to-instagram' ), array( 'status' => 403 ) );
        }
        $post_id = intval( $request['post_id'] );
        $image_ids = array_map( 'intval', (array) $request['image_ids'] );
        $caption = sanitize_text_field( $request['caption'] );
        // Call Instagram posting logic
        if ( ! class_exists( 'PTI_Instagram_API' ) ) {
            require_once PTI_PLUGIN_DIR . 'includes/class-instagram-api.php';
        }
        $result = PTI_Instagram_API::post_now( $post_id, $image_ids, $caption );
        if ( isset($result['success']) && $result['success'] ) {
            return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Posted to Instagram successfully.', 'post-to-instagram' ) ), 200 );
        }
        return new WP_REST_Response( array( 'success' => false, 'message' => isset($result['message']) ? $result['message'] : __( 'Failed to post to Instagram.', 'post-to-instagram' ) ), 500 );
    }

    public function disconnect_account( $request ) {
        if ( ! wp_verify_nonce( $request['_wpnonce'], 'pti_disconnect_nonce' ) ) {
            return new WP_Error( 'invalid_nonce', __( 'Invalid nonce.', 'post-to-instagram' ), array( 'status' => 403 ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', __( 'You do not have permission to disconnect this account.', 'post-to-instagram' ), array( 'status' => 403 ) );
        }
        pti_update_option( 'app_id', '' );
        pti_update_option( 'app_secret', '' );
        pti_update_option( 'auth_details', array() );
        return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Account disconnected successfully.', 'post-to-instagram' ) ), 200 );
    }
} 