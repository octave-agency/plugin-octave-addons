/*
MINIFIED ASSETS
-- Lists the frontend sources that ship a .min copy, and hashes a source so
-- the copy can record which version it was built from
-- node tests/js/assets.js          prints every source path
-- node tests/js/assets.js --hash f prints the hash of one source
---------------------------------------------------------- */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const crypto = require( 'crypto' );

const root = path.join( __dirname, '..', '..' );

const folders = [
	'modules/performance/assets',
	'modules/breakdance/lazy-load/assets',
	'modules/breakdance/ajax-filtering/assets',
	'modules/design/animations/assets',
	'modules/design/animations/assets/presets',
	'modules/design/page-loader/assets',
	'modules/design/page-loader/assets/loaders',
	'modules/design/page-loader/assets/transitions',
	'modules/engagement/mobile-contact-popup/assets',
	'modules/engagement/notifications-bar/assets',
	'modules/ai-agents/accessibility-tree/assets',
];

/*
SOURCES
-- Every unminified .js and .css file in the folders above, relative to the plugin root
---------------------------------------------------------- */

function sources() {

	return folders.flatMap( ( folder ) => {

		return fs.readdirSync( path.join( root, folder ) ).filter( ( file ) => {

			return /\.(js|css)$/.test( file ) && ! /\.min\.(js|css)$/.test( file );

		} ).map( ( file ) => folder + '/' + file );

	} );

}

function hash( source ) {

	return crypto.createHash( 'sha1' ).update( fs.readFileSync( path.join( root, source ) ) ).digest( 'hex' ).slice( 0, 12 );

}

function minified( source ) {

	return source.replace( /\.(js|css)$/, '.min.$1' );

}

module.exports = { root, sources, hash, minified };

if ( require.main === module ) {

	if ( '--hash' === process.argv[ 2 ] ) {

		process.stdout.write( hash( process.argv[ 3 ] ) );

	} else {

		process.stdout.write( sources().join( '\n' ) + '\n' );

	}

}
