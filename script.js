/* global totosyncAdmin, jQuery */
jQuery( function ( $ ) {
    'use strict';

    var BATCH     = 10;
    var $btn      = $( '#totosync-btn' );
    var $spinner  = $( '#totosync-spinner' );
    var $wrap     = $( '#totosync-progress-wrap' );
    var $bar      = $( '#totosync-progress' );
    var $label    = $( '#totosync-label' );
    var $result   = $( '#totosync-result' );

    $btn.on( 'click', function () {
        $btn.prop( 'disabled', true );
        $spinner.css( 'visibility', 'visible' );
        $wrap.show();
        $bar.val( 0 );
        $label.text( 'Fetching products from API\u2026' );
        $result.empty();

        runBatch( 0 );
    } );

    function runBatch( offset ) {
        $.post( totosyncAdmin.ajaxurl, {
            action:     'totosync_sync',
            nonce:      totosyncAdmin.nonce,
            offset:     offset,
            batch_size: BATCH,
        } )
        .done( function ( res ) {
            if ( ! res.success ) {
                showError( res.data || 'Unknown server error.' );
                return;
            }

            var d         = res.data;
            var processed = Math.min( offset + BATCH, d.total );
            var pct       = d.total > 0 ? Math.round( ( processed / d.total ) * 100 ) : 100;

            $bar.val( pct );
            $label.text( 'Processing ' + processed + ' / ' + d.total + ' products (' + pct + '%)' );

            if ( d.done ) {
                $bar.val( 100 );
                $label.text( 'Done! All ' + d.total + ' products synced.' );
                $result.html(
                    '<div class="notice notice-success inline" style="display:block;">' +
                    '<p>Sync complete. Reloading in 3 s to refresh the log\u2026</p></div>'
                );
                setTimeout( function () { location.reload(); }, 3000 );
                resetUI();
            } else {
                // Small pause between batches so we don't hammer the server.
                setTimeout( function () { runBatch( offset + BATCH ); }, 400 );
            }
        } )
        .fail( function ( xhr, status, err ) {
            showError( 'Request failed: ' + err );
        } );
    }

    function showError( msg ) {
        $result.html(
            '<div class="notice notice-error inline" style="display:block;"><p>' +
            escHtml( msg ) + '</p></div>'
        );
        resetUI();
    }

    function resetUI() {
        $btn.prop( 'disabled', false );
        $spinner.css( 'visibility', 'hidden' );
    }

    // Minimal XSS-safe escaper for dynamic content inserted into the DOM.
    function escHtml( str ) {
        return $( '<span>' ).text( str ).html();
    }
} );
