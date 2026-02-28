<?php
/**
 * Direct CLI / server-cron entry point for ToToSync.
 *
 * This script lets you trigger a full product sync from a real server cron
 * without relying on WordPress's WP-Cron (which only fires on page visits).
 *
 * Add ONE of the following to your server's crontab:
 *
 *   # Option A — PHP CLI (fastest, no HTTP overhead):
 *   */30 * * * * php /var/www/html/wp-content/plugins/totosync/sync.php >> /var/log/totosync.log 2>&1
 *
 *   # Option B — curl the WP-Cron endpoint (simpler if PHP CLI isn't available):
 *   */30 * * * * curl -s https://your-site.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
 *
 * Option A runs totosync_run_sync() directly.
 * Option B lets WordPress fire all scheduled hooks including totosync_scheduled_sync.
 */

// Prevent direct HTTP access — this file is for CLI use only.
if ( php_sapi_name() !== 'cli' && isset( $_SERVER['HTTP_HOST'] ) ) {
    http_response_code( 403 );
    exit( 'CLI only.' );
}

// Bootstrap WordPress.
require_once __DIR__ . '/../../../wp-load.php';

// Run the sync.
totosync_run_sync();
