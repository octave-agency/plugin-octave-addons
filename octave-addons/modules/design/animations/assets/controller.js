/*
SCROLL ANIMATION CONTROLLER
-- Shared, unreplaceable core for every scroll preset and for custom code
-- Hides targets only while it is in charge, reveals each one once as it
-- scrolls into view, and releases everything if anything fails
-- Presets and custom JavaScript only configure it via OctaveAnimations.preset()
-- Performance mode (window.oaAnimPerformance) hides nothing before the scan,
-- leaves everything in the first screen untouched so the largest element is
-- painted at once, and never splits headings
---------------------------------------------------------- */

( function () {

	'use strict';

	var root = document.documentElement;
	var reduced = window.matchMedia && window.matchMedia( '( prefers-reduced-motion: reduce )' ).matches;
	var light = true === window.oaAnimPerformance;

	/*
	TARGETS
	-- Kept in step with the pre-scan selector list in presets/base.css, so
	-- everything hidden before the scan is either tagged or released by it
	---------------------------------------------------------- */

	var kinds = [
		{ kind: 'heading', selector: '.bde-section .bde-heading' },
		{ kind: 'media', selector: '.bde-section .bde-image, .bde-section .bde-video, .bde-section .swiper' },
		{ kind: 'item', selector: '.bde-section .bde-column, .bde-section .bde-grid > div, .bde-section .bde-loop:not(.ee-posts-isotope) > .bde-loop-item, .bde-section .bde-faq__item' },
		{ kind: 'text', selector: '.bde-section .bde-text, .bde-section .bde-rich-text, .bde-section .bde-button, .oa_anim' }
	];

	var config = {
		split: false,
		stagger: 80,
		maxStagger: 480,
		wordStagger: 40,
		lineStagger: 100,
		maxWords: 40,
		settle: 1400,
		manual: false,
		prepare: null,
		delay: null
	};

	var api = {
		reduced: reduced,
		preset: preset,
		reveal: reveal,
		split: splitHeading
	};

	var observer = null;
	var pending = 0;
	var released = false;

	window.OctaveAnimations = api;

	if ( reduced ) {

		return;

	}

	// In performance mode nothing is hidden until the scan has skipped the first screen.
	if ( ! light ) {

		root.classList.add( 'oa-anim-ready' );

	}

	// If the scan never completes, nothing may stay hidden.
	var guard = window.setTimeout( release, 6000 );

	/*
	PRESET
	-- Merges a preset or custom configuration before the scan runs
	---------------------------------------------------------- */

	function preset( options ) {

		Object.keys( options || {} ).forEach( function ( key ) {

			config[ key ] = options[ key ];

		} );

		return api;

	}

	/*
	RELEASE
	-- Drops every hidden state at once; used by the guard and on errors
	---------------------------------------------------------- */

	function release() {

		released = true;
		root.classList.remove( 'oa-anim-ready' );

		if ( observer ) {

			observer.disconnect();

		}

	}

	/*
	SPLIT HEADING
	-- Wraps each word of a heading's text nodes in a mask, walking into
	-- links, spans and inline styling instead of flattening them. Line
	-- breaks, icons, SVGs and images are left exactly where they were.
	---------------------------------------------------------- */

	var skipTags = /^(BR|SVG|IMG|I|SCRIPT|STYLE|TEXTAREA|SELECT|CANVAS|VIDEO|IFRAME|PICTURE)$/;

	function splitNode( node, words, spans ) {

		Array.prototype.slice.call( node.childNodes ).forEach( function ( child ) {

			if ( 1 === child.nodeType ) {

				if ( ! skipTags.test( child.tagName.toUpperCase() ) ) {

					if ( 'SPAN' === child.tagName.toUpperCase() && ( child.getAttribute( 'class' ) || '' ).trim() ) {

						child.setAttribute( 'data-oa-span', '' );
						spans.push( child );

					}

					splitNode( child, words, spans );

				}

				return;

			}

			if ( 3 !== child.nodeType || ! child.textContent.trim() ) {

				return;

			}

			var fragment = document.createDocumentFragment();

			child.textContent.split( /(\s+)/ ).forEach( function ( part ) {

				if ( ! part ) {

					return;

				}

				if ( ! part.trim() ) {

					fragment.appendChild( document.createTextNode( part ) );

					return;

				}

				var outer = document.createElement( 'span' );
				var inner = document.createElement( 'span' );

				outer.className = 'oa-w';
				inner.className = 'oa-wi';
				inner.textContent = part;
				outer.appendChild( inner );
				fragment.appendChild( outer );
				words.push( outer );

			} );

			node.replaceChild( fragment, child );

		} );

	}

	/*
	STYLED SPANS
	-- A span with a class inside a heading, such as one that clips a
	-- gradient to its text, keeps wrapping its words. Anything it paints
	-- behind or through its text is handed down to each word instead (see
	-- base.css): painted once around words that move, the browser has to
	-- repaint it every frame and the reveal stutters. These offsets line
	-- the words' slices up into one continuous paint, carried across line
	-- breaks as the original was
	---------------------------------------------------------- */

	function alignSpan( span ) {

		var masks = span.querySelectorAll( '.oa-w' );
		var base  = 0;
		var start = null;
		var right = 0;
		var top   = null;

		Array.prototype.forEach.call( masks, function ( mask ) {

			var box = mask.getBoundingClientRect();

			// A word on a new line continues where the last line ended.
			if ( null === top || Math.abs( box.top - top ) > 4 ) {

				base += null === start ? 0 : right - start;
				start = box.left;
				top   = box.top;

			}

			right = box.right;
			mask.style.setProperty( '--oa-span-x', -Math.round( base + box.left - start ) + 'px' );

		} );

		span.style.setProperty( '--oa-span-w', Math.round( base + ( null === start ? 0 : right - start ) ) + 'px' );

	}

	var styledSpans = [];
	var realignTimer = 0;

	function realignSpans() {

		styledSpans.forEach( alignSpan );

	}

	/*
	WATCH SPANS
	-- Word widths change when web fonts arrive and when the window resizes,
	-- so the slices are lined up again then. Set up once, on first use
	---------------------------------------------------------- */

	function watchSpans() {

		if ( styledSpans.length ) {

			return;

		}

		window.addEventListener( 'resize', function () {

			window.clearTimeout( realignTimer );
			realignTimer = window.setTimeout( realignSpans, 150 );

		} );

		if ( document.fonts && document.fonts.ready ) {

			document.fonts.ready.then( realignSpans );

		}

	}

	function splitHeading( heading, mode ) {

		if ( heading.hasAttribute( 'data-oa-split' ) ) {

			return true;

		}

		var count = ( heading.textContent.trim().match( /\s+/g ) || [] ).length + 1;

		if ( count > config.maxWords ) {

			return false;

		}

		var words = [];
		var spans = [];

		splitNode( heading, words, spans );
		heading.setAttribute( 'data-oa-split', mode || 'words' );

		if ( spans.length ) {

			watchSpans();
			spans.forEach( alignSpan );
			styledSpans = styledSpans.concat( spans );

		}

		var line = 0;
		var lastTop = null;

		words.forEach( function ( word, index ) {

			var delay = index * config.wordStagger;

			if ( 'lines' === mode ) {

				var top = word.getBoundingClientRect().top;

				if ( null !== lastTop && Math.abs( top - lastTop ) > 4 ) {

					line++;

				}

				lastTop = top;
				delay = line * config.lineStagger;

			}

			word.style.setProperty( '--oa-wd', Math.min( delay, 900 ) + 'ms' );

		} );

		return true;

	}

	/*
	REVEAL
	-- Shows one target once, then clears every animation-only style after it
	-- settles so no transition, filter, clip or will-change lingers
	---------------------------------------------------------- */

	function reveal( element, delay ) {

		if ( element.hasAttribute( 'data-oa-revealed' ) ) {

			return;

		}

		delay = delay || 0;

		element.setAttribute( 'data-oa-revealed', '' );
		element.style.setProperty( '--oa-delay', delay + 'ms' );
		element.classList.add( 'visible' );

		window.setTimeout( function () {

			element.classList.add( 'oa-done' );
			element.style.removeProperty( '--oa-delay' );
			element.style.removeProperty( '--oa-from' );

		}, delay + config.settle );

	}

	function delayFor( element, index ) {

		var fallback = Math.min( index * config.stagger, config.maxStagger );

		if ( 'function' !== typeof config.delay ) {

			return fallback;

		}

		try {

			return Math.max( 0, Number( config.delay( element, index, fallback ) ) || 0 );

		} catch ( error ) {

			return fallback;

		}

	}

	/*
	OBSERVE
	-- A target counts as seen once a fifth of it, or a seventh of the
	-- viewport's height of it, is on screen, so very tall columns and short
	-- footer lines both reveal. Manual mode leaves timing to custom code and
	-- only steps in for a target stuck on screen for two seconds.
	---------------------------------------------------------- */

	function isSeen( entry ) {

		var viewport = window.innerHeight || root.clientHeight;

		return entry.isIntersecting && ( entry.intersectionRatio >= 0.2 || entry.intersectionRect.height >= viewport * 0.15 );

	}

	function onIntersect( entries ) {

		var batch = [];

		entries.forEach( function ( entry ) {

			var element = entry.target;

			if ( ! config.manual ) {

				if ( isSeen( entry ) ) {

					batch.push( element );

				}

				return;

			}

			window.clearTimeout( element.oaStuckTimer );

			if ( isSeen( entry ) ) {

				element.oaStuckTimer = window.setTimeout( function () {

					finish( element );
					reveal( element, 0 );

				}, 2000 );

			}

		} );

		batch.forEach( function ( element, index ) {

			finish( element );
			reveal( element, delayFor( element, index ) );

		} );

	}

	function finish( element ) {

		if ( ! observer ) {

			return;

		}

		observer.unobserve( element );
		pending--;

		if ( pending <= 0 ) {

			observer.disconnect();
			observer = null;

		}

	}

	/*
	SCAN
	-- Tags each visible target once with its kind, lets the preset prepare
	-- it, then marks the document scanned so later AJAX content is untouched
	-- Targets without layout (closed tabs, hidden templates) are not tagged
	-- and simply stay visible
	-- Anything inside or carrying .oa-no-anim is opted out and left alone
	---------------------------------------------------------- */

	function scan() {

		var targets = [];

		kinds.forEach( function ( group ) {

			document.querySelectorAll( group.selector ).forEach( function ( element ) {

				if ( element.hasAttribute( 'data-oa-anim' ) || element.closest( '.oa-no-anim' ) || ! element.getClientRects().length ) {

					return;

				}

				if ( light && element.getBoundingClientRect().top < window.innerHeight ) {

					return;

				}

				var kind = group.kind;

				if ( 'heading' === kind && ! ( ! light && config.split && splitHeading( element, config.split ) ) ) {

					kind = 'text';

				}

				element.setAttribute( 'data-oa-anim', kind );
				targets.push( element );

			} );

		} );

		if ( 'function' === typeof config.prepare ) {

			targets.forEach( function ( element, index ) {

				try {

					config.prepare( element, index );

				} catch ( error ) {

					element.style.removeProperty( '--oa-from' );

				}

			} );

		}

		root.classList.add( 'oa-anim-scanned' );

		if ( light ) {

			root.classList.add( 'oa-anim-ready' );

		}

		return targets;

	}

	function init() {

		if ( released ) {

			return;

		}

		try {

			var targets = scan();

			if ( 'function' !== typeof window.IntersectionObserver ) {

				targets.forEach( function ( element ) {

					reveal( element, 0 );

				} );

			} else if ( targets.length ) {

				pending = targets.length;
				observer = new IntersectionObserver( onIntersect, { threshold: [ 0, 0.05, 0.1, 0.2 ] } );

				targets.forEach( function ( element ) {

					observer.observe( element );

				} );

			}

			window.clearTimeout( guard );

		} catch ( error ) {

			release();

		}

	}

	if ( 'loading' === document.readyState ) {

		document.addEventListener( 'DOMContentLoaded', init );

	} else {

		window.setTimeout( init, 0 );

	}

	// A page restored from the back/forward cache keeps its revealed state.
	window.addEventListener( 'pageshow', function ( event ) {

		if ( event.persisted && root.classList.contains( 'oa-anim-ready' ) && ! root.classList.contains( 'oa-anim-scanned' ) ) {

			release();

		}

	} );

} )();
