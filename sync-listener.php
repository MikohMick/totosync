<?php
/**
 * ToToSync Listener — background sync endpoint.
 *
 * Called by the "Sync Now" button via a non-blocking wp_remote_post from
 * totosync_ajax_start_sync(). Closes the HTTP connection immediately so
 * the caller gets a response at once, then runs the full sync in the background.
 *
 * Direct web access without the correct secret returns 401.
 */

// Bootstrap WordPress so we can read the stored secret from wp_options.
define( 'WP_USE_THEMES', false );
define( 'DOING_CRON',    true );
require_once dirname( __DIR__, 3 ) . '/wp-load.php';

// ── Authenticate ──────────────────────────────────────────────────────────────
$stored   = get_option( 'totosync_listener_secret', '' );
$provided = isset( $_POST['totosync_secret'] ) ? (string) $_POST['totosync_secret'] : '';

if ( empty( $stored ) || ! hash_equals( $stored, $provided ) ) {
    http_response_code( 401 );
    exit( 'Unauthorized' );
}

// ── Guard against overlapping runs ────────────────────────────────────────────
if ( get_transient( 'totosync_running' ) ) {
    http_response_code( 200 );
    exit( 'Already running.' );
}

// ── Close the HTTP connection so the AJAX caller gets its response now ────────
header( 'Content-Type: text/plain' );
header( 'Content-Length: 2' );
header( 'Connection: close' );
echo 'OK';

if ( function_exists( 'fastcgi_finish_request' ) ) {
    fastcgi_finish_request();
} else {
    flush();
}

// ── Run the sync ──────────────────────────────────────────────────────────────
ignore_user_abort( true );
set_time_limit( 0 );

set_transient( 'totosync_running', time(), 10 * MINUTE_IN_SECONDS );

if ( function_exists( 'totosync_run_sync' ) ) {
    totosync_run_sync();
}

delete_transient( 'totosync_running' );
