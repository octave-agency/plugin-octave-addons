/*
LINK PRELOAD
-- Prefetches same-origin pages on hover intent (about 100ms) or touch, once
-- per URL and a few at a time. Skips visitors with Save-Data or a very slow
-- connection, and any link that could change state when requested
---------------------------------------------------------- */

( function () {

	'use strict';

	var config = window.oaPreload || {};
	var connection = navigator.connection || {};

	if ( connection.saveData || /(^|-)2g$/.test( connection.effectiveType || '' ) ) {

		return;

	}

	var probe = document.createElement( 'link' );

	if ( ! probe.relList || ! probe.relList.supports || ! probe.relList.supports( 'prefetch' ) ) {

		return;

	}

	var delay = parseInt( config.delay, 10 ) || 100;
	var exclude = ( config.exclude || [] ).map( function ( pattern ) {

		return String( pattern ).toLowerCase();

	} );
	var fileTypes = /\.(?:jpe?g|png|gif|webp|avif|svg|ico|pdf|docx?|xlsx?|pptx?|csv|txt|zip|rar|7z|gz|tar|dmg|exe|mp3|mp4|mov|webm|wav|ogg|xml|json)$/i;
	var seen = {};
	var queue = [];
	var hoverTimer = null;
	var lastRequest = 0;
	var gap = 300;
	var limit = 30;
	var count = 0;

	/*
	TARGET URL
	-- The URL a link would open, or null when it must not be prefetched
	---------------------------------------------------------- */

	function targetUrl( link ) {

		if ( ! link || ! link.href || link.hasAttribute( 'download' ) || link.hasAttribute( 'data-oa-no-preload' ) ) {

			return null;

		}

		var url;

		try {

			url = new URL( link.href, window.location.href );

		} catch ( error ) {

			return null;

		}

		if ( ( 'http:' !== url.protocol && 'https:' !== url.protocol ) || url.origin !== window.location.origin ) {

			return null;

		}

		// Same page with only a different fragment.
		if ( url.pathname === window.location.pathname && url.search === window.location.search ) {

			return null;

		}

		if ( fileTypes.test( url.pathname ) ) {

			return null;

		}

		var href = url.href.toLowerCase();

		var excluded = exclude.some( function ( pattern ) {

			return pattern && -1 !== href.indexOf( pattern );

		} );

		if ( excluded ) {

			return null;

		}

		// Pathname and search are kept exactly, so trailing slashes are respected.
		return url.origin + url.pathname + url.search;

	}

	/*
	PREFETCH
	-- Throttled: requests are spaced out and capped per page view
	---------------------------------------------------------- */

	function prefetch( url ) {

		if ( seen[ url ] || count >= limit ) {

			return;

		}

		var wait = lastRequest + gap - Date.now();

		if ( wait > 0 ) {

			if ( -1 === queue.indexOf( url ) ) {

				queue.push( url );

			}

			window.setTimeout( flushQueue, wait );

			return;

		}

		seen[ url ] = true;
		count++;
		lastRequest = Date.now();

		var link = document.createElement( 'link' );

		link.rel = 'prefetch';
		link.href = url;
		link.as = 'document';
		document.head.appendChild( link );

	}

	function flushQueue() {

		var next = queue.shift();

		if ( next ) {

			prefetch( next );

		}

	}

	function linkFrom( event ) {

		return event.target && event.target.closest ? event.target.closest( 'a[href]' ) : null;

	}

	document.addEventListener( 'mouseover', function ( event ) {

		var link = linkFrom( event );
		var url = targetUrl( link );

		if ( ! url || seen[ url ] ) {

			return;

		}

		window.clearTimeout( hoverTimer );

		hoverTimer = window.setTimeout( function () {

			prefetch( url );

		}, delay );

		link.addEventListener( 'mouseout', function () {

			window.clearTimeout( hoverTimer );

		}, { once: true } );

	}, { passive: true } );

	document.addEventListener( 'touchstart', function ( event ) {

		var url = targetUrl( linkFrom( event ) );

		if ( url ) {

			prefetch( url );

		}

	}, { passive: true } );

} )();
