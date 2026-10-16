/*
CUSTOM LOGIN CAPTCHA
-- Runs Google reCAPTCHA or Cloudflare Turnstile on the WordPress login form
---------------------------------------------------------- */

( function () {

	'use strict';

	var config = window.OctaveCustomLoginCaptcha || {};
	var form = document.getElementById( 'loginform' );
	var captcha = document.querySelector( '[data-oa-login-captcha]' );

	if ( ! form || ! captcha || ! config.provider || ! config.siteKey ) {

		return;

	}

	/*
	SHOW ERROR
	-- Presents provider failures without replacing WordPress login notices
	---------------------------------------------------------- */

	function showError() {

		var error = captcha.querySelector( '.oa-login-captcha__error' );

		if ( ! error ) {

			error = document.createElement( 'p' );
			error.className = 'oa-login-captcha__error';
			error.setAttribute( 'role', 'alert' );
			captcha.appendChild( error );

		}

		error.textContent = config.errorMessage;

	}

	/*
	GOOGLE CAPTCHA
	-- Fetches a fresh score token immediately before each form submission
	---------------------------------------------------------- */

	function setupGoogle() {

		var token = form.querySelector( '[name="oa_login_captcha_token"]' );
		var pending = false;

		if ( ! token || ! window.grecaptcha ) {

			showError();
			return;

		}

		form.addEventListener( 'submit', function ( event ) {

			if ( 'true' === form.dataset.oaCaptchaVerified ) {

				delete form.dataset.oaCaptchaVerified;
				return;

			}

			event.preventDefault();

			if ( pending ) {

				return;

			}

			pending = true;

			window.grecaptcha.ready( function () {

				window.grecaptcha.execute( config.siteKey, { action: config.googleAction } ).then( function ( value ) {

					token.value = value;
					form.dataset.oaCaptchaVerified = 'true';
					pending = false;
					form.requestSubmit();

				} ).catch( function () {

					pending = false;
					showError();

				} );

			} );

		} );

	}

	/*
	TURNSTILE CAPTCHA
	-- Renders Cloudflare's managed widget into the reserved form region
	---------------------------------------------------------- */

	function setupTurnstile() {

		var widget = captcha.querySelector( '.oa-login-captcha__widget' );
		var widgetId;

		if ( ! widget || ! window.turnstile ) {

			showError();
			return;

		}

		widgetId = window.turnstile.render( widget, {
			sitekey: config.siteKey,
			'error-callback': showError,
			'expired-callback': function () {

				window.turnstile.reset( widgetId );

			},
		} );

	}

	if ( 'google' === config.provider ) {

		setupGoogle();

	} else if ( 'turnstile' === config.provider ) {

		setupTurnstile();

	}

}() );
