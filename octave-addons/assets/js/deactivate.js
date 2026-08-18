/*
DEACTIVATION GUARD
-- Intercepts deactivation of Octave Addons on the Plugins screen and asks
-- for confirmation before letting the request through
-- Covers the row action link and the bulk "Deactivate" action
---------------------------------------------------------- */

( function () {

    'use strict';

    if ( ! window.oaDeactivate ) {

        return;

    }

    var basename = oaDeactivate.basename;
    var modal    = null;
    var onConfirm = null;

    /*
    BUILD MODAL
    -- Creates the dialog once and wires its buttons
    ---------------------------------------------------------- */

    function buildModal() {

        modal = document.createElement( 'div' );
        modal.id = 'oaDeactivateModal';
        modal.setAttribute( 'aria-hidden', 'true' );
        modal.innerHTML =
            '<div class="oa-dm-overlay"></div>' +
            '<div class="oa-dm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="oaDeactivateTitle" aria-describedby="oaDeactivateBody">' +
                '<div class="oa-dm-icon" aria-hidden="true">' +
                    '<svg class="oa-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>' +
                '</div>' +
                '<h2 class="oa-dm-title" id="oaDeactivateTitle"></h2>' +
                '<p class="oa-dm-body" id="oaDeactivateBody"></p>' +
                '<div class="oa-dm-actions">' +
                    '<button type="button" class="oa-dm-cancel"></button>' +
                    '<button type="button" class="oa-dm-confirm"></button>' +
                '</div>' +
            '</div>';

        document.body.appendChild( modal );

        modal.querySelector( '.oa-dm-title' ).textContent   = oaDeactivate.title;
        modal.querySelector( '.oa-dm-cancel' ).textContent  = oaDeactivate.cancelText;
        modal.querySelector( '.oa-dm-confirm' ).textContent = oaDeactivate.confirmText;

        modal.querySelector( '.oa-dm-overlay' ).addEventListener( 'click', closeModal );
        modal.querySelector( '.oa-dm-cancel' ).addEventListener( 'click', closeModal );

        modal.querySelector( '.oa-dm-confirm' ).addEventListener( 'click', function () {

            var action = onConfirm;

            closeModal();

            if ( action ) {

                action();

            }

        } );

    }

    /*
    OPEN / CLOSE
    -- openModal stores the action to run if the user confirms
    ---------------------------------------------------------- */

    function openModal( message, confirmAction ) {

        if ( ! modal ) {

            buildModal();

        }

        onConfirm = confirmAction;

        modal.querySelector( '.oa-dm-body' ).textContent = message;
        modal.setAttribute( 'aria-hidden', 'false' );
        modal.classList.add( 'is-open' );
        modal.querySelector( '.oa-dm-cancel' ).focus();

    }

    function closeModal() {

        if ( ! modal ) {

            return;

        }

        modal.classList.remove( 'is-open' );
        modal.setAttribute( 'aria-hidden', 'true' );
        onConfirm = null;

    }

    document.addEventListener( 'keydown', function ( event ) {

        if ( 'Escape' === event.key && modal && modal.classList.contains( 'is-open' ) ) {

            closeModal();

        }

    } );

    /*
    ROW ACTION
    -- Guards the "Deactivate" link in the plugin's own table row
    ---------------------------------------------------------- */

    var row = document.querySelector( 'tr[data-plugin="' + basename + '"]' );

    if ( row ) {

        row.querySelectorAll( 'a' ).forEach( function ( link ) {

            if ( link.href.indexOf( 'action=deactivate' ) === -1 ) {

                return;

            }

            link.addEventListener( 'click', function ( event ) {

                event.preventDefault();

                openModal( oaDeactivate.message, function () {

                    window.location.href = link.href;

                } );

            } );

        } );

    }

    /*
    BULK ACTION
    -- Guards bulk deactivation whenever this plugin is among the selected rows
    ---------------------------------------------------------- */

    var checkbox = row ? row.querySelector( 'input[type="checkbox"]' ) : null;

    document.querySelectorAll( 'form#bulk-action-form' ).forEach( function ( form ) {

        form.addEventListener( 'submit', function ( event ) {

            if ( ! checkbox || ! checkbox.checked ) {

                return;

            }

            var selected = [];

            form.querySelectorAll( 'select[name="action"], select[name="action2"]' ).forEach( function ( select ) {

                selected.push( select.value );

            } );

            if ( selected.indexOf( 'deactivate-selected' ) === -1 ) {

                return;

            }

            event.preventDefault();

            openModal( oaDeactivate.bulkMessage, function () {

                form.submit();

            } );

        } );

    } );

}() );
