/*
BREAKDANCE FORMS
-- Initialises the forced captcha provider and prepares its token before the
-- native Breakdance submit handler sends a form
---------------------------------------------------------- */

(function () {

	'use strict';

	var config = window.OctaveBreakdanceForms || {};
	var selector = '.breakdance-form';

	/*
	SHOW ERROR
	-- Keeps provider failures beside the captcha rather than replacing Breakdance messages
	---------------------------------------------------------- */

	function showError( form, message ) {

		var error = form.querySelector( '.oa-breakdance-form-captcha__error' );

		if ( ! error ) {

			return;

		}

		error.textContent = message || config.errorMessage;
		error.hidden = false;

	}

	/*
	SET SUBMIT STATE
	-- Turnstile forms stay unavailable until the widget holds a valid response
	---------------------------------------------------------- */

	function setSubmitState( form, enabled ) {

		form.querySelectorAll( '.breakdance-form-button__submit' ).forEach( function ( button ) {

			button.disabled = ! enabled;

		} );

	}

	/*
	INIT TURNSTILE
	-- Explicit rendering supports several forms and forms added after page load
	---------------------------------------------------------- */

	function initTurnstile( form ) {

		var widget = form.querySelector( '[data-oa-captcha="turnstile"] .oa-breakdance-form-captcha__widget' );

		if ( ! widget || widget.dataset.oaReady || ! window.turnstile ) {

			return;

		}

		widget.dataset.oaReady = 'true';
		setSubmitState( form, false );

		var widgetId = window.turnstile.render( widget, {
			sitekey: config.siteKey,
			callback: function () {

				setSubmitState( form, true );

			},
			'expired-callback': function () {

				setSubmitState( form, false );

			},
			'error-callback': function () {

				setSubmitState( form, false );
				showError( form, config.errorMessage );

			},
		} );

		form.addEventListener( 'reset', function () {

			window.setTimeout( function () {

				window.turnstile.reset( widgetId );
				setSubmitState( form, false );

			}, 0 );

		} );

	}

	/*
	SET GOOGLE TOKEN
	-- Stores the short-lived v3 response where Breakdance's FormData will include it
	---------------------------------------------------------- */

	function setGoogleToken( form, token ) {

		var input = form.querySelector( 'input[name="oa_captcha_token"]' );

		if ( ! input ) {

			input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = 'oa_captcha_token';
			form.appendChild( input );

		}

		input.value = token;

	}

	/*
	INIT GOOGLE
	-- Captures submit once, gets a fresh token, then hands the same submit back
	-- to Breakdance without bypassing native browser field validation
	---------------------------------------------------------- */

	function initGoogle( form ) {

		if ( form.dataset.oaGoogleReady ) {

			return;

		}

		form.dataset.oaGoogleReady = 'true';

		form.addEventListener( 'submit', function ( event ) {

			if ( 'true' === form.dataset.oaGooglePending ) {

				event.preventDefault();
				event.stopImmediatePropagation();

				return;

			}

			if ( 'true' === form.dataset.oaGoogleBypass ) {

				delete form.dataset.oaGoogleBypass;

				return;

			}

			event.preventDefault();
			event.stopImmediatePropagation();
			form.dataset.oaGooglePending = 'true';
			setSubmitState( form, false );

			var submitter = event.submitter;

			window.grecaptcha.ready( function () {

				window.grecaptcha.execute( config.siteKey, { action: config.googleAction } ).then( function ( token ) {

					setGoogleToken( form, token );
					delete form.dataset.oaGooglePending;
					form.dataset.oaGoogleBypass = 'true';
					setSubmitState( form, true );

					if ( form.requestSubmit ) {

						form.requestSubmit( submitter || undefined );

					} else {

						form.dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );

					}

				} ).catch( function () {

					delete form.dataset.oaGooglePending;
					setSubmitState( form, true );
					showError( form, config.errorMessage );

				} );

			} );

		}, true );

	}

	/*
	INIT FORM
	-- Applies only to forms carrying the server-rendered Octave captcha marker
	---------------------------------------------------------- */

	function initForm( form ) {

		if ( 'turnstile' === config.provider ) {

			initTurnstile( form );

		} else if ( 'google' === config.provider && form.querySelector( '[data-oa-captcha="google"]' ) && window.grecaptcha ) {

			initGoogle( form );

		}

	}

	/*
	INIT ALL
	-- Re-runs safely for popup and dynamically rendered Breakdance forms
	---------------------------------------------------------- */

	function initAll() {

		document.querySelectorAll( selector ).forEach( initForm );

	}

	document.addEventListener( 'DOMContentLoaded', initAll );
	window.addEventListener( 'load', initAll );

	new MutationObserver( initAll ).observe( document.documentElement, {
		childList: true,
		subtree: true,
	} );

}());
