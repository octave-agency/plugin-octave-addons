<?php

/*
PERFORMANCE STATE AND TOOLBAR TESTS
-- One answer to whether Performance is on, and a toolbar that offers only
-- "Performance" and "Clear cache", behind a nonce and a capability
---------------------------------------------------------- */

require_once OCTAVE_ADDONS_DIR . 'includes/class-admin.php';

class WP_Admin_Bar {

	public array $nodes = [];

	public function add_node( array $node ): void {

		$this->nodes[ $node['id'] ] = $node;

	}

}

class OA_Test_Die extends Exception {}

function check_admin_referer( $action ) {

	if ( ( $_GET['_wpnonce'] ?? '' ) !== 'valid-' . $action ) {

		throw new OA_Test_Die( 'bad-nonce' );

	}

	return 1;

}

function wp_die( $message = '' ) {

	throw new OA_Test_Die( (string) $message );

}

function wp_get_referer() {

	return 'https://example.com/about/';

}

/*
HELPERS
---------------------------------------------------------- */

function oa_toolbar(): WP_Admin_Bar {

	$bar = new WP_Admin_Bar();

	Octave_Addons_Perf_Admin::admin_bar( $bar );

	return $bar;

}

/*
PERFORMANCE STATE
---------------------------------------------------------- */

function test_performance_is_off_while_every_user_facing_module_is_off(): void {

	oa_assert( ! Octave_Addons_Perf::is_enabled(), 'defaults' );

	// Always-on modules never count: the cache coordinator and the Breakdance lazy-load policy.
	oa_set_settings( 'performance-cache', [ 'enabled' => true ] );
	oa_set_settings( 'breakdance-lazy-load', [ 'enabled' => true ] );

	oa_assert( ! Octave_Addons_Perf::is_enabled(), 'hidden modules ignored' );

}

function test_any_user_facing_performance_module_turns_performance_on(): void {

	foreach ( [ 'performance-media', 'performance-files', 'performance-page-cache', 'performance-heartbeat', 'performance-database' ] as $id ) {

		oa_test_reset();
		oa_set_settings( $id, [ 'enabled' => true ] );

		oa_assert( Octave_Addons_Perf::is_enabled(), $id );

	}

}

/*
TOOLBAR
---------------------------------------------------------- */

function test_toolbar_is_hidden_while_performance_is_off(): void {

	$GLOBALS['oa_caps'] = [ 'manage_options' ];

	oa_assert_same( [], oa_toolbar()->nodes );

}

function test_toolbar_needs_an_administrator(): void {

	oa_set_settings( 'performance-media', [ 'enabled' => true ] );

	oa_assert_same( [], oa_toolbar()->nodes, 'editor' );

}

function test_toolbar_offers_exactly_one_clear_cache_action(): void {

	oa_set_settings( 'performance-media', [ 'enabled' => true ] );
	oa_set_settings( 'performance-files', [ 'enabled' => true ] );
	oa_cloudflare();

	$GLOBALS['oa_caps'] = [ 'manage_options' ];

	$nodes    = oa_toolbar()->nodes;
	$children = array_values( array_filter( $nodes, static function ( array $node ): bool {

		return 'oa-performance' === ( $node['parent'] ?? '' );

	} ) );

	oa_assert_same( [ 'oa-performance', 'oa-performance-clear' ], array_keys( $nodes ) );
	oa_assert_same( 'Performance', $nodes['oa-performance']['title'] );
	oa_assert_contains( 'page=', $nodes['oa-performance']['href'], 'links to the settings page' );
	oa_assert_same( 1, count( $children ) );
	oa_assert_same( 'Clear cache', $children[0]['title'] );
	oa_assert_contains( '_wpnonce=valid-' . Octave_Addons_Perf_Admin::BAR_ACTION, $children[0]['href'] );

	foreach ( $nodes as $node ) {

		foreach ( [ 'urge', 'inified', 'Cloudflare', 'Varnish', 'URL' ] as $word ) {

			oa_assert_not_contains( $word, $node['title'], 'no implementation details' );

		}

	}

}

function test_clear_cache_checks_the_nonce_and_capability(): void {

	oa_set_settings( 'performance-media', [ 'enabled' => true ] );

	$GLOBALS['oa_caps'] = [ 'manage_options' ];
	$_GET['_wpnonce']   = 'forged';

	try {

		Octave_Addons_Perf_Admin::clear_from_toolbar();
		oa_assert( false, 'a forged nonce must stop the clear' );

	} catch ( OA_Test_Die $error ) {

		oa_assert_same( 'bad-nonce', $error->getMessage() );

	}

	$GLOBALS['oa_caps'] = [];
	$_GET['_wpnonce']   = 'valid-' . Octave_Addons_Perf_Admin::BAR_ACTION;

	try {

		Octave_Addons_Perf_Admin::clear_from_toolbar();
		oa_assert( false, 'an editor must not clear' );

	} catch ( OA_Test_Die $error ) {

		oa_assert_contains( 'permission', $error->getMessage() );

	}

	oa_assert_same( 0, (int) did_action( 'octave_addons_perf_purged_all' ), 'nothing cleared' );

}

function test_clear_cache_clears_every_layer_and_says_so_plainly(): void {

	oa_set_settings( 'performance-media', [ 'enabled' => true ] );
	oa_fake_page_cache();

	$GLOBALS['oa_caps'] = [ 'manage_options' ];
	$_GET['_wpnonce']   = 'valid-' . Octave_Addons_Perf_Admin::BAR_ACTION;

	$report = Octave_Addons_Perf_Admin::clear_from_toolbar();

	oa_assert_same( [ 'all' ], $GLOBALS['oa_cache_calls'], 'the page cache was cleared' );
	oa_assert_same( 'success', $report['files']['status'], 'minified files cleared' );
	oa_assert_same( [ 'message' => 'Cache cleared.', 'warning' => '' ], Octave_Addons_Perf_Admin::notice_text( $report ) );

	$report['page-cache-broken'] = [ 'label' => 'Broken Cache', 'status' => 'error', 'message' => 'API down' ];

	$text = Octave_Addons_Perf_Admin::notice_text( $report );

	oa_assert_same( 'Cache cleared.', $text['message'] );
	oa_assert_contains( 'Broken Cache', $text['warning'], 'a failed layer is named' );
	oa_assert_not_contains( 'API down', $text['warning'], 'details stay on the settings page' );

}
