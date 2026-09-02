<?php

/*
ADMIN EXPERIENCE
-- Owns the optional site-wide WordPress admin refresh and per-device theme.
-- The stored values belong to the Modern WordPress Admin module, but this
-- class runs long before modules boot, so it reads the option directly.
-- Keeping the feature isolated makes it safe to disable or remove later.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Admin_Experience {

	const THEME_COOKIE      = 'oa_admin_theme';
	const THEME_COOKIE_DAYS = 365;
	const MODULE_ID         = 'modern-admin';
	const DEFAULT_ACCENT    = '#1769C2';

	public function __construct() {

		// Runs before the Settings API handles a submission, so a legacy site is
		// already migrated by the time anything can save over it.
		add_action( 'admin_init',            [ $this, 'migrate_legacy_option' ], 1 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_bar_menu',        [ $this, 'add_theme_toggle' ], 998 );

	}

	/*
	MIGRATE LEGACY OPTION
	-- This feature used to own a standalone option and had no module row. A
	-- module that has never been saved is written from its bare defaults when
	-- any other page is submitted, which would switch the refresh off behind
	-- the user's back, so the stored row is created once up front.
	---------------------------------------------------------- */

	public function migrate_legacy_option(): void {

		$all = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );
		$all = is_array( $all ) ? $all : [];

		if ( isset( $all[ self::MODULE_ID ] ) && is_array( $all[ self::MODULE_ID ] ) ) {

			return;

		}

		$legacy = get_option( OCTAVE_ADDONS_ADMIN_EXPERIENCE_OPTION, null );

		if ( null === $legacy ) {

			return;

		}

		$settings            = self::get_setting_defaults();
		$settings['enabled'] = '1' === (string) $legacy;

		$all[ self::MODULE_ID ] = $settings;

		update_option( OCTAVE_ADDONS_OPTION_KEY, $all );

	}

	/*
	GET SETTING DEFAULTS
	-- The module delegates its defaults here so the shape is declared once.
	---------------------------------------------------------- */

	public static function get_setting_defaults(): array {

		return [
			'enabled'       => false,
			'accent_source' => 'default',  // default | custom
			'accent_color'  => self::DEFAULT_ACCENT,
		];

	}

	/*
	SANITIZE SETTINGS
	-- Shared with the module so a save and a direct read agree on the rules.
	---------------------------------------------------------- */

	public static function sanitize_settings( $input ): array {

		$input = is_array( $input ) ? $input : [];
		$clean = self::get_setting_defaults();

		$clean['enabled'] = ! empty( $input['enabled'] );

		$clean['accent_source'] = in_array( $input['accent_source'] ?? '', [ 'default', 'custom' ], true )
			? $input['accent_source'] : 'default';

		$clean['accent_color'] = sanitize_hex_color( $input['accent_color'] ?? self::DEFAULT_ACCENT ) ?: self::DEFAULT_ACCENT;

		return $clean;

	}

	/*
	GET SETTINGS
	-- Reads the module's saved values, falling back to the standalone option
	-- this feature used before it became a module so an existing site keeps
	-- the refresh switched on without a migration step.
	---------------------------------------------------------- */

	public function get_settings(): array {

		$all   = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );
		$saved = is_array( $all ) && isset( $all[ self::MODULE_ID ] ) && is_array( $all[ self::MODULE_ID ] )
			? $all[ self::MODULE_ID ] : null;

		if ( null !== $saved ) {

			return wp_parse_args( $saved, self::get_setting_defaults() );

		}

		$defaults            = self::get_setting_defaults();
		$defaults['enabled'] = '1' === (string) get_option( OCTAVE_ADDONS_ADMIN_EXPERIENCE_OPTION, '0' );

		return $defaults;

	}

	/*
	IS ENABLED
	-- Provides the single feature boundary used by assets and interface controls.
	---------------------------------------------------------- */

	public function is_enabled(): bool {

		$settings = $this->get_settings();

		return ! empty( $settings['enabled'] );

	}

	/*
	ENQUEUE ASSETS
	-- Loads the visual refresh only while the site-wide switch is enabled.
	---------------------------------------------------------- */

	public function enqueue_assets( string $hook ): void {

		$settings = $this->get_settings();

		if ( empty( $settings['enabled'] ) ) {

			return;

		}

		$css_path          = OCTAVE_ADDONS_DIR . 'assets/css/admin-experience/base.css';
		$integrations_path = OCTAVE_ADDONS_DIR . 'assets/css/admin-experience/integrations.css';
		$woo_path          = OCTAVE_ADDONS_DIR . 'assets/css/admin-experience/woocommerce.css';
		$rank_math_path    = OCTAVE_ADDONS_DIR . 'assets/css/admin-experience/rank-math.css';
		$js_path           = OCTAVE_ADDONS_DIR . 'assets/js/admin-experience.js';
		$theme             = $this->get_theme();

		wp_enqueue_style(
			'octave-addons-admin-experience',
			OCTAVE_ADDONS_URL . 'assets/css/admin-experience/base.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : OCTAVE_ADDONS_VERSION
		);

		$accent_css = $this->accent_css( $settings );

		if ( '' !== $accent_css ) {

			wp_add_inline_style( 'octave-addons-admin-experience', $accent_css );

		}

		wp_enqueue_style(
			'octave-addons-admin-experience-integrations',
			OCTAVE_ADDONS_URL . 'assets/css/admin-experience/integrations.css',
			[ 'octave-addons-admin-experience' ],
			file_exists( $integrations_path ) ? (string) filemtime( $integrations_path ) : OCTAVE_ADDONS_VERSION
		);

		if ( $this->is_woocommerce_active() ) {

			wp_enqueue_style(
				'octave-addons-admin-experience-woocommerce',
				OCTAVE_ADDONS_URL . 'assets/css/admin-experience/woocommerce.css',
				[ 'octave-addons-admin-experience' ],
				file_exists( $woo_path ) ? (string) filemtime( $woo_path ) : OCTAVE_ADDONS_VERSION
			);

		}

		if ( $this->is_rank_math_active() ) {

			wp_enqueue_style(
				'octave-addons-admin-experience-rank-math',
				OCTAVE_ADDONS_URL . 'assets/css/admin-experience/rank-math.css',
				[ 'octave-addons-admin-experience' ],
				file_exists( $rank_math_path ) ? (string) filemtime( $rank_math_path ) : OCTAVE_ADDONS_VERSION
			);

		}

		wp_enqueue_script(
			'octave-addons-admin-experience',
			OCTAVE_ADDONS_URL . 'assets/js/admin-experience.js',
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : OCTAVE_ADDONS_VERSION,
			false
		);

		wp_localize_script( 'octave-addons-admin-experience', 'oaAdminExperience', [
			'theme'           => $theme,
			'cookieName'      => self::THEME_COOKIE,
			'cookieDays'      => (string) self::THEME_COOKIE_DAYS,
			'cookiePath'      => $this->get_cookie_path(),
			'cookieDomain'    => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
			'cookieSecure'    => is_ssl() ? '1' : '',
			'darkModeText'    => __( 'Use dark mode', 'octave-addons' ),
			'lightModeText'   => __( 'Use light mode', 'octave-addons' ),
			'mediaSearchText' => __( 'Search media…', 'octave-addons' ),
		] );

	}

	/*
	ACCENT CSS
	-- Rewrites the accent tokens from one brand colour. Both themes are given
	-- their own tone, because a colour picked to read on white is rarely light
	-- enough to read on the dark canvas.
	---------------------------------------------------------- */

	public function accent_css( array $settings ): string {

		if ( 'custom' !== ( $settings['accent_source'] ?? 'default' ) ) {

			return '';

		}

		$hex = sanitize_hex_color( $settings['accent_color'] ?? '' );

		if ( ! $hex ) {

			return '';

		}

		[ $hue, $saturation, $lightness ] = self::hex_to_hsl( $hex );

		$light_accent = self::hsl_to_hex( $hue, $saturation, min( max( $lightness, 0.30 ), 0.52 ) );
		$light_strong = self::hsl_to_hex( $hue, $saturation, min( max( $lightness - 0.12, 0.20 ), 0.42 ) );
		$dark_accent  = self::hsl_to_hex( $hue, min( $saturation, 0.85 ), 0.68 );
		$dark_strong  = self::hsl_to_hex( $hue, min( $saturation, 0.85 ), 0.78 );

		$light_rgb = implode( ', ', self::hex_to_rgb( $light_accent ) );
		$dark_rgb  = implode( ', ', self::hex_to_rgb( $dark_accent ) );

		$light_on = self::contrast_color( $light_accent );
		$dark_on  = self::contrast_color( $dark_accent );

		$css  = 'html[data-oa-admin-theme="light"] {';
		$css .= '--oa-admin-accent: ' . $light_accent . ';';
		$css .= '--oa-admin-accent-dark: ' . $light_strong . ';';
		$css .= '--oa-admin-accent-soft: rgba(' . $light_rgb . ', 0.10);';
		$css .= '--oa-admin-surface-selected: rgba(' . $light_rgb . ', 0.12);';
		$css .= '--oa-admin-focus-ring: rgba(' . $light_rgb . ', 0.36);';
		$css .= '--oa-admin-on-accent: ' . $light_on . ';';
		$css .= '--oa-admin-check-filter: ' . ( '#FFFFFF' === $light_on ? 'brightness(0) invert(1)' : 'brightness(0)' ) . ';';
		$css .= '}';

		$css .= 'html[data-oa-admin-theme="dark"] {';
		$css .= '--oa-admin-accent: ' . $dark_accent . ';';
		$css .= '--oa-admin-accent-dark: ' . $dark_strong . ';';
		$css .= '--oa-admin-accent-soft: rgba(' . $dark_rgb . ', 0.14);';
		$css .= '--oa-admin-surface-selected: rgba(' . $dark_rgb . ', 0.20);';
		$css .= '--oa-admin-focus-ring: rgba(' . $dark_rgb . ', 0.32);';
		$css .= '--oa-admin-on-accent: ' . $dark_on . ';';
		$css .= '--oa-admin-check-filter: ' . ( '#FFFFFF' === $dark_on ? 'brightness(0) invert(1)' : 'brightness(0)' ) . ';';
		$css .= '}';

		return $css;

	}

	/*
	IS WOOCOMMERCE ACTIVE
	-- Gates the WooCommerce sheet so it never loads on a store-free site.
	---------------------------------------------------------- */

	public function is_woocommerce_active(): bool {

		return class_exists( 'WooCommerce' );

	}

	/*
	IS RANK MATH ACTIVE
	-- Gates the Rank Math sheet so it never loads on a site without the plugin.
	---------------------------------------------------------- */

	public function is_rank_math_active(): bool {

		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );

	}

	/*
	ADD THEME TOGGLE
	-- Places an icon-only per-user light and dark mode control in the admin bar.
	---------------------------------------------------------- */

	public function add_theme_toggle( WP_Admin_Bar $admin_bar ): void {

		if ( ! is_admin() || ! is_user_logged_in() ) {

			return;

		}

		if ( ! $this->is_enabled() ) {

			return;

		}

		$sun_icon  = '<span class="oa-theme-icon oa-theme-icon-sun" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"></path></svg></span>';
		$moon_icon = '<span class="oa-theme-icon oa-theme-icon-moon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M20.5 14.3A8.5 8.5 0 0 1 9.7 3.5 8.5 8.5 0 1 0 20.5 14.3Z"></path></svg></span>';

		$admin_bar->add_node( [
			'id'     => 'oa-theme-toggle',
			'parent' => 'top-secondary',
			'title'  => $sun_icon . $moon_icon,
			'href'   => '#',
			'meta'   => [
				'class' => 'oa-theme-toggle',
				'title' => __( 'Toggle light or dark mode', 'octave-addons' ),
			],
		] );

	}

	/*
	GET THEME
	-- Reads the appearance choice from the browser so shared accounts stay per
	-- device. Without a choice the browser follows the operating system.
	---------------------------------------------------------- */

	public function get_theme(): string {

		$theme = isset( $_COOKIE[ self::THEME_COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ self::THEME_COOKIE ] ) ) : '';

		if ( ! in_array( $theme, [ 'light', 'dark' ], true ) ) {

			return 'system';

		}

		return $theme;

	}

	/*
	GET COOKIE PATH
	-- Scopes the cookie to the whole install so wp-admin and the front end agree.
	---------------------------------------------------------- */

	public function get_cookie_path(): string {

		if ( defined( 'SITECOOKIEPATH' ) && SITECOOKIEPATH ) {

			return (string) SITECOOKIEPATH;

		}

		return '/';

	}

	/*
	HEX TO RGB
	---------------------------------------------------------- */

	public static function hex_to_rgb( string $hex ): array {

		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {

			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];

		}

		return [
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		];

	}

	/*
	HEX TO HSL
	-- Returns hue in degrees and saturation and lightness as 0–1 fractions.
	---------------------------------------------------------- */

	public static function hex_to_hsl( string $hex ): array {

		[ $red, $green, $blue ] = self::hex_to_rgb( $hex );

		$red   /= 255;
		$green /= 255;
		$blue  /= 255;

		$max   = max( $red, $green, $blue );
		$min   = min( $red, $green, $blue );
		$delta = $max - $min;

		$lightness  = ( $max + $min ) / 2;
		$saturation = 0.0;
		$hue        = 0.0;

		if ( $delta > 0 ) {

			$saturation = $delta / ( 1 - abs( ( 2 * $lightness ) - 1 ) );

			if ( $max === $red ) {

				$hue = 60 * fmod( ( $green - $blue ) / $delta, 6 );

			} elseif ( $max === $green ) {

				$hue = 60 * ( ( ( $blue - $red ) / $delta ) + 2 );

			} else {

				$hue = 60 * ( ( ( $red - $green ) / $delta ) + 4 );

			}

		}

		if ( $hue < 0 ) {

			$hue += 360;

		}

		return [ $hue, $saturation, $lightness ];

	}

	/*
	HSL TO HEX
	---------------------------------------------------------- */

	public static function hsl_to_hex( float $hue, float $saturation, float $lightness ): string {

		$saturation = min( max( $saturation, 0 ), 1 );
		$lightness  = min( max( $lightness, 0 ), 1 );

		$chroma    = ( 1 - abs( ( 2 * $lightness ) - 1 ) ) * $saturation;
		$secondary = $chroma * ( 1 - abs( fmod( $hue / 60, 2 ) - 1 ) );
		$match     = $lightness - ( $chroma / 2 );

		if ( $hue < 60 ) {

			$rgb = [ $chroma, $secondary, 0 ];

		} elseif ( $hue < 120 ) {

			$rgb = [ $secondary, $chroma, 0 ];

		} elseif ( $hue < 180 ) {

			$rgb = [ 0, $chroma, $secondary ];

		} elseif ( $hue < 240 ) {

			$rgb = [ 0, $secondary, $chroma ];

		} elseif ( $hue < 300 ) {

			$rgb = [ $secondary, 0, $chroma ];

		} else {

			$rgb = [ $chroma, 0, $secondary ];

		}

		$hex = '#';

		foreach ( $rgb as $channel ) {

			$hex .= str_pad( dechex( (int) round( ( $channel + $match ) * 255 ) ), 2, '0', STR_PAD_LEFT );

		}

		return strtoupper( $hex );

	}

	/*
	CONTRAST COLOR
	-- Picks white or near-black for text sitting on the accent, using the
	-- relative luminance rather than a plain brightness average.
	---------------------------------------------------------- */

	public static function contrast_color( string $hex ): string {

		[ $red, $green, $blue ] = self::hex_to_rgb( $hex );

		$channels = [];

		foreach ( [ $red, $green, $blue ] as $channel ) {

			$channel    = $channel / 255;
			$channels[] = $channel <= 0.03928 ? $channel / 12.92 : pow( ( $channel + 0.055 ) / 1.055, 2.4 );

		}

		$luminance = ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );

		// 0.179 is where white and near-black give the same contrast ratio, so
		// either side of it the better of the two always wins.
		return $luminance > 0.179 ? '#0B1116' : '#FFFFFF';

	}

}
