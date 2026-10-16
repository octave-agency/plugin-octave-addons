<?php

/*
CUSTOM LOGIN TESTS
-- Covers captcha settings, shared credentials, rendering and verification
---------------------------------------------------------- */

function oa_custom_login_settings( array $overrides = [] ): array {

	return array_merge(
		oa_module( 'custom-login' )->get_defaults(),
		$overrides
	);

}

function test_custom_login_captcha_defaults_to_off(): void {

	$defaults = oa_module( 'custom-login' )->get_defaults();

	oa_assert_same( '', $defaults['captcha_mode'] );
	oa_assert_same( '', $defaults['captcha_site_key'] );
	oa_assert_same( '', $defaults['captcha_secret_key'] );

}

function test_custom_login_sanitizes_captcha_mode_and_keys(): void {

	$module = oa_module( 'custom-login' );
	$clean  = $module->sanitize(
		[
			'captcha_mode'       => 'CUSTOM-TURNSTILE',
			'captcha_site_key'   => ' site-key ',
			'captcha_secret_key' => ' secret-key ',
		]
	);

	oa_assert_same( 'custom-turnstile', $clean['captcha_mode'] );
	oa_assert_same( 'site-key', $clean['captcha_site_key'] );
	oa_assert_same( 'secret-key', $clean['captcha_secret_key'] );
	oa_assert_same( '', $module->sanitize( [ 'captcha_mode' => 'both' ] )['captcha_mode'] );

}

function test_custom_login_registers_captcha_hooks(): void {

	oa_module( 'custom-login' )->run(
		oa_custom_login_settings(
			[
				'enabled'      => true,
				'captcha_mode' => 'custom-google',
			]
		)
	);

	oa_assert( has_filter( 'login_form' ) );
	oa_assert( has_filter( 'authenticate' ) );
	oa_assert( has_filter( 'login_enqueue_scripts' ) );

}

function test_custom_login_uses_forms_turnstile_keys(): void {

	oa_set_settings(
		'breakdance-forms',
		[
			'turnstile_site_key'   => 'forms-site',
			'turnstile_secret_key' => 'forms-secret',
		]
	);

	oa_module( 'custom-login' )->run( oa_custom_login_settings( [ 'captcha_mode' => 'forms-turnstile' ] ) );

	ob_start();
	Octave_Addons_Module_Custom_Login::render_captcha();
	$html = ob_get_clean();

	oa_assert_contains( 'data-oa-login-captcha="turnstile"', $html );

	$GLOBALS['oa_http'] = static function () {

		return oa_http_response( 200, '{"success":true}' );

	};

	oa_assert( Octave_Addons_Module_Custom_Login::verify_captcha_response( 'turnstile', 'token' ) );
	oa_assert_same( 'forms-secret', $GLOBALS['oa_http_log'][0]['args']['body']['secret'] );

}

function test_custom_login_verifies_google_action_and_score(): void {

	oa_module( 'custom-login' )->run(
		oa_custom_login_settings(
			[
				'captcha_mode'       => 'custom-google',
				'captcha_site_key'   => 'custom-site',
				'captcha_secret_key' => 'custom-secret',
			]
		)
	);

	$GLOBALS['oa_http'] = static function () {

		return oa_http_response( 200, '{"success":true,"score":0.8,"action":"octave_login"}' );

	};

	oa_assert( Octave_Addons_Module_Custom_Login::verify_captcha_response( 'google', 'token', '127.0.0.1' ) );
	oa_assert_same( 'custom-secret', $GLOBALS['oa_http_log'][0]['args']['body']['secret'] );

	$GLOBALS['oa_http'] = static function () {

		return oa_http_response( 200, '{"success":true,"score":0.3,"action":"octave_login"}' );

	};

	oa_assert( ! Octave_Addons_Module_Custom_Login::verify_captcha_response( 'google', 'token' ) );

}

function test_custom_login_rejects_missing_token_on_password_login(): void {

	oa_module( 'custom-login' )->run(
		oa_custom_login_settings(
			[
				'captcha_mode'       => 'custom-turnstile',
				'captcha_site_key'   => 'site',
				'captcha_secret_key' => 'secret',
			]
		)
	);

	$_POST['log'] = 'editor';
	$_POST['pwd'] = 'password';

	$result = Octave_Addons_Module_Custom_Login::validate_login_captcha( null, 'editor', 'password' );

	oa_assert( is_wp_error( $result ) );
	oa_assert_contains( 'security check', $result->get_error_message() );

}

function test_custom_login_enqueues_only_public_captcha_configuration(): void {

	oa_module( 'custom-login' )->run(
		oa_custom_login_settings(
			[
				'captcha_mode'       => 'custom-google',
				'captcha_site_key'   => 'public-site',
				'captcha_secret_key' => 'private-secret',
			]
		)
	);

	Octave_Addons_Module_Custom_Login::enqueue_captcha_assets();

	oa_assert_same( 'google', $GLOBALS['oa_localized']['OctaveCustomLoginCaptcha']['provider'] );
	oa_assert_same( 'public-site', $GLOBALS['oa_localized']['OctaveCustomLoginCaptcha']['siteKey'] );
	oa_assert( ! in_array( 'private-secret', $GLOBALS['oa_localized']['OctaveCustomLoginCaptcha'], true ) );

}
