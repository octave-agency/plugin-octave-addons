<?php

/**
 * Plugin Name:       Octave Addons
 * Plugin URI:        https://www.octaveagency.com/
 * Description:       A modular collection of Octave site add-ons.
 * Version:           3.39.1
 * Author:            Octave Agency
 * Author URI:        https://octaveagency.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       octave-addons
 * Requires at least: 5.8
 * Requires PHP:      7.4
 *
 * Update URI:        https://github.com/octave-agency/plugin-octave-addons
 */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

// -------------------------------------------------------------------------
// Constants
// -------------------------------------------------------------------------

$octave_addons_plugin_data = get_file_data( __FILE__, [ 'version' => 'Version' ], 'plugin' );

define( 'OCTAVE_ADDONS_VERSION',      $octave_addons_plugin_data['version'] );
define( 'OCTAVE_ADDONS_FILE',         __FILE__ );
define( 'OCTAVE_ADDONS_BASENAME',     plugin_basename( __FILE__ ) );
define( 'OCTAVE_ADDONS_DIR',          plugin_dir_path( __FILE__ ) );
define( 'OCTAVE_ADDONS_URL',          plugin_dir_url( __FILE__ ) );
define( 'OCTAVE_ADDONS_MODULES_DIR',  OCTAVE_ADDONS_DIR . 'modules/' );
define( 'OCTAVE_ADDONS_OPTION_KEY',   'octave_addons_settings' );
define( 'OCTAVE_ADDONS_SLUG',         'octave-addons' );
define( 'OCTAVE_ADDONS_ADMIN_EXPERIENCE_OPTION', 'octave_addons_admin_experience_enabled' );

define( 'OCTAVE_ADDONS_GITHUB_REPOSITORY', 'octave-agency/plugin-octave-addons' );

unset( $octave_addons_plugin_data );

// -------------------------------------------------------------------------
// Autoload core classes
// -------------------------------------------------------------------------

require_once OCTAVE_ADDONS_DIR . 'includes/class-icons.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-builders.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-module.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-module-manager.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-admin-experience.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-admin.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-elements-manifest.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-updater.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-site-status.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-user-profile.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-octave-addons.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-fields.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-colors.php';

// -------------------------------------------------------------------------
// Boot
// -------------------------------------------------------------------------

add_action( 'plugins_loaded', [ 'Octave_Addons', 'instance' ], 5 );

// -------------------------------------------------------------------------
// Activation / deactivation
// -------------------------------------------------------------------------

register_activation_hook( __FILE__, function () {

	// Ensure defaults exist so the admin screen isn't blank on first load.
	if ( false === get_option( OCTAVE_ADDONS_OPTION_KEY ) ) {

		add_option( OCTAVE_ADDONS_OPTION_KEY, [] );

	}

	// Fingerprint the shipped elements while they are still pristine.
	Octave_Addons_Elements_Manifest::build();

	Octave_Addons_Site_Status::install_maintenance_drop_in();
	Octave_Addons_Site_Status::install_php_error_drop_in();

} );

register_deactivation_hook( __FILE__, function () {

	// Clear update caches so any future re-activation forces a fresh check.
	delete_site_transient( 'update_plugins' );
	delete_site_transient( 'octave_addons_github_release' );

	Octave_Addons_Site_Status::remove_status_drop_ins();

	// Performance background jobs are booked again when their modules next run.
	foreach ( [ 'octave_addons_perf_fonts_refresh', 'octave_addons_perf_fonts_fetch', 'octave_addons_perf_cf_flush', 'octave_addons_perf_db_cleanup', 'octave_addons_perf_db_cleanup_continue', 'octave_addons_perf_warm', 'octave_addons_perf_imagify_verify', 'octave_addons_perf_page_cache_gc' ] as $hook ) {

		wp_clear_scheduled_hook( $hook );

	}

	// Octave's page-cache drop-in and stored pages only; anyone else's drop-in is left alone.
	if ( class_exists( 'Octave_Addons_Perf_Disk_Cache' ) ) {

		Octave_Addons_Perf_Disk_Cache::uninstall();

	}

} );
