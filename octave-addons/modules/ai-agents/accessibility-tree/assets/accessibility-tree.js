/*
ACCESSIBILITY TREE REPAIRS
-- Adds a missing accessible name and role to Breakdance controls that ship
-- without one. Each repair targets stable semantic classes only, never the
-- generated per-instance classes, and leaves existing semantics alone.
---------------------------------------------------------- */

( function () {

	'use strict';

	/*
	HAS ACCESSIBLE NAME
	-- True when the author or the markup already names the control, whether
	-- by aria-label, aria-labelledby, a title or a label holding real text.
	-- A present aria-labelledby always counts, so author intent is never
	-- second-guessed.
	---------------------------------------------------------- */

	function hasAccessibleName( control ) {

		if ( '' !== ( control.getAttribute( 'aria-label' ) || '' ).trim() ) {

			return true;

		}

		if ( '' !== ( control.getAttribute( 'aria-labelledby' ) || '' ).trim() ) {

			return true;

		}

		if ( '' !== ( control.getAttribute( 'title' ) || '' ).trim() ) {

			return true;

		}

		return Array.prototype.some.call( control.labels || [], function ( label ) {

			return '' !== label.textContent.trim();

		} );

	}

	/*
	UNIQUE ID
	-- Returns an id no other element on the page is using.
	---------------------------------------------------------- */

	var idCounter = 0;

	function uniqueId( prefix ) {

		var id;

		do {

			idCounter++;
			id = prefix + idCounter;

		} while ( document.getElementById( id ) );

		return id;

	}

	/*
	REPAIR CONTENT TOGGLE
	-- Breakdance wraps the checkbox in a label with no text, so it has no name.
	-- The visible label after the switch describes the checked state, which is
	-- exactly what an on/off switch is named by: "Yearly, switch, on".
	-- A toggle with no usable label text is left alone rather than guessed at.
	---------------------------------------------------------- */

	function repairContentToggle( switcher ) {

		var checkbox = switcher.querySelector( '.js-content-toggle-checkbox' );

		if ( ! checkbox || 'checkbox' !== checkbox.type || hasAccessibleName( checkbox ) ) {

			return;

		}

		var onLabel = Array.prototype.find.call( switcher.querySelectorAll( '.js-content-toggle-label' ), function ( label ) {

			return checkbox.compareDocumentPosition( label ) & Node.DOCUMENT_POSITION_FOLLOWING;

		} );

		if ( ! onLabel || '' === onLabel.textContent.trim() ) {

			return;

		}

		if ( ! onLabel.id ) {

			onLabel.id = uniqueId( 'oa-content-toggle-label-' );

		}

		checkbox.setAttribute( 'aria-labelledby', onLabel.id );

		if ( ! checkbox.hasAttribute( 'role' ) ) {

			checkbox.setAttribute( 'role', 'switch' );

		}

	}

	/*
	REPAIRS
	-- One entry per repair: the container it works on and the function that
	-- fixes one container. Add narrowly targeted repairs here.
	---------------------------------------------------------- */

	var repairs = [
		{ selector: '.bde-content-toggle__switcher', repair: repairContentToggle }
	];

	/*
	SCAN
	-- Repairs every matching container in or around a node: the node itself or
	-- the container it was added into, plus any containers inside it.
	---------------------------------------------------------- */

	function scan( root ) {

		repairs.forEach( function ( item ) {

			var owner = root.closest ? root.closest( item.selector ) : null;

			if ( owner ) {

				item.repair( owner );

			}

			root.querySelectorAll( item.selector ).forEach( item.repair );

		} );

	}

	/*
	WATCH
	-- Popups, AJAX and other late content are repaired as they arrive. Only
	-- the added subtrees are scanned, never the whole document again.
	---------------------------------------------------------- */

	function watch() {

		if ( ! window.MutationObserver ) {

			return;

		}

		new MutationObserver( function ( mutations ) {

			mutations.forEach( function ( mutation ) {

				mutation.addedNodes.forEach( function ( node ) {

					if ( Node.ELEMENT_NODE === node.nodeType ) {

						scan( node );

					}

				} );

			} );

		} ).observe( document.documentElement, { childList: true, subtree: true } );

	}

	function init() {

		scan( document );
		watch();

	}

	if ( 'loading' === document.readyState ) {

		document.addEventListener( 'DOMContentLoaded', init );

	} else {

		init();

	}

}() );
