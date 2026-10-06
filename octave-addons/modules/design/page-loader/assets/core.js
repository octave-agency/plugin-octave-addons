/*
PAGE TRANSITIONS CORE
-- Runs in the head and owns the whole lifecycle, so custom code can listen
-- but never leave the page covered or locked:
-- Initial loader: shown on a full page load (per the display frequency),
-- progress from real readiness signals, removed on window load, with an
-- 8 second hard limit. Every timing derives from cfg.duration (the Loader
-- duration setting), including a minimum display time that paces progress
-- so a fast page still shows the whole animation
-- Transitions: eligible same-origin clicks cover the page and navigate as
-- soon as it is covered; the next page reveals itself on DOMContentLoaded.
-- cfg.cover and cfg.reveal arrive already scaled by the Transition duration
-- Events on document: oa-loader:start|progress|exit|done,
-- oa-transition:out|in|done
---------------------------------------------------------- */

( function () {

	'use strict';

	var cfg = window.oaPageMotion || {};
	var root = document.documentElement;
	var reduced = window.matchMedia && window.matchMedia( '( prefers-reduced-motion: reduce )' ).matches;

	var flagKey = 'oaPageTransition';
	var seenKey = 'oaLoaderSeen';
	var hardLimit = 8000;
	var states = [ 'oa-loader-active', 'oa-loader-exit', 'oa-loader-cover', 'oa-transition-out', 'oa-transition-in', 'oa-transition-reveal' ];

	var api = window.OctavePageMotion = { state: 'idle', cleanup: cleanup };

	/*
	HELPERS
	-- Storage can throw in private modes, so every access is guarded
	---------------------------------------------------------- */

	function store( action, key, value ) {

		try {

			if ( 'get' === action ) {

				return window.sessionStorage.getItem( key );

			}

			if ( 'set' === action ) {

				window.sessionStorage.setItem( key, value );

			} else {

				window.sessionStorage.removeItem( key );

			}

			return true;

		} catch ( error ) {

			return null;

		}

	}

	function emit( name, detail ) {

		try {

			document.dispatchEvent( new CustomEvent( name, { detail: detail || {} } ) );

		} catch ( error ) {}

	}

	function onReady( callback ) {

		if ( 'loading' === document.readyState ) {

			document.addEventListener( 'DOMContentLoaded', callback );

		} else {

			callback();

		}

	}

	function nextFrame( callback ) {

		var ran = false;

		function run() {

			if ( ! ran ) {

				ran = true;
				callback();

			}

		}

		// Background tabs pause animation frames, so a timer backs it up.
		window.requestAnimationFrame( run );
		window.setTimeout( run, 120 );

	}

	/*
	CLICK ORIGIN
	-- Exposes where the navigation started as --oa-pt-x / --oa-pt-y, so
	-- presets such as Circle Reveal can grow from the clicked link and close
	-- back into the same point on the next page
	---------------------------------------------------------- */

	function setOrigin( x, y ) {

		if ( 'number' === typeof x && 'number' === typeof y ) {

			root.style.setProperty( '--oa-pt-x', x.toFixed( 2 ) + '%' );
			root.style.setProperty( '--oa-pt-y', y.toFixed( 2 ) + '%' );

		}

	}

	/*
	CLEANUP
	-- Removes every overlay state, which also lifts the scroll lock
	---------------------------------------------------------- */

	function cleanup() {

		states.forEach( function ( state ) {

			root.classList.remove( state );

		} );

		api.state = 'idle';

	}

	/*
	ELIGIBLE URL
	-- Same-origin frontend pages only. Skips downloads, files, targets,
	-- opted-out links, hash links on this page, WordPress admin/login/REST/
	-- AJAX/preview URLs, builder URLs, WooCommerce cart/checkout/account and
	-- cart actions, and anything carrying a nonce or an action
	---------------------------------------------------------- */

	var filePattern = /\.(pdf|zip|rar|7z|gz|tar|dmg|exe|msi|apk|docx?|xlsx?|pptx?|odt|csv|txt|rtf|xml|json|jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?|mp4|m4v|mov|webm|avi|mp3|wav|ogg|m4a|flac|ics|vcf|epub)$/i;
	var pathPattern = /(^|\/)(wp-admin|wp-login\.php|wp-json|wp-content|wp-includes|xmlrpc\.php|wp-cron\.php|admin-ajax\.php)(\/|$)|\/feed\/?$/i;
	var blockedParams = [ 'rest_route', 'preview', 'preview_id', 'preview_nonce', 'breakdance', 'breakdance_iframe', 'add-to-cart', 'remove_item', 'undo_item', 'removed_item', 'action', 'wc-ajax', 'customize_changeset_uuid', 'download', 'logout', 'loggedout', 'key' ];

	function isBlockedQuery( params ) {

		var blocked = false;

		params.forEach( function ( value, key ) {

			key = key.toLowerCase();

			if ( -1 !== blockedParams.indexOf( key ) || -1 !== key.indexOf( 'nonce' ) ) {

				blocked = true;

			}

		} );

		return blocked;

	}

	function isExcludedPath( path ) {

		return ( cfg.exclude || [] ).some( function ( prefix ) {

			return prefix && prefix.length > 1 && 0 === path.indexOf( prefix );

		} );

	}

	function eligibleUrl( link ) {

		var target = ( link.getAttribute( 'target' ) || '' ).toLowerCase();
		var raw = link.getAttribute( 'href' ) || '';

		if ( 'string' !== typeof link.href || ! raw || '#' === raw.charAt( 0 ) || link.hasAttribute( 'download' ) ) {

			return null;

		}

		if ( ( target && '_self' !== target ) || link.closest( '[data-oa-no-transition]' ) ) {

			return null;

		}

		if ( link.classList.contains( 'add_to_cart_button' ) || link.classList.contains( 'ajax_add_to_cart' ) ) {

			return null;

		}

		var url;

		try {

			url = new URL( link.href, window.location.href );

		} catch ( error ) {

			return null;

		}

		if ( ! /^https?:$/.test( url.protocol ) || url.origin !== window.location.origin ) {

			return null;

		}

		if ( url.hash && url.pathname === window.location.pathname && url.search === window.location.search ) {

			return null;

		}

		if ( pathPattern.test( url.pathname ) || filePattern.test( url.pathname ) || isExcludedPath( url.pathname ) || isBlockedQuery( url.searchParams ) ) {

			return null;

		}

		return url;

	}

	function linkFrom( event ) {

		return event.target && event.target.closest ? event.target.closest( 'a[href]' ) : null;

	}

	/*
	INITIAL LOADER
	-- Progress blends document readiness with how many images have loaded,
	-- eased so the number never jumps, and never reaches 100 until the
	-- window load event (or the hard limit) actually fires
	---------------------------------------------------------- */

	function startLoader() {

		var loader = null;
		var shown = 0;
		var target = 0;
		var finished = false;
		var exited = false;
		var lastPercent = -1;
		var frame = null;
		var closed = false;
		var timers = [];
		var phases = { loading: 0.15, interactive: 0.55, complete: 1 };
		var base = cfg.duration || 900;
		var started = Date.now();
		var minTime = base * 5 / 3;

		root.classList.add( 'oa-loader-active' );
		api.state = 'loading';
		emit( 'oa-loader:start' );

		function measure() {

			var images = document.images;
			var done = 0;

			for ( var i = 0; i < images.length; i++ ) {

				if ( images[ i ].complete ) {

					done++;

				}

			}

			var phase = phases[ document.readyState ] || 0;
			var media = images.length ? done / images.length : phase;

			target = Math.max( target, Math.min( 0.98, phase * 0.5 + media * 0.5 ) );

		}

		function paint( progress ) {

			loader = loader || document.getElementById( 'oa-page-loader' );

			var percent = Math.round( progress * 100 );

			if ( ! loader || percent === lastPercent ) {

				return;

			}

			lastPercent = percent;
			loader.style.setProperty( '--oa-progress', progress.toFixed( 3 ) );

			var count = loader.querySelector( '[data-oa-loader-count]' );

			if ( count ) {

				count.textContent = String( percent );

			}

			emit( 'oa-loader:progress', { progress: progress } );

		}

		function done() {

			if ( closed ) {

				return;

			}

			closed = true;
			timers.forEach( function ( timer ) {

				window.clearTimeout( timer );

			} );
			window.cancelAnimationFrame( frame );
			cleanup();
			emit( 'oa-loader:done' );

		}

		function exit() {

			if ( exited || closed ) {

				return;

			}

			exited = true;
			window.cancelAnimationFrame( frame );
			paint( 1 );
			root.classList.add( 'oa-loader-exit' );
			api.state = 'exiting';
			emit( 'oa-loader:exit' );
			timers.push( window.setTimeout( done, base + 60 ) );

		}

		// Caps displayed progress to an ease-out curve over the minimum time
		// (base × 5/3, 1500ms at the 900ms default), so a page that loads
		// instantly still counts up rather than flashing.
		function paced() {

			var t = Math.min( 1, ( Date.now() - started ) / minTime );

			return t * ( 2 - t );

		}

		function finish() {

			if ( finished || closed ) {

				return;

			}

			finished = true;
			target = 1;

			// Lets the number catch up, without waiting on a paused frame loop.
			timers.push( window.setTimeout( exit, Math.max( 0, minTime - ( Date.now() - started ) ) + base * 0.47 ) );

		}

		function tick() {

			try {

				if ( ! finished ) {

					measure();

				}

				var goal = Math.min( target, paced() );

				shown += ( goal - shown ) * 0.18;

				if ( goal - shown < 0.004 ) {

					shown = goal;

				}

				paint( shown );

				if ( finished && shown >= 1 ) {

					exit();

					return;

				}

				frame = window.requestAnimationFrame( tick );

			} catch ( error ) {

				done();

			}

		}

		frame = window.requestAnimationFrame( tick );

		timers.push( window.setTimeout( finish, hardLimit ) );
		timers.push( window.setTimeout( done, hardLimit + base + 1500 ) );

		if ( 'complete' === document.readyState ) {

			finish();

		} else {

			window.addEventListener( 'load', finish );

		}

		// A theme without wp_body_open never prints the loader markup.
		onReady( function () {

			if ( ! document.getElementById( 'oa-page-loader' ) ) {

				done();

			}

		} );

	}

	/*
	TRANSITION INGRESS
	-- The overlay covers the first paint, then reveals as soon as the DOM is
	-- ready rather than waiting for every image
	---------------------------------------------------------- */

	function startIngress( flag ) {

		var revealed = false;
		var closed = false;
		var safety = null;

		setOrigin( flag.x, flag.y );
		root.classList.add( 'oa-transition-in' );
		api.state = 'arriving';
		emit( 'oa-transition:in' );

		function done() {

			if ( closed ) {

				return;

			}

			closed = true;
			window.clearTimeout( safety );
			cleanup();
			emit( 'oa-transition:done' );

		}

		onReady( function () {

			if ( ! document.getElementById( 'oa-page-transition' ) ) {

				done();

				return;

			}

			nextFrame( function () {

				if ( revealed ) {

					return;

				}

				revealed = true;
				root.classList.add( 'oa-transition-reveal' );
				window.setTimeout( done, ( cfg.reveal || 600 ) + 60 );

			} );

		} );

		safety = window.setTimeout( done, 3000 + ( cfg.reveal || 600 ) );

	}

	/*
	TRANSITION EGRESS
	-- Listens on window so every other click handler, including delegated
	-- lightbox and AJAX handlers, has had the chance to cancel the click
	---------------------------------------------------------- */

	function bindNavigation() {

		var stuck = null;

		window.addEventListener( 'click', function ( event ) {

			if ( event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {

				return;

			}

			var link = linkFrom( event );
			var url = link ? eligibleUrl( link ) : null;

			if ( ! url || url.href === window.location.href ) {

				return;

			}

			var overlay = document.getElementById( cfg.replay ? 'oa-page-loader' : 'oa-page-transition' );
			var flag = { t: Date.now() };

			// A keyboard activation has no pointer position, so it starts centred.
			if ( event.detail && window.innerWidth && window.innerHeight ) {

				flag.x = event.clientX / window.innerWidth * 100;
				flag.y = event.clientY / window.innerHeight * 100;

			}

			event.preventDefault();
			setOrigin( flag.x, flag.y );
			store( 'set', flagKey, JSON.stringify( flag ) );

			if ( ! overlay ) {

				window.location.assign( url.href );

				return;

			}

			root.classList.add( cfg.replay ? 'oa-loader-cover' : 'oa-transition-out' );
			api.state = 'leaving';
			emit( 'oa-transition:out', { url: url.href } );

			window.setTimeout( function () {

				window.location.assign( url.href );

			}, cfg.cover || 400 );

			// A download response or a cancelled navigation leaves us here.
			window.clearTimeout( stuck );
			stuck = window.setTimeout( function () {

				store( 'remove', flagKey );
				cleanup();

			}, ( cfg.cover || 400 ) + 5000 );

		} );

		window.addEventListener( 'pagehide', function () {

			window.clearTimeout( stuck );

		} );

	}

	/*
	BOOT
	-- Decides between the initial loader, a transition ingress or nothing
	---------------------------------------------------------- */

	function shouldShowLoader() {

		if ( 'every' === cfg.frequency ) {

			return true;

		}

		if ( store( 'get', seenKey ) ) {

			return false;

		}

		// Without storage the first visit cannot be remembered, so skip it.
		return true === store( 'set', seenKey, '1' );

	}

	try {

		var raw = store( 'get', flagKey );
		var flag = null;

		store( 'remove', flagKey );

		try {

			flag = raw ? JSON.parse( raw ) : null;

		} catch ( error ) {

			flag = null;

		}

		// Performance mode never covers an arriving page, so its largest element shows at once.
		var arrived = ! reduced && ! cfg.performance && cfg.transition && flag && Date.now() - flag.t < 10000;

		if ( arrived && cfg.replay ) {

			startLoader();

		} else if ( arrived ) {

			startIngress( flag );

		} else if ( ! reduced && cfg.loader && shouldShowLoader() ) {

			startLoader();

		}

		if ( cfg.transition && ! reduced ) {

			bindNavigation();

		}

	} catch ( error ) {

		cleanup();

	}

	// A page restored from the back/forward cache must never show an overlay.
	window.addEventListener( 'pageshow', function ( event ) {

		if ( event.persisted ) {

			cleanup();

		}

	} );

} )();
