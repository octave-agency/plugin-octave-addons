/*
NOTIFICATIONS BAR
-- Closes a banner, remembers the choice in a cookie for the configured number
-- of days, and takes the bar away once every banner has been dismissed.
-- Banners already carrying a cookie are removed before paint by the inline
-- script the module prints beside the markup, not here.
---------------------------------------------------------- */

(function () {

	'use strict';

	var bar = document.getElementById( 'oaNotificationsBar' );

	if ( ! bar ) {

		return;

	}

	var days = parseInt( bar.getAttribute( 'data-cookie-days' ), 10 );

	function remember( id ) {

		var cookie = 'oa_nb_' + id + '=1; path=/; SameSite=Lax';

		// Zero days is a session cookie, so the banner returns on the next visit.
		if ( days > 0 ) {

			cookie += '; max-age=' + ( days * 86400 );

		}

		if ( 'https:' === window.location.protocol ) {

			cookie += '; Secure';

		}

		document.cookie = cookie;

	}

	function dismiss( banner ) {

		remember( banner.getAttribute( 'data-oa-nb-id' ) );
		banner.classList.add( 'is-closing' );

		window.setTimeout( function () {

			banner.remove();

			if ( ! bar.querySelector( '[data-oa-nb-id]' ) ) {

				bar.remove();

			}

		}, 200 );

	}

	bar.addEventListener( 'click', function ( event ) {

		var close = event.target.closest( '.oa-nb__close' );

		if ( ! close ) {

			return;

		}

		dismiss( close.closest( '[data-oa-nb-id]' ) );

	} );

})();
