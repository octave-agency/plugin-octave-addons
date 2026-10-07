<?php

/*
ADMIN EXPERIENCE TESTS
-- Every admin stylesheet loads as its own file from the plugin, and only
-- for the integrations active on the site.
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

function is_ssl() {

	return true;

}

function oa_admin_styles( OA_Test_Admin_Experience $admin, string $hook ): array {

	update_option( OCTAVE_ADDONS_OPTION_KEY, [ Octave_Addons_Admin_Experience::MODULE_ID => [ 'enabled' => true ] ] );

	$admin->enqueue_assets( $hook );

	return array_values( array_filter( $GLOBALS['oa_enqueued'], static function ( $handle ) {

		return 0 === strpos( (string) $handle, 'octave-addons-admin-experience' );

	} ) );

}

function test_admin_stylesheets_load_separately_for_active_integrations(): void {

	$admin = new OA_Test_Admin_Experience();

	oa_assert_same( [
		'octave-addons-admin-experience',
		'octave-addons-admin-experience',
		'octave-addons-admin-experience-integrations',
	], oa_admin_styles( $admin, 'index.php' ), 'base style and script, then integrations' );

	oa_test_reset();

	$admin->active = [ 'woocommerce', 'imagify', 'rank-math', 'activity-log' ];

	oa_assert_same( [
		'octave-addons-admin-experience',
		'octave-addons-admin-experience',
		'octave-addons-admin-experience-integrations',
		'octave-addons-admin-experience-cookieyes',
		'octave-addons-admin-experience-woocommerce',
		'octave-addons-admin-experience-imagify',
		'octave-addons-admin-experience-rank-math',
		'octave-addons-admin-experience-activity-log',
	], oa_admin_styles( $admin, 'toplevel_page_cookie-law-info' ) );

	oa_assert( ! is_dir( WP_CONTENT_DIR . '/uploads/octave-addons/admin-css' ), 'no combined file is written' );

}
