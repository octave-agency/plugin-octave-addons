<?php

/*
PAGE BUILDER TESTS
-- Breakdance-only and Divi-only parts appear and run only where their
-- builder is active, and Octave's performance features work with Divi 4
-- and 5: its builder is left alone, its hero is preloaded, its CSS is
-- bundled, and its saves clear the right pages
---------------------------------------------------------- */

/*
HELPERS
---------------------------------------------------------- */

function oa_builders( array $builders ): void {

	$GLOBALS['oa_builders'] = $builders;

}

function oa_divi_css( string $path, string $css ): string {

	$file = WP_CONTENT_DIR . '/et-cache/' . $path;

	@mkdir( dirname( $file ), 0777, true );
	file_put_contents( $file, $css );

	return '/wp-content/et-cache/' . $path;

}

/** A Divi page: Theme Builder header, then the post's sections. */
function oa_divi_page( string $head, string $first_class = 'et_pb_section_0' ): string {

	return '<!doctype html><html><head><meta charset="UTF-8">' . $head . '</head><body class="et_divi_theme">'
		. '<header class="et-l et-l--header"><div class="et_pb_section et_pb_section_0_tb_header"><img src="/logo.png" width="160"></div></header>'
		. '<div id="et-main-area"><div class="et-l et-l--post"><div class="et_builder_inner_content">'
		. '<div class="et_pb_section ' . $first_class . ' et_pb_with_background et_section_regular"><div class="et_pb_row et_pb_row_0"><div class="et_pb_column et_pb_column_4_4 et_pb_column_0"><div class="et_pb_text et_pb_text_0"><h1>Welcome</h1></div></div></div></div>'
		. '<div class="et_pb_section et_pb_section_1"><img src="/later.jpg" width="1200"></div>'
		. '</div></div></div></body></html>';

}

function oa_divi_hero_css(): string {

	return '.et_pb_section_0.et_pb_section{background-image:url(https://example.com/wp-content/uploads/desk.jpg);background-size:cover}'
		. '@media only screen and (max-width:980px){.et_pb_section_0.et_pb_section{background-image:url(https://example.com/wp-content/uploads/tab.jpg)}}'
		. '@media only screen and (max-width:767px){.et_pb_section_0.et_pb_section{background-image:url(https://example.com/wp-content/uploads/mob.jpg)}}'
		. '.et_pb_section_0_tb_header.et_pb_section{background-image:url(https://example.com/wp-content/uploads/header.jpg)}';

}

/*
DETECTION AND VISIBILITY
---------------------------------------------------------- */

function test_builders_report_what_the_site_runs(): void {

	oa_builders( [] );
	oa_assert_same( [], Octave_Addons_Builders::active() );
	oa_assert( Octave_Addons_Builders::any( [] ), 'no requirement' );
	oa_assert( ! Octave_Addons_Builders::any( [ 'breakdance', 'divi' ] ) );

	oa_builders( [ 'divi' ] );
	oa_assert_same( [ 'divi' ], Octave_Addons_Builders::active() );
	oa_assert( Octave_Addons_Builders::any( [ 'breakdance', 'divi' ] ), 'either builder will do' );
	oa_assert_same( [ 'divi' => [ '/et-cache/', '/themes/Divi/', '/themes/Extra/' ] ], Octave_Addons_Builders::css_paths() );

}

function test_breakdance_parts_disappear_without_breakdance(): void {

	$manager = $GLOBALS['oa_manager'];

	oa_builders( [ 'divi' ] );

	$entries = $manager->admin_entries();

	oa_assert( ! isset( $entries['breakdance'] ), 'no Breakdance page in the menu' );
	oa_assert( ! $manager->is_available( 'breakdance-spacing' ), 'Breakdance area modules' );
	oa_assert( ! $manager->is_available( 'animations' ), 'Animations targets Breakdance markup only' );
	oa_assert( ! $manager->is_available( 'accessibility-tree' ), 'repairs Breakdance controls only' );
	oa_assert( $manager->is_available( 'breakdance-lazy-load' ), 'the video loader serves WordPress videos everywhere' );
	oa_assert( $manager->is_available( 'performance-media' ) && isset( $entries['performance'] ), 'general modules stay' );

	oa_builders( [ 'breakdance' ] );

	oa_assert( isset( $manager->admin_entries()['breakdance'] ), 'back with Breakdance' );
	oa_assert( $manager->is_available( 'animations' ) );

}

function test_modules_for_a_missing_builder_neither_run_nor_lose_their_settings(): void {

	$manager = $GLOBALS['oa_manager'];

	oa_builders( [] );
	oa_set_settings( 'breakdance-spacing', [ 'enabled' => true ] );

	$saved = get_option( OCTAVE_ADDONS_OPTION_KEY )['breakdance-spacing'];
	// A form that even names the hidden module cannot overwrite it.
	$clean = $manager->sanitize_all( [
		Octave_Addons_Module_Manager::SUBMITTED_FIELD => 'breakdance-spacing,performance-media',
		'breakdance-spacing'                          => [],
		'performance-media'                           => [ 'enabled' => '1' ],
	] );

	oa_assert_same( $saved, $clean['breakdance-spacing'], 'kept for when Breakdance returns' );
	oa_assert_same( true, $clean['performance-media']['enabled'] );

	$manager->run_enabled();

	oa_assert( ! has_filter( 'breakdance_global_settings_css' ), 'spacing did not run' );

}

/*
DIVI BUILDER
---------------------------------------------------------- */

function test_divis_builder_and_previews_are_never_optimised(): void {

	oa_builders( [ 'divi' ] );

	foreach ( [ 'et_fb', 'et_bfb', 'et_pb_preview', 'et_tb' ] as $argument ) {

		$_GET = [ $argument => '1' ];

		oa_assert( Octave_Addons_Module::is_builder_request(), $argument );
		oa_assert_same( 'builder', Octave_Addons_Perf_Context::bypass_reason( 'html' ), $argument . ' bypasses every optimisation' );

	}

	$_GET = [ 'page' => 'et_theme_builder' ];
	$GLOBALS['oa_flags']['admin'] = true;

	oa_assert( Octave_Addons_Module::is_builder_request(), 'Theme Builder screen' );

	$_GET = [];
	$GLOBALS['oa_flags']['admin'] = false;

	oa_assert( ! Octave_Addons_Module::is_builder_request(), 'an ordinary page view' );

}

function test_divis_own_scripts_are_never_delayed(): void {

	$protected = Octave_Addons_Perf_Script_Delayer::protected_patterns( [] );

	foreach ( [ '/wp-content/themes/Divi/js/scripts.min.js', 'var et_pb_custom = {}', 'var et_animation_data = []', '/wp-content/et-cache/15/et-divi-dynamic-15.js' ] as $script ) {

		oa_assert( '' !== Octave_Addons_Perf::matches_any( $script, $protected ), $script );

	}

}

/*
DIVI HERO
---------------------------------------------------------- */

function test_divi_hero_is_preloaded_per_screen_width_from_the_first_view(): void {

	oa_builders( [ 'divi' ] );

	$link = oa_divi_css( '15/et-core-unified-15.min.css', oa_divi_hero_css() );
	$html = oa_hero_transform( oa_divi_page( '<link rel="stylesheet" href="' . $link . '">' ) );

	oa_assert_contains( 'href="https://example.com/wp-content/uploads/mob.jpg" fetchpriority="high" media="(max-width: 767px)"', $html );
	oa_assert_contains( 'href="https://example.com/wp-content/uploads/tab.jpg" fetchpriority="high" media="(min-width: 768px) and (max-width: 980px)"', $html );
	oa_assert_contains( 'href="https://example.com/wp-content/uploads/desk.jpg" fetchpriority="high" media="(min-width: 981px)"', $html );
	oa_assert_not_contains( 'header.jpg', $html, 'the Theme Builder header is not the hero' );
	oa_assert_not_contains( '<img fetchpriority="high"', $html, 'no competing image guess' );

}

function test_divi_hero_is_found_in_inline_critical_css(): void {

	oa_builders( [ 'divi' ] );

	$html = oa_hero_transform( oa_divi_page( '<style id="et-critical-inline-css">' . oa_divi_hero_css() . '</style>' ) );

	oa_assert_contains( 'mob.jpg" fetchpriority="high" media="(max-width: 767px)"', $html );

}

function test_theme_builder_body_sections_count_and_divi_markup_is_ignored_without_divi(): void {

	oa_builders( [ 'divi' ] );

	$css  = '.et_pb_section_0_tb_body{background-image:url(https://example.com/wp-content/uploads/body.jpg)}';
	$html = oa_hero_transform( oa_divi_page( '<style>' . $css . '</style>', 'et_pb_section_0_tb_body' ) );

	oa_assert_contains( 'body.jpg" fetchpriority="high"', $html );

	oa_test_reset();
	oa_builders( [ 'breakdance' ] );

	oa_assert_same( [], Octave_Addons_Perf_Hero::discover( oa_divi_page( '<style>' . oa_divi_hero_css() . '</style>' ) ), 'Divi markup means nothing without Divi' );

}

/*
DIVI CSS
---------------------------------------------------------- */

function test_divi_stylesheets_bundle_in_order(): void {

	oa_builders( [ 'divi' ] );

	$theme  = '<link rel="stylesheet" href="' . oa_bd_css_at( 'themes/Divi/style-static.min.css', '.et_pb_row{width:80%}' ) . '">';
	$unified = '<link rel="stylesheet" href="' . oa_divi_css( '15/et-core-unified-15.min.css', '.et_pb_section_0{padding:0}' ) . '">';
	$late   = '<link rel="stylesheet" href="' . oa_divi_css( '15/et-divi-dynamic-15-late.css', '.late{}' ) . '" media="print" onload="this.media=\'all\'">';

	$html = oa_warm_bundle( oa_bundle_page( $theme . $unified . $late ) );

	oa_assert_contains( 'oa-css-bundle-1', $html );
	oa_assert( strpos( $html, '.et_pb_row' ) < strpos( $html, '.et_pb_section_0' ), 'order kept' );
	oa_assert_contains( 'et-divi-dynamic-15-late.css', $html, 'Divi\'s deferred critical CSS stays as it is' );

	oa_test_reset();
	oa_builders( [] );

	$html = oa_bundle_page( $theme . $unified );

	oa_assert_same( $html, oa_bundle_files()->bundle_styles( $html ), 'nothing bundled without a builder' );

}

function oa_bd_css_at( string $path, string $css ): string {

	$file = WP_CONTENT_DIR . '/' . $path;

	@mkdir( dirname( $file ), 0777, true );
	file_put_contents( $file, $css );

	return '/wp-content/' . $path;

}

/*
DIVI CACHE INVALIDATION
---------------------------------------------------------- */

function test_divi_site_wide_changes_clear_everything_once(): void {

	oa_builders( [ 'divi' ] );
	oa_fake_page_cache();
	Octave_Addons_Perf_Cache::register_invalidation();

	$GLOBALS['oa_caps'] = [ 'edit_theme_options' ];

	do_action( 'et_core_static_resources_removed', 'all' );
	do_action( 'et_epanel_update_option', 'divi_logo', 'x' );
	do_action( 'et_global_colors_saved', [] );
	do_action( 'shutdown' );

	oa_assert_same( [ 'all' ], $GLOBALS['oa_cache_calls'], 'one full clear' );
	oa_assert_same( 'divi', Octave_Addons_Perf_Cache::last_clears()['full']['reason'] );

	do_action( 'et_core_static_resources_removed', 'all' );
	do_action( 'shutdown' );

	oa_assert_same( 1, count( $GLOBALS['oa_cache_calls'] ), 'at most once a minute' );

}

function test_divi_layouts_clear_everything_and_pages_clear_themselves(): void {

	oa_builders( [ 'divi' ] );
	oa_fake_page_cache();
	Octave_Addons_Perf_Cache::register_invalidation();

	$GLOBALS['oa_posts']      = [ 3 => [ 'et_header_layout', 'publish' ], 8 => [ 'page', 'publish' ] ];
	$GLOBALS['oa_permalinks'] = [ 8 => 'https://example.com/about/' ];

	do_action( 'et_core_static_resources_removed', 8 );
	do_action( 'shutdown' );

	oa_assert_same( [ [ 'https://example.com/', 'https://example.com/about/' ] ], $GLOBALS['oa_cache_calls'], 'one page: a targeted clear' );

	oa_test_reset();
	oa_builders( [ 'divi' ] );
	oa_fake_page_cache();
	Octave_Addons_Perf_Cache::register_invalidation();

	$GLOBALS['oa_posts'] = [ 3 => [ 'et_header_layout', 'publish' ] ];

	do_action( 'wp_after_insert_post', 3, get_post( 3 ), true, get_post( 3 ) );
	do_action( 'shutdown' );

	oa_assert_same( [ 'all' ], $GLOBALS['oa_cache_calls'], 'a Theme Builder header is on every page' );

}

/*
DIVI SETTINGS
---------------------------------------------------------- */

function test_divis_google_fonts_handling_makes_octave_step_aside(): void {

	oa_builders( [ 'divi' ] );
	update_option( 'et_divi', [ 'divi_google_fonts_inline' => 'on' ] );

	oa_assert_same( 'Divi', Octave_Addons_Perf::handled_elsewhere( 'google_fonts' ) );

	Octave_Addons_Perf_Owners::reset();
	update_option( 'et_divi', [] );

	oa_assert_same( '', Octave_Addons_Perf::handled_elsewhere( 'google_fonts' ), 'Divi\'s default leaves fonts to Octave' );

}

function test_divi_audit_reads_divis_own_settings_with_its_defaults(): void {

	oa_builders( [] );
	oa_assert_same( [], Octave_Addons_Perf_Diagnostics::divi_audit(), 'nothing without Divi' );

	oa_builders( [ 'divi' ] );
	update_option( 'et_divi', [ 'divi_critical_css' => 'off' ] );

	$rows = array_column( Octave_Addons_Perf_Diagnostics::divi_audit(), 'status', 'label' );

	oa_assert_same( 'Off', $rows['Critical CSS'], 'saved value' );
	oa_assert_same( 'On', $rows['Dynamic CSS'], 'Divi default' );
	oa_assert_same( 'Off', $rows['Improve Google Fonts Loading'], 'Divi default' );

}

/*
BREAKDANCE-ONLY OPTIONS IN SHARED MODULES
---------------------------------------------------------- */

function test_breakdance_colours_are_only_offered_with_breakdance(): void {

	require_once OCTAVE_ADDONS_DIR . 'includes/class-colors.php';

	oa_builders( [ 'divi' ] );

	ob_start();
	Octave_Addons_Colors::render_options( 'brand' );

	oa_assert_same( '', trim( (string) ob_get_clean() ), 'no Breakdance options' );
	oa_assert_same( '#123456', Octave_Addons_Colors::resolve( 'brand', '#123456' ), 'a saved Breakdance colour falls back to its hex' );
	oa_assert_same( '#3B82F6', Octave_Addons_Colors::resolve( 'brand', '' ), 'or to the variable\'s own fallback' );
	oa_assert_same( [], Octave_Addons_Colors::resolved_values() );

	oa_builders( [ 'breakdance' ] );

	oa_assert_same( 'var(--bde-brand-primary-color, #123456)', Octave_Addons_Colors::resolve( 'brand', '#123456' ) );

}

function test_breakdance_icon_field_type_is_only_offered_with_breakdance(): void {

	$module  = oa_module( 'custom-post-types' );
	$offered = new ReflectionMethod( $module, 'offered_types' );
	$types   = [ 'text' => 'Text', 'icon' => 'Breakdance icon' ];

	oa_builders( [ 'divi' ] );

	oa_assert_same( [ 'text' => 'Text' ], $offered->invoke( $module, $types, 'text' ) );
	oa_assert_same( $types, $offered->invoke( $module, $types, 'icon' ), 'an existing icon field keeps its type' );

	oa_builders( [ 'breakdance' ] );

	oa_assert_same( $types, $offered->invoke( $module, $types, 'text' ) );

}
