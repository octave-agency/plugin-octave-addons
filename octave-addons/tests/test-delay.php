<?php

/*
DELAY TESTS
-- Allowlist matching, protected scripts, attribute and order preservation
---------------------------------------------------------- */

function oa_delay( array $config ): array {

	return Octave_Addons_Perf_Script_Delayer::process( oa_page( $config['html'] ), [
		'services' => $config['services'] ?? [],
		'include'  => $config['include'] ?? [],
		'exclude'  => $config['exclude'] ?? [],
	] );

}

function test_nothing_is_delayed_without_a_selection(): void {

	$html   = '<script src="https://www.googletagmanager.com/gtag/js?id=G-1" async></script>';
	$result = oa_delay( [ 'html' => $html ] );

	oa_assert_same( 0, $result['delayed'] );
	oa_assert_same( oa_page( $html ), $result['html'] );

}

function test_selected_service_and_its_inline_config_are_delayed_with_attributes(): void {

	$html = '<script src="https://www.googletagmanager.com/gtag/js?id=G-1" async integrity="sha384-abc" crossorigin="anonymous" referrerpolicy="origin"></script>'
		. '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("config","G-1");</script>'
		. '<script type="module" src="https://static.hotjar.com/c/hotjar.js" defer></script>'
		. '<script nomodule src="https://static.hotjar.com/legacy.js"></script>';

	$result = oa_delay( [ 'html' => $html, 'services' => [ 'google-analytics', 'hotjar' ] ] );
	$out    = $result['html'];

	oa_assert_same( 4, $result['delayed'] );
	oa_assert_not_contains( ' src="https://www.googletagmanager.com', $out, 'src parked' );
	oa_assert_contains( 'data-oa-src="https://www.googletagmanager.com/gtag/js?id=G-1"', $out );
	oa_assert_contains( 'async integrity="sha384-abc" crossorigin="anonymous" referrerpolicy="origin"', $out, 'attributes untouched' );
	oa_assert_contains( 'data-oa-type="module"', $out, 'module type kept for the loader' );
	oa_assert_contains( 'nomodule', $out );
	oa_assert_contains( 'gtag("config","G-1");</script>', $out, 'inline code untouched' );

	preg_match_all( '/data-oa-delay="([^"]+)"/', $out, $order );

	oa_assert_same( [ 'google-analytics', 'google-analytics', 'hotjar', 'hotjar' ], $order[1], 'document order preserved' );

}

function test_breakdance_core_and_protected_scripts_are_never_delayed(): void {

	$scripts = [
		'<script src="https://example.com/wp-content/plugins/breakdance/plugin/global-scripts/breakdance-utils.js"></script>',
		'<script src="https://example.com/wp-content/plugins/octave-addons/modules/design/animations/assets/controller.js"></script>',
		'<script src="https://example.com/wp-includes/js/jquery/jquery.min.js"></script>',
		'<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>',
		'<script src="https://js.stripe.com/v3/"></script>',
		'<script src="https://consent.cookiebot.com/uc.js"></script>',
		'<script src="https://www.google.com/recaptcha/api.js"></script>',
		'<script id="breakdance-menu-js-extra">var BreakdanceMenu = {};</script>',
	];

	$result = oa_delay( [
		'html'     => implode( '', $scripts ),
		'services' => array_diff( array_keys( Octave_Addons_Perf_Script_Delayer::services() ), [ 'captcha' ] ),
		'include'  => [ 'breakdance', 'jquery', '.js' ],
	] );

	oa_assert_same( 0, $result['delayed'], 'every protected script kept even when included by pattern' );

}

function test_captcha_only_delays_when_contextual_preset_selected(): void {

	$html   = '<script src="https://www.google.com/recaptcha/api.js"></script>';
	$result = oa_delay( [ 'html' => $html, 'services' => [ 'captcha' ] ] );

	oa_assert_same( 1, $result['delayed'] );
	oa_assert_contains( 'data-oa-context="form, [data-oa-captcha]"', $result['html'] );

}

function test_same_origin_scripts_need_a_deliberate_include(): void {

	$html = '<script src="/wp-content/themes/site/slider.js"></script><script src="https://example.com/wp-content/plugins/x/widget.js"></script>';

	oa_assert_same( 0, oa_delay( [ 'html' => $html, 'services' => [ 'chat', 'reviews' ] ] )['delayed'] );
	oa_assert_same( 1, oa_delay( [ 'html' => $html, 'include' => [ 'widget.js' ] ] )['delayed'] );

}

function test_exclusions_and_no_delay_attribute_win(): void {

	$html = '<script src="https://connect.facebook.net/en_US/fbevents.js" data-oa-no-delay></script><script src="https://snap.licdn.com/li.lms-analytics/insight.min.js"></script>';

	$result = oa_delay( [ 'html' => $html, 'services' => [ 'meta-pixel', 'linkedin' ], 'exclude' => [ 'licdn' ] ] );

	oa_assert_same( 0, $result['delayed'] );

	$reasons = array_column( $result['decisions'], 'reason' );

	oa_assert_same( [ 'marked-critical', 'excluded-by-pattern' ], $reasons );

}

function test_non_executable_scripts_are_ignored(): void {

	$html = '<script type="application/ld+json">{"gtag(":"x"}</script><script type="importmap">{}</script>';

	oa_assert_same( 0, oa_delay( [ 'html' => $html, 'services' => [ 'google-analytics' ] ] )['delayed'] );

}

function test_module_adds_loader_once_and_fails_open(): void {

	$module = oa_module( 'performance-delay' );

	$module->run( oa_set_settings( 'performance-delay', [ 'enabled' => true, 'services' => [ 'meta-pixel' ] ] ) );

	$html = $module->transform( oa_page( '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>' ) );

	oa_assert_same( 1, substr_count( $html, 'id="oa-delay-loader"' ) );
	oa_assert_contains( '</script></body>', $html, 'loader before </body>' );

	$plain = oa_page( '<script src="/app.js"></script>' );

	oa_assert_same( $plain, $module->transform( $plain ), 'nothing to delay, nothing changed' );

}
