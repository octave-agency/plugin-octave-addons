<?php

/*
PAGE BUILDERS
-- Which page builders the site runs, so builder-specific modules, settings
-- and optimisations only appear and run where their builder exists. One
-- answer for the whole plugin: Breakdance, and Divi 4 or 5 whether it comes
-- as the Divi or Extra theme (or a child theme of either) or as the Divi
-- Builder plugin
-- Safe from plugins_loaded on: a theme's functions.php has not loaded yet
-- then, so Divi is recognised from the active template as well as from the
-- constants it defines once loaded
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Builders {

	public const BREAKDANCE = 'breakdance';
	public const DIVI       = 'divi';

	/** Themes that carry the Divi builder. */
	protected const DIVI_THEMES = [ 'divi', 'extra' ];

	/*
	BREAKDANCE
	---------------------------------------------------------- */

	public static function breakdance(): bool {

		return self::filtered( self::BREAKDANCE, did_action( 'breakdance_loaded' ) > 0
			|| class_exists( '\Breakdance\Elements\Element' )
			|| defined( '__BREAKDANCE_VERSION' ) );

	}

	/*
	DIVI
	---------------------------------------------------------- */

	public static function divi(): bool {

		$template = function_exists( 'get_template' ) ? strtolower( (string) get_template() ) : '';

		return self::filtered( self::DIVI, in_array( $template, self::DIVI_THEMES, true )
			|| defined( 'ET_BUILDER_PLUGIN_ACTIVE' )
			|| defined( 'ET_BUILDER_VERSION' ) );

	}

	/*
	DIVI VERSION
	-- 5 when Divi 5's builder is loaded, 4 for an earlier Divi, 0 without Divi
	---------------------------------------------------------- */

	public static function divi_version(): int {

		if ( ! self::divi() ) {

			return 0;

		}

		if ( defined( 'ET_BUILDER_5_DIR' ) ) {

			return 5;

		}

		$version = defined( 'ET_BUILDER_VERSION' ) ? (string) ET_BUILDER_VERSION : '';

		return '' !== $version && version_compare( $version, '5.0', '>=' ) ? 5 : 4;

	}

	/*
	ACTIVE
	-- Every builder found, as BREAKDANCE / DIVI keys
	---------------------------------------------------------- */

	public static function active(): array {

		return array_values( array_filter( [
			self::breakdance() ? self::BREAKDANCE : '',
			self::divi() ? self::DIVI : '',
		] ) );

	}

	/*
	ANY
	-- Whether at least one of the named builders is active. An empty list
	-- needs no builder
	---------------------------------------------------------- */

	public static function any( array $builders ): bool {

		return empty( $builders ) || ! empty( array_intersect( $builders, self::active() ) );

	}

	/*
	CSS PATHS
	-- Where each active builder's stylesheets live, as URL path fragments:
	-- Breakdance under /breakdance/, Divi in /et-cache/ and its theme folder
	---------------------------------------------------------- */

	public static function css_paths(): array {

		$paths = [];

		if ( self::breakdance() ) {

			$paths[ self::BREAKDANCE ] = [ '/breakdance/' ];

		}

		if ( self::divi() ) {

			$paths[ self::DIVI ] = [ '/et-cache/', '/themes/Divi/', '/themes/Extra/' ];

		}

		return $paths;

	}

	/*
	LABEL
	---------------------------------------------------------- */

	public static function label( string $builder ): string {

		return self::DIVI === $builder ? 'Divi' : 'Breakdance';

	}

	/*
	IS DIVI BUILDER REQUEST
	-- Divi's Visual Builder (et_fb), its backend builder (et_bfb), builder
	-- previews (et_pb_preview) and the Theme Builder and Divi admin screens
	---------------------------------------------------------- */

	public static function is_divi_builder_request(): bool {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only request checks.
		foreach ( [ 'et_fb', 'et_bfb', 'et_pb_preview', 'et_tb' ] as $argument ) {

			if ( ! empty( $_GET[ $argument ] ) ) {

				return true;

			}

		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:enable

		return is_admin() && ( 0 === strpos( $page, 'et_' ) || 0 === strpos( $page, 'divi' ) );

	}

	/*
	FILTERED
	-- Lets a site or a test say which builders it has
	---------------------------------------------------------- */

	protected static function filtered( string $builder, bool $active ): bool {

		/**
		 * Filters whether a page builder counts as active.
		 *
		 * @param bool   $active  Whether it was detected.
		 * @param string $builder breakdance or divi.
		 */
		return (bool) apply_filters( 'octave_addons_builder_active', $active, $builder );

	}

}
