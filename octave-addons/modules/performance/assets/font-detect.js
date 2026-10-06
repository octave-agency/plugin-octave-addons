/*
FONT DETECT
-- Reports the font files this page downloaded before it finished loading, in
-- the order they were requested, so the server can preload them on later
-- page views. Browsers only fetch a font once text needs it, so these are
-- the fonts the first render actually used
---------------------------------------------------------- */

( function () {

	'use strict';

	var config = window.oaFontDetect || {};

	if ( ! config.ajaxUrl || ! navigator.sendBeacon || ! window.performance || ! performance.getEntriesByType ) {

		return;

	}

	window.addEventListener( 'load', function () {

		var urls = performance.getEntriesByType( 'resource' ).filter( function ( entry ) {

			// Only the site's own files can be preloaded; the server checks this again.
			return 0 === entry.name.indexOf( window.location.origin + '/' ) && /\.woff2?(\?|$)/i.test( entry.name );

		} ).sort( function ( a, b ) {

			return a.startTime - b.startTime;

		} ).map( function ( entry ) {

			return entry.name;

		} ).slice( 0, parseInt( config.max, 10 ) || 3 );

		var body = new FormData();

		body.append( 'action', config.action );

		urls.forEach( function ( url ) {

			body.append( 'urls[]', url );

		} );

		navigator.sendBeacon( config.ajaxUrl, body );

	} );

} )();
