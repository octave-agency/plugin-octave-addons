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
	env.addScript( { id: 'oa-delay-loader', 'data-timeout': '0' } );

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

test( 'timeout releases scripts without interaction', () => {

	const env = makeEnvironment( {} );

	env.addScript( { type: 'text/oa-delayed', 'data-oa-delay': 'x' }, 'X' );
	env.addScript( { id: 'oa-delay-loader', 'data-timeout': '5' } );
	env.run( 'delay.js' );

	assert.deepStrictEqual( env.log, [] );

	env.runTimers();

	assert.strictEqual( env.log[ 0 ], 'inline:X' );

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

console.log( '\n\n' + results.passed + ' passed, ' + results.failed + ' failed' );
process.exit( results.failed ? 1 : 0 );
