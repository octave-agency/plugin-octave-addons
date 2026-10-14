<?php

/*
USED CSS TESTS
-- Relaxed trimming: only rules naming something absent from the page and
-- its scripts go; states, at-rules and anything unreadable are kept, and
-- the full stylesheet still loads behind the trimmed copy
---------------------------------------------------------- */

function oa_tokens( string $html ): array {

	return Octave_Addons_Perf_Used_Css::page_tokens( $html );

}

/*
REDUCER
---------------------------------------------------------- */

function test_rules_for_classes_and_ids_absent_from_the_page_are_dropped(): void {

	$tokens = oa_tokens( '<div class="hero big" id="top"><a class="btn">x</a></div>' );
	$css    = Octave_Addons_Perf_Used_Css::reduce( '.hero{a:1}.footer-cta{b:2}#top .btn{c:3}#gone{d:4}.hero .gone{e:5}body,p{f:6}.gone,.big{g:7}', $tokens );

	oa_assert_same( '.hero{a:1}#top .btn{c:3}body,p{f:6}.gone,.big{g:7}', $css );

}

function test_states_scripts_and_selectors_it_cannot_judge_are_kept(): void {

	$tokens = oa_tokens( '<nav class="menu-wrap"></nav><script>el.classList.add("et_pb_zoomed");</script>' );
	$css    = Octave_Addons_Perf_Used_Css::reduce( '.x-open{a:1}.et_pb_zoomed{b:2}:is(.nope){c:3}.nope:hover{d:4}a[href^="#"]{e:5}.card:not(.nope){f:6}.card{g:7}', $tokens );

	oa_assert_contains( '.x-open{a:1}', $css, 'state word kept' );
	oa_assert_contains( '.et_pb_zoomed{b:2}', $css, 'class named in a script kept' );
	oa_assert_contains( ':is(.nope){c:3}', $css, ':is() never judged' );
	oa_assert_not_contains( '.nope:hover', $css, 'a pseudo-class does not save an absent class' );
	oa_assert_contains( 'a[href^="#"]{e:5}', $css, 'attributes and tags never judged' );
	oa_assert_not_contains( '.card', $css, '.card is absent; :not() contents do not count' );

}

function test_at_rules_are_kept_or_trimmed_inside(): void {

	$tokens = oa_tokens( '<p class="here"></p>' );
	$css    = Octave_Addons_Perf_Used_Css::reduce( "@charset \"utf-8\";@font-face{font-family:X;src:url(data:font/woff2;base64,AA{}) }@keyframes spin{from{a:0}to{a:1}}@media (max-width:600px){.gone{a:1}.here{b:2}}@media print{.gone{c:3}}.a{b:url(\"x;}.png\")}", $tokens );

	oa_assert_contains( '@charset "utf-8";', $css );
	oa_assert_contains( '@font-face{font-family:X;src:url(data:font/woff2;base64,AA{}) }', $css, 'data URI with braces and semicolons survives' );
	oa_assert_contains( '@keyframes spin{from{a:0}to{a:1}}', $css );
	oa_assert_contains( '@media (max-width:600px){.here{b:2}}', $css, 'media blocks trimmed inside' );
	oa_assert_not_contains( '@media print', $css, 'an emptied media block goes' );

}

function test_unreadable_css_returns_null(): void {

	oa_assert_same( null, Octave_Addons_Perf_Used_Css::reduce( '.a{color:red', oa_tokens( '' ) ) );
	oa_assert_same( null, Octave_Addons_Perf_Used_Css::reduce( '.a{b:"unclosed}', oa_tokens( '' ) ) );

}

/*
PAGE
---------------------------------------------------------- */

function test_large_local_stylesheets_are_trimmed_with_the_full_file_behind(): void {

	$rules = str_repeat( '.unused-module-rule{color:red}', 300 );
	$href  = oa_content_file( 'themes/site/style.css', '.hero{color:blue;background:url(img/bg.png)}' . $rules );
	$small = oa_content_file( 'plugins/tiny/tiny.css', '.tiny{a:1}' );
	$page  = oa_page( '<link rel="stylesheet" id="theme-css" href="' . $href . '?ver=1" media="all"><link rel="stylesheet" href="' . $small . '"><div class="hero"></div>' );

	$html = oa_files( [ 'minify_css' => false, 'used_css' => true ] )->used_styles( $page );

	oa_assert_contains( '<style id="theme-css-used" data-oa-used-css>.hero{color:blue;background:url(https://example.com/wp-content/themes/site/img/bg.png)}</style>', $html, 'trimmed, relative URLs fixed' );
	oa_assert_contains( 'id="theme-css" href="' . $href . '?ver=1" media="print" onload="this.media=\'all\'" data-oa-used-full', $html, 'full file loads without blocking' );
	oa_assert_contains( '<noscript><link rel="stylesheet" href="' . $href . '?ver=1"></noscript>', $html );
	oa_assert_contains( '<link rel="stylesheet" href="' . $small . '">', $html, 'small files left alone' );

}

function test_visitors_never_wait_for_a_trim_but_the_preview_does_it_now(): void {

	$href   = oa_content_file( 'themes/site/style.css', '.hero{a:1}' . str_repeat( '.x{b:2}', 900 ) );
	$page   = oa_page( '<link rel="stylesheet" href="' . $href . '"><div class="hero"></div>' );
	$module = oa_files( [ 'minify_css' => false, 'used_css' => true ] );

	unset( $_SERVER['HTTP_X_OCTAVE_WARM'] );

	oa_assert_same( $page, $module->used_styles( $page ), 'a visitor gets the original while the warmer prepares it' );

	$_GET['oa_ucss'] = '1';

	oa_assert_contains( 'data-oa-used-css', $module->used_styles( $page ), 'the preview trims straight away' );

}

function test_page_specific_builder_css_is_never_trimmed(): void {

	$href = oa_content_file( 'uploads/breakdance/css/post-12.css', str_repeat( '.x{b:2}', 900 ) );
	$page = oa_page( '<link rel="stylesheet" href="' . $href . '">' );

	oa_assert_same( $page, oa_files( [ 'used_css' => true ] )->used_styles( $page ) );

}

function test_the_fingerprint_ignores_nonces_and_generated_ids(): void {

	$a = oa_tokens( '<div class="hero" id="form_123456"></div><script>var n="abc123";</script>' );
	$b = oa_tokens( '<div class="hero" id="form_987654"></div><script>var n="zzz999";</script>' );
	$c = oa_tokens( '<div class="hero footer"></div>' );

	oa_assert_same( Octave_Addons_Perf_Used_Css::fingerprint( $a ), Octave_Addons_Perf_Used_Css::fingerprint( $b ), 'the same page twice shares one trimmed copy' );
	oa_assert( Octave_Addons_Perf_Used_Css::fingerprint( $a ) !== Octave_Addons_Perf_Used_Css::fingerprint( $c ), 'different markup, different copy' );

}
