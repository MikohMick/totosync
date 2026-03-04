<?php
/**
 * Plugin Name: ToToSync
 * Plugin URI:  https://github.com/MikohMick/totosync
 * Description: Syncs featured products from POS API into WooCommerce — variable products,
 *              attributes (Colour + Measurement), images, prices, and live stock levels.
 *              Runs automatically every 30 minutes via WP-Cron; also supports manual sync.
 * Version:     2.1.3
 * Author:      rindradev@gmail.com
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────────────────────
// Constants
// ─────────────────────────────────────────────────────────────────────────────

define( 'TOTOSYNC_VERSION',   '2.1.3' );
define( 'TOTOSYNC_POS_IP',    '197.248.191.179' );
define( 'TOTOSYNC_API_URL',   'http://shop.ruelsoftware.co.ke/api/FeaturedProducts/' . TOTOSYNC_POS_IP );
define( 'TOTOSYNC_CRON_HOOK', 'totosync_scheduled_sync' );
define( 'TOTOSYNC_LOG_OPT',   'totosync_sync_log' );
define( 'TOTOSYNC_LAST_OPT',  'totosync_last_sync' );
define( 'TOTOSYNC_PROG_KEY',  'totosync_progress' );

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────────

register_activation_hook(   __FILE__, 'totosync_activate' );
register_deactivation_hook( __FILE__, 'totosync_deactivate' );

add_action( 'admin_menu',            'totosync_admin_menu' );
add_action( 'admin_enqueue_scripts', 'totosync_enqueue_scripts' );
add_action( 'wp_ajax_totosync_start_sync', 'totosync_ajax_start_sync' );
add_action( 'wp_ajax_totosync_poll',       'totosync_ajax_poll' );
add_action( TOTOSYNC_CRON_HOOK,      'totosync_run_sync' );

// Register a 30-minute cron interval ("twicehourly" if WordPress doesn't ship one).
add_filter( 'cron_schedules', function ( $schedules ) {
    if ( ! isset( $schedules['twicehourly'] ) ) {
        $schedules['twicehourly'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display'  => 'Twice Per Hour (every 30 min)',
        ];
    }
    return $schedules;
} );

// ─────────────────────────────────────────────────────────────────────────────
// Activation / Deactivation
// ─────────────────────────────────────────────────────────────────────────────

function totosync_activate() {
    if ( ! wp_next_scheduled( TOTOSYNC_CRON_HOOK ) ) {
        wp_schedule_event( time(), 'twicehourly', TOTOSYNC_CRON_HOOK );
    }
}

function totosync_deactivate() {
    $ts = wp_next_scheduled( TOTOSYNC_CRON_HOOK );
    if ( $ts ) {
        wp_unschedule_event( $ts, TOTOSYNC_CRON_HOOK );
    }
    // Clear runtime transients so a stale lock can't block the next sync
    // after the plugin is re-enabled.
    delete_transient( 'totosync_running' );
    delete_transient( TOTOSYNC_PROG_KEY );
}

// ─────────────────────────────────────────────────────────────────────────────
// Admin UI
// ─────────────────────────────────────────────────────────────────────────────

function totosync_admin_menu() {
    add_menu_page(
        'ToToSync',
        'ToToSync',
        'manage_options',
        'totosync',
        'totosync_page',
        'dashicons-update',
        56
    );
}

function totosync_enqueue_scripts( $hook ) {
    if ( $hook !== 'toplevel_page_totosync' ) {
        return;
    }
    wp_enqueue_script(
        'totosync-admin',
        plugin_dir_url( __FILE__ ) . 'script.js',
        [ 'jquery' ],
        TOTOSYNC_VERSION,
        true
    );
    $prog = get_transient( TOTOSYNC_PROG_KEY );
    wp_localize_script( 'totosync-admin', 'totosyncAdmin', [
        'ajaxurl'   => admin_url( 'admin-ajax.php' ),
        'nonce'     => wp_create_nonce( 'totosync_nonce' ),
        'last_sync' => (int) get_option( TOTOSYNC_LAST_OPT, 0 ),
        'running'   => get_transient( 'totosync_running' ) ? true : false,
        'progress'  => $prog ? $prog : null,
    ] );
}

function totosync_page() {
    $last_sync        = get_option( TOTOSYNC_LAST_OPT );
    $log              = get_option( TOTOSYNC_LOG_OPT, [] );
    $next_cron        = wp_next_scheduled( TOTOSYNC_CRON_HOOK );
    $server_cron_mode = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
    $sync_running     = (bool) get_transient( 'totosync_running' );

    // ── Cron health calculation ───────────────────────────────────────────────
    if ( ! $last_sync ) {
        $health_dot   = '#aaa';
        $health_label = 'Never run';
        $health_ago   = 'No syncs have run yet.';
    } else {
        $age = time() - (int) $last_sync;
        if ( $age < 35 * MINUTE_IN_SECONDS ) {
            $health_dot   = '#46b450';
            $health_label = 'Healthy';
        } elseif ( $age < 90 * MINUTE_IN_SECONDS ) {
            $health_dot   = '#ffb900';
            $health_label = 'Running late';
        } else {
            $health_dot   = '#dc3232';
            $health_label = 'Not running';
        }
        $health_ago = 'Last run ' . human_time_diff( (int) $last_sync, time() ) . ' ago'
                    . ' &mdash; ' . esc_html( date_i18n( 'D, d M Y H:i:s', (int) $last_sync ) );
    }

    echo '<div class="wrap">';
    echo '<h1>ToToSync &mdash; WooCommerce Product Sync</h1>';

    // ── Status card ──────────────────────────────────────────────────────────
    echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:16px 20px;'
       . 'margin-bottom:20px;border-radius:4px;max-width:660px;">';
    echo '<h2 style="margin-top:0">Status</h2>';

    // Cron health row
    echo '<p style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">'
       . '<span style="display:inline-block;width:14px;height:14px;border-radius:50%;'
       . 'background:' . $health_dot . ';flex-shrink:0;"></span>'
       . '<strong>' . esc_html( $health_label ) . '</strong>'
       . ' &nbsp;<span style="color:#555;font-size:13px;">' . $health_ago . '</span>'
       . '</p>';

    if ( $sync_running ) {
        echo '<p style="color:#0073aa;font-size:13px;margin-top:0;">'
           . '&#9696; Sync is currently running&hellip;</p>';
    }

    // Cron mode row
    $sync_php_path = __DIR__ . '/sync.php';
    $php_bin       = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
    $cron_cmd      = '*/30 * * * * ' . $php_bin . ' ' . $sync_php_path
                   . ' >> /var/log/totosync.log 2>&1';

    if ( $server_cron_mode ) {
        echo '<p style="margin:6px 0;font-size:13px;">'
           . '<strong>Cron mode:</strong> '
           . '<span style="color:#46b450;">&#10003; Server cron active</span>'
           . ' (<code>DISABLE_WP_CRON</code> is set in <code>wp-config.php</code>)</p>';

        echo '<div style="font-size:12px;margin:8px 0 0;padding:10px 12px;'
           . 'background:#f8f8f8;border:1px solid #ddd;border-radius:3px;">';
        echo '<p style="margin:0 0 6px;"><strong>The green/grey dot above is how you know if cron is working.</strong> '
           . 'WordPress cannot directly detect an OS cron job &mdash; but if the dot turns green '
           . 'within 35 minutes of setup, your cron is firing correctly.</p>';
        echo '<p style="margin:0 0 4px;"><strong>To verify your crontab is set, run in terminal:</strong></p>';
        echo '<code style="display:block;word-break:break-all;padding:6px 8px;'
           . 'background:#fff;border:1px solid #ddd;margin-bottom:8px;">'
           . 'crontab -l</code>';
        echo '<p style="margin:0 0 4px;"><strong>You should see this line (or similar):</strong></p>';
        echo '<code style="display:block;word-break:break-all;padding:6px 8px;'
           . 'background:#fff;border:1px solid #ddd;">'
           . esc_html( $cron_cmd ) . '</code>';
        echo '</div>';
    } else {
        echo '<p style="margin:6px 0;font-size:13px;">'
           . '<strong>Cron mode:</strong> WP-Cron (fires on page visits)';
        if ( $next_cron ) {
            echo ' &mdash; next run in ~' . human_time_diff( time(), $next_cron );
        } else {
            echo ' &mdash; <span style="color:#dc3232;">not scheduled &mdash; '
               . 'deactivate &amp; reactivate the plugin</span>';
        }
        echo '</p>';

        echo '<div style="font-size:12px;margin:8px 0 0;padding:10px 12px;'
           . 'background:#fffbf0;border-left:3px solid #ffb900;">';
        echo '<p style="margin:0 0 6px;"><strong>Recommended:</strong> Switch to a real server cron for reliable 30-minute syncs.</p>';
        echo '<p style="margin:0 0 4px;">1. Add to <code>wp-config.php</code> (above the "stop editing" line):</p>';
        echo '<code style="display:block;padding:6px 8px;background:#fff;border:1px solid #ddd;margin-bottom:8px;">'
           . "define( 'DISABLE_WP_CRON', true );</code>";
        echo '<p style="margin:0 0 4px;">2. Run <code>crontab -e</code> and add:</p>';
        echo '<code style="display:block;word-break:break-all;padding:6px 8px;background:#fff;border:1px solid #ddd;">'
           . esc_html( $cron_cmd ) . '</code>';
        echo '</div>';
    }

    echo '<p style="margin:10px 0 0;font-size:13px;">'
       . '<strong>API endpoint:</strong><br>'
       . '<code>' . esc_html( TOTOSYNC_API_URL ) . '</code></p>';

    echo '</div>';

    // ── Manual sync ──────────────────────────────────────────────────────────
    $init_prog = get_transient( TOTOSYNC_PROG_KEY );

    echo '<div style="max-width:660px;margin-top:4px;">';
    echo '<button id="totosync-btn" class="button button-primary" '
       . 'style="height:36px;padding:0 20px;font-size:14px;"'
       . ( $sync_running ? ' disabled' : '' ) . '>Sync Now</button>';
    echo '<span id="totosync-spinner" class="spinner" '
       . 'style="float:none;margin:4px 0 0 8px;vertical-align:middle;'
       . 'visibility:' . ( $sync_running ? 'visible' : 'hidden' ) . ';"></span>';

    // Progress bar — shown immediately if a sync was in progress when the page loaded.
    $bar_style = ( $sync_running && $init_prog ) ? '' : 'display:none;';
    $bar_val   = $init_prog && $init_prog['total'] > 0
                   ? round( $init_prog['processed'] / $init_prog['total'] * 100 )
                   : 0;
    $bar_label = $init_prog
                   ? 'Processing ' . $init_prog['processed'] . ' / ' . $init_prog['total']
                     . ' products (' . $bar_val . '%)'
                   : '';

    echo '<div id="totosync-progress-wrap" style="margin-top:14px;' . $bar_style . '">';
    echo '<progress id="totosync-progress" value="' . $bar_val . '" max="100" '
       . 'style="width:100%;height:20px;display:block;"></progress>';
    echo '<p id="totosync-progress-label" style="margin:4px 0 0;color:#555;font-size:12px;">'
       . esc_html( $bar_label ) . '</p>';
    echo '</div>';

    echo '<div id="totosync-status" style="margin-top:8px;font-size:13px;color:#555;">';
    if ( $sync_running ) {
        echo 'Sync is running in the background &mdash; you can safely navigate away. '
           . 'This page updates automatically every 5 s, or <a href="">refresh manually</a>.';
    }
    echo '</div>';

    echo '<div id="totosync-result" style="margin-top:8px;"></div>';
    echo '</div>';

    // ── Sync log ─────────────────────────────────────────────────────────────
    if ( ! empty( $log ) ) {
        echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:16px 20px;'
           . 'margin-top:24px;border-radius:4px;max-width:660px;">';
        echo '<h2 style="margin-top:0">Last Sync Log</h2>';
        echo '<ul style="max-height:300px;overflow-y:auto;padding-left:20px;margin:0;">';
        foreach ( $log as $entry ) {
            $type  = $entry['type'] ?? 'info';
            $color = $type === 'error' ? '#c00' : ( $type === 'warning' ? '#996600' : '#333' );
            echo '<li style="color:' . $color . ';margin-bottom:3px;">'
               . esc_html( $entry['message'] ) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    echo '</div>'; // .wrap
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX handler — fire-and-forget background sync
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Kick off a manual sync in the background.
 *
 * Sends the JSON response to the browser immediately, then closes the HTTP
 * connection and continues running totosync_run_sync() server-side.
 * This means the browser can safely navigate away — the sync will complete
 * regardless of whether the admin page stays open.
 */
function totosync_ajax_start_sync() {
    check_ajax_referer( 'totosync_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    // Guard against double-triggers.
    if ( get_transient( 'totosync_running' ) ) {
        wp_send_json_success( [
            'already_running' => true,
            'last_sync'       => (int) get_option( TOTOSYNC_LAST_OPT, 0 ),
        ] );
        return;
    }

    // Mark sync as in-progress (expires after 10 min as a failsafe).
    set_transient( 'totosync_running', time(), 10 * MINUTE_IN_SECONDS );

    $last_sync_before = (int) get_option( TOTOSYNC_LAST_OPT, 0 );

    // Build the response body before we flush output buffers.
    $body = wp_json_encode( [
        'success' => true,
        'data'    => [
            'started'   => true,
            'last_sync' => $last_sync_before,
        ],
    ] );

    // Flush all output buffers accumulated by WordPress so far.
    while ( ob_get_level() ) {
        ob_end_clean();
    }

    // Send headers + response, then close the connection.
    // The browser receives the JSON and is free to navigate away.
    header( 'Content-Type: application/json; charset=UTF-8' );
    header( 'Connection: close' );
    header( 'Content-Length: ' . strlen( $body ) );
    echo $body;
    flush();

    // PHP-FPM: tell the SAPI the response is complete.
    if ( function_exists( 'fastcgi_finish_request' ) ) {
        fastcgi_finish_request();
    }

    // Continue running even if the client has disconnected.
    ignore_user_abort( true );
    set_time_limit( 300 );

    // Run the full sync — this is the same code the server cron calls.
    totosync_run_sync();
    delete_transient( 'totosync_running' );
    exit;
}

/**
 * Lightweight polling endpoint — returns the current sync state.
 * JS calls this every 5 s to detect when a background sync finishes.
 */
function totosync_ajax_poll() {
    check_ajax_referer( 'totosync_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    $prog = get_transient( TOTOSYNC_PROG_KEY );
    wp_send_json_success( [
        'last_sync' => (int) get_option( TOTOSYNC_LAST_OPT, 0 ),
        'running'   => (bool) get_transient( 'totosync_running' ),
        'progress'  => $prog ? $prog : null,
    ] );
}

// ─────────────────────────────────────────────────────────────────────────────
// Cron — automatic sync every 30 minutes
// ─────────────────────────────────────────────────────────────────────────────

function totosync_run_sync() {
    // Ensure the process doesn't get killed by a PHP execution time limit
    // when running via CLI cron or a long-lived server request.
    set_time_limit( 0 );

    $products = totosync_fetch_products();
    if ( is_wp_error( $products ) ) {
        error_log( '[ToToSync] API fetch failed: ' . $products->get_error_message() );
        return;
    }

    $total = count( $products );
    $log   = [ [
        'type'    => 'info',
        'message' => 'Auto-sync started at ' . date( 'Y-m-d H:i:s' ) . ' (' . $total . ' products)',
    ] ];

    set_transient( TOTOSYNC_PROG_KEY, [ 'processed' => 0, 'total' => $total ], 15 * MINUTE_IN_SECONDS );

    foreach ( $products as $i => $item ) {
        $log[] = totosync_process_product( $item );
        set_transient( TOTOSYNC_PROG_KEY, [ 'processed' => $i + 1, 'total' => $total ], 15 * MINUTE_IN_SECONDS );
    }

    // Trash products / variations that have disappeared from the API.
    $api_skus = array_values( array_filter( array_map(
        fn( $item ) => trim( $item['itemCode'] ?? '' ),
        $products
    ) ) );
    $log = array_merge( $log, totosync_trash_removed( $api_skus ) );

    delete_transient( TOTOSYNC_PROG_KEY );
    update_option( TOTOSYNC_LAST_OPT, time() );
    update_option( TOTOSYNC_LOG_OPT, array_slice( $log, 0, 300 ) );
    error_log( '[ToToSync] Auto-sync done. ' . $total . ' products processed.' );
}

// ─────────────────────────────────────────────────────────────────────────────
// API fetch
// ─────────────────────────────────────────────────────────────────────────────

function totosync_fetch_products() {
    $response = wp_remote_get( TOTOSYNC_API_URL, [
        'timeout'    => 30,
        'user-agent' => 'ToToSync/' . TOTOSYNC_VERSION,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        return new WP_Error( 'http_error', "API returned HTTP {$code}" );
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( ! is_array( $data ) ) {
        return new WP_Error( 'parse_error', 'API response is not a valid JSON array' );
    }

    return $data;
}

// ─────────────────────────────────────────────────────────────────────────────
// Product processing — entry point per item
// ─────────────────────────────────────────────────────────────────────────────

function totosync_process_product( array $item ) {
    $name        = trim( $item['itemName']        ?? '' );
    $sku         = trim( $item['itemCode']        ?? '' );
    $colour      = trim( $item['colour']          ?? '' );
    $measurement = trim( $item['measurement']     ?? '' );
    $price       = (float) ( $item['price1']      ?? 0 );
    $qty         = (int)   ( $item['quantity']    ?? 0 );
    $category    = trim( $item['productCategory'] ?? '' );
    $description = trim( $item['description']     ?? $name );
    $raw_images  = $item['imageUrls']             ?? [];

    if ( empty( $name ) ) {
        return [ 'type' => 'warning', 'message' => "Item #{$item['itemId']} has no name — skipped." ];
    }

    // Transform / filter image URLs:
    //   ftp://197.248.x.x/...  → http://197.248.x.x/...   (public IP, downloadable)
    //   ftp://192.168.x.x/...  → skipped                  (private IP, unreachable)
    //   http(s)://...          → kept as-is
    $images = [];
    foreach ( $raw_images as $url ) {
        $clean = totosync_transform_image_url( $url );
        if ( $clean ) {
            $images[] = $clean;
        }
    }

    try {
        if ( $colour !== '' && $measurement !== '' ) {
            return totosync_sync_variable(
                $name, $sku, $colour, $measurement,
                $price, $qty, $category, $description, $images
            );
        } else {
            return totosync_sync_simple(
                $name, $sku, $price, $qty, $category, $description, $images
            );
        }
    } catch ( Throwable $e ) {
        error_log( '[ToToSync] Exception for SKU ' . $sku . ': ' . $e->getMessage() );
        return [ 'type' => 'error', 'message' => "SKU {$sku}: " . $e->getMessage() ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Variable product + variation sync
// ─────────────────────────────────────────────────────────────────────────────

function totosync_sync_variable(
    $name, $sku, $colour, $measurement,
    $price, $qty, $category, $description, $images
) {
    // 1. Get or create the parent variable product.
    $parent_id = totosync_get_or_create_parent( $name, $category, $description );
    if ( ! $parent_id ) {
        return [ 'type' => 'error', 'message' => "Could not create parent product for '{$name}'" ];
    }

    // 2. Ensure global WooCommerce attribute taxonomies exist.
    $colour_attr_id      = totosync_ensure_wc_attribute( 'colour',      'Colour' );
    $measurement_attr_id = totosync_ensure_wc_attribute( 'measurement', 'Measurement' );

    // 3. Get or create the taxonomy terms for this specific colour/measurement.
    $colour_term_id      = totosync_ensure_term( 'pa_colour',      $colour );
    $measurement_term_id = totosync_ensure_term( 'pa_measurement', $measurement );

    // 4. Register both attribute + term with the parent product object.
    totosync_add_term_to_parent( $parent_id, 'pa_colour',      $colour_attr_id,      $colour_term_id );
    totosync_add_term_to_parent( $parent_id, 'pa_measurement', $measurement_attr_id, $measurement_term_id );

    // 5. Parent image (only if not already set).
    if ( ! has_post_thumbnail( $parent_id ) && ! empty( $images ) ) {
        totosync_attach_image( $parent_id, $images[0] );
    }

    // 6. Create or update the variation.
    $variation_id = totosync_get_or_create_variation(
        $parent_id,
        $colour,      $colour_term_id,
        $measurement, $measurement_term_id,
        $sku, $price, $qty, $description
    );

    if ( ! $variation_id ) {
        return [ 'type' => 'error', 'message' => "Could not create variation for SKU {$sku}" ];
    }

    // 7. Variation image (only if not already set).
    if ( ! has_post_thumbnail( $variation_id ) && ! empty( $images ) ) {
        totosync_attach_image( $variation_id, $images[0] );
    }

    // Bust the parent's cached price range so WooCommerce recalculates it.
    wc_delete_product_transients( $parent_id );

    $stock_msg = $qty > 0 ? "qty={$qty}" : 'out of stock';
    return [
        'type'    => 'success',
        'message' => "Synced variation SKU {$sku} ({$colour} / {$measurement}, {$stock_msg}) under '{$name}'",
    ];
}

/**
 * Get an existing variable parent product or create a new one.
 * Products are keyed by a custom meta _totosync_item_name so lookups
 * are SKU-safe even when the same item name appears across many variations.
 */
function totosync_get_or_create_parent( $name, $category, $description ) {
    $found = get_posts( [
        'post_type'      => 'product',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [ [ 'key' => '_totosync_item_name', 'value' => $name ] ],
    ] );

    $cat_id = totosync_get_or_create_category( $category );

    if ( ! empty( $found ) ) {
        // Update category; restore from trash if it was removed previously.
        $product = wc_get_product( $found[0] );
        if ( $product ) {
            $product->set_category_ids( [ $cat_id ] );
            $product->set_status( 'publish' );
            $product->save();
        }
        return (int) $found[0];
    }

    $product = new WC_Product_Variable();
    $product->set_name( $name );
    $product->set_description( $description );
    $product->set_status( 'publish' );
    $product->set_category_ids( [ $cat_id ] );
    $id = $product->save();

    if ( $id ) {
        update_post_meta( $id, '_totosync_item_name', $name );
    }

    return $id ?: false;
}

/**
 * Ensure a WooCommerce global attribute taxonomy exists (e.g. pa_colour).
 * Returns the attribute ID.
 */
function totosync_ensure_wc_attribute( $slug, $label ) {
    global $wpdb;

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s",
        $slug
    ) );

    if ( $row ) {
        return (int) $row->attribute_id;
    }

    $result = wc_create_attribute( [
        'name'         => $label,
        'slug'         => $slug,
        'type'         => 'select',
        'order_by'     => 'menu_order',
        'has_archives' => false,
    ] );

    if ( is_wp_error( $result ) ) {
        error_log( '[ToToSync] Failed to create WC attribute "' . $slug . '": ' . $result->get_error_message() );
        return 0;
    }

    // Make the taxonomy usable in the current PHP request without a reload.
    register_taxonomy( 'pa_' . $slug, [ 'product', 'product_variation' ] );
    delete_transient( 'wc_attribute_taxonomies' );

    return (int) $result;
}

/**
 * Ensure a taxonomy term exists. Returns the term ID.
 */
function totosync_ensure_term( $taxonomy, $value ) {
    $slug = sanitize_title( $value );
    $term = get_term_by( 'slug', $slug, $taxonomy );
    if ( $term ) {
        return (int) $term->term_id;
    }

    $result = wp_insert_term( $value, $taxonomy, [ 'slug' => $slug ] );
    if ( is_wp_error( $result ) ) {
        // Term may already exist under a slightly different slug.
        $existing = get_term_by( 'name', $value, $taxonomy );
        return $existing ? (int) $existing->term_id : 0;
    }

    return (int) $result['term_id'];
}

/**
 * Add a term to a parent variable product's attribute definition.
 * Uses the proper WC_Product_Attribute API so WooCommerce shows the
 * correct attribute options on the product page.
 */
function totosync_add_term_to_parent( $parent_id, $taxonomy, $attr_id, $term_id ) {
    if ( ! $term_id ) {
        return;
    }

    $product    = wc_get_product( $parent_id );
    if ( ! $product ) {
        return;
    }

    $attributes = $product->get_attributes();
    $changed    = false;

    if ( isset( $attributes[ $taxonomy ] ) ) {
        $attr    = $attributes[ $taxonomy ];
        $options = $attr->get_options();
        if ( ! in_array( $term_id, $options, true ) ) {
            $options[] = $term_id;
            $attr->set_options( $options );
            $attributes[ $taxonomy ] = $attr;
            $changed = true;
        }
    } else {
        $attr = new WC_Product_Attribute();
        $attr->set_id( $attr_id );
        $attr->set_name( $taxonomy );
        $attr->set_options( [ $term_id ] );
        $attr->set_visible( true );
        $attr->set_variation( true );
        $attributes[ $taxonomy ] = $attr;
        $changed = true;
    }

    if ( $changed ) {
        $product->set_attributes( $attributes );
        $product->save();
    }
}

/**
 * Create or update a product variation.
 * Lookup order: SKU → matching attributes on existing children → create new.
 * Returns the variation ID or false.
 */
function totosync_get_or_create_variation(
    $parent_id,
    $colour,      $colour_term_id,
    $measurement, $measurement_term_id,
    $sku, $price, $qty, $description
) {
    // Try to find by SKU (most reliable, avoids duplicates).
    $variation_id = $sku ? wc_get_product_id_by_sku( $sku ) : 0;

    if ( $variation_id ) {
        $variation = wc_get_product( $variation_id );
        if ( $variation && $variation->is_type( 'variation' ) ) {
            $variation->set_regular_price( $price );
            $variation->set_description( $description );
            $variation->set_status( 'publish' ); // Restore if previously trashed.
            totosync_set_stock( $variation, $qty );
            $variation->save();
            totosync_write_variation_attrs( $variation_id, $colour, $measurement );
            return $variation_id;
        }
    }

    // Fall back: scan existing children for matching colour + measurement.
    $parent = wc_get_product( $parent_id );
    if ( $parent && $parent->is_type( 'variable' ) ) {
        foreach ( $parent->get_children() as $child_id ) {
            $child = wc_get_product( $child_id );
            if ( ! $child ) {
                continue;
            }
            if ( strtolower( trim( $child->get_attribute( 'pa_colour' ) ) )      === strtolower( $colour ) &&
                 strtolower( trim( $child->get_attribute( 'pa_measurement' ) ) ) === strtolower( $measurement ) ) {
                $child->set_regular_price( $price );
                $child->set_sku( $sku );
                $child->set_description( $description );
                $child->set_status( 'publish' ); // Restore if previously trashed.
                totosync_set_stock( $child, $qty );
                $child->save();
                totosync_write_variation_attrs( $child_id, $colour, $measurement );
                return $child_id;
            }
        }
    }

    // Create a new variation.
    $variation = new WC_Product_Variation();
    $variation->set_parent_id( $parent_id );
    $variation->set_sku( $sku );
    $variation->set_regular_price( $price );
    $variation->set_description( $description );
    totosync_set_stock( $variation, $qty );
    $new_id = $variation->save();

    if ( $new_id ) {
        totosync_write_variation_attrs( $new_id, $colour, $measurement );
    }

    return $new_id ?: false;
}

/**
 * Set stock quantity, status, and management on a WC product object.
 * If qty = 0 the variation will appear as "Out of stock" — customers
 * cannot add it to their cart until stock is replenished.
 */
function totosync_set_stock( WC_Product $product, $qty ) {
    $product->set_manage_stock( true );
    $product->set_stock_quantity( $qty );
    $product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
    $product->set_backorders( 'no' );
}

/**
 * Write the attribute slug postmeta that WooCommerce reads to
 * display and match variation attributes on the product page.
 */
function totosync_write_variation_attrs( $variation_id, $colour, $measurement ) {
    update_post_meta( $variation_id, 'attribute_pa_colour',      sanitize_title( $colour ) );
    update_post_meta( $variation_id, 'attribute_pa_measurement', sanitize_title( $measurement ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// Simple product sync
// ─────────────────────────────────────────────────────────────────────────────

function totosync_sync_simple( $name, $sku, $price, $qty, $category, $description, $images ) {
    $cat_id     = totosync_get_or_create_category( $category );
    $product_id = $sku ? wc_get_product_id_by_sku( $sku ) : 0;

    if ( ! $product_id ) {
        // Fallback: look up by our custom meta.
        $found = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [ [ 'key' => '_totosync_item_name', 'value' => $name ] ],
        ] );
        if ( ! empty( $found ) ) {
            $product_id = (int) $found[0];
        }
    }

    if ( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( $product ) {
            $product->set_name( $name );
            $product->set_sku( $sku );
            $product->set_regular_price( $price );
            $product->set_description( $description );
            $product->set_category_ids( [ $cat_id ] );
            $product->set_status( 'publish' ); // Restore if previously trashed.
            totosync_set_stock( $product, $qty );
            $product->save();

            if ( ! has_post_thumbnail( $product_id ) && ! empty( $images ) ) {
                totosync_attach_image( $product_id, $images[0] );
            }

            $stock_msg = $qty > 0 ? "qty={$qty}" : 'out of stock';
            return [ 'type' => 'success', 'message' => "Updated simple product SKU {$sku} '{$name}' ({$stock_msg})" ];
        }
    }

    // Create new simple product.
    $product = new WC_Product_Simple();
    $product->set_name( $name );
    $product->set_sku( $sku );
    $product->set_regular_price( $price );
    $product->set_description( $description );
    $product->set_category_ids( [ $cat_id ] );
    $product->set_status( 'publish' );
    totosync_set_stock( $product, $qty );
    $new_id = $product->save();

    if ( $new_id ) {
        update_post_meta( $new_id, '_totosync_item_name', $name );
        if ( ! empty( $images ) ) {
            totosync_attach_image( $new_id, $images[0] );
        }
    }

    $stock_msg = $qty > 0 ? "qty={$qty}" : 'out of stock';
    return [ 'type' => 'success', 'message' => "Created simple product SKU {$sku} '{$name}' ({$stock_msg})" ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Category helper
// ─────────────────────────────────────────────────────────────────────────────

function totosync_get_or_create_category( $name ) {
    if ( empty( $name ) ) {
        return 0;
    }
    $term = get_term_by( 'name', $name, 'product_cat' );
    if ( $term ) {
        return (int) $term->term_id;
    }
    $result = wp_insert_term( $name, 'product_cat' );
    return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
}

// ─────────────────────────────────────────────────────────────────────────────
// Image URL transformation
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Transform a raw image URL from the POS API:
 *
 *   ftp://197.248.191.179/items_images/foo.png  →  http://197.248.191.179/items_images/foo.png
 *   ftp://192.168.0.30/items_images/foo.png     →  false   (private IP — skip image, keep product)
 *   http://197.248.191.179/foo.png              →  returned as-is
 *
 * Products whose imageUrls are ALL private/unreachable are still synced
 * to WooCommerce without a featured image. On the next cron run, once the
 * POS server is reachable at a public URL (e.g. after moving from 192.x to
 * 197.x), the image will be attached automatically because we only skip
 * setting a thumbnail when one already exists.
 *
 * @param  string $url Raw URL from the API.
 * @return string|false Transformed URL ready for download, or false to skip.
 */
function totosync_transform_image_url( $url ) {
    if ( empty( $url ) ) {
        return false;
    }

    $parsed = parse_url( $url );
    $scheme = strtolower( $parsed['scheme'] ?? '' );
    $host   = $parsed['host'] ?? '';
    $path   = $parsed['path'] ?? '/';

    // Any private / reserved / loopback address → skip the image.
    // The product is still synced; the thumbnail will be attached on the next
    // sync run once the API starts returning a reachable public-IP URL.
    if ( totosync_is_private_host( $host ) ) {
        return false;
    }

    if ( $scheme === 'ftp' ) {
        // Serve the file over HTTP from the public POS IP.
        return 'http://' . $host . $path;
    }

    if ( in_array( $scheme, [ 'http', 'https' ], true ) ) {
        return $url;
    }

    return false; // Unknown or unsupported scheme.
}

/**
 * Returns true when $host is a private, loopback, or link-local IP address
 * (e.g. 192.168.x.x, 10.x.x.x, 172.16-31.x.x, 127.x.x.x, 169.254.x.x).
 * Hostnames (not raw IPs) are assumed public and return false.
 */
function totosync_is_private_host( $host ) {
    if ( empty( $host ) ) {
        return true;
    }

    // Only raw IP addresses are checked; DNS names are treated as public.
    if ( filter_var( $host, FILTER_VALIDATE_IP ) === false ) {
        return false;
    }

    // Returns false when the IP IS private/reserved → meaning it IS private.
    return filter_var(
        $host,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

// ─────────────────────────────────────────────────────────────────────────────
// Image download and attachment
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Download $url and attach it as the featured image of $post_id.
 * Uses WordPress's native media_handle_sideload() so all metadata,
 * thumbnails, and MIME detection are handled automatically.
 *
 * @param  int    $post_id Attachment parent (product or variation).
 * @param  string $url     Fully qualified HTTP/HTTPS URL.
 * @return int|false Attachment ID on success, false on failure.
 */
function totosync_attach_image( $post_id, $url ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $tmp = download_url( $url, 15 ); // 15-second timeout — skip slow/hung image servers
    if ( is_wp_error( $tmp ) ) {
        error_log( '[ToToSync] download_url failed (' . $url . '): ' . $tmp->get_error_message() );
        return false;
    }

    // Resize to max 1200×1200 px at 85 % quality before sideloading.
    // This caps the stored original so WordPress's derived thumbnail sizes
    // (WooCommerce shop/catalogue/single) are generated from a lean source.
    $editor = wp_get_image_editor( $tmp );
    if ( ! is_wp_error( $editor ) ) {
        $editor->set_quality( 85 );
        $editor->resize( 1200, 1200, false ); // false = keep aspect ratio, no crop
        $editor->save( $tmp );                // overwrite temp file in place
    }

    $file_array = [
        'name'     => sanitize_file_name( basename( parse_url( $url, PHP_URL_PATH ) ) ),
        'tmp_name' => $tmp,
    ];

    $attachment_id = media_handle_sideload( $file_array, $post_id );

    // Always clean up the temp file even if sideload failed.
    if ( file_exists( $tmp ) ) {
        @unlink( $tmp );
    }

    if ( is_wp_error( $attachment_id ) ) {
        error_log( '[ToToSync] media_handle_sideload failed: ' . $attachment_id->get_error_message() );
        return false;
    }

    set_post_thumbnail( $post_id, $attachment_id );
    return $attachment_id;
}

// ─────────────────────────────────────────────────────────────────────────────
// Trash products / variations removed from the API
// ─────────────────────────────────────────────────────────────────────────────

/**
 * After a full sync, trash any WooCommerce product or variation that was
 * previously created by ToToSync but is no longer present in the API response.
 *
 * - Simple products: trashed when their SKU is absent from the API.
 * - Variations:      trashed when their SKU is absent from the API.
 * - Variable parent: trashed only when every one of its variations has been
 *                    trashed (no live children remain).
 *
 * Restoration is automatic: the next time a trashed item's SKU reappears in
 * the API the regular sync code finds it (post_status = 'any'), sets its
 * status back to 'publish', and restores its stock level.
 *
 * @param  string[] $api_skus All itemCode values seen in the current API response.
 * @return array[]  Log entries (type/message) for every trashed item.
 */
function totosync_trash_removed( array $api_skus ) {
    // Guard: if the API returned nothing, don't nuke the whole catalogue.
    if ( empty( $api_skus ) ) {
        return [];
    }

    $log     = [];
    $api_set = array_flip( $api_skus ); // O(1) SKU lookup.

    // All products managed by ToToSync (both simple and variable parents).
    $managed_ids = get_posts( [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [ [ 'key' => '_totosync_item_name' ] ],
    ] );

    foreach ( $managed_ids as $pid ) {
        $product = wc_get_product( $pid );
        if ( ! $product ) {
            continue;
        }

        // ── Simple product ────────────────────────────────────────────────────
        if ( $product->is_type( 'simple' ) ) {
            $sku = $product->get_sku();
            if ( $sku !== '' && ! isset( $api_set[ $sku ] ) ) {
                wp_trash_post( $pid );
                $log[] = [
                    'type'    => 'info',
                    'message' => "Trashed simple product SKU {$sku} '{$product->get_name()}' (removed from API)",
                ];
            }
            continue;
        }

        // ── Variable product — check each variation ───────────────────────────
        if ( $product->is_type( 'variable' ) ) {
            $has_live = false;

            // Query all children including any already-trashed ones.
            $children = get_posts( [
                'post_type'      => 'product_variation',
                'post_parent'    => $pid,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ] );

            foreach ( $children as $var_id ) {
                $variation = wc_get_product( $var_id );
                if ( ! $variation ) {
                    continue;
                }
                $sku = $variation->get_sku();

                if ( $sku === '' || isset( $api_set[ $sku ] ) ) {
                    // Unknown SKU or still present — treat as live.
                    $has_live = true;
                } else {
                    wp_trash_post( $var_id );
                    $log[] = [
                        'type'    => 'info',
                        'message' => "Trashed variation SKU {$sku} under '{$product->get_name()}' (removed from API)",
                    ];
                }
            }

            // Trash the parent only when no live variations remain.
            if ( ! $has_live ) {
                wp_trash_post( $pid );
                $log[] = [
                    'type'    => 'info',
                    'message' => "Trashed parent product '{$product->get_name()}' (all variations removed from API)",
                ];
            }
        }
    }

    return $log;
}
