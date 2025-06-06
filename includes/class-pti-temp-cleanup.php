<?php
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * PTI_Temp_Cleanup Class
 *
 * Handles the scheduled cleanup of the temporary directory for cropped images.
 */
class PTI_Temp_Cleanup {

    const CRON_HOOK = 'pti_daily_temp_file_cleanup_hook';

    /**
     * Initialize the cleanup scheduler.
     */
    public function __construct() {
        add_action( 'init', array( $this, 'schedule_cleanup_event' ) );
        add_action( self::CRON_HOOK, array( $this, 'do_cleanup' ) );
    }

    /**
     * Schedule the daily cron event if it's not already scheduled.
     */
    public function schedule_cleanup_event() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    /**
     * The main cleanup function executed by the cron job.
     * Deletes files in the pti-temp directory older than 24 hours.
     */
    public function do_cleanup() {
        $wp_upload_dir = wp_upload_dir();
        $temp_dir_path = $wp_upload_dir['basedir'] . '/pti-temp';

        if ( ! is_dir( $temp_dir_path ) ) {
            return; // Directory doesn't exist, nothing to do.
        }

        $files = glob( $temp_dir_path . '/*' );
        $expiration_time = 24 * HOUR_IN_SECONDS;
        $now = time();

        foreach ( $files as $file ) {
            if ( is_file( $file ) && ( $now - filemtime( $file ) ) >= $expiration_time ) {
                unlink( $file );
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