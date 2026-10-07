<?php

/*
PAGE LOADER TESTS
-- The loader's middle content, tagline and text size, the transitions'
-- centre content, the first-visit frequency, and older saved settings
-- keeping the look they had
---------------------------------------------------------- */

require_once OCTAVE_ADDONS_DIR . 'includes/class-fields.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-colors.php';

/*
HELPERS
---------------------------------------------------------- */

function oa_loader_settings( array $values = [] ): array {

	return array_merge( oa_module( 'page-loader' )->get_defaults(), [ 'loader_text' => 'Octave', 'loader_logo' => 'https://example.com/logo.svg', 'loader_image' => 'https://example.com/hero.jpg', 'tagline' => 'Design studio' ], $values );

}

function oa_loader_call( string $method, ...$args ): string {

	$module = oa_module( 'page-loader' );
	$call   = new ReflectionMethod( $module, $method );

	ob_start();
	$result = $call->invoke( $module, ...$args );

	return is_string( $result ) ? $result : (string) ob_get_clean();

}

/*
SETTINGS
---------------------------------------------------------- */

function test_new_loader_options_are_cleaned_on_save(): void {

	$clean = oa_module( 'page-loader' )->sanitize( [
		'loader_content'     => 'logo-text',
		'loader_tagline'     => '1',
		'loader_frequency'   => 'once',
		'transition_content' => 'spinner',
		'text_size'          => 'xl',
		'tagline'            => ' <b>Design</b> studio ',
	] );

	oa_assert_same( 'logo-text', $clean['loader_content'] );
	oa_assert_same( true, $clean['loader_tagline'] );
	oa_assert_same( 'once', $clean['loader_frequency'] );
	oa_assert_same( 'spinner', $clean['transition_content'] );
	oa_assert_same( 'xl', $clean['text_size'] );

	$bad = oa_module( 'page-loader' )->sanitize( [ 'loader_content' => 'video', 'loader_frequency' => 'hourly', 'transition_content' => 'iframe', 'text_size' => 'huge' ] );

	oa_assert_same( [ '', 'session', '', 'medium' ], [ $bad['loader_content'], $bad['loader_frequency'], $bad['transition_content'], $bad['text_size'] ], 'unknown values fall back' );

}

/*
LOADER CONTENT
---------------------------------------------------------- */

function test_loader_shows_text_logo_both_or_nothing(): void {

	$text = oa_loader_call( 'print_mark', 'brand-counter', 'Octave', oa_loader_settings() );

	oa_assert_same( '<span class="oa-loader__brand"><span class="oa-pm-text">Octave</span></span>', $text, 'the style\'s usual text, as before' );

	$logo = oa_loader_call( 'print_mark', 'curtain', 'Octave', oa_loader_settings( [ 'loader_content' => 'logo' ] ) );

	oa_assert_contains( '<img class="oa-pm-logo" src="https://example.com/logo.svg"', $logo );
	oa_assert_not_contains( 'oa-pm-text', $logo );

	$both = oa_loader_call( 'print_mark', 'curtain', 'Octave', oa_loader_settings( [ 'loader_content' => 'logo-text', 'loader_tagline' => true ] ) );

	oa_assert( strpos( $both, 'oa-pm-logo' ) < strpos( $both, 'oa-pm-text' ) && strpos( $both, 'oa-pm-text' ) < strpos( $both, 'oa-pm-tagline' ), 'logo, text, tagline' );
	oa_assert_contains( '>Design studio<', $both );

	oa_assert_same( '', oa_loader_call( 'print_mark', 'custom', 'Octave', oa_loader_settings( [ 'loader_content' => 'none' ] ) ) );
	oa_assert_same( '', oa_loader_call( 'print_mark', 'orbital', 'Octave', oa_loader_settings() ), 'Orbital shows nothing by default, as before' );

	$fallback = oa_loader_call( 'print_mark', 'curtain', 'Octave', oa_loader_settings( [ 'loader_content' => 'logo', 'loader_logo' => '' ] ) );

	oa_assert_contains( '<span class="oa-pm-text">Octave</span>', $fallback, 'no logo set: the text instead' );

}

/*
TRANSITION CONTENT
---------------------------------------------------------- */

function test_transitions_show_their_chosen_centre_content(): void {

	oa_assert_same( 'text', oa_loader_call( 'transition_content', 'brand-wipe', oa_loader_settings() ), 'Brand Wipe keeps its brand text' );
	oa_assert_same( '', oa_loader_call( 'transition_content', 'slide-up', oa_loader_settings() ), 'other styles show nothing by default' );
	oa_assert_same( 'image', oa_loader_call( 'transition_content', 'slide-up', oa_loader_settings( [ 'transition_content' => 'image' ] ) ) );
	oa_assert_same( '', oa_loader_call( 'transition_content', 'brand-wipe', oa_loader_settings( [ 'transition_content' => 'none' ] ) ), 'Brand Wipe without its text' );

	oa_assert_contains( '<img class="oa-pm-image" src="https://example.com/hero.jpg"', oa_loader_call( 'print_transition_content', 'image', 'Octave', oa_loader_settings() ) );
	oa_assert_contains( '<span class="oa-pm-spinner"></span>', oa_loader_call( 'print_transition_content', 'spinner', 'Octave', oa_loader_settings() ) );
	oa_assert_contains( '<span class="oa-pm-text">Octave</span>', oa_loader_call( 'print_transition_content', 'logo', 'Octave', oa_loader_settings( [ 'loader_logo' => '' ] ) ), 'no logo: the text' );

}

function test_text_size_reaches_the_page(): void {

	oa_assert_contains( '--oa-pm-text-scale:1.35;', oa_loader_call( 'root_css', oa_loader_settings( [ 'text_size' => 'large' ] ) ) );
	oa_assert_contains( '--oa-pm-text-scale:1;', oa_loader_call( 'root_css', oa_loader_settings() ), 'medium by default' );

}

function test_brand_wipe_styles_now_use_the_shared_content_element(): void {

	$css = (string) file_get_contents( OCTAVE_ADDONS_DIR . 'modules/design/page-loader/assets/transitions/brand-wipe.css' );

	oa_assert_contains( '.oa-transition--brand-wipe .oa-transition__content', $css );
	oa_assert_not_contains( 'oa-transition__brand', $css );

}

function test_replay_loader_includes_its_custom_css_and_javascript(): void {

	$settings = oa_loader_settings( [
		'loader_enabled'         => false,
		'transitions_enabled'    => true,
		'transition_type'        => 'replay-loader',
		'transition_loader_type' => 'custom',
		'loader_css'             => '.custom-replay-loader{}',
		'loader_js'              => 'window.customReplayLoader = true;',
	] );

	oa_loader_call( 'enqueue_assets', $settings );

	oa_assert_contains( '.custom-replay-loader{}', implode( '', $GLOBALS['oa_inline_styles']['octave-addons-page-motion'] ?? [] ) );
	oa_assert_same( 'window.customReplayLoader = true;', $GLOBALS['oa_inline_scripts']['octave-addons-page-loader-custom'][0]['data'] ?? '' );

}
