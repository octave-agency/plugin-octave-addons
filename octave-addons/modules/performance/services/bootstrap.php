<?php

/*
PERFORMANCE SERVICES
-- Loads the services every Performance module shares and holds the small
-- helpers they have in common. Each module requires this file, so the
-- services exist whichever modules happen to be switched on
-- This folder has no class-module.php, so module discovery passes over it
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once __DIR__ . '/trait-field-rows.php';
require_once __DIR__ . '/class-context.php';
require_once __DIR__ . '/class-store.php';
require_once __DIR__ . '/class-log.php';
require_once __DIR__ . '/class-cache.php';
require_once __DIR__ . '/class-html.php';
require_once __DIR__ . '/class-cloudflare.php';
require_once __DIR__ . '/class-cleanup.php';
require_once __DIR__ . '/class-minifier.php';
require_once __DIR__ . '/class-google-fonts.php';
require_once __DIR__ . '/class-admin.php';

class Octave_Addons_Perf {

	/** Admin group id, named by the performance/ area folder. */
	public const GROUP = 'performance';

	/*
	SETTINGS
	-- Saved settings for one module, merged with its defaults. Read through the
	-- module manager so always-enabled modules report themselves as enabled
	---------------------------------------------------------- */

	public static function settings( string $module_id ): array {

		if ( class_exists( 'Octave_Addons' ) ) {

			return Octave_Addons::instance()->modules->settings_for( $module_id );

		}

		$all = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );

		return is_array( $all[ $module_id ] ?? null ) ? $all[ $module_id ] : [];

	}

	/*
	LINES
	-- Splits a textarea value into trimmed, non-empty, unique lines
	---------------------------------------------------------- */

	public static function lines( $value ): array {

		if ( is_array( $value ) ) {

			$value = implode( "\n", $value );

		}

		$lines = preg_split( '/[\r\n]+/', (string) $value );
		$lines = array_map( 'trim', is_array( $lines ) ? $lines : [] );

		return array_values( array_unique( array_filter( $lines, 'strlen' ) ) );

	}

	/*
	SANITIZE LINES
	-- Cleans a pattern textarea for storage: plain text, one entry per line
	---------------------------------------------------------- */

	public static function sanitize_lines( $value ): string {

		$lines = array_map( 'sanitize_text_field', self::lines( wp_unslash( $value ) ) );

		return implode( "\n", array_filter( $lines, 'strlen' ) );

	}

	/*
	MATCHES ANY
	-- Case-insensitive substring match against a list of patterns, returning
	-- the first pattern found or an empty string
	---------------------------------------------------------- */

	public static function matches_any( string $subject, array $patterns ): string {

		if ( '' === $subject ) {

			return '';

		}

		foreach ( $patterns as $pattern ) {

			$pattern = (string) $pattern;

			if ( '' !== $pattern && false !== stripos( $subject, $pattern ) ) {

				return $pattern;

			}

		}

		return '';

	}

	/*
	IS SAME ORIGIN
	-- True for root-relative URLs and absolute URLs on the site's own host
	---------------------------------------------------------- */

	public static function is_same_origin( string $url ): bool {

		$url = trim( $url );

		if ( '' === $url ) {

			return false;

		}

		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {

			return true;

		}

		$host = wp_parse_url( 0 === strpos( $url, '//' ) ? 'https:' . $url : $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) && is_string( $home ) && 0 === strcasecmp( $host, $home );

	}

	/*
	HANDLED ELSEWHERE
	-- Names another active plugin already doing the same job, so Octave can
	-- step aside rather than process the same markup twice. Only settings
	-- whose keys are known are read; other plugins can declare themselves
	-- through the octave_addons_perf_handled_elsewhere filter
	---------------------------------------------------------- */

	public static function handled_elsewhere( string $feature ): string {

		$rocket_keys = [
			'lazy_images'  => 'lazyload',
			'lazy_iframes' => 'lazyload_iframes',
			'delay'        => 'delay_js',
			'minify_css'   => 'minify_css',
			'minify_js'    => 'minify_js',
		];

		$owner = '';

		if ( defined( 'WP_ROCKET_VERSION' ) && isset( $rocket_keys[ $feature ] ) ) {

			$rocket = get_option( 'wp_rocket_settings', [] );

			if ( is_array( $rocket ) && ! empty( $rocket[ $rocket_keys[ $feature ] ] ) ) {

				$owner = 'WP Rocket';

			}

		}

		/**
		 * Filters which plugin, if any, already handles a Performance feature.
		 *
		 * @param string $owner   Plugin name, or '' when Octave should handle it.
		 * @param string $feature lazy_images, lazy_iframes, delay, minify_css or minify_js.
		 */
		return (string) apply_filters( 'octave_addons_perf_handled_elsewhere', $owner, $feature );

	}

	/*
	HAS HTML API
	-- WP_HTML_Tag_Processor arrived in WordPress 6.2. Every markup rewrite
	-- checks for it and leaves output untouched on older installs
	---------------------------------------------------------- */

	public static function has_html_api(): bool {

		return class_exists( 'WP_HTML_Tag_Processor' );

	}

	/*
	ASSET URL
	-- Public URL and cache-busting version for a file in the shared assets folder
	---------------------------------------------------------- */

	public static function asset_url( string $file ): string {

		return OCTAVE_ADDONS_URL . 'modules/performance/assets/' . $file;

	}

	public static function asset_version( string $file ): string {

		return Octave_Addons_Module::file_version( OCTAVE_ADDONS_DIR . 'modules/performance/assets/' . $file );

	}

}

Octave_Addons_Perf_Html::boot();
Octave_Addons_Perf_Admin::boot();
