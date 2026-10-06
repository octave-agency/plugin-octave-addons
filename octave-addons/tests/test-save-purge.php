<?php

/*
SAVE PURGE TESTS
-- A completed save purges the pages it changed once, when the request
-- ends, and queues them for warming; Breakdance's shared documents and
-- global design data clear everything once
---------------------------------------------------------- */

/*
HELPERS
-- A fake page cache recording each call, and a save request that ends
---------------------------------------------------------- */

function oa_save_setup(): void {

	oa_fake_page_cache();
	Octave_Addons_Perf_Cache::register_invalidation();

	$GLOBALS['oa_posts']      = [ 7 => [ 'post', 'publish' ], 8 => [ 'page', 'publish' ], 5 => [ 'breakdance_footer', 'publish' ] ];
	$GLOBALS['oa_permalinks'] = [ 7 => 'https://example.com/news/hello/', 8 => 'https://example.com/about/' ];
	$GLOBALS['oa_terms']      = [ 7 => [ 'https://example.com/category/news/' ] ];

}

function oa_save( int $id, string $status = 'publish', ?string $before = 'publish', string $name = '' ): void {

	$post        = new WP_Post( [ 'ID' => $id, 'post_type' => get_post( $id )->post_type, 'post_status' => $status, 'post_name' => $name ] );
	$post_before = null === $before ? null : new WP_Post( [ 'ID' => $id, 'post_type' => $post->post_type, 'post_status' => $before ] );

	do_action( 'wp_after_insert_post', $id, $post, null !== $before, $post_before );

}

function oa_targeted_calls(): array {

	return array_values( array_filter( $GLOBALS['oa_cache_calls'], 'is_array' ) );

}

/*
ORDINARY CONTENT
---------------------------------------------------------- */

function test_a_published_save_purges_its_pages_once_after_the_request(): void {

	oa_save_setup();

	oa_save( 7 );
	oa_save( 7 );

	oa_assert_same( [], $GLOBALS['oa_cache_calls'], 'nothing while the save is still running' );

	do_action( 'shutdown' );

	oa_assert_same( 1, count( oa_targeted_calls() ), 'one purge however often the hook fired' );
	oa_assert_same( [ 'https://example.com/', 'https://example.com/news/hello/', 'https://example.com/category/news/' ], oa_targeted_calls()[0], 'canonical URL, home page and public term archives' );
	oa_assert_same( 'content', Octave_Addons_Perf_Cache::last_clears()['targeted']['reason'] );

}

function test_purged_pages_are_queued_for_warming(): void {

	oa_save_setup();

	oa_save( 8 );
	do_action( 'shutdown' );

	$state = Octave_Addons_Perf_Page_Cache::state();

	oa_assert_same( [ 'https://example.com/', 'https://example.com/about/' ], $state['queue'] );
	oa_assert_same( 'content', $state['reason'] );
	oa_assert_same( Octave_Addons_Perf_Page_Cache::WARM_HOOK, $GLOBALS['oa_cron'][0]['hook'], 'warmed from cron, not by the next visitor' );

}

function test_the_purge_url_filter_still_adds_urls(): void {

	oa_save_setup();

	add_filter( 'octave_addons_perf_post_purge_urls', static function ( $urls ) {

		$urls[] = 'https://example.com/landing/';

		return $urls;

	} );

	oa_save( 8 );
	do_action( 'shutdown' );

	oa_assert( in_array( 'https://example.com/landing/', oa_targeted_calls()[0], true ) );

}

function test_revisions_autosaves_and_drafts_never_purge(): void {

	oa_save_setup();

	$GLOBALS['oa_posts'][9] = [ 'revision', 'inherit' ];

	do_action( 'wp_after_insert_post', 9, get_post( 9 ), true, null );
	oa_save( 7, 'publish', 'publish', 'autosave' );
	oa_save( 7, 'auto-draft', null );
	oa_save( 7, 'draft', 'draft' );
	do_action( 'shutdown' );

	oa_assert_same( [], $GLOBALS['oa_cache_calls'] );
	oa_assert_same( [], $GLOBALS['oa_cron'], 'nothing warmed' );

}

function test_unpublishing_purges_the_old_pages_without_warming_the_gone_url(): void {

	oa_save_setup();

	oa_save( 8, 'trash', 'publish' );
	do_action( 'shutdown' );

	oa_assert_same( [ 'https://example.com/', 'https://example.com/about/' ], oa_targeted_calls()[0], 'old address purged' );
	oa_assert_same( [ 'https://example.com/' ], Octave_Addons_Perf_Page_Cache::state()['queue'], 'the gone page is not refilled' );

}

function test_term_changes_purge_their_archive(): void {

	oa_save_setup();

	do_action( 'edited_term', 3, 3, 'category' );
	do_action( 'edited_term', 4, 4, 'secret' );
	do_action( 'shutdown' );

	oa_assert_same( [ [ 'https://example.com/', 'https://example.com/category/term-3/' ] ], oa_targeted_calls(), 'private taxonomies ignored' );

}

function test_menu_changes_clear_every_page_once(): void {

	oa_save_setup();

	do_action( 'wp_update_nav_menu', 2 );
	do_action( 'wp_update_nav_menu', 3 );

	oa_assert_same( [], $GLOBALS['oa_cache_calls'] );

	do_action( 'shutdown' );

	oa_assert_same( [ 'all' ], $GLOBALS['oa_cache_calls'], 'one full clear reaches the page cache' );
	oa_assert_same( 'menu', Octave_Addons_Perf_Cache::last_clears()['full']['reason'] );

}

/*
BREAKDANCE
---------------------------------------------------------- */

function test_a_breakdance_page_save_purges_once_after_its_css_is_written(): void {

	oa_save_setup();

	$order = [];

	add_filter( 'octave_addons_perf_purge_url_layers', static function ( $report ) use ( &$order ) {

		$order[] = 'purge';

		return $report;

	} );

	// Breakdance's save: wp_update_post() first, then the CSS file, then its hook.
	oa_save( 8 );
	$order[] = 'css written';
	do_action( 'breakdance_after_save_document', 8 );

	do_action( 'shutdown' );

	oa_assert_same( [ 'css written', 'purge' ], $order, 'purged after the CSS, not before' );
	oa_assert_same( 1, count( oa_targeted_calls() ), 'one purge for both hooks' );
	oa_assert_same( 0, (int) did_action( 'octave_addons_perf_purged_all' ), 'no full clear for one page' );

}

function test_shared_breakdance_documents_and_globals_clear_everything_once(): void {

	oa_save_setup();

	oa_save( 5 );
	do_action( 'breakdance_after_save_document', 5 );
	do_action( 'breakdance_option_updated_global_settings_json_string', '{}' );
	do_action( 'breakdance_option_updated_presets_json_string', '{}' );
	do_action( 'shutdown' );

	oa_assert_same( [ 'all' ], $GLOBALS['oa_cache_calls'], 'one full clear, and no separate targeted purge' );
	oa_assert_same( 1, (int) did_action( 'octave_addons_perf_purged_all' ) );

}
