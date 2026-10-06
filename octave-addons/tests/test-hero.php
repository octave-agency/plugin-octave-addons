<?php

/*
BREAKDANCE HERO TESTS
-- The first view of a Breakdance page preloads its hero background from
-- the page's own CSS, per screen width, before any stylesheet, without
-- competing or repeating preloads, and learned reports still correct it
---------------------------------------------------------- */

const OA_HERO_DESKTOP = 'https://example.com/wp-content/uploads/2026/09/shutterstock_2795741781-scaled.jpg';
const OA_HERO_MOBILE  = 'https://example.com/wp-content/uploads/2026/09/shutterstock_2795741781-1024x683.jpg';

/*
HELPERS
-- A Breakdance page shaped like the supplied one: a header, then
-- section.bde-section-15-100 whose background is set in post-15.css
---------------------------------------------------------- */

function oa_bd_css( string $name, string $css ): string {

	$dir = WP_CONTENT_DIR . '/uploads/breakdance/css/';

	@mkdir( $dir, 0777, true );
	file_put_contents( $dir . $name, $css );

	return '/wp-content/uploads/breakdance/css/' . $name;

}

function oa_hero_css(): string {

	return '.breakdance .bde-section-15-100{background-image:url(' . OA_HERO_DESKTOP . ');background-size:cover}'
		. '@media (max-width: 1023px){.breakdance .bde-section-15-100{background-image:url(' . OA_HERO_MOBILE . ')}}'
		. '@media (max-width: 767px){.breakdance .bde-section-15-100 .section-container{padding:0}}'
		. '.breakdance .bde-section-15-100:hover{background-image:url(/hover.jpg)}'
		. '.breakdance .bde-section-15-100 > .section-background-overlay{background-image:url(/overlay.png)}';

}

function oa_hero_page( string $css = '', string $extra_head = '', string $body = '' ): string {

	$normalize = oa_bd_css( 'normalize.css', 'html{line-height:1.15}' );
	$post      = oa_bd_css( 'post-15.css', '' !== $css ? $css : oa_hero_css() );

	return '<!doctype html><html><head><meta charset="UTF-8"><title>Home</title>' . $extra_head
		. '<link rel="stylesheet" href="' . $normalize . '?ver=1">'
		. '<link rel="stylesheet" href="' . $post . '?v=abc">'
		. '</head><body>'
		. '<header class="bde-header-builder-15-1"><div class="bde-div-15-2"><img src="/logo.png" width="160"></div></header>'
		. '<section class="bde-section-15-100 bde-section" fetchpriority="high"><div class="section-container"><h1>Welcome</h1></div></section>'
		. $body
		. '<section class="bde-section-15-200 bde-section"><img src="/later.jpg" width="1200"></section>'
		. '</body></html>';

}

function oa_hero_transform( string $html, string $path = '/' ): string {

	$_SERVER['REQUEST_URI'] = $path;

	return oa_media()->transform( $html );

}

function oa_preloads( string $html ): array {

	preg_match_all( '#<link rel="preload" as="image"[^>]*>#', $html, $matches );

	return $matches[0];

}

/*
DISCOVERY
---------------------------------------------------------- */

function test_first_view_preloads_the_right_hero_image_per_screen_width(): void {

	$html     = oa_hero_transform( oa_hero_page() );
	$preloads = oa_preloads( $html );

	oa_assert_same( 2, count( $preloads ), 'one per width range' );
	oa_assert_contains( 'href="' . OA_HERO_DESKTOP . '" fetchpriority="high" media="(min-width: 1024px)"', $preloads[0] . $preloads[1] );
	oa_assert_contains( 'href="' . OA_HERO_MOBILE . '" fetchpriority="high" media="(max-width: 1023px)"', $preloads[0] . $preloads[1], 'phones get the image Breakdance shows them' );
	oa_assert_not_contains( 'hover.jpg', $html, 'hover states are not the hero' );
	oa_assert_not_contains( 'overlay.png', implode( '', $preloads ), 'descendants are not the hero' );

}

function test_hero_preload_comes_right_after_the_charset_before_any_stylesheet(): void {

	$html    = oa_hero_transform( oa_hero_page() );
	$charset = strpos( $html, '<meta charset="UTF-8">' );
	$preload = strpos( $html, 'rel="preload" as="image"' );
	$styles  = strpos( $html, 'rel="stylesheet"' );

	oa_assert( $charset < $preload && $preload < $styles, 'charset, preload, stylesheets' );

}

function test_a_section_with_fetchpriority_is_not_treated_as_the_hero_image(): void {

	$html = oa_hero_transform( oa_hero_page( '.breakdance .bde-section-15-100{padding:40px}' ) );

	oa_assert_same( [], oa_preloads( $html ), 'no background, no preload' );
	oa_assert_contains( '<img fetchpriority="high" src="/later.jpg" width="1200">', $html, 'the image guess still runs despite the section attribute' );

}

function test_hero_background_replaces_the_image_guess(): void {

	$html = oa_hero_transform( oa_hero_page() );

	oa_assert_not_contains( '<img fetchpriority="high"', $html, 'never two high-priority hero images' );

}

function test_relative_urls_and_mobile_first_rules_resolve(): void {

	$css  = '.bde-section-15-100{background:#000 url("../../2026/09/small.jpg") center/cover no-repeat}'
		. '@media screen and (min-width: 768px){.bde-section-15-100{background-image:url(/wp-content/uploads/2026/09/large.jpg)}}';
	$html = oa_hero_transform( oa_hero_page( $css ) );

	oa_assert_contains( 'href="https://example.com/wp-content/uploads/2026/09/small.jpg" fetchpriority="high" media="(max-width: 767px)"', $html );
	oa_assert_contains( 'href="https://example.com/wp-content/uploads/2026/09/large.jpg" fetchpriority="high" media="(min-width: 768px)"', $html );

}

function test_a_breakpoint_without_an_image_gets_no_preload(): void {

	$css  = '.bde-section-15-100{background-image:url(/desk.jpg)}@media (max-width: 479px){.bde-section-15-100{background-image:none}}';
	$html = oa_hero_transform( oa_hero_page( $css ) );

	oa_assert_same( [ '<link rel="preload" as="image" href="https://example.com/desk.jpg" fetchpriority="high" media="(min-width: 480px)" data-oa-hero>' ], oa_preloads( $html ) );

}

function test_anything_unexpected_means_no_guess(): void {

	$cases = [
		'orientation'  => '.bde-section-15-100{background-image:url(/a.jpg)}@media (orientation: portrait){.bde-section-15-100{background-image:url(/b.jpg)}}',
		'image-set'    => '.bde-section-15-100{background-image:image-set(url(/a.jpg) 1x, url(/b.jpg) 2x)}',
		'layers'       => '.bde-section-15-100{background-image:url(/a.png), url(/b.jpg)}',
		'broken sheet' => '.bde-section-15-100{background-image:url(/a.jpg)',
	];

	foreach ( $cases as $label => $css ) {

		oa_test_reset();

		oa_assert_same( [], oa_preloads( oa_hero_transform( oa_hero_page( $css ) ) ), $label );

	}

}

function test_an_existing_high_priority_image_preload_is_not_competed_with(): void {

	$html = oa_hero_transform( oa_hero_page( '', '<link rel="preload" as="image" href="/theirs.jpg" fetchpriority="high">' ) );

	oa_assert_same( 1, count( oa_preloads( $html ) ), 'only the existing one' );

}

function test_hero_result_is_cached_against_the_stylesheets(): void {

	oa_hero_transform( oa_hero_page() );

	$css = WP_CONTENT_DIR . '/uploads/breakdance/css/post-15.css';

	file_put_contents( $css, '.bde-section-15-100{background-image:url(/new.jpg)}' );
	touch( $css, time() + 5 );
	clearstatcache();

	$html = oa_hero_transform( preg_replace( '#<link rel="preload"[^>]*>\n?#', '', oa_hero_page( '.bde-section-15-100{background-image:url(/new.jpg)}' ) ) );

	oa_assert_contains( 'href="https://example.com/new.jpg"', $html, 'a Breakdance save is seen straight away' );

}

/*
LEARNED RECORDS
---------------------------------------------------------- */

function test_learned_and_page_hints_never_repeat_each_other(): void {

	Octave_Addons_Perf_Lcp::store( '/', [
		'd' => [ 'kind' => 'bg', 'url' => OA_HERO_DESKTOP, 'time' => time() ],
		'm' => [ 'kind' => 'bg', 'url' => OA_HERO_MOBILE, 'time' => time() ],
	] );

	$preloads = oa_preloads( oa_hero_transform( oa_hero_page() ) );

	oa_assert_same( 2, count( $preloads ), 'the learned record agrees, so only the page hints remain' );
	oa_assert_not_contains( '(min-width: 768px)', implode( '', $preloads ), 'no learned duplicate' );

}

function test_a_fresh_report_naming_another_image_corrects_the_page_guess(): void {

	Octave_Addons_Perf_Lcp::store( '/', [
		'm' => [ 'kind' => 'img', 'url' => 'https://example.com/later.jpg', 'time' => time() ],
		'd' => [ 'kind' => 'img', 'url' => 'https://example.com/later.jpg', 'time' => time() ],
	] );

	$html = oa_hero_transform( oa_hero_page() );

	oa_assert_same( [], oa_preloads( $html ), 'the background guess is set aside' );
	oa_assert_contains( 'fetchpriority="high" src="/later.jpg"', $html, 'the reported image is fetched first' );

}

/*
PRECONNECT
---------------------------------------------------------- */

function test_at_most_two_useful_preconnects(): void {

	$css  = '.bde-section-15-100{background-image:url(https://cdn.example.net/hero.jpg)}';
	$head = '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Inter">';
	$html = oa_hero_transform( oa_hero_page( $css, $head ) );

	oa_assert_contains( '<link rel="preconnect" href="https://cdn.example.net">', $html );
	oa_assert_contains( '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>', $html );
	oa_assert_same( 2, substr_count( $html, 'rel="preconnect"' ) );

	oa_test_reset();

	$html = oa_hero_transform( oa_hero_page( $css, '<link rel="preconnect" href="https://a.test"><link rel="preconnect" href="https://b.test">' ) );

	oa_assert_same( 2, substr_count( $html, 'rel="preconnect"' ), 'none added once the page has two' );

}
