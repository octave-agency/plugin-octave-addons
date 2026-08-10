/*
POST FIELDS EDITOR
-- Provides media-library controls for Octave fields on post edit screens.
---------------------------------------------------------- */

(function ( $ ) {
    'use strict';

    document.querySelectorAll( '.oa-post-field-media' ).forEach( function ( field ) {

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

    } );

})( jQuery );
