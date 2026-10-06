/*
LAZY VIDEO
-- Activates HTML5 videos parked by the server (src and <source src> held
-- in data-oa-src, preload="none", autoplay held in data-oa-autoplay) once
-- they are actually in the viewport, not merely near it, so no video
-- downloads speculatively. Only then is the source restored, preload set
-- and autoplay resumed
-- Nothing starts until the page has finished loading and the browser is
-- idle, so a video in view on arrival never competes with the stylesheets,
-- fonts and hero image the first render needs. The poster shows until then
-- With Save-Data or a 2G connection nothing plays by itself: posters show,
-- autoplay videos gain controls, and a video loads when it is played
-- Playing a parked video, through its controls or a click, activates it
-- straight away. Videos added to the page later, such as Breakdance popups
-- and loop pages, are picked up once each
-- Posters parked in data-oa-poster by Lazy Loading come back as their
-- video nears the viewport, at the size it is shown when WordPress sizes
-- are listed in data-oa-poster-srcset
---------------------------------------------------------- */

( function () {

	'use strict';

	var selector = 'video[data-oa-lazy-video]';
	var connection = navigator.connection || {};
	var constrained = !! connection.saveData || /(^|-)2g$/.test( connection.effectiveType || '' );
	var supportsObserver = 'function' === typeof window.IntersectionObserver;
	var started = false;
	var observer = null;
	var posterObserver = null;

	/*
	PICK POSTER
	-- The smallest listed size at least as wide as the video is shown, or
	-- the largest when none is; the parked poster without a list
	---------------------------------------------------------- */

	function pickPoster( video, width ) {

		var fallback = video.getAttribute( 'data-oa-poster' );
		var srcset = video.getAttribute( 'data-oa-poster-srcset' );

		if ( ! srcset || ! width ) {

			return fallback;

		}

		var needed = width * ( window.devicePixelRatio || 1 );
		var best = null;
		var largest = null;

		srcset.split( ',' ).forEach( function ( candidate ) {

			var parts = candidate.trim().split( /\s+/ );
			var size = parseInt( parts[ 1 ], 10 );

			if ( ! parts[ 0 ] || ! size ) {

				return;

			}

			if ( ! largest || size > largest.size ) {

				largest = { url: parts[ 0 ], size: size };

			}

			if ( size >= needed && ( ! best || size < best.size ) ) {

				best = { url: parts[ 0 ], size: size };

			}

		} );

		return ( best || largest || { url: fallback } ).url;

	}

	/*
	SHOW POSTER
	-- Restores a parked poster once
	---------------------------------------------------------- */

	function showPoster( video, width ) {

		var poster = video.getAttribute( 'data-oa-poster' );

		if ( poster ) {

			video.poster = pickPoster( video, width ) || poster;
			video.removeAttribute( 'data-oa-poster' );

		}

	}

	/*
	RESTORE SOURCES
	-- Moves the parked src back onto the video and its <source>s
	---------------------------------------------------------- */

	function restoreSources( video ) {

		var src = video.getAttribute( 'data-oa-src' );

		if ( src ) {

			video.setAttribute( 'src', src );
			video.removeAttribute( 'data-oa-src' );

		}

		Array.prototype.forEach.call( video.querySelectorAll( 'source[data-oa-src]' ), function ( source ) {

			source.setAttribute( 'src', source.getAttribute( 'data-oa-src' ) );
			source.removeAttribute( 'data-oa-src' );

		} );

	}

	/*
	ACTIVATE
	-- Restores the sources, the intended preload and, unless the connection
	-- is constrained, autoplay, then loads the video once. $play starts it
	-- for a viewer who asked to play it
	---------------------------------------------------------- */

	function activate( video, play ) {

		if ( video.getAttribute( 'data-oa-video-active' ) ) {

			return;

		}

		var autoplay = video.hasAttribute( 'data-oa-autoplay' ) && ! constrained;

		video.setAttribute( 'data-oa-video-active', '1' );

		if ( observer ) {

			observer.unobserve( video );

		}

		showPoster( video );
		restoreSources( video );
		video.preload = autoplay || play ? 'auto' : ( video.getAttribute( 'data-oa-preload' ) || 'metadata' );

		if ( autoplay ) {

			video.autoplay = true;

		}

		video.load();

		if ( autoplay || play ) {

			var playing = video.play();

			// A browser autoplay policy can refuse playback; the poster stays.
			if ( playing && 'function' === typeof playing.catch ) {

				playing.catch( function () {} );

			}

		}

	}

	/*
	ON DEMAND
	-- A viewer playing or clicking a parked video activates it at once,
	-- from the moment the script runs. Media events do not bubble, so the
	-- document listens in the capture phase
	---------------------------------------------------------- */

	function onDemand( event ) {

		var video = event.target;

		if ( video && video.matches && video.matches( selector ) ) {

			activate( video, true );

		}

	}

	/*
	WATCH
	-- Each video once: it waits to be in view, or on a constrained
	-- connection for the viewer to play it
	---------------------------------------------------------- */

	function watch( video ) {

		if ( video.getAttribute( 'data-oa-video-watched' ) ) {

			return;

		}

		video.setAttribute( 'data-oa-video-watched', '1' );

		if ( constrained ) {

			if ( video.hasAttribute( 'data-oa-autoplay' ) ) {

				video.controls = true;

			}

			return;

		}

		if ( ! observer ) {

			activate( video, false );

			return;

		}

		observer.observe( video );

	}

	/*
	WATCH POSTERS
	-- Runs as soon as the script does, with a wide margin so a poster is in
	-- place before its video scrolls into view. Without IntersectionObserver
	-- every poster is restored at once
	---------------------------------------------------------- */

	function watchPosters( root ) {

		var videos = Array.prototype.slice.call( root.querySelectorAll( 'video[data-oa-poster]:not([data-oa-poster-watched])' ) );

		if ( ! supportsObserver ) {

			videos.forEach( function ( video ) {

				showPoster( video, 0 );

			} );

			return;

		}

		if ( ! posterObserver ) {

			posterObserver = new window.IntersectionObserver( function ( entries ) {

				entries.forEach( function ( entry ) {

					if ( entry.isIntersecting ) {

						posterObserver.unobserve( entry.target );
						showPoster( entry.target, entry.boundingClientRect ? entry.boundingClientRect.width : 0 );

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
	SCAN
	-- Every parked video and poster inside $root not yet watched
	---------------------------------------------------------- */

	function scan( root ) {

		watchPosters( root );

		if ( started ) {

			Array.prototype.forEach.call( root.querySelectorAll( selector ), watch );

		}

	}

	/*
	OBSERVE INSERTIONS
	-- New markup is scanned once per added element tree
	---------------------------------------------------------- */

	function observeInsertions() {

		if ( 'function' !== typeof window.MutationObserver || ! document.body ) {

			return;

		}

		new window.MutationObserver( function ( records ) {

			records.forEach( function ( record ) {

				Array.prototype.forEach.call( record.addedNodes, function ( node ) {

					if ( 1 !== node.nodeType ) {

						return;

					}

					if ( node.matches && node.matches( selector ) ) {

						watchPosters( node.parentNode || node );
						watch( node );

						return;

					}

					if ( node.querySelector && node.querySelector( 'video' ) ) {

						scan( node );

					}

				} );

			} );

		} ).observe( document.body, { childList: true, subtree: true } );

	}

	/*
	INIT
	-- Only a video genuinely inside the viewport is activated: no margin,
	-- and at least a sliver of it must be visible
	---------------------------------------------------------- */

	function init() {

		started = true;

		if ( supportsObserver ) {

			observer = new window.IntersectionObserver( function ( entries ) {

				entries.forEach( function ( entry ) {

					if ( entry.isIntersecting && entry.intersectionRatio > 0 ) {

						activate( entry.target, false );

					}

				} );

			}, { rootMargin: '0px', threshold: 0.01 } );

		}

		scan( document );
		observeInsertions();

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

	document.addEventListener( 'play', onDemand, true );
	document.addEventListener( 'click', onDemand, true );

	watchPosters( document );

	if ( 'loading' === document.readyState ) {

		document.addEventListener( 'DOMContentLoaded', function () {

			watchPosters( document );

		} );

	}

	if ( 'complete' === document.readyState ) {

		start();

	} else {

		window.addEventListener( 'load', start );

	}

} )();
