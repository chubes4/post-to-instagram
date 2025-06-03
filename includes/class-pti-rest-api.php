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
} 