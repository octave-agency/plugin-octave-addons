/*
PERFORMANCE ADMIN
-- Async actions on the Performance page: cache purges, Safe Mode, Cloudflare,
-- font refresh, diagnostics and database cleanup. Every result is written
-- with textContent into a live region, so screen readers hear the outcome
-- and nothing returned by the server is ever parsed as HTML
---------------------------------------------------------- */

( function () {

	'use strict';

	var config = window.oaPerf || {};
	var i18n = config.i18n || {};
	var entry = document.getElementById( 'oa-entry-performance' );

	if ( ! entry || ! config.ajaxUrl ) {

		return;

	}

	/*
	LOCAL INPUTS
	-- Action fields have no name and are never saved, so typing in them must
	-- not mark the settings form as having unsaved changes
	---------------------------------------------------------- */

	[ 'input', 'change' ].forEach( function ( type ) {

		entry.addEventListener( type, function ( event ) {

			if ( event.target && ! event.target.name && 'SELECT' !== event.target.tagName ) {

				event.stopPropagation();

			}

		} );

	} );

	/*
	REQUEST
	-- Posts one action with the page nonce and resolves with the JSON body
	---------------------------------------------------------- */

	function request( action, params ) {

		var body = new FormData();

		body.append( 'action', action );
		body.append( 'nonce', config.nonce );

		Object.keys( params || {} ).forEach( function ( key ) {

			body.append( key, params[ key ] );

		} );

		return fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( response ) {

			return response.json().catch( function () {

				return { success: false, data: { message: i18n.failed } };

			} );

		} );

	}

	/*
	RESULT HELPERS
	---------------------------------------------------------- */

	function clear( region ) {

		while ( region.firstChild ) {

			region.removeChild( region.firstChild );

		}

	}

	function element( tag, className, text ) {

		var node = document.createElement( tag );

		if ( className ) {

			node.className = className;

		}

		if ( undefined !== text && null !== text ) {

			node.textContent = String( text );

		}

		return node;

	}

	function showMessage( region, message, isError ) {

		clear( region );
		region.classList.toggle( 'is-error', !! isError );

		String( message || '' ).split( '\n' ).forEach( function ( line ) {

			if ( line ) {

				region.appendChild( element( 'p', '', line ) );

			}

		} );

	}

	function showLayers( region, layers ) {

		var list = element( 'ul', 'oa-perf-layers' );

		clear( region );
		region.classList.remove( 'is-error' );

		layers.forEach( function ( layer ) {

			var item = element( 'li', 'is-' + ( layer.status || 'skipped' ) );

			item.appendChild( element( 'strong', '', layer.label ) );
			item.appendChild( document.createTextNode( ' ' + ( layer.message || '' ) ) );
			list.appendChild( item );

		} );

		region.appendChild( list );

	}

	/*
	SCAN REPORT
	-- Summarises what each module decided for the scanned page
	---------------------------------------------------------- */

	function showScan( region, data ) {

		var report = data.report || {};

		clear( region );
		region.classList.remove( 'is-error' );

		if ( data.bypass ) {

			region.appendChild( element( 'p', 'oa-perf-warning', i18n.scanBypass + ' ' + data.bypass ) );

			return;

		}

		var delay = report.delay || [];
		var media = report.media || [];
		var fonts = report.fonts || [];

		if ( ! delay.length && ! media.length && ! fonts.length ) {

			region.appendChild( element( 'p', '', i18n.scanEmpty ) );

			return;

		}

		if ( delay.length ) {

			var delayList = element( 'ul', 'oa-perf-scan-list' );

			region.appendChild( element( 'h4', '', i18n.scanScripts ) );

			delay.forEach( function ( item ) {

				var row = element( 'li', 'delayed' === item.action ? 'is-success' : 'is-skipped' );

				row.appendChild( element( 'strong', '', item.action ) );
				row.appendChild( element( 'code', '', item.script ) );
				row.appendChild( element( 'span', '', item.reason ) );
				delayList.appendChild( row );

			} );

			region.appendChild( delayList );

		}

		if ( media.length ) {

			var counts = {};

			media.forEach( function ( item ) {

				var key = item.tag + ': ' + ( 'lazy' === item.action ? 'lazy' : item.reason );

				counts[ key ] = ( counts[ key ] || 0 ) + 1;

			} );

			var mediaList = element( 'ul', 'oa-perf-scan-list' );

			region.appendChild( element( 'h4', '', i18n.scanMedia ) );

			Object.keys( counts ).forEach( function ( key ) {

				mediaList.appendChild( element( 'li', '', key + ' × ' + counts[ key ] ) );

			} );

			region.appendChild( mediaList );

		}

		var fontUrls = fonts.filter( function ( item ) {

			return item.url;

		} );

		var stylesheets = fonts.filter( function ( item ) {

			return item.stylesheet;

		} );

		if ( stylesheets.length ) {

			var sheetList = element( 'ul', 'oa-perf-scan-list' );

			region.appendChild( element( 'h4', '', i18n.scanFonts ) );

			stylesheets.forEach( function ( item ) {

				var row = element( 'li', item.local ? 'is-success' : 'is-queued' );

				row.appendChild( element( 'code', '', item.stylesheet ) );
				row.appendChild( element( 'span', '', item.local ? '→ ' + item.local : i18n.scanQueued ) );
				sheetList.appendChild( row );

			} );

			region.appendChild( sheetList );

		}

		if ( fontUrls.length ) {

			var fontList = document.querySelector( '[data-oa-perf-font-list]' );

			fontUrls.forEach( function ( item ) {

				if ( ! fontList || fontList.querySelector( '[data-oa-perf-add-font="' + CSS.escape( item.url ) + '"]' ) ) {

					return;

				}

				var row = element( 'li' );
				var button = element( 'button', 'button button-small', i18n.add );

				button.type = 'button';
				button.setAttribute( 'data-oa-perf-add-font', item.url );
				row.appendChild( element( 'code', '', item.url ) );
				row.appendChild( button );
				fontList.appendChild( row );

			} );

		}

	}

	/*
	ACTION BUTTONS
	-- data-oa-perf-action names the AJAX action; data-scope, data-input and
	-- data-confirm add a scope, a field value and a confirmation step
	---------------------------------------------------------- */

	function confirmFor( key ) {

		if ( ! key || 'function' !== typeof window.oaConfirm ) {

			return Promise.resolve( true );

		}

		return window.oaConfirm( {
			title: i18n[ key + 'Title' ],
			message: i18n[ key + 'Text' ],
			confirmText: i18n[ key + 'Action' ],
			destructive: 'safe' !== key
		} );

	}

	function runAction( button ) {

		var region = document.getElementById( button.getAttribute( 'data-result' ) );
		var input = button.getAttribute( 'data-input' ) ? document.getElementById( button.getAttribute( 'data-input' ) ) : null;
		var action = button.getAttribute( 'data-oa-perf-action' );
		var params = {};

		if ( button.getAttribute( 'data-scope' ) ) {

			params.scope = button.getAttribute( 'data-scope' );

		}

		if ( input ) {

			params.value = input.value;

		}

		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );

		if ( region ) {

			showMessage( region, i18n.working, false );

		}

		request( action, params ).then( function ( json ) {

			var data = json.data || {};

			if ( ! region ) {

				return;

			}

			if ( ! json.success ) {

				showMessage( region, data.message || i18n.failed, true );

				return;

			}

			if ( 'oa_perf_scan' === action ) {

				showScan( region, data );

			} else if ( data.layers ) {

				showLayers( region, data.layers );

			} else {

				showMessage( region, data.message, false );

			}

			if ( data.reload ) {

				window.setTimeout( function () {

					window.location.reload();

				}, 800 );

			}

		} ).catch( function () {

			if ( region ) {

				showMessage( region, i18n.failed, true );

			}

		} ).then( function () {

			button.disabled = false;
			button.removeAttribute( 'aria-busy' );

		} );

	}

	entry.addEventListener( 'click', function ( event ) {

		var button = event.target.closest( '[data-oa-perf-action]' );

		if ( ! button ) {

			return;

		}

		event.preventDefault();

		confirmFor( button.getAttribute( 'data-confirm' ) ).then( function ( confirmed ) {

			if ( confirmed ) {

				runAction( button );

			}

		} );

	} );

	/*
	FONT PRELOAD PICKER
	-- Adds a font URL to the preload list once, and warns past three
	---------------------------------------------------------- */

	var preloadField = document.getElementById( 'oa-performance-fonts-preload' );
	var fontWarning = document.querySelector( '[data-oa-perf-font-warning]' );

	function preloadLines() {

		return preloadField ? preloadField.value.split( /\r?\n/ ).map( function ( line ) {

			return line.trim();

		} ).filter( Boolean ) : [];

	}

	function syncFontWarning() {

		if ( fontWarning ) {

			fontWarning.classList.toggle( 'oa-hidden', preloadLines().length <= 3 );

		}

	}

	if ( preloadField ) {

		preloadField.addEventListener( 'input', syncFontWarning );

	}

	entry.addEventListener( 'click', function ( event ) {

		var button = event.target.closest( '[data-oa-perf-add-font]' );

		if ( ! button || ! preloadField ) {

			return;

		}

		var url = button.getAttribute( 'data-oa-perf-add-font' );
		var lines = preloadLines();

		if ( -1 === lines.indexOf( url ) ) {

			lines.push( url );
			preloadField.value = lines.join( '\n' );
			preloadField.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		}

		syncFontWarning();

		if ( 'function' === typeof window.oaNotify ) {

			window.oaNotify( lines.length > 3 ? i18n.fontTooMany : i18n.fontAdded, lines.length > 3 ? 'error' : 'success' );

		}

	} );

	/*
	DATABASE CLEANUP
	-- Counts load on view; a run works through each selected item in batches
	-- until nothing is left or a batch removes nothing
	---------------------------------------------------------- */

	var db = entry.querySelector( '[data-oa-perf-db]' );

	if ( ! db ) {

		return;

	}

	var dbResult = db.querySelector( '[data-oa-perf-db-result]' );
	var runButton = db.querySelector( '[data-oa-perf-db-run]' );

	function loadCounts() {

		return request( 'oa_perf_db_counts' ).then( function ( json ) {

			var counts = json.success && json.data ? json.data.counts : {};

			Object.keys( counts || {} ).forEach( function ( item ) {

				var cell = db.querySelector( '[data-oa-perf-db-item="' + item + '"] [data-oa-perf-db-count]' );

				if ( cell ) {

					cell.textContent = String( counts[ item ] );

				}

			} );

		} );

	}

	function cleanItem( item, label, offset, removed ) {

		return request( 'oa_perf_db_run', { item: item, offset: offset, confirmed: 1 } ).then( function ( json ) {

			if ( ! json.success ) {

				throw new Error( ( json.data && json.data.message ) || i18n.failed );

			}

			var batch = json.data;
			var total = removed + batch.done;

			showMessage( dbResult, i18n.dbProgress.replace( '%1$s', label ).replace( '%2$d', total ), false );

			if ( batch.done > 0 && batch.remaining > 0 ) {

				return cleanItem( item, label, batch.offset, total );

			}

			return total;

		} );

	}

	db.querySelector( '[data-oa-perf-db-counts]' ).addEventListener( 'click', loadCounts );

	runButton.addEventListener( 'click', function () {

		var selected = Array.prototype.filter.call( db.querySelectorAll( 'tbody input[type="checkbox"]' ), function ( box ) {

			return box.checked;

		} );

		if ( ! selected.length ) {

			showMessage( dbResult, i18n.dbNothing, true );

			return;

		}

		confirmFor( 'db' ).then( function ( confirmed ) {

			if ( ! confirmed ) {

				return;

			}

			var summary = [];

			runButton.disabled = true;

			var chain = Promise.resolve();

			selected.forEach( function ( box ) {

				chain = chain.then( function () {

					return cleanItem( box.value, box.getAttribute( 'data-label' ), 0, 0 ).then( function ( total ) {

						summary.push( box.getAttribute( 'data-label' ) + ': ' + total );

					} );

				} );

			} );

			chain.then( function () {

				showMessage( dbResult, i18n.dbDone + '\n' + summary.join( '\n' ), false );

			} ).catch( function ( error ) {

				showMessage( dbResult, error.message, true );

			} ).then( function () {

				runButton.disabled = false;

				selected.forEach( function ( box ) {

					box.checked = false;

				} );

				return loadCounts();

			} );

		} );

	} );

	if ( ! db.closest( '.oa-hidden' ) ) {

		loadCounts();

	}

} )();
