<?php

/*
CORE TESTS
-- Discovery, settings, request bypasses, the HTML pipeline and purge
-- endpoints
---------------------------------------------------------- */

const OA_PERF_MODULES = [
	'performance-cache',
	'performance-page-cache',
	'performance-media',
	'performance-delay',
	'performance-files',
	'performance-preload',
	'performance-fonts',
	'performance-heartbeat',
	'performance-cloudflare',
	'performance-database',
];

/*
DISCOVERY AND GROUPING
---------------------------------------------------------- */

function test_performance_modules_share_one_admin_entry_in_order(): void {

	$entries = $GLOBALS['oa_manager']->admin_entries();

	oa_assert( isset( $entries['performance'] ), 'performance entry exists' );
	oa_assert_same( array_slice( OA_PERF_MODULES, 1 ), array_keys( $entries['performance']['modules'] ), 'grouped modules in order, cache hidden' );
	oa_assert_same( 'performance', $GLOBALS['oa_manager']->entry_id_for( 'performance-fonts' ) );

}

function test_breakdance_and_performance_list_quick_scroll_links(): void {

	require_once OCTAVE_ADDONS_DIR . 'includes/class-admin.php';

	$admin  = ( new ReflectionClass( 'Octave_Addons_Admin' ) )->newInstanceWithoutConstructor();
	$scroll = new ReflectionMethod( $admin, 'has_quick_scroll' );

	oa_assert( $scroll->invoke( $admin, [ 'group' => 'performance', 'modules' => [ 'a' => 1, 'b' => 2 ] ] ), 'performance' );
	oa_assert( $scroll->invoke( $admin, [ 'group' => 'breakdance', 'modules' => [ 'a' => 1, 'b' => 2 ] ] ), 'breakdance' );
	oa_assert( ! $scroll->invoke( $admin, [ 'group' => 'ai-agents', 'modules' => [ 'a' => 1, 'b' => 2 ] ] ), 'ai agents stays one link' );
	oa_assert( ! $scroll->invoke( $admin, [ 'group' => 'breakdance', 'modules' => [ 'a' => 1 ] ] ), 'single module stays one link' );

}

function test_area_wide_groups_share_a_page_while_feature_groups_split(): void {

	require_once OCTAVE_ADDONS_DIR . 'includes/class-admin.php';

	$admin   = ( new ReflectionClass( 'Octave_Addons_Admin' ) )->newInstanceWithoutConstructor();
	$current = new ReflectionMethod( $admin, 'current_modules' );

	$performance = [ 'group' => 'performance', 'modules' => [ 'a' => 1, 'b' => 2 ] ];
	$ai_agents   = [ 'group' => 'ai-agents', 'modules' => [ 'a' => 1, 'b' => 2 ] ];
	$breakdance  = [ 'group' => 'breakdance', 'modules' => [ 'a' => 1, 'b' => 2 ] ];
	$design      = [ 'group' => 'design', 'modules' => [ 'a' => 1, 'b' => 2 ] ];

	unset( $_GET['module'] );
	oa_assert_same( [ 'a', 'b' ], array_keys( $current->invoke( $admin, $performance ) ), 'performance keeps every panel' );
	oa_assert_same( [ 'a', 'b' ], array_keys( $current->invoke( $admin, $ai_agents ) ), 'AI Agents keeps every panel' );
	oa_assert_same( [ 'a', 'b' ], array_keys( $current->invoke( $admin, $breakdance ) ), 'Breakdance keeps every panel' );
	oa_assert_same( [], $current->invoke( $admin, $design ), 'split group defaults to its card overview' );

	$_GET['module'] = 'b';
	oa_assert_same( [ 'b' ], array_keys( $current->invoke( $admin, $design ) ), 'split group renders the requested module' );

	$_GET['module'] = 'missing';
	oa_assert_same( [ 'a' ], array_keys( $current->invoke( $admin, $design ) ), 'unknown module falls back to the first' );

	unset( $_GET['module'] );

}

function test_custom_post_type_editor_routes_avoid_wordpress_reserved_post_type_arg(): void {

	require_once OCTAVE_ADDONS_DIR . 'includes/class-admin.php';

	$module     = oa_module( 'custom-post-types' );
	$editor_url = new ReflectionMethod( $module, 'editor_url' );

	parse_str( (string) parse_url( $editor_url->invoke( $module, 'oa_team', 'fields' ), PHP_URL_QUERY ), $editor_args );

	oa_assert_same( 'oa_team', $editor_args['oa_post_type'] ?? '', 'editor route uses plugin-owned post type arg' );
	oa_assert( ! array_key_exists( 'post_type', $editor_args ), 'editor route avoids WordPress reserved post_type arg' );

	$admin     = ( new ReflectionClass( 'Octave_Addons_Admin' ) )->newInstanceWithoutConstructor();
	$normalise = new ReflectionMethod( $admin, 'normalise_legacy_route_args' );
	$legacy    = $normalise->invoke( $admin, [ 'section' => 'fields', 'post_type' => 'oa_team' ], 'custom-post-types' );

	oa_assert_same( 'oa_team', $legacy['oa_post_type'] ?? '', 'legacy route preserves the requested post type' );
	oa_assert( ! array_key_exists( 'post_type', $legacy ), 'legacy route removes WordPress reserved post_type arg' );

}

function test_plugin_header_and_changelog_versions_match(): void {

	$plugin = (string) file_get_contents( OCTAVE_ADDONS_DIR . 'octave-addons.php' );
	$readme = (string) file_get_contents( OCTAVE_ADDONS_DIR . 'readme.txt' );

	$has_plugin_version    = 1 === preg_match( '/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/m', $plugin, $plugin_match );
	$has_changelog_version = 1 === preg_match( '/^=\s*([0-9]+\.[0-9]+\.[0-9]+)\s*=$/m', $readme, $changelog_match );

	oa_assert( $has_plugin_version, 'plugin header has a semantic version' );
	oa_assert( $has_changelog_version, 'changelog starts with a semantic version' );
	oa_assert_same( $plugin_match[1], $changelog_match[1], 'plugin header and latest changelog versions match' );

}

function test_existing_lazy_load_module_is_still_discovered_and_hidden(): void {

	$module = oa_module( 'breakdance-lazy-load' );

	oa_assert( $module->is_always_enabled() && ! $module->show_in_admin(), 'video lazy loading unchanged' );

}

function test_every_performance_feature_ships_off_except_cache(): void {

	foreach ( OA_PERF_MODULES as $id ) {

		$defaults = oa_module( $id )->get_defaults();

		oa_assert_same( 'performance-cache' === $id, (bool) $defaults['enabled'], $id . ' default' );

	}

}

/*
SETTINGS SANITATION
---------------------------------------------------------- */

function test_sanitize_rejects_unknown_and_hostile_values(): void {

	$delay = oa_module( 'performance-delay' )->sanitize( [
		'enabled'  => '1',
		'services' => [ 'google-analytics', 'evil<script>', 'not-a-service' ],
		'include'  => "<b>tag</b>\n\n analytics.example ",
	] );

	oa_assert_same( [ 'google-analytics' ], $delay['services'] );
	oa_assert_same( "tag\nanalytics.example", $delay['include'] );

	$heartbeat = oa_module( 'performance-heartbeat' )->sanitize( [ 'editor' => 'disable', 'admin' => '45', 'frontend' => 'disable' ] );

	oa_assert_same( '120', $heartbeat['editor'], 'editor cannot be disabled' );
	oa_assert_same( '120', $heartbeat['admin'], 'unknown interval falls back' );

	$fonts = oa_module( 'performance-fonts' )->sanitize( [ 'font_display' => 'bogus', 'refresh_days' => '0' ] );

	oa_assert_same( 'swap', $fonts['font_display'] );
	oa_assert_same( 1, $fonts['refresh_days'] );

	$cloudflare = oa_module( 'performance-cloudflare' )->sanitize( [ 'zone_id' => 'not-a-zone', 'api_token' => 'secret-token' ] );

	oa_assert_same( '', $cloudflare['zone_id'] );
	oa_assert( ! isset( $cloudflare['api_token'] ), 'token never stored with settings' );
	oa_assert_same( 'secret-token', get_option( Octave_Addons_Perf_Cloudflare::TOKEN_OPTION ) );
	oa_assert_same( false, $GLOBALS['oa_autoload'][ Octave_Addons_Perf_Cloudflare::TOKEN_OPTION ], 'token not autoloaded' );

	$database = oa_module( 'performance-database' )->sanitize( [ 'schedule' => 'hourly', 'scheduled_items' => [ 'revisions', 'all_transients' ] ] );

	oa_assert_same( 'manual', $database['schedule'] );
	oa_assert_same( [ 'revisions' ], $database['scheduled_items'], 'all transients never scheduled' );

}

function test_cache_module_is_always_enabled(): void {

	oa_assert( oa_module( 'performance-cache' )->sanitize( [] )['enabled'] );

}

/*
REQUEST BYPASSES
---------------------------------------------------------- */

function test_bypass_reasons_by_context(): void {

	oa_assert_same( '', Octave_Addons_Perf_Context::bypass_reason( 'media' ), 'plain frontend request' );

	$cases = [
		'admin'     => static function () { $GLOBALS['oa_flags']['admin'] = true; },
		'ajax'      => static function () { $GLOBALS['oa_flags']['ajax'] = true; },
		'rest'      => static function () { $GLOBALS['oa_flags']['rest'] = true; },
		'cron'      => static function () { $GLOBALS['oa_flags']['cron'] = true; },
		'builder'   => static function () { $_GET['breakdance'] = 'builder'; },
		'login'     => static function () { $GLOBALS['pagenow'] = 'wp-login.php'; },
		'method'    => static function () { $_SERVER['REQUEST_METHOD'] = 'POST'; },
		'logged-in' => static function () { $GLOBALS['oa_flags']['logged_in'] = true; },
	];

	foreach ( $cases as $expected => $setup ) {

		oa_test_reset();
		$setup();

		oa_assert_same( $expected, Octave_Addons_Perf_Context::bypass_reason( 'media' ), $expected );

	}

	oa_test_reset();
	do_action( 'parse_query' );
	$GLOBALS['oa_flags']['feed'] = true;

	oa_assert_same( 'feed', Octave_Addons_Perf_Context::bypass_reason( 'media' ) );

	$GLOBALS['oa_flags']['feed'] = false;
	$GLOBALS['oa_flags']['cart'] = true;

	oa_assert_same( 'woocommerce', Octave_Addons_Perf_Context::bypass_reason( 'media' ) );

}

function test_logged_in_users_always_see_unoptimised_pages(): void {

	$GLOBALS['oa_flags']['logged_in'] = true;
	oa_set_settings( 'performance-cache', [ 'optimize_logged_in' => true ] );

	oa_assert_same( 'logged-in', Octave_Addons_Perf_Context::bypass_reason( 'media' ), 'the old setting no longer opts in' );

}

function test_cache_module_has_no_settings_page(): void {

	oa_assert( ! oa_module( 'performance-cache' )->show_in_admin() );

}

/*
DIAGNOSTICS SCAN
---------------------------------------------------------- */

function oa_scanned_request( string $token ): void {

	$_GET[ Octave_Addons_Perf_Log::SCAN_ARG ] = $token;

	Octave_Addons_Perf_Log::maybe_begin_report();
	Octave_Addons_Perf_Log::note( 'media', [ 'tag' => 'img', 'action' => 'lazy' ] );
	Octave_Addons_Perf_Log::save_report();

}

function test_scan_token_survives_the_scanned_request_sanitising_it(): void {

	$token = Octave_Addons_Perf_Log::start_scan();

	oa_assert_same( sanitize_key( $token ), $token, 'token is already a valid key' );

	oa_scanned_request( $token );

	$scan = Octave_Addons_Perf_Log::read_scan( $token );

	oa_assert_same( 'done', $scan['status'] ?? '' );
	oa_assert_same( 'lazy', $scan['report']['media'][0]['action'] ?? '' );

}

function test_scan_is_read_past_this_request_memory(): void {

	$token = Octave_Addons_Perf_Log::start_scan();

	oa_scanned_request( $token );
	Octave_Addons_Perf_Log::read_scan( $token );

	oa_assert( in_array( [ '_transient_oa_perf_scan_' . $token, 'options' ], $GLOBALS['oa_cache_deleted'], true ), 'in-memory option copy dropped' );

	oa_test_reset();
	$GLOBALS['oa_flags']['ext_cache'] = true;

	$token = Octave_Addons_Perf_Log::start_scan();

	oa_scanned_request( $token );

	oa_assert_same( 'done', Octave_Addons_Perf_Log::read_scan( $token )['status'] ?? '' );
	oa_assert( in_array( [ 'oa_perf_scan_' . $token, 'transient', true ], $GLOBALS['oa_cache_reads'], true ), 'persistent cache read forced' );

}

function test_scan_opens_a_buffer_with_no_transformations(): void {

	$_GET[ Octave_Addons_Perf_Log::SCAN_ARG ] = Octave_Addons_Perf_Log::start_scan();

	Octave_Addons_Perf_Log::maybe_begin_report();

	$level = ob_get_level();

	Octave_Addons_Perf_Html::start();

	$started = ob_get_level() > $level;

	if ( $started ) {

		ob_end_clean();

	}

	Octave_Addons_Perf_Log::save_report();

	oa_assert( $started, 'buffer started so the report is saved' );

}

function test_no_optimize_argument_needs_admin_or_site_key(): void {

	$_GET['oa_no_optimize'] = '1';

	oa_assert( Octave_Addons_Perf_Context::can_optimize( 'media' ), 'anonymous ?oa_no_optimize=1 is ignored' );

	$GLOBALS['oa_flags']['logged_in'] = true;
	$GLOBALS['oa_caps']               = [ 'manage_options' ];

	oa_assert_same( 'query-arg', Octave_Addons_Perf_Context::bypass_reason( 'media' ), 'administrator bypass' );

	oa_test_reset();
	$_GET['oa_no_optimize'] = Octave_Addons_Perf_Context::bypass_key();

	oa_assert_same( 'query-arg', Octave_Addons_Perf_Context::bypass_reason( 'media' ), 'site key bypass' );

}

function test_bypass_filter_can_override(): void {

	add_filter( 'octave_addons_perf_bypass_reason', static function ( $reason, $feature ) {

		return 'delay' === $feature ? 'custom' : $reason;

	}, 10, 2 );

	oa_assert_same( 'custom', Octave_Addons_Perf_Context::bypass_reason( 'delay' ) );
	oa_assert_same( '', Octave_Addons_Perf_Context::bypass_reason( 'media' ) );

}

/*
HTML PIPELINE
---------------------------------------------------------- */

function test_failed_transformations_return_original_html(): void {

	$html = '<!doctype html><html><body><p>Original</p></body></html>';

	Octave_Addons_Perf_Html::register( 'media', static function ( string $html ): string {

		throw new RuntimeException( 'parser exploded' );

	} );

	Octave_Addons_Perf_Html::register( 'delay', static function ( string $html ): string {

		return '';

	} );

	oa_assert_same( $html, Octave_Addons_Perf_Html::process( $html ) );
	oa_assert_same( 2, count( Octave_Addons_Perf_Log::entries() ), 'both failures logged' );

}

function test_pipeline_ignores_non_html_and_keeps_successful_work(): void {

	Octave_Addons_Perf_Html::register( 'media', static function ( string $html ): string {

		return str_replace( 'Original', 'Changed', $html );

	} );

	oa_assert_same( '{"a":"Original"}', Octave_Addons_Perf_Html::process( '{"a":"Original"}' ) );
	oa_assert_contains( 'Changed', Octave_Addons_Perf_Html::process( '<html><body>Original</body></html>' ) );

}

function test_pipeline_buffers_flushed_chunks_until_final(): void {

	Octave_Addons_Perf_Html::register( 'media', static function ( string $html ): string {

		return strtoupper( $html );

	} );

	oa_assert_same( '', Octave_Addons_Perf_Html::buffer( '<html><body>a', PHP_OUTPUT_HANDLER_FLUSH ) );
	oa_assert_same( '<HTML><BODY>AB</BODY></HTML>', Octave_Addons_Perf_Html::buffer( 'b</body></html>', PHP_OUTPUT_HANDLER_FINAL ) );

}

function test_disabling_every_module_leaves_output_untouched(): void {

	foreach ( OA_PERF_MODULES as $id ) {

		$module = oa_module( $id );

		if ( ! $module->is_always_enabled() ) {

			$module->run_disabled( $module->get_defaults() );

		}

	}

	oa_module( 'performance-cache' )->run( oa_module( 'performance-cache' )->get_defaults() );

	$transformers = new ReflectionProperty( 'Octave_Addons_Perf_Html', 'transformers' );

	oa_assert_same( [], $transformers->getValue(), 'no page transformations, so no output buffer starts' );
	oa_assert( ! has_filter( 'style_loader_tag' ) && ! has_filter( 'script_loader_tag' ) && ! has_filter( 'wp_preload_resources' ), 'no asset filters' );

}

/*
PURGES
---------------------------------------------------------- */

function test_purge_endpoint_requires_nonce_and_capability(): void {

	$_POST = [ 'scope' => 'all', 'nonce' => 'forged' ];
	$GLOBALS['oa_caps'] = [ 'manage_options' ];

	$response = oa_json_call( [ 'Octave_Addons_Perf_Admin', 'ajax_purge' ] );

	oa_assert( ! $response->success && 403 === $response->status, 'bad nonce rejected' );

	$_POST['nonce']     = wp_create_nonce( Octave_Addons_Perf_Admin::NONCE );
	$GLOBALS['oa_caps'] = [];

	$response = oa_json_call( [ 'Octave_Addons_Perf_Admin', 'ajax_purge' ] );

	oa_assert( ! $response->success && 403 === $response->status, 'missing capability rejected' );

	$GLOBALS['oa_caps'] = [ 'manage_options' ];

	$response = oa_json_call( [ 'Octave_Addons_Perf_Admin', 'ajax_purge' ] );

	oa_assert( $response->success, 'administrator can purge' );
	oa_assert_same( 'success', $response->data['layers'][0]['status'] );

}

function test_purge_all_clears_only_min_folder_and_reports_layers(): void {

	$min     = Octave_Addons_Perf_Store::dir( 'min' );
	$fonts   = Octave_Addons_Perf_Store::dir( 'fonts/abc' );
	$foreign = WP_CONTENT_DIR . '/cache/other-plugin/page.html';

	Octave_Addons_Perf_Store::write( $min . 'a.min.css', 'a{}' );
	Octave_Addons_Perf_Store::write( $fonts . 'f.woff2', 'wOF2' );
	@mkdir( dirname( $foreign ), 0777, true );
	file_put_contents( $foreign, 'keep' );

	$generation = Octave_Addons_Perf_Cache::generation();

	add_filter( 'octave_addons_perf_purge_all_layers', static function ( $report ) {

		$report['host'] = [ 'label' => 'Host', 'status' => 'error', 'message' => 'Down' ];

		return $report;

	} );

	$report = Octave_Addons_Perf_Cache::purge_all( 'all', 'manual' );

	oa_assert( file_exists( $min . 'a.min.css' ), 'minified file kept for pages still cached elsewhere' );
	oa_assert( file_exists( $fonts . 'f.woff2' ), 'self-hosted fonts kept' );
	oa_assert( file_exists( $foreign ), 'other caches untouched' );
	oa_assert_same( $generation + 1, Octave_Addons_Perf_Cache::generation() );
	oa_assert_same( [ 'files', 'host' ], array_keys( $report ) );
	oa_assert_same( 'Full purge', Octave_Addons_Perf_Cache::last_purge()['label'] );
	oa_assert_same( [ 'files' ], array_keys( Octave_Addons_Perf_Cache::purge_all( 'files', 'manual' ) ), 'files scope skips other layers' );

}

function test_retired_minified_files_are_swept_after_the_grace_period(): void {

	$min = Octave_Addons_Perf_Store::dir( 'min' );

	Octave_Addons_Perf_Store::write( $min . 'old.min.js', 'a' );
	Octave_Addons_Perf_Store::write( $min . 'nextgen/k/old.css', 'a{}' );
	Octave_Addons_Perf_Store::write( $min . 'new.min.js', 'b' );
	touch( $min . 'old.min.js', time() - 3 * DAY_IN_SECONDS );
	touch( $min . 'nextgen/k/old.css', time() - 3 * DAY_IN_SECONDS );

	update_option( Octave_Addons_Perf_Cache::RETIRED_OPTION, [ time() - DAY_IN_SECONDS ] );

	oa_assert_same( 0, Octave_Addons_Perf_Cache::sweep_files()['files'], 'nothing goes inside the grace period' );

	update_option( Octave_Addons_Perf_Cache::RETIRED_OPTION, [ time() - Octave_Addons_Perf_Cache::GRACE - 60 ] );

	oa_assert_same( 2, Octave_Addons_Perf_Cache::sweep_files()['files'] );
	oa_assert( ! file_exists( $min . 'old.min.js' ) && ! file_exists( $min . 'nextgen/k/old.css' ), 'retired files and subfolders swept' );
	oa_assert( file_exists( $min . 'new.min.js' ), 'files written after the retirement kept' );

	Octave_Addons_Perf_Cache::purge_all( 'all', 'manual' );

	oa_assert_same( 1, count( oa_cron_hooks( Octave_Addons_Perf_Cache::SWEEP_HOOK ) ), 'a sweep is booked after the grace period' );

}

function test_store_refuses_paths_outside_cache(): void {

	oa_assert( ! Octave_Addons_Perf_Store::write( WP_CONTENT_DIR . '/evil.php', 'x' ) );
	oa_assert( ! Octave_Addons_Perf_Store::write( Octave_Addons_Perf_Store::dir( 'min' ) . '../../../evil.php', 'x' ) );

}

function test_settings_change_purges_only_for_performance_modules(): void {

	Octave_Addons_Perf_Cache::register_invalidation();

	$generation = Octave_Addons_Perf_Cache::generation();

	update_option( OCTAVE_ADDONS_OPTION_KEY, [ 'animations' => [ 'enabled' => true ] ] );

	oa_assert_same( $generation, Octave_Addons_Perf_Cache::generation(), 'unrelated module' );

	do_action( 'shutdown' );

	oa_assert_same( $generation, Octave_Addons_Perf_Cache::generation(), 'unrelated module, after the request' );

	update_option( OCTAVE_ADDONS_OPTION_KEY, [ 'animations' => [ 'enabled' => true ], 'performance-media' => [ 'enabled' => true ] ] );

	oa_assert_same( $generation, Octave_Addons_Perf_Cache::generation(), 'not while the save is running' );

	do_action( 'shutdown' );

	oa_assert_same( $generation + 1, Octave_Addons_Perf_Cache::generation(), 'performance module' );
	oa_assert_same( 'settings', Octave_Addons_Perf_Cache::last_clears()['full']['reason'], 'a full clear, so cached pages lose the old markup' );

}

function test_url_purge_targets_same_origin_urls_only(): void {

	$seen = [];

	add_filter( 'octave_addons_perf_purge_url_layers', static function ( $report, $urls ) use ( &$seen ) {

		$seen = $urls;

		return $report;

	}, 10, 2 );

	Octave_Addons_Perf_Cache::purge_urls( [ 'https://example.com/a/#top', 'https://evil.test/b', 'https://example.com/a/' ], 'content' );

	oa_assert_same( [ 'https://example.com/a/' ], $seen );

}

/*
PIPELINE TIMINGS AND OWNERSHIP
---------------------------------------------------------- */

function test_each_transformer_is_timed_and_failures_still_fail_open(): void {

	$html = '<!doctype html><html><body><p>Original</p></body></html>';

	Octave_Addons_Perf_Html::register( 'media', static function ( string $html ): string {

		throw new RuntimeException( 'parser exploded' );

	} );

	Octave_Addons_Perf_Html::register( 'files', static function ( string $html ): string {

		return str_replace( 'Original', 'Inlined', $html );

	}, 50 );

	oa_assert_contains( 'Inlined', Octave_Addons_Perf_Html::process( $html ), 'the working transformer still applied' );

	$timings = Octave_Addons_Perf_Html::timings();

	oa_assert_same( [ 'oa-media', 'oa-css-inline', 'oa-total' ], array_keys( $timings ) );
	oa_assert( $timings['oa-total'] >= $timings['oa-media'], 'total covers every transformer' );

}

function test_no_buffer_starts_when_every_transformer_is_owned_elsewhere(): void {

	Octave_Addons_Perf_Html::register( 'delay', 'strtoupper', 30, static function (): bool {

		return false;

	} );

	$level = ob_get_level();

	Octave_Addons_Perf_Html::start();

	$started = ob_get_level() > $level;

	if ( $started ) {

		ob_end_clean();

	}

	oa_assert( ! $started, 'nothing to do, so no output buffer' );

}

function test_media_is_not_needed_when_another_plugin_lazy_loads_everything(): void {

	oa_define_plugins();
	update_option( 'wp_rocket_settings', [ 'lazyload' => 1, 'lazyload_iframes' => 1 ] );

	$module = oa_media( [ 'header_eager' => false, 'dimensions' => false, 'lazy_posters' => false, 'lcp' => false ] );

	oa_assert( ! $module->is_needed() );
	oa_assert( oa_media( [ 'lcp' => true ] )->is_needed(), 'learned LCP still needs the page' );

}

function test_server_timing_needs_the_signed_argument(): void {

	oa_assert( ! Octave_Addons_Perf_Html::timing_requested() );

	$_GET[ Octave_Addons_Perf_Html::TIMING_ARG ] = '1';

	oa_assert( ! Octave_Addons_Perf_Html::timing_requested(), 'a guessable value is refused' );

	$_GET[ Octave_Addons_Perf_Html::TIMING_ARG ] = Octave_Addons_Perf_Html::timing_key();

	oa_assert( Octave_Addons_Perf_Html::timing_requested() );

}

/*
LOADER AND ANIMATION PERFORMANCE MODE
---------------------------------------------------------- */

function test_performance_mode_is_opt_in_and_drops_arrival_overlays(): void {

	$loader     = oa_module( 'page-loader' );
	$animations = oa_module( 'animations' );
	$types      = new ReflectionMethod( $loader, 'active_types' );

	oa_assert_same( false, $loader->get_defaults()['performance'], 'existing sites keep their loader' );
	oa_assert_same( false, $animations->get_defaults()['performance'], 'existing sites keep their motion' );

	$s = array_merge( $loader->get_defaults(), [ 'loader_enabled' => true, 'transitions_enabled' => true, 'transition_type' => 'slide-up' ] );

	oa_assert_same( [ 'brand-counter', 'slide-up' ], $types->invoke( $loader, $s ), 'unchanged without performance mode' );
	oa_assert_same( [ '', 'slide-up' ], $types->invoke( $loader, [ 'performance' => true ] + $s ), 'no initial loader, exit transitions kept' );
	oa_assert_same( [ '', '' ], $types->invoke( $loader, [ 'performance' => true, 'transition_type' => 'replay-loader' ] + $s ), 'a replayed loader would cover the arriving page' );
	oa_assert( $animations->sanitize( [ 'performance' => '1' ] )['performance'] );

}

/*
ANIMATION IGNORE SELECTORS
---------------------------------------------------------- */

function test_animation_ignore_selectors_are_normalised_and_mark_breakdance_regions(): void {

	$animations = oa_module( 'animations' );
	$clean      = $animations->sanitize( [ 'ignore_selectors' => ' HEADER, footer, .hero-static, header ' ] );

	oa_assert_same( 'header, footer, .hero-static', $clean['ignore_selectors'] );

	$markup = '<section class="bde-section"><div class="bde-text">Footer</div></section><div class="oa_anim">Extra</div>';

	$GLOBALS['oa_posts'][91] = [ 'breakdance_footer', 'publish' ];
	$animations->run( array_merge( $animations->get_defaults(), [ 'ignore_selectors' => 'footer' ] ) );

	$output = apply_filters( 'breakdance_render_rendered_html', $markup, 91 );

	oa_assert_contains( 'class="bde-section oa-no-anim"', $output );
	oa_assert_contains( 'class="oa_anim oa-no-anim"', $output );
	oa_assert_same( 2, substr_count( $output, 'data-oa-anim-region="footer"' ) );

}
