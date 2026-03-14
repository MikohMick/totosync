/* global totosyncAdmin, jQuery */
jQuery( function ( $ ) {
    'use strict';

    var $btn        = $( '#totosync-btn' );
    var $spinner    = $( '#totosync-spinner' );
    var $progWrap   = $( '#totosync-progress-wrap' );
    var $bar        = $( '#totosync-progress' );
    var $barLabel   = $( '#totosync-progress-label' );
    var $status     = $( '#totosync-status' );
    var $result     = $( '#totosync-result' );

    var pollTimer      = null;
    var lastSyncBefore = totosyncAdmin.last_sync;
    var POLL_INTERVAL  = 5000; // ms

    // If a sync was already running when the page loaded, restore the UI
    // and start polling immediately so the page stays live.
    if ( totosyncAdmin.running ) {
        setRunningUI();
        if ( totosyncAdmin.progress ) {
            updateBar( totosyncAdmin.progress );
        }
        schedulePoll();
    }

    // ── Sync Now button ───────────────────────────────────────────────────────
    $btn.on( 'click', function () {
        lastSyncBefore = totosyncAdmin.last_sync;
        setRunningUI();
        $barLabel.text( 'Starting\u2026' );

        $.post( totosyncAdmin.ajaxurl, {
            action: 'totosync_start_sync',
            nonce:  totosyncAdmin.nonce,
        } )
        .done( function ( res ) {
            if ( ! res.success ) {
                showError( res.data || 'Server error.' );
                return;
            }

            lastSyncBefore = res.data.last_sync;
            schedulePoll();
        } )
        .fail( function () {
            showError( 'Could not reach the server. Please try again.' );
        } );
    } );

    // ── Polling ───────────────────────────────────────────────────────────────
    function schedulePoll() {
        clearInterval( pollTimer );
        pollTimer = setInterval( poll, POLL_INTERVAL );
    }

    function poll() {
        $.post( totosyncAdmin.ajaxurl, {
            action: 'totosync_poll',
            nonce:  totosyncAdmin.nonce,
        } )
        .done( function ( res ) {
            if ( ! res.success ) {
                return; // Transient error — keep polling.
            }

            var d = res.data;

            // Update progress bar whenever data is available.
            if ( d.progress ) {
                updateBar( d.progress );
            }

            if ( ! d.running && d.last_sync > lastSyncBefore ) {
                // Sync finished and last_sync advanced — done.
                clearInterval( pollTimer );
                $bar.val( 100 );
                $barLabel.text( 'Done!' );
                $result.html(
                    '<div class="notice notice-success inline" style="display:block;">' +
                    '<p>Sync complete! Reloading\u2026</p></div>'
                );
                $status.empty();
                resetUI();
                setTimeout( function () { location.reload(); }, 1500 );

            } else if ( d.running ) {
                $status.text( 'Running in the background \u2014 you can safely navigate away.' );
            }
        } );
    }

    // ── Progress bar ─────────────────────────────────────────────────────────
    function updateBar( prog ) {
        var pct = prog.total > 0 ? Math.round( ( prog.processed / prog.total ) * 100 ) : 0;
        $progWrap.show();
        $bar.val( pct );
        $barLabel.text(
            'Processing ' + prog.processed + ' / ' + prog.total +
            ' products (' + pct + '%)'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    function setRunningUI() {
        $btn.prop( 'disabled', true );
        $spinner.css( 'visibility', 'visible' );
        $result.empty();
        $progWrap.show();
    }

    function resetUI() {
        $btn.prop( 'disabled', false );
        $spinner.css( 'visibility', 'hidden' );
    }

    function showError( msg ) {
        clearInterval( pollTimer );
        $progWrap.hide();
        $result.html(
            '<div class="notice notice-error inline" style="display:block;"><p>' +
            escHtml( msg ) + '</p></div>'
        );
        $status.empty();
        resetUI();
    }

    function escHtml( str ) {
        return $( '<span>' ).text( str ).html();
    }

    // ── Debug / Retry panel ───────────────────────────────────────────────────
    var $debugBtn     = $( '#totosync-debug-btn' );
    var $debugSku     = $( '#totosync-debug-sku' );
    var $debugSpinner = $( '#totosync-debug-spinner' );
    var $debugOutput  = $( '#totosync-debug-output' );
    var $debugLog     = $( '#totosync-debug-log' );

    $debugBtn.on( 'click', function () {
        var sku = $.trim( $debugSku.val() );
        if ( ! sku ) {
            alert( 'Please enter a SKU first.' );
            return;
        }

        $debugBtn.prop( 'disabled', true );
        $debugSpinner.css( 'visibility', 'visible' );
        $debugLog.empty();
        $debugOutput.show();
        $debugLog.append(
            '<li style="color:#555;">Fetching API and running debug sync for SKU <strong>' +
            escHtml( sku ) + '</strong>\u2026</li>'
        );

        $.post( totosyncAdmin.ajaxurl, {
            action: 'totosync_debug_item',
            nonce:  totosyncAdmin.nonce,
            sku:    sku,
        } )
        .done( function ( res ) {
            $debugLog.empty();
            if ( ! res.success ) {
                $debugLog.append(
                    '<li style="color:#c00;">' + escHtml( res.data || 'Server error.' ) + '</li>'
                );
                return;
            }

            var entries = res.data.log || [];
            entries.forEach( function ( entry ) {
                var color = entry.type === 'error'   ? '#c00'
                          : entry.type === 'warning' ? '#996600'
                          : entry.type === 'success' ? '#008a00'
                          : '#333';
                $debugLog.append(
                    '<li style="color:' + color + ';margin-bottom:2px;">' +
                    escHtml( entry.message ) + '</li>'
                );
            } );

            // Scroll to bottom so the final verification result is visible.
            $debugLog[0].scrollTop = $debugLog[0].scrollHeight;
        } )
        .fail( function () {
            $debugLog.empty();
            $debugLog.append( '<li style="color:#c00;">Request failed — could not reach the server.</li>' );
        } )
        .always( function () {
            $debugBtn.prop( 'disabled', false );
            $debugSpinner.css( 'visibility', 'hidden' );
        } );
    } );

    // Allow pressing Enter in the SKU field to trigger the debug.
    $debugSku.on( 'keydown', function ( e ) {
        if ( e.key === 'Enter' ) {
            $debugBtn.trigger( 'click' );
        }
    } );
} );
