<?php

/*
PERFORMANCE OWNERSHIP
-- Which other plugin or host already does each optimisation, so Octave can
-- step aside instead of processing the same page twice and the Performance
-- page can show who owns what
-- A feature is only claimed when the plugin's own setting for it is on:
-- an active plugin with the feature switched off owns nothing. Settings
-- whose keys are not known are never guessed at
-- Each integration is one entry in INTEGRATIONS and one detect_* method
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Owners {

	/** Every feature whose owner is tracked. */
	public const FEATURES = [ 'lazy_images', 'lazy_iframes', 'delay', 'minify_js', 'minify_css', 'page_cache', 'nextgen_images', 'google_fonts' ];

	/** Detector method suffix => plugin name. */
	protected const INTEGRATIONS = [
		'wp_rocket'   => 'WP Rocket',
		'litespeed'   => 'LiteSpeed Cache',
		'perfmatters' => 'Perfmatters',
		'flyingpress' => 'FlyingPress',
		'autoptimize' => 'Autoptimize',
		'imagify'     => 'Imagify',
		'breeze'      => 'Breeze',
		'cloudways'   => 'Cloudways Varnish',
		'host'        => '',
	];

	/** Owners that cache in front of WordPress and stack with a plugin cache by design, so never count as a duplicate. */
	protected const PROXY_OWNERS = [ 'Cloudways Varnish' ];

	/** @var array<string, string[]>|null Feature => owner names, per request. */
	protected static ?array $owners = null;

	public static function reset(): void {

		self::$owners = null;

	}

	/*
	LABELS
	-- What the admin calls each feature
	---------------------------------------------------------- */

	public static function labels(): array {

		return [
			'lazy_images'    => __( 'Image lazy loading', 'octave-addons' ),
			'lazy_iframes'   => __( 'Iframe lazy loading', 'octave-addons' ),
			'delay'          => __( 'JavaScript delay', 'octave-addons' ),
			'minify_js'      => __( 'JavaScript minification', 'octave-addons' ),
			'minify_css'     => __( 'CSS minification', 'octave-addons' ),
			'page_cache'     => __( 'Page caching', 'octave-addons' ),
			'nextgen_images' => __( 'WebP/AVIF delivery', 'octave-addons' ),
			'google_fonts'   => __( 'Google Fonts self-hosting', 'octave-addons' ),
		];

	}

	/*
	OWNERS
	-- Every detected owner per feature
	---------------------------------------------------------- */

	public static function owners(): array {

		if ( null !== self::$owners ) {

			return self::$owners;

		}

		$owners = array_fill_keys( self::FEATURES, [] );

		foreach ( self::INTEGRATIONS as $id => $name ) {

			$detector = 'detect_' . $id;

			foreach ( self::$detector() as $feature => $value ) {

				if ( ! empty( $value ) && isset( $owners[ $feature ] ) ) {

					// The host detector names the host it found.
					$owners[ $feature ][] = 'host' === $id ? (string) $value : $name;

				}

			}

		}

		return self::$owners = $owners;

	}

	/*
	OWNER
	-- The owners of one feature as one readable name, or ''
	---------------------------------------------------------- */

	public static function owner( string $feature ): string {

		return implode( ', ', self::owners()[ $feature ] ?? [] );

	}

	/*
	OCTAVE STATE
	-- Whether Octave itself is switched on for a feature. Next-generation
	-- delivery is Octave's only when the Imagify module hands it to Octave's
	-- URL rewriting; otherwise that module only configures Imagify
	---------------------------------------------------------- */

	public static function octave_enabled( string $feature ): bool {

		$map = [
			'lazy_images'  => [ 'performance-media', 'images' ],
			'lazy_iframes' => [ 'performance-media', 'iframes' ],
			'delay'        => [ 'performance-delay', 'enabled' ],
			'minify_js'    => [ 'performance-files', 'minify_js' ],
			'minify_css'   => [ 'performance-files', 'minify_css' ],
			'google_fonts' => [ 'performance-fonts', 'self_host' ],
			'page_cache'   => [ 'performance-page-cache', 'enabled' ],
		];

		if ( 'nextgen_images' === $feature ) {

			return Octave_Addons_Perf_Imagify::octave_delivery();

		}

		if ( ! isset( $map[ $feature ] ) ) {

			return false;

		}

		$settings = Octave_Addons_Perf::settings( $map[ $feature ][0] );

		return ! empty( $settings['enabled'] ) && ! empty( $settings[ $map[ $feature ][1] ] );

	}

	/*
	CONFLICTS
	-- One row per feature: Octave's state, every owner, and whether two
	-- optimisers are switched on for it. Octave always steps aside, so a
	-- real conflict is two other owners at once
	---------------------------------------------------------- */

	public static function report(): array {

		$rows = [];

		foreach ( self::labels() as $feature => $label ) {

			$owners = array_filter( array_map( 'trim', explode( ',', Octave_Addons_Perf::handled_elsewhere( $feature ) ) ) );
			$octave = self::octave_enabled( $feature );
			$status = 'none';

			if ( count( array_diff( $owners, self::PROXY_OWNERS ) ) > 1 ) {

				$status = 'conflict';

			} elseif ( $octave && ! empty( $owners ) ) {

				$status = 'deferred';

			} elseif ( $octave ) {

				$status = 'octave';

			} elseif ( ! empty( $owners ) ) {

				$status = 'external';

			}

			$rows[ $feature ] = [ 'label' => $label, 'octave' => $octave, 'owners' => array_values( $owners ), 'status' => $status ];

		}

		return $rows;

	}

	public static function conflicts(): array {

		return array_filter( self::report(), static function ( array $row ): bool {

			return 'conflict' === $row['status'];

		} );

	}

	/*
	OPTION
	-- A plugin's settings array, or [] when it is missing
	---------------------------------------------------------- */

	protected static function option( string $name ): array {

		$value = get_option( $name, [] );

		return is_array( $value ) ? $value : [];

	}

	/*
	WP ROCKET
	-- Caching is WP Rocket's core job, so a saved configuration means it owns it
	---------------------------------------------------------- */

	protected static function detect_wp_rocket(): array {

		if ( ! defined( 'WP_ROCKET_VERSION' ) ) {

			return [];

		}

		$s = self::option( 'wp_rocket_settings' );

		return [
			'lazy_images'  => $s['lazyload'] ?? false,
			'lazy_iframes' => $s['lazyload_iframes'] ?? false,
			'delay'        => $s['delay_js'] ?? false,
			'minify_css'   => $s['minify_css'] ?? false,
			'minify_js'    => $s['minify_js'] ?? false,
			'page_cache'   => ! empty( $s ),
			'google_fonts' => $s['host_fonts_locally'] ?? false,
		];

	}

	/*
	LITESPEED CACHE
	-- Each setting is its own litespeed.conf.* option. JavaScript defer
	-- value 2 means Delayed
	---------------------------------------------------------- */

	protected static function detect_litespeed(): array {

		if ( ! defined( 'LSCWP_V' ) ) {

			return [];

		}

		$conf = static function ( string $key ) {

			return get_option( 'litespeed.conf.' . $key, false );

		};

		return [
			'lazy_images'    => $conf( 'media-lazy' ),
			'lazy_iframes'   => $conf( 'media-iframe_lazy' ),
			'delay'          => 2 === (int) $conf( 'optm-js_defer' ),
			'minify_css'     => $conf( 'optm-css_min' ),
			'minify_js'      => $conf( 'optm-js_min' ),
			'page_cache'     => $conf( 'cache' ),
			'nextgen_images' => $conf( 'img_optm-webp' ),
		];

	}

	/*
	PERFMATTERS
	---------------------------------------------------------- */

	protected static function detect_perfmatters(): array {

		if ( ! defined( 'PERFMATTERS_VERSION' ) ) {

			return [];

		}

		$s = self::option( 'perfmatters_options' );

		return [
			'lazy_images'  => $s['lazyload']['lazy_loading'] ?? false,
			'lazy_iframes' => $s['lazyload']['lazy_loading_iframes'] ?? false,
			'delay'        => $s['assets']['delay_js'] ?? false,
			'minify_css'   => $s['assets']['minify_css'] ?? false,
			'minify_js'    => $s['assets']['minify_js'] ?? false,
			'google_fonts' => $s['fonts']['local_google_fonts'] ?? false,
		];

	}

	/*
	FLYINGPRESS
	-- A full-page cache by design; other features only when their key is set
	---------------------------------------------------------- */

	protected static function detect_flyingpress(): array {

		if ( ! defined( 'FLYING_PRESS_VERSION' ) ) {

			return [];

		}

		$s = self::option( 'FLYING_PRESS_CONFIG' );

		return [
			'lazy_images'  => $s['lazy_load'] ?? false,
			'delay'        => $s['js_delay'] ?? false,
			'minify_css'   => $s['css_minify'] ?? false,
			'minify_js'    => $s['js_minify'] ?? false,
			'page_cache'   => ! empty( $s ),
			'google_fonts' => $s['fonts_optimize_google'] ?? false,
		];

	}

	/*
	AUTOPTIMIZE
	---------------------------------------------------------- */

	protected static function detect_autoptimize(): array {

		if ( ! defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) ) {

			return [];

		}

		$images = self::option( 'autoptimize_imgopt_settings' );

		return [
			'lazy_images' => $images['autoptimize_imgopt_checkbox_field_3'] ?? false,
			'minify_css'  => 'on' === get_option( 'autoptimize_css' ),
			'minify_js'   => 'on' === get_option( 'autoptimize_js' ),
		];

	}

	/*
	IMAGIFY
	-- Only delivery: generating the files is Imagify's whether shown or not
	---------------------------------------------------------- */

	protected static function detect_imagify(): array {

		if ( ! defined( 'IMAGIFY_VERSION' ) ) {

			return [];

		}

		return [ 'nextgen_images' => self::option( 'imagify_settings' )['display_nextgen'] ?? false ];

	}

	/*
	BREEZE
	---------------------------------------------------------- */

	protected static function detect_breeze(): array {

		if ( ! defined( 'BREEZE_VERSION' ) ) {

			return [];

		}

		$basic    = self::option( 'breeze_basic_settings' );
		$advanced = self::option( 'breeze_advanced_settings' );

		return [
			'lazy_images'  => $advanced['breeze-lazy-load'] ?? false,
			'lazy_iframes' => $advanced['breeze-lazy-load-iframes'] ?? false,
			'delay'        => $advanced['breeze-enable-js-delay'] ?? false,
			'minify_css'   => $basic['breeze-minify-css'] ?? false,
			'minify_js'    => $basic['breeze-minify-js'] ?? false,
			'page_cache'   => $basic['breeze-active'] ?? false,
		];

	}

	/*
	CLOUDWAYS VARNISH
	-- Varnish in front of the application, while Cloudways has it switched on
	---------------------------------------------------------- */

	protected static function detect_cloudways(): array {

		return [ 'page_cache' => Octave_Addons_Perf_Varnish::is_running() ];

	}

	/*
	HOST
	-- Managed hosts that cache whole pages in front of WordPress, recognised
	-- by the constants or classes their platform defines
	---------------------------------------------------------- */

	protected static function detect_host(): array {

		$hosts = [
			'WP Engine'    => defined( 'WPE_APIKEY' ) || class_exists( 'WpeCommon' ),
			'Kinsta'       => defined( 'KINSTAMU_VERSION' ),
			'SiteGround'   => function_exists( 'sg_cachepress_purge_cache' ),
			'Pantheon'     => defined( 'PANTHEON_ENVIRONMENT' ),
			'Pressable'    => defined( 'IS_PRESSABLE' ),
			'Flywheel'     => defined( 'FLYWHEEL_CONFIG_DIR' ),
			'GoDaddy'      => class_exists( 'WPaaS\Plugin' ),
			'Nginx Helper' => defined( 'NGINX_HELPER_BASENAME' ),
		];

		foreach ( $hosts as $name => $found ) {

			if ( $found ) {

				return [ 'page_cache' => $name ];

			}

		}

		return [];

	}

}
