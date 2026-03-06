<?php
/**
 * Plugin Name: ToToSync
 * Plugin URI:  https://github.com/MikohMick/totosync
 * Description: Syncs featured products from POS API into WooCommerce — variable products,
 *              attributes (Colour + Measurement), images, prices, and live stock levels.
 *              Manual sync only — no automatic scheduling.
 * Version:     2.3.0
 * Author:      rindradev@gmail.com
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────────────────────
// Constants
// ─────────────────────────────────────────────────────────────────────────────

define( 'TOTOSYNC_VERSION',   '2.3.0' );
define( 'TOTOSYNC_POS_IP',    '197.248.191.179' );
define( 'TOTOSYNC_API_URL',   'http://shop.ruelsoftware.co.ke/api/FeaturedProducts/' . TOTOSYNC_POS_IP );
define( 'TOTOSYNC_LOG_OPT',   'totosync_sync_log' );
define( 'TOTOSYNC_LAST_OPT',  'totosync_last_sync' );
define( 'TOTOSYNC_PROG_KEY',  'totosync_progress' );

// ── Test mode ──────────────────────────────────────────────────────────────────
// Set to a positive integer to process only that many API items across a curated
// mix of scenarios (multi-variation, single-variation, with/without attributes).
// Set to 0 (or remove) to process the full catalogue.
define( 'TOTOSYNC_TEST_LIMIT', 0 );

// ── Product name filter (for targeted testing) ─────────────────────────────────
// Set to a non-empty string to process ONLY items whose itemName exactly matches
// this value. The full API is still fetched; only the matching product is synced.
// Trash step is skipped so other products are not affected.
// Set to '' (empty string) to process all products normally.
define( 'TOTOSYNC_TEST_PRODUCT', 'Baby T-shirt Set Of 5' );

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ─────────────────────────────────────────────────────────────────────────────

register_activation_hook(   __FILE__, 'totosync_activate' );
register_deactivation_hook( __FILE__, 'totosync_deactivate' );

add_action( 'admin_menu',            'totosync_admin_menu' );
add_action( 'admin_enqueue_scripts', 'totosync_enqueue_scripts' );
add_action( 'wp_ajax_totosync_start_sync', 'totosync_ajax_start_sync' );
add_action( 'wp_ajax_totosync_poll',       'totosync_ajax_poll' );

// ─────────────────────────────────────────────────────────────────────────────
// Activation / Deactivation
// ─────────────────────────────────────────────────────────────────────────────

function totosync_activate() {
    // Generate a listener secret used to authenticate sync-listener.php calls.
    if ( ! get_option( 'totosync_listener_secret' ) ) {
        update_option( 'totosync_listener_secret', wp_generate_password( 32, false ), false );
    }
}

function totosync_deactivate() {
    // Clear runtime transients so a stale lock can't block the next manual sync.
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
    $last_sync    = get_option( TOTOSYNC_LAST_OPT );
    $log          = get_option( TOTOSYNC_LOG_OPT, [] );
    $sync_running = (bool) get_transient( 'totosync_running' );

    echo '<div class="wrap">';
    echo '<h1>ToToSync &mdash; WooCommerce Product Sync</h1>';

    // ── Status card ──────────────────────────────────────────────────────────
    echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:16px 20px;'
       . 'margin-bottom:20px;border-radius:4px;max-width:660px;">';
    echo '<h2 style="margin-top:0">Status</h2>';

    if ( $last_sync ) {
        echo '<p style="margin:0 0 6px;font-size:13px;">'
           . '<strong>Last sync:</strong> '
           . esc_html( date_i18n( 'D, d M Y H:i:s', (int) $last_sync ) )
           . ' (' . human_time_diff( (int) $last_sync, time() ) . ' ago)</p>';
    } else {
        echo '<p style="margin:0 0 6px;font-size:13px;color:#888;">No syncs have run yet.</p>';
    }

    if ( $sync_running ) {
        echo '<p style="color:#0073aa;font-size:13px;margin:0;">'
           . '&#9696; Sync is currently running&hellip;</p>';
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
 * Makes a non-blocking HTTP POST to sync-listener.php and immediately
 * returns JSON to the browser. The listener handles the actual sync,
 * so the button always responds instantly regardless of server config.
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

    // Fire-and-forget: POST to sync-listener.php with timeout=0.01 so
    // wp_remote_post returns before the listener responds.
    $secret = get_option( 'totosync_listener_secret', '' );
    wp_remote_post(
        plugins_url( 'sync-listener.php', __FILE__ ),
        [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
            'cookies'   => [],
            'body'      => [ 'totosync_secret' => $secret ],
        ]
    );

    wp_send_json_success( [
        'started'   => true,
        'last_sync' => (int) get_option( TOTOSYNC_LAST_OPT, 0 ),
    ] );
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
// Sync runner
// ─────────────────────────────────────────────────────────────────────────────

function totosync_run_sync() {
    set_time_limit( 0 );

    $products = totosync_fetch_products();
    if ( is_wp_error( $products ) ) {
        error_log( '[ToToSync] API fetch failed: ' . $products->get_error_message() );
        return;
    }

    // Group items by itemName — the name identifies the parent product;
    // each item in the group becomes a variation regardless of attribute count.
    $groups = [];
    foreach ( $products as $item ) {
        $name = trim( $item['itemName'] ?? '' );
        if ( $name !== '' ) {
            $groups[ $name ][] = $item;
        }
    }

    // ── Product name filter ───────────────────────────────────────────────────
    // When TOTOSYNC_TEST_PRODUCT is set, keep only the matching group so we can
    // test a single product's full variation set without touching the rest.
    $filter_name = defined( 'TOTOSYNC_TEST_PRODUCT' ) ? trim( TOTOSYNC_TEST_PRODUCT ) : '';
    if ( $filter_name !== '' ) {
        $groups = isset( $groups[ $filter_name ] ) ? [ $filter_name => $groups[ $filter_name ] ] : [];
    }

    // ── Test mode ────────────────────────────────────────────────────────────
    // When TOTOSYNC_TEST_LIMIT > 0, select a curated mix of up to that many
    // items covering all sync scenarios, then skip the trash step so we don't
    // accidentally trash products not included in the test run.
    $test_mode = ( $filter_name !== '' ) || ( defined( 'TOTOSYNC_TEST_LIMIT' ) && TOTOSYNC_TEST_LIMIT > 0 );
    if ( defined( 'TOTOSYNC_TEST_LIMIT' ) && TOTOSYNC_TEST_LIMIT > 0 ) {
        $groups = totosync_select_test_groups( $groups, TOTOSYNC_TEST_LIMIT );
    }

    // Flatten groups back to a flat list so we can count and track progress.
    $items_to_process = [];
    foreach ( $groups as $items ) {
        foreach ( $items as $item ) {
            $items_to_process[] = $item;
        }
    }

    $total = count( $items_to_process );
    $mode_label = '';
    if ( $filter_name !== '' ) {
        $mode_label = '[FILTER: "' . $filter_name . '"] ';
    } elseif ( defined( 'TOTOSYNC_TEST_LIMIT' ) && TOTOSYNC_TEST_LIMIT > 0 ) {
        $mode_label = '[TEST MODE – ' . TOTOSYNC_TEST_LIMIT . ' items] ';
    }
    $log = [ [
        'type'    => 'info',
        'message' => $mode_label . 'Sync started at ' . date( 'Y-m-d H:i:s' )
                   . ' (' . $total . ' items across ' . count( $groups ) . ' products)',
    ] ];

    set_transient( TOTOSYNC_PROG_KEY, [ 'processed' => 0, 'total' => $total ], 15 * MINUTE_IN_SECONDS );

    $processed = 0;
    foreach ( $items_to_process as $item ) {
        $log[] = totosync_process_product( $item );
        $processed++;
        set_transient( TOTOSYNC_PROG_KEY, [ 'processed' => $processed, 'total' => $total ], 15 * MINUTE_IN_SECONDS );
    }

    // Trash removed products — skipped in test mode since we intentionally
    // only processed a subset of the catalogue; trashing would remove everything else.
    if ( ! $test_mode ) {
        $api_skus = array_values( array_filter( array_map(
            fn( $item ) => trim( $item['itemCode'] ?? '' ),
            $products
        ) ) );
        $log = array_merge( $log, totosync_trash_removed( $api_skus ) );
    } else {
        $log[] = [
            'type'    => 'info',
            'message' => 'Trash step skipped in test mode.',
        ];
    }

    delete_transient( TOTOSYNC_PROG_KEY );
    update_option( TOTOSYNC_LAST_OPT, time() );
    update_option( TOTOSYNC_LOG_OPT, array_slice( $log, 0, 300 ) );
    error_log( '[ToToSync] Sync done. ' . $total . ' items processed.' );
}

/**
 * From all groups, select up to $limit items covering every sync scenario:
 *
 *  Scenario A — Variable with attributes + multiple variations + in stock:
 *               Groups that have >1 item AND at least one item with colour or measurement AND qty > 0.
 *  Scenario B — Variable with attributes + one variation (in or out of stock):
 *               Groups that have exactly 1 item with colour or measurement.
 *  Scenario C — No attributes (colour and measurement both empty):
 *               Any group where all items lack colour and measurement.
 *
 * Groups are drawn from each bucket in round-robin order until $limit is reached.
 *
 * @param  array $groups  All groups keyed by product name.
 * @param  int   $limit   Maximum total items to return.
 * @return array          Trimmed groups array.
 */
function totosync_select_test_groups( array $groups, int $limit ): array {
    $bucket_a = []; // multi-variation, has attributes, in-stock
    $bucket_b = []; // single-variation, has attributes
    $bucket_c = []; // no attributes at all

    foreach ( $groups as $name => $items ) {
        $has_attrs   = false;
        $has_instock = false;
        foreach ( $items as $item ) {
            if ( trim( $item['colour'] ?? '' ) !== '' || trim( $item['measurement'] ?? '' ) !== '' ) {
                $has_attrs = true;
            }
            if ( (int) ( $item['quantity'] ?? 0 ) > 0 ) {
                $has_instock = true;
            }
        }

        if ( ! $has_attrs ) {
            $bucket_c[ $name ] = $items;
        } elseif ( count( $items ) > 1 && $has_instock ) {
            $bucket_a[ $name ] = $items;
        } else {
            $bucket_b[ $name ] = $items;
        }
    }

    // Round-robin pull from buckets until the limit is reached.
    $selected  = [];
    $remaining = $limit;
    $buckets   = array_filter( [ $bucket_a, $bucket_b, $bucket_c ] );

    while ( $remaining > 0 && ! empty( $buckets ) ) {
        foreach ( $buckets as $key => $bucket ) {
            if ( $remaining <= 0 ) {
                break;
            }
            // Pick the first group from this bucket.
            reset( $bucket );
            $gname = key( $bucket );
            $items = $bucket[ $gname ];

            // Clip the group's items if they alone exceed the remaining budget.
            $items                  = array_slice( $items, 0, $remaining );
            $selected[ $gname ]     = $items;
            $remaining             -= count( $items );
            unset( $buckets[ $key ][ $gname ] );

            if ( empty( $buckets[ $key ] ) ) {
                unset( $buckets[ $key ] );
            }
        }
    }

    return $selected;
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
        // All products are variable — the name groups items into a parent;
        // each API item becomes one variation under that parent.
        return totosync_sync_variable(
            $name, $sku, $colour, $measurement,
            $price, $qty, $category, $description, $images
        );
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

    // 2. Ensure WC attribute taxonomies + terms — only for non-empty values.
    $colour_attr_id      = 0;
    $colour_term_id      = 0;
    $measurement_attr_id = 0;
    $measurement_term_id = 0;

    if ( $colour !== '' ) {
        $colour_attr_id = totosync_ensure_wc_attribute( 'colour', 'Colour' );
        $colour_term_id = totosync_ensure_term( 'pa_colour', $colour );
        totosync_add_term_to_parent( $parent_id, 'pa_colour', $colour_attr_id, $colour_term_id );
    }

    if ( $measurement !== '' ) {
        $measurement_attr_id = totosync_ensure_wc_attribute( 'measurement', 'Measurement' );
        $measurement_term_id = totosync_ensure_term( 'pa_measurement', $measurement );
        totosync_add_term_to_parent( $parent_id, 'pa_measurement', $measurement_attr_id, $measurement_term_id );
    }

    // 3. Parent image (only if not already set).
    if ( ! has_post_thumbnail( $parent_id ) && ! empty( $images ) ) {
        totosync_attach_image( $parent_id, $images[0] );
    }

    // 4. Create or update the variation.
    $variation_id = totosync_get_or_create_variation(
        $parent_id,
        $colour,      $colour_term_id,
        $measurement, $measurement_term_id,
        $sku, $price, $qty, $description
    );

    if ( ! $variation_id ) {
        return [ 'type' => 'error', 'message' => "Could not create variation for SKU {$sku}" ];
    }

    // 5. Variation image (only if not already set).
    if ( ! has_post_thumbnail( $variation_id ) && ! empty( $images ) ) {
        totosync_attach_image( $variation_id, $images[0] );
    }

    // Bust the parent's cached price range so WooCommerce recalculates it.
    wc_delete_product_transients( $parent_id );

    $attr_parts = array_filter( [ $colour, $measurement ] );
    $stock_msg  = $qty > 0 ? "qty={$qty}" : 'out of stock';
    return [
        'type'    => 'success',
        'message' => "Synced variation SKU {$sku} (" . implode( ' / ', $attr_parts ) . ", {$stock_msg}) under '{$name}'",
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
        $product = wc_get_product( $found[0] );
        if ( $product ) {
            // If a previous sync created this as a simple product (e.g. when
            // colour/measurement were absent), convert it to variable in place.
            if ( ! $product->is_type( 'variable' ) ) {
                wp_set_object_terms( $found[0], 'variable', 'product_type' );
                $product = new WC_Product_Variable( $found[0] );
            }
            // Update category; restore from trash if it was removed previously.
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
 *
 * Root cause of previous bug: WooCommerce's $product->save() calls
 * wp_set_object_terms() WITHOUT $append=true, so each per-variation save
 * replaced all previously accumulated terms with only the current term.
 * Because items are processed one at a time, the parent always ended up
 * with only the last-saved variation's single term per attribute.
 *
 * Fix: bypass the WC_Product object layer for term assignment entirely.
 * Use wp_set_object_terms($append=true) so terms always accumulate, and
 * manage _product_attributes meta directly for the attribute definition
 * (is_visible / is_variation flags). WooCommerce reads taxonomy attribute
 * options from wp_get_object_terms(), not from the meta 'value' field,
 * so this is the canonical, cache-safe way to register options.
 */
function totosync_add_term_to_parent( $parent_id, $taxonomy, $attr_id, $term_id ) {
    if ( ! $term_id ) {
        return;
    }

    // 1. Append the term to the product's taxonomy relationship.
    //    $append=true means existing terms are NEVER replaced — only new
    //    ones are added — so every variation's terms accumulate correctly.
    wp_set_object_terms( $parent_id, [ $term_id ], $taxonomy, /* append */ true );

    // 2. Ensure the attribute definition row exists in _product_attributes.
    //    For taxonomy attributes WooCommerce ignores the 'value' field and
    //    reads options via wp_get_object_terms(), so 'value' stays empty.
    $raw = get_post_meta( $parent_id, '_product_attributes', true );
    if ( ! is_array( $raw ) ) {
        $raw = [];
    }

    if ( ! isset( $raw[ $taxonomy ] ) ) {
        $raw[ $taxonomy ] = [
            'name'         => $taxonomy,
            'value'        => '',
            'position'     => count( $raw ),
            'is_visible'   => 1,
            'is_variation' => 1,
            'is_taxonomy'  => 1,
        ];
        update_post_meta( $parent_id, '_product_attributes', $raw );
        wc_delete_product_transients( $parent_id );
        clean_post_cache( $parent_id );
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
    // Use a fresh DB query instead of $parent->get_children() to avoid WC object-cache
    // returning a stale children list (e.g. a variation created earlier in this sync run).
    $children = get_posts( [
        'post_type'      => 'product_variation',
        'post_parent'    => $parent_id,
        'post_status'    => [ 'publish', 'private' ],
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    foreach ( $children as $child_id ) {
        // Compare against the stored slug (what write_variation_attrs saves) rather than
        // get_attribute() which may return a term label or a cached empty string.
        $stored_colour      = get_post_meta( $child_id, 'attribute_pa_colour',      true );
        $stored_measurement = get_post_meta( $child_id, 'attribute_pa_measurement', true );

        if ( sanitize_title( $colour )      === $stored_colour &&
             sanitize_title( $measurement ) === $stored_measurement ) {
            $child = wc_get_product( $child_id );
            if ( ! $child ) {
                continue;
            }
            $child->set_regular_price( $price );
            $child->set_sku( $sku );
            $child->set_description( $description );
            $child->set_status( 'publish' ); // Restore if previously trashed.
            totosync_set_stock( $child, $qty );
            $child->save();
            totosync_write_variation_attrs( $child_id, $colour, $measurement );
            WC_Product_Variable::sync( $parent_id );
            return $child_id;
        }
    }

    // Create a new variation.
    $variation = new WC_Product_Variation();
    $variation->set_parent_id( $parent_id );
    $variation->set_sku( $sku );
    $variation->set_regular_price( $price );
    $variation->set_description( $description );
    $variation->set_status( 'publish' );
    totosync_set_stock( $variation, $qty );
    $new_id = $variation->save();

    if ( $new_id ) {
        totosync_write_variation_attrs( $new_id, $colour, $measurement );
        WC_Product_Variable::sync( $parent_id );
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
    // Only write postmeta for attributes that are actually set — an empty string
    // tells WooCommerce to match "any" value, making variations indistinguishable.
    if ( $colour !== '' ) {
        update_post_meta( $variation_id, 'attribute_pa_colour', sanitize_title( $colour ) );
    }
    if ( $measurement !== '' ) {
        update_post_meta( $variation_id, 'attribute_pa_measurement', sanitize_title( $measurement ) );
    }
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
