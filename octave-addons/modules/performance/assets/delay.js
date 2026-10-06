/*
DELAYED SCRIPT LOADER
-- Runs the scripts the server marked with data-oa-delay, in their original
-- document order, once the visitor first interacts (or after the optional
-- timeout). Scripts tied to a page element, such as maps and CAPTCHA, can be
-- released earlier when that element nears the viewport or gains focus;
-- everything before them in the page runs first, so order is never broken
-- Each script is recreated with its original attributes, so async, defer,
-- module, nomodule, integrity and crossorigin behave as originally written,
-- and load and error events fire on the new element as normal
---------------------------------------------------------- */

( function () {

	'use strict';

	var scripts = Array.prototype.slice.call( document.querySelectorAll( 'script[data-oa-delay]' ) );

	if ( ! scripts.length ) {

		return;

	}

	var loader = document.getElementById( 'oa-delay-loader' );
	var timeout = loader ? parseInt( loader.getAttribute( 'data-timeout' ), 10 ) || 0 : 0;
	var interactionEvents = [ 'pointerdown', 'mousemove', 'touchstart', 'keydown', 'wheel', 'scroll' ];
	var supportsModules = 'noModule' in document.createElement( 'script' );
	var loadedSources = {};
	var target = -1;
	var position = 0;
	var running = false;

	/*
	COPY ATTRIBUTES
	-- Everything except the placeholder type, the parked src and Octave's own
	-- data attributes
	---------------------------------------------------------- */

	function copyAttributes( from, to ) {

		Array.prototype.forEach.call( from.attributes, function ( attribute ) {

			var name = attribute.name;

			if ( 'type' === name || 'src' === name || 0 === name.indexOf( 'data-oa-' ) ) {

				return;

			}

			to.setAttribute( name, attribute.value );

		} );

	}

	/*
	ALREADY LOADED
	-- Prevents a script running twice when the page also loads it normally
	---------------------------------------------------------- */

	function alreadyLoaded( src ) {

		if ( loadedSources[ src ] ) {

			return true;

		}

		return Array.prototype.some.call( document.scripts, function ( script ) {

			return script.getAttribute( 'src' ) === src;

		} );

	}

	/*
	SWAP
	-- Puts the live script where the placeholder was, or at the end of the
	-- body if another script has removed the placeholder in the meantime
	---------------------------------------------------------- */

	function swap( original, replacement ) {

		if ( original.parentNode ) {

			original.parentNode.replaceChild( replacement, original );

			return;

		}

		document.body.appendChild( replacement );

	}

	/*
	RUN NEXT
	-- Recreates one script. A blocking external script is waited for before
	-- the next runs; async scripts and inline code continue straight away
	---------------------------------------------------------- */

	function runNext() {

		if ( position > target || position >= scripts.length ) {

			running = false;

			if ( position >= scripts.length ) {

				window.dispatchEvent( new Event( 'oa:delayed-scripts-loaded' ) );

			}

			return;

		}

		running = true;

		var original = scripts[ position ];
		var replacement = document.createElement( 'script' );
		var src = original.getAttribute( 'data-oa-src' );
		var type = original.getAttribute( 'data-oa-type' );

		position++;

		copyAttributes( original, replacement );

		if ( type ) {

			replacement.type = type;

		}

		if ( ! src ) {

			replacement.text = original.text;
			swap( original, replacement );
			runNext();

			return;

		}

		if ( alreadyLoaded( src ) || ( supportsModules && original.hasAttribute( 'nomodule' ) ) ) {

			runNext();

			return;

		}

		loadedSources[ src ] = true;

		var waits = ! original.hasAttribute( 'async' );

		if ( waits ) {

			replacement.async = false;
			replacement.addEventListener( 'load', runNext );
			replacement.addEventListener( 'error', runNext );

		}

		replacement.src = src;
		swap( original, replacement );

		if ( ! waits ) {

			runNext();

		}

	}

	/*
	RELEASE
	-- Allows every script up to and including the given index to run
	---------------------------------------------------------- */

	function release( index ) {

		if ( index <= target ) {

			return;

		}

		target = index;

		if ( ! running ) {

			runNext();

		}

	}

	function releaseAll() {

		interactionEvents.forEach( function ( name ) {

			window.removeEventListener( name, releaseAll, { passive: true } );

		} );

		release( scripts.length - 1 );

	}

	interactionEvents.forEach( function ( name ) {

		window.addEventListener( name, releaseAll, { passive: true } );

	} );

	/*
	CONTEXTUAL TRIGGERS
	-- Each context selector releases up to the last script that needs it
	---------------------------------------------------------- */

	var contexts = {};

	scripts.forEach( function ( script, index ) {

		var selector = script.getAttribute( 'data-oa-context' );

		if ( selector ) {

			contexts[ selector ] = index;

		}

	} );

	Object.keys( contexts ).forEach( function ( selector ) {

		var elements;

		try {

			elements = document.querySelectorAll( selector );

		} catch ( error ) {

			return;

		}

		if ( ! elements.length ) {

			return;

		}

		var index = contexts[ selector ];

		document.addEventListener( 'focusin', function ( event ) {

			if ( event.target.closest && event.target.closest( selector ) ) {

				release( index );

			}

		} );

		if ( 'function' !== typeof window.IntersectionObserver ) {

			return;

		}

		var observer = new IntersectionObserver( function ( entries ) {

			var visible = entries.some( function ( entry ) {

				return entry.isIntersecting;

			} );

			if ( visible ) {

				observer.disconnect();
				release( index );

			}

		}, { rootMargin: '300px 0px' } );

		Array.prototype.forEach.call( elements, function ( element ) {

			observer.observe( element );

		} );

	} );

	/*
	TIMEOUT
	-- Counts from the window load event, so a slow page is not cut short
	---------------------------------------------------------- */

	if ( timeout > 0 ) {

		var startTimer = function () {

			window.setTimeout( releaseAll, timeout * 1000 );

		};

		if ( 'complete' === document.readyState ) {

			startTimer();

		} else {

			window.addEventListener( 'load', startTimer );

		}

	}

} )();
