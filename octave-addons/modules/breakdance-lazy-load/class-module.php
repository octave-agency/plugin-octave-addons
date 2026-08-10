<?php

/*
BREAKDANCE LAZY LOAD
-- Turns Breakdance's own Lazy Load toggles off, because lazy loading on
-- Octave sites is handled by a third-party performance plugin
-- Every element that exposes a "Lazy Load" toggle stores it under a
-- lazy_load property, so both the builder defaults and the rendered
-- output are walked for that key and forced to off
-- Always on and hidden from the admin — there is nothing to configure
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Breakdance_Lazy_Load extends Octave_Addons_Module {

	/**
	 * Property key every Breakdance Lazy Load toggle writes to.
	 */
	protected const LAZY_KEY = 'lazy_load';

	/*
	GET ID
	-- Returns the module settings key
	---------------------------------------------------------- */

	public function get_id(): string {

		return 'breakdance-lazy-load';

	}

	/*
	GET TITLE
	-- Names the module for anything that lists modules internally
	---------------------------------------------------------- */

	public function get_title(): string {

		return __( 'Breakdance Lazy Load', 'octave-addons' );

	}

	/*
	GET DESCRIPTION
	-- Describes the module for anything that lists modules internally
	---------------------------------------------------------- */

	public function get_description(): string {

		return __( 'Keeps every Breakdance Lazy Load toggle off so images, backgrounds and embeds are left to the site\'s third-party performance plugin.', 'octave-addons' );

	}

	/*
	SHOW IN ADMIN
	-- Hidden: the policy is fixed, so there is nothing to present
	---------------------------------------------------------- */

	public function show_in_admin(): bool {

		return false;

	}

	/*
	IS ALWAYS ENABLED
	-- Runs regardless of saved settings
	---------------------------------------------------------- */

	public function is_always_enabled(): bool {

		return true;

	}

	/*
	RUN
	-- Registers the two Breakdance filters the module needs
	---------------------------------------------------------- */

	public function run( array $s ): void {

		add_filter( 'breakdance_element_default_properties', [ __CLASS__, 'filter_default_properties' ] );
		add_filter( 'breakdance_before_render_node', [ __CLASS__, 'filter_render_node' ] );

	}

	/*
	FILTER DEFAULT PROPERTIES
	-- Breakdance hands the builder each element's starting properties here, so
	-- an element dropped onto the canvas arrives with Lazy Load already off
	-- Elements with no defaults return false rather than an array
	---------------------------------------------------------- */

	public static function filter_default_properties( $properties ) {

		if ( ! is_array( $properties ) ) {

			return $properties;

		}

		return self::disable_lazy_load( $properties );

	}

	/*
	FILTER RENDER NODE
	-- Catches everything the defaults filter cannot reach: saved pages, nested
	-- child elements shipped inside sliders and accordions, and any toggle an
	-- editor has switched back on
	---------------------------------------------------------- */

	public static function filter_render_node( $node ) {

		if ( ! is_array( $node ) || empty( $node['data']['properties'] ) || ! is_array( $node['data']['properties'] ) ) {

			return $node;

		}

		$node['data']['properties'] = self::disable_lazy_load( $node['data']['properties'] );

		return $node;

	}

	/*
	DISABLE LAZY LOAD
	-- Walks a property tree and forces every lazy_load value to false
	-- A lazy_load holding an array is a control section rather than a toggle
	-- (the Video element names one that way), so those are recursed into and
	-- left intact
	---------------------------------------------------------- */

	protected static function disable_lazy_load( array $properties ): array {

		foreach ( $properties as $key => $value ) {

			if ( is_array( $value ) ) {

				$properties[ $key ] = self::disable_lazy_load( $value );

				continue;

			}

			if ( self::LAZY_KEY === $key ) {

				$properties[ $key ] = false;

			}

		}

		return $properties;

	}

}

return new Octave_Addons_Module_Breakdance_Lazy_Load();
