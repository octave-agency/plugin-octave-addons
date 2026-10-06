<?php

/*
BREAKDANCE HEADING TAGS
-- Adds paragraph and div choices to the native Breakdance Heading element
-- without replacing the element or editing Breakdance's plugin files
-- Always on and hidden from the admin because it only extends an existing
-- builder control
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Breakdance_Heading_Tags extends Octave_Addons_Module {

	/** Breakdance element slug for the native Heading element. */
	protected const HEADING_TYPE = 'EssentialElements\\Heading';

	/** Additional root tags offered by the Heading element. */
	protected const ADDITIONAL_TAGS = [ 'p', 'div' ];

	/*
	GET ID
	-- Returns the module settings key
	---------------------------------------------------------- */

	public function get_id(): string {

		return 'breakdance-heading-tags';

	}

	/*
	GET TITLE
	-- Names the module for anything that lists modules internally
	---------------------------------------------------------- */

	public function get_title(): string {

		return __( 'Breakdance Heading Tags', 'octave-addons' );

	}

	/*
	GET DESCRIPTION
	-- Describes the extra semantic choices added to Heading elements
	---------------------------------------------------------- */

	public function get_description(): string {

		return __( 'Adds paragraph and div choices to the native Breakdance Heading element while keeping its heading typography controls.', 'octave-addons' );

	}

	/*
	SHOW IN ADMIN
	-- Hidden: the fixed extension has no settings
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
	-- Extends the Heading control definition sent to the Breakdance builder
	---------------------------------------------------------- */

	public function run( array $s ): void {

		add_filter( 'breakdance_element_controls', [ __CLASS__, 'filter_controls' ], 10, 2 );

	}

	/*
	FILTER CONTROLS
	-- Adds the extra tags only to Breakdance's native Heading element
	---------------------------------------------------------- */

	public static function filter_controls( $controls, $element ) {

		if ( ! is_array( $controls ) || ! self::is_heading( $element ) ) {

			return $controls;

		}

		return self::add_heading_tags( $controls );

	}

	/*
	IS HEADING
	-- Uses Breakdance's element slug so the check also works with subclasses
	---------------------------------------------------------- */

	protected static function is_heading( $element ): bool {

		return is_object( $element ) && method_exists( $element, 'slug' ) && self::HEADING_TYPE === $element::slug();

	}

	/*
	ADD HEADING TAGS
	-- Walks the nested control tree, preserving Breakdance's control shape and
	-- avoiding duplicate options if Breakdance later ships either tag itself
	---------------------------------------------------------- */

	protected static function add_heading_tags( array $controls ): array {

		foreach ( $controls as $key => $control ) {

			if ( ! is_array( $control ) ) {

				continue;

			}

			$is_tag_control = 'tags' === ( $control['slug'] ?? '' ) && 'dropdown' === ( $control['options']['type'] ?? '' );

			if ( $is_tag_control ) {

				$items  = is_array( $control['options']['items'] ?? null ) ? $control['options']['items'] : [];
				$values = [];

				foreach ( $items as $item ) {

					if ( is_array( $item ) && isset( $item['value'] ) ) {

						$values[] = $item['value'];

					}

				}

				foreach ( self::ADDITIONAL_TAGS as $tag ) {

					if ( in_array( $tag, $values, true ) ) {

						continue;

					}

					$items[]  = [ 'text' => $tag, 'value' => $tag ];
					$values[] = $tag;

				}

				$controls[ $key ]['options']['items'] = $items;

				continue;

			}

			$controls[ $key ] = self::add_heading_tags( $control );

		}

		return $controls;

	}

}

return new Octave_Addons_Module_Breakdance_Heading_Tags();
