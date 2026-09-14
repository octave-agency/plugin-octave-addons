/*
NOTIFICATIONS BAR
-- Opens the bar on load, closes a banner, remembers the choice in a cookie for
-- the configured number of days, and takes the bar away once every banner has
-- been dismissed.
-- Height is the single source of truth. The bar's own height pushes the page
-- below it, and the same measurement is published as --oa-nb-offset so a fixed
-- header, which no amount of document flow can move, travels with it.
-- Which of the two the opening animation moves is left to the stylesheet; all
-- this file does is add the class that opens the bar and keep the measurement
-- honest while it does.
-- The height is read from an inner track rather than the bar itself, so the
-- measurement never depends on the height being animated.
-- Banners already carrying a cookie are removed before paint by the inline
-- script the module prints beside the markup, not here.
---------------------------------------------------------- */

(function () {

	'use strict';

	var bar = document.getElementById( 'oaNotificationsBar' );

	if ( ! bar ) {

		return;

	}

	var root     = document.documentElement;
	var track    = bar.querySelector( '.oa-nb__track' ) || bar;
	var days     = parseInt( bar.getAttribute( 'data-cookie-days' ), 10 );
	var animated = bar.classList.contains( 'oa-nb--animated' );
	var fades    = 'fade' === bar.getAttribute( 'data-animation' );
	var pushes   = ! bar.classList.contains( 'oa-nb--bottom' );
	var sticky   = bar.classList.contains( 'oa-nb--sticky' );

	var duration = animated ? motionDuration() : 0;
	var height   = 0;

	// A fade keeps its height throughout, so the space it needs is already
	// its own from the first frame and only the banners have to arrive.
	var isOpen   = ! animated || fades;
	var settled  = ! animated;
	var live     = true;
	var observer = null;
	var timer    = null;

	/*
	MOTION DURATION
	-- The animation length lives in the stylesheet so the custom CSS box can
	-- retune it, which means reading it back rather than repeating it here.
	---------------------------------------------------------- */

	function motionDuration() {

		var value = window.getComputedStyle( root ).getPropertyValue( '--oa-nb-duration' ).trim();
		var count = parseFloat( value ) || 0;

		return -1 === value.indexOf( 'ms' ) ? count * 1000 : count;

	}

	/*
	PUBLISH
	-- Writes the measured height to the root element, where both the bar's own
	-- height rule and the fixed header offset read it from.
	---------------------------------------------------------- */

	function publish() {

		if ( ! live ) {

			return;

		}

		height = track.offsetHeight;

		root.style.setProperty( '--oa-nb-height', height + 'px' );

		sync();

	}

	/*
	SYNC
	-- Hands the fixed header the space the bar is currently taking. A bar that
	-- scrolls away gives that space back as it goes, so the header rises with
	-- it instead of leaving a gap once the bar has gone.
	---------------------------------------------------------- */

	function sync() {

		if ( ! live ) {

			return;

		}

		var offset = 0;

		if ( pushes && isOpen ) {

			offset = sticky ? height : Math.max( 0, height - ( window.pageYOffset || 0 ) );

		}

		root.style.setProperty( '--oa-nb-offset', offset + 'px' );

	}

	/*
	EASE
	-- Opens the window in which the header is allowed to transition. Outside
	-- it the header moves instantly, which is what scroll tracking needs.
	---------------------------------------------------------- */

	function ease() {

		if ( ! animated ) {

			return;

		}

		root.classList.add( 'oa-nb-animating' );

		window.clearTimeout( timer );

		timer = window.setTimeout( function () {

			root.classList.remove( 'oa-nb-animating' );

		}, duration + 60 );

	}

	/*
	REVEAL
	-- Two frames, so the collapsed height is painted before the transition to
	-- the full height begins.
	---------------------------------------------------------- */

	function reveal() {

		window.requestAnimationFrame( function () {

			window.requestAnimationFrame( function () {

				isOpen = true;

				ease();
				bar.classList.add( 'is-open' );
				sync();

				window.setTimeout( function () {

					settled = true;

				}, duration + 60 );

			} );

		} );

	}

	function remember( id ) {

		var cookie = 'oa_nb_' + id + '=1; path=/; SameSite=Lax';

		// Zero days is a session cookie, so the banner returns on the next visit.
		if ( days > 0 ) {

			cookie += '; max-age=' + ( days * 86400 );

		}

		if ( 'https:' === window.location.protocol ) {

			cookie += '; Secure';

		}

		document.cookie = cookie;

	}

	function dismiss( banner ) {

		remember( banner.getAttribute( 'data-oa-nb-id' ) );
		banner.classList.add( 'is-closing' );

		window.setTimeout( function () {

			banner.remove();
			ease();

			if ( ! bar.querySelector( '[data-oa-nb-id]' ) ) {

				close();

			}

		}, 200 );

	}

	/*
	CLOSE
	-- The last banner has gone, so the bar collapses back out of the page and
	-- is only removed once the space it held has been handed back.
	---------------------------------------------------------- */

	function close() {

		isOpen = false;

		bar.classList.remove( 'is-open' );
		sync();

		window.setTimeout( function () {

			live = false;

			if ( observer ) {

				observer.disconnect();

			}

			bar.remove();
			root.style.removeProperty( '--oa-nb-height' );
			root.style.removeProperty( '--oa-nb-offset' );

		}, duration + 60 );

	}

	/*
	START
	---------------------------------------------------------- */

	bar.addEventListener( 'click', function ( event ) {

		var button = event.target.closest( '.oa-nb__close' );

		if ( ! button ) {

			return;

		}

		dismiss( button.closest( '[data-oa-nb-id]' ) );

	} );

	if ( window.ResizeObserver ) {

		observer = new window.ResizeObserver( publish );

		observer.observe( track );

	} else {

		window.addEventListener( 'resize', publish );

	}

	if ( pushes && ! sticky ) {

		window.addEventListener( 'scroll', function () {

			if ( settled ) {

				root.classList.remove( 'oa-nb-animating' );

			}

			sync();

		}, { passive: true } );

	}

	publish();

	if ( animated ) {

		reveal();

	}

})();
