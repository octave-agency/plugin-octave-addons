<?php

/*
OWNERSHIP TESTS
-- The integration registry behind handled_elsewhere(): settings, not mere
-- activation, decide ownership, and two optimisers at once are reported
---------------------------------------------------------- */

function oa_define_plugins(): void {

	foreach ( [ 'WP_ROCKET_VERSION' => '3.18', 'PERFMATTERS_VERSION' => '2.3', 'LSCWP_V' => '6.5', 'BREEZE_VERSION' => '2.1' ] as $constant => $version ) {

		if ( ! defined( $constant ) ) {

			define( $constant, $version );

		}

	}

}

function test_active_plugin_with_feature_off_owns_nothing(): void {

	oa_define_plugins();

	update_option( 'wp_rocket_settings', [ 'lazyload' => 0, 'delay_js' => 0 ] );
	update_option( 'perfmatters_options', [ 'lazyload' => [ 'lazy_loading' => 0 ] ] );

	oa_assert_same( '', Octave_Addons_Perf::handled_elsewhere( 'lazy_images' ) );
	oa_assert_same( '', Octave_Addons_Perf::handled_elsewhere( 'delay' ) );
	oa_assert_same( 'WP Rocket', Octave_Addons_Perf::handled_elsewhere( 'page_cache' ), 'a configured WP Rocket caches pages' );

}

function test_settings_from_each_integration_are_read(): void {

	oa_define_plugins();

	update_option( 'perfmatters_options', [ 'assets' => [ 'delay_js' => 1, 'minify_css' => 1 ], 'fonts' => [ 'local_google_fonts' => 1 ] ] );
	update_option( 'litespeed.conf.optm-js_defer', 1 );
	update_option( 'litespeed.conf.optm-js_min', 1 );
	update_option( 'breeze_basic_settings', [ 'breeze-active' => '1' ] );

	oa_assert_same( 'Perfmatters', Octave_Addons_Perf::handled_elsewhere( 'delay' ), 'LiteSpeed defer (1) is not delay (2)' );
	oa_assert_same( 'LiteSpeed Cache', Octave_Addons_Perf::handled_elsewhere( 'minify_js' ) );
	oa_assert_same( 'Perfmatters', Octave_Addons_Perf::handled_elsewhere( 'google_fonts' ) );
	oa_assert_same( 'Breeze', Octave_Addons_Perf::handled_elsewhere( 'page_cache' ) );

}

function test_duplicate_optimizers_are_reported_as_conflicts(): void {

	oa_define_plugins();

	update_option( 'wp_rocket_settings', [ 'lazyload' => 1 ] );
	update_option( 'perfmatters_options', [ 'lazyload' => [ 'lazy_loading' => 1 ] ] );
	oa_set_settings( 'performance-media', [ 'enabled' => true ] );

	$report = Octave_Addons_Perf_Owners::report();

	oa_assert_same( 'conflict', $report['lazy_images']['status'] );
	oa_assert_same( [ 'WP Rocket', 'Perfmatters' ], $report['lazy_images']['owners'] );
	oa_assert_same( [ 'lazy_images' ], array_keys( Octave_Addons_Perf_Owners::conflicts() ) );
	oa_assert_same( 'octave', $report['lazy_iframes']['status'], 'Octave alone owns iframes' );

}

function test_octave_steps_aside_for_a_single_other_owner(): void {

	oa_define_plugins();

	update_option( 'wp_rocket_settings', [ 'minify_css' => 1 ] );
	oa_set_settings( 'performance-files', [ 'enabled' => true, 'minify_css' => true ] );

	$row = Octave_Addons_Perf_Owners::report()['minify_css'];

	oa_assert_same( 'deferred', $row['status'] );
	oa_assert( $row['octave'] );

}

function test_third_parties_declare_ownership_through_the_filter(): void {

	add_filter( 'octave_addons_perf_handled_elsewhere', static function ( $owner, $feature ) {

		return 'nextgen_images' === $feature ? 'Custom CDN' : $owner;

	}, 10, 2 );

	oa_assert_same( 'Custom CDN', Octave_Addons_Perf::handled_elsewhere( 'nextgen_images' ) );
	oa_assert_same( 'external', Octave_Addons_Perf_Owners::report()['nextgen_images']['status'] );

}
