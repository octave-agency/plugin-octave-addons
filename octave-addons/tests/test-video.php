<?php

/*
VIDEO DEFERRAL TESTS
-- Video sources stay parked until the loader activates them, posters and
-- player attributes survive, and a <noscript> copy keeps every video
-- playable without JavaScript
---------------------------------------------------------- */

function attachment_url_to_postid( $url ) {

	return 'https://example.com/wp-content/uploads/poster.jpg' === $url ? 41 : 0;

}

function wp_get_attachment_image_srcset( $id, $size = 'medium' ) {

	return 41 === $id ? 'https://example.com/wp-content/uploads/poster-768x433.jpg 768w, https://example.com/wp-content/uploads/poster.jpg 1280w' : false;

}

function wp_print_scripts( $handle = '' ) {

	$GLOBALS['oa_enqueued'][] = 'printed:' . $handle;

}

/** The parked markup before any <noscript> copy, with spacing collapsed. */
function oa_parked( string $html ): string {

	$parked = (string) strstr( $html, '<noscript>', true ) ?: $html;

	return (string) preg_replace( '/\s+(?=[\s>])/', '', $parked );

}

/*
TESTS
---------------------------------------------------------- */

function test_video_and_source_urls_stay_parked_until_activation(): void {

	$html   = Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_markup( '<video autoplay muted loop playsinline controls poster="/p.jpg"><source src="/hero.webm" type="video/webm"><source src="/hero.mp4" type="video/mp4"><track src="/captions.vtt" kind="captions"></video>' );
	$parked = oa_parked( $html );

	oa_assert_not_contains( ' src="/hero', $parked, 'no source the browser could fetch' );
	oa_assert_contains( '<source data-oa-src="/hero.webm" type="video/webm">', $parked );
	oa_assert_contains( '<source data-oa-src="/hero.mp4" type="video/mp4">', $parked );
	oa_assert_contains( 'src="/captions.vtt"', $parked, 'tracks keep working' );
	oa_assert_contains( 'preload="none"', $parked );
	oa_assert_contains( 'data-oa-autoplay', $parked );

	foreach ( [ 'muted', 'loop', 'playsinline', 'controls', 'poster="/p.jpg"' ] as $kept ) {

		oa_assert_contains( $kept, $parked, $kept );

	}

	$single = oa_parked( Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_markup( '<video src="/clip.mp4" controls></video>' ) );

	oa_assert_contains( 'data-oa-src="/clip.mp4"', $single );
	oa_assert_not_contains( ' src=', $single );

}

function test_without_javascript_the_original_video_plays(): void {

	$original = '<video src="/clip.mp4" autoplay muted playsinline></video>';
	$html     = Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_markup( $original );

	oa_assert_contains( '<noscript>' . $original . '</noscript>', $html );

	Octave_Addons_Module_Breakdance_Lazy_Load::print_late_script();

	ob_start();
	Octave_Addons_Module_Breakdance_Lazy_Load::print_late_script();
	$footer = (string) ob_get_clean();

	oa_assert_contains( '<noscript><style>video[data-oa-lazy-video]{display:none!important}</style></noscript>', $footer, 'the parked copy steps aside' );

}

function test_markup_processed_twice_is_not_parked_or_copied_again(): void {

	$once  = Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_markup( '<video src="/clip.mp4" autoplay></video>' );
	$twice = Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_markup( $once );

	oa_assert_same( $once, $twice );
	oa_assert_same( 1, substr_count( $twice, '<noscript>' ) );

}

function test_audio_sources_are_never_touched(): void {

	$audio = '<audio controls><source src="/song.mp3" type="audio/mpeg"></audio><video src="/v.mp4"></video>';
	$html  = Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_markup( $audio );

	oa_assert_contains( '<audio controls><source src="/song.mp3" type="audio/mpeg"></audio>', $html );

}

function test_breakdance_background_videos_are_parked(): void {

	$section = '<section class="bde-section-15-300"><div class="section-background-video"><video autoplay muted loop playsinline><source src="/bg.mp4" type="video/mp4"></video></div><div class="section-container"><video src="/other.mp4"></video></div></section>';
	$html    = Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_element_html( $section, [ 'data' => [ 'type' => 'EssentialElements\\Section' ] ] );

	oa_assert_contains( '<source data-oa-src="/bg.mp4" type="video/mp4">', oa_parked( $html ) );
	oa_assert_contains( '<video src="/other.mp4"></video>', $html, 'only the background video' );
	oa_assert_same( 1, substr_count( $html, '<noscript>' ) );
	oa_assert_contains( '<noscript><video autoplay muted loop playsinline><source src="/bg.mp4" type="video/mp4"></video></noscript>', $html );

}

function test_local_posters_list_their_wordpress_sizes(): void {

	$html = oa_parked( Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_markup( '<video src="/v.mp4" poster="https://example.com/wp-content/uploads/poster.jpg"></video>' ) );

	oa_assert_contains( 'data-oa-poster-srcset="https://example.com/wp-content/uploads/poster-768x433.jpg 768w, https://example.com/wp-content/uploads/poster.jpg 1280w"', $html );

	$html = oa_parked( Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_markup( '<video src="/v.mp4" poster="https://cdn.other.test/p.jpg"></video>' ) );

	oa_assert_not_contains( 'data-oa-poster-srcset', $html, 'remote posters left as they are' );

}

function test_first_video_poster_waits_when_the_hero_is_a_background(): void {

	$video = Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_markup( '<video src="/v.mp4" autoplay muted poster="/p.jpg"></video>' );
	$html  = oa_hero_transform( str_replace( '</body>', $video . '</body>', oa_hero_page() ) );

	oa_assert_contains( 'data-oa-poster="/p.jpg"', $html, 'not the LCP, so its poster loads near the viewport' );

}

function test_deferred_videos_are_reported_with_their_size(): void {

	@mkdir( WP_CONTENT_DIR . '/uploads', 0777, true );
	file_put_contents( WP_CONTENT_DIR . '/uploads/clip.mp4', str_repeat( 'x', 5000 ) );

	$token = Octave_Addons_Perf_Log::start_scan();

	$_GET[ Octave_Addons_Perf_Log::SCAN_ARG ] = $token;
	Octave_Addons_Perf_Log::maybe_begin_report();

	Octave_Addons_Module_Breakdance_Lazy_Load::filter_video_markup( '<video src="/wp-content/uploads/clip.mp4" autoplay></video>' );
	Octave_Addons_Perf_Log::save_report();

	$rows = Octave_Addons_Perf_Diagnostics::video_rows( (array) ( Octave_Addons_Perf_Log::read_scan( $token )['report']['videos'] ?? [] ) );

	oa_assert_contains( '1 videos wait until they are on screen', $rows[0] );
	oa_assert_contains( '/wp-content/uploads/clip.mp4', $rows[1] );

}
