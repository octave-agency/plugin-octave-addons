<?php

/*
ABSTRACT BASE CLASS FOR EVERY OCTAVE ADDONS MODULE
-- To add a new module, create a folder under /modules/ with a class-module.php
-- file that returns an instance of a class extending Octave_Addons_Module.
-- The Module Manager auto-discovers and registers it — no code changes
-- required anywhere else.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

abstract class Octave_Addons_Module {


	/**
	 * Unique slug for this module (folder-name style, e.g. "empty-link-highlighter").
	 * Used as the settings key and admin tab id.
	 */
	abstract public function get_id(): string;

	/**
	 * Human-readable title shown in the admin UI.
	 */
	abstract public function get_title(): string;

	/**
	 * Short one-line description shown under the title in the admin UI.
	 */
	abstract public function get_description(): string;

	/**
	 * Default values for this module's settings.
	 * Must always include an 'enabled' key.
	 *
	 * @return array
	 */
	public function get_defaults(): array {

		return [ 'enabled' => false ];

	}

	/**
	 * Whether this module should appear in the Octave Addons admin UI.
	 */
	public function show_in_admin(): bool {

		return true;

	}

	/**
	 * Whether this module should run regardless of saved toggle state.
	 */
	public function is_always_enabled(): bool {

		return false;

	}

	/**
	 * Called only when the module is enabled. Register hooks here.
	 * This is where the module does its real work on the site.
	 */
	abstract public function run( array $settings ): void;

	/**
	 * Render the settings fields for this module's admin tab.
	 *
	 * Helper methods like $this->field_name('color') will produce the
	 * correctly nested input name so WordPress Settings API can persist it.
	 */
	public function render_settings( array $settings ): void {

		// Default: only an enabled toggle. Override in subclasses.
		echo '<p><em>' . esc_html__( 'No additional settings.', 'octave-addons' ) . '</em></p>';

	}

	/**
	 * Sanitize raw POST input for this module.
	 */
	public function sanitize( $input ): array {

		$clean = $this->get_defaults();
		$clean['enabled'] = $this->is_always_enabled() || ! empty( $input['enabled'] );
		return $clean;

	}

	// ---- Helpers for subclasses --------------------------------------------

	/**
	 * Produce the correctly namespaced <input name="..."> for a setting key,
	 * so it lands under octave_addons_settings[<module_id>][<key>].
	 */
	protected function field_name( string $key ): string {

		return sprintf( '%s[%s][%s]', OCTAVE_ADDONS_OPTION_KEY, $this->get_id(), $key );

	}

	/**
	 * Produce a DOM id for a setting key.
	 */
	protected function field_id( string $key ): string {

		return sprintf( 'oa-%s-%s', $this->get_id(), $key );

	}

	/**
	 * Merge user-saved settings on top of defaults.
	 */
	public function get_settings( array $saved ): array {

		return wp_parse_args( $saved, $this->get_defaults() );

	}

}
