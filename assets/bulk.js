/* global AI4DBulk, jQuery */
( function ( $ ) {
    'use strict';

    // Localize step (AI4DBulk) is enqueued separately; bail safely if absent.
    if ( typeof AI4DBulk === 'undefined' ) { return; }

    var MAX_RETRIES = 3;
    var retries = 0;
    var running = false;

    function setRunning( on ) {
        running = on;
        $( '#ai4d-bulk-start, #ai4d-bulk-resume' ).prop( 'disabled', on );
    }

    function mode() {
        return $( 'input[name=ai4d_bulk_mode]:checked' ).val() || 'all';
    }

    function post( action, extra ) {
        var data = $.extend( { action: action, nonce: AI4DBulk.nonce }, extra || {} );
        return $.post( AI4DBulk.ajaxurl, data );
    }

    function render( p ) {
        var pct = p.total > 0 ? Math.round( ( p.cursor / p.total ) * 100 ) : 100;
        $( '#ai4d-bulk-bar' ).css( 'width', pct + '%' );
        $( '#ai4d-bulk-status' ).text(
            p.status + ' — ' + p.cursor + '/' + p.total +
            ' (ok: ' + p.succeeded + ', skipped: ' + p.skipped + ', failed: ' + p.failed + ')'
        );
    }

    function status( msg ) {
        $( '#ai4d-bulk-status' ).text( msg );
    }

    function loop() {
        if ( ! running ) { return; }
        post( 'ai4d_topics_bulk_step' )
            .done( function ( res ) {
                if ( ! res || ! res.success ) {
                    // Logical failure (server returned success:false): surface the
                    // reason and stop — retrying a deterministic error is pointless.
                    setRunning( false );
                    status( res && res.data && res.data.message ? res.data.message : 'Stopped — server error.' );
                    return;
                }
                retries = 0;
                render( res.data );
                if ( res.data.status === 'running' ) {
                    loop();
                } else {
                    setRunning( false );
                }
            } )
            .fail( retryStep ); // transport failure only
    }

    function retryStep() {
        retries++;
        if ( retries > MAX_RETRIES ) {
            setRunning( false );
            status( 'Stopped after repeated errors — see the event log.' );
            return;
        }
        setTimeout( loop, 1000 * retries );
    }

    $( '#ai4d-bulk-preview' ).on( 'click', function ( e ) {
        e.preventDefault();
        post( 'ai4d_topics_bulk_preview', { mode: mode() } ).done( function ( res ) {
            if ( res && res.success ) {
                var d = res.data;
                $( '#ai4d-bulk-preview-result' ).text(
                    d.eligible + ' will extract · ' + d.excluded_by_tag + ' excluded by tag'
                );
            }
        } );
    } );

    $( '#ai4d-bulk-start' ).on( 'click', function ( e ) {
        e.preventDefault();
        if ( running ) { return; }
        post( 'ai4d_topics_bulk_start', { mode: mode() } ).done( function ( res ) {
            if ( res && res.success ) {
                retries = 0;
                setRunning( true );
                render( res.data );
                loop();
            } else {
                status( res && res.data ? res.data.message : 'Could not start.' );
            }
        } );
    } );

    $( '#ai4d-bulk-resume' ).on( 'click', function ( e ) {
        e.preventDefault();
        if ( running ) { return; }
        // Continue draining the existing queue; no new start() call.
        retries = 0;
        setRunning( true );
        loop();
    } );

    $( '#ai4d-bulk-cancel' ).on( 'click', function ( e ) {
        e.preventDefault();
        setRunning( false );
        post( 'ai4d_topics_bulk_cancel' ).done( function ( res ) {
            if ( res && res.success ) { render( res.data ); }
        } );
    } );

    // Reflect any in-progress run on page load so a reload after closing the
    // tab shows current state; the operator clicks Resume to continue it.
    if ( AI4DBulk.progress ) {
        render( AI4DBulk.progress );
    }
} )( jQuery );
