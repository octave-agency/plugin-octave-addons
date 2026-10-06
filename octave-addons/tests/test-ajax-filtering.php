<?php

/*
AJAX FILTERING TESTS
-- Assets load only where a Breakdance loop is filtered or controls render,
-- and Breakdance's loops are recognised by the tag its query filter adds
---------------------------------------------------------- */

class WP_Query {

	public array $query;
	public array $query_vars;
	protected bool $main;

	public function __construct( $args = [], bool $main = false ) {

		$this->query      = is_string( $args ) ? wp_parse_args_string( $args ) : (array) $args;
		$this->query_vars = $this->query;
		$this->main       = $main;

	}

	public function get( $key, $default = '' ) {

		return $this->query_vars[ $key ] ?? $default;

	}

	public function set( $key, $value ): void {

		$this->query_vars[ $key ] = $value;

	}

	public function is_main_query(): bool {

		return $this->main;

	}

	public function is_feed(): bool {

		return false;

	}

}

function wp_parse_args_string( string $args ): array {

	parse_str( $args, $parsed );

	return $parsed;

}

function get_post_type_object( $post_type ) {

	return in_array( $post_type, [ 'post', 'page' ], true ) ? (object) [ 'name' => $post_type, 'public' => true ] : null;

}

function oa_filtering(): Octave_Addons_Module_Breakdance_Ajax_Filtering {

	Octave_Addons_Module_Breakdance_Ajax_Filtering::reset();

	return oa_module( 'breakdance-ajax-filtering' );

}

function test_unrelated_pages_receive_no_filtering_assets(): void {

	$module = oa_filtering();

	$module->filter_loop_query( new WP_Query( [ 'post_type' => 'post', 'posts_per_page' => 5 ] ) );

	do_action( 'wp_enqueue_scripts' );
	Octave_Addons_Module_Breakdance_Ajax_Filtering::register_assets();

	oa_assert( isset( $GLOBALS['oa_registered'][ Octave_Addons_Module_Breakdance_Ajax_Filtering::HANDLE ] ), 'registered early' );
	oa_assert_same( [], $GLOBALS['oa_enqueued'], 'an untagged secondary query is not adopted, so nothing loads' );

	ob_start();
	$module->render_automatic_controls();

	oa_assert_same( '', ob_get_clean(), 'no payload either' );

}

function test_adopted_breakdance_query_enqueues_deferred_minified_assets(): void {

	$module = oa_filtering();

	do_action( 'wp_enqueue_scripts' );
	Octave_Addons_Module_Breakdance_Ajax_Filtering::register_assets();

	$args = Octave_Addons_Module_Breakdance_Ajax_Filtering::mark_breakdance_query( [ 'post_type' => 'post', 'posts_per_page' => 6 ] );

	$module->filter_loop_query( new WP_Query( $args ) );

	$handle = Octave_Addons_Module_Breakdance_Ajax_Filtering::HANDLE;

	oa_assert_same( [ $handle, $handle ], $GLOBALS['oa_enqueued'], 'style and script' );
	oa_assert_contains( 'filtering.min.js', $GLOBALS['oa_registered'][ $handle ]['src'] );
	oa_assert_same( 'defer', $GLOBALS['oa_registered'][ $handle ]['args']['strategy'] );

}

function test_main_query_adoption_waits_for_registration(): void {

	$module = oa_filtering();

	$module->filter_breakdance_query( [ 'post_type' => 'post', 'posts_per_page' => 4 ] );

	oa_assert_same( [], $GLOBALS['oa_enqueued'], 'not enqueued before the scripts are registered' );

	do_action( 'wp_enqueue_scripts' );
	Octave_Addons_Module_Breakdance_Ajax_Filtering::register_assets();

	oa_assert_same( 2, count( $GLOBALS['oa_enqueued'] ), 'enqueued once registered' );

}

function test_text_queries_are_tagged_too(): void {

	$tagged = Octave_Addons_Module_Breakdance_Ajax_Filtering::mark_breakdance_query( 'post_type=post&posts_per_page=3' );

	oa_assert_same( '1', ( new WP_Query( $tagged ) )->get( Octave_Addons_Module_Breakdance_Ajax_Filtering::QUERY_MARKER ) );
	oa_assert_same( '', Octave_Addons_Module_Breakdance_Ajax_Filtering::mark_breakdance_query( '' ), 'empty left alone' );

}

function test_builder_requests_never_load_filtering_assets(): void {

	oa_filtering();

	$_GET['breakdance'] = 'builder';

	do_action( 'wp_enqueue_scripts' );
	Octave_Addons_Module_Breakdance_Ajax_Filtering::register_assets();
	Octave_Addons_Module_Breakdance_Ajax_Filtering::enqueue_assets();

	oa_assert_same( [], $GLOBALS['oa_registered'] );
	oa_assert_same( [], $GLOBALS['oa_enqueued'] );

}
