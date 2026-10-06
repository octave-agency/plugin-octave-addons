<?php

/*
ADMIN EXPERIENCE TESTS
-- Verifies active-plugin CSS selection and the generated normal-admin bundle.
---------------------------------------------------------- */

require_once dirname( __DIR__ ) . '/includes/class-admin-experience.php';

class OA_Test_Admin_Experience extends Octave_Addons_Admin_Experience {

	public array $active = [];

	public function is_woocommerce_active(): bool {

		return in_array( 'woocommerce', $this->active, true );

	}

	public function is_imagify_active(): bool {

		return in_array( 'imagify', $this->active, true );

	}

	public function is_rank_math_active(): bool {

		return in_array( 'rank-math', $this->active, true );

	}

	public function is_wp_activity_log_active(): bool {

		return in_array( 'activity-log', $this->active, true );

	}

}

function test_admin_css_files_only_include_active_integrations(): void {

	$admin = new OA_Test_Admin_Experience();

	oa_assert_same( [
		'assets/css/admin-experience/base.css',
		'assets/css/admin-experience/integrations.css',
	], $admin->admin_css_files( 'index.php' ) );

	$admin->active = [ 'woocommerce', 'imagify', 'rank-math', 'activity-log' ];

	oa_assert_same( [
		'assets/css/admin-experience/base.css',
		'assets/css/admin-experience/integrations.css',
		'assets/css/admin-experience/cookieyes.css',
		'assets/css/admin-experience/woocommerce.css',
		'assets/css/admin-experience/imagify.css',
		'assets/css/admin-experience/rank-math.css',
		'assets/css/admin-experience/activity-log.css',
	], $admin->admin_css_files( 'toplevel_page_cookie-law-info' ) );

}

function test_admin_css_bundle_combines_selected_sources_once(): void {

	$admin         = new OA_Test_Admin_Experience();
	$admin->active = [ 'imagify' ];
	$bundle        = $admin->admin_css_bundle( 'upload.php' );
	$path          = trailingslashit( WP_CONTENT_DIR ) . 'uploads/octave-addons/admin-css/admin-' . $bundle['version'] . '.css';

	oa_assert( is_file( $path ), 'bundle was written' );

	$css = file_get_contents( $path );

	oa_assert_contains( 'assets/css/admin-experience/base.css', $css, 'base included' );
	oa_assert_contains( 'assets/css/admin-experience/integrations.css', $css, 'integrations included' );
	oa_assert_contains( 'assets/css/admin-experience/imagify.css', $css, 'active plugin included' );
	oa_assert_not_contains( 'assets/css/admin-experience/woocommerce.css', $css, 'inactive plugin excluded' );
	oa_assert_same( $bundle, $admin->admin_css_bundle( 'upload.php' ), 'existing immutable bundle reused' );

}
