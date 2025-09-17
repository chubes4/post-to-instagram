<?php
/**
 * Instagram Post Action Handler
 *
 * Centralized action-based Instagram posting system. Handles all Instagram API
 * operations including media container creation, status polling, and publishing.
 * Emits WordPress action hooks for success/error handling instead of return values.
 *
 * @package PostToInstagram\Core\Actions
 */

namespace PostToInstagram\Core\Actions;

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Post Class
 *
 * Handles Instagram posting via WordPress action hooks following Data Machine patterns.
 */
class Post {

    /**
     * Register the Instagram posting action hook.
     */
    public static function register() {
        add_action( 'pti_post_to_instagram', [ __CLASS__, 'handle_post' ], 10, 1 );
    }

    /**
     * Handle Instagram posting request.
     *
     * @param array $params {
     *     Posting parameters
     *     @type int    $post_id    WordPress post ID for tracking
     *     @type array  $image_urls Array of public image URLs (JPEG format)
     *     @type string $caption    Instagram caption text
     *     @type array  $image_ids  WordPress attachment IDs for tracking
     * }
     */
    public static function handle_post( $params ) {
        // Validate required parameters
        if ( empty( $params['post_id'] ) || empty( $params['image_urls'] ) || empty( $params['image_ids'] ) ) {
            do_action( 'pti_post_error', [
                'message' => 'Missing required parameters: post_id, image_urls, or image_ids',
                'params' => $params
            ] );
            return;
        }

        $post_id = absint( $params['post_id'] );
        $image_urls = $params['image_urls'];
        $caption = isset( $params['caption'] ) ? sanitize_textarea_field( $params['caption'] ) : '';
        $image_ids = array_map( 'absint', $params['image_ids'] );

        // Validate URLs
        foreach ( $image_urls as $url ) {
            if ( filter_var( $url, FILTER_VALIDATE_URL ) === FALSE ) {
                do_action( 'pti_post_error', [
                    'message' => 'Invalid image URL provided: ' . esc_url( $url ),
                    'params' => $params
                ] );
                return;
            }
        }

        // Get authentication credentials
        $access_token = \PostToInstagram\Core\Auth::get_access_token();
        $user_id = \PostToInstagram\Core\Auth::get_instagram_user_id();

        if ( ! $access_token || ! $user_id ) {
            do_action( 'pti_post_error', [
                'message' => 'Instagram account not authenticated.',
                'params' => $params
            ] );
            return;
        }

        // Create media containers for each image
        $container_ids = [];
        foreach ( $image_urls as $url ) {
            $response = wp_remote_post( "https://graph.instagram.com/{$user_id}/media", [
                'body' => [
                    'image_url' => $url,
                    'is_carousel_item' => 'true',
                    'access_token' => $access_token,
                ],
                'timeout' => 20,
            ] );

            if ( is_wp_error( $response ) ) {
                do_action( 'pti_post_error', [
                    'message' => 'Error creating media container: ' . $response->get_error_message(),
                    'params' => $params,
                    'url' => $url
                ] );
                return;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $body['id'] ) ) {
                do_action( 'pti_post_error', [
                    'message' => 'No container ID returned for image.',
                    'params' => $params,
                    'url' => $url,
                    'response' => $body
                ] );
                return;
            }

            $container_id = $body['id'];

            // Poll until container processing completes
            $status = '';
            $tries = 0;
            while ( $tries < 10 ) {
                sleep( 2 );
                $status_resp = wp_remote_get( "https://graph.instagram.com/{$container_id}?fields=status_code&access_token={$access_token}", [
                    'timeout' => 20,
                ] );

                if ( is_wp_error( $status_resp ) ) {
                    do_action( 'pti_post_error', [
                        'message' => 'Error polling container status: ' . $status_resp->get_error_message(),
                        'params' => $params,
                        'container_id' => $container_id
                    ] );
                    return;
                }

                $status_body = json_decode( wp_remote_retrieve_body( $status_resp ), true );
                if ( ! empty( $status_body['status_code'] ) ) {
                    $status = $status_body['status_code'];
                    if ( $status === 'FINISHED' ) break;
                    if ( $status === 'ERROR' || $status === 'EXPIRED' ) {
                        do_action( 'pti_post_error', [
                            'message' => 'Container status error: ' . $status,
                            'params' => $params,
                            'container_id' => $container_id,
                            'status' => $status
                        ] );
                        return;
                    }
                }
                $tries++;
            }

            if ( $status !== 'FINISHED' ) {
                do_action( 'pti_post_error', [
                    'message' => 'Timeout waiting for container to finish.',
                    'params' => $params,
                    'container_id' => $container_id,
                    'final_status' => $status,
                    'tries' => $tries
                ] );
                return;
            }

            $container_ids[] = $container_id;
        }

        // Create carousel or single image container
        $main_container_id = null;
        if ( count( $container_ids ) > 1 ) {
            $children = implode( ',', $container_ids );
            $carousel_resp = wp_remote_post( "https://graph.instagram.com/{$user_id}/media", [
                'body' => [
                    'media_type' => 'CAROUSEL',
                    'children' => $children,
                    'caption' => $caption,
                    'access_token' => $access_token,
                ],
                'timeout' => 20,
            ] );

            if ( is_wp_error( $carousel_resp ) ) {
                do_action( 'pti_post_error', [
                    'message' => 'Error creating carousel container: ' . $carousel_resp->get_error_message(),
                    'params' => $params,
                    'container_ids' => $container_ids
                ] );
                return;
            }

            $carousel_body = json_decode( wp_remote_retrieve_body( $carousel_resp ), true );
            if ( empty( $carousel_body['id'] ) ) {
                do_action( 'pti_post_error', [
                    'message' => 'No carousel container ID returned.',
                    'params' => $params,
                    'container_ids' => $container_ids,
                    'response' => $carousel_body
                ] );
                return;
            }

            $main_container_id = $carousel_body['id'];
        } else if ( count( $container_ids ) === 1 ) {
            $main_container_id = $container_ids[0];
        } else {
            do_action( 'pti_post_error', [
                'message' => 'No valid images to post.',
                'params' => $params,
                'container_ids' => $container_ids
            ] );
            return;
        }

        // Publish to Instagram
        $publish_resp = wp_remote_post( "https://graph.instagram.com/{$user_id}/media_publish", [
            'body' => [
                'creation_id' => $main_container_id,
                'access_token' => $access_token,
            ],
            'timeout' => 20,
        ] );

        if ( is_wp_error( $publish_resp ) ) {
            do_action( 'pti_post_error', [
                'message' => 'Error publishing to Instagram: ' . $publish_resp->get_error_message(),
                'params' => $params,
                'main_container_id' => $main_container_id
            ] );
            return;
        }

        $publish_body = json_decode( wp_remote_retrieve_body( $publish_resp ), true );
        if ( empty( $publish_body['id'] ) ) {
            do_action( 'pti_post_error', [
                'message' => 'No media ID returned after publishing.',
                'params' => $params,
                'main_container_id' => $main_container_id,
                'response' => $publish_body
            ] );
            return;
        }

        $media_id = $publish_body['id'];

        // Fetch Instagram post permalink
        $permalink_url = "https://graph.instagram.com/{$media_id}?fields=id,permalink,caption,media_type,media_url,thumbnail_url,timestamp,username&access_token={$access_token}";
        $permalink_resp = wp_remote_get( $permalink_url, [ 'timeout' => 20 ] );
        $permalink = null;

        if ( ! is_wp_error( $permalink_resp ) ) {
            $permalink_body = json_decode( wp_remote_retrieve_body( $permalink_resp ), true );
            $permalink = isset( $permalink_body['permalink'] ) ? $permalink_body['permalink'] : null;
        }

        // Track shared images in WordPress post meta
        $shared = get_post_meta( $post_id, '_pti_instagram_shared_images', true );
        if ( ! is_array( $shared ) ) $shared = [];
        $existing_ids = array_column( $shared, 'image_id' );

        foreach ( $image_ids as $id ) {
            if ( ! in_array( $id, $existing_ids ) ) {
                $shared[] = [
                    'image_id' => $id,
                    'instagram_media_id' => $media_id,
                    'timestamp' => time(),
                    'permalink' => $permalink,
                ];
            }
        }
        update_post_meta( $post_id, '_pti_instagram_shared_images', $shared );

        // Emit success action with all result data
        $result = [
            'success' => true,
            'message' => $permalink ? 'Posted to Instagram. View post: ' . $permalink : 'Posted to Instagram. Media ID: ' . $media_id . ' (no permalink found)',
            'response' => $publish_body,
            'permalink' => $permalink,
            'media_id' => $media_id,
            'params' => $params
        ];

        if ( ! $permalink ) {
            $result['warning'] = 'No permalink returned from Instagram.';
        }

        do_action( 'pti_post_success', $result );
    }
}