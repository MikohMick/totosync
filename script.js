/* global totosyncAdmin, jQuery */
jQuery( function ( $ ) {
    'use strict';

    var $btn     = $( '#totosync-btn' );
    var $spinner = $( '#totosync-spinner' );
    var $status  = $( '#totosync-status' );
    var $result  = $( '#totosync-result' );

    var pollTimer       = null;
    var lastSyncBefore  = totosyncAdmin.last_sync;
    var POLL_INTERVAL   = 5000; // ms

    // If a sync was already running when the page loaded, start polling immediately.
    if ( totosyncAdmin.running ) {
        setRunningUI( 'Sync is running in the background\u2026' );
        schedulePoll();
    }

    // ── Sync Now button ───────────────────────────────────────────────────────
    $btn.on( 'click', function () {
        lastSyncBefore = totosyncAdmin.last_sync; // snapshot before starting
        setRunningUI( 'Starting sync\u2026' );

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

            if ( res.data.already_running ) {
                setStatus(
                    'A sync is already running. Waiting for it to finish\u2026 ' +
                    'You can safely navigate away.'
                );
            } else {
                setStatus(
                    'Sync started in the background. ' +
                    'You can safely navigate away \u2014 this page updates automatically when done.'
                );
            }

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
                return; // Ignore transient errors; keep polling.
            }

            var d = res.data;

            if ( ! d.running && d.last_sync > lastSyncBefore ) {
                // Sync finished and last_sync timestamp advanced — success.
                clearInterval( pollTimer );
                $result.html(
                    '<div class="notice notice-success inline" style="display:block;">' +
                    '<p>Sync complete! Reloading\u2026</p></div>'
                );
                setStatus( '' );
                resetUI();
                setTimeout( function () { location.reload(); }, 1500 );

            } else if ( d.running ) {
                // Still running — update the timestamp in the status message.
                var ts = new Date().toLocaleTimeString();
                setStatus(
                    'Sync running in the background\u2026 (last checked ' + ts + '). ' +
                    'You can safely navigate away.'
                );
            }
            // If !running && last_sync hasn't changed, the sync hasn't started
            // on the server yet — keep polling silently.
        } );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    function setRunningUI( msg ) {
        $btn.prop( 'disabled', true );
        $spinner.css( 'visibility', 'visible' );
        $result.empty();
        setStatus( msg );
    }

    function resetUI() {
        $btn.prop( 'disabled', false );
        $spinner.css( 'visibility', 'hidden' );
    }

    function setStatus( msg ) {
        $status.html( msg );
    }

    function showError( msg ) {
        clearInterval( pollTimer );
        $result.html(
            '<div class="notice notice-error inline" style="display:block;"><p>' +
            escHtml( msg ) + '</p></div>'
        );
        setStatus( '' );
        resetUI();
    }

    function escHtml( str ) {
        return $( '<span>' ).text( str ).html();
    }
} );
