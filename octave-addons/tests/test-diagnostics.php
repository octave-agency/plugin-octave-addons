<?php

/*
DIAGNOSTICS TESTS
-- Static delivery findings, cache status, scan detail and the read-only
-- Breakdance audit
---------------------------------------------------------- */

function oa_headers( array $headers, int $code = 200, string $body = '' ): array {

	return [ 'response' => [ 'code' => $code ], 'body' => $body, 'headers' => $headers ];

}

function test_static_delivery_reports_compression_caching_and_cors(): void {

	$GLOBALS['oa_http'] = static function ( $url ) {

		if ( false !== strpos( $url, '.css' ) ) {

			return oa_headers( [ 'content-type' => 'text/css', 'content-encoding' => 'br', 'cache-control' => 'public, max-age=31536000', 'cf-cache-status' => 'HIT' ] );

		}

		if ( false !== strpos( $url, '.js' ) ) {

			return oa_headers( [ 'content-type' => 'text/javascript', 'content-encoding' => 'gzip', 'cache-control' => 'max-age=3600' ] );

		}

		return oa_headers( [ 'content-type' => 'font/woff2' ] );

	};

	$lines = Octave_Addons_Perf_Diagnostics::static_delivery();

	oa_assert_contains( 'Brotli', $lines[0] );
	oa_assert_contains( 'cached 365 days', $lines[0] );
	oa_assert_contains( 'cf-cache-status: HIT', $lines[0] );
	oa_assert_contains( 'Gzip (no Brotli)', $lines[1] );
	oa_assert_contains( 'cached only 0 days', $lines[1] );
	oa_assert_contains( 'no CORS header', $lines[2] );
	oa_assert_contains( 'no browser caching header', $lines[2] );
	oa_assert_contains( 'no Imagify next-generation copy', $lines[3] );
	oa_assert_same( 'br, gzip', $GLOBALS['oa_http_log'][0]['args']['headers']['Accept-Encoding'] );
	oa_assert_same( false, $GLOBALS['oa_http_log'][0]['args']['decompress'], 'encoding seen as sent' );

}

function test_static_delivery_names_nginx_instead_of_htaccess(): void {

	$_SERVER['SERVER_SOFTWARE'] = 'nginx';

	$lines = Octave_Addons_Perf_Diagnostics::static_delivery();

	oa_assert_contains( 'Nginx', end( $lines ) );
	oa_assert_contains( 'could not be requested', $lines[0], 'transport errors reported, not hidden' );

	$_SERVER['SERVER_SOFTWARE'] = 'Apache';

}

function test_cache_status_reads_headers_and_signatures(): void {

	oa_assert_same( 'x-litespeed-cache: hit', Octave_Addons_Perf_Diagnostics::cache_status( oa_headers( [ 'x-litespeed-cache' => 'hit', 'cf-cache-status' => 'DYNAMIC' ] ) ) );
	oa_assert_same( 'WP Rocket cached page', Octave_Addons_Perf_Diagnostics::cache_status( oa_headers( [], 200, '<html></html><!-- This website is like a Rocket -->' ) ) );
	oa_assert_same( '', Octave_Addons_Perf_Diagnostics::cache_status( oa_headers( [] ) ) );

}

function test_scan_details_cover_timings_assets_lcp_and_ownership(): void {

	Octave_Addons_Perf_Lcp::store( '/about/', [
		'm' => [ 'kind' => 'img', 'url' => 'https://example.com/hero.jpg', 'w' => 2400, 'h' => 1600, 'rw' => 390, 'rh' => 260, 'bytes' => 300000, 'type' => 'image/jpeg', 'time' => time() ],
	] );

	$scan = [ 'report' => [
		'summary' => [ 'timings' => [ 'oa-media' => 1.5, 'oa-total' => 2.25 ], 'inlined_css_bytes' => 2048, 'assets' => [ 'octave' => [ 'octave-addons-lazy-video' ], 'breakdance' => [ 'breakdance-global' ] ], 'preloaded_fonts' => [ '/f.woff2' ] ],
		'delay'   => [ [ 'action' => 'delayed' ], [ 'action' => 'kept' ] ],
		'media'   => [ [ 'action' => 'lazy' ], [ 'action' => 'lazy' ], [ 'action' => 'skipped' ] ],
	] ];

	$details = Octave_Addons_Perf_Diagnostics::scan_details( 'https://example.com/about/', $scan, 320.4, oa_headers( [ 'x-cache' => 'MISS' ] ), 85.2 );
	$text    = wp_json_encode( $details );

	foreach ( [ 'Uncached response (scan): 320 ms', 'x-cache: MISS', 'Media processing: 1.50 ms', 'Total HTML processing: 2.25 ms', 'Inlined CSS: 2048 B', 'Delayed scripts: 1', 'Lazy-loaded media: 2', 'octave-addons-lazy-video', 'breakdance-global', '/f.woff2', 'hero.jpg (JPEG, 2400', 'Larger screens: not reported yet', 'Imagify', 'None: each feature has at most one owner.' ] as $needle ) {

		oa_assert_contains( $needle, str_replace( [ '\/', '×' ], [ '/', 'x' ], $text ), $needle );

	}

	oa_assert_not_contains( WP_CONTENT_DIR, $text, 'no server paths' );

}

function test_breakdance_audit_is_read_only_and_links_to_breakdance(): void {

	if ( ! function_exists( 'Breakdance\Data\get_global_option' ) ) {

		// A namespaced stand-in cannot be declared beside global code in one file; the string is a fixed literal.
		eval( 'namespace Breakdance\Data; function get_global_option( $name ) { $GLOBALS["oa_bd_reads"][] = $name; return [ "wp-emoji", "rsd-links" ]; }' );

	}

	$rows = Octave_Addons_Perf_Diagnostics::breakdance_audit();
	$map  = array_column( $rows, 'status', 'label' );

	oa_assert_same( 'Removed by Breakdance', $map['Emoji assets'] );
	oa_assert_same( 'Left in place', $map['Gutenberg block CSS'] );
	oa_assert_same( 'Added by WordPress', $map['Image responsive attributes'] );
	oa_assert_same( 'None', $map['Custom/local fonts'] );
	oa_assert_same( [ 'breakdance_settings_bloat_eliminator' ], $GLOBALS['oa_bd_reads'], 'one read, no writes' );
	oa_assert_contains( 'page=breakdance_settings&tab=bloat_eliminator', Octave_Addons_Perf_Diagnostics::breakdance_settings_url() );

}
