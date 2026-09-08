/*
OA COPY TEXT
-- Copies a value to the clipboard and swaps the button label while it holds
-- Falls back to a hidden textarea where the async Clipboard API is blocked,
-- which is the case on any page not served over HTTPS
---------------------------------------------------------- */

( function () {

    'use strict';

    /*
    RESOLVE VALUE
    -- Either the stored value or the text of the element it points at
    ---------------------------------------------------------- */

    function resolveValue( wrap ) {

        if ( 'selector' === wrap.dataset.source ) {

            var selector = ( wrap.dataset.selector || '' ).trim();

            if ( ! selector ) {

                return '';

            }

            var target = document.querySelector( selector );

            if ( ! target ) {

                return '';

            }

            return ( target.value || target.textContent || '' ).trim();

        }

        return wrap.dataset.value || '';

    }

    /*
    LEGACY COPY
    -- execCommand path for insecure contexts
    ---------------------------------------------------------- */

    function legacyCopy( text ) {

        var field = document.createElement( 'textarea' );

        field.value = text;
        field.setAttribute( 'readonly', '' );
        field.style.position = 'fixed';
        field.style.top = '-1000px';
        field.style.opacity = '0';

        document.body.appendChild( field );
        field.select();

        var copied = false;

        try {

            copied = document.execCommand( 'copy' );

        } catch ( error ) {

            copied = false;

        }

        document.body.removeChild( field );

        return copied;

    }

    /*
    COPY
    -- Resolves to true when the value reached the clipboard
    ---------------------------------------------------------- */

    function copy( text ) {

        if ( navigator.clipboard && window.isSecureContext ) {

            return navigator.clipboard.writeText( text ).then( function () {

                return true;

            } ).catch( function () {

                return legacyCopy( text );

            } );

        }

        return Promise.resolve( legacyCopy( text ) );

    }

    /*
    FEEDBACK
    -- Swaps the label, announces the change, then restores the original
    ---------------------------------------------------------- */

    function feedback( wrap ) {

        var label = wrap.querySelector( '[data-oa-copy-label]' );
        var live  = wrap.querySelector( '.oa_copy_text-live' );
        var reset = ( parseFloat( wrap.dataset.resetAfter ) || 2 ) * 1000;

        wrap.classList.add( 'is-copied' );

        if ( label ) {

            label.textContent = wrap.dataset.labelCopied || 'Copied!';

        }

        if ( live ) {

            live.textContent = wrap.dataset.labelCopied || 'Copied!';

        }

        window.clearTimeout( wrap.oaCopyTimer );

        wrap.oaCopyTimer = window.setTimeout( function () {

            wrap.classList.remove( 'is-copied' );

            if ( label ) {

                label.textContent = wrap.dataset.label || 'Copy';

            }

            if ( live ) {

                live.textContent = '';

            }

        }, reset );

    }

    /*
    INIT
    -- Public entry point; the builder calls this again on every edit
    ---------------------------------------------------------- */

    window.oaCopyTextInit = function () {

        document.querySelectorAll( '[data-oa-copy]' ).forEach( function ( wrap ) {

            var button = wrap.querySelector( '[data-oa-copy-button]' );

            if ( ! button || wrap.oaCopyBound ) {

                return;

            }

            wrap.oaCopyBound = true;

            button.addEventListener( 'click', function () {

                var value = resolveValue( wrap );

                if ( ! value ) {

                    return;

                }

                copy( value ).then( function ( copied ) {

                    if ( copied ) {

                        feedback( wrap );

                    }

                } );

            } );

        } );

    };

    if ( 'loading' === document.readyState ) {

        document.addEventListener( 'DOMContentLoaded', window.oaCopyTextInit );

    } else {

        window.oaCopyTextInit();

    }

}() );
