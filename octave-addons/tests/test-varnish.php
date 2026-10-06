<?php

/*
CLOUDWAYS VARNISH TESTS
-- Detection from Cloudways' request values, the PURGE protocol, honest
-- results, URL validation and no second purge when WP Rocket does it
---------------------------------------------------------- */

/*
HELPERS
---------------------------------------------------------- */

function oa_cloudways( bool $varnish = true ): void {

	$_SERVER['cw_allowed_ip']      = '1';
	$_SERVER['HTTP_X_APPLICATION'] = $varnish ? 'wordpress' : 'varnishpass';

	if ( $varnish ) {

		$_SERVER['HTTP_X_VARNISH'] = '12345';

	}

	oa_set_settings( 'performance-media', [ 'enabled' => true ] );

}

function oa_varnish_answers( int $code = 200 ): void {

	$GLOBALS['oa_http'] = static function () use ( $code ) {

		return oa_http_response( $code, '', 'text/plain' );

	};

}

/*
DETECTION
---------------------------------------------------------- */

function test_varnish_is_detected_only_on_cloudways_with_varnish_on(): void {

	oa_assert( ! Octave_Addons_Perf_Varnish::is_running(), 'not Cloudways' );

	$_SERVER['HTTP_X_VARNISH']     = '1';
	$_SERVER['HTTP_X_APPLICATION'] = 'wordpress';

	oa_assert( ! Octave_Addons_Perf_Varnish::is_running(), 'Varnish headers alone are not Cloudways' );

	oa_cloudways( false );

	oa_assert( ! Octave_Addons_Perf_Varnish::is_running(), 'Cloudways with Varnish switched off' );

	oa_cloudways();

	oa_assert( Octave_Addons_Perf_Varnish::is_running() );
	oa_assert_same( 'Cloudways Varnish', Octave_Addons_Perf::handled_elsewhere( 'page_cache' ), 'registered as the page-cache owner' );

}

function test_cron_uses_the_last_answer_from_a_web_request(): void {

	oa_cloudways();

	Octave_Addons_Perf_Varnish::is_running();

	unset( $_SERVER['HTTP_X_VARNISH'], $_SERVER['HTTP_X_APPLICATION'] );
	$GLOBALS['oa_flags']['cron'] = true;

	oa_assert( Octave_Addons_Perf_Varnish::is_running(), 'remembered' );

	delete_option( Octave_Addons_Perf_Varnish::STATE_OPTION );

	oa_assert( ! Octave_Addons_Perf_Varnish::is_running(), 'unknown means absent' );

}

/*
PROTOCOL
---------------------------------------------------------- */

function test_targeted_purge_sends_purge_to_the_local_service_with_the_site_host(): void {

	oa_varnish_answers();

	$result = Octave_Addons_Perf_Varnish::purge_urls( [ 'https://example.com/about/', 'https://example.com/about/#team', 'https://example.com/blog/?page=2' ] );
	$first  = $GLOBALS['oa_http_log'][0];

	oa_assert_same( 'success', $result['status'] );
	oa_assert_same( 2, count( $GLOBALS['oa_http_log'] ), 'fragments dropped, duplicates merged' );
	oa_assert_same( 'http://127.0.0.1:8080/about/', $first['url'] );
	oa_assert_same( 'PURGE', $first['args']['method'] );
	oa_assert_same( 'example.com', $first['args']['headers']['Host'] );
	oa_assert_same( 'default', $first['args']['headers']['X-Purge-Method'] );
	oa_assert( $first['args']['timeout'] <= 3, 'short timeout' );
	oa_assert_same( 'http://127.0.0.1:8080/blog/?page=2', $GLOBALS['oa_http_log'][1]['url'] );

}

function test_full_purge_uses_a_regex_over_the_whole_site(): void {

	oa_varnish_answers();

	$result = Octave_Addons_Perf_Varnish::purge_all();
	$call   = $GLOBALS['oa_http_log'][0];

	oa_assert_same( 'success', $result['status'] );
	oa_assert_same( 'http://127.0.0.1:8080/.*', $call['url'] );
	oa_assert_same( 'regex', $call['args']['headers']['X-Purge-Method'] );
	oa_assert_same( 'example.com', $call['args']['headers']['Host'] );

}

function test_other_hosts_and_unsafe_paths_are_never_purged(): void {

	oa_varnish_answers();

	$result = Octave_Addons_Perf_Varnish::purge_urls( [ 'https://evil.test/x/', 'javascript:alert(1)', '' ] );

	oa_assert_same( 'skipped', $result['status'] );
	oa_assert_same( [], $GLOBALS['oa_http_log'], 'no request at all' );
	oa_assert_same( '', Octave_Addons_Perf_Varnish::path( "https://example.com/a\r\nX-Injected: 1" ), 'no header injection' );

}

function test_errors_and_refusals_are_reported_and_stop_further_requests(): void {

	$GLOBALS['oa_http'] = static function () {

		return new WP_Error( 'timeout', 'Operation timed out' );

	};

	$result = Octave_Addons_Perf_Varnish::purge_urls( [ 'https://example.com/a/', 'https://example.com/b/' ] );

	oa_assert_same( 'error', $result['status'] );
	oa_assert_contains( 'timed out', $result['message'] );
	oa_assert_same( 1, count( $GLOBALS['oa_http_log'] ), 'a dead service costs one timeout, not one per URL' );

	oa_test_reset();
	oa_varnish_answers( 405 );

	$result = Octave_Addons_Perf_Varnish::purge_all();

	oa_assert_same( 'error', $result['status'] );
	oa_assert_contains( '405', $result['message'] );

}

/*
INTEGRATION
---------------------------------------------------------- */

function test_varnish_is_cleared_with_every_purge_and_saves(): void {

	oa_cloudways();
	oa_varnish_answers();
	Octave_Addons_Perf_Page_Cache::boot();

	$report = Octave_Addons_Perf_Cache::purge_all( 'all', 'manual' );

	oa_assert_same( 'success', $report['page-cache-cloudwaysvarnish']['status'] );
	oa_assert_same( 'http://127.0.0.1:8080/.*', $GLOBALS['oa_http_log'][0]['url'] );

	$report = Octave_Addons_Perf_Cache::purge_urls( [ 'https://example.com/about/' ], 'content' );

	oa_assert_same( 'success', $report['page-cache-cloudwaysvarnish']['status'], 'ordinary content saves reach Varnish' );
	oa_assert_same( 'http://127.0.0.1:8080/about/', $GLOBALS['oa_http_log'][1]['url'] );
	oa_assert( Octave_Addons_Perf_Page_Cache::should_warm(), 'Varnish counts as a page cache to refill' );
	oa_assert( in_array( 'https://example.com/', Octave_Addons_Perf_Page_Cache::state()['queue'], true ), 'the full clear queued warming' );

}

function test_a_failed_varnish_purge_only_fails_its_own_layer(): void {

	oa_cloudways();
	oa_varnish_answers( 500 );
	Octave_Addons_Perf_Page_Cache::boot();

	$report = Octave_Addons_Perf_Cache::purge_all( 'all', 'manual' );

	oa_assert_same( 'error', $report['page-cache-cloudwaysvarnish']['status'] );
	oa_assert_same( 'success', $report['files']['status'] );
	oa_assert_contains( 'Cloudways Varnish', Octave_Addons_Perf_Cache::last_clears()['full']['failed'][0], 'kept for the settings page' );

}

function test_no_second_varnish_purge_when_wp_rocket_already_does_it(): void {

	oa_cloudways();

	add_filter( 'octave_addons_perf_varnish_handled_by', static function () {

		return 'WP Rocket';

	} );

	oa_assert( ! isset( Octave_Addons_Perf_Page_Cache::connectors()['Cloudways Varnish'] ) );

}

function test_varnish_stays_out_while_performance_is_off(): void {

	$_SERVER['cw_allowed_ip']      = '1';
	$_SERVER['HTTP_X_VARNISH']     = '1';
	$_SERVER['HTTP_X_APPLICATION'] = 'wordpress';

	oa_assert( ! isset( Octave_Addons_Perf_Page_Cache::connectors()['Cloudways Varnish'] ) );

}

function test_varnish_with_a_plugin_cache_is_not_flagged_as_a_duplicate(): void {

	oa_cloudways();

	add_filter( 'octave_addons_perf_handled_elsewhere', static function ( $owner, $feature ) {

		return 'page_cache' === $feature ? 'WP Rocket, Cloudways Varnish' : $owner;

	}, 10, 2 );

	oa_assert_same( 'external', Octave_Addons_Perf_Owners::report()['page_cache']['status'] );
	oa_assert_same( 'Cloudways Varnish', Octave_Addons_Perf_Page_Cache::active_label() );

}
