<?php
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

class PTI_Auth_Handler {

    const OAUTH_REDIRECT_PARAM = 'pti_oauth_redirect';
    const OAUTH_STATE_NONCE_ACTION = 'pti_oauth_state';
    const INSTAGRAM_GRAPH_API_URL = 'https://graph.instagram.com';
    const INSTAGRAM_API_URL = 'https://api.instagram.com'; // For OAuth token exchange
    const REST_API_NAMESPACE = 'pti/v1';

    public function __construct() {
        add_action( 'init', array( $this, 'handle_oauth_redirect' ), 5 ); // Early hook for redirect handling
    }

    /**
     * Get authentication status data.
     * This method can be called by both AJAX and REST handlers.
     */
    private function get_auth_status_data() {
        return array(
            'is_configured'   => self::is_configured(),
            'is_authenticated'=> self::is_authenticated(),
            'auth_url'        => self::is_configured() && !self::is_authenticated() ? self::get_authorization_url() : '#',
            // Provide the nonce needed by the JS to perform other actions securely via admin-ajax.php
            // If all actions move to REST API, this specific nonce might be for a different REST endpoint.
            'nonce_auth_check' => wp_create_nonce('pti_auth_check_nonce'), // This is for the old AJAX, JS uses X-WP-Nonce for REST
            'ajax_url'        => admin_url('admin-ajax.php'), // Still useful if some actions remain AJAX
        );
    }

    /**
     * Check if App ID and Secret are configured.
     */
    public static function is_configured() {
        $app_id = pti_get_option( 'app_id' );
        $app_secret = pti_get_option( 'app_secret' );
        return ! empty( $app_id ) && ! empty( $app_secret );
    }

    /**
     * Check if the user is authenticated with Instagram.
     */
    public static function is_authenticated() {
        if ( ! self::is_configured() ) {
            return false;
        }
        $auth_details = pti_get_option( 'auth_details' );
        return ! empty( $auth_details['access_token'] ) && ! empty( $auth_details['user_id'] );
    }

    /**
     * Get the stored access token.
     */
    public static function get_access_token() {
        if ( ! self::is_authenticated() ) {
            return null;
        }
        $auth_details = pti_get_option( 'auth_details' );
        return $auth_details['access_token'];
    }

    /**
     * Get the stored Instagram User ID.
     */
    public static function get_instagram_user_id() {
        if ( ! self::is_authenticated() ) {
            return null;
        }
        $auth_details = pti_get_option( 'auth_details' );
        return $auth_details['user_id'];
    }

    /**
     * Generate the Instagram Authorization URL.
     */
    public static function get_authorization_url() {
        if ( ! self::is_configured() ) {
            return '#'; // Or some error indication
        }
        $app_id = pti_get_option( 'app_id' );
        $redirect_uri = self::get_redirect_uri();
        $state = wp_create_nonce( self::OAUTH_STATE_NONCE_ACTION );

        // Store state in session/transient for verification on redirect
        set_transient( 'pti_oauth_state_' . $state, $state, HOUR_IN_SECONDS );

        $auth_url = add_query_arg(
            array(
                'client_id'     => $app_id,
                'redirect_uri'  => $redirect_uri,
                'scope'         => implode(',', [
                    'instagram_business_basic',
                    'instagram_business_content_publish',
                    'instagram_business_manage_messages',
                    'instagram_business_manage_comments',
                    // 'instagram_business_manage_insights', // add if needed
                ]),
                'response_type' => 'code',
                'state'         => $state,
                'enable_fb_login' => '0',
                'force_authentication' => '1',
            ),
            'https://www.instagram.com/oauth/authorize'
        );
        return $auth_url;
    }

    /**
     * Get the OAuth redirect URI for this site.
     */
    public static function get_redirect_uri() {
        // Use a path-based URI for better compatibility with Instagram/Facebook OAuth
        return home_url( '/pti-oauth/' );
    }

    /**
     * Handle the OAuth redirect from Instagram.
     */
    public function handle_oauth_redirect() {
        // Only run if code and state are present
        if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
            return;
        }

        $code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
        $state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
        $stored_state = get_transient( 'pti_oauth_state_' . $state );

        // Verify state nonce
        if ( ! $stored_state || $stored_state !== $state || ! wp_verify_nonce( $state, self::OAUTH_STATE_NONCE_ACTION ) ) {
            // State mismatch or nonce verification failed, potential CSRF attack.
            // Log this event and redirect to an error page or the settings page with an error message.
            $handler_url = plugin_dir_url( __FILE__ ) . 'oauth-handler.html?pti_auth_status=error&pti_auth_error=state_mismatch';
            wp_redirect( $handler_url );
            exit;
        }
        delete_transient( 'pti_oauth_state_' . $state ); // Clean up used transient

        if ( ! self::is_configured() ) {
            $handler_url = plugin_dir_url( __FILE__ ) . 'oauth-handler.html?pti_auth_status=error&pti_auth_error=not_configured';
            wp_redirect( $handler_url );
            exit;
        }

        $app_id = pti_get_option( 'app_id' );
        $app_secret = pti_get_option( 'app_secret' );
        $redirect_uri = self::get_redirect_uri();

        // Exchange code for short-lived token
        $response = wp_remote_post(
            self::INSTAGRAM_API_URL . '/oauth/access_token',
            array(
                'method'    => 'POST',
                'timeout'   => 45,
                'body'      => array(
                    'client_id'     => $app_id,
                    'client_secret' => $app_secret,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => $redirect_uri,
                    'code'          => $code,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $handler_url = plugin_dir_url( __FILE__ ) . 'oauth-handler.html?pti_auth_status=error&pti_auth_error=token_exchange_failed';
            wp_redirect( $handler_url );
            exit;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( empty( $data['access_token'] ) || empty( $data['user_id'] ) ) {
            $handler_url = plugin_dir_url( __FILE__ ) . 'oauth-handler.html?pti_auth_status=error&pti_auth_error=short_token_missing';
            wp_redirect( $handler_url );
            exit;
        }

        $short_lived_token = $data['access_token'];
        $instagram_user_id = $data['user_id'];

        // Exchange short-lived token for long-lived token
        $long_lived_response = wp_remote_get(
            add_query_arg(
                array(
                    'grant_type'        => 'ig_exchange_token',
                    'client_secret'     => $app_secret,
                    'access_token'      => $short_lived_token,
                ),
                self::INSTAGRAM_GRAPH_API_URL . '/access_token'
            )
        );

        if ( is_wp_error( $long_lived_response ) ) {
            $handler_url = plugin_dir_url( __FILE__ ) . 'oauth-handler.html?pti_auth_status=error&pti_auth_error=long_token_failed';
            wp_redirect( $handler_url );
            exit;
        }

        $long_lived_body = wp_remote_retrieve_body( $long_lived_response );
        $long_lived_data = json_decode( $long_lived_body, true );

        if ( empty( $long_lived_data['access_token'] ) ) {
            $handler_url = plugin_dir_url( __FILE__ ) . 'oauth-handler.html?pti_auth_status=error&pti_auth_error=long_token_missing';
            wp_redirect( $handler_url );
            exit;
        }

        $long_lived_token = $long_lived_data['access_token'];
        // $token_type = $long_lived_data['token_type']; // Typically 'bearer'
        // $expires_in = $long_lived_data['expires_in']; // Store this for refresh logic later

        // Fetch the Instagram username (account name) using the Graph API
        $username = null;
        $user_info_resp = wp_remote_get(
            self::INSTAGRAM_GRAPH_API_URL . "/{$instagram_user_id}?fields=username&access_token={$long_lived_token}",
            array('timeout' => 20)
        );
        if (!is_wp_error($user_info_resp)) {
            $user_info_body = json_decode(wp_remote_retrieve_body($user_info_resp), true);
            if (!empty($user_info_body['username'])) {
                $username = $user_info_body['username'];
            }
        }

        // Store the long-lived token, user ID, and username
        pti_update_option( 'auth_details', array(
            'access_token' => $long_lived_token,
            'user_id'      => $instagram_user_id,
            'username'     => $username,
            // 'expires_at' => time() + $expires_in, // For future refresh logic
        ) );

        // Redirect to the handler page for postMessage communication
        $handler_url = plugin_dir_url( __FILE__ ) . 'oauth-handler.html?pti_auth_status=success';
        wp_redirect( $handler_url );
        exit;
    }
} 