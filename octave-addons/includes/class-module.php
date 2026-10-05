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
	 * Group id this module belongs to, or '' for a standalone tab.
	 *
	 * Modules sharing a group id are collapsed into one navigation entry and
	 * rendered on the same page, each keeping its own settings key, its own
	 * enable switch, and its own sanitize().
	 */
	public function get_group(): string {

		return '';

	}

	/**
	 * Sort weight inside a group page, lowest first.
	 *
	 * Modules sharing a group are rendered in this order rather than in
	 * discovery order, so a group can lead with its headline module without
	 * anyone having to rename a folder. Ties keep discovery order.
	 */
	public function get_order(): int {

		return 0;

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

	/*
	RUN DISABLED
	-- Called on every request the module is switched off, so a module that
	-- leaves something behind on the page — an admin bar node, a body class —
	-- can clear it. Most modules need nothing here.
	---------------------------------------------------------- */

	public function run_disabled( array $settings ): void {

	}

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

	/*
	IS BUILDER REQUEST
	-- Detects Breakdance builder canvases, server-side renders and the block
	-- editor iframe without disabling frontend features across all of wp-admin
	---------------------------------------------------------- */

	public static function is_builder_request(): bool {

		$breakdance_mode = isset( $_GET['breakdance'] ) ? sanitize_key( wp_unslash( $_GET['breakdance'] ) ) : '';
		$admin_page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$iframe_mode     = isset( $_GET['breakdance_iframe'] ) ? sanitize_key( wp_unslash( $_GET['breakdance_iframe'] ) ) : '';

		if ( 'builder' === $breakdance_mode || isset( $_GET['breakdance_frame'] ) || isset( $_GET['breakdance_gutenberg_iframe'] ) ) {

			return true;

		}

		if ( '' !== $iframe_mode || isset( $_GET['breakdance_open_document'] ) ) {

			return true;

		}

		if ( is_admin() && false !== strpos( $admin_page, 'breakdance' ) ) {

			return true;

		}

		return false;

	}

	/*
	IS SENSITIVE WOOCOMMERCE VIEW
	-- Cart, checkout and account views replace parts of the DOM via AJAX
	-- fragments during critical flows, so motion features stay off them
	---------------------------------------------------------- */

	public static function is_sensitive_woocommerce_view(): bool {

		foreach ( [ 'is_cart', 'is_checkout', 'is_account_page' ] as $tag ) {

			if ( function_exists( $tag ) && $tag() ) {

				return true;

			}

		}

		return false;

	}

	/*
	FILE VERSION
	-- Uses the file mtime as asset version so edits bust browser caches
	-- without having to bump the plugin version
	---------------------------------------------------------- */

	public static function file_version( string $path ): string {

		if ( file_exists( $path ) ) {

			return (string) filemtime( $path );

		}

		return OCTAVE_ADDONS_VERSION;

	}

}
