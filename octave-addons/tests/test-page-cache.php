<?php

/*
PAGE CACHE TESTS
-- Purges reach the site's page cache only for changes it cannot see, and
-- warming refills it in small batches without touching private pages
---------------------------------------------------------- */

function oa_fake_page_cache( bool $fail = false ): void {

	$GLOBALS['oa_cache_calls'] = [];

	add_filter( 'octave_addons_perf_page_cache_connectors', static function ( $connectors ) use ( $fail ) {

		$connectors['Fake Cache'] = [
			'all'  => static function () {

				$GLOBALS['oa_cache_calls'][] = 'all';

			},
			'urls' => static function ( $urls ) {

				$GLOBALS['oa_cache_calls'][] = $urls;

			},
		];

		if ( $fail ) {

			$connectors['Broken Cache'] = [ 'all' => static function () {

				throw new RuntimeException( 'API down' );

			}, 'urls' => null ];

		}

		return $connectors;

	} );

	add_filter( 'octave_addons_perf_handled_elsewhere', static function ( $owner, $feature ) {

		return 'page_cache' === $feature ? 'Fake Cache' : $owner;

	}, 10, 2 );

	Octave_Addons_Perf_Page_Cache::boot();

}

function test_page_cache_is_purged_only_for_changes_it_cannot_see(): void {

	oa_fake_page_cache();

	Octave_Addons_Perf_Cache::purge_urls( [ 'https://example.com/a/' ], 'content' );
	Octave_Addons_Perf_Cache::purge_all( 'files', 'settings' );

	oa_assert_same( [], $GLOBALS['oa_cache_calls'], 'post edits and file purges are left to the cache plugin' );

	$report = Octave_Addons_Perf_Cache::purge_all( 'all', 'breakdance' );

	oa_assert_same( [ 'all' ], $GLOBALS['oa_cache_calls'] );
	oa_assert_same( 'success', $report['page-cache-fakecache']['status'] );

	Octave_Addons_Perf_Cache::purge_urls( [ 'https://example.com/hero/' ], 'lcp' );

	oa_assert_same( [ 'https://example.com/hero/' ], $GLOBALS['oa_cache_calls'][1], 'a page whose LCP changed' );

}

function test_a_failing_cache_api_only_fails_its_own_layer(): void {

	oa_fake_page_cache( true );

	$report = Octave_Addons_Perf_Cache::purge_all( 'all', 'manual' );

	oa_assert_same( 'error', $report['page-cache-brokencache']['status'] );
	oa_assert_same( 'success', $report['page-cache-fakecache']['status'] );
	oa_assert_same( 'skipped', Octave_Addons_Perf_Cache::purge_urls( [ 'https://example.com/x/' ], 'manual' )['page-cache-brokencache']['status'], 'no targeted API' );

}

function test_full_purge_warms_public_pages_in_small_batches(): void {

	oa_fake_page_cache();

	$GLOBALS['oa_post_ids']   = range( 1, 7 );
	$GLOBALS['oa_permalinks'] = [
		1 => 'https://example.com/about/',
		2 => 'https://example.com/wp-login.php',
		3 => 'https://example.com/private/',
		4 => 'https://example.com/blog/',
		5 => 'https://example.com/shop/?add-to-cart=3',
		6 => 'https://example.com/team/',
		7 => 'https://example.com/contact/',
	];
	$GLOBALS['oa_meta'] = [ 3 => [ '_yoast_wpseo_meta-robots-noindex' => '1' ] ];

	Octave_Addons_Perf_Cache::purge_all( 'all', 'manual' );

	$queue = Octave_Addons_Perf_Page_Cache::state()['queue'];

	oa_assert_same( [ 'https://example.com/', 'https://example.com/about/', 'https://example.com/blog/', 'https://example.com/team/', 'https://example.com/contact/' ], $queue, 'login, cart and noindex pages skipped' );
	oa_assert_same( Octave_Addons_Perf_Page_Cache::WARM_HOOK, $GLOBALS['oa_cron'][0]['hook'], 'runs from cron, not during the purge' );
	oa_assert_same( [], $GLOBALS['oa_http_log'] );

	$GLOBALS['oa_post_ids'][] = 8;
	$GLOBALS['oa_permalinks'][8] = 'https://example.com/new/';

	$GLOBALS['oa_http'] = static function ( $url ) {

		return false !== strpos( $url, 'blog' ) ? oa_http_response( 500, '', 'text/html' ) : oa_http_response( 200, '', 'text/html' );

	};

	$state = Octave_Addons_Perf_Page_Cache::warm_batch();

	oa_assert_same( Octave_Addons_Perf_Page_Cache::BATCH, count( $GLOBALS['oa_http_log'] ), 'one batch' );
	oa_assert_same( '1', $GLOBALS['oa_http_log'][0]['args']['headers'][ Octave_Addons_Perf_Page_Cache::WARM_HEADER ] );
	oa_assert_same( [], $GLOBALS['oa_http_log'][0]['args']['cookies'], 'as a logged-out visitor' );
	oa_assert_same( [ 'https://example.com/blog/' => 'HTTP 500' ], $state['failed'] );
	oa_assert_same( 4, $state['done'] );
	oa_assert_same( 'manual', $state['log'][0]['reason'], 'cycle logged' );

}

function test_queue_is_extended_not_duplicated_and_long_queues_continue(): void {

	oa_fake_page_cache();

	$GLOBALS['oa_post_ids']   = range( 1, 9 );
	$GLOBALS['oa_permalinks'] = array_combine( range( 1, 9 ), array_map( static function ( $i ) {

		return 'https://example.com/p' . $i . '/';

	}, range( 1, 9 ) ) );

	Octave_Addons_Perf_Page_Cache::queue_warm( 'manual' );
	Octave_Addons_Perf_Page_Cache::queue_warm( 'breakdance' );

	oa_assert_same( 10, count( Octave_Addons_Perf_Page_Cache::state()['queue'] ), 'no duplicates' );
	oa_assert_same( 'manual', Octave_Addons_Perf_Page_Cache::state()['reason'], 'running cycle kept' );

	$GLOBALS['oa_http'] = static function () {

		return oa_http_response( 200, '', 'text/html' );

	};

	Octave_Addons_Perf_Page_Cache::warm_batch();

	oa_assert_same( 5, count( Octave_Addons_Perf_Page_Cache::state()['queue'] ) );
	oa_assert_same( 2, count( $GLOBALS['oa_cron'] ), 'next batch booked' );

	set_transient( 'oa_perf_warm_lock', 1 );

	oa_assert_same( [], Octave_Addons_Perf_Page_Cache::warm_batch(), 'never two batches at once' );

}

function test_nothing_is_warmed_without_a_page_cache_or_minified_files(): void {

	Octave_Addons_Perf_Page_Cache::boot();

	Octave_Addons_Perf_Cache::purge_all( 'all', 'manual' );

	oa_assert_same( [], Octave_Addons_Perf_Page_Cache::state()['queue'] );
	oa_assert_same( [], $GLOBALS['oa_cron'] );

}
