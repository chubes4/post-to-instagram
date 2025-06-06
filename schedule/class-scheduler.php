<?php
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * PTI_Scheduler Class
 *
 * Handles the WP-Cron based scheduling of Instagram posts.
 */
class PTI_Scheduler {

    const CRON_HOOK = 'pti_scheduled_posts_cron_hook';
    const CRON_INTERVAL_NAME = 'every_five_minutes';

    /**
     * Initialize the scheduler.
     */
    public function __construct() {
        add_filter( 'cron_schedules', array( $this, 'add_custom_cron_interval' ) );
        add_action( 'init', array( $this, 'schedule_event' ) );
        add_action( self::CRON_HOOK, array( $this, 'run_scheduled_posts' ) );
    }

    /**
     * Add a custom cron interval.
     *
     * @param array $schedules Existing cron schedules.
     * @return array Modified cron schedules.
     */
    public function add_custom_cron_interval( $schedules ) {
        if ( ! isset( $schedules[ self::CRON_INTERVAL_NAME ] ) ) {
            $schedules[ self::CRON_INTERVAL_NAME ] = array(
                'interval' => 300, // 5 minutes in seconds
                'display'  => __( 'Every 5 Minutes', 'post-to-instagram' ),
            );
        }
        return $schedules;
    }

    /**
     * Schedule the cron event if it's not already scheduled.
     */
    public function schedule_event() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_INTERVAL_NAME, self::CRON_HOOK );
        }
    }

    /**
     * The main function executed by the cron job.
     * Finds and publishes scheduled Instagram posts.
     */
    public function run_scheduled_posts() {
        // Find posts with the _pti_instagram_scheduled_posts meta key.
        $scheduled_items_query = new WP_Query([
            'post_type'      => 'any',
            'posts_per_page' => -1,
            'meta_key'       => '_pti_instagram_scheduled_posts',
            'fields'         => 'ids',
        ]);

        if ( ! $scheduled_items_query->have_posts() ) {
            return;
        }

        $post_ids = $scheduled_items_query->posts;
        $current_time = current_time('timestamp', true); // Get current time in server's timezone (UTC)

        foreach ( $post_ids as $post_id ) {
            $scheduled_posts = get_post_meta( $post_id, '_pti_instagram_scheduled_posts', true );
            if ( empty( $scheduled_posts ) || ! is_array( $scheduled_posts ) ) {
                continue;
            }

            $updated_scheduled_posts = $scheduled_posts;
            $made_changes = false;

            foreach ( $scheduled_posts as $index => $scheduled_post ) {
                if ( ! isset( $scheduled_post['schedule_time'] ) || ! isset( $scheduled_post['status'] ) || $scheduled_post['status'] !== 'pending' ) {
                    continue;
                }

                // Timezones: schedule_time is saved from JS `new Date()`, which is user's local time.
                // We need to treat it as being in the site's configured timezone.
                $schedule_timestamp = strtotime( get_date_from_gmt( date( 'Y-m-d H:i:s', strtotime( $scheduled_post['schedule_time'] ) ), 'U' ) );
                
                if ( $schedule_timestamp > $current_time ) {
                    continue; // Not yet time to post.
                }

                // It's time to post!
                $made_changes = true;
                $temp_image_urls = [];
                $error = false;

                // 1. Generate cropped images
                foreach ( $scheduled_post['image_ids'] as $image_index => $image_id ) {
                    $original_image_path = get_attached_file( $image_id );
                    if ( ! $original_image_path || ! file_exists( $original_image_path ) ) {
                        // Log error and skip this image
                        error_log( "PTI Scheduler: Original image not found for ID $image_id in post $post_id." );
                        continue;
                    }

                    $crop_data = $scheduled_post['crop_data'][$image_index]['croppedAreaPixels'];
                    $editor = wp_get_image_editor( $original_image_path );

                    if ( is_wp_error( $editor ) ) {
                        error_log( "PTI Scheduler: Failed to load image editor for image ID $image_id. Error: " . $editor->get_error_message() );
                        $error = true;
                        break;
                    }
                    
                    // The crop data needs x, y, width, height.
                    $editor->crop( $crop_data['x'], $crop_data['y'], $crop_data['width'], $crop_data['height'] );
                    
                    // We need a unique filename for the temp file
                    $original_filename = basename( $original_image_path );
                    $temp_filename = 'scheduled-crop-' . $post_id . '-' . $scheduled_post['id'] . '-' . $original_filename;

                    $wp_upload_dir = wp_upload_dir();
                    $temp_dir_path = $wp_upload_dir['basedir'] . '/pti-temp';
                    if ( ! file_exists( $temp_dir_path ) ) {
                        wp_mkdir_p( $temp_dir_path );
                    }

                    $saved_image = $editor->save( $temp_dir_path . '/' . $temp_filename );

                    if ( is_wp_error( $saved_image ) ) {
                         error_log( "PTI Scheduler: Failed to save cropped image for ID $image_id. Error: " . $saved_image->get_error_message() );
                         $error = true;
                         break;
                    }

                    $temp_image_urls[] = $wp_upload_dir['baseurl'] . '/pti-temp/' . $saved_image['file'];
                }

                if ( $error || empty($temp_image_urls) ) {
                    // Mark as failed and move to next scheduled post
                    $updated_scheduled_posts[$index]['status'] = 'failed';
                    $updated_scheduled_posts[$index]['error_message'] = 'Failed during image processing.';
                    continue;
                }

                // 2. Post to Instagram
                $result = PTI_Instagram_API::post_now_with_urls(
                    $post_id,
                    $temp_image_urls,
                    $scheduled_post['caption'],
                    $scheduled_post['image_ids']
                );

                if ( $result['success'] ) {
                    // 3. Success: Remove from scheduled list
                    unset( $updated_scheduled_posts[$index] );
                } else {
                    // 4. Failure: Mark as failed and log error
                    $updated_scheduled_posts[$index]['status'] = 'failed';
                    $updated_scheduled_posts[$index]['error_message'] = $result['message'];
                    error_log( "PTI Scheduler: Failed to post to Instagram for post $post_id. Reason: " . $result['message'] );
                }
            }

            if ( $made_changes ) {
                // Re-index the array after unsetting elements and update post meta
                update_post_meta( $post_id, '_pti_instagram_scheduled_posts', array_values( $updated_scheduled_posts ) );
            }
        }
    }

    /**
     * Hook for plugin deactivation to unschedule the cron event.
     */
    public static function on_deactivation() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }
} 