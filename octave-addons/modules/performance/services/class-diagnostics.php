<?php

/*
PERFORMANCE DIAGNOSTICS
-- Read-only checks for the Performance page: how this server delivers
-- static files, which Breakdance performance settings are in use, and the
-- extra detail a page scan reports
-- Nothing here changes a setting or writes a server rule. Results name
-- headers and public URLs only, never file paths, keys or tokens
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Diagnostics {

	/** Response headers that report a cache hit or miss, in the order they are trusted. */
	protected const CACHE_HEADERS = [ 'x-octave-cache', 'x-litespeed-cache', 'cf-cache-status', 'x-cache', 'x-proxy-cache', 'x-kinsta-cache', 'x-cache-status', 'x-sucuri-cache' ];

	/*
	NOTE ASSETS
	-- During a scan, after every footer script has printed: which Octave,
	-- Breakdance and Divi scripts and styles the page actually loaded
	---------------------------------------------------------- */

	public static function note_assets(): void {

		if ( ! Octave_Addons_Perf_Log::is_reporting() || ! function_exists( 'wp_scripts' ) || ! function_exists( 'wp_styles' ) ) {

			return;

		}

		$assets = [ 'octave' => [], 'breakdance' => [], 'divi' => [] ];

		foreach ( [ wp_scripts(), wp_styles() ] as $dependencies ) {

			foreach ( (array) $dependencies->done as $handle ) {

				$src = (string) ( $dependencies->registered[ $handle ]->src ?? '' );

				if ( 0 === strpos( (string) $handle, 'octave' ) ) {

					$assets['octave'][] = (string) $handle;

				} elseif ( 0 === strpos( (string) $handle, 'breakdance' ) || false !== strpos( $src, '/breakdance/' ) ) {

					$assets['breakdance'][] = (string) $handle;

				} elseif ( 0 === strpos( (string) $handle, 'divi' ) || 0 === strpos( (string) $handle, 'et-' ) || false !== stripos( $src, '/themes/Divi/' ) || false !== strpos( $src, '/et-cache/' ) ) {

					$assets['divi'][] = (string) $handle;

				}

			}

		}

		Octave_Addons_Perf_Log::summary( 'assets', $assets );

	}

	/*
	CACHE STATUS
	-- The first cache header present and its value, or a page cache's own
	-- signature comment, or ''
	---------------------------------------------------------- */

	public static function cache_status( $response ): string {

		foreach ( self::CACHE_HEADERS as $header ) {

			$value = (string) wp_remote_retrieve_header( $response, $header );

			if ( '' !== $value ) {

				return $header . ': ' . $value;

			}

		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( false !== stripos( $body, 'This website is like a Rocket' ) ) {

			return __( 'Saved copy from WP Rocket', 'octave-addons' );

		}

		if ( false !== stripos( $body, 'Cache served by breeze' ) ) {

			return __( 'Saved copy from Breeze', 'octave-addons' );

		}

		return '';

	}

	/*
	SCAN DETAILS
	-- Sections added to a page scan, each a heading and readable rows
	---------------------------------------------------------- */

	public static function scan_details( string $url, array $scan, float $scan_ms, $plain, float $plain_ms ): array {

		$summary = (array) ( $scan['report']['summary'] ?? [] );
		$report  = (array) ( $scan['report'] ?? [] );
		$details = [];

		$owner  = Octave_Addons_Perf::handled_elsewhere( 'page_cache' );
		$status = is_wp_error( $plain ) ? '' : self::cache_status( $plain );

		$details[] = [
			'heading' => __( 'How the page loaded', 'octave-addons' ),
			'rows'    => array_filter( [
				/* translators: %d: milliseconds. */
				sprintf( __( 'Built fresh: %d ms', 'octave-addons' ), (int) $scan_ms ),
				is_wp_error( $plain ) ? '' : sprintf( /* translators: %d: milliseconds. */ __( 'As a visitor sees it: %d ms', 'octave-addons' ), (int) $plain_ms ),
				/* translators: %s: cache owner. */
				sprintf( __( 'Saved copies of pages: %s', 'octave-addons' ), '' !== $owner ? $owner : __( 'not set up', 'octave-addons' ) ),
				/* translators: %s: cache status header. */
				sprintf( __( 'Served from a saved copy: %s', 'octave-addons' ), '' !== $status ? $status : __( 'unknown', 'octave-addons' ) ),
			] ),
		];

		$blockers = is_wp_error( $plain ) ? [] : self::cache_blockers( $plain );

		$details[] = [
			'heading' => __( 'Anything stopping a saved copy', 'octave-addons' ),
			'rows'    => $blockers ?: [ __( 'Nothing: this page can be saved for quick loading.', 'octave-addons' ) ],
		];

		$names = [
			'oa-media'      => __( 'Images and videos', 'octave-addons' ),
			'oa-delay'      => __( 'Held-back scripts', 'octave-addons' ),
			'oa-fonts'      => __( 'Fonts', 'octave-addons' ),
			'oa-css-inline' => __( 'Styles placed in the page', 'octave-addons' ),
			'oa-css-bundle' => __( 'Combined page-builder styles', 'octave-addons' ),
			'oa-total'      => __( 'Total time Octave spent', 'octave-addons' ),
		];
		$rows  = [];

		foreach ( (array) ( $summary['timings'] ?? [] ) as $name => $ms ) {

			$rows[] = ( $names[ $name ] ?? $name ) . ': ' . number_format_i18n( (float) $ms, 2 ) . ' ms';

		}

		/* translators: %s: size. */
		$rows[] = sprintf( __( 'Styles placed in the page: %s', 'octave-addons' ), size_format( (int) ( $summary['inlined_css_bytes'] ?? 0 ) ) ?: '0 B' );

		$delayed = count( array_filter( (array) ( $report['delay'] ?? [] ), static function ( $item ): bool {

			return 'delayed' === ( $item['action'] ?? '' );

		} ) );

		$lazy = count( array_filter( (array) ( $report['media'] ?? [] ), static function ( $item ): bool {

			return 'lazy' === ( $item['action'] ?? '' );

		} ) );

		/* translators: %d: count. */
		$rows[] = sprintf( __( 'Scripts held back: %d', 'octave-addons' ), $delayed );
		/* translators: %d: count. */
		$rows[] = sprintf( __( 'Loaded as visitors scroll: %d', 'octave-addons' ), $lazy );

		$details[] = [ 'heading' => __( 'Octave\'s changes', 'octave-addons' ), 'rows' => $rows ];

		$assets = (array) ( $summary['assets'] ?? [] );
		/* translators: %s: handles. */
		$rows   = [ sprintf( __( 'Octave: %s', 'octave-addons' ), implode( ', ', (array) ( $assets['octave'] ?? [] ) ) ?: __( 'none', 'octave-addons' ) ) ];

		foreach ( Octave_Addons_Builders::active() as $builder ) {

			/* translators: 1: page builder name, 2: handles. */
			$rows[] = sprintf( __( '%1$s: %2$s', 'octave-addons' ), Octave_Addons_Builders::label( $builder ), implode( ', ', (array) ( $assets[ $builder ] ?? [] ) ) ?: __( 'none', 'octave-addons' ) );

		}

		/* translators: %s: font URLs. */
		$rows[]    = sprintf( __( 'Fonts loaded early: %s', 'octave-addons' ), implode( ', ', (array) ( $summary['preloaded_fonts'] ?? [] ) ) ?: __( 'none', 'octave-addons' ) );
		$details[] = [ 'heading' => __( 'Files the page loaded', 'octave-addons' ), 'rows' => $rows ];

		$details[] = [ 'heading' => __( 'Main image', 'octave-addons' ), 'rows' => array_merge( self::hero_rows( (array) ( $summary['lcp_hero'] ?? [] ) ), self::lcp_rows( Octave_Addons_Perf_Lcp::entry( Octave_Addons_Perf_Lcp::path( $url ) ) ) ) ];
		$details[] = [ 'heading' => __( 'Styles the page waits for', 'octave-addons' ), 'rows' => self::bundle_rows( (array) ( $summary['css_bundle'] ?? [] ) ) ];
		$details[] = [ 'heading' => __( 'Videos', 'octave-addons' ), 'rows' => self::video_rows( (array) ( $report['videos'] ?? [] ) ) ];

		$imagify = [];

		if ( Octave_Addons_Perf_Imagify::is_active() ) {

			$test      = Octave_Addons_Perf_Imagify::status()['test'] ?? [];
			$imagify[] = Octave_Addons_Perf_Imagify::available() ? sprintf( /* translators: %s: version. */ __( 'Imagify %s', 'octave-addons' ), Octave_Addons_Perf_Imagify::version() ) : Octave_Addons_Perf_Imagify::unavailable_reason();
			$imagify[] = empty( $test['time'] ) ? __( 'Modern image formats: not checked yet', 'octave-addons' ) : ( ! empty( $test['ok'] ) ? __( 'Modern image formats: working', 'octave-addons' ) : __( 'Modern image formats: not working', 'octave-addons' ) );

		} else {

			$imagify[] = __( 'Imagify is not active.', 'octave-addons' );

		}

		$details[] = [ 'heading' => __( 'Modern image formats', 'octave-addons' ), 'rows' => $imagify ];

		$conflicts = [];

		foreach ( Octave_Addons_Perf_Owners::conflicts() as $row ) {

			$conflicts[] = $row['label'] . ': ' . implode( ', ', $row['owners'] );

		}

		$details[] = [ 'heading' => __( 'Doubled-up features', 'octave-addons' ), 'rows' => $conflicts ?: [ __( 'None: each feature is handled by one plugin.', 'octave-addons' ) ] ];

		return $details;

	}

	/*
	CACHE BLOCKERS
	-- Cookies, a PHP session and Cache-Control in a visitor's response
	---------------------------------------------------------- */

	public static function cache_blockers( $response ): array {

		$cookies = wp_remote_retrieve_header( $response, 'set-cookie' );

		return Octave_Addons_Perf_Sessions::cache_blockers( is_array( $cookies ) ? $cookies : array_filter( [ (string) $cookies ] ), (string) wp_remote_retrieve_header( $response, 'cache-control' ) );

	}

	/*
	HERO ROWS
	-- The Breakdance hero background chosen from the page, per width range
	---------------------------------------------------------- */

	public static function hero_rows( array $hero ): array {

		if ( empty( $hero['preloads'] ) ) {

			return [ __( 'No page-builder background image found at the top of the page.', 'octave-addons' ) ];

		}

		$rows = [];

		foreach ( $hero['preloads'] as $preload ) {

			/* translators: 1: media condition, 2: image URL. */
			$rows[] = sprintf( __( 'Loaded first on %1$s: %2$s', 'octave-addons' ), '' !== $preload['media'] ? $preload['media'] : __( 'every screen size', 'octave-addons' ), $preload['url'] );

		}

		return $rows;

	}

	/*
	BUNDLE ROWS
	---------------------------------------------------------- */

	public static function bundle_rows( array $bundle ): array {

		if ( empty( $bundle ) ) {

			return [ __( 'No page-builder styles were combined on this page.', 'octave-addons' ) ];

		}

		$modes = [
			'inline' => __( 'placed in the page', 'octave-addons' ),
			'file'   => __( 'served as one file', 'octave-addons' ),
			'queued' => __( 'being prepared; the original files are used meanwhile', 'octave-addons' ),
		];

		return [
			/* translators: 1: number of stylesheets, 2: size, 3: delivery mode. */
			sprintf( __( 'Page-builder styles combined: %1$d files, %2$s, %3$s', 'octave-addons' ), count( (array) ( $bundle['files'] ?? [] ) ), size_format( (int) ( $bundle['bytes'] ?? 0 ) ) ?: '0 B', $modes[ $bundle['mode'] ?? '' ] ?? '' ),
			/* translators: %s: stylesheet URLs. */
			sprintf( __( 'Files: %s', 'octave-addons' ), implode( ', ', (array) ( $bundle['files'] ?? [] ) ) ),
		];

	}

	/*
	VIDEO ROWS
	-- Video files parked until they are in view, and their size when local
	---------------------------------------------------------- */

	public static function video_rows( array $videos ): array {

		if ( empty( $videos ) ) {

			return [ __( 'No videos were held back.', 'octave-addons' ) ];

		}

		$bytes = array_sum( array_map( 'intval', array_column( $videos, 'bytes' ) ) );

		$rows = [
			/* translators: 1: number of files, 2: size. */
			sprintf( __( '%1$d videos wait until they are on screen, saving up to %2$s when the page first loads.', 'octave-addons' ), count( $videos ), $bytes > 0 ? size_format( $bytes ) : __( 'an unknown amount', 'octave-addons' ) ),
		];

		foreach ( array_slice( $videos, 0, 5 ) as $video ) {

			$rows[] = (string) ( $video['src'] ?? '' ) . ( ! empty( $video['bytes'] ) ? ' (' . size_format( (int) $video['bytes'] ) . ')' : '' );

		}

		return $rows;

	}

	/*
	LCP ROWS
	-- One line per screen size from a learned record, with its warnings
	---------------------------------------------------------- */

	public static function lcp_rows( array $entry ): array {

		$rows   = [];
		$labels = [ 'm' => __( 'Phones', 'octave-addons' ), 'd' => __( 'Larger screens', 'octave-addons' ) ];

		foreach ( Octave_Addons_Perf_Lcp::DEVICES as $device ) {

			$record = (array) ( $entry[ $device ] ?? [] );

			if ( empty( $record['time'] ) ) {

				$rows[] = $labels[ $device ] . ': ' . __( 'not reported yet', 'octave-addons' );

				continue;

			}

			if ( 'none' === ( $record['kind'] ?? '' ) ) {

				$rows[] = $labels[ $device ] . ': ' . __( 'no image (text is the biggest thing at the top)', 'octave-addons' );

				continue;

			}

			$line = $labels[ $device ] . ': ' . $record['url'] . ' (' . Octave_Addons_Perf_Lcp::format( $record );

			if ( ! empty( $record['w'] ) ) {

				$line .= ', ' . (int) $record['w'] . '×' . (int) ( $record['h'] ?? 0 );

			}

			if ( ! empty( $record['rw'] ) ) {

				/* translators: 1: width, 2: height. */
				$line .= ', ' . sprintf( __( 'shown at %1$d×%2$d', 'octave-addons' ), (int) $record['rw'], (int) ( $record['rh'] ?? 0 ) );

			}

			if ( ! empty( $record['bytes'] ) ) {

				$line .= ', ' . size_format( (int) $record['bytes'] );

			}

			$rows[] = $line . ')';

			foreach ( Octave_Addons_Perf_Lcp::warnings( $record ) as $warning ) {

				$rows[] = '⚠ ' . $warning;

			}

		}

		return $rows;

	}

	/*
	STATIC DELIVERY
	-- Requests a stylesheet, a script, a font and any WebP/AVIF copy the
	-- way a browser would and reports compression, browser caching, MIME
	-- types, font CORS, Vary: Accept and CDN cache status. Octave only
	-- reports these; the server, host or a cache plugin owns the rules
	---------------------------------------------------------- */

	public static function static_delivery(): array {

		$files = [
			'css'  => [ __( 'Stylesheet', 'octave-addons' ), includes_url( 'css/dashicons.min.css' ) ],
			'js'   => [ __( 'Script', 'octave-addons' ), includes_url( 'js/jquery/jquery.min.js' ) ],
			'font' => [ __( 'Font', 'octave-addons' ), includes_url( 'fonts/dashicons.woff2' ) ],
		];

		$image = Octave_Addons_Perf_Imagify::available() ? Octave_Addons_Perf_Imagify::find_test_image( 'webp' ) : [];

		if ( ! empty( $image ) ) {

			$files['webp'] = [ 'WebP', $image['url'] . '.webp' ];

		}

		$image = Octave_Addons_Perf_Imagify::available() ? Octave_Addons_Perf_Imagify::find_test_image( 'avif' ) : [];

		if ( ! empty( $image ) ) {

			$files['avif'] = [ 'AVIF', $image['url'] . '.avif' ];

		}

		$lines = [];

		foreach ( $files as $kind => [ $label, $url ] ) {

			$lines[] = $label . ': ' . implode( ' · ', self::check_file( $kind, $url ) );

		}

		if ( ! isset( $files['webp'] ) && ! isset( $files['avif'] ) ) {

			$lines[] = __( 'Modern image formats: no Imagify copy found to check.', 'octave-addons' );

		}

		$lines[] = 'nginx' === Octave_Addons_Perf_Imagify::server()
			? __( 'This server (Nginx) needs any missing settings added by your host.', 'octave-addons' )
			: __( 'Octave only reports these. Ask your host, or the cache plugin that manages them, to fix anything missing.', 'octave-addons' );

		return $lines;

	}

	/*
	CHECK FILE
	-- Readable findings for one static file
	---------------------------------------------------------- */

	public static function check_file( string $kind, string $url ): array {

		$response = wp_remote_get( $url, [
			'timeout'             => 10,
			'redirection'         => 2,
			'decompress'          => false,
			'limit_response_size' => 65536,
			'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
			'headers'             => [
				'Accept-Encoding' => 'br, gzip',
				'Accept'          => in_array( $kind, [ 'webp', 'avif' ], true ) ? 'image/avif,image/webp,*/*' : '*/*',
				'Origin'          => 'https://octave-diagnostics.invalid',
			],
		] );

		if ( is_wp_error( $response ) ) {

			/* translators: %s: error. */
			return [ sprintf( __( 'could not be opened: %s', 'octave-addons' ), $response->get_error_message() ) ];

		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {

			/* translators: %d: HTTP status. */
			return [ sprintf( __( 'HTTP %d', 'octave-addons' ), $code ) ];

		}

		$found    = [];
		$encoding = strtolower( (string) wp_remote_retrieve_header( $response, 'content-encoding' ) );
		$type     = strtolower( trim( (string) strtok( (string) wp_remote_retrieve_header( $response, 'content-type' ), ';' ) ) );

		if ( in_array( $kind, [ 'css', 'js' ], true ) ) {

			$found[] = false !== strpos( $encoding, 'br' ) ? __( 'Brotli', 'octave-addons' ) : ( false !== strpos( $encoding, 'gzip' ) ? __( 'compressed (Gzip)', 'octave-addons' ) : __( 'not compressed: ask your host to turn on compression', 'octave-addons' ) );

		}

		$found[] = self::browser_cache( $response );

		if ( 'font' === $kind ) {

			$found[] = '' !== (string) wp_remote_retrieve_header( $response, 'access-control-allow-origin' ) ? __( 'fonts allowed from other sites', 'octave-addons' ) : __( 'fonts not shared with other sites (only matters if fonts come from another domain)', 'octave-addons' );

		}

		if ( in_array( $kind, [ 'webp', 'avif' ], true ) ) {

			/* translators: 1: MIME type received, 2: MIME type expected. */
			$found[] = 'image/' . $kind === $type ? sprintf( __( 'MIME %s', 'octave-addons' ), $type ) : sprintf( __( 'served as the wrong type (%1$s instead of %2$s)', 'octave-addons' ), '' !== $type ? $type : '?', 'image/' . $kind );

			if ( 'rewrite' === ( Octave_Addons_Perf_Imagify::config()['display_nextgen_method'] ?? '' ) ) {

				$found[] = false !== stripos( (string) wp_remote_retrieve_header( $response, 'vary' ), 'accept' ) ? __( 'right format for each browser', 'octave-addons' ) : __( 'may send one browser\'s format to another', 'octave-addons' );

			}

		}

		$status = self::cache_status( $response );

		if ( '' !== $status ) {

			$found[] = $status;

		}

		return $found;

	}

	/*
	BROWSER CACHE
	-- How long browsers may keep the file
	---------------------------------------------------------- */

	protected static function browser_cache( $response ): string {

		$control = strtolower( (string) wp_remote_retrieve_header( $response, 'cache-control' ) );

		if ( preg_match( '/max-age=(\d+)/', $control, $match ) ) {

			$days = (int) floor( (int) $match[1] / DAY_IN_SECONDS );

			/* translators: %d: days. */
			return $days >= 30 ? sprintf( __( 'kept by browsers for %d days', 'octave-addons' ), $days ) : sprintf( __( 'kept by browsers for only %d days', 'octave-addons' ), $days );

		}

		return '' !== (string) wp_remote_retrieve_header( $response, 'expires' ) ? __( 'kept by browsers (older method)', 'octave-addons' ) : __( 'not kept by browsers between visits', 'octave-addons' );

	}

	/*
	BREAKDANCE AUDIT
	-- Which of Breakdance's own performance settings are on, as label,
	-- status and note rows. Read only: Octave never changes them
	---------------------------------------------------------- */

	public static function breakdance_audit(): array {

		if ( ! function_exists( 'Breakdance\Data\get_global_option' ) ) {

			return [];

		}

		$items = [
			'gutenberg-blocks-css' => __( 'Gutenberg block CSS', 'octave-addons' ),
			'wp-emoji'             => __( 'Emoji assets', 'octave-addons' ),
			'wp-dashicons'         => __( 'Dashicons for logged-out visitors', 'octave-addons' ),
			'wp-oembed'            => __( 'oEmbed', 'octave-addons' ),
			'rsd-links'            => __( 'RSD link', 'octave-addons' ),
			'wlw-link'             => __( 'WLW manifest link', 'octave-addons' ),
			'rest-api'             => __( 'REST API discovery links', 'octave-addons' ),
			'wp-generator'         => __( 'Generator meta tag', 'octave-addons' ),
			'shortlink'            => __( 'Shortlinks', 'octave-addons' ),
			'rel-links'            => __( 'Relational links', 'octave-addons' ),
			'feed-links'           => __( 'RSS feed discovery links', 'octave-addons' ),
			'xml-rpc'              => __( 'XML-RPC', 'octave-addons' ),
		];

		$removed = (array) call_user_func( 'Breakdance\Data\get_global_option', 'breakdance_settings_bloat_eliminator' );
		$rows    = [];

		foreach ( $items as $key => $label ) {

			$rows[] = [
				'label'  => $label,
				'status' => in_array( $key, $removed, true ) ? __( 'Removed by Breakdance', 'octave-addons' ) : __( 'Left in place', 'octave-addons' ),
				'note'   => '',
			];

		}

		$rows[] = [
			'label'  => __( 'Image responsive attributes', 'octave-addons' ),
			'status' => has_filter( 'wp_calculate_image_srcset_meta' ) ? __( 'Filtered by a plugin or theme', 'octave-addons' ) : __( 'Added by WordPress', 'octave-addons' ),
			'note'   => __( 'Breakdance image elements can also turn this off one at a time.', 'octave-addons' ),
		];

		$fonts = glob( trailingslashit( (string) ( wp_upload_dir( null, false )['basedir'] ?? '' ) ) . 'breakdance/font_styles/*.css' );
		$count = is_array( $fonts ) ? count( $fonts ) : 0;

		$rows[] = [
			'label'  => __( 'Custom/local fonts', 'octave-addons' ),
			/* translators: %d: number of stylesheets. */
			'status' => $count ? sprintf( _n( '%d stylesheet, served from this site', '%d stylesheets, served from this site', $count, 'octave-addons' ), $count ) : __( 'None', 'octave-addons' ),
			'note'   => __( 'Already served from your site, so Octave simply loads them early when needed.', 'octave-addons' ),
		];

		return $rows;

	}

	public static function breakdance_settings_url(): string {

		return admin_url( 'admin.php?page=breakdance_settings&tab=bloat_eliminator' );

	}

	/*
	DIVI AUDIT
	-- Which of Divi's own performance settings (Theme Options > General >
	-- Performance) are on, with how each sits beside Octave. Read only:
	-- Octave never changes them. A setting never saved shows Divi's default
	---------------------------------------------------------- */

	public static function divi_audit(): array {

		if ( ! Octave_Addons_Builders::divi() ) {

			return [];

		}

		$options = get_option( 'et_divi', [] );
		$options = is_array( $options ) ? $options : [];

		$items = [
			'divi_dynamic_module_framework' => [ __( 'Dynamic Module Framework', 'octave-addons' ), 'on', __( 'Loads only the modules a page uses.', 'octave-addons' ) ],
			'divi_dynamic_css'              => [ __( 'Dynamic CSS', 'octave-addons' ), 'on', __( 'Writes each page\'s styles to a file, which Octave can combine.', 'octave-addons' ) ],
			'divi_critical_css'             => [ __( 'Critical CSS', 'octave-addons' ), 'on', __( 'Loads styles for lower down the page later. Octave leaves those alone.', 'octave-addons' ) ],
			'divi_dynamic_js_libraries'     => [ __( 'Dynamic JavaScript Libraries', 'octave-addons' ), 'on', __( 'Loads only the scripts a page uses. Octave never delays Divi\'s own scripts.', 'octave-addons' ) ],
			'divi_disable_emojis'           => [ __( 'Disable WordPress Emojis', 'octave-addons' ), 'on', '' ],
			'divi_defer_block_css'          => [ __( 'Defer Gutenberg Block CSS', 'octave-addons' ), 'on', '' ],
			'divi_google_fonts_inline'      => [ __( 'Improve Google Fonts Loading', 'octave-addons' ), 'off', __( 'When on, Divi handles Google Fonts, so Octave leaves them to it.', 'octave-addons' ) ],
			'divi_enable_jquery_body'       => [ __( 'Defer jQuery And jQuery Migrate', 'octave-addons' ), 'on', __( 'Works alongside Octave\'s Script Delay.', 'octave-addons' ) ],
		];

		$rows = [];

		foreach ( $items as $key => [ $label, $default, $note ] ) {

			$value = (string) ( $options[ $key ] ?? $default );

			$rows[] = [
				'label'  => $label,
				'status' => 'on' === $value ? __( 'On', 'octave-addons' ) : __( 'Off', 'octave-addons' ),
				'note'   => $note,
			];

		}

		return $rows;

	}

	public static function divi_settings_url(): string {

		return admin_url( 'admin.php?page=et_divi_options' );

	}

}
