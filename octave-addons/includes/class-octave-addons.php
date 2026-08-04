<?php

/*
MAIN PLUGIN SINGLETON — TIES THE MODULE MANAGER, ADMIN UI AND UPDATER
-- together and boots enabled modules.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

final class Octave_Addons {

	protected static ?Octave_Addons $instance = null;

	public Octave_Addons_Module_Manager $modules;
	public Octave_Addons_Admin         $admin;
	public Octave_Addons_Updater       $updater;

	public static function instance(): Octave_Addons {

		if ( null === self::$instance ) {

			self::$instance = new self();

		}
		return self::$instance;

	}

	protected function __construct() {

		$this->modules = new Octave_Addons_Module_Manager();
		$this->admin   = new Octave_Addons_Admin( $this->modules );
		$this->updater = new Octave_Addons_Updater(
			OCTAVE_ADDONS_FILE,
			OCTAVE_ADDONS_GITHUB_REPOSITORY,
			OCTAVE_ADDONS_VERSION
		);

		// Load text domain.
		add_action( 'init', function () {

			load_plugin_textdomain(
				'octave-addons',
				false,
				dirname( OCTAVE_ADDONS_BASENAME ) . '/languages'
			);
		} );

		// Boot enabled modules on the standard hook.
		add_action( 'init', function () {

			$this->modules->run_enabled();
		}, 5 );

	}

	/*
	IS BREAKDANCE ACTIVE
	-- Single source of truth for "is the builder on this site at all".
	-- Checks the boot action first and falls back to the core element class so
	-- the answer is right whether or not breakdance_loaded has fired yet.
	---------------------------------------------------------- */

	public static function is_breakdance_active(): bool {

		return did_action( 'breakdance_loaded' ) > 0
			|| class_exists( '\Breakdance\Elements\Element' )
			|| defined( '__BREAKDANCE_VERSION' );

	}

	/** Prevent cloning / serialization — this is a singleton. */
	protected function __clone() {}
	public function __wakeup() {

		throw new \Exception( 'Cannot unserialize singleton' );

	}

}
