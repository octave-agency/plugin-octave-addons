<?php

/*
FONT TESTS
-- Detected preloads, Google Fonts host validation, CSS rewriting, refresh
-- failure handling and the frontend rewrite
---------------------------------------------------------- */

const OA_GOOGLE_CSS = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap';

function oa_fonts( array $values = [] ): Octave_Addons_Module_Performance_Fonts {

	$module = oa_module( 'performance-fonts' );

	$module->run( oa_set_settings( 'performance-fonts', array_merge( [ 'enabled' => true ], $values ) ) );

	return $module;

}

/*
GOOGLE HTTP
-- A fake Google: one stylesheet with two WOFF2 files
---------------------------------------------------------- */

function oa_fake_google( bool $up = true ): void {

	$GLOBALS['oa_http'] = static function ( string $url ) use ( $up ) {

		if ( ! $up ) {

			return new WP_Error( 'down', 'Google is unavailable' );

		}

		if ( 0 === strpos( $url, 'https://fonts.googleapis.com/' ) ) {

			return oa_http_response( 200, "@font-face {\n  font-family: 'Inter';\n  font-style: normal;\n  font-weight: 400;\n  src: url(https://fonts.gstatic.com/s/inter/v1/a.woff2) format('woff2');\n  unicode-range: U+0000-00FF;\n}\n@font-face {\n  font-family: 'Inter';\n  font-weight: 700;\n  font-display: optional;\n  src: url(https://fonts.gstatic.com/s/inter/v1/b.woff2) format('woff2');\n}\n", 'text/css; charset=utf-8' );

		}

		if ( 0 === strpos( $url, 'https://fonts.gstatic.com/' ) ) {

			return oa_http_response( 200, 'wOF2' . str_repeat( 'x', 64 ), 'font/woff2' );

		}

		return oa_http_response( 404, '' );

	};

}

/*
PRELOAD
---------------------------------------------------------- */

function oa_detected_fonts( array $urls, int $age = 0, string $family = 'other' ): void {

	$all = get_option( Octave_Addons_Module_Performance_Fonts::DETECTED_OPTION, [] );

	$all['families'][ $family ] = [ 'urls' => $urls, 'time' => time() - $age ];

	update_option( Octave_Addons_Module_Performance_Fonts::DETECTED_OPTION, $all );

}

function test_font_preloads_are_deduplicated_with_correct_attributes(): void {

	oa_detected_fonts( [ '/wp-content/fonts/body.woff2', 'https://example.com/fonts/head.woff?v=3' ] );

	$module = oa_fonts( [ 'preload_max' => 3 ] );

	add_filter( 'octave_addons_perf_preload_fonts', static function ( $urls ) {

		$urls[] = '/wp-content/fonts/body.woff2';
		$urls[] = '/wp-content/fonts/legacy.ttf';

		return $urls;

	} );

	$resources = $module->filter_preload_resources( [ [ 'href' => 'https://example.com/fonts/head.woff?v=3', 'as' => 'font' ] ] );

	oa_assert_same( 2, count( $resources ), 'no duplicates, no TTF' );
	oa_assert_same( [
		'href'        => '/wp-content/fonts/body.woff2',
		'as'          => 'font',
		'type'        => 'font/woff2',
		'crossorigin' => 'anonymous',
	], $resources[1] );

	ob_start();
	$module->print_preload_tags();
	$tags = (string) ob_get_clean();

	oa_assert_same( 1, substr_count( $tags, 'body.woff2' ), 'fallback output deduplicated' );
	oa_assert_contains( 'href="https://example.com/fonts/head.woff?v=3" as="font" type="font/woff" crossorigin="anonymous"', $tags, 'query string kept' );

}

function test_stale_detection_skips_preloads_and_loads_the_detector(): void {

	oa_detected_fonts( [ '/wp-content/fonts/body.woff2' ], 2 * DAY_IN_SECONDS );

	$module = oa_fonts();

	oa_assert_same( [], $module->preload_list(), 'stale list not preloaded' );

	Octave_Addons_Module_Performance_Fonts::enqueue_detector();

	oa_assert( in_array( 'octave-addons-font-detect', $GLOBALS['oa_enqueued'] ?? [], true ), 'detector enqueued' );

	oa_detected_fonts( [ '/wp-content/fonts/body.woff2' ] );
	$GLOBALS['oa_enqueued'] = [];

	Octave_Addons_Module_Performance_Fonts::enqueue_detector();

	oa_assert_same( [], $GLOBALS['oa_enqueued'], 'fresh list, no detector' );

}

function test_detection_report_keeps_three_same_origin_font_files(): void {

	$_POST = [ 'urls' => [
		'https://example.com/wp-content/fonts/a.woff2',
		'https://evil.test/x.woff2',
		'/wp-content/fonts/b.woff',
		'/wp-content/fonts/b.woff',
		'/wp-content/fonts/c.ttf',
		'javascript:alert(1)',
		'/wp-content/fonts/d.woff2',
		'/wp-content/fonts/e.woff2',
	] ];

	oa_json_call( [ 'Octave_Addons_Module_Performance_Fonts', 'ajax_detect' ] );

	oa_assert_same( [ 'https://example.com/wp-content/fonts/a.woff2', '/wp-content/fonts/b.woff', '/wp-content/fonts/d.woff2' ], Octave_Addons_Module_Performance_Fonts::detected()['urls'] );

	$_POST = [ 'urls' => [ '/wp-content/fonts/other.woff2' ] ];

	oa_json_call( [ 'Octave_Addons_Module_Performance_Fonts', 'ajax_detect' ] );

	oa_assert_same( 3, count( Octave_Addons_Module_Performance_Fonts::detected()['urls'] ), 'fresh list is not replaced' );

}

/*
HOST VALIDATION
---------------------------------------------------------- */

function test_only_google_font_hosts_are_accepted(): void {

	$valid = [ OA_GOOGLE_CSS, '//fonts.googleapis.com/css?family=Roboto', 'http://fonts.googleapis.com/css?family=Roboto', 'https://fonts.googleapis.com/icon?family=Material+Icons' ];

	foreach ( $valid as $url ) {

		oa_assert( Octave_Addons_Perf_Google_Fonts::is_stylesheet_url( $url ), $url );

	}

	$invalid = [
		'https://fonts.googleapis.com.evil.test/css2?family=Inter',
		'https://fonts.googleapis.com@evil.test/css2?family=Inter',
		'https://evil.test/css2?family=fonts.googleapis.com',
		'https://fonts.googleapis.com/../admin',
		'https://use.typekit.net/abc.css',
		'file:///etc/passwd',
	];

	foreach ( $invalid as $url ) {

		oa_assert( ! Octave_Addons_Perf_Google_Fonts::is_stylesheet_url( $url ), $url );

	}

	oa_assert( Octave_Addons_Perf_Google_Fonts::is_font_file_url( 'https://fonts.gstatic.com/s/inter/v1/a.woff2' ) );
	oa_assert( ! Octave_Addons_Perf_Google_Fonts::is_font_file_url( 'https://fonts.gstatic.com:8443/s/a.woff2' ), 'port' );
	oa_assert( ! Octave_Addons_Perf_Google_Fonts::is_font_file_url( 'https://user@fonts.gstatic.com/s/a.woff2' ), 'userinfo' );
	oa_assert( ! Octave_Addons_Perf_Google_Fonts::is_font_file_url( 'http://fonts.gstatic.com/s/a.woff2' ), 'plain http' );
	oa_assert( ! Octave_Addons_Perf_Google_Fonts::is_font_file_url( 'https://fonts.gstatic.com/s/a.php' ), 'extension' );

}

/*
REWRITING AND CACHING
---------------------------------------------------------- */

function test_google_css_is_rewritten_with_font_display(): void {

	$css = "@font-face{font-family:'Inter';src:url(https://fonts.gstatic.com/a.woff2) format('woff2');unicode-range:U+0000-00FF}@font-face{font-family:'Inter';font-display:block;src:url(https://fonts.gstatic.com/b.woff2)}";
	$out = Octave_Addons_Perf_Google_Fonts::rewrite_css( $css, [ 'https://fonts.gstatic.com/a.woff2' => 'a1.woff2', 'https://fonts.gstatic.com/b.woff2' => 'b1.woff2' ], 'swap' );

	oa_assert_contains( 'src:url(a1.woff2) format(\'woff2\');unicode-range:U+0000-00FF', $out );
	oa_assert_contains( 'font-display: swap;', $out );
	oa_assert_same( 1, substr_count( $out, 'font-display: swap' ), 'existing value kept' );
	oa_assert_contains( 'font-display:block', $out );
	oa_assert_same( [ 'Inter' ], Octave_Addons_Perf_Google_Fonts::families( $css ) );

}

function test_fetch_caches_fonts_and_requests_only_google_over_https(): void {

	oa_fonts( [ 'self_host' => true ] );
	oa_fake_google();

	$result = Octave_Addons_Perf_Google_Fonts::fetch( OA_GOOGLE_CSS );

	oa_assert( $result['ok'], $result['message'] );

	foreach ( $GLOBALS['oa_http_log'] as $request ) {

		oa_assert( preg_match( '#^https://fonts\.(googleapis|gstatic)\.com/#', $request['url'] ) === 1, $request['url'] );
		oa_assert_same( 0, $request['args']['redirection'], 'no redirects' );

	}

	$local = Octave_Addons_Perf_Google_Fonts::local_url( OA_GOOGLE_CSS );
	$path  = str_replace( 'https://example.com/wp-content', WP_CONTENT_DIR, strtok( $local, '?' ) );
	$css   = (string) file_get_contents( $path );

	oa_assert_contains( '/cache/octave-addons/fonts/', $local );
	oa_assert_not_contains( 'gstatic', $css, 'no remote font URLs left' );
	oa_assert_contains( 'unicode-range: U+0000-00FF', $css );
	oa_assert_contains( 'font-display: optional', $css, 'source value kept' );
	oa_assert_same( 2, count( Octave_Addons_Perf_Google_Fonts::cached_files() ) );

}

function test_failed_refresh_keeps_previous_cache(): void {

	oa_fonts( [ 'self_host' => true ] );
	oa_fake_google();
	Octave_Addons_Perf_Google_Fonts::fetch( OA_GOOGLE_CSS );

	$before = Octave_Addons_Perf_Google_Fonts::local_url( OA_GOOGLE_CSS );

	oa_fake_google( false );

	$results = Octave_Addons_Perf_Google_Fonts::refresh();

	oa_assert( ! $results[ Octave_Addons_Perf_Google_Fonts::normalize( OA_GOOGLE_CSS ) ]['ok'] );
	oa_assert_same( $before, Octave_Addons_Perf_Google_Fonts::local_url( OA_GOOGLE_CSS ), 'previous copy still served' );
	oa_assert( file_exists( str_replace( 'https://example.com/wp-content', WP_CONTENT_DIR, strtok( $before, '?' ) ) ) );
	oa_assert_same( 2, Octave_Addons_Perf_Store::size( 'fonts' )['files'] - 1, 'both font files still present' );

}

function test_font_file_urls_stay_stable_across_refreshes(): void {

	oa_fonts( [ 'self_host' => true ] );
	oa_fake_google();
	Octave_Addons_Perf_Google_Fonts::fetch( OA_GOOGLE_CSS );

	$before = array_column( Octave_Addons_Perf_Google_Fonts::cached_files(), 'url' );

	Octave_Addons_Perf_Google_Fonts::refresh();

	oa_assert_same( $before, array_column( Octave_Addons_Perf_Google_Fonts::cached_files(), 'url' ), 'preload URLs survive a refresh' );
	oa_assert_same( 3, Octave_Addons_Perf_Store::size( 'fonts' )['files'], 'two fonts and one stylesheet, no leftovers' );

}

function test_invalid_downloads_are_rejected_without_partial_cache(): void {

	oa_fonts( [ 'self_host' => true ] );

	$GLOBALS['oa_http'] = static function ( string $url ) {

		if ( false !== strpos( $url, 'googleapis' ) ) {

			return oa_http_response( 200, '@font-face{src:url(https://fonts.gstatic.com/a.woff2)}', 'text/css' );

		}

		return oa_http_response( 200, '<?php evil();', 'font/woff2' );

	};

	$result = Octave_Addons_Perf_Google_Fonts::fetch( OA_GOOGLE_CSS );

	oa_assert( ! $result['ok'], 'bad signature rejected' );
	oa_assert_same( 0, Octave_Addons_Perf_Store::size( 'fonts' )['files'], 'nothing left behind' );

	$GLOBALS['oa_http'] = static function () {

		return oa_http_response( 200, '@font-face{src:url(https://evil.test/a.woff2)}', 'text/css' );

	};

	oa_assert( ! Octave_Addons_Perf_Google_Fonts::fetch( OA_GOOGLE_CSS )['ok'], 'foreign font host rejected' );

}

/*
FRONTEND
---------------------------------------------------------- */

function test_frontend_uses_local_copy_and_drops_google_hints(): void {

	$module = oa_fonts( [ 'self_host' => true ] );
	$page   = oa_page( '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="' . esc_attr( OA_GOOGLE_CSS ) . '">' );

	oa_fake_google( false );

	oa_assert_same( $page, $module->transform( $page ), 'uncached: Google kept' );
	oa_assert( in_array( Octave_Addons_Perf_Google_Fonts::normalize( OA_GOOGLE_CSS ), get_option( Octave_Addons_Perf_Google_Fonts::QUEUE_OPTION ), true ), 'queued for cron' );
	oa_assert_same( [], $GLOBALS['oa_http_log'], 'no download during the page request' );

	oa_fake_google();
	Octave_Addons_Perf_Google_Fonts::process_queue();

	$html = $module->transform( $page );

	oa_assert_not_contains( 'fonts.googleapis.com', preg_replace( '/data-oa-removed-href="[^"]*"/', '', $html ) );
	oa_assert_not_contains( ' href="https://fonts.gstatic.com"', $html, 'preconnect disabled' );
	oa_assert_contains( '/cache/octave-addons/fonts/', $html );

}

/*
DETECTION
-- Builders load Google Fonts in more than one way; each must be found
---------------------------------------------------------- */

const OA_WEBFONT_SCRIPT = "<script>WebFont.load({ google: { families: ['Poppins:400,700:latin,latin-ext', 'Open Sans:400'] } });</script>";
const OA_WEBFONT_CSS    = 'https://fonts.googleapis.com/css?family=Poppins:400,700|Open+Sans:400&subset=latin,latin-ext';

function test_google_fonts_found_in_links_imports_and_webfont_loader(): void {

	$html = '<link rel="stylesheet" href="' . esc_attr( OA_GOOGLE_CSS ) . '">'
		. "<style>@import url('https://fonts.googleapis.com/css2?family=Lora&display=swap');</style>"
		. OA_WEBFONT_SCRIPT;

	$sources = Octave_Addons_Perf_Google_Fonts::sources_in( $html );

	oa_assert_same( [ OA_GOOGLE_CSS, 'https://fonts.googleapis.com/css2?family=Lora&display=swap', OA_WEBFONT_CSS ], $sources );

}

function test_webfont_loader_and_import_use_local_copies(): void {

	$module = oa_fonts( [ 'self_host' => true ] );
	$page   = oa_page( "<style>@import url('https://fonts.googleapis.com/css2?family=Lora&display=swap');</style>" . OA_WEBFONT_SCRIPT );

	oa_fake_google();

	oa_assert_same( $page, $module->transform( $page ), 'uncached: Google kept' );

	Octave_Addons_Perf_Google_Fonts::process_queue();

	$html = $module->transform( $page );

	oa_assert_not_contains( 'fonts.googleapis.com', $html );
	oa_assert_contains( 'custom: {"families":["Poppins","Open Sans"],"urls":["https://example.com/wp-content/cache/octave-addons/fonts/', $html );
	oa_assert_contains( '@import url("https://example.com/wp-content/cache/octave-addons/fonts/', $html );

}

function test_manual_refresh_discovers_fonts_on_the_home_page(): void {

	$GLOBALS['oa_http'] = static function ( string $url ) {

		if ( 0 === strpos( $url, 'https://example.com/' ) ) {

			return oa_http_response( 200, '<!DOCTYPE html><html><head>' . OA_WEBFONT_SCRIPT . '</head></html>', 'text/html' );

		}

		if ( 0 === strpos( $url, 'https://fonts.googleapis.com/' ) ) {

			return oa_http_response( 200, "@font-face { font-family: 'Poppins'; src: url(https://fonts.gstatic.com/s/p/a.woff2) format('woff2'); }", 'text/css' );

		}

		return oa_http_response( 200, 'wOF2' . str_repeat( 'x', 64 ), 'font/woff2' );

	};

	$results = Octave_Addons_Perf_Google_Fonts::refresh();

	oa_assert( ! empty( $results[ OA_WEBFONT_CSS ]['ok'] ), 'found and cached without a visitor' );
	oa_assert_contains( Octave_Addons_Perf_Context::BYPASS_ARG . '=', $GLOBALS['oa_http_log'][0]['url'], 'home page fetched unoptimised' );

}

function test_manual_refresh_drops_fonts_no_longer_on_the_home_page(): void {

	oa_fonts( [ 'self_host' => true ] );
	oa_fake_google();
	Octave_Addons_Perf_Google_Fonts::fetch( OA_GOOGLE_CSS );

	$old  = Octave_Addons_Perf_Google_Fonts::key( OA_GOOGLE_CSS );
	$fake = $GLOBALS['oa_http'];

	$GLOBALS['oa_http'] = static function ( string $url ) use ( $fake ) {

		if ( 0 === strpos( $url, 'https://example.com/' ) ) {

			return oa_http_response( 200, '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lora&display=swap">', 'text/html' );

		}

		return $fake( $url );

	};

	Octave_Addons_Perf_Google_Fonts::refresh();

	$manifest = Octave_Addons_Perf_Google_Fonts::manifest();

	oa_assert( ! isset( $manifest[ $old ] ), 'old stylesheet forgotten' );
	oa_assert( ! is_dir( Octave_Addons_Perf_Store::dir( 'fonts/' . $old ) ), 'old folder deleted' );
	oa_assert_same( 1, count( $manifest ), 'only the current stylesheet kept' );

}

/*
PRELOAD FALLBACK
---------------------------------------------------------- */

function test_unicode_ranges_are_checked_for_latin(): void {

	oa_assert( Octave_Addons_Perf_Google_Fonts::covers_latin( 'U+0000-00FF, U+0131' ) );
	oa_assert( ! Octave_Addons_Perf_Google_Fonts::covers_latin( 'U+0460-052F, U+1C80-1C8A' ), 'cyrillic' );
	oa_assert( ! Octave_Addons_Perf_Google_Fonts::covers_latin( 'U+0100-02BA' ), 'latin-ext' );

}

function test_self_hosted_latin_fonts_are_preloaded_until_detection_reports(): void {

	oa_fake_google();
	Octave_Addons_Perf_Google_Fonts::fetch( OA_GOOGLE_CSS );
	oa_detected_fonts( [] );

	$urls = oa_fonts( [ 'self_host' => true, 'preload_max' => 3 ] )->preload_list();

	oa_assert_same( 2, count( $urls ), 'both Latin files' );
	oa_assert_same( 1, count( oa_fonts( [ 'self_host' => true ] )->preload_list() ), 'one face by default' );
	oa_assert_contains( '/cache/octave-addons/fonts/', $urls[0] );
	oa_assert_same( [], oa_fonts()->preload_list(), 'not without self-hosting' );

	oa_detected_fonts( [ '/wp-content/fonts/own.woff2' ] );

	oa_assert_same( [ '/wp-content/fonts/own.woff2' ], oa_fonts( [ 'self_host' => true ] )->preload_list(), 'detection wins' );

}

function test_font_preloads_are_scoped_to_the_template_family(): void {

	oa_detected_fonts( [ '/wp-content/fonts/display.woff2' ], 0, 'front' );
	oa_detected_fonts( [ '/wp-content/fonts/body.woff2' ], 0, 'single-post' );

	$GLOBALS['oa_flags']['front_page'] = true;

	oa_assert_same( [ '/wp-content/fonts/display.woff2' ], oa_fonts()->preload_list() );

	$GLOBALS['oa_flags']['front_page'] = false;
	$GLOBALS['oa_flags']['archive']    = true;

	oa_assert_same( [], oa_fonts()->preload_list(), 'archives have no record yet' );

	$_POST = [ 'family' => 'archive', 'urls' => [ '/wp-content/fonts/list.woff2' ] ];

	oa_json_call( [ 'Octave_Addons_Module_Performance_Fonts', 'ajax_detect' ] );

	oa_assert_same( [ '/wp-content/fonts/list.woff2' ], oa_fonts()->preload_list(), 'reported for archives only' );
	oa_assert_same( [ '/wp-content/fonts/display.woff2' ], Octave_Addons_Module_Performance_Fonts::detected( 'front' )['urls'], 'other families untouched' );

	$_POST = [ 'family' => str_repeat( 'x', 60 ), 'urls' => [ '/wp-content/fonts/x.woff2' ] ];

	oa_json_call( [ 'Octave_Addons_Module_Performance_Fonts', 'ajax_detect' ] );

	oa_assert( ! empty( Octave_Addons_Module_Performance_Fonts::detected( 'other' ) ), 'an invalid family is filed as other' );

}

function test_woff2_is_preferred_and_one_face_is_preloaded_by_default(): void {

	oa_detected_fonts( [ '/wp-content/fonts/body.woff', '/wp-content/fonts/head.woff2', '/wp-content/fonts/body.woff2' ] );

	oa_assert_same( [ '/wp-content/fonts/head.woff2' ], oa_fonts()->preload_list(), 'one essential face' );
	oa_assert_same( [ '/wp-content/fonts/head.woff2', '/wp-content/fonts/body.woff2' ], oa_fonts( [ 'preload_max' => 3 ] )->preload_list(), 'WOFF dropped beside its WOFF2' );
	oa_assert_same( 3, oa_module( 'performance-fonts' )->sanitize( [ 'preload_max' => '9' ] )['preload_max'] );

}

function test_breakdance_custom_fonts_count_as_self_hosted(): void {

	oa_assert_contains( 'Breakdance custom font', Octave_Addons_Module_Performance_Fonts::font_origin( 'https://example.com/wp-content/uploads/breakdance/fonts/inter.woff2' ) );
	oa_assert( Octave_Addons_Perf::is_same_origin( 'https://example.com/wp-content/uploads/breakdance/fonts/inter.woff2' ), 'same origin, so preloadable' );

}

function test_font_changes_retire_cached_pages_and_detection(): void {

	oa_detected_fonts( [ '/wp-content/fonts/a.woff2' ] );

	Octave_Addons_Module_Performance_Fonts::on_fonts_changed();

	oa_assert_same( false, get_option( Octave_Addons_Module_Performance_Fonts::DETECTED_OPTION ) );
	oa_assert_same( 1, did_action( 'octave_addons_perf_purged_all' ) );

}
