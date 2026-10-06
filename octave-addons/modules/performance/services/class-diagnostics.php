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
	protected const CACHE_HEADERS = [ 'x-litespeed-cache', 'cf-cache-status', 'x-cache', 'x-proxy-cache', 'x-kinsta-cache', 'x-cache-status', 'x-sucuri-cache' ];

	/*
	NOTE ASSETS
	-- During a scan, after every footer script has printed: which Octave and
	-- Breakdance scripts and styles the page actually loaded
	---------------------------------------------------------- */

	public static function note_assets(): void {

		if ( ! Octave_Addons_Perf_Log::is_reporting() || ! function_exists( 'wp_scripts' ) || ! function_exists( 'wp_styles' ) ) {

			return;

		}

		$assets = [ 'octave' => [], 'breakdance' => [] ];

		foreach ( [ wp_scripts(), wp_styles() ] as $dependencies ) {

			foreach ( (array) $dependencies->done as $handle ) {

				$src = (string) ( $dependencies->registered[ $handle ]->src ?? '' );

				if ( 0 === strpos( (string) $handle, 'octave' ) ) {

					$assets['octave'][] = (string) $handle;

				} elseif ( 0 === strpos( (string) $handle, 'breakdance' ) || false !== strpos( $src, '/breakdance/' ) ) {

					$assets['breakdance'][] = (string) $handle;

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

			return __( 'WP Rocket cached page', 'octave-addons' );

		}

		if ( false !== stripos( $body, 'Cache served by breeze' ) ) {

			return __( 'Breeze cached page', 'octave-addons' );

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
			'heading' => __( 'Page delivery', 'octave-addons' ),
			'rows'    => array_filter( [
				/* translators: %d: milliseconds. */
				sprintf( __( 'Uncached response (scan): %d ms', 'octave-addons' ), (int) $scan_ms ),
				is_wp_error( $plain ) ? '' : sprintf( /* translators: %d: milliseconds. */ __( 'Response as a visitor (time to full response, not only first byte): %d ms', 'octave-addons' ), (int) $plain_ms ),
				/* translators: %s: cache owner. */
				sprintf( __( 'Full-page cache: %s', 'octave-addons' ), '' !== $owner ? $owner : __( 'none detected', 'octave-addons' ) ),
				/* translators: %s: cache status header. */
				sprintf( __( 'Cache or edge status: %s', 'octave-addons' ), '' !== $status ? $status : __( 'not reported by the response', 'octave-addons' ) ),
			] ),
		];

		$names = [
			'oa-media'      => __( 'Media processing', 'octave-addons' ),
			'oa-delay'      => __( 'Third-party delay', 'octave-addons' ),
			'oa-fonts'      => __( 'Font rewriting', 'octave-addons' ),
			'oa-css-inline' => __( 'Stylesheet inlining', 'octave-addons' ),
			'oa-total'      => __( 'Total HTML processing', 'octave-addons' ),
		];
		$rows  = [];

		foreach ( (array) ( $summary['timings'] ?? [] ) as $name => $ms ) {

			$rows[] = ( $names[ $name ] ?? $name ) . ': ' . number_format_i18n( (float) $ms, 2 ) . ' ms';

		}

		/* translators: %s: size. */
		$rows[] = sprintf( __( 'Inlined CSS: %s', 'octave-addons' ), size_format( (int) ( $summary['inlined_css_bytes'] ?? 0 ) ) ?: '0 B' );

		$delayed = count( array_filter( (array) ( $report['delay'] ?? [] ), static function ( $item ): bool {

			return 'delayed' === ( $item['action'] ?? '' );

		} ) );

		$lazy = count( array_filter( (array) ( $report['media'] ?? [] ), static function ( $item ): bool {

			return 'lazy' === ( $item['action'] ?? '' );

		} ) );

		/* translators: %d: count. */
		$rows[] = sprintf( __( 'Delayed scripts: %d', 'octave-addons' ), $delayed );
		/* translators: %d: count. */
		$rows[] = sprintf( __( 'Lazy-loaded media: %d', 'octave-addons' ), $lazy );

		$details[] = [ 'heading' => __( 'HTML processing', 'octave-addons' ), 'rows' => $rows ];

		$assets    = (array) ( $summary['assets'] ?? [] );
		$details[] = [
			'heading' => __( 'Assets loaded', 'octave-addons' ),
			'rows'    => [
				/* translators: %s: handles. */
				sprintf( __( 'Octave: %s', 'octave-addons' ), implode( ', ', (array) ( $assets['octave'] ?? [] ) ) ?: __( 'none', 'octave-addons' ) ),
				/* translators: %s: handles. */
				sprintf( __( 'Breakdance: %s', 'octave-addons' ), implode( ', ', (array) ( $assets['breakdance'] ?? [] ) ) ?: __( 'none', 'octave-addons' ) ),
				/* translators: %s: font URLs. */
				sprintf( __( 'Preloaded fonts: %s', 'octave-addons' ), implode( ', ', (array) ( $summary['preloaded_fonts'] ?? [] ) ) ?: __( 'none', 'octave-addons' ) ),
			],
		];

		$details[] = [ 'heading' => __( 'Largest Contentful Paint', 'octave-addons' ), 'rows' => self::lcp_rows( Octave_Addons_Perf_Lcp::entry( Octave_Addons_Perf_Lcp::path( $url ) ) ) ];

		$imagify = [];

		if ( Octave_Addons_Perf_Imagify::is_active() ) {

			$test      = Octave_Addons_Perf_Imagify::status()['test'] ?? [];
			$imagify[] = Octave_Addons_Perf_Imagify::available() ? sprintf( /* translators: %s: version. */ __( 'Imagify %s', 'octave-addons' ), Octave_Addons_Perf_Imagify::version() ) : Octave_Addons_Perf_Imagify::unavailable_reason();
			$imagify[] = empty( $test['time'] ) ? __( 'WebP/AVIF delivery test: not run yet', 'octave-addons' ) : ( ! empty( $test['ok'] ) ? __( 'WebP/AVIF delivery test: passed', 'octave-addons' ) : __( 'WebP/AVIF delivery test: failed', 'octave-addons' ) );

		} else {

			$imagify[] = __( 'Imagify is not active.', 'octave-addons' );

		}

		$details[] = [ 'heading' => __( 'Next-generation images', 'octave-addons' ), 'rows' => $imagify ];

		$conflicts = [];

		foreach ( Octave_Addons_Perf_Owners::conflicts() as $row ) {

			$conflicts[] = $row['label'] . ': ' . implode( ', ', $row['owners'] );

		}

		$details[] = [ 'heading' => __( 'Duplicate optimisation', 'octave-addons' ), 'rows' => $conflicts ?: [ __( 'None: each feature has at most one owner.', 'octave-addons' ) ] ];

		return $details;

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

				$rows[] = $labels[ $device ] . ': ' . __( 'no image (text is the largest element)', 'octave-addons' );

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

			$lines[] = __( 'WebP/AVIF: no Imagify next-generation copy to test.', 'octave-addons' );

		}

		$lines[] = 'nginx' === Octave_Addons_Perf_Imagify::server()
			? __( 'Nginx: add compression, caching and MIME rules in the server configuration; .htaccess is not read.', 'octave-addons' )
			: __( 'Octave never writes these rules. Add missing ones through the host, the web server or the cache plugin that already manages them.', 'octave-addons' );

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
			return [ sprintf( __( 'could not be requested: %s', 'octave-addons' ), $response->get_error_message() ) ];

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

			$found[] = false !== strpos( $encoding, 'br' ) ? __( 'Brotli', 'octave-addons' ) : ( false !== strpos( $encoding, 'gzip' ) ? __( 'Gzip (no Brotli)', 'octave-addons' ) : __( 'not compressed', 'octave-addons' ) );

		}

		$found[] = self::browser_cache( $response );

		if ( 'font' === $kind ) {

			$found[] = '' !== (string) wp_remote_retrieve_header( $response, 'access-control-allow-origin' ) ? __( 'CORS header sent', 'octave-addons' ) : __( 'no CORS header (needed only if fonts are served from another domain)', 'octave-addons' );

		}

		if ( in_array( $kind, [ 'webp', 'avif' ], true ) ) {

			/* translators: 1: MIME type received, 2: MIME type expected. */
			$found[] = 'image/' . $kind === $type ? sprintf( __( 'MIME %s', 'octave-addons' ), $type ) : sprintf( __( 'wrong MIME %1$s, expected %2$s', 'octave-addons' ), '' !== $type ? $type : '?', 'image/' . $kind );

			if ( 'rewrite' === ( Octave_Addons_Perf_Imagify::config()['display_nextgen_method'] ?? '' ) ) {

				$found[] = false !== stripos( (string) wp_remote_retrieve_header( $response, 'vary' ), 'accept' ) ? __( 'Vary: Accept', 'octave-addons' ) : __( 'no Vary: Accept', 'octave-addons' );

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
			return $days >= 30 ? sprintf( __( 'cached %d days', 'octave-addons' ), $days ) : sprintf( __( 'cached only %d days', 'octave-addons' ), $days );

		}

		return '' !== (string) wp_remote_retrieve_header( $response, 'expires' ) ? __( 'Expires header only', 'octave-addons' ) : __( 'no browser caching header', 'octave-addons' );

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
			'note'   => __( 'Breakdance image elements can also switch srcset and sizes off per element.', 'octave-addons' ),
		];

		$fonts = glob( trailingslashit( (string) ( wp_upload_dir( null, false )['basedir'] ?? '' ) ) . 'breakdance/font_styles/*.css' );
		$count = is_array( $fonts ) ? count( $fonts ) : 0;

		$rows[] = [
			'label'  => __( 'Custom/local fonts', 'octave-addons' ),
			/* translators: %d: number of stylesheets. */
			'status' => $count ? sprintf( _n( '%d stylesheet, served from this site', '%d stylesheets, served from this site', $count, 'octave-addons' ), $count ) : __( 'None', 'octave-addons' ),
			'note'   => __( 'Already self-hosted, so Octave preloads them like any local font and never rewrites them.', 'octave-addons' ),
		];

		return $rows;

	}

	public static function breakdance_settings_url(): string {

		return admin_url( 'admin.php?page=breakdance_settings&tab=bloat_eliminator' );

	}

}
