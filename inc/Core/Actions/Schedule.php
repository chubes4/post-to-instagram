<?php
/**
 * Instagram Schedule Action Handler
 *
 * Centralized action-based Instagram scheduling system. Handles both storing
 * scheduled posts and processing them via WP-Cron. Includes CRON registration,
 * image processing, and complete scheduling workflow.
 *
 * @package PostToInstagram\Core\Actions
 */

namespace PostToInstagram\Core\Actions;

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Schedule Class
 *
 * Complete Instagram scheduling system via WordPress action hooks.
 */
class Schedule {

    const CRON_HOOK = 'pti_scheduled_posts_cron_hook';
    const CRON_INTERVAL_NAME = 'every_five_minutes';

    /**
     * Register all scheduling action hooks and CRON integration.
     */
    public static function register() {
        // Register scheduling actions
        add_action( 'pti_schedule_instagram_post', [ __CLASS__, 'handle_schedule' ], 10, 1 );
        add_action( 'pti_process_scheduled_posts', [ __CLASS__, 'process_scheduled_posts' ], 10 );

        // Register CRON integration
        add_filter( 'cron_schedules', [ __CLASS__, 'add_custom_cron_interval' ] );
        add_action( 'init', [ __CLASS__, 'schedule_cron_event' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'cron_trigger_processing' ] );
    }

    /**
     * Handle Instagram post scheduling request.
     *
     * @param array $params {
     *     Scheduling parameters
     *     @type int    $post_id      WordPress post ID
     *     @type array  $image_ids    WordPress attachment IDs
     *     @type array  $crop_data    Image crop data for each image
     *     @type string $caption      Instagram caption text
     *     @type string $schedule_time Schedule time (ISO 8601 format)
     * }
     */
    public static function handle_schedule( $params ) {
        // Validate required parameters
        if ( empty( $params['post_id'] ) || empty( $params['image_ids'] ) || empty( $params['schedule_time'] ) ) {
            do_action( 'pti_schedule_error', [
                'message' => 'Missing required parameters: post_id, image_ids, or schedule_time',
                'params' => $params
            ] );
            return;
        }

        $post_id = absint( $params['post_id'] );
        $image_ids = array_map( 'absint', $params['image_ids'] );
        $crop_data = $params['crop_data'] ?? [];
        $caption = isset( $params['caption'] ) ? sanitize_textarea_field( $params['caption'] ) : '';
        $schedule_time = sanitize_text_field( $params['schedule_time'] );

        // Validate post exists
        if ( ! get_post( $post_id ) ) {
            do_action( 'pti_schedule_error', [
                'message' => 'Invalid post ID: ' . $post_id,
                'params' => $params
            ] );
            return;
        }

        // Validate images exist
        foreach ( $image_ids as $image_id ) {
            if ( ! wp_attachment_is_image( $image_id ) ) {
                do_action( 'pti_schedule_error', [
                    'message' => 'Invalid image ID: ' . $image_id,
                    'params' => $params
                ] );
                return;
            }
        }

        // Get existing scheduled posts
        $scheduled_posts = get_post_meta( $post_id, '_pti_instagram_scheduled_posts', true );
        if ( ! is_array( $scheduled_posts ) ) {
            $scheduled_posts = [];
        }

        // Create new scheduled post entry
        $new_post = [
            'id'            => uniqid( 'pti_' ),
            'image_ids'     => $image_ids,
            'crop_data'     => $crop_data,
            'caption'       => $caption,
            'schedule_time' => $schedule_time,
            'created_at'    => current_time( 'mysql' ),
            'status'        => 'pending',
        ];

        // Add to scheduled posts array
        $scheduled_posts[] = $new_post;

        // Update post meta
        $update_result = update_post_meta( $post_id, '_pti_instagram_scheduled_posts', $scheduled_posts );

        if ( $update_result ) {
            do_action( 'pti_schedule_success', [
                'message' => 'Post successfully scheduled for ' . $schedule_time,
                'scheduled_post' => $new_post,
                'post_id' => $post_id
            ] );
        } else {
            do_action( 'pti_schedule_error', [
                'message' => 'Failed to store scheduled post data',
                'params' => $params
            ] );
        }
    }

    /**
     * Process all scheduled Instagram posts that are ready to publish.
     */
    public static function process_scheduled_posts() {
        // Find posts with scheduled Instagram posts
        $scheduled_items_query = new \WP_Query([
            'post_type'      => 'any',
            'posts_per_page' => -1,
            'meta_key'       => '_pti_instagram_scheduled_posts',
            'fields'         => 'ids',
        ]);

        if ( ! $scheduled_items_query->have_posts() ) {
            return;
        }

        $post_ids = $scheduled_items_query->posts;
        $current_time = current_time( 'timestamp', true );

        foreach ( $post_ids as $post_id ) {
            $scheduled_posts = get_post_meta( $post_id, '_pti_instagram_scheduled_posts', true );
            if ( empty( $scheduled_posts ) || ! is_array( $scheduled_posts ) ) {
                continue;
            }

            $updated_scheduled_posts = $scheduled_posts;
            $made_changes = false;

            foreach ( $scheduled_posts as $index => $scheduled_post ) {
                if ( ! isset( $scheduled_post['schedule_time'] ) ||
                     ! isset( $scheduled_post['status'] ) ||
                     $scheduled_post['status'] !== 'pending' ) {
                    continue;
                }

                // Parse schedule time with timezone handling
                $schedule_timestamp = strtotime(
                    get_date_from_gmt(
                        date( 'Y-m-d H:i:s', strtotime( $scheduled_post['schedule_time'] ) ),
                        'U'
                    )
                );

                if ( $schedule_timestamp > $current_time ) {
                    continue; // Not yet time to post
                }

                // Process this scheduled post
                $made_changes = true;
                $processing_result = self::process_single_scheduled_post( $post_id, $scheduled_post );

                if ( $processing_result['success'] ) {
                    // Remove successfully posted item
                    unset( $updated_scheduled_posts[$index] );
                } else {
                    // Mark as failed
                    $updated_scheduled_posts[$index]['status'] = 'failed';
                    $updated_scheduled_posts[$index]['error_message'] = $processing_result['error'];
                    error_log( "PTI Schedule: Failed to post scheduled Instagram post for post $post_id. Reason: " . $processing_result['error'] );
                }
            }

            if ( $made_changes ) {
                // Re-index array and update post meta
                update_post_meta( $post_id, '_pti_instagram_scheduled_posts', array_values( $updated_scheduled_posts ) );
            }
        }
    }

    /**
     * Process a single scheduled post: crop images and trigger posting.
     *
     * @param int   $post_id        WordPress post ID
     * @param array $scheduled_post Scheduled post data
     * @return array Processing result with success/error status
     */
    private static function process_single_scheduled_post( $post_id, $scheduled_post ) {
        $temp_image_urls = [];

        // 1. Generate cropped images
        foreach ( $scheduled_post['image_ids'] as $image_index => $image_id ) {
            $original_image_path = get_attached_file( $image_id );
            if ( ! $original_image_path || ! file_exists( $original_image_path ) ) {
                return [
                    'success' => false,
                    'error' => "Original image not found for ID $image_id"
                ];
            }

            $crop_data = $scheduled_post['crop_data'][$image_index]['croppedAreaPixels'] ?? null;
            if ( ! $crop_data ) {
                return [
                    'success' => false,
                    'error' => "Crop data missing for image ID $image_id"
                ];
            }

            // Load WordPress image editor
            $editor = wp_get_image_editor( $original_image_path );
            if ( is_wp_error( $editor ) ) {
                return [
                    'success' => false,
                    'error' => "Failed to load image editor: " . $editor->get_error_message()
                ];
            }

            // Apply crop
            $crop_result = $editor->crop(
                $crop_data['x'],
                $crop_data['y'],
                $crop_data['width'],
                $crop_data['height']
            );

            if ( is_wp_error( $crop_result ) ) {
                return [
                    'success' => false,
                    'error' => "Failed to crop image: " . $crop_result->get_error_message()
                ];
            }

            // Save cropped image to temp directory
            $original_filename = basename( $original_image_path );
            $temp_filename = 'scheduled-crop-' . $post_id . '-' . $scheduled_post['id'] . '-' . $original_filename;

            $wp_upload_dir = wp_upload_dir();
            $temp_dir_path = $wp_upload_dir['basedir'] . '/pti-temp';

            if ( ! file_exists( $temp_dir_path ) ) {
                wp_mkdir_p( $temp_dir_path );
            }

            $saved_image = $editor->save( $temp_dir_path . '/' . $temp_filename );

            if ( is_wp_error( $saved_image ) ) {
                return [
                    'success' => false,
                    'error' => "Failed to save cropped image: " . $saved_image->get_error_message()
                ];
            }

            $temp_image_urls[] = $wp_upload_dir['baseurl'] . '/pti-temp/' . basename( $saved_image['file'] );
        }

        if ( empty( $temp_image_urls ) ) {
            return [
                'success' => false,
                'error' => 'No valid cropped images generated'
            ];
        }

        // 2. Trigger Instagram posting via action
        $post_success = false;
        $post_error = null;

        // Set up event listeners
        $success_handler = function( $success_result ) use ( &$post_success ) {
            $post_success = true;
        };
        $error_handler = function( $error_result ) use ( &$post_error ) {
            $post_error = $error_result['message'] ?? 'Unknown posting error';
        };

        add_action( 'pti_post_success', $success_handler );
        add_action( 'pti_post_error', $error_handler );

        // Trigger posting action
        do_action( 'pti_post_to_instagram', [
            'post_id' => $post_id,
            'image_urls' => $temp_image_urls,
            'caption' => $scheduled_post['caption'],
            'image_ids' => $scheduled_post['image_ids']
        ] );

        // Clean up event listeners
        remove_action( 'pti_post_success', $success_handler );
        remove_action( 'pti_post_error', $error_handler );

        return [
            'success' => $post_success,
            'error' => $post_error ?? ( $post_success ? null : 'Unknown error during posting' )
        ];
    }

    /**
     * Add custom 5-minute cron interval.
     *
     * @param array $schedules Existing cron schedules
     * @return array Modified schedules
     */
    public static function add_custom_cron_interval( $schedules ) {
        if ( ! isset( $schedules[ self::CRON_INTERVAL_NAME ] ) ) {
            $schedules[ self::CRON_INTERVAL_NAME ] = [
                'interval' => 300, // 5 minutes in seconds
                'display'  => __( 'Every 5 Minutes', 'post-to-instagram' ),
            ];
        }
        return $schedules;
    }

    /**
     * Schedule the CRON event if not already scheduled.
     */
    public static function schedule_cron_event() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_INTERVAL_NAME, self::CRON_HOOK );
        }
    }

    /**
     * CRON hook that triggers scheduled post processing.
     */
    public static function cron_trigger_processing() {
        do_action( 'pti_process_scheduled_posts' );
    }

    /**
     * Clean up scheduled events on plugin deactivation.
     */
    public static function on_deactivation() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }
}