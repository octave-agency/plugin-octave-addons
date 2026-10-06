/*
FRONTEND SCRIPT TESTS
-- Runs delay.js and preload.js against a minimal fake DOM in Node's vm, so
-- execution order, attribute copying and preload exclusions are checked
-- without a browser. Usage: node tests/js/run.js
---------------------------------------------------------- */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );
const assert = require( 'assert' );

const assets = path.join( __dirname, '..', '..', 'modules', 'performance', 'assets' );
const results = { passed: 0, failed: 0 };

/*
FAKE DOM
---------------------------------------------------------- */

class FakeEvent {

	constructor( type ) {

		this.type = type;

	}

}

function makeEnvironment( options ) {

	const listeners = {};
	const timers = [];
	const log = [];
	const pending = [];

	function on( target, name, callback ) {

		( listeners[ target + ':' + name ] = listeners[ target + ':' + name ] || [] ).push( callback );

	}

	function off( target, name, callback ) {

		listeners[ target + ':' + name ] = ( listeners[ target + ':' + name ] || [] ).filter( ( item ) => item !== callback );

	}

	function fire( target, name, event ) {

		( listeners[ target + ':' + name ] || [] ).slice().forEach( ( callback ) => callback( event || {} ) );

	}

	class FakeElement {

		constructor( tag ) {

			this.tagName = tag.toUpperCase();
			this.attributes = [];
			this.events = {};
			this.text = '';
			this.parentNode = null;
			this.relList = { supports: () => true };

		}

		getAttribute( name ) {

			const found = this.attributes.find( ( item ) => item.name === name );

			return found ? found.value : null;

		}

		setAttribute( name, value ) {

			const found = this.attributes.find( ( item ) => item.name === name );

			if ( found ) {

				found.value = String( value );

			} else {

				this.attributes.push( { name: name, value: String( value ) } );

			}

		}

		hasAttribute( name ) {

			return null !== this.getAttribute( name );

		}

		addEventListener( name, callback ) {

			( this.events[ name ] = this.events[ name ] || [] ).push( callback );

		}

		emit( name ) {

			( this.events[ name ] || [] ).forEach( ( callback ) => callback() );

		}

		set src( value ) {

			this.setAttribute( 'src', value );

		}

		set type( value ) {

			this.setAttribute( 'type', value );

		}

		get dataset() {

			return {};

		}

		closest() {

			return this;

		}

	}

	const parent = {
		replaceChild( replacement, original ) {

			const index = scripts.indexOf( original );

			scripts[ index ] = replacement;
			replacement.parentNode = parent;
			original.parentNode = null;

			if ( replacement.getAttribute( 'src' ) ) {

				pending.push( replacement );

			} else {

				log.push( 'inline:' + replacement.text );

			}

		},
	};

	const scripts = [];

	const document = {
		readyState: 'complete',
		get scripts() {

			return scripts;

		},
		head: { appendChild: ( node ) => log.push( 'prefetch:' + node.href ) },
		body: { appendChild: ( node ) => log.push( 'append:' + node.getAttribute( 'src' ) ) },
		createElement: ( tag ) => new FakeElement( tag ),
		getElementById: ( id ) => scripts.find( ( item ) => item.getAttribute( 'id' ) === id ) || null,
		querySelectorAll: ( selector ) => 'script[data-oa-delay]' === selector ? scripts.filter( ( item ) => item.hasAttribute( 'data-oa-delay' ) ) : [],
		addEventListener: ( name, callback ) => on( 'document', name, callback ),
	};

	const window = {
		location: { href: 'https://example.com/current/', origin: 'https://example.com', pathname: '/current/', search: '' },
		addEventListener: ( name, callback ) => on( 'window', name, callback ),
		removeEventListener: ( name, callback ) => off( 'window', name, callback ),
		dispatchEvent: ( event ) => log.push( 'event:' + event.type ),
		setTimeout: ( callback, delay ) => timers.push( { callback, delay } ) - 1,
		clearTimeout: ( id ) => {

			if ( timers[ id ] ) {

				timers[ id ].callback = null;

			}

		},
		oaPreload: options.preload,
	};

	window.window = window;

	function addScript( attributes, text ) {

		const element = new FakeElement( 'script' );

		Object.keys( attributes ).forEach( ( name ) => element.setAttribute( name, attributes[ name ] ) );
		element.text = text || '';
		element.parentNode = parent;
		scripts.push( element );

		return element;

	}

	function run( file ) {

		const context = vm.createContext( {
			window: window,
			document: document,
			navigator: { connection: options.connection || {} },
			URL: URL,
			Event: FakeEvent,
			Promise: Promise,
		} );

		vm.runInContext( fs.readFileSync( path.join( assets, file ), 'utf8' ), context );

	}

	function loadNext() {

		const script = pending.shift();

		log.push( 'src:' + script.getAttribute( 'src' ) );
		script.emit( 'load' );

		return script;

	}

	function runTimers() {

		timers.splice( 0 ).forEach( ( timer ) => timer.callback && timer.callback() );

	}

	return { addScript, run, fire, log, pending, loadNext, runTimers, FakeElement };

}

function test( name, callback ) {

	try {

		callback();
		results.passed++;
		process.stdout.write( '.' );

	} catch ( error ) {

		results.failed++;
		console.log( '\nFAIL ' + name + ': ' + error.message );

	}

}

/*
DELAY LOADER
---------------------------------------------------------- */

test( 'delayed scripts wait for interaction and keep their order', () => {

	const env = makeEnvironment( {} );

	env.addScript( { type: 'text/oa-delayed', 'data-oa-delay': 'a', 'data-oa-src': 'https://a.test/a.js', integrity: 'sha384-a', crossorigin: 'anonymous' } );
	env.addScript( { type: 'text/oa-delayed', 'data-oa-delay': 'a' }, 'B' );
	env.addScript( { type: 'text/oa-delayed', 'data-oa-delay': 'c', 'data-oa-src': 'https://c.test/c.js', async: '' } );
	env.addScript( { type: 'text/oa-delayed', 'data-oa-delay': 'd', 'data-oa-src': 'https://d.test/d.js', 'data-oa-type': 'module' } );
	env.addScript( { type: 'text/oa-delayed', 'data-oa-delay': 'd' }, 'E' );
	env.addScript( { type: 'text/oa-delayed', 'data-oa-delay': 'a', 'data-oa-src': 'https://a.test/a.js' } );
	env.addScript( { id: 'oa-delay-loader' } );

	env.run( 'delay.js' );

	assert.deepStrictEqual( env.log, [], 'nothing runs before interaction' );

	env.fire( 'window', 'scroll' );

	assert.strictEqual( env.pending.length, 1, 'only the first blocking script is requested' );

	const first = env.loadNext();

	assert.strictEqual( first.getAttribute( 'integrity' ), 'sha384-a' );
	assert.strictEqual( first.getAttribute( 'crossorigin' ), 'anonymous' );
	assert.strictEqual( first.getAttribute( 'type' ), null, 'placeholder type removed' );
	assert.ok( ! first.attributes.some( ( item ) => 0 === item.name.indexOf( 'data-oa-' ) ), 'no Octave attributes copied' );

	assert.deepStrictEqual( env.pending.map( ( item ) => item.getAttribute( 'src' ) ), [ 'https://c.test/c.js', 'https://d.test/d.js' ], 'async C does not hold up D' );

	const moduleScript = env.pending[ 1 ];

	assert.strictEqual( moduleScript.getAttribute( 'type' ), 'module', 'original type restored' );

	env.loadNext();
	env.loadNext();

	assert.deepStrictEqual( env.log, [
		'src:https://a.test/a.js',
		'inline:B',
		'src:https://c.test/c.js',
		'src:https://d.test/d.js',
		'inline:E',
		'event:oa:delayed-scripts-loaded',
	], 'document order kept, duplicate A skipped' );

} );

test( 'scripts wait for interaction, not a timer', () => {

	const env = makeEnvironment( {} );

	env.addScript( { type: 'text/oa-delayed', 'data-oa-delay': 'x' }, 'X' );
	env.addScript( { id: 'oa-delay-loader' } );
	env.run( 'delay.js' );
	env.runTimers();

	assert.deepStrictEqual( env.log, [] );

} );

/*
LINK PRELOAD
---------------------------------------------------------- */

function preloadEnvironment( connection ) {

	const env = makeEnvironment( { preload: { delay: 100, exclude: [ '/wp-admin', 'add-to-cart=', 'action=logout', 'nonce=', '/checkout/' ] }, connection: connection } );

	env.run( 'preload.js' );

	return env;

}

function link( env, href, attributes ) {

	const element = new env.FakeElement( 'a' );

	element.href = href;
	Object.keys( attributes || {} ).forEach( ( name ) => element.setAttribute( name, attributes[ name ] ) );

	return element;

}

test( 'hover intent prefetches eligible same-origin links once', () => {

	const env = preloadEnvironment();
	const target = link( env, 'https://example.com/about/' );

	env.fire( 'document', 'mouseover', { target: target } );
	env.runTimers();
	env.fire( 'document', 'mouseover', { target: target } );
	env.runTimers();

	assert.deepStrictEqual( env.log, [ 'prefetch:https://example.com/about/' ], 'requested once, trailing slash kept' );

} );

test( 'leaving before the delay cancels the prefetch', () => {

	const env = preloadEnvironment();
	const target = link( env, 'https://example.com/team' );

	env.fire( 'document', 'mouseover', { target: target } );
	target.emit( 'mouseout' );
	env.runTimers();

	assert.deepStrictEqual( env.log, [] );

} );

test( 'unsafe and ineligible links are never prefetched', () => {

	const env = preloadEnvironment();
	const links = [
		link( env, 'https://other.test/page/' ),
		link( env, 'https://example.com/wp-admin/edit.php' ),
		link( env, 'https://example.com/shop/?add-to-cart=12' ),
		link( env, 'https://example.com/wp-login.php?action=logout&_wpnonce=abc' ),
		link( env, 'https://example.com/checkout/' ),
		link( env, 'https://example.com/brochure.pdf' ),
		link( env, 'https://example.com/file/', { download: '' } ),
		link( env, 'https://example.com/current/#section' ),
		link( env, 'mailto:hello@example.com' ),
		link( env, 'tel:+441234' ),
		link( env, 'javascript:void(0)' ),
	];

	links.forEach( ( target ) => env.fire( 'document', 'touchstart', { target: target } ) );

	assert.deepStrictEqual( env.log, [] );

} );

test( 'save-data and slow connections disable preloading', () => {

	[ { saveData: true }, { effectiveType: '2g' }, { effectiveType: 'slow-2g' } ].forEach( ( connection ) => {

		const env = preloadEnvironment( connection );

		env.fire( 'document', 'touchstart', { target: link( env, 'https://example.com/about/' ) } );

		assert.deepStrictEqual( env.log, [], JSON.stringify( connection ) );

	} );

} );

/*
SCROLL ANIMATIONS
-- The controller against a few fake Breakdance elements
---------------------------------------------------------- */

function animationEnvironment( light ) {

	const classes = new Set();
	const listeners = {};

	function element( top ) {

		const attributes = {};

		return {
			top: top,
			childNodes: [],
			getClientRects: () => [ 1 ],
			getBoundingClientRect: () => ( { top: top } ),
			closest: () => null,
			hasAttribute: ( name ) => name in attributes,
			getAttribute: ( name ) => ( name in attributes ? attributes[ name ] : null ),
			setAttribute: ( name, value ) => {

				attributes[ name ] = String( value );

			},
			classList: { add() {}, remove() {}, contains: () => false },
			style: { setProperty() {}, removeProperty() {} },
		};

	}

	const hero = element( 120 );
	const lower = element( 2400 );
	const observed = [];

	const window = {
		innerHeight: 900,
		oaAnimPerformance: light,
		matchMedia: () => ( { matches: false } ),
		setTimeout: () => 0,
		clearTimeout: () => {},
		addEventListener: () => {},
		IntersectionObserver: function () {

			this.observe = ( target ) => observed.push( target );
			this.disconnect = () => {};

		},
	};

	const document = {
		readyState: 'loading',
		documentElement: { classList: { add: ( name ) => classes.add( name ), remove: ( name ) => classes.delete( name ), contains: ( name ) => classes.has( name ) } },
		addEventListener: ( name, callback ) => {

			listeners[ name ] = callback;

		},
		querySelectorAll: ( selector ) => ( /bde-heading/.test( selector ) ? [ hero, lower ] : [] ),
	};

	window.document = document;

	vm.runInNewContext( fs.readFileSync( path.join( __dirname, '..', '..', 'modules', 'design', 'animations', 'assets', 'controller.js' ), 'utf8' ), { window: window, document: document, IntersectionObserver: window.IntersectionObserver } );

	// Splitting needs real text nodes, so only performance mode, which must skip it, asks for it.
	window.OctaveAnimations.preset( { split: light ? 'words' : false } );

	return { classes, listeners, hero, lower, observed };

}

test( 'performance mode leaves the first screen visible and never splits headings', () => {

	const env = animationEnvironment( true );

	assert.ok( ! env.classes.has( 'oa-anim-ready' ), 'nothing hidden before the scan' );

	env.listeners.DOMContentLoaded();

	assert.strictEqual( env.hero.getAttribute( 'data-oa-anim' ), null, 'hero left alone' );
	assert.strictEqual( env.lower.getAttribute( 'data-oa-anim' ), 'text', 'lower heading animates as text' );
	assert.strictEqual( env.lower.getAttribute( 'data-oa-split' ), null, 'not split' );
	assert.deepStrictEqual( env.observed, [ env.lower ] );
	assert.ok( env.classes.has( 'oa-anim-ready' ), 'hiding starts only after the scan' );

} );

test( 'without performance mode existing motion is unchanged', () => {

	const env = animationEnvironment( false );

	assert.ok( env.classes.has( 'oa-anim-ready' ), 'pre-scan hiding as before' );

	env.listeners.DOMContentLoaded();

	assert.strictEqual( env.observed.length, 2, 'every target animates' );

} );

/*
HEADING SPLIT
-- Real DOM-like nodes, so the markup the controller builds can be read back
---------------------------------------------------------- */

function splitEnvironment( markup ) {

	function text( value ) {

		return { nodeType: 3, textContent: value, parentNode: null };

	}

	function element( tag, attributes, children ) {

		const node = {
			nodeType: 1,
			tagName: tag.toUpperCase(),
			attributes: Object.keys( attributes || {} ).map( ( name ) => ( { name: name, value: attributes[ name ] } ) ),
			childNodes: [],
			style: { setProperty( name, value ) {

				if ( 0 === name.indexOf( '--oa-span' ) ) {

					node.setAttribute( 'style', ( node.getAttribute( 'style' ) || '' ) + name + ':' + value + ';' );

				}

			} },
			classList: { add() {}, remove() {}, contains: () => false },
			getAttribute( name ) {

				const found = this.attributes.find( ( item ) => item.name === name );

				return found ? found.value : null;

			},
			setAttribute( name, value ) {

				const found = this.attributes.find( ( item ) => item.name === name );

				if ( found ) {

					found.value = String( value );

				} else {

					this.attributes.push( { name: name, value: String( value ) } );

				}

			},
			hasAttribute( name ) {

				return null !== this.getAttribute( name );

			},
			set className( value ) {

				this.setAttribute( 'class', value );

			},
			get textContent() {

				return this.childNodes.map( ( child ) => child.textContent ).join( '' );

			},
			set textContent( value ) {

				this.childNodes = [ text( value ) ];

			},
			appendChild( child ) {

				( child.isFragment ? child.childNodes : [ child ] ).forEach( ( item ) => this.childNodes.push( item ) );

				return child;

			},
			replaceChild( replacement, original ) {

				const index = this.childNodes.indexOf( original );

				this.childNodes.splice( index, 1, ...( replacement.isFragment ? replacement.childNodes : [ replacement ] ) );

			},
			getBoundingClientRect() {

				return { top: 2400, left: this.x || 0, right: ( this.x || 0 ) + 50 };

			},
			getClientRects: () => [ 1 ],
			closest: () => null,
			querySelectorAll( selector ) {

				const found = [];
				const walk = ( item ) => item.childNodes.forEach( ( child ) => {

					if ( 1 !== child.nodeType ) {

						return;

					}

					if ( '.oa-w' === selector && 'oa-w' === child.getAttribute( 'class' ) ) {

						found.push( child );

					}

					walk( child );

				} );

				walk( this );

				return found;

			},
		};

		// Each created span sits 60px right of the last, so word masks (outer and inner spans) are 120px apart on one line.
		if ( 'span' === tag && ! attributes ) {

			node.x = 60 * ( element.created = ( element.created || 0 ) + 1 );

		}

		( children || [] ).forEach( ( child ) => node.appendChild( 'string' === typeof child ? text( child ) : child ) );

		return node;

	}

	function html( node ) {

		if ( 3 === node.nodeType ) {

			return node.textContent;

		}

		const attributes = node.attributes.map( ( item ) => ' ' + item.name + '="' + item.value + '"' ).join( '' );

		return '<' + node.tagName.toLowerCase() + attributes + '>' + node.childNodes.map( html ).join( '' ) + '</' + node.tagName.toLowerCase() + '>';

	}

	const heading = markup( element );
	const listeners = {};

	const document = {
		readyState: 'loading',
		documentElement: { classList: { add() {}, remove() {}, contains: () => false } },
		createElement: ( tag ) => element( tag ),
		createTextNode: text,
		createDocumentFragment: () => {

			const fragment = element( 'fragment' );

			fragment.isFragment = true;

			return fragment;

		},
		addEventListener: ( name, callback ) => {

			listeners[ name ] = callback;

		},
		querySelectorAll: ( selector ) => ( /bde-heading/.test( selector ) ? [ heading ] : [] ),
	};

	const window = {
		innerHeight: 900,
		document: document,
		matchMedia: () => ( { matches: false } ),
		setTimeout: () => 0,
		clearTimeout: () => {},
		addEventListener: () => {},
		IntersectionObserver: function () {

			this.observe = () => {};
			this.disconnect = () => {};

		},
	};

	vm.runInNewContext( fs.readFileSync( path.join( __dirname, '..', '..', 'modules', 'design', 'animations', 'assets', 'controller.js' ), 'utf8' ), { window: window, document: document, IntersectionObserver: window.IntersectionObserver } );

	window.OctaveAnimations.preset( { split: 'words' } );
	listeners.DOMContentLoaded();

	return html( heading );

}

test( 'a classed span stays one wrapper and its words share one continuous paint', () => {

	const output = splitEnvironment( ( element ) => element( 'h2', { class: 'bde-heading' }, [
		'Explore our ',
		element( 'span', { class: 'text-gradient' }, [ 'virtual office address' ] ),
		' services',
	] ) );

	assert.ok( /<span class="text-gradient" data-oa-span="" style="--oa-span-w:290px;"><span class="oa-w" style="--oa-span-x:0px;"><span class="oa-wi">virtual<\/span><\/span> <span class="oa-w" style="--oa-span-x:-120px;"><span class="oa-wi">office<\/span><\/span> <span class="oa-w" style="--oa-span-x:-240px;"><span class="oa-wi">address<\/span><\/span><\/span>/.test( output ), output );
	assert.ok( output.includes( '<span class="oa-w"><span class="oa-wi">services</span></span>' ), 'words outside it are untouched' );
	assert.strictEqual( ( output.match( /text-gradient/g ) || [] ).length, 1, 'one span, never one per word' );

} );

test( 'any class counts, while unclassed spans and links are only walked into', () => {

	const output = splitEnvironment( ( element ) => element( 'h2', { class: 'bde-heading' }, [
		element( 'span', { class: 'brand' }, [ 'Octave' ] ),
		' ',
		element( 'span', {}, [ 'plain' ] ),
		' ',
		element( 'a', { href: '/x' }, [ 'more' ] ),
	] ) );

	assert.ok( /<span class="brand" data-oa-span="" style="--oa-span-w:\d+px;"><span class="oa-w" style="--oa-span-x:0px;"><span class="oa-wi">Octave/.test( output ), output );
	assert.ok( output.includes( '<span><span class="oa-w"><span class="oa-wi">plain</span></span></span>' ), 'no class, no marker' );
	assert.ok( output.includes( '<a href="/x"><span class="oa-w"><span class="oa-wi">more</span></span></a>' ), 'links kept' );

} );

/*
PERFORMANCE SELECT ALL
-- The real Performance admin script against one checkbox group, with
-- change events bubbling to the page container as a browser would
---------------------------------------------------------- */

function selectAllEnvironment( ticked ) {

	const listeners = {};
	const group = { tag: 'group' };

	function checkbox( master, checked ) {

		return {
			type: 'checkbox',
			name: master ? '' : 'services[]',
			checked: !! checked,
			indeterminate: false,
			disabled: false,
			master: master,
			hasAttribute: ( name ) => master && 'data-oa-perf-select-all' === name,
			closest: ( selector ) => ( '[data-oa-perf-select-group]' === selector ? group : null ),
			dispatchEvent( event ) {

				event.target = this;
				( listeners[ event.type ] || [] ).forEach( ( callback ) => callback( event ) );

			},
		};

	}

	const master = checkbox( true, false );
	const boxes = ticked.map( ( value ) => checkbox( false, value ) );

	group.querySelectorAll = () => [ master ].concat( boxes );

	const entry = {
		addEventListener: ( type, callback ) => ( listeners[ type ] = listeners[ type ] || [] ).push( callback ),
		querySelectorAll: ( selector ) => ( '[data-oa-perf-select-all]' === selector ? [ master ] : [] ),
		querySelector: () => null,
	};

	function FakeChange( type ) {

		this.type = type;
		this.stopPropagation = () => {};

	}

	vm.runInNewContext( fs.readFileSync( path.join( assets, 'admin.js' ), 'utf8' ), {
		window: { oaPerf: { ajaxUrl: '/wp-admin/admin-ajax.php', i18n: {} } },
		document: { getElementById: ( id ) => ( 'oa-entry-performance' === id ? entry : null ) },
		Event: FakeChange,
	} );

	return { master, boxes, change: ( box ) => box.dispatchEvent( new FakeChange( 'change' ) ) };

}

test( 'select all ticks and clears every checkbox in its group', () => {

	const env = selectAllEnvironment( [ false, false, false, false ] );

	env.master.checked = true;
	env.change( env.master );

	assert.deepStrictEqual( env.boxes.map( ( box ) => box.checked ), [ true, true, true, true ], 'every box ticked' );
	assert.strictEqual( env.master.checked, true );
	assert.strictEqual( env.master.indeterminate, false );

	env.master.checked = false;
	env.change( env.master );

	assert.deepStrictEqual( env.boxes.map( ( box ) => box.checked ), [ false, false, false, false ], 'every box cleared' );

} );

test( 'select all shows partly ticked while only some boxes are', () => {

	const env = selectAllEnvironment( [ true, false, false ] );

	assert.strictEqual( env.master.indeterminate, true, 'on load' );

	env.boxes[1].checked = true;
	env.boxes[2].checked = true;
	env.change( env.boxes[2] );

	assert.strictEqual( env.master.checked, true, 'all ticked by hand' );
	assert.strictEqual( env.master.indeterminate, false );

} );

/*
LAZY VIDEO
-- The video loader against fake videos, observers and connections
---------------------------------------------------------- */

function videoEnvironment( options ) {

	const opts = options || {};
	const listeners = {};
	const observers = [];
	const mutations = [];
	const idle = [];
	const videos = [];

	function element( attributes, children ) {

		const attrs = Object.assign( {}, attributes );

		return {
			nodeType: 1,
			attrs: attrs,
			children: children || [],
			plays: 0,
			loads: 0,
			preload: attrs.preload || '',
			autoplay: false,
			controls: false,
			poster: '',
			getAttribute: ( name ) => ( name in attrs ? attrs[ name ] : null ),
			setAttribute: ( name, value ) => {

				attrs[ name ] = String( value );

			},
			hasAttribute: ( name ) => name in attrs,
			removeAttribute: ( name ) => {

				delete attrs[ name ];

			},
			matches( selector ) {

				return 'video[data-oa-lazy-video]' === selector && 'data-oa-lazy-video' in attrs;

			},
			querySelector( selector ) {

				return this.querySelectorAll( selector )[ 0 ] || null;

			},
			querySelectorAll( selector ) {

				if ( 'source[data-oa-src]' === selector ) {

					return this.children.filter( ( child ) => child.hasAttribute( 'data-oa-src' ) );

				}

				return find( this.children, selector );

			},
			load() {

				this.loads++;

			},
			play() {

				this.plays++;

				return Promise.resolve();

			},
		};

	}

	function find( list, selector ) {

		if ( 'video[data-oa-lazy-video]' === selector ) {

			return list.filter( ( item ) => item.hasAttribute && item.hasAttribute( 'data-oa-lazy-video' ) );

		}

		if ( 'video' === selector ) {

			return list.filter( ( item ) => item.hasAttribute && item.hasAttribute( 'data-oa-lazy-video' ) );

		}

		if ( /data-oa-poster\]/.test( selector ) ) {

			return list.filter( ( item ) => item.hasAttribute && item.hasAttribute( 'data-oa-poster' ) && ! item.hasAttribute( 'data-oa-poster-watched' ) );

		}

		return [];

	}

	function on( target, name, callback ) {

		( listeners[ target + ':' + name ] = listeners[ target + ':' + name ] || [] ).push( callback );

	}

	const document = {
		readyState: opts.readyState || 'complete',
		body: {},
		querySelectorAll: ( selector ) => find( videos, selector ),
		addEventListener: ( name, callback ) => on( 'document', name, callback ),
	};

	function IntersectionObserver( callback, config ) {

		this.callback = callback;
		this.config = config;
		this.observed = [];
		this.observe = ( target ) => this.observed.push( target );
		this.unobserve = ( target ) => {

			this.observed = this.observed.filter( ( item ) => item !== target );

		};
		observers.push( this );

	}

	function MutationObserver( callback ) {

		this.observe = () => mutations.push( callback );

	}

	const window = {
		devicePixelRatio: opts.dpr || 1,
		IntersectionObserver: IntersectionObserver,
		MutationObserver: MutationObserver,
		requestIdleCallback: ( callback ) => idle.push( callback ),
		setTimeout: ( callback ) => idle.push( callback ),
		addEventListener: ( name, callback ) => on( 'window', name, callback ),
	};

	function run() {

		vm.runInNewContext( fs.readFileSync( path.join( __dirname, '..', '..', 'modules', 'breakdance', 'lazy-load', 'assets', 'lazy-video.js' ), 'utf8' ), {
			window: window,
			document: document,
			navigator: { connection: opts.connection || {} },
			Promise: Promise,
		} );

	}

	function fire( target, name, event ) {

		( listeners[ target + ':' + name ] || [] ).slice().forEach( ( callback ) => callback( event || {} ) );

	}

	function runIdle() {

		idle.splice( 0 ).forEach( ( callback ) => callback() );

	}

	// The activation observer is the one with no margin.
	function viewport() {

		return observers.find( ( observer ) => '0px' === observer.config.rootMargin );

	}

	return { element, videos, run, fire, runIdle, viewport, observers, mutations };

}

function parkedVideo( env, extra ) {

	const source = env.element( { 'data-oa-src': '/hero.mp4', type: 'video/mp4' } );
	const video = env.element( Object.assign( { 'data-oa-lazy-video': '', 'data-oa-autoplay': '', 'data-oa-preload': 'metadata', preload: 'none' }, extra || {} ), [ source ] );

	env.videos.push( video );

	return { video, source };

}

test( 'parked videos load only once genuinely in the viewport', () => {

	const env = videoEnvironment();
	const { video, source } = parkedVideo( env );

	env.run();
	env.runIdle();

	const observer = env.viewport();

	assert.ok( observer, 'observed with no margin' );
	assert.strictEqual( observer.config.threshold, 0.01 );
	assert.strictEqual( source.getAttribute( 'src' ), null, 'still parked' );
	assert.strictEqual( video.preload, 'none', 'never preload="auto" early' );

	observer.callback( [ { target: video, isIntersecting: false, intersectionRatio: 0 } ] );

	assert.strictEqual( source.getAttribute( 'src' ), null, 'near is not in view' );

	observer.callback( [ { target: video, isIntersecting: true, intersectionRatio: 0.4 } ] );

	assert.strictEqual( source.getAttribute( 'src' ), '/hero.mp4', 'restored' );
	assert.strictEqual( video.preload, 'auto' );
	assert.strictEqual( video.autoplay, true );
	assert.strictEqual( video.loads, 1 );
	assert.strictEqual( video.plays, 1 );

	observer.callback( [ { target: video, isIntersecting: true, intersectionRatio: 1 } ] );

	assert.strictEqual( video.loads, 1, 'activated once' );

} );

test( 'nothing activates before the page has loaded', () => {

	const env = videoEnvironment( { readyState: 'interactive' } );

	parkedVideo( env );
	env.run();
	env.runIdle();

	assert.strictEqual( env.viewport(), undefined, 'waiting for load' );

	env.fire( 'window', 'load' );
	env.runIdle();

	assert.ok( env.viewport(), 'starts after load and idle' );

} );

test( 'save-data and slow connections keep posters and wait for the viewer', () => {

	[ { saveData: true }, { effectiveType: '2g' }, { effectiveType: 'slow-2g' } ].forEach( ( connection ) => {

		const env = videoEnvironment( { connection: connection } );
		const { video, source } = parkedVideo( env, { 'data-oa-poster': '/p.jpg' } );

		env.run();
		env.runIdle();

		assert.strictEqual( env.viewport().observed.length, 0, JSON.stringify( connection ) + ' not observed' );
		assert.strictEqual( video.controls, true, 'controls offered' );
		assert.strictEqual( source.getAttribute( 'src' ), null, 'nothing downloads' );

		env.fire( 'document', 'play', { target: video } );

		assert.strictEqual( source.getAttribute( 'src' ), '/hero.mp4', 'deliberate playback loads it' );
		assert.strictEqual( video.autoplay, false, 'no autoplay on a constrained connection' );
		assert.strictEqual( video.plays, 1 );

	} );

} );

test( 'videos added later are watched once each', () => {

	const env = videoEnvironment();

	env.run();
	env.runIdle();

	const { video } = parkedVideo( env );
	const wrapper = env.element( {}, [ video ] );

	env.mutations[ 0 ]( [ { addedNodes: [ wrapper ] } ] );
	env.mutations[ 0 ]( [ { addedNodes: [ wrapper ] } ] );

	assert.deepStrictEqual( env.viewport().observed, [ video ] );

} );

test( 'parked posters return at the size they are shown', () => {

	const env = videoEnvironment( { dpr: 2 } );
	const { video } = parkedVideo( env, { 'data-oa-poster': '/poster.jpg', 'data-oa-poster-srcset': '/poster-768x433.jpg 768w, /poster.jpg 1280w' } );

	env.run();

	const posters = env.observers.find( ( observer ) => '600px 0px' === observer.config.rootMargin );

	posters.callback( [ { target: video, isIntersecting: true, boundingClientRect: { width: 360 } } ] );

	assert.strictEqual( video.poster, '/poster-768x433.jpg', '360px at 2x needs 720px' );
	assert.strictEqual( video.getAttribute( 'data-oa-poster' ), null );

} );

/*
NOTIFICATIONS BAR
-- Layout is only read where the browser has already laid out the page
---------------------------------------------------------- */

test( 'notifications bar never reads layout straight after writing styles', () => {

	const styles = {};
	const log = [];
	const frames = [];
	let resize = null;

	const track = {};

	Object.defineProperty( track, 'offsetHeight', { get: () => {

		log.push( 'read' );

		return 40;

	} } );

	const bar = {
		classList: { contains: ( name ) => 'oa-nb--animated' === name, add: () => {}, remove: () => {} },
		getAttribute: () => null,
		querySelector: ( selector ) => ( '.oa-nb__track' === selector ? track : null ),
		addEventListener: () => {},
	};

	const root = {
		style: { setProperty: ( name, value ) => {

			styles[ name ] = value;
			log.push( 'write' );

		}, removeProperty: () => {} },
		classList: { add: () => {}, remove: () => {} },
	};

	const window = {
		pageYOffset: 0,
		location: { protocol: 'https:' },
		ResizeObserver: function ( callback ) {

			resize = callback;
			this.observe = () => {};

		},
		getComputedStyle: () => {

			log.push( 'style' );

			return { getPropertyValue: () => '300ms' };

		},
		requestAnimationFrame: ( callback ) => frames.push( callback ),
		setTimeout: () => 0,
		clearTimeout: () => {},
		addEventListener: () => {},
	};

	vm.runInNewContext( fs.readFileSync( path.join( __dirname, '..', '..', 'modules', 'engagement', 'notifications-bar', 'assets', 'notifications-bar.js' ), 'utf8' ), {
		window: window,
		document: { getElementById: () => bar, documentElement: root },
	} );

	assert.deepStrictEqual( log, [], 'nothing measured while the script runs' );

	resize( [ { borderBoxSize: [ { blockSize: 40.4 } ] } ] );

	assert.strictEqual( styles[ '--oa-nb-height' ], '40px', 'height from ResizeObserver' );
	assert.ok( ! log.includes( 'read' ), 'no offsetHeight read' );

	frames.shift()();

	assert.ok( log.includes( 'style' ), 'animation length read inside a frame' );

} );

/*
MINIFIED ASSETS
-- Every frontend asset ships a minified copy built from its current source
---------------------------------------------------------- */

test( 'every frontend asset has a current, valid minified copy', () => {

	const assets = require( './assets.js' );

	assets.sources().forEach( ( source ) => {

		const file = path.join( assets.root, assets.minified( source ) );

		assert.ok( fs.existsSync( file ), source + ' has no minified copy; run tests/build-assets.sh' );

		const code = fs.readFileSync( file, 'utf8' );

		assert.ok( code.includes( '/*oa:' + assets.hash( source ) + '*/' ), source + ' changed since it was minified; run tests/build-assets.sh' );

		if ( source.endsWith( '.js' ) ) {

			assert.doesNotThrow( () => new vm.Script( code ), source + ' minified copy does not parse' );

		}

	} );

} );

console.log( '\n\n' + results.passed + ' passed, ' + results.failed + ' failed' );
process.exit( results.failed ? 1 : 0 );
