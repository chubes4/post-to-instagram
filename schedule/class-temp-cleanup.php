<?php
/**
 * Handles the cleanup of temporary files.
 *
 * @package Post_to_Instagram
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * PTI_Temp_Cleanup Class
 *
 * This class is responsible for scheduling and running the cleanup
 * process for the temporary image directory.
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
     * Sets up the cron job for cleanup.
     */
    public function __construct() {
        add_action( self::CRON_HOOK, array( $this, 'do_cleanup' ) );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    /**
     * The main cleanup logic.
     *
     * This method will eventually contain the logic to find and
     * delete old, orphaned temporary files.
     */
    public function do_cleanup() {
        // Cleanup logic will be implemented here.
        // For now, it does nothing.
    }

    /**
     * On deactivation, unschedule the cron job.
     */
    public static function on_deactivation() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }
} 