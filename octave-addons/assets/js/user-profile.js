/*
USER PROFILE MEDIA
-- Selects and previews attachment-backed avatars from the Media Library.
---------------------------------------------------------- */

(function () {

    'use strict';

    document.querySelectorAll( '.oa-avatar-field' ).forEach( function ( field ) {

        var input = field.querySelector( '.oa-avatar-id' );
        var preview = field.querySelector( '.oa-avatar-preview' );
        var selectButton = field.querySelector( '.oa-avatar-select' );
        var removeButton = field.querySelector( '.oa-avatar-remove' );
        var frame = null;

        if ( ! input || ! preview || ! selectButton || ! removeButton || ! window.wp || ! wp.media ) {

            return;

        }

        /*
        RENDER PREVIEW
        -- Replaces the image without injecting Media Library markup.
        ---------------------------------------------------------- */

        function renderPreview( url ) {

            var image = preview.querySelector( 'img' );

            if ( ! image ) {

                image = document.createElement( 'img' );
                image.alt = '';
                preview.appendChild( image );

            }

            image.src = url;

        }

        selectButton.addEventListener( 'click', function () {

            if ( frame ) {

                frame.open();
                return;

            }

            frame = wp.media( {
                title: field.dataset.dialogTitle,
                button: {
                    text: field.dataset.dialogButton
                },
                library: {
                    type: 'image'
                },
                multiple: false
            } );

            frame.on( 'select', function () {

                var attachment = frame.state().get( 'selection' ).first().toJSON();
                var previewUrl = attachment.sizes && attachment.sizes.thumbnail
                    ? attachment.sizes.thumbnail.url
                    : attachment.url;

                input.value = attachment.id;
                selectButton.textContent = field.dataset.replaceLabel;
                removeButton.classList.remove( 'hidden' );

                renderPreview( previewUrl );

            } );

            frame.open();

        } );

        removeButton.addEventListener( 'click', function () {

            input.value = '0';
            selectButton.textContent = field.dataset.selectLabel;
            removeButton.classList.add( 'hidden' );

            renderPreview( field.dataset.defaultAvatar );

        } );

    } );

}());
