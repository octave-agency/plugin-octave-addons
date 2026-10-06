<?php

/*
INTEGRATION TESTS
-- Cloudflare, database cleanup, Heartbeat and link preloading
---------------------------------------------------------- */

const OA_ZONE = '0123456789abcdef0123456789abcdef';

function oa_cloudflare( array $values = [] ): void {

	oa_module( 'performance-cloudflare' )->run( oa_set_settings( 'performance-cloudflare', array_merge( [ 'enabled' => true, 'zone_id' => OA_ZONE ], $values ) ) );
	update_option( Octave_Addons_Perf_Cloudflare::TOKEN_OPTION, 'tok' );

}

function oa_cloudflare_ok(): void {

	$GLOBALS['oa_http'] = static function () {

		return oa_http_response( 200, '{"success":true,"errors":[],"result":{"status":"active"}}' );

	};

}

/*
CLOUDFLARE
---------------------------------------------------------- */

function test_cloudflare_targeted_purge_uses_files_payload_in_batches(): void {

	oa_cloudflare();
	oa_cloudflare_ok();

	$urls = array_map( static function ( $i ) {

		return 'https://example.com/p/' . $i . '/';

	}, range( 1, 31 ) );

	$result = Octave_Addons_Perf_Cloudflare::purge_files( $urls );

	oa_assert( $result['ok'] );
	oa_assert_same( 2, count( $GLOBALS['oa_http_log'] ), 'two batches' );

	$first = $GLOBALS['oa_http_log'][0];

	oa_assert_same( 'https://api.cloudflare.com/client/v4/zones/' . OA_ZONE . '/purge_cache', $first['url'] );
	oa_assert_same( 'POST', $first['args']['method'] );
	oa_assert_same( 'Bearer tok', $first['args']['headers']['Authorization'] );
	oa_assert_same( 30, count( json_decode( $first['args']['body'], true )['files'] ) );
	oa_assert( $first['args']['timeout'] <= 10, 'short timeout' );

	Octave_Addons_Perf_Cloudflare::purge_everything();

	oa_assert_same( '{"purge_everything":true}', end( $GLOBALS['oa_http_log'] )['args']['body'] );

}

function test_cloudflare_errors_are_reported_readably(): void {

	oa_cloudflare();

	$GLOBALS['oa_http'] = static function () {

		return oa_http_response( 403, '{"success":false,"errors":[{"code":10000,"message":"Authentication error"}]}' );

	};

	$result = Octave_Addons_Perf_Cloudflare::purge_files( [ 'https://example.com/' ] );

	oa_assert( ! $result['ok'] );
	oa_assert_contains( 'HTTP 403', $result['message'] );
	oa_assert_contains( 'Authentication error', $result['message'] );

	$GLOBALS['oa_http'] = static function () {

		return new WP_Error( 'timeout', 'Operation timed out' );

	};

	oa_assert_contains( 'Operation timed out', Octave_Addons_Perf_Cloudflare::purge_everything()['message'] );
	oa_assert_same( 2, count( $GLOBALS['oa_http_log'] ), 'one request per failed call, no retries' );

	$GLOBALS['oa_http'] = static function () {

		return oa_http_response( 200, '{"success":true,"result":{"status":"disabled"}}' );

	};

	oa_assert( ! Octave_Addons_Perf_Cloudflare::test()['ok'], 'inactive token' );

	update_option( OCTAVE_ADDONS_OPTION_KEY, [ 'performance-cloudflare' => [ 'zone_id' => 'bad' ] ] );

	oa_assert_contains( 'Zone ID', Octave_Addons_Perf_Cloudflare::test()['message'] );

}

function test_automatic_purges_are_queued_and_never_block_saves(): void {

	oa_cloudflare();

	$GLOBALS['oa_http'] = static function () {

		throw new RuntimeException( 'Must not be called during a save' );

	};

	$report = Octave_Addons_Perf_Cache::purge_urls( [ 'https://example.com/a/' ], 'content' );

	oa_assert_same( 'queued', $report['cloudflare']['status'] );
	oa_assert_same( [ 'https://example.com/a/' ], get_option( Octave_Addons_Perf_Cloudflare::QUEUE_OPTION ) );
	oa_assert( false !== wp_next_scheduled( Octave_Addons_Perf_Cloudflare::FLUSH_HOOK ) );

	oa_cloudflare_ok();

	oa_assert( Octave_Addons_Perf_Cloudflare::flush_queue()['ok'] );
	oa_assert_same( false, get_option( Octave_Addons_Perf_Cloudflare::QUEUE_OPTION ), 'queue emptied' );

}

function test_full_purge_only_purges_everything_when_explicitly_allowed(): void {

	oa_cloudflare();
	oa_cloudflare_ok();

	$report = Octave_Addons_Perf_Cache::purge_all( 'all', 'manual' );

	oa_assert_same( 'skipped', $report['cloudflare']['status'] );
	oa_assert_same( [], $GLOBALS['oa_http_log'] );

	oa_test_reset();
	oa_cloudflare( [ 'purge_on_full' => true ] );
	oa_cloudflare_ok();

	oa_assert_same( 'skipped', Octave_Addons_Perf_Cache::purge_all( 'all', 'settings' )['cloudflare']['status'], 'settings saves never purge everything' );
	oa_assert_same( 'success', Octave_Addons_Perf_Cache::purge_all( 'all', 'manual' )['cloudflare']['status'] );

}

function test_wp_config_constants_take_precedence(): void {

	define( 'OCTAVE_ADDONS_CLOUDFLARE_API_TOKEN', 'from-config' );

	update_option( Octave_Addons_Perf_Cloudflare::TOKEN_OPTION, 'from-db' );

	oa_assert_same( 'from-config', Octave_Addons_Perf_Cloudflare::token() );

}

/*
DATABASE CLEANUP
-- A fake $wpdb holding revision IDs
---------------------------------------------------------- */

class OA_Fake_Wpdb {

	public string $posts    = 'wp_posts';
	public string $comments = 'wp_comments';
	public string $options  = 'wp_options';
	public string $prefix   = 'wp_';
	public array $revisions = [];
	public array $queries   = [];

	public function prepare( $query, ...$args ) {

		$GLOBALS['oa_last_prepare'] = $args;

		return vsprintf( str_replace( [ '%s', '%d' ], [ "'%s'", '%d' ], $query ), $args );

	}

	public function esc_like( $text ) {

		return addcslashes( $text, '_%\\' );

	}

	public function get_var( $query ) {

		return false !== strpos( $query, "post_type = 'revision'" ) ? count( $this->revisions ) : 0;

	}

	public function get_col( $query ) {

		if ( false !== strpos( $query, "post_type = 'revision'" ) && preg_match( '/LIMIT (\d+)/', $query, $match ) ) {

			return array_slice( $this->revisions, 0, (int) $match[1] );

		}

		if ( 0 === strpos( $query, 'SHOW TABLES' ) ) {

			return [ 'wp_posts', 'wp_options' ];

		}

		return [];

	}

	public function query( $query ) {

		$this->queries[] = $query;

		return 1;

	}

}

function wp_delete_post_revision( $id ) {

	$GLOBALS['wpdb']->revisions = array_values( array_diff( $GLOBALS['wpdb']->revisions, [ $id ] ) );

	return true;

}

function test_cleanup_runs_in_batches_and_reports_remaining(): void {

	$GLOBALS['wpdb']            = new OA_Fake_Wpdb();
	$GLOBALS['wpdb']->revisions = range( 1, 250 );

	oa_assert_same( 250, Octave_Addons_Perf_Cleanup::count( 'revisions' ) );

	$batch = Octave_Addons_Perf_Cleanup::run_batch( 'revisions' );

	oa_assert_same( [ 'done' => 100, 'remaining' => 150, 'offset' => 0 ], $batch );

	Octave_Addons_Perf_Cleanup::run_batch( 'revisions' );

	oa_assert_same( [ 'done' => 50, 'remaining' => 0, 'offset' => 0 ], Octave_Addons_Perf_Cleanup::run_batch( 'revisions' ) );

	$tables = Octave_Addons_Perf_Cleanup::run_batch( 'optimize_tables', 100, 1 );

	oa_assert_same( [ 'done' => 1, 'remaining' => 0, 'offset' => 2 ], $tables, 'one table per request' );
	oa_assert_same( 'OPTIMIZE TABLE `wp_options`', end( $GLOBALS['wpdb']->queries ) );

}

function test_cleanup_endpoint_requires_nonce_capability_and_confirmation(): void {

	$GLOBALS['wpdb']            = new OA_Fake_Wpdb();
	$GLOBALS['wpdb']->revisions = range( 1, 5 );

	$_POST = [ 'item' => 'revisions', 'confirmed' => '1', 'nonce' => 'forged' ];
	$GLOBALS['oa_caps'] = [ 'manage_options' ];

	oa_assert_same( 403, oa_json_call( [ 'Octave_Addons_Perf_Admin', 'ajax_db_run' ] )->status, 'nonce' );

	$_POST['nonce']     = wp_create_nonce( Octave_Addons_Perf_Admin::NONCE );
	$GLOBALS['oa_caps'] = [ 'edit_posts' ];

	oa_assert_same( 403, oa_json_call( [ 'Octave_Addons_Perf_Admin', 'ajax_db_run' ] )->status, 'capability' );

	$GLOBALS['oa_caps'] = [ 'manage_options' ];
	unset( $_POST['confirmed'] );

	oa_assert( ! oa_json_call( [ 'Octave_Addons_Perf_Admin', 'ajax_db_run' ] )->success, 'confirmation required' );
	oa_assert_same( 5, count( $GLOBALS['wpdb']->revisions ), 'nothing deleted' );

	$_POST['confirmed'] = '1';

	$response = oa_json_call( [ 'Octave_Addons_Perf_Admin', 'ajax_db_run' ] );

	oa_assert( $response->success && 5 === $response->data['done'] );
	oa_assert_same( 5, Octave_Addons_Perf_Cleanup::last()['totals']['revisions'] );

}

function test_cleanup_schedule_follows_settings_and_module_state(): void {

	$module = oa_module( 'performance-database' );

	$module->run( oa_set_settings( 'performance-database', [ 'enabled' => true ] ) );

	oa_assert_same( false, wp_next_scheduled( Octave_Addons_Module_Performance_Database::CRON_HOOK ), 'manual by default' );

	$module->run( oa_set_settings( 'performance-database', [ 'enabled' => true, 'schedule' => 'oa_monthly' ] ) );

	oa_assert( false !== wp_next_scheduled( Octave_Addons_Module_Performance_Database::CRON_HOOK ) );
	oa_assert_same( 'oa_monthly', $GLOBALS['oa_cron'][0]['recurrence'] );
	oa_assert( isset( apply_filters( 'cron_schedules', [] )['oa_monthly'] ) );

	$module->run_disabled( [] );

	oa_assert_same( false, wp_next_scheduled( Octave_Addons_Module_Performance_Database::CRON_HOOK ), 'unscheduled when disabled' );

}

/*
HEARTBEAT
---------------------------------------------------------- */

function test_heartbeat_follows_context(): void {

	$s = oa_module( 'performance-heartbeat' )->get_defaults();

	oa_assert_same( 'frontend', Octave_Addons_Module_Performance_Heartbeat::context() );
	oa_assert( Octave_Addons_Module_Performance_Heartbeat::maybe_disable( $s, 'frontend' ), 'frontend disabled by default' );
	oa_assert_same( [ 'heartbeat' ], $GLOBALS['oa_deregistered'] );

	$GLOBALS['oa_flags']['admin'] = true;
	$GLOBALS['pagenow']           = 'post.php';

	oa_assert_same( 'editor', Octave_Addons_Module_Performance_Heartbeat::context() );
	oa_assert( ! Octave_Addons_Module_Performance_Heartbeat::maybe_disable( [ 'editor' => 'disable' ] + $s, 'editor' ), 'editor never disabled' );
	oa_assert_same( 120, Octave_Addons_Module_Performance_Heartbeat::filter_settings( [], $s, 'editor' )['interval'] );

	$GLOBALS['pagenow'] = 'index.php';

	oa_assert_same( 'admin', Octave_Addons_Module_Performance_Heartbeat::context() );
	oa_assert_same( [ 'x' => 1 ], Octave_Addons_Module_Performance_Heartbeat::filter_settings( [ 'x' => 1 ], [ 'admin' => 'default' ], 'admin' ), 'default untouched' );

	$_GET['breakdance'] = 'builder';

	oa_assert_same( '', Octave_Addons_Module_Performance_Heartbeat::context(), 'builder left alone' );

}

/*
LINK PRELOADING
---------------------------------------------------------- */

function test_preload_exclusions_and_logged_in_default(): void {

	$patterns = Octave_Addons_Module_Performance_Preload::exclusions( [ 'exclude' => '/members/' ] );

	foreach ( [ '/wp-admin', '/wp-login.php', 'action=logout', 'add-to-cart=', 'nonce=', '/members/' ] as $pattern ) {

		oa_assert( in_array( $pattern, $patterns, true ), $pattern );

	}

	oa_module( 'performance-preload' )->run( oa_set_settings( 'performance-preload', [ 'enabled' => true ] ) );
	$GLOBALS['oa_flags']['logged_in'] = true;

	do_action( 'wp_enqueue_scripts' );

	oa_assert_same( [], $GLOBALS['oa_enqueued'], 'not loaded for logged-in users' );

	$GLOBALS['oa_flags']['logged_in'] = false;

	do_action( 'wp_enqueue_scripts' );

	oa_assert_same( [ 'octave-addons-link-preload' ], $GLOBALS['oa_enqueued'] );
	oa_assert( has_filter( 'wp_speculation_rules_configuration' ), 'core speculative loading switched off' );

}

/*
BREAKDANCE TOGGLES
---------------------------------------------------------- */

function test_breakdance_lazy_toggles_are_removed_from_builder_controls(): void {

	$toggle  = [ 'slug' => 'lazy_load', 'options' => [ 'type' => 'toggle' ], 'children' => [] ];
	$section = [ 'slug' => 'lazy_load', 'options' => [ 'type' => 'section' ], 'children' => [ [ 'slug' => 'icon_color', 'options' => [ 'type' => 'color' ], 'children' => [] ] ] ];
	$alt     = [ 'slug' => 'alt', 'options' => [ 'type' => 'text' ], 'children' => [] ];

	$controls = Octave_Addons_Module_Breakdance_Lazy_Load::filter_controls( [
		'contentSections' => [ [ 'slug' => 'content', 'options' => [ 'type' => 'section' ], 'children' => [ $alt, $toggle ] ] ],
		'designSections'  => [ $section ],
	] );

	oa_assert_same( [ $alt ], $controls['contentSections'][0]['children'], 'toggle removed, list reindexed' );
	oa_assert_same( [ $section ], $controls['designSections'], 'Video play button section kept' );

}

/*
WORDPRESS BLOAT
---------------------------------------------------------- */

function test_jquery_migrate_is_dropped_but_jquery_kept(): void {

	$scripts = (object) [ 'registered' => [ 'jquery' => (object) [ 'deps' => [ 'jquery-core', 'jquery-migrate' ] ] ] ];

	oa_assert( Octave_Addons_Module_Performance_Bloat::drop_jquery_migrate( $scripts ) );
	oa_assert_same( [ 'jquery-core' ], $scripts->registered['jquery']->deps );
	oa_assert( ! Octave_Addons_Module_Performance_Bloat::drop_jquery_migrate( $scripts ), 'second call is a no-op' );
	oa_assert( ! Octave_Addons_Module_Performance_Bloat::drop_jquery_migrate( (object) [ 'registered' => [] ] ), 'no jquery registered' );

}

function test_bloat_module_is_off_by_default_with_safe_options_preselected(): void {

	$defaults = oa_module( 'performance-bloat' )->get_defaults();

	oa_assert( ! $defaults['enabled'] && $defaults['emojis'] && $defaults['embeds'] );
	oa_assert( ! $defaults['jquery_migrate'] && ! $defaults['block_styles'], 'risky options stay off' );

}
