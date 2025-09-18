<?php
/**
 * Instagram posting via action hooks with media container creation and publishing.
 *
 * @package PostToInstagram\Core\Actions
 */

namespace PostToInstagram\Core\Actions;

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Instagram posting handler using WordPress action hooks.
 */
class Post {

    /**
     * Register WordPress action hooks.
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

        // Ensure we have a valid, non-expired access token
        $token_validation = \PostToInstagram\Core\Auth::ensure_valid_token();
        if ( $token_validation !== true ) {
            $error_message = 'Authentication failed';
            if ( isset( $token_validation['error'] ) ) {
                $error_message .= ': ' . $token_validation['error'];
            }
            $error_message .= '. Please re-authenticate your Instagram account.';

            do_action( 'pti_post_error', [
                'message' => $error_message,
                'params' => $params,
                'token_validation' => $token_validation
            ] );
            return;
        }

        // Get authentication credentials (after validation/refresh)
        $access_token = \PostToInstagram\Core\Auth::get_access_token();
        $user_id = \PostToInstagram\Core\Auth::get_instagram_user_id();

        if ( ! $access_token || ! $user_id ) {
            do_action( 'pti_post_error', [
                'message' => 'Instagram account not authenticated.',
                'params' => $params
            ] );
            return;
        }

        // Determine if this is a single image or carousel
        $is_carousel = count( $image_urls ) > 1;

        // Create media containers for each image (no blocking sleep)
        $container_data = [];
    foreach ( $image_urls as $index => $url ) {
            $container_body = [
                'image_url' => $url,
                'access_token' => $access_token,
            ];

            // For single images, include caption and omit is_carousel_item
            // For carousel items, include is_carousel_item and omit caption (caption goes on carousel container)
            if ( $is_carousel ) {
                $container_body['is_carousel_item'] = 'true';
            } else {
                $container_body['caption'] = $caption;
            }

            $response = wp_remote_post( "https://graph.instagram.com/{$user_id}/media", [
                'body' => $container_body,
                'timeout' => 40,
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
            $status_code = wp_remote_retrieve_response_code( $response );

            if ( empty( $body['id'] ) ) {
                $error_message = 'No container ID returned for image.';

                // Include Instagram API error details if available
                if ( isset( $body['error'] ) ) {
                    $error_message .= ' Instagram API Error: ' . $body['error']['message'];
                    if ( isset( $body['error']['code'] ) ) {
                        $error_message .= ' (Code: ' . $body['error']['code'] . ')';
                    }
                }

                $error_message .= ' HTTP Status: ' . $status_code;
                $error_message .= ' Full Response: ' . json_encode( $body );

                do_action( 'pti_post_error', [
                    'message' => $error_message,
                    'params' => $params,
                    'url' => $url,
                    'response' => $body,
                    'status_code' => $status_code
                ] );
                return;
            }

            $container_id = $body['id'];

            // Check initial status (no sleep)
            $status_resp = wp_remote_get( "https://graph.instagram.com/{$container_id}?fields=status_code&access_token={$access_token}", [
                'timeout' => 40,
            ] );

            $status = 'IN_PROGRESS';
            if ( ! is_wp_error( $status_resp ) ) {
                $status_body = json_decode( wp_remote_retrieve_body( $status_resp ), true );
                if ( ! empty( $status_body['status_code'] ) ) {
                    $status = $status_body['status_code'];
                }
            }

            // Handle immediate errors
            if ( $status === 'ERROR' || $status === 'EXPIRED' ) {
                do_action( 'pti_post_error', [
                    'message' => 'Container status error: ' . $status,
                    'params' => $params,
                    'container_id' => $container_id,
                    'status' => $status
                ] );
                return;
            }

            // Store only essential container details (id + status)
            $container_data[] = [
                'id' => $container_id,
                'status' => $status,
            ];
        }

        // Check if all containers are ready immediately
        $ready_containers = [];
        $pending_containers = [];

        foreach ( $container_data as $container ) {
            if ( $container['status'] === 'FINISHED' ) {
                $ready_containers[] = $container['id'];
            } else {
                $pending_containers[] = $container;
            }
        }

        // If not all containers are ready, store in transient for async processing
        if ( ! empty( $pending_containers ) ) {
            $processing_key = 'pti_processing_' . $post_id . '_' . uniqid();

            set_transient( $processing_key, [
                'post_id' => $post_id,
                'image_ids' => $image_ids,
                'caption' => $caption,
                'is_carousel' => $is_carousel,
                'access_token' => $access_token,
                'user_id' => $user_id,
                'container_data' => $container_data,
                'ready_containers' => $ready_containers,
                'pending_containers' => $pending_containers,
                'publishing' => false,
                'publishing_started' => null,
                'published' => false,
            ], 300 ); // 5 minute expiration

            // Return processing status instead of completing
            do_action( 'pti_post_processing', [
                'message' => 'Instagram containers are being processed. Please wait...',
                'processing_key' => $processing_key,
                'total_containers' => count( $container_data ),
                'ready_containers' => count( $ready_containers ),
                'pending_containers' => count( $pending_containers )
            ] );
            return;
        }

        // All containers ready immediately - proceed to publish
        $container_ids = $ready_containers;

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
                'timeout' => 40,
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
            $carousel_status_code = wp_remote_retrieve_response_code( $carousel_resp );

            if ( empty( $carousel_body['id'] ) ) {
                $error_message = 'No carousel container ID returned.';

                // Include Instagram API error details if available
                if ( isset( $carousel_body['error'] ) ) {
                    $error_message .= ' Instagram API Error: ' . $carousel_body['error']['message'];
                    if ( isset( $carousel_body['error']['code'] ) ) {
                        $error_message .= ' (Code: ' . $carousel_body['error']['code'] . ')';
                    }
                }

                $error_message .= ' HTTP Status: ' . $carousel_status_code;
                $error_message .= ' Full Response: ' . json_encode( $carousel_body );

                do_action( 'pti_post_error', [
                    'message' => $error_message,
                    'params' => $params,
                    'container_ids' => $container_ids,
                    'response' => $carousel_body,
                    'status_code' => $carousel_status_code
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
            'timeout' => 40,
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
        $publish_status_code = wp_remote_retrieve_response_code( $publish_resp );

        if ( empty( $publish_body['id'] ) ) {
            $error_message = 'No media ID returned after publishing.';

            // Include Instagram API error details if available
            if ( isset( $publish_body['error'] ) ) {
                $error_message .= ' Instagram API Error: ' . $publish_body['error']['message'];
                if ( isset( $publish_body['error']['code'] ) ) {
                    $error_message .= ' (Code: ' . $publish_body['error']['code'] . ')';
                }
            }

            $error_message .= ' HTTP Status: ' . $publish_status_code;
            $error_message .= ' Full Response: ' . json_encode( $publish_body );

            do_action( 'pti_post_error', [
                'message' => $error_message,
                'params' => $params,
                'main_container_id' => $main_container_id,
                'response' => $publish_body,
                'status_code' => $publish_status_code
            ] );
            return;
        }

        $media_id = $publish_body['id'];

        // Fetch Instagram post permalink
        // Normalize to minimal fields (id, permalink) to reduce payload
        $permalink_url = "https://graph.instagram.com/{$media_id}?fields=id,permalink&access_token={$access_token}";
        $permalink_resp = wp_remote_get( $permalink_url, [ 'timeout' => 40 ] );
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

        // Emit success action with essential data only
        $result = [
            'success' => true,
            'message' => $permalink ? 'Posted to Instagram. View post: ' . $permalink : 'Posted to Instagram. Media ID: ' . $media_id . ' (no permalink found)',
            'permalink' => $permalink,
            'media_id' => $media_id
        ];

        if ( ! $permalink ) {
            $result['warning'] = 'No permalink returned from Instagram.';
        }

        do_action( 'pti_post_success', $result );
    }

    /**
     * Check processing status for a previously initiated Instagram post (async container completion).
     *
     * Loads transient created in handle_post() when some containers were still IN_PROGRESS.
     * Updates container statuses, publishes when all are FINISHED, or emits progress event.
     *
     * @param string $processing_key Transient key returned in pti_post_processing action.
     */
    public static function check_processing_status( $processing_key ) {
        if ( empty( $processing_key ) ) {
            do_action( 'pti_post_error', [ 'message' => 'Processing key missing.' ] );
            return;
        }

        $data = get_transient( $processing_key );
        if ( ! $data || ! is_array( $data ) ) {
            do_action( 'pti_post_error', [ 'message' => 'Processing key not found or expired.', 'processing_key' => $processing_key ] );
            return;
        }

        // If already published, avoid duplicate work.
        if ( ! empty( $data['published'] ) ) {
            return; // Success event already fired earlier.
        }

        // If currently publishing, check for staleness (allow takeover if stale)
        $now = time();
        $stale_after = 180; // seconds
        if ( ! empty( $data['publishing'] ) ) {
            $started = isset( $data['publishing_started'] ) ? (int) $data['publishing_started'] : 0;
            if ( $started && ( $now - $started ) < $stale_after ) {
                // Another process is actively publishing; emit processing state and return
                do_action( 'pti_post_processing', [
                    'message' => 'Finalizing publish...',
                    'processing_key' => $processing_key,
                    'total_containers' => isset( $data['container_data'] ) ? count( $data['container_data'] ) : null,
                    'ready_containers' => isset( $data['ready_containers'] ) ? count( $data['ready_containers'] ) : null,
                    'pending_containers' => isset( $data['pending_containers'] ) ? count( $data['pending_containers'] ) : null,
                ] );
                return;
            }
            // Stale lock -> allow takeover by clearing publishing flag
            $data['publishing'] = false;
            $data['publishing_started'] = null;
            set_transient( $processing_key, $data, 300 );
        }

        $access_token = isset( $data['access_token'] ) ? $data['access_token'] : null;
        $user_id      = isset( $data['user_id'] ) ? $data['user_id'] : null;

        if ( ! $access_token || ! $user_id ) {
            do_action( 'pti_post_error', [ 'message' => 'Missing authentication data in processing transient.', 'processing_key' => $processing_key ] );
            delete_transient( $processing_key );
            return;
        }

        $pending = isset( $data['pending_containers'] ) ? $data['pending_containers'] : [];
        $ready   = isset( $data['ready_containers'] ) ? $data['ready_containers'] : [];
        $container_data = isset( $data['container_data'] ) ? $data['container_data'] : [];

        $still_pending = [];

        foreach ( $pending as $pending_container ) {
            $cid = $pending_container['id'];
            $status_resp = wp_remote_get( "https://graph.instagram.com/{$cid}?fields=status_code&access_token={$access_token}", [ 'timeout' => 40 ] );
            if ( is_wp_error( $status_resp ) ) {
                do_action( 'pti_post_error', [
                    'message' => 'Error polling container status: ' . $status_resp->get_error_message(),
                    'processing_key' => $processing_key,
                    'container_id' => $cid
                ] );
                return;
            }
            $status_body = json_decode( wp_remote_retrieve_body( $status_resp ), true );
            $status_code = isset( $status_body['status_code'] ) ? $status_body['status_code'] : 'IN_PROGRESS';

            // Update master container_data entry for this container
            foreach ( $container_data as &$cd ) {
                if ( $cd['id'] === $cid ) {
                    $cd['status'] = $status_code;
                    break;
                }
            }
            unset( $cd );

            if ( $status_code === 'FINISHED' ) {
                if ( ! in_array( $cid, $ready, true ) ) {
                    $ready[] = $cid;
                }
            } elseif ( $status_code === 'ERROR' || $status_code === 'EXPIRED' ) {
                do_action( 'pti_post_error', [
                    'message' => 'Container status error during async processing: ' . $status_code,
                    'processing_key' => $processing_key,
                    'container_id' => $cid,
                    'status' => $status_code
                ] );
                delete_transient( $processing_key );
                return;
            } else { // Still in progress
                $still_pending[] = $pending_container; // Keeps original metadata (url, index)
            }
        }

        // Update transient data
        $data['ready_containers'] = $ready;
        $data['pending_containers'] = $still_pending;
        $data['container_data'] = $container_data;
        // Refresh expiration window (another 5 minutes)
        set_transient( $processing_key, $data, 300 );

        if ( empty( $still_pending ) ) {
            self::complete_processing( $processing_key );
            return;
        }

        do_action( 'pti_post_processing', [
            'message' => 'Instagram containers still processing...',
            'processing_key' => $processing_key,
            'total_containers' => count( $container_data ),
            'ready_containers' => count( $ready ),
            'pending_containers' => count( $still_pending )
        ] );
    }

    /**
     * Complete processing by publishing a prepared media (single or carousel) once all containers FINISHED.
     *
     * @param string $processing_key Transient key created during initial post.
     */
    public static function complete_processing( $processing_key ) {
        $data = get_transient( $processing_key );
        if ( ! $data || ! is_array( $data ) ) {
            do_action( 'pti_post_error', [ 'message' => 'Processing data missing when attempting completion.', 'processing_key' => $processing_key ] );
            return;
        }

        if ( ! empty( $data['published'] ) ) {
            return; // Already done
        }

        // Acquire publishing lock if not already held (or stale)
        $now = time();
        $stale_after = 180; // seconds
        if ( ! empty( $data['publishing'] ) ) {
            $started = isset( $data['publishing_started'] ) ? (int) $data['publishing_started'] : 0;
            if ( $started && ( $now - $started ) < $stale_after ) {
                // Another process is already handling publish; avoid duplicate
                return;
            }
        }
        // Set lock
        $data['publishing'] = true;
        $data['publishing_started'] = $now;
        set_transient( $processing_key, $data, 300 );

        $post_id      = isset( $data['post_id'] ) ? absint( $data['post_id'] ) : 0;
        $caption      = isset( $data['caption'] ) ? $data['caption'] : '';
        $is_carousel  = ! empty( $data['is_carousel'] );
        $access_token = isset( $data['access_token'] ) ? $data['access_token'] : null;
        $user_id      = isset( $data['user_id'] ) ? $data['user_id'] : null;
        $image_ids    = isset( $data['image_ids'] ) ? $data['image_ids'] : [];
        $container_data = isset( $data['container_data'] ) ? $data['container_data'] : [];
        $ready = isset( $data['ready_containers'] ) ? $data['ready_containers'] : [];

        if ( ! $post_id || ! $access_token || ! $user_id ) {
            do_action( 'pti_post_error', [ 'message' => 'Incomplete processing data; cannot publish.', 'processing_key' => $processing_key ] );
            delete_transient( $processing_key );
            return;
        }

        // Ensure all containers are finished
        $total_expected = count( $container_data );
        if ( $total_expected === 0 || count( $ready ) !== $total_expected ) {
            do_action( 'pti_post_error', [ 'message' => 'Attempted completion before all containers finished.', 'processing_key' => $processing_key ] );
            return;
        }

        $container_ids = $ready;

        // Create carousel or use single container
        $main_container_id = null;
        if ( $is_carousel ) {
            $children = implode( ',', $container_ids );
            $carousel_resp = wp_remote_post( "https://graph.instagram.com/{$user_id}/media", [
                'body' => [
                    'media_type' => 'CAROUSEL',
                    'children' => $children,
                    'caption' => $caption,
                    'access_token' => $access_token,
                ],
                'timeout' => 40,
            ] );

            if ( is_wp_error( $carousel_resp ) ) {
                do_action( 'pti_post_error', [
                    'message' => 'Error creating carousel container (async): ' . $carousel_resp->get_error_message(),
                    'processing_key' => $processing_key,
                ] );
                delete_transient( $processing_key );
                return;
            }
            $carousel_body = json_decode( wp_remote_retrieve_body( $carousel_resp ), true );
            if ( empty( $carousel_body['id'] ) ) {
                do_action( 'pti_post_error', [
                    'message' => 'No carousel container ID returned during async completion.',
                    'processing_key' => $processing_key,
                    'response' => $carousel_body
                ] );
                delete_transient( $processing_key );
                return;
            }
            $main_container_id = $carousel_body['id'];
        } else {
            $main_container_id = $container_ids[0];
        }

        // Publish
        $publish_resp = wp_remote_post( "https://graph.instagram.com/{$user_id}/media_publish", [
            'body' => [
                'creation_id' => $main_container_id,
                'access_token' => $access_token,
            ],
            'timeout' => 40,
        ] );

        if ( is_wp_error( $publish_resp ) ) {
            do_action( 'pti_post_error', [
                'message' => 'Error publishing to Instagram (async): ' . $publish_resp->get_error_message(),
                'processing_key' => $processing_key,
                'main_container_id' => $main_container_id
            ] );
            delete_transient( $processing_key );
            return;
        }

        $publish_body = json_decode( wp_remote_retrieve_body( $publish_resp ), true );
        if ( empty( $publish_body['id'] ) ) {
            do_action( 'pti_post_error', [
                'message' => 'No media ID returned after publishing (async).',
                'processing_key' => $processing_key,
                'response' => $publish_body
            ] );
            delete_transient( $processing_key );
            return;
        }

        $media_id = $publish_body['id'];

        // Fetch permalink
        $permalink = null;
    $permalink_resp = wp_remote_get( "https://graph.instagram.com/{$media_id}?fields=id,permalink&access_token={$access_token}", [ 'timeout' => 40 ] );
        if ( ! is_wp_error( $permalink_resp ) ) {
            $permalink_body = json_decode( wp_remote_retrieve_body( $permalink_resp ), true );
            if ( isset( $permalink_body['permalink'] ) ) {
                $permalink = $permalink_body['permalink'];
            }
        }

        // Track shared images (same logic as synchronous path)
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

        $result = [
            'success' => true,
            'message' => $permalink ? 'Posted to Instagram (async). View post: ' . $permalink : 'Posted to Instagram (async). Media ID: ' . $media_id . ' (no permalink found)',
            'permalink' => $permalink,
            'media_id' => $media_id
        ];
        if ( ! $permalink ) {
            $result['warning'] = 'No permalink returned from Instagram (async).';
        }

    // Mark published and clear lock; remove transient
    $data['published'] = true;
    $data['publishing'] = false;
    $data['publishing_started'] = null;
    delete_transient( $processing_key );

        do_action( 'pti_post_success', $result );
    }
}