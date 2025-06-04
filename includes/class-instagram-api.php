<?php
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}
require_once PTI_PLUGIN_DIR . 'includes/convert-to-jpg.php';

class PTI_Instagram_API {
    /**
     * Post images to Instagram (single or carousel)
     * @param int $post_id
     * @param array $image_ids
     * @param string $caption
     * @return array [ 'success' => bool, 'message' => string ]
     */
    public static function post_now( $post_id, $image_ids, $caption = '' ) {
        if ( ! class_exists( 'PTI_Auth_Handler' ) ) {
            require_once PTI_PLUGIN_DIR . 'auth/class-auth-handler.php';
        }
        $access_token = PTI_Auth_Handler::get_access_token();
        $user_id = PTI_Auth_Handler::get_instagram_user_id();
        if ( ! $access_token || ! $user_id ) {
            return array( 'success' => false, 'message' => 'Instagram account not authenticated.' );
        }
        $container_ids = array();
        $temp_files = array();
        foreach ( $image_ids as $id ) {
            $jpeg = pti_convert_to_jpg_if_needed( $id );
            if ( ! $jpeg || empty( $jpeg['url'] ) ) {
                // Cleanup any temp files created so far
                foreach ( $temp_files as $file ) { pti_cleanup_temp_jpg( $file ); }
                return array( 'success' => false, 'message' => 'Could not get JPEG for image ID ' . $id );
            }
            if ( $jpeg['temp_file'] ) {
                $temp_files[] = $jpeg['temp_file'];
            }
            // Step 1: Create media container for this image
            $response = wp_remote_post( "https://graph.instagram.com/{$user_id}/media", array(
                'body' => array(
                    'image_url' => $jpeg['url'],
                    'is_carousel_item' => 'true',
                    'access_token' => $access_token,
                ),
            ) );
            if ( is_wp_error( $response ) ) {
                foreach ( $temp_files as $file ) { pti_cleanup_temp_jpg( $file ); }
                return array( 'success' => false, 'message' => 'Error creating media container: ' . $response->get_error_message() );
            }
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $body['id'] ) ) {
                foreach ( $temp_files as $file ) { pti_cleanup_temp_jpg( $file ); }
                return array( 'success' => false, 'message' => 'No container ID returned for image.' );
            }
            $container_id = $body['id'];
            // Poll for FINISHED status
            $status = '';
            $tries = 0;
            while ( $tries < 10 ) {
                sleep( 2 );
                $status_resp = wp_remote_get( "https://graph.instagram.com/{$container_id}?fields=status_code&access_token={$access_token}" );
                if ( is_wp_error( $status_resp ) ) {
                    foreach ( $temp_files as $file ) { pti_cleanup_temp_jpg( $file ); }
                    return array( 'success' => false, 'message' => 'Error polling container status: ' . $status_resp->get_error_message() );
                }
                $status_body = json_decode( wp_remote_retrieve_body( $status_resp ), true );
                if ( ! empty( $status_body['status_code'] ) ) {
                    $status = $status_body['status_code'];
                    if ( $status === 'FINISHED' ) break;
                    if ( $status === 'ERROR' || $status === 'EXPIRED' ) {
                        foreach ( $temp_files as $file ) { pti_cleanup_temp_jpg( $file ); }
                        return array( 'success' => false, 'message' => 'Container status error: ' . $status );
                    }
                }
                $tries++;
            }
            if ( $status !== 'FINISHED' ) {
                foreach ( $temp_files as $file ) { pti_cleanup_temp_jpg( $file ); }
                return array( 'success' => false, 'message' => 'Timeout waiting for container to finish.' );
            }
            $container_ids[] = $container_id;
        }
        // Step 2: Create carousel container
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
            ) );
            if ( is_wp_error( $carousel_resp ) ) {
                foreach ( $temp_files as $file ) { pti_cleanup_temp_jpg( $file ); }
                return array( 'success' => false, 'message' => 'Error creating carousel container: ' . $carousel_resp->get_error_message() );
            }
            $carousel_body = json_decode( wp_remote_retrieve_body( $carousel_resp ), true );
            if ( empty( $carousel_body['id'] ) ) {
                foreach ( $temp_files as $file ) { pti_cleanup_temp_jpg( $file ); }
                return array( 'success' => false, 'message' => 'No carousel container ID returned.' );
            }
            $main_container_id = $carousel_body['id'];
        } else if ( count( $container_ids ) === 1 ) {
            $main_container_id = $container_ids[0];
        } else {
            foreach ( $temp_files as $file ) { pti_cleanup_temp_jpg( $file ); }
            return array( 'success' => false, 'message' => 'No valid images to post.' );
        }
        // Step 3: Publish the container
        $publish_resp = wp_remote_post( "https://graph.instagram.com/{$user_id}/media_publish", array(
            'body' => array(
                'creation_id' => $main_container_id,
                'access_token' => $access_token,
            ),
        ) );
        foreach ( $temp_files as $file ) { pti_cleanup_temp_jpg( $file ); }
        if ( is_wp_error( $publish_resp ) ) {
            return array( 'success' => false, 'message' => 'Error publishing to Instagram: ' . $publish_resp->get_error_message() );
        }
        $publish_body = json_decode( wp_remote_retrieve_body( $publish_resp ), true );
        if ( empty( $publish_body['id'] ) ) {
            return array( 'success' => false, 'message' => 'No media ID returned after publishing.' );
        }
        return array( 'success' => true, 'message' => 'Posted to Instagram. Media ID: ' . $publish_body['id'] );
    }
} 