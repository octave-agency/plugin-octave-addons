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

	document.querySelectorAll( '.oa-post-field-media' ).forEach( wireMediaField );
	document.querySelectorAll( '.oa-post-field-gallery' ).forEach( wireGalleryField );
	initializeWysiwyg( document );

})( jQuery );
