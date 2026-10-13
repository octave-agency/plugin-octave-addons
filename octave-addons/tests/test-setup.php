<?php

/*
QUICK SETUP TESTS
-- The setup card only shows on untouched sites, each level saves its
-- modules through their own sanitisers, and nothing else is changed
---------------------------------------------------------- */

function test_setup_is_offered_only_while_performance_is_untouched(): void {

	oa_assert( Octave_Addons_Perf_Setup::is_untouched(), 'fresh site' );

	oa_set_settings( 'performance-preload', [ 'enabled' => true ] );

	oa_assert( ! Octave_Addons_Perf_Setup::is_untouched(), 'one switch changed' );

}

function test_balanced_switches_on_the_recommended_modules(): void {

	oa_set_settings( 'notifications-bar', [ 'enabled' => true ] );

	oa_assert( Octave_Addons_Perf_Setup::apply( 'balanced' ) );

	$files = Octave_Addons_Perf::settings( 'performance-files' );
	$delay = Octave_Addons_Perf::settings( 'performance-delay' );

	oa_assert( ! empty( Octave_Addons_Perf::settings( 'performance-page-cache' )['enabled'] ), 'page cache on' );
	oa_assert( $files['enabled'] && $files['minify_css'] && $files['minify_js'] && $files['breakdance_css'], 'files on' );
	oa_assert( ! $files['defer_js'], 'defer is Maximum only' );
	oa_assert( in_array( 'google-analytics', $delay['services'], true ), 'analytics held back' );
	oa_assert( ! empty( Octave_Addons_Perf::settings( 'animations' )['performance'] ), 'animations never hide the first screen' );
	oa_assert( empty( Octave_Addons_Perf::settings( 'performance-database' )['enabled'] ), 'no database cleanup' );
	oa_assert( ! empty( Octave_Addons_Perf::settings( 'notifications-bar' )['enabled'] ), 'other modules untouched' );
	oa_assert( ! Octave_Addons_Perf_Setup::is_untouched(), 'card hidden afterwards' );

}

function test_maximum_switches_everything_on(): void {

	oa_assert( Octave_Addons_Perf_Setup::apply( 'maximum' ) );

	$database = Octave_Addons_Perf::settings( 'performance-database' );

	oa_assert( $database['enabled'] && 'weekly' === $database['schedule'], 'weekly cleanup' );
	oa_assert_same( array_keys( Octave_Addons_Perf_Script_Delayer::services() ), Octave_Addons_Perf::settings( 'performance-delay' )['services'], 'every service held back' );
	oa_assert( ! empty( Octave_Addons_Perf::settings( 'performance-cloudflare' )['enabled'] ) );

}

function test_unknown_setup_levels_change_nothing(): void {

	$before = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );

	oa_assert( ! Octave_Addons_Perf_Setup::apply( 'turbo' ) );
	oa_assert_same( $before, get_option( OCTAVE_ADDONS_OPTION_KEY, [] ) );

}
