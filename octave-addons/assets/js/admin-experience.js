/*
WORDPRESS ADMIN EXPERIENCE
-- Applies and persists the current user's light or dark admin appearance.
---------------------------------------------------------- */

(function () {

    'use strict';

    var config = window.oaAdminExperience || {};
    var root = document.documentElement;
    var mediaQuery = window.matchMedia( '(prefers-color-scheme: dark)' );
    var savedTheme = 'light' === config.theme || 'dark' === config.theme ? config.theme : 'system';
    var activeTheme = 'system' === savedTheme ? ( mediaQuery.matches ? 'dark' : 'light' ) : savedTheme;

    /*
    APPLY THEME
    -- Updates the document before the admin interface finishes rendering.
    ---------------------------------------------------------- */

    function applyTheme( theme ) {

        activeTheme = theme;
        root.dataset.oaAdminTheme = theme;
        root.style.colorScheme = theme;

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
    -- Stores an explicit preference without interrupting the current screen.
    ---------------------------------------------------------- */

    function saveTheme( theme ) {

        if ( ! config.ajaxUrl || ! config.nonce ) {

            return;

        }

        var request = new URLSearchParams();

        request.append( 'action', 'oa_save_admin_theme' );
        request.append( 'nonce', config.nonce );
        request.append( 'theme', theme );

        window.fetch( config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: request.toString()
        } ).catch( function () {

            // The selected appearance remains active for this page if persistence fails.

        } );

    }

    applyTheme( activeTheme );

    document.addEventListener( 'DOMContentLoaded', function () {

        updateToggle();

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
