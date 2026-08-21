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
