<?php
/**
 * Temporary file cleanup with daily WP-Cron for pti-temp directory.
 *
 * @package PostToInstagram\Core\Actions
 */

namespace PostToInstagram\Core\Actions;

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Temporary file cleanup system with WP-Cron integration.
 */
class Cleanup {

    const CRON_HOOK = 'pti_temp_cleanup_cron';

    /**
     * Register WordPress action hooks and WP-Cron integration.
     */
    public static function register() {
        // Register cleanup actions
        add_action( 'pti_cleanup_temp_files', [ __CLASS__, 'handle_cleanup' ], 10 );

        // Register CRON integration
        add_action( 'init', [ __CLASS__, 'schedule_cron_event' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'cron_trigger_cleanup' ] );
    }

    /**
     * Handle temporary file cleanup request.
     *
     * Deletes temporary files older than 24 hours from the pti-temp directory.
     */
    public static function handle_cleanup() {
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

        do_action( 'pti_cleanup_complete', [
            'message' => 'Temporary file cleanup completed',
            'files_processed' => count( $files ),
            'cutoff_time' => $cutoff
        ] );
    }

    /**
     * Schedule the CRON event if not already scheduled.
     */
    public static function schedule_cron_event() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    /**
     * CRON hook that triggers cleanup processing.
     */
    public static function cron_trigger_cleanup() {
        do_action( 'pti_cleanup_temp_files' );
    }

    /**
     * Clean up scheduled events on plugin deactivation.
     */
    public static function on_deactivation() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }
}