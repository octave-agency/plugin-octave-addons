<?php

/*
MODULE MANAGER
-- Auto-discovers every module in /modules/<slug>/class-module.php.
-- Each class-module.php file must `return new Your_Module_Class();`.
-- Adding a new module in the future is therefore a purely additive
-- operation: drop a folder in, reload the admin, and a new tab
-- appears. No registry edits required.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Manager {


	/** @var Octave_Addons_Module[] Keyed by module id. */
	protected array $modules = [];

	public function __construct() {

		$this->discover();

	}

	/**
	 * Walk the modules directory and instantiate each module.
	 */
	protected function discover(): void {

		if ( ! is_dir( OCTAVE_ADDONS_MODULES_DIR ) ) {

			return;

		}

		$dirs = glob( OCTAVE_ADDONS_MODULES_DIR . '*', GLOB_ONLYDIR );
		if ( empty( $dirs ) ) {

			return;

		}

		// Sort alphabetically so tab order is predictable.
		sort( $dirs );

		foreach ( $dirs as $dir ) {

			$file = trailingslashit( $dir ) . 'class-module.php';
			if ( ! file_exists( $file ) ) {

				continue;

			}

			$module = include $file;

			if ( $module instanceof Octave_Addons_Module ) {

				$this->modules[ $module->get_id() ] = $module;

			}

		}

		/**
		 * Allow external code to register additional modules programmatically
		 * (e.g. from another plugin or an mu-plugin).
		 *
		 * @param array $modules  Array of Octave_Addons_Module instances keyed by id.
		 */
		$this->modules = apply_filters( 'octave_addons_register_modules', $this->modules );

	}

	/** @return Octave_Addons_Module[] */
	public function all(): array {

		return $this->modules;

	}

	/** @return Octave_Addons_Module[] */
	public function visible_in_admin(): array {

		return array_filter(
			$this->modules,
			static function ( Octave_Addons_Module $module ): bool {
				return $module->show_in_admin();

			}
		);

	}

	public function get( string $id ): ?Octave_Addons_Module {

		return $this->modules[ $id ] ?? null;

	}

	/**
	 * Pull saved settings for a single module, merged with its defaults.
	 */
	public function settings_for( string $id ): array {

		$module = $this->get( $id );
		if ( ! $module ) {

			return [];

		}

		$all_settings = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );
		$saved        = $all_settings[ $id ] ?? [];

		$settings = $module->get_settings( is_array( $saved ) ? $saved : [] );

		if ( $module->is_always_enabled() ) {
			$settings['enabled'] = true;

		}

		return $settings;

	}

	/**
	 * Boot every enabled module so it can register its hooks.
	 */
	public function run_enabled(): void {

		foreach ( $this->modules as $id => $module ) {

			$settings = $this->settings_for( $id );
			if ( $module->is_always_enabled() || ! empty( $settings['enabled'] ) ) {

				$module->run( $settings );

			}

		}

	}

	/**
	 * Sanitize a full submission from the settings page — dispatches
	 * each module's piece to its own sanitize() method.
	 */
	public function sanitize_all( $input ): array {

		$clean = [];
		if ( ! is_array( $input ) ) {

			$input = [];

		}

		foreach ( $this->modules as $id => $module ) {

			$piece         = $input[ $id ] ?? [];
			$clean[ $id ] = $module->sanitize( is_array( $piece ) ? $piece : [] );

		}

		return $clean;

	}

}
