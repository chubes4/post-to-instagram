<?php
/**
 * Temporary file cleanup handler.
 *
 * Manages automatic cleanup of cropped images in /wp-content/uploads/pti-temp/
 * via daily WP-Cron scheduling.
 *
 * @package Post_to_Instagram
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * PTI_Temp_Cleanup Class
 *
 * Schedules daily cleanup of temporary images older than 24 hours.
 */
class PTI_Temp_Cleanup {

    /**
     * The hook for the cron event.
     *
     * @var string
     */
    const CRON_HOOK = 'pti_temp_cleanup_cron';

    /**
     * Constructor.
     *
     * Registers WP-Cron cleanup job if not already scheduled.
     */
    public function __construct() {
        add_action( self::CRON_HOOK, array( $this, 'do_cleanup' ) );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Delete temporary files older than 24 hours.
     */
    public function do_cleanup() {
        $temp_dir = wp_upload_dir()['basedir'] . '/pti-temp/';
        if ( ! is_dir( $temp_dir ) ) {
            return;
        }

        $files = glob( $temp_dir . '*' );
        $cutoff = time() - DAY_IN_SECONDS;

        foreach ( $files as $file ) {
            if ( is_file( $file ) && filemtime( $file ) < $cutoff ) {
                wp_delete_file( $file );
            }
        }
    }

    /**
     * Unschedule cleanup on plugin deactivation.
     */
    public static function on_deactivation() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }
} 