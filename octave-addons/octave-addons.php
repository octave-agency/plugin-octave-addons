<?php

/**
 * Plugin Name:       Octave Addons
 * Plugin URI:        https://github.com/octave-agency/plugin-octave-addons
 * Description:       A modular collection of Octave site add-ons.
 * Version:           1.5.1
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

define( 'OCTAVE_ADDONS_GITHUB_REPOSITORY', 'octave-agency/plugin-octave-addons' );

unset( $octave_addons_plugin_data );

// -------------------------------------------------------------------------
// Autoload core classes
// -------------------------------------------------------------------------

require_once OCTAVE_ADDONS_DIR . 'includes/class-module.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-module-manager.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-admin.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-updater.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-octave-addons.php';
require_once OCTAVE_ADDONS_DIR . 'includes/class-fields.php';

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
} );

register_deactivation_hook( __FILE__, function () {
	// Clear update caches so any future re-activation forces a fresh check.
	delete_site_transient( 'update_plugins' );
	delete_site_transient( 'octave_addons_github_release' );
} );
