/*
PERFORMANCE ADMIN
-- Async actions on the Performance page: cache purges, Cloudflare, font
-- refresh, diagnostics and database cleanup. Every result is written
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

	/*
	NOTICE
	-- Every response is shown as the plugin's standard inline notice, so a
	-- purge, a test or a scan reads the same way as any other message
	---------------------------------------------------------- */

	function notice( region, type ) {

		var box = element( 'div', 'notice notice-' + type + ' inline oa-inline-notice' );

		clear( region );
		region.appendChild( box );

		return box;

	}

	function showMessage( region, message, type ) {

		var box = notice( region, type || 'success' );

		String( message || '' ).split( '\n' ).forEach( function ( line ) {

			if ( line ) {

				box.appendChild( element( 'p', '', line ) );

			}

		} );

	}

	function showLayers( region, layers ) {

		var failed = layers.some( function ( layer ) {

			return 'error' === layer.status;

		} );

		var box = notice( region, failed ? 'warning' : 'success' );

		layers.forEach( function ( layer ) {

			var line = element( 'p' );

			line.appendChild( element( 'strong', '', layer.label + ':' ) );
			line.appendChild( document.createTextNode( ' ' + ( layer.message || '' ) ) );
			box.appendChild( line );

		} );

	}

	/*
	SCAN REPORT
	-- Summarises what each module decided for the scanned page
	---------------------------------------------------------- */

	function scanList( box, heading, rows ) {

		var title = element( 'p' );
		var list = element( 'ul' );

		title.appendChild( element( 'strong', '', heading ) );
		box.appendChild( title );

		rows.forEach( function ( text ) {

			list.appendChild( element( 'li', '', text ) );

		} );

		box.appendChild( list );

	}

	function showScan( region, data ) {

		var report = data.report || {};

		if ( data.bypass ) {

			showMessage( region, i18n.scanBypass + ' ' + data.bypass, 'warning' );

			return;

		}

		var delay = report.delay || [];
		var media = report.media || [];
		var fonts = report.fonts || [];
		var details = data.details || [];

		if ( ! delay.length && ! media.length && ! fonts.length && ! details.length ) {

			showMessage( region, i18n.scanEmpty, 'info' );

			return;

		}

		var box = notice( region, 'success' );

		if ( ! delay.length && ! media.length && ! fonts.length ) {

			box.appendChild( element( 'p', '', i18n.scanEmpty ) );

		}

		if ( delay.length ) {

			scanList( box, i18n.scanScripts, delay.map( function ( item ) {

				return item.action + ': ' + item.script + ( item.reason ? ' (' + item.reason + ')' : '' );

			} ) );

		}

		if ( media.length ) {

			var counts = {};

			media.forEach( function ( item ) {

				var key = item.tag + ': ' + ( 'lazy' === item.action ? 'lazy' : item.reason );

				counts[ key ] = ( counts[ key ] || 0 ) + 1;

			} );

			scanList( box, i18n.scanMedia, Object.keys( counts ).map( function ( key ) {

				return key + ' × ' + counts[ key ];

			} ) );

		}

		var stylesheets = fonts.filter( function ( item ) {

			return item.stylesheet;

		} );

		if ( stylesheets.length ) {

			scanList( box, i18n.scanFonts, stylesheets.map( function ( item ) {

				return item.stylesheet + ' → ' + ( item.local || i18n.scanQueued );

			} ) );

		}

		// Delivery, timings, assets, LCP and ownership, already worded by the server.
		details.forEach( function ( section ) {

			scanList( box, section.heading, section.rows || [] );

		} );

	}

	/*
	ACTION BUTTONS
	-- data-oa-perf-action names the AJAX action; data-scope, data-input and
	-- data-confirm add a scope, a field value and a confirmation step.
	-- data-fields ("param:field-id ...") sends current, unsaved field values
	-- and reruns the action whenever one of those fields changes
	---------------------------------------------------------- */

	function fieldsFor( button ) {

		return ( button.getAttribute( 'data-fields' ) || '' ).split( ' ' ).filter( Boolean ).map( function ( pair ) {

			var parts = pair.split( ':' );

			return { param: parts[0], input: document.getElementById( parts[1] ) };

		} ).filter( function ( field ) {

			return field.input;

		} );

	}

	function confirmFor( key ) {

		if ( ! key || 'function' !== typeof window.oaConfirm ) {

			return Promise.resolve( true );

		}

		return window.oaConfirm( {
			title: i18n[ key + 'Title' ],
			message: i18n[ key + 'Text' ],
			confirmText: i18n[ key + 'Action' ],
			destructive: true
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

		fieldsFor( button ).forEach( function ( field ) {

			params[ field.param ] = field.input.value;

		} );

		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );

		if ( region ) {

			showMessage( region, i18n.working, 'info' );

		}

		request( action, params ).then( function ( json ) {

			var data = json.data || {};

			if ( ! region ) {

				return;

			}

			if ( ! json.success ) {

				showMessage( region, data.message || i18n.failed, 'error' );

				return;

			}

			if ( 'oa_perf_scan' === action ) {

				showScan( region, data );

			} else if ( data.layers ) {

				showLayers( region, data.layers );

			} else {

				showMessage( region, data.message, 'success' );

			}

		} ).catch( function () {

			if ( region ) {

				showMessage( region, i18n.failed, 'error' );

			}

		} ).then( function () {

			button.disabled = false;
			button.removeAttribute( 'aria-busy' );

		} );

	}

	entry.addEventListener( 'change', function ( event ) {

		entry.querySelectorAll( '[data-oa-perf-action][data-fields]' ).forEach( function ( button ) {

			var watched = fieldsFor( button ).some( function ( field ) {

				return field.input === event.target;

			} );

			if ( watched && ! button.disabled ) {

				runAction( button );

			}

		} );

	} );

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
	SELECT ALL
	-- A master checkbox ([data-oa-perf-select-all]) ticks every checkbox in its
	-- [data-oa-perf-select-group]. Ticked boxes fire their own change event, so
	-- saved fields still mark the form as changed. The master shows as partly
	-- ticked while only some of its boxes are
	---------------------------------------------------------- */

	function groupBoxes( master ) {

		var group = master.closest( '[data-oa-perf-select-group]' );

		return group ? Array.prototype.filter.call( group.querySelectorAll( 'input[type="checkbox"]' ), function ( box ) {

			return box !== master && ! box.disabled;

		} ) : [];

	}

	function syncMaster( master ) {

		var boxes = groupBoxes( master );
		var ticked = boxes.filter( function ( box ) {

			return box.checked;

		} ).length;

		master.checked = boxes.length > 0 && ticked === boxes.length;
		master.indeterminate = ticked > 0 && ticked < boxes.length;

	}

	function syncMasters() {

		entry.querySelectorAll( '[data-oa-perf-select-all]' ).forEach( syncMaster );

	}

	entry.addEventListener( 'change', function ( event ) {

		var target = event.target;

		if ( ! target || 'checkbox' !== target.type ) {

			return;

		}

		if ( target.hasAttribute( 'data-oa-perf-select-all' ) ) {

			groupBoxes( target ).forEach( function ( box ) {

				if ( box.checked !== target.checked ) {

					box.checked = target.checked;
					box.dispatchEvent( new Event( 'change', { bubbles: true } ) );

				}

			} );

		}

		syncMasters();

	} );

	syncMasters();

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

			showMessage( dbResult, i18n.dbProgress.replace( '%1$s', label ).replace( '%2$d', total ), 'info' );

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

			showMessage( dbResult, i18n.dbNothing, 'error' );

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

				showMessage( dbResult, i18n.dbDone + '\n' + summary.join( '\n' ), 'success' );

			} ).catch( function ( error ) {

				showMessage( dbResult, error.message, 'error' );

			} ).then( function () {

				runButton.disabled = false;

				selected.forEach( function ( box ) {

					box.checked = false;

				} );

				syncMasters();

				return loadCounts();

			} );

		} );

	} );

	if ( ! db.closest( '.oa-hidden' ) ) {

		loadCounts();

	}

} )();
