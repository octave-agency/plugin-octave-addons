/*
WORDPRESS ADMIN EXPERIENCE
-- Applies the light or dark admin appearance and stores it per device in a cookie.
---------------------------------------------------------- */

(function () {

    'use strict';

    var config = window.oaAdminExperience || {};
    var root = document.documentElement;
    var mediaQuery = window.matchMedia( '(prefers-color-scheme: dark)' );
    var savedTheme = 'light' === config.theme || 'dark' === config.theme ? config.theme : 'system';
    var activeTheme = 'system' === savedTheme ? ( mediaQuery.matches ? 'dark' : 'light' ) : savedTheme;
    var editorCanvasObserver;
    var editorCanvasFrame;
    var syncedEditorCanvas;

    /*
    SYNC WYSIWYG THEME
    -- TinyMCE keeps editable content in separate iframe documents. Injects the
    -- resolved palette into every classic and custom-field editor, including
    -- editors added later inside repeaters.
    ---------------------------------------------------------- */

    function syncWysiwygTheme() {

        var rootStyles = window.getComputedStyle( root );
        var background = rootStyles.getPropertyValue( '--oa-admin-surface' ).trim();
        var text = rootStyles.getPropertyValue( '--oa-admin-text' ).trim();
        var softText = rootStyles.getPropertyValue( '--oa-admin-text-soft' ).trim();
        var accent = rootStyles.getPropertyValue( '--oa-admin-accent-dark' ).trim();

        document.querySelectorAll( '.wp-editor-wrap iframe' ).forEach( function ( iframe ) {

            if ( ! iframe.contentDocument || ! iframe.contentDocument.head ) {

                return;

            }

            var editorRoot = iframe.contentDocument.documentElement;
            var style = iframe.contentDocument.getElementById( 'oa-wysiwyg-theme' );

            editorRoot.dataset.oaAdminTheme = activeTheme;
            editorRoot.style.colorScheme = activeTheme;

            if ( ! style ) {

                style = iframe.contentDocument.createElement( 'style' );
                style.id = 'oa-wysiwyg-theme';
                iframe.contentDocument.head.appendChild( style );

            }

            style.textContent = 'html, body#tinymce { color: ' + text + ' !important; background: ' + background + ' !important; }'
                + ' body#tinymce { caret-color: ' + text + '; }'
                + ' body#tinymce p, body#tinymce li { color: ' + text + '; }'
                + ' body#tinymce blockquote { color: ' + softText + '; }'
                + ' body#tinymce a { color: ' + accent + '; }';

            if ( ! iframe.dataset.oaThemeSync ) {

                iframe.dataset.oaThemeSync = '1';
                iframe.addEventListener( 'load', syncWysiwygTheme );

            }

        } );

    }

    /*
    SYNC EDITOR CANVAS THEME
    -- Gutenberg renders post content in a separate document. Copies the active
    -- theme and its resolved tokens into that document so editor and meta-box
    -- styling always change together.
    ---------------------------------------------------------- */

    function syncEditorCanvasTheme() {

        var canvas = document.querySelector( 'iframe[name="editor-canvas"]' );

        if ( ! canvas || ! canvas.contentDocument ) {

            return false;

        }

        var canvasRoot = canvas.contentDocument.documentElement;

        if ( canvas === syncedEditorCanvas && activeTheme === canvasRoot.dataset.oaAdminTheme ) {

            return true;

        }

        var rootStyles = window.getComputedStyle( root );
        var tokenNames = [
            '--oa-admin-accent',
            '--oa-admin-accent-dark',
            '--oa-admin-accent-soft',
            '--oa-admin-canvas',
            '--oa-admin-surface',
            '--oa-admin-surface-soft',
            '--oa-admin-surface-subtle',
            '--oa-admin-surface-hover',
            '--oa-admin-surface-selected',
            '--oa-admin-text',
            '--oa-admin-text-soft',
            '--oa-admin-text-dim',
            '--oa-admin-border',
            '--oa-admin-border-strong',
            '--oa-admin-line-soft',
            '--oa-admin-on-accent',
            '--oa-admin-focus-ring',
            '--oa-admin-danger',
            '--oa-admin-warning',
            '--oa-admin-success',
            '--oa-admin-shadow',
            '--oa-admin-shadow-raised'
        ];

        canvasRoot.dataset.oaAdminTheme = activeTheme;
        canvasRoot.style.colorScheme = activeTheme;
        syncedEditorCanvas = canvas;

        tokenNames.forEach( function ( tokenName ) {

            canvasRoot.style.setProperty( tokenName, rootStyles.getPropertyValue( tokenName ) );

        } );

        if ( ! canvas.dataset.oaThemeSync ) {

            canvas.dataset.oaThemeSync = '1';
            canvas.addEventListener( 'load', syncEditorCanvasTheme );

        }

        return true;

    }

    /*
    WATCH EDITOR CANVAS
    -- Gutenberg can create or replace its iframe after the admin DOM is ready.
    ---------------------------------------------------------- */

    function watchEditorCanvas() {

        syncEditorCanvasTheme();
        syncWysiwygTheme();

        if ( editorCanvasObserver ) {

            return;

        }

        // The Customizer rewrites large parts of its DOM on every setting change,
        // so the sync is collapsed into one pass per frame rather than one per
        // mutation batch.
        editorCanvasObserver = new MutationObserver( function () {

            if ( editorCanvasFrame ) {

                return;

            }

            editorCanvasFrame = window.requestAnimationFrame( function () {

                editorCanvasFrame = null;

                syncEditorCanvasTheme();
                syncWysiwygTheme();

            } );

        } );
        editorCanvasObserver.observe( document.body, {
            childList: true,
            subtree: true
        } );

    }

    /*
    APPLY THEME
    -- Updates the document before the admin interface finishes rendering.
    ---------------------------------------------------------- */

    function applyTheme( theme ) {

        activeTheme = theme;
        root.dataset.oaAdminTheme = theme;
        root.style.colorScheme = theme;

        syncEditorCanvasTheme();
        syncWysiwygTheme();
        updateToggle();

    }

    /*
    UPDATE TOGGLE
    -- Keeps the icon-only admin-bar control accessible and aligned with the next action.
    ---------------------------------------------------------- */

    function updateToggle() {

        var toggle = document.querySelector( '#wp-admin-bar-oa-theme-toggle > .ab-item' );

        if ( ! toggle ) {

            return;

        }

        var nextIsDark = 'light' === activeTheme;
        var nextLabel = nextIsDark ? config.darkModeText : config.lightModeText;

        toggle.setAttribute( 'aria-label', nextLabel );
        toggle.setAttribute( 'title', nextLabel );
        toggle.setAttribute( 'aria-pressed', 'dark' === activeTheme ? 'true' : 'false' );
        toggle.setAttribute( 'role', 'button' );

    }

    /*
    PREPARE MEDIA SEARCH
    -- Removes the visible label and keeps the Media Library search accessible.
    ---------------------------------------------------------- */

    function prepareMediaSearch() {

        var mediaSearch = document.querySelector( '#media-search-input' );

        if ( ! mediaSearch ) {

            return false;

        }

        var searchLabel = document.querySelector( '.media-search-input-label' );
        var searchText = config.mediaSearchText || 'Search media…';

        if ( searchLabel ) {

            searchLabel.remove();

        }

        mediaSearch.setAttribute( 'placeholder', searchText );
        mediaSearch.setAttribute( 'aria-label', searchText );

        return true;

    }

    /*
    SAVE THEME
    -- Writes the choice to a browser cookie so shared accounts stay device specific.
    ---------------------------------------------------------- */

    function saveTheme( theme ) {

        var name = config.cookieName || 'oa_admin_theme';
        var days = parseInt( config.cookieDays, 10 ) || 365;
        var cookie = encodeURIComponent( name ) + '=' + encodeURIComponent( theme );

        cookie += '; max-age=' + ( days * 86400 );
        cookie += '; path=' + ( config.cookiePath || '/' );

        if ( config.cookieDomain ) {

            cookie += '; domain=' + config.cookieDomain;

        }

        cookie += '; samesite=lax';

        if ( config.cookieSecure ) {

            cookie += '; secure';

        }

        document.cookie = cookie;

    }

    applyTheme( activeTheme );

    document.addEventListener( 'DOMContentLoaded', function () {

        updateToggle();
        watchEditorCanvas();

        if ( document.body.classList.contains( 'upload-php' ) && ! prepareMediaSearch() ) {

            var mediaSearchObserver = new MutationObserver( function () {

                if ( prepareMediaSearch() ) {

                    mediaSearchObserver.disconnect();

                }

            } );

            mediaSearchObserver.observe( document.body, {
                childList: true,
                subtree: true
            } );

        }

        var toggle = document.querySelector( '#wp-admin-bar-oa-theme-toggle > .ab-item' );

        if ( ! toggle ) {

            return;

        }

        function toggleTheme( event ) {

            event.preventDefault();

            savedTheme = 'dark' === activeTheme ? 'light' : 'dark';

            applyTheme( savedTheme );
            saveTheme( savedTheme );

        }

        toggle.addEventListener( 'click', toggleTheme );

        toggle.addEventListener( 'keydown', function ( event ) {

            if ( ' ' !== event.key ) {

                return;

            }

            toggleTheme( event );

        } );

    } );

    function followSystemTheme( event ) {

        if ( 'system' !== savedTheme ) {

            return;

        }

        applyTheme( event.matches ? 'dark' : 'light' );

    }

    if ( mediaQuery.addEventListener ) {

        mediaQuery.addEventListener( 'change', followSystemTheme );

    } else {

        mediaQuery.addListener( followSystemTheme );

    }

}());
