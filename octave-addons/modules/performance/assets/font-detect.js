/*
FONT DETECT
-- Reports the font files this page needed before its largest element was
-- shown, in the order they were requested, so the server can preload them
-- on later views of the same kind of page. Browsers only fetch a font once
-- text needs it, so these are the fonts the first view actually used
-- Without Largest Contentful Paint support, fonts fetched before the load
-- event are reported instead
---------------------------------------------------------- */

( function () {

	'use strict';

	var config = window.oaFontDetect || {};
	var lcp = 0;

	if ( ! config.ajaxUrl || ! navigator.sendBeacon || ! window.performance || ! performance.getEntriesByType ) {

		return;

	}

	try {

		new PerformanceObserver( function ( list ) {

			var entries = list.getEntries();

			if ( entries.length ) {

				lcp = entries[ entries.length - 1 ].startTime;

			}

		} ).observe( { type: 'largest-contentful-paint', buffered: true } );

	} catch ( error ) {

		lcp = 0;

	}

	window.addEventListener( 'load', function () {

		var urls = performance.getEntriesByType( 'resource' ).filter( function ( entry ) {

			// Only the site's own files can be preloaded; the server checks this again.
			return 0 === entry.name.indexOf( window.location.origin + '/' ) && /\.woff2?(\?|$)/i.test( entry.name ) && ( ! lcp || entry.startTime <= lcp );

		} ).sort( function ( a, b ) {

			return a.startTime - b.startTime;

		} ).map( function ( entry ) {

			return entry.name;

		} ).slice( 0, parseInt( config.max, 10 ) || 3 );

		var body = new FormData();

		body.append( 'action', config.action );
		body.append( 'family', config.family || 'other' );

		urls.forEach( function ( url ) {

			body.append( 'urls[]', url );

		} );

		navigator.sendBeacon( config.ajaxUrl, body );

	} );

} )();
