<?php

/*
NEXT-GENERATION URL TESTS
-- Octave URL rewriting points pages at Imagify's WebP or AVIF copies
-- without server rules or picture tags: images, posters, preloads and
-- inline styles directly, and Breakdance backgrounds with a fallback
---------------------------------------------------------- */

/*
HELPERS
-- Imagify set to AVIF, the module on Octave delivery, and a few uploads,
-- each with or without an AVIF copy beside it
---------------------------------------------------------- */

function oa_nextgen( array $copies = [ 'hero.jpg', 'photo.jpg', 'photo-768x512.jpg', 'poster.jpg' ] ): void {

	oa_imagify();
	update_option( 'imagify_settings', [ 'optimization_format' => 'avif', 'display_nextgen' => 0 ] );
	oa_set_settings( 'performance-imagify', [ 'enabled' => true, 'delivery' => 'octave' ] );

	@mkdir( WP_CONTENT_DIR . '/uploads/2026/09', 0777, true );

	foreach ( [ 'hero.jpg', 'photo.jpg', 'photo-768x512.jpg', 'poster.jpg', 'plain.png' ] as $name ) {

		file_put_contents( WP_CONTENT_DIR . '/uploads/2026/09/' . $name, 'x' );

		if ( in_array( $name, $copies, true ) ) {

			file_put_contents( WP_CONTENT_DIR . '/uploads/2026/09/' . $name . '.avif', 'x' );

		} else {

			@unlink( WP_CONTENT_DIR . '/uploads/2026/09/' . $name . '.avif' );

		}

	}

}

function oa_nextgen_url( string $name ): string {

	return 'https://example.com/wp-content/uploads/2026/09/' . $name;

}

/*
DELIVERY CHOICE
---------------------------------------------------------- */

function test_octave_delivery_keeps_imagify_making_files_but_not_delivering_them(): void {

	oa_imagify();
	update_option( 'imagify_settings', [ 'display_nextgen' => 1, 'display_nextgen_method' => 'rewrite', 'optimization_format' => 'webp' ] );

	oa_imagify_save( [ 'format' => 'avif', 'delivery' => 'octave' ] );

	$saved = get_option( 'imagify_settings' );

	oa_assert_same( 'avif', $saved['optimization_format'], 'still creates AVIF copies' );
	oa_assert_same( 0, $saved['display_nextgen'], 'Imagify no longer rewrites the same images' );
	oa_assert( Octave_Addons_Perf_Imagify::octave_delivery() );
	oa_assert( Octave_Addons_Perf_Owners::octave_enabled( 'nextgen_images' ), 'Octave owns next-generation delivery' );
	oa_assert_same( 'octave', oa_module( 'performance-imagify' )->sanitize( [ 'delivery' => 'octave' ] )['delivery'] );

}

function test_nothing_is_rewritten_unless_octave_delivers(): void {

	oa_nextgen();
	oa_set_settings( 'performance-imagify', [ 'enabled' => true, 'delivery' => 'picture' ] );

	$html = oa_page( '<img src="' . oa_nextgen_url( 'photo.jpg' ) . '">' );

	oa_assert( ! Octave_Addons_Perf_Nextgen::active() );
	oa_assert_same( $html, Octave_Addons_Perf_Nextgen::transform( $html ) );

}

/*
PAGE MARKUP
---------------------------------------------------------- */

function test_images_point_at_their_copies_without_changing_tags(): void {

	oa_nextgen();

	$html = Octave_Addons_Perf_Nextgen::transform( oa_page(
		'<img class="bde-image" src="' . oa_nextgen_url( 'photo.jpg' ) . '?ver=2" srcset="' . oa_nextgen_url( 'photo.jpg' ) . ' 1200w, ' . oa_nextgen_url( 'photo-768x512.jpg' ) . ' 768w, ' . oa_nextgen_url( 'plain.png' ) . ' 300w" width="1200">'
		. '<img src="' . oa_nextgen_url( 'plain.png' ) . '">'
		. '<img src="https://cdn.other.test/photo.jpg">'
	) );

	oa_assert_contains( '<img class="bde-image" src="' . oa_nextgen_url( 'photo.jpg' ) . '.avif?ver=2"', $html, 'query kept after the new extension' );
	oa_assert_contains( 'srcset="' . oa_nextgen_url( 'photo.jpg' ) . '.avif 1200w, ' . oa_nextgen_url( 'photo-768x512.jpg' ) . '.avif 768w, ' . oa_nextgen_url( 'plain.png' ) . ' 300w"', $html, 'each candidate with a copy' );
	oa_assert_contains( '<img src="' . oa_nextgen_url( 'plain.png' ) . '">', $html, 'no copy, no change' );
	oa_assert_contains( 'https://cdn.other.test/photo.jpg"', $html, 'other hosts untouched' );
	oa_assert_not_contains( '<picture', $html, 'no wrappers' );

}

function test_picture_markup_is_left_to_itself(): void {

	oa_nextgen();

	$html = oa_page( '<picture><source type="image/avif" srcset="' . oa_nextgen_url( 'photo.jpg' ) . '.avif"><img src="' . oa_nextgen_url( 'photo.jpg' ) . '"></picture>' );

	oa_assert_same( $html, Octave_Addons_Perf_Nextgen::transform( $html ) );

}

function test_posters_inline_styles_and_preloads_point_at_copies(): void {

	oa_nextgen();

	$html = Octave_Addons_Perf_Nextgen::transform( '<!doctype html><html><head><meta charset="UTF-8">'
		. '<link rel="preload" as="image" href="' . oa_nextgen_url( 'hero.jpg' ) . '" fetchpriority="high" media="(min-width: 1024px)" data-oa-hero>'
		. '</head><body>'
		. '<video data-oa-poster="' . oa_nextgen_url( 'poster.jpg' ) . '" data-oa-poster-srcset="' . oa_nextgen_url( 'poster.jpg' ) . ' 1280w"></video>'
		. '<video poster="' . oa_nextgen_url( 'poster.jpg' ) . '"></video>'
		. '<div style="background-image: url(\'' . oa_nextgen_url( 'hero.jpg' ) . '\'); color: red">x</div>'
		. '</body></html>' );

	oa_assert_contains( 'href="' . oa_nextgen_url( 'hero.jpg' ) . '.avif"', $html );
	oa_assert_contains( 'type="image/avif"', $html, 'a browser without AVIF skips the preload' );
	oa_assert_contains( 'data-oa-poster="' . oa_nextgen_url( 'poster.jpg' ) . '.avif"', $html, 'parked poster' );
	oa_assert_contains( 'data-oa-poster-srcset="' . oa_nextgen_url( 'poster.jpg' ) . '.avif 1280w"', $html );
	oa_assert_contains( 'poster="' . oa_nextgen_url( 'poster.jpg' ) . '.avif"', $html );
	oa_assert_contains( oa_nextgen_url( 'hero.jpg' ) . '.avif&apos;); color: red', $html, 'inline style' );

}

/*
BREAKDANCE CSS
---------------------------------------------------------- */

function test_breakdance_backgrounds_gain_the_copy_with_the_original_as_fallback(): void {

	oa_nextgen();

	$css  = '.bde-section-15-100{background-image:url(../../2026/09/hero.jpg);background-size:cover}'
		. '@media (max-width:1023px){.bde-section-15-100{background:#000 url("' . oa_nextgen_url( 'photo-768x512.jpg' ) . '") center/cover !important}}'
		. '.a{background:linear-gradient(red,blue),url(' . oa_nextgen_url( 'photo.jpg' ) . ')}'
		. '.b{background-image:url(' . oa_nextgen_url( 'plain.png' ) . ')}'
		. '.c{background-image:linear-gradient(red,blue),url(' . oa_nextgen_url( 'photo.jpg' ) . ')}';
	$link = oa_bd_css( 'post-15.css', $css );
	$html = Octave_Addons_Perf_Nextgen::transform( '<!doctype html><html><head><link rel="stylesheet" href="' . $link . '?v=1"></head><body></body></html>' );

	preg_match( '#href="([^"]+)"#', $html, $match );

	oa_assert_contains( '/cache/octave-addons/min/nextgen/', $match[1], 'served from Octave\'s copy' );
	oa_assert_contains( '/breakdance/css/post-15.css', $match[1], 'same name, so bundling and inlining treat it as before' );

	$copy = (string) file_get_contents( Octave_Addons_Perf_Admin::local_path( $match[1] ) );

	oa_assert_contains( 'background-image:url(https://example.com/wp-content/uploads/2026/09/hero.jpg);background-image:image-set(url("https://example.com/wp-content/uploads/2026/09/hero.jpg.avif") type("image/avif"), url("https://example.com/wp-content/uploads/2026/09/hero.jpg") type("image/jpeg"));background-size:cover', $copy, 'fallback first, copy second, same rule' );
	oa_assert_contains( 'center/cover !important;background-image:image-set(url("' . oa_nextgen_url( 'photo-768x512.jpg' ) . '.avif") type("image/avif"), url("' . oa_nextgen_url( 'photo-768x512.jpg' ) . '") type("image/jpeg")) !important}}', $copy, 'shorthand with one image, importance kept' );
	oa_assert_contains( '.a{background:linear-gradient(red,blue),url(' . oa_nextgen_url( 'photo.jpg' ) . ')}', $copy, 'a layered shorthand is left alone' );
	oa_assert_contains( '.b{background-image:url(' . oa_nextgen_url( 'plain.png' ) . ')}', $copy, 'no copy, no change' );
	oa_assert_contains( 'background-image:linear-gradient(red,blue),image-set(', $copy, 'layered background-image keeps its gradient' );
	oa_assert( null !== Octave_Addons_Perf_Css::rules( $copy ), 'still valid CSS' );

}

function test_stylesheets_without_copies_keep_their_links(): void {

	oa_nextgen( [] );

	$link = oa_bd_css( 'post-16.css', '.x{background-image:url(' . oa_nextgen_url( 'photo.jpg' ) . ')}' );
	$html = '<!doctype html><html><head><link rel="stylesheet" href="' . $link . '"></head><body></body></html>';

	oa_assert_same( $html, Octave_Addons_Perf_Nextgen::transform( $html ) );

}

function test_the_delivery_test_checks_the_copy_itself_for_octave_delivery(): void {

	oa_nextgen();

	$GLOBALS['oa_attachments'] = [ 7 => [ WP_CONTENT_DIR . '/uploads/2026/09/photo.jpg', oa_nextgen_url( 'photo.jpg' ) ] ];
	$GLOBALS['oa_http']        = static function () {

		return oa_http_response( 200, '', 'image/avif' );

	};

	$result = Octave_Addons_Perf_Imagify::test_delivery();

	oa_assert( $result['ok'], $result['message'] );
	oa_assert_contains( '/photo.jpg.avif?oa_nextgen_test=', $GLOBALS['oa_http_log'][0]['url'], 'the copy, not the original URL' );
	oa_assert_contains( 'no server rules are needed', $result['message'] );

}

function test_divi_backgrounds_get_next_generation_copies(): void {

	oa_nextgen();
	oa_builders( [ 'divi' ] );

	$link = oa_divi_css( '15/et-core-unified-15.min.css', '.et_pb_section_0{background-image:url(' . oa_nextgen_url( 'hero.jpg' ) . ')}' );
	$html = Octave_Addons_Perf_Nextgen::transform( '<!doctype html><html><head><link rel="stylesheet" href="' . $link . '"></head><body></body></html>' );

	preg_match( '#href="([^"]+)"#', $html, $match );

	oa_assert_contains( '/min/nextgen/', $match[1] );
	oa_assert_contains( '/et-cache/15/et-core-unified-15.min.css', $match[1], 'folder and name kept' );
	oa_assert_contains( 'image-set(url("' . oa_nextgen_url( 'hero.jpg' ) . '.avif") type("image/avif")', (string) file_get_contents( Octave_Addons_Perf_Admin::local_path( $match[1] ) ) );

}
