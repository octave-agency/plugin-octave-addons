/*
FEATURED IMAGE COLUMN
-- Swaps or clears a post's featured image straight from the list table.
-- The server hands back the rebuilt cell rather than a bare id, so the table
-- always shows exactly what the next page load would.
---------------------------------------------------------- */

(function () {

	'use strict';

	if ( ! window.oaFeaturedImage || ! window.wp || ! window.wp.media ) {

		return;

	}

	var frame = null;
	var activeCell = null;

	/*
	UPDATE
	-- Sends one change and replaces the cell with the markup that comes back.
	-- An attachment id of zero is the request to clear the image.
	---------------------------------------------------------- */

	function update( cell, attachmentId ) {

		var body = new FormData();

		cell.classList.add( 'is-updating' );

		body.append( 'action', oaFeaturedImage.action );
		body.append( 'nonce', oaFeaturedImage.nonce );
		body.append( 'post_id', cell.getAttribute( 'data-post-id' ) );
		body.append( 'attachment_id', attachmentId );

		window.fetch( oaFeaturedImage.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {

			return response.json();

		} ).then( function ( result ) {

			if ( ! result || ! result.success || ! result.data || ! result.data.html ) {

				throw new Error( 'update-failed' );

			}

			cell.outerHTML = result.data.html;

		} ).catch( function () {

			cell.classList.remove( 'is-updating' );
			window.alert( oaFeaturedImage.errorText );

		} );

	}

	/*
	OPEN LIBRARY
	-- One media frame is shared by every row. Its selection is cleared each
	-- time it opens so the previous row's choice is never carried across.
	---------------------------------------------------------- */

	function openLibrary( cell ) {

		if ( ! frame ) {

			frame = wp.media( {
				title: oaFeaturedImage.frameTitle,
				button: { text: oaFeaturedImage.frameButton },
				library: { type: 'image' },
				multiple: false
			} );

			frame.on( 'open', function () {

				frame.state().get( 'selection' ).reset();

			} );

			frame.on( 'select', function () {

				var attachment = frame.state().get( 'selection' ).first();

				if ( attachment && activeCell ) {

					update( activeCell, attachment.id );

				}

			} );

		}

		activeCell = cell;
		frame.open();

	}

	document.addEventListener( 'click', function ( event ) {

		var button = event.target.closest( '[data-oa-fic-action]' );

		if ( ! button ) {

			return;

		}

		event.preventDefault();

		var cell = button.closest( '.oa-fic' );

		if ( ! cell ) {

			return;

		}

		if ( 'remove' === button.getAttribute( 'data-oa-fic-action' ) ) {

			if ( window.confirm( oaFeaturedImage.confirmRemove ) ) {

				update( cell, 0 );

			}

			return;

		}

		openLibrary( cell );

	} );

})();
