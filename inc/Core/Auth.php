<?php
/**
 * Instagram OAuth Authentication
 *
 * Centralized Instagram OAuth 2.0 authentication management. Handles OAuth flow,
 * token exchange, and authentication state using utility class pattern.
 * Manages both short-lived and long-lived access tokens with CSRF protection.
 *
 * @package PostToInstagram\Core
 */

namespace PostToInstagram\Core;

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Auth Class
 *
 * Complete Instagram OAuth authentication system.
 */
class Auth {

    const OAUTH_REDIRECT_PARAM = 'pti_oauth_redirect';
    const OAUTH_STATE_NONCE_ACTION = 'pti_oauth_state';
    const INSTAGRAM_GRAPH_API_URL = 'https://graph.instagram.com';
    const INSTAGRAM_API_URL = 'https://api.instagram.com';

    /**
     * Check if Instagram App ID and Secret are configured.
     *
     * @return bool True if both app_id and app_secret are set
     */
    public static function is_configured() {
        $options = get_option( 'pti_settings' );
        $app_id = isset( $options['app_id'] ) ? $options['app_id'] : '';
        $app_secret = isset( $options['app_secret'] ) ? $options['app_secret'] : '';
        return ! empty( $app_id ) && ! empty( $app_secret );
    }

    /**
     * Check if user has valid Instagram authentication.
     *
     * @return bool True if access_token and user_id exist
     */
    public static function is_authenticated() {
        if ( ! self::is_configured() ) {
            return false;
        }
        $options = get_option( 'pti_settings' );
        $auth_details = isset( $options['auth_details'] ) ? $options['auth_details'] : array();
        return ! empty( $auth_details['access_token'] ) && ! empty( $auth_details['user_id'] );
    }

    /**
     * Get Instagram long-lived access token.
     *
     * @return string|null Access token or null if not authenticated
     */
    public static function get_access_token() {
        if ( ! self::is_authenticated() ) {
            return null;
        }
        $options = get_option( 'pti_settings' );
        $auth_details = isset( $options['auth_details'] ) ? $options['auth_details'] : array();
        return $auth_details['access_token'];
    }

    /**
     * Get Instagram user ID.
     *
     * @return string|null Instagram user ID or null if not authenticated
     */
    public static function get_instagram_user_id() {
        if ( ! self::is_authenticated() ) {
            return null;
        }
        $options = get_option( 'pti_settings' );
        $auth_details = isset( $options['auth_details'] ) ? $options['auth_details'] : array();
        return $auth_details['user_id'];
    }

    /**
     * Generate Instagram OAuth authorization URL with CSRF protection.
     *
     * @return string Authorization URL or '#' if not configured
     */
    public static function get_authorization_url() {
        if ( ! self::is_configured() ) {
            return '#';
        }
        $options = get_option( 'pti_settings' );
        $app_id = isset( $options['app_id'] ) ? $options['app_id'] : '';
        $redirect_uri = self::get_redirect_uri();
        $state = wp_create_nonce( self::OAUTH_STATE_NONCE_ACTION );

        // Store CSRF state for verification
        set_transient( 'pti_oauth_state_' . $state, $state, HOUR_IN_SECONDS );

        $auth_url = add_query_arg(
            array(
                'client_id'     => $app_id,
                'redirect_uri'  => $redirect_uri,
                'scope'         => implode( ',', [
                    'instagram_business_basic',
                    'instagram_business_content_publish',
                    'instagram_business_manage_messages',
                    'instagram_business_manage_comments',
                ] ),
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
     * Get OAuth redirect URI.
     *
     * @return string Redirect URI for OAuth callbacks
     */
    public static function get_redirect_uri() {
        return home_url( '/pti-oauth/' );
    }

    /**
     * Handle OAuth callback from Instagram.
     *
     * Exchanges authorization code for tokens and stores credentials.
     * Uses CSRF protection and redirects to handler page for popup communication.
     */
    public static function handle_oauth_redirect() {
        if ( ! is_main_query() || ! get_query_var( 'pti_oauth' ) ) {
            return;
        }

        if ( empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
            return;
        }

        $code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
        $state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
        $stored_state = get_transient( 'pti_oauth_state_' . $state );

        // CSRF protection: verify state nonce
        if ( ! $stored_state || $stored_state !== $state || ! wp_verify_nonce( $state, self::OAUTH_STATE_NONCE_ACTION ) ) {
            $handler_url = PTI_PLUGIN_URL . 'auth/oauth-handler.html?pti_auth_status=error&pti_auth_error=state_mismatch';
            wp_redirect( $handler_url );
            exit;
        }
        delete_transient( 'pti_oauth_state_' . $state );

        if ( ! self::is_configured() ) {
            $handler_url = PTI_PLUGIN_URL . 'auth/oauth-handler.html?pti_auth_status=error&pti_auth_error=not_configured';
            wp_redirect( $handler_url );
            exit;
        }

        $options = get_option( 'pti_settings' );
        $app_id = isset( $options['app_id'] ) ? $options['app_id'] : '';
        $app_secret = isset( $options['app_secret'] ) ? $options['app_secret'] : '';
        $redirect_uri = self::get_redirect_uri();

        // Step 1: Exchange authorization code for short-lived token
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
            $handler_url = PTI_PLUGIN_URL . 'auth/oauth-handler.html?pti_auth_status=error&pti_auth_error=token_exchange_failed';
            wp_redirect( $handler_url );
            exit;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( empty( $data['access_token'] ) || empty( $data['user_id'] ) ) {
            $handler_url = PTI_PLUGIN_URL . 'auth/oauth-handler.html?pti_auth_status=error&pti_auth_error=short_token_missing';
            wp_redirect( $handler_url );
            exit;
        }

        $short_lived_token = $data['access_token'];
        $instagram_user_id = $data['user_id'];

        // Step 2: Exchange short-lived for long-lived token (60-day expiry)
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
            $handler_url = PTI_PLUGIN_URL . 'auth/oauth-handler.html?pti_auth_status=error&pti_auth_error=long_token_failed';
            wp_redirect( $handler_url );
            exit;
        }

        $long_lived_body = wp_remote_retrieve_body( $long_lived_response );
        $long_lived_data = json_decode( $long_lived_body, true );

        if ( empty( $long_lived_data['access_token'] ) ) {
            $handler_url = PTI_PLUGIN_URL . 'auth/oauth-handler.html?pti_auth_status=error&pti_auth_error=long_token_missing';
            wp_redirect( $handler_url );
            exit;
        }

        $long_lived_token = $long_lived_data['access_token'];

        // Step 3: Fetch Instagram username for display
        $username = null;
        $user_info_resp = wp_remote_get(
            self::INSTAGRAM_GRAPH_API_URL . "/{$instagram_user_id}?fields=username&access_token={$long_lived_token}",
            array( 'timeout' => 20 )
        );
        if ( ! is_wp_error( $user_info_resp ) ) {
            $user_info_body = json_decode( wp_remote_retrieve_body( $user_info_resp ), true );
            if ( ! empty( $user_info_body['username'] ) ) {
                $username = $user_info_body['username'];
            }
        }

        // Step 4: Store authentication credentials
        $options = get_option( 'pti_settings', array() );
        $options['auth_details'] = array(
            'access_token' => $long_lived_token,
            'user_id'      => $instagram_user_id,
            'username'     => $username,
        );
        update_option( 'pti_settings', $options );

        $handler_url = PTI_PLUGIN_URL . 'auth/oauth-handler.html?pti_auth_status=success';
        wp_redirect( $handler_url );
        exit;
    }
}