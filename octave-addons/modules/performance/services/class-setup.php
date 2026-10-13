<?php

/*
PERFORMANCE: QUICK SETUP
-- One-click starting points for a site whose speed settings were never
-- touched. Each level switches on a set of modules with the settings the
-- admin already calls recommended; Maximum switches on everything
-- Settings are saved through the normal settings sanitisers and the save
-- purge, exactly as if the switches had been flipped by hand, and only
-- the modules a level names are changed
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Setup {

	public const LEVELS = [ 'essentials', 'balanced', 'maximum' ];

	/*
	LABELS
	-- Title and one-line promise for each level, for the setup card
	---------------------------------------------------------- */

	public static function labels(): array {

		return [
			'essentials' => [
				'title' => __( 'Essentials', 'octave-addons' ),
				'text'  => __( 'Page cache, lazy loading, smaller and combined style files, fonts from your site and link preloading. Scripts are left as they are.', 'octave-addons' ),
			],
			'balanced'   => [
				'title' => __( 'Balanced', 'octave-addons' ),
				'text'  => __( 'Recommended. Essentials, plus smaller script files, analytics and marketing tags held back until visitors interact, modern image formats and animations that never hide the top of the page.', 'octave-addons' ),
			],
			'maximum'    => [
				'title' => __( 'Maximum', 'octave-addons' ),
				'text'  => __( 'Everything on: every known third-party script held back, a weekly database cleanup, AVIF images and Cloudflare clearing. Check your pages afterwards.', 'octave-addons' ),
			],
		];

	}

	/*
	PRESET
	-- Module id => settings merged over the module's current settings
	---------------------------------------------------------- */

	public static function preset( string $level ): array {

		$on = [ 'enabled' => true ];

		$preset = [
			'performance-page-cache' => $on,
			'performance-media'      => $on + [ 'images' => true, 'iframes' => true, 'videos' => true, 'header_eager' => true, 'dimensions' => true, 'lazy_posters' => true, 'lcp' => true ],
			'performance-files'      => $on + [ 'minify_css' => true, 'inline_css' => true, 'breakdance_css' => true ],
			'performance-fonts'      => $on + [ 'self_host' => true ],
			'performance-preload'    => $on,
			'performance-heartbeat'  => $on,
		];

		if ( 'essentials' === $level ) {

			return $preset;

		}

		$services = Octave_Addons_Perf_Script_Delayer::services();
		$tracking = array_keys( array_filter( $services, static function ( array $service ): bool {

			return in_array( $service['category'] ?? '', [ 'analytics', 'marketing' ], true );

		} ) );

		$preset['performance-files'] += [ 'minify_js' => true ];
		$preset['performance-delay']  = $on + [ 'services' => 'maximum' === $level ? array_keys( $services ) : $tracking ];
		$preset['animations']         = [ 'performance' => true ];

		if ( Octave_Addons_Perf_Imagify::is_active() ) {

			$preset['performance-imagify'] = $on + ( 'maximum' === $level ? [ 'format' => 'avif' ] : [] );

		}

		if ( 'balanced' === $level ) {

			return $preset;

		}

		$preset['performance-files']     += [ 'defer_js' => true ];
		$preset['performance-database']   = $on + [ 'schedule' => 'weekly' ];
		$preset['performance-cloudflare'] = $on;

		return $preset;

	}

	/*
	IS UNTOUCHED
	-- True while every Performance module still has its default settings,
	-- which is the only time the setup card is offered
	---------------------------------------------------------- */

	public static function is_untouched(): bool {

		$modules = Octave_Addons_Perf::manager();

		foreach ( $modules->all() as $id => $module ) {

			if ( Octave_Addons_Perf::GROUP !== $modules->group_for( (string) $id ) || $module->is_always_enabled() ) {

				continue;

			}

			if ( $modules->settings_for( (string) $id ) != $module->get_defaults() ) { // phpcs:ignore Universal.Operators.StrictComparisons -- key order may differ.

				return false;

			}

		}

		return true;

	}

	/*
	APPLY
	-- Saves one level. Only the modules it names are submitted; every other
	-- module keeps exactly what it holds
	---------------------------------------------------------- */

	public static function apply( string $level ): bool {

		if ( ! in_array( $level, self::LEVELS, true ) ) {

			return false;

		}

		$modules = Octave_Addons_Perf::manager();
		$stored  = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );
		$input   = is_array( $stored ) ? $stored : [];
		$ids     = [];

		foreach ( self::preset( $level ) as $id => $values ) {

			if ( null === $modules->get( $id ) || ! $modules->is_available( $id ) ) {

				continue;

			}

			$input[ $id ] = array_merge( $modules->settings_for( $id ), $values );
			$ids[]        = $id;

		}

		$input[ Octave_Addons_Module_Manager::SUBMITTED_FIELD ] = $ids;

		// The settings sanitiser is registered on admin_init; run it directly anywhere else.
		if ( ! has_filter( 'sanitize_option_' . OCTAVE_ADDONS_OPTION_KEY ) ) {

			$input = $modules->sanitize_all( $input );

		}

		update_option( OCTAVE_ADDONS_OPTION_KEY, $input );

		return true;

	}

}
