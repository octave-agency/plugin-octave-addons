/*
LAZY VIDEO
-- Loads HTML5 videos rendered with preload="none" as they approach the
-- viewport, and only then restores autoplay, so offscreen videos neither
-- download nor play early. Loads everything at once without IntersectionObserver
-- Nothing starts until the page has finished loading, so a large video in
-- view on arrival never competes with the stylesheets, fonts and hero image
-- the first render needs. The poster shows until then
---------------------------------------------------------- */

( function () {

	'use strict';

	var selector = 'video[data-oa-lazy-video]';

	/*
	ACTIVATE
	-- Restores the intended preload and autoplay, then loads the video once
	---------------------------------------------------------- */

	function activate( video ) {

		if ( video.dataset.oaVideoActive ) {

			return;

		}

		var autoplay = video.hasAttribute( 'data-oa-autoplay' );

		video.dataset.oaVideoActive = '1';
		video.preload = autoplay ? 'auto' : ( video.dataset.oaPreload || 'metadata' );

		if ( autoplay ) {

			video.autoplay = true;

		}

		video.load();

		if ( autoplay ) {

			var playing = video.play();

			// A browser autoplay policy can refuse playback; the poster stays.
			if ( playing && 'function' === typeof playing.catch ) {

				playing.catch( function () {} );

			}

		}

	}

	/*
	INIT
	-- Observes every deferred video with a small margin to hide loading pauses
	---------------------------------------------------------- */

	function init() {

		var videos = Array.prototype.slice.call( document.querySelectorAll( selector ) );

		if ( ! videos.length ) {

			return;

		}

		if ( 'function' !== typeof window.IntersectionObserver ) {

			videos.forEach( activate );

			return;

		}

		var observer = new IntersectionObserver( function ( entries ) {

			entries.forEach( function ( entry ) {

				if ( ! entry.isIntersecting ) {

					return;

				}

				observer.unobserve( entry.target );
				activate( entry.target );

			} );

		}, { rootMargin: '200px 0px' } );

		videos.forEach( function ( video ) {

			observer.observe( video );

		} );

	}

	/*
	START
	-- After the load event, then when the browser is next idle (at most a
	-- second later), so the page's own resources always finish first
	---------------------------------------------------------- */

	function start() {

		if ( 'function' === typeof window.requestIdleCallback ) {

			window.requestIdleCallback( init, { timeout: 1000 } );

			return;

		}

		window.setTimeout( init, 1 );

	}

	if ( 'complete' === document.readyState ) {

		start();

	} else {

		window.addEventListener( 'load', start );

	}

} )();
