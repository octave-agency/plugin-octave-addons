<?php

/*
BREAKDANCE CSS BUNDLE TESTS
-- Each unbroken run of Breakdance and Octave stylesheets becomes one
-- bundle with identical order, media and URLs, prepared by the warmer,
-- cached by source, and anything unsafe keeps its original link
---------------------------------------------------------- */

/*
HELPERS
---------------------------------------------------------- */

function oa_bundle_files(): Octave_Addons_Module_Performance_Files {

	$module = oa_module( 'performance-files' );

	$module->run( oa_set_settings( 'performance-files', [ 'enabled' => true, 'breakdance_css' => true, 'inline_css' => false ] ) );

	return $module;

}

function oa_bundle_page( string $head ): string {

	return '<!doctype html><html><head><meta charset="UTF-8">' . $head . '</head><body><p>Hi</p></body></html>';

}

function oa_bd_link( string $name, string $css, string $attributes = '' ): string {

	return '<link rel="stylesheet" href="' . oa_bd_css( $name, $css ) . '?ver=2"' . $attributes . '>';

}

function oa_warm_bundle( string $html ): string {

	$_SERVER['HTTP_X_OCTAVE_WARM'] = '1';

	$result = oa_bundle_files()->bundle_styles( $html );

	unset( $_SERVER['HTTP_X_OCTAVE_WARM'] );

	return $result;

}

/*
TESTS
---------------------------------------------------------- */

function test_a_run_of_breakdance_stylesheets_becomes_one_ordered_bundle(): void {

	$head = oa_bd_link( 'normalize.css', 'html{margin:0}' )
		. '<style id="bd-inline-css">.a{color:red}</style>'
		. oa_bd_link( 'global-settings.css', '.b{background:url(img/bg.png)}' )
		. '<link rel="preload" href="/font.woff2" as="font" crossorigin>'
		. oa_bd_link( 'post-15.css', '.c{color:blue}', ' media="screen and (min-width: 768px)"' );

	$html = oa_warm_bundle( oa_bundle_page( $head ) );

	oa_assert_same( 0, substr_count( $html, 'rel="stylesheet"' ), 'no blocking requests left' );
	oa_assert_same( 1, substr_count( $html, '<style id="oa-css-bundle-1" data-oa-bundle="3">' ), 'one bundle, three files' );
	oa_assert( strpos( $html, 'html{margin:0}' ) < strpos( $html, '.a{color:red}' ) && strpos( $html, '.a{color:red}' ) < strpos( $html, '.b{' ) && strpos( $html, '.b{' ) < strpos( $html, '.c{' ), 'exact order, inline style included' );
	oa_assert_contains( '@media screen and (min-width: 768px){.c{color:blue}}', $html, 'media kept' );
	oa_assert_contains( 'url(https://example.com/wp-content/uploads/breakdance/css/img/bg.png)', $html, 'relative URLs rewritten' );
	oa_assert( strpos( $html, 'rel="preload"' ) < strpos( $html, 'oa-css-bundle-1' ), 'other links kept, ahead of the bundle' );

}

function test_bundles_are_prepared_by_the_warmer_not_a_visitor(): void {

	oa_fake_page_cache();

	$html = oa_bundle_page( oa_bd_link( 'a.css', '.a{}' ) . oa_bd_link( 'b.css', '.b{}' ) );

	oa_assert_same( $html, oa_bundle_files()->bundle_styles( $html ), 'the visitor gets the original links' );

	$state = Octave_Addons_Perf_Page_Cache::state();

	oa_assert_same( 'bundle', $state['reason'] );
	oa_assert( ! empty( $state['purge_first'] ), 'the page is purged before the warmer requests it' );
	oa_assert_same( 'incomplete', Octave_Addons_Perf_Disk_Cache::response_bypass( '<!doctype html><html></html>' ), 'that page is not stored by Octave\'s page cache' );

	oa_assert_contains( 'oa-css-bundle-1', oa_warm_bundle( $html ) );
	oa_assert_contains( 'oa-css-bundle-1', oa_bundle_files()->bundle_styles( $html ), 'later visitors get the bundle' );

}

function test_an_edited_stylesheet_gets_a_new_bundle(): void {

	$html = oa_bundle_page( oa_bd_link( 'a.css', '.a{color:red}' ) . oa_bd_link( 'b.css', '.b{}' ) );

	oa_assert_contains( 'color:red', oa_warm_bundle( $html ) );

	$html = oa_bundle_page( oa_bd_link( 'a.css', '.a{color:green;}' ) . oa_bd_link( 'b.css', '.b{}' ) );

	oa_assert_contains( 'color:green', oa_warm_bundle( $html ) );

}

function test_unsafe_or_foreign_stylesheets_keep_their_links_and_split_runs(): void {

	$head = oa_bd_link( 'a.css', '.a{}' )
		. oa_bd_link( 'b.css', '.b{}' )
		. '<link rel="stylesheet" href="https://cdn.other.test/x.css">'
		. oa_bd_link( 'c.css', '.c{}', ' integrity="sha384-x"' )
		. oa_bd_link( 'd.css', '@import url(x.css);.d{}' )
		. '<link rel="stylesheet" href="/wp-content/themes/theme/style.css">'
		. oa_bd_link( 'print.css', '.p{}', ' media="print"' )
		. '<script>var x = 1;</script>'
		. oa_bd_link( 'e.css', '.e{}' );

	$html = oa_warm_bundle( oa_bundle_page( $head ) );

	oa_assert_contains( 'oa-css-bundle-1', $html, 'a and b bundled' );
	oa_assert_not_contains( 'oa-css-bundle-2', $html, 'nothing else forms a run of two' );

	foreach ( [ 'cdn.other.test/x.css', 'c.css', 'd.css', 'theme/style.css', 'print.css', 'e.css' ] as $kept ) {

		oa_assert_contains( $kept, $html, $kept . ' keeps its link' );

	}

}

function test_a_broken_stylesheet_fails_open(): void {

	$html = oa_bundle_page( oa_bd_link( 'a.css', '.a{color:red' ) . oa_bd_link( 'b.css', '.b{}' ) );

	oa_assert_same( $html, oa_warm_bundle( $html ) );
	oa_assert_contains( 'could not be bundled', Octave_Addons_Perf_Log::entries()[0]['message'] ?? '' );

}

function test_large_bundles_are_one_cached_file_instead_of_inline(): void {

	add_filter( 'octave_addons_perf_bundle_inline_max', static function () {

		return 10;

	} );

	$html = oa_warm_bundle( oa_bundle_page( oa_bd_link( 'a.css', '.a{color:red}' ) . oa_bd_link( 'b.css', '.b{color:blue}' ) ) );

	oa_assert_same( 1, substr_count( $html, 'rel="stylesheet"' ), 'one request' );
	oa_assert_contains( '/cache/octave-addons/min/bundle-', $html );

}

function test_exclusions_keep_a_stylesheet_out_of_the_bundle(): void {

	add_filter( 'octave_addons_perf_bundle_exclusions', static function () {

		return [ 'b.css' ];

	} );

	$html = oa_warm_bundle( oa_bundle_page( oa_bd_link( 'a.css', '.a{}' ) . oa_bd_link( 'b.css', '.b{}' ) . oa_bd_link( 'c.css', '.c{}' ) ) );

	oa_assert_not_contains( 'oa-css-bundle', $html, 'the excluded file splits the run into singles' );

}
