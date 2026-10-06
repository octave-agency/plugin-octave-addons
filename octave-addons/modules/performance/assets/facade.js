/*
VIDEO FACADE
-- Replaces a YouTube or Vimeo facade with the real player when its button is
-- pressed. The player is copied from the facade's <template>, so its title,
-- captions and player parameters are exactly what the embed shipped with
---------------------------------------------------------- */

( function () {

	'use strict';

	/*
	ACTIVATE
	-- Adds autoplay so the press that revealed the player also starts it,
	-- then moves focus to the player for keyboard and screen reader users
	---------------------------------------------------------- */

	function activate( facade ) {

		var template = facade.querySelector( 'template' );

		if ( ! template || ! template.content ) {

			return;

		}

		var iframe = template.content.querySelector( 'iframe' );

		if ( ! iframe ) {

			return;

		}

		iframe = iframe.cloneNode( true );

		try {

			var url = new URL( iframe.getAttribute( 'src' ), window.location.href );

			url.searchParams.set( 'autoplay', '1' );
			iframe.setAttribute( 'src', url.toString() );

		} catch ( error ) {

			// An unparsable src is used as it is; the player still loads.

		}

		var allow = iframe.getAttribute( 'allow' ) || '';

		if ( -1 === allow.indexOf( 'autoplay' ) ) {

			iframe.setAttribute( 'allow', ( allow ? allow + '; ' : '' ) + 'autoplay' );

		}

		facade.classList.add( 'is-playing' );
		facade.replaceChildren( iframe );
		iframe.focus();

	}

	document.addEventListener( 'click', function ( event ) {

		var button = event.target.closest ? event.target.closest( '.oa-video-facade-button' ) : null;

		if ( ! button ) {

			return;

		}

		var facade = button.closest( '[data-oa-facade]' );

		if ( facade ) {

			activate( facade );

		}

	} );

} )();
