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


	/** Hidden field naming the modules a submission carried. */
	public const SUBMITTED_FIELD = '__oa_submitted';

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

	/*
	ADMIN ENTRIES
	-- One entry per navigation item. Modules that declare the same group id
	-- collapse into a single entry and share one page, while ungrouped modules
	-- stay one entry each. Discovery order is preserved, so a group takes the
	-- position of its first module.
	--
	-- @return array<string, array{id: string, group: string, modules: Octave_Addons_Module[]}>
	---------------------------------------------------------- */

	public function admin_entries(): array {

		$entries = [];

		foreach ( $this->visible_in_admin() as $id => $module ) {

			$group = $module->get_group();
			$key   = '' !== $group ? $group : $id;

			if ( ! isset( $entries[ $key ] ) ) {

				$entries[ $key ] = [
					'id'      => $key,
					'group'   => $group,
					'modules' => [],
				];

			}

			$entries[ $key ]['modules'][ $id ] = $module;

		}

		return $entries;

	}

	/*
	ENTRY ID FOR
	-- Resolves a tab request to the entry that owns it, so links and bookmarks
	-- pointing at a module that has since joined a group still land somewhere.
	---------------------------------------------------------- */

	public function entry_id_for( string $requested ): string {

		$entries = $this->admin_entries();

		if ( isset( $entries[ $requested ] ) ) {

			return $requested;

		}

		foreach ( $entries as $key => $entry ) {

			if ( isset( $entry['modules'][ $requested ] ) ) {

				return $key;

			}

		}

		return '';

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

	/*
	SUBMITTED MODULE IDS
	-- Reads the list of modules the form actually carried. Returns null when the
	-- field is absent, which marks the submission as a full one.
	---------------------------------------------------------- */

	protected function submitted_module_ids( array $input ): ?array {

		if ( ! isset( $input[ self::SUBMITTED_FIELD ] ) ) {

			return null;

		}

		$raw = $input[ self::SUBMITTED_FIELD ];
		$ids = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$ids = array_filter( array_map( 'sanitize_key', $ids ) );

		return array_values( $ids );

	}

	/**
	 * Sanitize a submission from the settings page — dispatches each submitted
	 * module's piece to its own sanitize() method, and carries every module the
	 * form did not contain straight over from the stored option.
	 */
	public function sanitize_all( $input ): array {

		$clean = [];
		if ( ! is_array( $input ) ) {

			$input = [];

		}

		$submitted = $this->submitted_module_ids( $input );
		$stored    = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );
		$stored    = is_array( $stored ) ? $stored : [];

		unset( $input[ self::SUBMITTED_FIELD ] );

		foreach ( $this->modules as $id => $module ) {

			// A page only submits the modules it displays, so the rest keep the values they already hold.
			if ( null !== $submitted && ! in_array( $id, $submitted, true ) && isset( $stored[ $id ] ) && is_array( $stored[ $id ] ) ) {

				$clean[ $id ] = $stored[ $id ];

				continue;

			}

			$piece        = $input[ $id ] ?? [];
			$clean[ $id ] = $module->sanitize( is_array( $piece ) ? $piece : [] );

		}

		return $clean;

	}

}
