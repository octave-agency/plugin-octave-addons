<?php

/*
CACHE INVALIDATION TESTS
-- Breakdance documents and global design changes, and the protections
-- around the public LCP report endpoint
---------------------------------------------------------- */

function oa_full_purges(): int {

	return (int) did_action( 'octave_addons_perf_purged_all' );

}

/*
BREAKDANCE
---------------------------------------------------------- */

function test_breakdance_header_change_purges_everything_once_after_the_request(): void {

	Octave_Addons_Perf_Cache::register_invalidation();

	$GLOBALS['oa_posts'] = [ 5 => [ 'breakdance_header', 'publish' ] ];

	update_option( 'octave_addons_perf_fonts_detected', [ 'urls' => [ '/f.woff2' ], 'time' => time() ] );

	$generation = Octave_Addons_Perf_Lcp::generation();

	do_action( 'breakdance_after_save_document', 5 );
	do_action( 'breakdance_option_updated_global_settings_json_string', '{}' );

	oa_assert_same( 0, oa_full_purges(), 'nothing until the request ends' );

	do_action( 'shutdown' );

	oa_assert_same( 1, oa_full_purges(), 'one purge for both changes' );
	oa_assert_same( $generation + 1, Octave_Addons_Perf_Lcp::generation(), 'learned LCP records retired' );
	oa_assert_same( false, get_option( 'octave_addons_perf_fonts_detected' ), 'font detection retired' );

	Octave_Addons_Perf_Cache::queue_full_purge( 'breakdance' );
	Octave_Addons_Perf_Cache::run_queued_full_purge();

	oa_assert_same( 1, oa_full_purges(), 'debounced for a minute' );

}

function test_breakdance_global_styles_purge_everything(): void {

	Octave_Addons_Perf_Cache::register_invalidation();

	foreach ( [ 'breakdance_classes_json_string', 'presets_json_string', 'variables_json_string' ] as $field ) {

		oa_test_reset();
		Octave_Addons_Perf_Cache::register_invalidation();

		do_action( 'breakdance_option_updated_' . $field, '{}' );
		do_action( 'shutdown' );

		oa_assert_same( 1, oa_full_purges(), $field );

	}

}

function test_breakdance_font_and_cache_ajax_needs_an_editor(): void {

	Octave_Addons_Perf_Cache::register_invalidation();

	$GLOBALS['oa_flags']['ajax'] = true;

	do_action( 'wp_ajax_breakdance_save_font_families' );
	do_action( 'shutdown' );

	oa_assert_same( 0, oa_full_purges(), 'a subscriber cannot trigger purges' );

	$GLOBALS['oa_caps'] = [ 'edit_posts' ];

	do_action( 'wp_ajax_breakdance_regenerate_global_settings_cache' );
	do_action( 'shutdown' );

	oa_assert_same( 1, oa_full_purges() );

}

function test_ordinary_breakdance_page_only_expires_its_own_lcp_record(): void {

	Octave_Addons_Perf_Cache::register_invalidation();

	$GLOBALS['oa_posts']      = [ 9 => [ 'page', 'publish' ] ];
	$GLOBALS['oa_permalinks'] = [ 9 => 'https://example.com/about/' ];

	Octave_Addons_Perf_Lcp::store( '/about/', [ 'm' => [ 'kind' => 'img', 'url' => 'https://example.com/a.jpg', 'time' => time() ] ] );
	Octave_Addons_Perf_Lcp::store( '/other/', [ 'm' => [ 'kind' => 'img', 'url' => 'https://example.com/b.jpg', 'time' => time() ] ] );

	do_action( 'breakdance_after_save_document', 9 );
	do_action( 'shutdown' );

	oa_assert_same( [], Octave_Addons_Perf_Lcp::entry( '/about/' ), 'its record expired' );
	oa_assert( ! empty( Octave_Addons_Perf_Lcp::entry( '/other/' ) ), 'others kept' );
	oa_assert_same( 0, oa_full_purges(), 'no full purge for one page' );

}

function test_cloudflare_purges_everything_for_breakdance_only_when_allowed(): void {

	oa_cloudflare( [ 'purge_on_full' => true ] );
	oa_cloudflare_ok();

	oa_assert_same( 'success', Octave_Addons_Perf_Cache::purge_all( 'all', 'breakdance' )['cloudflare']['status'] );
	oa_assert_same( 'skipped', Octave_Addons_Perf_Cache::purge_all( 'all', 'imagify' )['cloudflare']['status'], 'other automatic purges never empty the zone' );

}

/*
LCP ENDPOINT
---------------------------------------------------------- */

function test_lcp_endpoint_is_rate_limited_per_visitor(): void {

	$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

	for ( $i = 1; $i <= Octave_Addons_Perf_Lcp::RATE_PER_IP; $i++ ) {

		oa_assert( oa_lcp_report( '/p' . $i . '/', 'm', 'none', '' )->success, 'report ' . $i );

	}

	oa_assert_same( 429, oa_lcp_report( '/p-next/', 'm', 'none', '' )->status, 'over the limit' );

	$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

	oa_assert( oa_lcp_report( '/p-next/', 'm', 'none', '' )->success, 'another visitor still reports' );

}

function test_forged_lcp_reports_cannot_repeat_purges(): void {

	$purges = 0;

	add_filter( 'octave_addons_perf_purge_url_layers', static function ( $report ) use ( &$purges ) {

		$purges++;

		return $report;

	} );

	$_SERVER['REMOTE_ADDR'] = '198.51.100.1';

	oa_lcp_report( '/target/', 'm', 'img', 'https://example.com/one.jpg' );

	// Make the record stale again, as a forger waiting a week would.
	Octave_Addons_Perf_Lcp::store( '/target/', [ 'm' => [ 'kind' => 'img', 'url' => 'https://example.com/one.jpg', 'time' => 1 ] ] );

	oa_lcp_report( '/target/', 'm', 'img', 'https://example.com/two.jpg' );

	oa_assert_same( 1, $purges, 'one purge per path per hour' );
	oa_assert_same( 'https://example.com/two.jpg', Octave_Addons_Perf_Lcp::entry( '/target/' )['m']['url'], 'the record itself still updates' );

}

function test_lcp_records_keep_measurements_and_flag_problems(): void {

	$_POST = [ 'path' => '/hero/', 'device' => 'd', 'kind' => 'img', 'url' => 'https://example.com/wp-content/uploads/hero.jpg', 'w' => '3000', 'h' => '2000', 'rw' => '1200', 'rh' => '800', 'bytes' => '450000', 'type' => 'image/jpeg', 'evil' => '<script>' ];

	oa_json_call( [ 'Octave_Addons_Perf_Lcp', 'ajax_report' ] );

	$record = Octave_Addons_Perf_Lcp::entry( '/hero/' )['d'];

	oa_assert_same( 3000, $record['w'] );
	oa_assert_same( 1200, $record['rw'] );
	oa_assert_same( 'image/jpeg', $record['type'] );
	oa_assert( ! isset( $record['evil'] ) );
	oa_assert_same( 3, count( Octave_Addons_Perf_Lcp::warnings( $record ) ), 'format, weight and oversize' );
	oa_assert_same( 'AVIF', Octave_Addons_Perf_Lcp::format( [ 'type' => 'image/avif', 'url' => 'https://example.com/a.jpg' ] ), 'rewrite delivery seen through the MIME type' );

}

function test_imagify_picture_sources_match_their_original_image(): void {

	oa_assert( Octave_Addons_Perf_Lcp::same_file( 'https://example.com/wp-content/uploads/photo.jpg.webp', '/wp-content/uploads/photo.jpg' ), 'picture source' );
	oa_assert( Octave_Addons_Perf_Lcp::same_file( 'https://cdn.test/wp-content/uploads/photo.png.avif?v=1', '/wp-content/uploads/photo.png' ) );
	oa_assert( ! Octave_Addons_Perf_Lcp::same_file( '/wp-content/uploads/photo.webp', '/wp-content/uploads/photo.jpg' ), 'a real WebP upload is its own file' );

}

function test_competing_image_preloads_are_not_added(): void {

	$entry = [ 'd' => [ 'kind' => 'bg', 'url' => 'https://example.com/bg.jpg', 'time' => time() ] ];
	$html  = '<!doctype html><html><head><link rel="preload" as="image" href="/other.jpg" fetchpriority="high"></head><body></body></html>';

	oa_assert_same( '', Octave_Addons_Perf_Lcp::preload_markup( $entry, $html ) );

}

function test_an_image_never_keeps_both_lazy_and_high_priority(): void {

	$html = oa_media()->transform( oa_page( '<img src="/hero.jpg" fetchpriority="high" loading="lazy" width="1200">' ) );

	oa_assert_contains( '<img src="/hero.jpg" fetchpriority="high"  width="1200">', $html );
	oa_assert_not_contains( 'loading="lazy"', $html );

}
