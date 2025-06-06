<?php
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}
// require_once PTI_PLUGIN_DIR . 'includes/convert-to-jpg.php';

class PTI_Instagram_API {
    /**
     * Post images to Instagram using direct URLs (already-cropped, already-JPEG)
     * @param int $post_id
     * @param array $image_urls
     * @param string $caption
     * @param array $image_ids
     * @return array [ 'success' => bool, 'message' => string ]
     */
    public static function post_now_with_urls( $post_id, $image_urls, $caption = '', $image_ids = array() ) {
        if ( ! class_exists( 'PTI_Auth_Handler' ) ) {
            require_once PTI_PLUGIN_DIR . 'auth/class-auth-handler.php';
        }
        $access_token = PTI_Auth_Handler::get_access_token();
        $user_id = PTI_Auth_Handler::get_instagram_user_id();
        if ( ! $access_token || ! $user_id ) {
            return array( 'success' => false, 'message' => 'Instagram account not authenticated.' );
        }
        $container_ids = array();
        foreach ( $image_urls as $url ) {
            // Step 1: Create media container for this image (assume JPEG, already public)
            $response = wp_remote_post( "https://graph.instagram.com/{$user_id}/media", array(
                'body' => array(
                    'image_url' => $url,
                    'is_carousel_item' => 'true',
                    'access_token' => $access_token,
                ),
                'timeout' => 20,
            ) );
            if ( is_wp_error( $response ) ) {
                return array( 'success' => false, 'message' => 'Error creating media container: ' . $response->get_error_message() );
            }
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $body['id'] ) ) {
                error_log('[PTI DEBUG] No container ID returned for image. Image URL: ' . $url . ' | Response: ' . wp_remote_retrieve_body( $response ));
                return array( 'success' => false, 'message' => 'No container ID returned for image.' );
            }
            $container_id = $body['id'];
            // Poll for FINISHED status
            $status = '';
            $tries = 0;
            while ( $tries < 10 ) {
                sleep( 2 );
                $status_resp = wp_remote_get( "https://graph.instagram.com/{$container_id}?fields=status_code&access_token={$access_token}", array(
                    'timeout' => 20,
                ) );
                if ( is_wp_error( $status_resp ) ) {
                    return array( 'success' => false, 'message' => 'Error polling container status: ' . $status_resp->get_error_message() );
                }
                $status_body = json_decode( wp_remote_retrieve_body( $status_resp ), true );
                if ( ! empty( $status_body['status_code'] ) ) {
                    $status = $status_body['status_code'];
                    if ( $status === 'FINISHED' ) break;
                    if ( $status === 'ERROR' || $status === 'EXPIRED' ) {
                        return array( 'success' => false, 'message' => 'Container status error: ' . $status );
                    }
                }
                $tries++;
            }
            if ( $status !== 'FINISHED' ) {
                return array( 'success' => false, 'message' => 'Timeout waiting for container to finish.' );
            }
            $container_ids[] = $container_id;
        }
        // Step 2: Create carousel container if needed
        $main_container_id = null;
        if ( count( $container_ids ) > 1 ) {
            $children = implode( ',', $container_ids );
            $carousel_resp = wp_remote_post( "https://graph.instagram.com/{$user_id}/media", array(
                'body' => array(
                    'media_type' => 'CAROUSEL',
                    'children' => $children,
                    'caption' => $caption,
                    'access_token' => $access_token,
                ),
                'timeout' => 20,
            ) );
            if ( is_wp_error( $carousel_resp ) ) {
                return array( 'success' => false, 'message' => 'Error creating carousel container: ' . $carousel_resp->get_error_message() );
            }
            $carousel_body = json_decode( wp_remote_retrieve_body( $carousel_resp ), true );
            if ( empty( $carousel_body['id'] ) ) {
                return array( 'success' => false, 'message' => 'No carousel container ID returned.' );
            }
            $main_container_id = $carousel_body['id'];
        } else if ( count( $container_ids ) === 1 ) {
            $main_container_id = $container_ids[0];
        } else {
            return array( 'success' => false, 'message' => 'No valid images to post.' );
        }
        // Step 3: Publish the container
        $publish_resp = wp_remote_post( "https://graph.instagram.com/{$user_id}/media_publish", array(
            'body' => array(
                'creation_id' => $main_container_id,
                'access_token' => $access_token,
            ),
            'timeout' => 20,
        ) );
        if ( is_wp_error( $publish_resp ) ) {
            return array( 'success' => false, 'message' => 'Error publishing to Instagram: ' . $publish_resp->get_error_message() );
        }
        $publish_body = json_decode( wp_remote_retrieve_body( $publish_resp ), true );
        if ( empty( $publish_body['id'] ) ) {
            return array( 'success' => false, 'message' => 'No media ID returned after publishing.' );
        }
        $media_id = $publish_body['id'];
        // Fetch permalink for the new media (remove status_code from fields)
        $permalink_url = "https://graph.instagram.com/{$media_id}?fields=id,permalink,caption,media_type,media_url,thumbnail_url,timestamp,username&access_token={$access_token}";
        $permalink_resp = wp_remote_get($permalink_url, array('timeout' => 20));
        $permalink = null;
        if ( !is_wp_error($permalink_resp) ) {
            $permalink_body = json_decode(wp_remote_retrieve_body($permalink_resp), true);
            $permalink = isset($permalink_body['permalink']) ? $permalink_body['permalink'] : null;
        }
        // Track published images in post meta (store original attachment IDs)
        $shared = get_post_meta($post_id, '_pti_instagram_shared_images', true);
        if (!is_array($shared)) $shared = array();
        $existing_ids = array_column($shared, 'image_id');
        
        foreach ($image_ids as $id) {
            if (!in_array($id, $existing_ids)) {
                $shared[] = array(
                    'image_id' => $id,
                    'instagram_media_id' => $media_id,
                    'timestamp' => time(),
                    'permalink' => $permalink,
                );
            }
        }
        update_post_meta($post_id, '_pti_instagram_shared_images', $shared);
        $result = array(
            'success' => true,
            'message' => $permalink ? 'Posted to Instagram. View post: ' . $permalink : 'Posted to Instagram. Media ID: ' . $media_id . ' (no permalink found)',
            'response' => $publish_body,
            'permalink' => $permalink,
            'media_id' => $media_id,
        );
        if (!$permalink) {
            $result['warning'] = 'No permalink returned from Instagram.';
        }
        return $result;
    }
} 