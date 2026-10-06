/*
LAZY VIDEO
-- Loads HTML5 videos rendered with preload="none" as they approach the
-- viewport, and only then restores autoplay, so offscreen videos neither
-- download nor play early. Loads everything at once without IntersectionObserver
-- Nothing starts until the page has finished loading, so a large video in
-- view on arrival never competes with the stylesheets, fonts and hero image
-- the first render needs. The poster shows until then
-- Posters parked in data-oa-poster by Media Lazy Loading come back as their
-- video nears the viewport, from the start rather than after the load event
---------------------------------------------------------- */

( function () {

	'use strict';

	var selector = 'video[data-oa-lazy-video]';

	/*
	SHOW POSTER
	-- Restores a parked poster once
	---------------------------------------------------------- */

	function showPoster( video ) {

		var poster = video.getAttribute( 'data-oa-poster' );

		if ( poster ) {

			video.removeAttribute( 'data-oa-poster' );
			video.poster = poster;

		}

	}

	/*
	WATCH POSTERS
	-- Runs as soon as the script does, with a wide margin so a poster is in
	-- place before its video scrolls into view. Without IntersectionObserver
	-- every poster is restored at once
	---------------------------------------------------------- */

	var posterObserver = null;

	function watchPosters() {

		var videos = Array.prototype.slice.call( document.querySelectorAll( 'video[data-oa-poster]:not([data-oa-poster-watched])' ) );

		if ( 'function' !== typeof window.IntersectionObserver ) {

			videos.forEach( showPoster );

			return;

		}

		if ( ! posterObserver ) {

			posterObserver = new IntersectionObserver( function ( entries ) {

				entries.forEach( function ( entry ) {

					if ( entry.isIntersecting ) {

						posterObserver.unobserve( entry.target );
						showPoster( entry.target );

					}

				} );

			}, { rootMargin: '600px 0px' } );

		}

		videos.forEach( function ( video ) {

			video.setAttribute( 'data-oa-poster-watched', '' );
			posterObserver.observe( video );

		} );

	}

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
		showPoster( video );
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

	watchPosters();

	if ( 'loading' === document.readyState ) {

		document.addEventListener( 'DOMContentLoaded', watchPosters );

	}

	if ( 'complete' === document.readyState ) {

		start();

	} else {

		window.addEventListener( 'load', start );

	}

} )();
