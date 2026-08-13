/*
POST FIELDS EDITOR
-- Provides media-library controls for Octave fields on post edit screens.
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
	initializeWysiwyg( document );

})( jQuery );
