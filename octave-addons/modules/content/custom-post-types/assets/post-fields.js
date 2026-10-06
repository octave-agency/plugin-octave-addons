/*
POST FIELDS EDITOR
-- Provides media-library controls for Octave fields on post edit screens,
-- including the orderable gallery grid.
---------------------------------------------------------- */

(function ( $ ) {
    'use strict';

	/*
	WIRE MEDIA FIELD
	-- Connects one existing or dynamically inserted Media Library control.
	---------------------------------------------------------- */

	function wireMediaField( field ) {

		if ( 'true' === field.dataset.wired ) {

			return;

		}

		field.dataset.wired = 'true';

        var selectButton = field.querySelector( '.oa-post-field-media-select' );
        var removeButton = field.querySelector( '.oa-post-field-media-remove' );
        var input = field.querySelector( 'input[type="hidden"]' );
        var preview = field.querySelector( '.oa-post-field-media-preview' );
        var mediaType = field.dataset.mediaType;

        function renderPreview( attachment ) {

            var iconOrImage;
            var name = document.createElement( 'span' );

            preview.replaceChildren();

            if ( attachment && 'image' === mediaType ) {

                iconOrImage = document.createElement( 'img' );
                iconOrImage.src = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                iconOrImage.alt = '';

            } else {

                iconOrImage = document.createElement( 'span' );
                iconOrImage.className = 'dashicons ' + ( 'image' === mediaType ? 'dashicons-format-image' : 'dashicons-media-default' );
                iconOrImage.setAttribute( 'aria-hidden', 'true' );

            }

            name.className = 'oa-post-field-media-name';
            name.textContent = attachment ? attachment.filename : '';

            preview.append( iconOrImage, name );

        }

        selectButton.addEventListener( 'click', function () {

            var frame = wp.media( {
                title: 'image' === mediaType ? octavePostFields.chooseImage : octavePostFields.chooseFile,
                button: { text: octavePostFields.useMedia },
                library: 'image' === mediaType ? { type: 'image' } : {},
                multiple: false
            } );

            frame.on( 'select', function () {

                var attachment = frame.state().get( 'selection' ).first().toJSON();
                input.value = attachment.id;
                preview.classList.add( 'has-value' );
                renderPreview( attachment );
                selectButton.textContent = octavePostFields.replace;
                removeButton.classList.remove( 'hidden' );
                input.dispatchEvent( new Event( 'change', { bubbles: true } ) );

            } );

            frame.open();

        } );

        removeButton.addEventListener( 'click', function () {

            input.value = '';
            preview.classList.remove( 'has-value' );
            renderPreview();
            removeButton.classList.add( 'hidden' );
            input.dispatchEvent( new Event( 'change', { bubbles: true } ) );

        } );

	}

	/*
	WIRE GALLERY FIELD
	-- Connects one gallery control: bulk Media Library selection, per-image
	-- removal, and reordering by drag or by arrow key. The visible tile order is
	-- the source of truth and is written back to the hidden input after every
	-- change, so the stored array always matches what the editor sees.
	---------------------------------------------------------- */

	function wireGalleryField( field ) {

		if ( 'true' === field.dataset.wired ) {

			return;

		}

		field.dataset.wired = 'true';

		var input = field.querySelector( 'input[type="hidden"]' );
		var list = field.querySelector( '.oa-gallery-items' );
		var selectButton = field.querySelector( '.oa-gallery-select' );
		var clearButton = field.querySelector( '.oa-gallery-clear' );
		var dragged = null;

		function currentIds() {

			return Array.prototype.map.call( list.children, function ( item ) {

				return item.dataset.id;

			} );

		}

		function sync() {

			var ids = currentIds();

			input.value = ids.join( ',' );
			field.classList.toggle( 'has-items', 0 !== ids.length );

			Array.prototype.forEach.call( list.children, function ( item, index ) {

				item.querySelector( '.oa-gallery-item-position' ).textContent = index + 1;
				item.setAttribute( 'aria-label', octavePostFields.galleryItemLabel.replace( '%d', index + 1 ) );

			} );

			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		}

		function moveItem( item, offset ) {

			var items = Array.prototype.slice.call( list.children );
			var target = items.indexOf( item ) + offset;

			if ( 0 > target || target >= items.length ) {

				return;

			}

			if ( 0 < offset ) {

				list.insertBefore( item, items[ target ].nextSibling );

			} else {

				list.insertBefore( item, items[ target ] );

			}

			item.focus();
			sync();

		}

		function wireItem( item ) {

			item.querySelector( '.oa-gallery-remove' ).addEventListener( 'click', function () {

				item.remove();
				sync();

			} );

			item.addEventListener( 'keydown', function ( event ) {

				if ( 'ArrowLeft' === event.key ) {

					event.preventDefault();
					moveItem( item, -1 );

				}

				if ( 'ArrowRight' === event.key ) {

					event.preventDefault();
					moveItem( item, 1 );

				}

			} );

			item.addEventListener( 'dragstart', function ( event ) {

				dragged = item;
				item.classList.add( 'is-dragging' );
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData( 'text/plain', item.dataset.id );

			} );

			item.addEventListener( 'dragend', function () {

				item.classList.remove( 'is-dragging' );
				dragged = null;
				sync();

			} );

			item.addEventListener( 'dragover', function ( event ) {

				if ( ! dragged || dragged === item ) {

					return;

				}

				event.preventDefault();
				event.dataTransfer.dropEffect = 'move';

				var box = item.getBoundingClientRect();
				var isAfter = event.clientX > box.left + ( box.width / 2 );

				list.insertBefore( dragged, isAfter ? item.nextSibling : item );

			} );

			item.addEventListener( 'drop', function ( event ) {

				event.preventDefault();

			} );

		}

		function addItem( attachment ) {

			var item = document.createElement( 'li' );
			var position = document.createElement( 'span' );
			var thumbnail = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
			var remove = document.createElement( 'button' );
			var removeIcon = document.createElement( 'span' );
			var preview;

			item.className = 'oa-gallery-item';
			item.draggable = true;
			item.tabIndex = 0;
			item.dataset.id = String( attachment.id );
			position.className = 'oa-gallery-item-position';

			if ( thumbnail ) {

				preview = document.createElement( 'img' );
				preview.src = thumbnail;
				preview.alt = '';

			} else {

				preview = document.createElement( 'span' );
				preview.className = 'dashicons dashicons-format-image';
				preview.setAttribute( 'aria-hidden', 'true' );

			}

			remove.type = 'button';
			remove.className = 'oa-gallery-remove';
			remove.setAttribute( 'aria-label', octavePostFields.removeImage );
			removeIcon.className = 'dashicons dashicons-no-alt';
			removeIcon.setAttribute( 'aria-hidden', 'true' );
			remove.append( removeIcon );

			item.append( position, preview, remove );
			list.append( item );
			wireItem( item );

		}

		selectButton.addEventListener( 'click', function () {

			var frame = wp.media( {
				title: octavePostFields.chooseImages,
				button: { text: octavePostFields.useImages },
				library: { type: 'image' },
				multiple: 'add'
			} );

			frame.on( 'select', function () {

				var existing = currentIds();

				frame.state().get( 'selection' ).toJSON().forEach( function ( attachment ) {

					if ( -1 === existing.indexOf( String( attachment.id ) ) ) {

						existing.push( String( attachment.id ) );
						addItem( attachment );

					}

				} );

				sync();

			} );

			frame.open();

		} );

		clearButton.addEventListener( 'click', function () {

			list.replaceChildren();
			sync();

		} );

		Array.prototype.forEach.call( list.children, wireItem );
		sync();

	}

	/*
	INITIALIZE WYSIWYG
	-- Turns nested textareas into WordPress editors after unique IDs exist.
	---------------------------------------------------------- */

	function initializeWysiwyg( scope ) {

		scope.querySelectorAll( '.oa-nested-wysiwyg' ).forEach( function ( textarea ) {

			if ( 'true' === textarea.dataset.editorReady || ! window.wp || ! wp.editor ) {

				return;

			}

			textarea.dataset.editorReady = 'true';
			wp.editor.initialize( textarea.id, {
				tinymce: { wpautop: true },
				quicktags: true,
				mediaButtons: true
			} );

		} );

	}

	/*
	WIRE REPEATER
	-- Adds, removes, reorders, and reindexes rows without changing meta shape.
	---------------------------------------------------------- */

	document.querySelectorAll( '.oa-post-field-repeater' ).forEach( function ( repeater ) {

		var list = repeater.querySelector( '.oa-repeater-rows' );
		var template = repeater.querySelector( '.oa-repeater-template' );
		var addButton = repeater.querySelector( '.oa-repeater-add' );
		var nextIndex = list.children.length;

		function destroyEditors( row ) {

			row.querySelectorAll( '.oa-nested-wysiwyg[data-editor-ready="true"]' ).forEach( function ( textarea ) {

				if ( window.wp && wp.editor ) {

					wp.editor.remove( textarea.id );

				}

			} );

		}

		function reindexRows() {

			list.querySelectorAll( '.oa-repeater-row' ).forEach( function ( row, index ) {

				row.querySelectorAll( '[name]' ).forEach( function ( input ) {

					input.name = input.name.replace( /(octave_post_fields\[[^\]]+\])\[[^\]]+\]/, '$1[' + index + ']' );

				} );

				row.querySelector( '.oa-repeater-row-number' ).textContent = octavePostFields.itemLabel.replace( '%d', index + 1 );
				row.querySelector( '.oa-repeater-move-up' ).disabled = 0 === index;
				row.querySelector( '.oa-repeater-move-down' ).disabled = list.children.length - 1 === index;

			} );

		}

		function wireRow( row ) {

			var up = row.querySelector( '.oa-repeater-move-up' );
			var down = row.querySelector( '.oa-repeater-move-down' );
			var remove = row.querySelector( '.oa-repeater-remove' );

			row.querySelectorAll( '.oa-post-field-media' ).forEach( wireMediaField );
			row.querySelectorAll( '.oa-post-field-gallery' ).forEach( wireGalleryField );
			initializeWysiwyg( row );

			up.addEventListener( 'click', function () {

				if ( row.previousElementSibling ) {

					list.insertBefore( row, row.previousElementSibling );
					reindexRows();

				}

			} );

			down.addEventListener( 'click', function () {

				if ( row.nextElementSibling ) {

					list.insertBefore( row.nextElementSibling, row );
					reindexRows();

				}

			} );

			remove.addEventListener( 'click', function () {

				destroyEditors( row );
				row.remove();
				reindexRows();

			} );

		}

		addButton.addEventListener( 'click', function () {

			var holder = document.createElement( 'div' );
			var html = template.innerHTML.split( '__ROW__' ).join( 'new_' + nextIndex );

			nextIndex++;
			holder.innerHTML = html.trim();

			var row = holder.firstElementChild;

			row.querySelectorAll( '[id]' ).forEach( function ( element ) {

				element.id = element.id.replace( /new_\d+/, 'row_' + nextIndex );

			} );

			row.querySelectorAll( '[for]' ).forEach( function ( label ) {

				label.htmlFor = label.htmlFor.replace( /new_\d+/, 'row_' + nextIndex );

			} );

			list.appendChild( row );
			wireRow( row );
			reindexRows();

			var firstInput = row.querySelector( 'input:not([type="hidden"]), select, textarea' );

			if ( firstInput ) {

				firstInput.focus();

			}

		} );

		list.querySelectorAll( '.oa-repeater-row' ).forEach( wireRow );
		reindexRows();

	} );

	/*
	WIRE FIELD TABS
	-- Switches panels from the tab strip and keeps arrow key navigation working.
	-- Panels stay in the form while hidden, so a required control the browser
	-- refuses to submit reveals its own panel before the browser reports it.
	---------------------------------------------------------- */

	function wireFieldTabs( tabs ) {

		var buttons = Array.prototype.slice.call( tabs.querySelectorAll( '.oa-post-fields-tab' ) );
		var panels  = Array.prototype.slice.call( tabs.querySelectorAll( '[role="tabpanel"]' ) );

		if ( ! buttons.length ) {

			return;

		}

		function activate( index, moveFocus ) {

			buttons.forEach( function ( button, position ) {

				var isActive = position === index;

				button.classList.toggle( 'is-active', isActive );
				button.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				button.tabIndex = isActive ? 0 : -1;

			} );

			panels.forEach( function ( panel, position ) {

				panel.hidden = position !== index;

			} );

			if ( moveFocus && buttons[ index ] ) {

				buttons[ index ].focus();

			}

		}

		buttons.forEach( function ( button, index ) {

			button.addEventListener( 'click', function () {

				activate( index, false );

			} );

			button.addEventListener( 'keydown', function ( event ) {

				var last = buttons.length - 1;
				var next = null;

				if ( 'ArrowRight' === event.key || 'ArrowDown' === event.key ) {

					next = index === last ? 0 : index + 1;

				} else if ( 'ArrowLeft' === event.key || 'ArrowUp' === event.key ) {

					next = index === 0 ? last : index - 1;

				} else if ( 'Home' === event.key ) {

					next = 0;

				} else if ( 'End' === event.key ) {

					next = last;

				}

				if ( null === next ) {

					return;

				}

				event.preventDefault();
				activate( next, true );

			} );

		} );

		var form = tabs.closest( 'form' );

		if ( ! form ) {

			return;

		}

		form.addEventListener( 'invalid', function ( event ) {

			panels.forEach( function ( panel, position ) {

				if ( panel.contains( event.target ) ) {

					activate( position, false );

				}

			} );

		}, true );

	}

	/*
	ICON PICKER
	-- Breakdance icon fields, wired once through the document so pickers in
	-- repeater rows added later work too. The panel searches Breakdance's
	-- icon library a page at a time; choosing an icon stores its SVG, which
	-- the server has already cleaned, in the field's hidden input
	---------------------------------------------------------- */

	var iconSettings = ( window.octavePostFields && octavePostFields.icons ) || {};
	var iconStrings  = iconSettings.strings || {};
	var searchTimer  = null;

	function iconField( element ) {

		return element.closest( '[data-oa-icon-field]' );

	}

	function setIconStatus( field, text ) {

		field.querySelector( '.oa-icon-status' ).textContent = text || '';

	}

	function loadIcons( field, append ) {

		var grid    = field.querySelector( '.oa-icon-grid' );
		var more    = field.querySelector( '.oa-icon-more' );
		var offset  = append ? grid.children.length : 0;
		var request = ( Number( field.dataset.iconRequest || 0 ) + 1 );
		var body    = new FormData();

		field.dataset.iconRequest = String( request );

		body.append( 'action', iconSettings.action );
		body.append( 'nonce', iconSettings.nonce );
		body.append( 'search', field.querySelector( '.oa-icon-search input' ).value );
		body.append( 'set', field.querySelector( '.oa-icon-set select' ).value );
		body.append( 'offset', String( offset ) );

		if ( ! append ) {

			grid.replaceChildren();

		}

		more.hidden = true;
		setIconStatus( field, iconStrings.loading );

		fetch( iconSettings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {

			return response.json();

		} ).then( function ( result ) {

			// A newer search has started since this one; its answer wins.
			if ( String( request ) !== field.dataset.iconRequest ) {

				return;

			}

			if ( ! result || ! result.success ) {

				setIconStatus( field, result && result.data && result.data.message ? result.data.message : iconStrings.failed );

				return;

			}

			var current = field.querySelector( 'input[type="hidden"]' ).value;

			result.data.icons.forEach( function ( icon ) {

				var button  = document.createElement( 'button' );
				var graphic = document.createElement( 'span' );
				var label   = document.createElement( 'span' );

				button.type      = 'button';
				button.className = 'oa-icon-option' + ( icon.value === current ? ' is-selected' : '' );
				button.title     = icon.name;
				button.setAttribute( 'role', 'option' );
				button.setAttribute( 'aria-selected', icon.value === current ? 'true' : 'false' );
				button.oaIcon = icon;

				// Cleaned on the server from an allowlist of SVG tags and attributes.
				graphic.className = 'oa-icon-option-graphic';
				graphic.innerHTML = icon.value;
				label.textContent = icon.name;

				button.append( graphic, label );
				grid.appendChild( button );

			} );

			setIconStatus( field, grid.children.length ? '' : iconStrings.empty );
			more.hidden = ! result.data.more;

		} ).catch( function () {

			setIconStatus( field, iconStrings.failed );

		} );

	}

	function chooseIcon( field, icon ) {

		var input   = field.querySelector( 'input[type="hidden"]' );
		var preview = field.querySelector( '.oa-icon-preview' );

		input.value = icon ? icon.value : '';
		preview.classList.toggle( 'has-value', !! icon );
		preview.innerHTML = icon ? icon.value : '<span class="dashicons dashicons-star-empty"></span>';

		field.querySelector( '.oa-icon-selection strong' ).textContent = icon ? icon.name : iconStrings.none;
		field.querySelector( '.oa-icon-selection code' ).textContent = icon ? icon.set : iconStrings.library;
		field.querySelector( '.oa-icon-remove' ).classList.toggle( 'hidden', ! icon );

		field.querySelectorAll( '.oa-icon-option' ).forEach( function ( option ) {

			var selected = !! icon && option.oaIcon && option.oaIcon.value === icon.value;

			option.classList.toggle( 'is-selected', selected );
			option.setAttribute( 'aria-selected', selected ? 'true' : 'false' );

		} );

		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );

	}

	function toggleIcons( field, open ) {

		var toggle  = field.querySelector( '.oa-icon-toggle' );
		var options = field.querySelector( '.oa-icon-options' );

		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		options.hidden = ! open;

		if ( open && ! field.dataset.iconLoaded ) {

			field.dataset.iconLoaded = 'true';
			loadIcons( field, false );

		}

		if ( open ) {

			field.querySelector( '.oa-icon-search input' ).focus();

		}

	}

	document.addEventListener( 'click', function ( event ) {

		var target = event.target.closest( '.oa-icon-toggle, .oa-icon-remove, .oa-icon-more, .oa-icon-option' );
		var field  = target ? iconField( target ) : null;

		if ( ! field ) {

			return;

		}

		if ( target.classList.contains( 'oa-icon-toggle' ) ) {

			toggleIcons( field, 'true' !== target.getAttribute( 'aria-expanded' ) );

		} else if ( target.classList.contains( 'oa-icon-remove' ) ) {

			chooseIcon( field, null );

		} else if ( target.classList.contains( 'oa-icon-more' ) ) {

			loadIcons( field, true );

		} else {

			chooseIcon( field, target.oaIcon );
			toggleIcons( field, false );
			field.querySelector( '.oa-icon-toggle' ).focus();

		}

	} );

	document.addEventListener( 'input', function ( event ) {

		var field = event.target.matches( '.oa-icon-search input' ) ? iconField( event.target ) : null;

		if ( ! field ) {

			return;

		}

		window.clearTimeout( searchTimer );

		searchTimer = window.setTimeout( function () {

			loadIcons( field, false );

		}, 250 );

	} );

	document.addEventListener( 'change', function ( event ) {

		var field = event.target.matches( '.oa-icon-set select' ) ? iconField( event.target ) : null;

		if ( field ) {

			loadIcons( field, false );

		}

	} );

	// Enter in the search box would submit the post form.
	document.addEventListener( 'keydown', function ( event ) {

		if ( 'Enter' === event.key && event.target.matches( '.oa-icon-search input' ) ) {

			event.preventDefault();

		}

		if ( 'Escape' === event.key && iconField( event.target ) ) {

			var field = iconField( event.target );

			if ( ! field.querySelector( '.oa-icon-options' ).hidden ) {

				toggleIcons( field, false );
				field.querySelector( '.oa-icon-toggle' ).focus();

			}

		}

	} );

	document.querySelectorAll( '[data-oa-field-tabs]' ).forEach( wireFieldTabs );
	document.querySelectorAll( '.oa-post-field-media' ).forEach( wireMediaField );
	document.querySelectorAll( '.oa-post-field-gallery' ).forEach( wireGalleryField );
	initializeWysiwyg( document );

})( jQuery );
