<?php
/**
 * ToToSync — Auto Sync
 *
 * Schedules recurring syncs via WooCommerce's Action Scheduler.
 * Settings are saved to wp_options; logs are written to
 * wp-content/uploads/totosync/autosync.log (overwritten each run).
 */

defined( 'ABSPATH' ) || exit;

define( 'TOTOSYNC_AUTOSYNC_HOOK',   'totosync_autosync_fire' );
define( 'TOTOSYNC_AUTOSYNC_GROUP',  'totosync' );
define( 'TOTOSYNC_AUTOSYNC_EN_OPT', 'totosync_autosync_enabled' );
define( 'TOTOSYNC_AUTOSYNC_IV_OPT', 'totosync_autosync_interval' );

// Register the Action Scheduler callback.
add_action( TOTOSYNC_AUTOSYNC_HOOK, 'totosync_autosync_run' );

// ── Log file ──────────────────────────────────────────────────────────────────

function totosync_autosync_log_path() {
    $upload_dir = wp_upload_dir();
    $dir        = trailingslashit( $upload_dir['basedir'] ) . 'totosync';
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    return $dir . '/autosync.log';
}

function totosync_autosync_write_log( array $log ) {
    $lines = [ '=== Auto Sync Run: ' . date( 'Y-m-d H:i:s' ) . ' ===' ];
    foreach ( $log as $entry ) {
        $type    = strtoupper( $entry['type'] ?? 'info' );
        $lines[] = '[' . $type . '] ' . ( $entry['message'] ?? '' );
    }
    file_put_contents( totosync_autosync_log_path(), implode( PHP_EOL, $lines ) . PHP_EOL );
}

function totosync_autosync_read_log() {
    $path = totosync_autosync_log_path();
    return file_exists( $path ) ? file_get_contents( $path ) : '';
}

// ── Scheduler helpers ─────────────────────────────────────────────────────────

function totosync_autosync_cancel() {
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( TOTOSYNC_AUTOSYNC_HOOK, [], TOTOSYNC_AUTOSYNC_GROUP );
    }
}

/**
 * Schedule an immediate run + a recurring action.
 * Used only when autosync is first enabled.
 */
function totosync_autosync_schedule( $interval_seconds ) {
    if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
        return false;
    }
    totosync_autosync_cancel();
    // Immediate one-off run.
    as_enqueue_async_action( TOTOSYNC_AUTOSYNC_HOOK, [], TOTOSYNC_AUTOSYNC_GROUP );
    // Recurring runs starting one interval from now.
    as_schedule_recurring_action(
        time() + (int) $interval_seconds,
        (int) $interval_seconds,
        TOTOSYNC_AUTOSYNC_HOOK,
        [],
        TOTOSYNC_AUTOSYNC_GROUP
    );
    return true;
}

// ── Save settings ─────────────────────────────────────────────────────────────

/**
 * Persist enable/interval settings and adjust the AS schedule accordingly.
 *
 * Behaviour:
 *   disabled → enabled   : cancel stale actions, run immediately, schedule recurring.
 *   enabled  → interval changed : reschedule only (no extra immediate run).
 *   enabled  → disabled  : cancel all scheduled actions.
 *
 * @param bool $enabled
 * @param int  $interval_seconds  Must be one of 900 / 1800 / 3600.
 */
function totosync_autosync_save_settings( $enabled, $interval_seconds ) {
    $was_enabled      = (bool) get_option( TOTOSYNC_AUTOSYNC_EN_OPT, false );
    $old_interval     = (int)  get_option( TOTOSYNC_AUTOSYNC_IV_OPT, 1800 );
    $interval_seconds = (int)  $interval_seconds;

    update_option( TOTOSYNC_AUTOSYNC_EN_OPT, (bool) $enabled, false );
    update_option( TOTOSYNC_AUTOSYNC_IV_OPT, $interval_seconds, false );

    if ( $enabled ) {
        if ( ! $was_enabled ) {
            // Freshly enabled — fire immediately and start the recurring schedule.
            totosync_autosync_schedule( $interval_seconds );
        } elseif ( $interval_seconds !== $old_interval ) {
            // Interval changed while already enabled — reschedule without extra run.
            totosync_autosync_cancel();
            as_schedule_recurring_action(
                time() + $interval_seconds,
                $interval_seconds,
                TOTOSYNC_AUTOSYNC_HOOK,
                [],
                TOTOSYNC_AUTOSYNC_GROUP
            );
        }
        // If enabled with no change: no action needed.
    } else {
        totosync_autosync_cancel();
    }
}

// ── Action Scheduler callback ─────────────────────────────────────────────────

function totosync_autosync_run() {
    totosync_run_sync();
    // totosync_run_sync() saves its log to TOTOSYNC_LOG_OPT — read it back
    // and overwrite the autosync log file so the UI can display it.
    $log = get_option( TOTOSYNC_LOG_OPT, [] );
    totosync_autosync_write_log( $log );
}

// ── AJAX: save settings ───────────────────────────────────────────────────────

function totosync_ajax_autosync_save() {
    check_ajax_referer( 'totosync_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    $enabled  = ! empty( $_POST['enabled'] );
    $interval = (int) ( $_POST['interval'] ?? 1800 );
    if ( ! in_array( $interval, [ 900, 1800, 3600 ], true ) ) {
        $interval = 1800;
    }

    if ( $enabled && ! function_exists( 'as_schedule_recurring_action' ) ) {
        wp_send_json_error( 'Action Scheduler is not available. Ensure WooCommerce is active.' );
    }

    totosync_autosync_save_settings( $enabled, $interval );

    // Calculate next scheduled run for the response.
    $next_ts = false;
    if ( $enabled && function_exists( 'as_next_scheduled_action' ) ) {
        $next_ts = as_next_scheduled_action( TOTOSYNC_AUTOSYNC_HOOK, [], TOTOSYNC_AUTOSYNC_GROUP );
    }

    wp_send_json_success( [
        'enabled'  => (bool) get_option( TOTOSYNC_AUTOSYNC_EN_OPT ),
        'interval' => (int)  get_option( TOTOSYNC_AUTOSYNC_IV_OPT, 1800 ),
        'next_run' => $next_ts ? (int) $next_ts : 0,
    ] );
}

// ── AJAX: read log ────────────────────────────────────────────────────────────

function totosync_ajax_autosync_log() {
    check_ajax_referer( 'totosync_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }
    wp_send_json_success( [ 'log' => totosync_autosync_read_log() ] );
}
