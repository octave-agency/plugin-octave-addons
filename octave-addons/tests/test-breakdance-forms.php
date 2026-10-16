<?php

/*
BREAKDANCE FORMS TESTS
-- Covers mutually exclusive captcha settings, output order, hooks and remote
-- verification for the global Breakdance Forms module
---------------------------------------------------------- */

function oa_breakdance_forms_settings( array $overrides = [] ): array {

	return array_merge(
		oa_module( 'breakdance-forms' )->get_defaults(),
		$overrides
	);

}

function test_breakdance_forms_defaults_are_opt_in(): void {

	$defaults = oa_module( 'breakdance-forms' )->get_defaults();

	oa_assert_same( false, $defaults['enabled'] );
	oa_assert_same( '', $defaults['captcha_provider'] );
	oa_assert_same( false, $defaults['message_enabled'] );
	oa_assert( ! Octave_Addons_Module_Breakdance_Forms::verify_captcha_response( 'both', 'token' ) );

}

function test_breakdance_forms_sanitizes_one_provider_and_safe_html(): void {

	$module = oa_module( 'breakdance-forms' );
	$clean  = $module->sanitize(
		[
			'enabled'          => '1',
			'captcha_provider' => 'TURNSTILE',
			'message_enabled'  => '1',
			'message_html'     => '<p>Read <a href="/terms">terms</a></p><script>alert(1)</script>',
		]
	);

	oa_assert_same( 'turnstile', $clean['captcha_provider'] );
	oa_assert_same( true, $clean['message_enabled'] );
	oa_assert_contains( '<a href="/terms">terms</a>', $clean['message_html'] );
	oa_assert_not_contains( '<script', $clean['message_html'] );

	$invalid = $module->sanitize( [ 'captcha_provider' => 'both' ] );

	oa_assert_same( '', $invalid['captcha_provider'] );

}

function test_breakdance_forms_registers_render_asset_and_early_validation_hooks(): void {

	$module = oa_module( 'breakdance-forms' );
	$module->run( oa_breakdance_forms_settings( [ 'enabled' => true, 'captcha_provider' => 'google' ] ) );

	oa_assert( has_filter( 'breakdance_form_before_footer' ) );
	oa_assert( has_filter( 'wp_enqueue_scripts' ) );
	oa_assert( has_filter( 'wp_ajax_breakdance_form_custom' ) );
	oa_assert( has_filter( 'wp_ajax_nopriv_breakdance_form_custom' ) );

}

function test_breakdance_forms_prints_message_before_captcha(): void {

	$module = oa_module( 'breakdance-forms' );
	$module->run(
		oa_breakdance_forms_settings(
			[
				'enabled'          => true,
				'captcha_provider' => 'turnstile',
				'message_enabled'  => true,
				'message_html'     => '<p class="terms">Accept the terms</p>',
			]
		)
	);

	ob_start();
	Octave_Addons_Module_Breakdance_Forms::render_before_footer();
	$html = ob_get_clean();

	oa_assert_contains( 'oa-breakdance-form-message', $html );
	oa_assert_contains( 'data-oa-captcha="turnstile"', $html );
	oa_assert( strpos( $html, 'oa-breakdance-form-message' ) < strpos( $html, 'oa-breakdance-form-captcha' ) );

}

function test_breakdance_forms_verifies_google_score_and_action(): void {

	$module = oa_module( 'breakdance-forms' );
	$module->run(
		oa_breakdance_forms_settings(
			[
				'enabled'           => true,
				'captcha_provider'  => 'google',
				'google_secret_key' => 'google-secret',
			]
		)
	);

	$GLOBALS['oa_http'] = static function () {

		return oa_http_response( 200, '{"success":true,"score":0.9,"action":"octave_breakdance_submit"}' );

	};

	oa_assert( Octave_Addons_Module_Breakdance_Forms::verify_captcha_response( 'google', 'token', '127.0.0.1' ) );
	oa_assert_same( 'https://www.google.com/recaptcha/api/siteverify', $GLOBALS['oa_http_log'][0]['url'] );
	oa_assert_same( 'google-secret', $GLOBALS['oa_http_log'][0]['args']['body']['secret'] );

}

function test_breakdance_forms_verifies_turnstile_success(): void {

	$module = oa_module( 'breakdance-forms' );
	$module->run(
		oa_breakdance_forms_settings(
			[
				'enabled'              => true,
				'captcha_provider'     => 'turnstile',
				'turnstile_secret_key' => 'turnstile-secret',
			]
		)
	);

	$GLOBALS['oa_http'] = static function () {

		return oa_http_response( 200, '{"success":true}' );

	};

	oa_assert( Octave_Addons_Module_Breakdance_Forms::verify_captcha_response( 'turnstile', 'token' ) );
	oa_assert_same( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', $GLOBALS['oa_http_log'][0]['url'] );

}
