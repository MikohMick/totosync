<?php
/**
 * Fired when the plugin is deleted via the WordPress admin.
 * Removes all options and transients created by ToToSync.
 */

// WordPress sets this constant before calling uninstall.php.
// Bail if accessed directly to prevent accidental data loss.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Options
delete_option( 'totosync_last_sync' );
delete_option( 'totosync_sync_log' );

// Transients (delete_transient handles both DB and object-cache backends)
delete_transient( 'totosync_running' );
delete_transient( 'totosync_progress' );
