<?php

/*
BREAKDANCE SESSION TESTS
-- View and session counting is switched off only when Breakdance's saved
-- data holds no Page View Count or Session Count rule, kept when one is
-- used or the check cannot be sure, and the check is redone after saves
---------------------------------------------------------- */

if ( ! defined( 'ARRAY_A' ) ) {

	define( 'ARRAY_A', 'ARRAY_A' );

}

/*
FAKE DATABASE
-- Answers the two scan queries from fixed rows and counts them
---------------------------------------------------------- */

class OA_Test_Wpdb {

	public string $postmeta   = 'wp_postmeta';
	public string $posts      = 'wp_posts';
	public string $options    = 'wp_options';
	public string $last_error = '';
	public array $meta_rows   = [];
	public array $option_rows = [];
	public int $queries       = 0;
	public bool $fail         = false;

	public function esc_like( $text ) {

		return addcslashes( (string) $text, '_%\\' );

	}

	public function prepare( $query, ...$args ) {

		return $query;

	}

	public function get_results( $query, $output = null ) {

		$this->queries++;
		$this->last_error = $this->fail ? 'Table is marked as crashed' : '';

		return false !== strpos( $query, $this->postmeta ) ? $this->meta_rows : $this->option_rows;

	}

}

function oa_wpdb( array $meta = [], array $options = [] ): OA_Test_Wpdb {

	$db              = new OA_Test_Wpdb();
	$db->meta_rows   = $meta;
	$db->option_rows = $options;

	$GLOBALS['wpdb'] = $db;

	return $db;

}

/** A Breakdance tree as it is stored: JSON holding the tree as a JSON string. */
function oa_bd_tree( string $slug ): string {

	$tree = wp_json_encode( [ 'root' => [ 'children' => [ [ 'data' => [ 'properties' => [ 'settings' => [ 'conditions' => [ 'conditions' => [ [ [ 'ruleSlug' => $slug, 'operand' => 'is greater than', 'value' => '2' ] ] ] ] ] ] ] ] ] ] ] );

	return wp_json_encode( [ 'tree_json_string' => $tree ] );

}

function oa_sessions_on(): void {

	oa_set_settings( 'performance-media', [ 'enabled' => true ] );
	Octave_Addons_Perf_Sessions::boot();

}

function oa_tracking_disabled(): bool {

	return (bool) apply_filters( 'breakdance_disable_track_view_and_session_counts', false );

}

/*
TESTS
---------------------------------------------------------- */

function test_tracking_is_switched_off_when_no_session_condition_is_used(): void {

	oa_sessions_on();
	oa_wpdb( [ [ 'id' => 8, 'value' => oa_bd_tree( 'post_type' ) . ' mentions page_views in text' ] ] );

	oa_assert( oa_tracking_disabled(), 'no PHP session, no cookies' );
	oa_assert_same( 'unused', Octave_Addons_Perf_Sessions::state()['state'] );
	oa_assert_same( '', Octave_Addons_Perf_Sessions::message( Octave_Addons_Perf_Sessions::state() ), 'no warning' );

}

function test_session_dependent_designs_keep_tracking_and_warn(): void {

	oa_sessions_on();
	oa_wpdb( [ [ 'id' => 12, 'value' => oa_bd_tree( 'page_views' ) ] ] );

	oa_assert( ! oa_tracking_disabled(), 'the condition keeps working' );
	oa_assert_same( [ 'state' => 'used', 'where' => [ '12' ] ], array_intersect_key( Octave_Addons_Perf_Sessions::state(), [ 'state' => 1, 'where' => 1 ] ) );
	oa_assert_contains( 'Pages cannot be saved for quick loading', Octave_Addons_Perf_Sessions::message( Octave_Addons_Perf_Sessions::state() ) );
	oa_assert_contains( '12', Octave_Addons_Perf_Sessions::message( Octave_Addons_Perf_Sessions::state() ), 'names where' );

	oa_test_reset();
	oa_sessions_on();
	oa_wpdb( [], [ [ 'id' => 'breakdance_popup_settings', 'value' => wp_json_encode( [ 'rules' => '{"ruleSlug":"session_count"}' ] ) ] ] );

	oa_assert( ! oa_tracking_disabled(), 'found in global data too' );

}

function test_template_conditions_are_recognised_in_any_escaping(): void {

	oa_assert( Octave_Addons_Perf_Sessions::uses_condition( '{"ruleSlug":"session_count"}' ) );
	oa_assert( Octave_Addons_Perf_Sessions::uses_condition( oa_bd_tree( 'session_count' ) ) );
	oa_assert( Octave_Addons_Perf_Sessions::uses_condition( wp_json_encode( oa_bd_tree( 'page_views' ) ) ), 'escaped twice' );
	oa_assert( ! Octave_Addons_Perf_Sessions::uses_condition( '{"text":"page_views and session_count"}' ), 'a mention is not a rule' );

}

function test_uncertain_detection_preserves_tracking(): void {

	oa_sessions_on();

	$db       = oa_wpdb( [ [ 'id' => 1, 'value' => '' ] ] );
	$db->fail = true;

	oa_assert( ! oa_tracking_disabled(), 'database error' );
	oa_assert_same( 'unknown', Octave_Addons_Perf_Sessions::state()['state'] );
	oa_assert_contains( 'could not confirm', Octave_Addons_Perf_Sessions::message( Octave_Addons_Perf_Sessions::state() ) );

	oa_test_reset();
	oa_sessions_on();

	oa_assert( ! oa_tracking_disabled(), 'no database at all' );

	oa_test_reset();
	oa_sessions_on();
	oa_wpdb( array_fill( 0, 201, [ 'id' => 1, 'value' => 'page_views' ] ) );

	oa_assert( ! oa_tracking_disabled(), 'too many candidates to check' );

}

function test_detection_is_cached_and_redone_after_a_breakdance_save(): void {

	oa_sessions_on();

	$db = oa_wpdb();

	oa_tracking_disabled();
	oa_tracking_disabled();

	oa_assert_same( 2, $db->queries, 'scanned once (two queries)' );

	$db->meta_rows = [ [ 'id' => 8, 'value' => oa_bd_tree( 'page_views' ) ] ];

	do_action( 'breakdance_after_save_document', 8 );

	oa_assert( ! oa_tracking_disabled(), 'the new condition is seen straight away' );
	oa_assert_same( 4, $db->queries );

}

function test_tracking_is_left_alone_while_performance_is_off_and_in_the_builder(): void {

	oa_wpdb();
	Octave_Addons_Perf_Sessions::boot();

	oa_assert( ! oa_tracking_disabled(), 'Performance off' );

	oa_sessions_on();

	$GLOBALS['oa_flags']['admin'] = true;

	oa_assert( ! oa_tracking_disabled(), 'admin keeps the conditions on offer' );

	$GLOBALS['oa_flags']['admin'] = false;

	oa_assert( oa_tracking_disabled(), 'front end' );

}

function test_cache_blockers_name_sessions_cookies_and_headers(): void {

	$reasons = Octave_Addons_Perf_Sessions::cache_blockers( [ 'PHPSESSID=abc; path=/', 'breakdance_view_count=3', 'breakdance_session_count=1' ], 'no-store, no-cache, must-revalidate' );

	oa_assert_contains( 'tracked with a session', $reasons[0] );
	oa_assert_contains( 'Breakdance', $reasons[1] );
	oa_assert_contains( 'PHPSESSID, breakdance_view_count, breakdance_session_count', $reasons[2] );
	oa_assert_not_contains( 'abc', implode( ' ', $reasons ), 'cookie values never shown' );
	oa_assert_contains( 'no-store', $reasons[3] );
	oa_assert_same( [], Octave_Addons_Perf_Sessions::cache_blockers( [], 'public, max-age=600' ) );

}
