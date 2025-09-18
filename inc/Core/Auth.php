<?php
/**
 * Instagram OAuth 2.0 authentication with CSRF protection and token management.
 *
 * @package PostToInstagram\Core
 */

namespace PostToInstagram\Core;

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Instagram OAuth authentication handler.
 */
class Auth {

    const OAUTH_REDIRECT_PARAM = 'pti_oauth_redirect';
    const OAUTH_STATE_NONCE_ACTION = 'pti_oauth_state';
    const INSTAGRAM_GRAPH_API_URL = 'https://graph.instagram.com';
    const INSTAGRAM_API_URL = 'https://api.instagram.com';

    /**
     * Check if Instagram App ID and Secret are configured.
     *
     * @return bool
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
     * @return bool
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
     * @return string|null
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
     * @return string|null
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
     * @return string
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
            array( 'timeout' => 40 )
        );
        if ( ! is_wp_error( $user_info_resp ) ) {
            $user_info_body = json_decode( wp_remote_retrieve_body( $user_info_resp ), true );
            if ( ! empty( $user_info_body['username'] ) ) {
                $username = $user_info_body['username'];
            }
        }

        // Step 4: Store authentication credentials with expiration timestamp
        // Long-lived tokens last 60 days from creation
        $expires_at = time() + ( 60 * 24 * 60 * 60 ); // 60 days from now

        $options = get_option( 'pti_settings', array() );
        $options['auth_details'] = array(
            'access_token' => $long_lived_token,
            'user_id'      => $instagram_user_id,
            'username'     => $username,
            'expires_at'   => $expires_at,
            'created_at'   => time(),
        );
        update_option( 'pti_settings', $options );

        // Schedule automatic token refresh for 59 days from now
        self::schedule_token_refresh();

        $handler_url = PTI_PLUGIN_URL . 'auth/oauth-handler.html?pti_auth_status=success';
        wp_redirect( $handler_url );
        exit;
    }

    /**
     * Check if the current access token is expired or close to expiring.
     *
     * @param int $buffer_days Number of days before expiration to consider "close to expiring" (default: 7)
     * @return bool True if token is expired or close to expiring
     */
    public static function is_token_expired( $buffer_days = 7 ) {
        if ( ! self::is_authenticated() ) {
            return true;
        }

        $options = get_option( 'pti_settings', array() );
        $auth_details = isset( $options['auth_details'] ) ? $options['auth_details'] : array();

        if ( empty( $auth_details['expires_at'] ) ) {
            // If no expiration timestamp, assume it needs refresh
            return true;
        }

        $expires_at = $auth_details['expires_at'];
        $buffer_time = $buffer_days * 24 * 60 * 60; // Convert days to seconds
        $expiry_threshold = $expires_at - $buffer_time;

        return time() >= $expiry_threshold;
    }

    /**
     * Refresh the Instagram access token.
     *
     * @return bool|array True on success, array with error details on failure
     */
    public static function refresh_access_token() {
        $current_token = self::get_access_token();
        if ( ! $current_token ) {
            return array(
                'success' => false,
                'error' => 'No current access token found'
            );
        }

        // Call Instagram's refresh token endpoint
        $refresh_url = self::INSTAGRAM_GRAPH_API_URL . '/refresh_access_token';
        $response = wp_remote_get(
            add_query_arg(
                array(
                    'grant_type' => 'ig_refresh_token',
                    'access_token' => $current_token,
                ),
                $refresh_url
            ),
            array( 'timeout' => 30 )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'error' => 'HTTP request failed: ' . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $status_code !== 200 || empty( $data['access_token'] ) ) {
            $error_message = 'Token refresh failed';
            if ( isset( $data['error']['message'] ) ) {
                $error_message .= ': ' . $data['error']['message'];
            }
            if ( isset( $data['error']['code'] ) ) {
                $error_message .= ' (Code: ' . $data['error']['code'] . ')';
            }
            $error_message .= ' Status: ' . $status_code;
            $error_message .= ' Response: ' . $body;

            return array(
                'success' => false,
                'error' => $error_message,
                'response' => $data,
                'status_code' => $status_code
            );
        }

        // Update stored token with new expiration
        $new_token = $data['access_token'];
        $new_expires_at = time() + ( 60 * 24 * 60 * 60 ); // 60 days from now

        $options = get_option( 'pti_settings', array() );
        if ( isset( $options['auth_details'] ) ) {
            $options['auth_details']['access_token'] = $new_token;
            $options['auth_details']['expires_at'] = $new_expires_at;
            $options['auth_details']['refreshed_at'] = time();
            update_option( 'pti_settings', $options );

            // Schedule next automatic refresh for 59 days from now
            self::schedule_token_refresh();
        }

        return array(
            'success' => true,
            'message' => 'Access token refreshed successfully',
            'new_token' => $new_token,
            'expires_at' => $new_expires_at
        );
    }

    /**
     * Ensure we have a valid, non-expired access token.
     * Automatically refresh if needed.
     *
     * @return bool|array True if token is valid, array with error details if refresh failed
     */
    public static function ensure_valid_token() {
        if ( ! self::is_authenticated() ) {
            return array(
                'success' => false,
                'error' => 'Not authenticated'
            );
        }

        if ( ! self::is_token_expired() ) {
            // Token is still valid
            return true;
        }

        // Token is expired or close to expiring, attempt refresh
        return self::refresh_access_token();
    }

    /**
     * Schedule automatic token refresh for 59 days from now.
     * Clears any existing scheduled refresh first.
     */
    public static function schedule_token_refresh() {
        // Clear any existing scheduled refresh
        wp_clear_scheduled_hook( 'pti_refresh_token' );

        // Schedule refresh for 59 days from now (1 day before expiration)
        $refresh_time = time() + ( 59 * DAY_IN_SECONDS );
        wp_schedule_single_event( $refresh_time, 'pti_refresh_token' );
    }

    /**
     * Handle scheduled token refresh via WP-Cron.
     * Called automatically 59 days after token creation/refresh.
     */
    public static function handle_scheduled_refresh() {
        // Only proceed if we have valid authentication
        if ( ! self::is_authenticated() ) {
            return;
        }

        $refresh_result = self::refresh_access_token();

        if ( $refresh_result === true || ( is_array( $refresh_result ) && $refresh_result['success'] ) ) {
            // Success - next refresh is already scheduled in refresh_access_token()
            error_log( 'PTI: Automatic token refresh successful' );
        } else {
            // Log failure but don't reschedule - let manual intervention handle it
            $error_message = is_array( $refresh_result ) ? $refresh_result['error'] : 'Unknown error';
            error_log( 'PTI: Automatic token refresh failed: ' . $error_message );
        }
    }

    /**
     * Clear scheduled token refresh.
     * Called when user disconnects account.
     */
    public static function clear_token_refresh() {
        wp_clear_scheduled_hook( 'pti_refresh_token' );
    }

    /**
     * Register WordPress hooks for authentication system.
     */
    public static function register() {
        // Handle OAuth redirect
        add_action( 'template_redirect', [ __CLASS__, 'handle_oauth_redirect' ] );

        // Handle scheduled token refresh
        add_action( 'pti_refresh_token', [ __CLASS__, 'handle_scheduled_refresh' ] );
    }
}