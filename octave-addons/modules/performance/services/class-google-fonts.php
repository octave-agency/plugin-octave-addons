<?php

/*
GOOGLE FONTS SELF-HOSTING
-- Downloads a Google Fonts stylesheet and the font files it references,
-- stores them in the plugin cache and rewrites the stylesheet to point at the
-- local copies. Only fonts.googleapis.com (stylesheets) and fonts.gstatic.com
-- (font files) are ever contacted, over HTTPS, with redirects refused, so this
-- can never be used to fetch anything else
-- Every file is downloaded and validated before anything is written, then
-- written atomically into the stylesheet's own folder under a name derived
-- from its source URL, so local URLs stay stable for preloading. A failed
-- refresh leaves the last good copy in place; with no good copy the page
-- keeps Google's URL
-- Frontend requests never download anything: unknown stylesheets are queued
-- and fetched by cron
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Google_Fonts {

	public const MANIFEST_OPTION = 'octave_addons_perf_google_fonts';
	public const QUEUE_OPTION    = 'octave_addons_perf_google_fonts_queue';
	public const FETCH_HOOK      = 'octave_addons_perf_fonts_fetch';
	public const REFRESH_HOOK    = 'octave_addons_perf_fonts_refresh';
	public const DISCOVER_HOOK   = 'octave_addons_perf_fonts_discover';
	public const DISCOVERED_FLAG = 'oa_perf_fonts_discovered';
	public const DIR             = 'fonts';

	public const CSS_HOST  = 'fonts.googleapis.com';
	public const FILE_HOST = 'fonts.gstatic.com';

	protected const CSS_MAX_BYTES  = 512 * KB_IN_BYTES;
	protected const FONT_MAX_BYTES = 5 * MB_IN_BYTES;
	protected const MAX_FILES      = 300;
	protected const RETRY_AFTER    = HOUR_IN_SECONDS;

	/** A current browser user agent, so Google answers with WOFF2 files. */
	protected const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

	/** Accepted file extensions and the leading bytes each format starts with. */
	protected const FORMATS = [
		'woff2' => [ 'wOF2' ],
		'woff'  => [ 'wOFF' ],
		'ttf'   => [ "\x00\x01\x00\x00", 'true' ],
		'otf'   => [ 'OTTO' ],
	];

	/*
	IS STYLESHEET URL
	-- Google Fonts CSS endpoints only: /css, /css2 and /icon
	---------------------------------------------------------- */

	public static function is_stylesheet_url( string $url ): bool {

		$url   = self::normalize( $url );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) ) {

			return false;

		}

		return self::CSS_HOST === strtolower( $parts['host'] ?? '' ) && in_array( $parts['path'] ?? '', [ '/css', '/css2', '/icon' ], true );

	}

	/*
	IS FONT FILE URL
	-- https://fonts.gstatic.com/... ending in a supported font extension
	---------------------------------------------------------- */

	public static function is_font_file_url( string $url ): bool {

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || self::FILE_HOST !== strtolower( $parts['host'] ?? '' ) ) {

			return false;

		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {

			return false;

		}

		return '' !== self::extension( $url );

	}

	/*
	NORMALIZE
	-- Decodes &amp; from markup and upgrades protocol-relative URLs to https
	---------------------------------------------------------- */

	public static function normalize( string $url ): string {

		$url = html_entity_decode( trim( $url ), ENT_QUOTES );

		if ( 0 === strpos( $url, '//' ) ) {

			$url = 'https:' . $url;

		}

		return (string) preg_replace( '#^http://#i', 'https://', $url );

	}

	public static function key( string $url ): string {

		return substr( md5( self::normalize( $url ) ), 0, 16 );

	}

	/*
	SOURCES IN
	-- Every Google Fonts stylesheet a page asks for, however it asks: a
	-- <link> (stylesheet or preload), an @import inside a <style> block, or a
	-- WebFont.load() call from the Web Font Loader that page builders such as
	-- Breakdance and Oxygen print, which builds its stylesheet URL in the
	-- browser, so its URL is rebuilt here the same way
	---------------------------------------------------------- */

	public static function sources_in( string $html ): array {

		$sources = [];

		preg_match_all( '#(?:https?:)?//' . preg_quote( self::CSS_HOST, '#' ) . '/(?:css2?|icon)\?[^"\'\s<>()]+#i', $html, $matches );

		foreach ( $matches[0] as $url ) {

			$url = self::normalize( $url );

			if ( self::is_stylesheet_url( $url ) ) {

				$sources[ $url ] = true;

			}

		}

		foreach ( self::webfont_configs( $html ) as $config ) {

			$sources[ $config['url'] ] = true;

		}

		return array_keys( $sources );

	}

	/*
	WEBFONT CONFIGS
	-- The google: { families: [...] } blocks of WebFont.load() calls, each
	-- with the stylesheet URL the loader would request for them
	---------------------------------------------------------- */

	public static function webfont_configs( string $html ): array {

		if ( false === stripos( $html, 'WebFont' ) ) {

			return [];

		}

		preg_match_all( '/google\s*:\s*\{\s*families\s*:\s*\[([^\]]*)\][^{}]*\}/i', $html, $matches, PREG_SET_ORDER );

		$configs = [];

		foreach ( $matches as $match ) {

			preg_match_all( '/([\'"])((?:(?!\1).)+)\1/', $match[1], $strings );

			$families = array_values( array_filter( array_map( 'trim', $strings[2] ?? [] ), 'strlen' ) );
			$url      = self::webfont_url( $families );

			if ( '' !== $url ) {

				$configs[] = [ 'match' => $match[0], 'families' => $families, 'url' => $url ];

			}

		}

		return $configs;

	}

	/*
	WEBFONT URL
	-- Mirrors the Web Font Loader: families joined by |, spaces as +, and any
	-- third :subset part gathered into one subset parameter
	---------------------------------------------------------- */

	public static function webfont_url( array $families ): string {

		$names   = [];
		$subsets = [];

		foreach ( $families as $family ) {

			$parts = explode( ':', (string) $family );
			$name  = trim( $parts[0] );

			if ( '' === $name || ! preg_match( '/^[\w\s\-]+$/u', $name ) ) {

				continue;

			}

			$variants = isset( $parts[1] ) && '' !== trim( $parts[1] ) ? ':' . preg_replace( '/[^\w,;@.]/', '', $parts[1] ) : '';

			foreach ( isset( $parts[2] ) ? explode( ',', $parts[2] ) : [] as $subset ) {

				$subset = sanitize_key( $subset );

				if ( '' !== $subset ) {

					$subsets[ $subset ] = true;

				}

			}

			$names[] = str_replace( ' ', '+', $name ) . $variants;

		}

		if ( empty( $names ) ) {

			return '';

		}

		return 'https://' . self::CSS_HOST . '/css?family=' . implode( '|', $names ) . ( $subsets ? '&subset=' . implode( ',', array_keys( $subsets ) ) : '' );

	}

	/*
	DISCOVER
	-- Requests the home page (or the given pages) as a logged-out visitor,
	-- unoptimised and past any page cache, and returns the Google Fonts
	-- stylesheets found. Stylesheets used to be found only as uncached pages
	-- were rendered, which a page cache can prevent indefinitely
	---------------------------------------------------------- */

	public static function discover( array $urls = [] ): array {

		$found = [];

		foreach ( $urls ?: [ home_url( '/' ) ] as $url ) {

			$response = wp_remote_get( add_query_arg( [
				Octave_Addons_Perf_Context::BYPASS_ARG => Octave_Addons_Perf_Context::bypass_key(),
				'oa_cb'                                => time(),
			], $url ), [
				'timeout'   => 15,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'cookies'   => [],
			] );

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {

				continue;

			}

			foreach ( self::sources_in( (string) wp_remote_retrieve_body( $response ) ) as $source ) {

				$found[ $source ] = true;

			}

		}

		set_transient( self::DISCOVERED_FLAG, time(), DAY_IN_SECONDS );

		return array_keys( $found );

	}

	/*
	DISCOVER AND QUEUE
	-- Cron handler: queues every stylesheet the home page uses that has no
	-- local copy yet, then downloads them
	---------------------------------------------------------- */

	public static function discover_and_fetch(): void {

		$manifest = self::manifest();

		foreach ( self::discover() as $source ) {

			if ( empty( $manifest[ self::key( $source ) ]['folder'] ) ) {

				self::fetch( $source );

			}

		}

	}

	/*
	LOCAL URL
	-- The cached replacement for a Google stylesheet, or '' when there is no
	-- usable copy yet (the stylesheet is queued for download instead)
	---------------------------------------------------------- */

	public static function local_url( string $source ): string {

		if ( ! self::is_stylesheet_url( $source ) ) {

			return '';

		}

		$entry = self::manifest()[ self::key( $source ) ] ?? null;

		if ( is_array( $entry ) && ! empty( $entry['folder'] ) && file_exists( Octave_Addons_Perf_Store::dir( self::DIR . '/' . $entry['folder'] ) . 'fonts.css' ) ) {

			return Octave_Addons_Perf_Store::url( self::DIR . '/' . $entry['folder'] ) . 'fonts.css?ver=' . (int) ( $entry['fetched'] ?? 0 );

		}

		self::queue( $source );

		return '';

	}

	/*
	QUEUE
	-- Remembers a stylesheet to fetch from cron. A recently failed source is
	-- not queued again until RETRY_AFTER has passed
	---------------------------------------------------------- */

	public static function queue( string $source ): void {

		$source = self::normalize( $source );
		$entry  = self::manifest()[ self::key( $source ) ] ?? [];

		if ( ! empty( $entry['last_attempt'] ) && ( time() - (int) $entry['last_attempt'] ) < self::RETRY_AFTER ) {

			return;

		}

		$queue = get_option( self::QUEUE_OPTION, [] );
		$queue = is_array( $queue ) ? $queue : [];

		if ( in_array( $source, $queue, true ) || count( $queue ) >= 20 ) {

			return;

		}

		$queue[] = $source;

		update_option( self::QUEUE_OPTION, $queue, false );

		if ( ! wp_next_scheduled( self::FETCH_HOOK ) ) {

			wp_schedule_single_event( time() + 10, self::FETCH_HOOK );

		}

	}

	/*
	PROCESS QUEUE
	-- Cron handler for stylesheets found on the frontend
	---------------------------------------------------------- */

	public static function process_queue(): void {

		$queue = get_option( self::QUEUE_OPTION, [] );

		delete_option( self::QUEUE_OPTION );

		foreach ( is_array( $queue ) ? $queue : [] as $source ) {

			self::fetch( (string) $source );

		}

	}

	/*
	REFRESH
	-- Re-downloads every known stylesheet, or only those older than $max_age
	---------------------------------------------------------- */

	public static function refresh( int $max_age = 0 ): array {

		$results = [];
		$sources = [];

		foreach ( self::manifest() as $entry ) {

			if ( $max_age > 0 && ( time() - (int) ( $entry['fetched'] ?? 0 ) ) < $max_age ) {

				continue;

			}

			$sources[] = (string) ( $entry['source'] ?? '' );

		}

		$queue = get_option( self::QUEUE_OPTION, [] );

		// A manual refresh also looks at the site itself, so stylesheets are
		// found even when no uncached page view has reported them.
		if ( 0 === $max_age ) {

			$sources = array_merge( $sources, is_array( $queue ) ? $queue : [], self::discover() );

			delete_option( self::QUEUE_OPTION );

		}

		foreach ( array_unique( array_filter( $sources ) ) as $source ) {

			$results[ $source ] = self::fetch( $source );

		}

		return $results;

	}

	/*
	FETCH
	-- Downloads one stylesheet and its fonts. Returns [ ok, message ]
	---------------------------------------------------------- */

	public static function fetch( string $source ): array {

		$source = self::normalize( $source );
		$key    = self::key( $source );

		if ( ! self::is_stylesheet_url( $source ) ) {

			return self::fail( $key, $source, __( 'Not a Google Fonts stylesheet URL.', 'octave-addons' ) );

		}

		$css = self::download( $source, self::CSS_MAX_BYTES, [ 'text/css' ] );

		if ( is_wp_error( $css ) ) {

			return self::fail( $key, $source, $css->get_error_message() );

		}

		preg_match_all( '/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', $css, $matches );

		$urls = array_values( array_unique( $matches[1] ?? [] ) );

		if ( empty( $urls ) || count( $urls ) > self::MAX_FILES ) {

			return self::fail( $key, $source, __( 'The stylesheet did not contain a usable list of font files.', 'octave-addons' ) );

		}

		// Everything is downloaded and checked before anything is written, so a
		// failure part-way leaves the live copy exactly as it was.
		$bodies = [];

		foreach ( $urls as $url ) {

			if ( ! self::is_font_file_url( $url ) ) {

				return self::fail( $key, $source, __( 'The stylesheet referenced a file outside fonts.gstatic.com, so it was not cached.', 'octave-addons' ) );

			}

			$extension = self::extension( $url );
			$body      = self::download( $url, self::FONT_MAX_BYTES, [ 'font/', 'application/font', 'application/x-font', 'application/octet-stream' ] );

			if ( is_wp_error( $body ) ) {

				return self::fail( $key, $source, $body->get_error_message() );

			}

			if ( ! self::has_signature( $body, $extension ) ) {

				return self::fail( $key, $source, __( 'A downloaded file was not a valid font.', 'octave-addons' ) );

			}

			// Named by source URL, so a file keeps its local URL across refreshes
			// and stays valid in the preload list.
			$bodies[ substr( md5( $url ), 0, 12 ) . '.' . $extension ] = [ $url, $body ];

		}

		$manifest = self::manifest();
		$previous = array_column( (array) ( $manifest[ $key ]['files'] ?? [] ), 'file' );
		$dir      = Octave_Addons_Perf_Store::dir( self::DIR . '/' . $key );
		$map      = [];
		$files    = [];

		foreach ( $bodies as $name => [ $url, $body ] ) {

			if ( ! Octave_Addons_Perf_Store::write( $dir . $name, $body ) ) {

				self::remove_files( $dir, array_diff( array_keys( $map ), $previous ) );

				return self::fail( $key, $source, __( 'A font file could not be written to the cache folder.', 'octave-addons' ) );

			}

			$map[ $url ] = $name;
			$files[]     = [ 'file' => $name, 'size' => strlen( $body ), 'format' => self::extension( $url ) ];

		}

		if ( ! Octave_Addons_Perf_Store::write( $dir . 'fonts.css', self::rewrite_css( $css, $map, self::font_display() ) ) ) {

			self::remove_files( $dir, array_diff( array_values( $map ), $previous ) );

			return self::fail( $key, $source, __( 'The stylesheet could not be written to the cache folder.', 'octave-addons' ) );

		}

		$manifest[ $key ] = [
			'source'       => $source,
			'folder'       => $key,
			'families'     => self::families( $css ),
			'files'        => $files,
			'fetched'      => time(),
			'last_attempt' => 0,
			'error'        => '',
		];

		update_option( self::MANIFEST_OPTION, $manifest, false );

		self::remove_files( $dir, array_diff( $previous, array_values( $map ) ) );

		do_action( 'octave_addons_perf_fonts_changed', $source );

		return [ 'ok' => true, 'message' => sprintf(
			/* translators: %d: number of font files. */
			_n( 'Cached %d font file.', 'Cached %d font files.', count( $files ), 'octave-addons' ),
			count( $files )
		) ];

	}

	/*
	REWRITE CSS
	-- Points every font URL at its local file (relative, so the stylesheet
	-- works on any host or CDN) and adds font-display to @font-face blocks
	-- that do not set their own. 'keep' leaves the source untouched
	---------------------------------------------------------- */

	public static function rewrite_css( string $css, array $map, string $display ): string {

		$css = (string) preg_replace_callback( '/url\(\s*([\'"]?)([^\'")\s]+)\1\s*\)/i', static function ( array $match ) use ( $map ): string {

			return isset( $map[ $match[2] ] ) ? 'url(' . $map[ $match[2] ] . ')' : $match[0];

		}, $css );

		if ( 'keep' === $display ) {

			return $css;

		}

		return (string) preg_replace_callback( '/@font-face\s*\{([^}]*)\}/i', static function ( array $match ) use ( $display ): string {

			if ( false !== stripos( $match[1], 'font-display' ) ) {

				return $match[0];

			}

			return '@font-face {' . rtrim( $match[1] ) . "\n  font-display: " . $display . ";\n}";

		}, $css );

	}

	/*
	FAMILIES
	-- Font family names declared in a stylesheet, for the status panel
	---------------------------------------------------------- */

	public static function families( string $css ): array {

		preg_match_all( '/font-family:\s*[\'"]?([^;\'"]+)[\'"]?\s*;/i', $css, $matches );

		return array_values( array_unique( array_map( 'trim', $matches[1] ?? [] ) ) );

	}

	public static function manifest(): array {

		$manifest = get_option( self::MANIFEST_OPTION, [] );

		return is_array( $manifest ) ? $manifest : [];

	}

	/*
	CACHED FILES
	-- Local font files across every cached stylesheet, for the preload picker
	---------------------------------------------------------- */

	public static function cached_files(): array {

		$files = [];

		foreach ( self::manifest() as $entry ) {

			if ( empty( $entry['folder'] ) ) {

				continue;

			}

			foreach ( (array) ( $entry['files'] ?? [] ) as $file ) {

				$files[] = [
					'url'      => Octave_Addons_Perf_Store::url( self::DIR . '/' . $entry['folder'] ) . $file['file'],
					'format'   => $file['format'],
					'size'     => (int) $file['size'],
					'families' => implode( ', ', (array) ( $entry['families'] ?? [] ) ),
				];

			}

		}

		return $files;

	}

	/*
	LATIN FILES
	-- Local font files whose @font-face covers basic Latin text, in the order
	-- the cached stylesheets declare them. Read from the stylesheets on disk,
	-- so it works for copies cached before this existed
	---------------------------------------------------------- */

	public static function latin_files( int $max ): array {

		$urls = [];

		foreach ( self::manifest() as $entry ) {

			if ( empty( $entry['folder'] ) ) {

				continue;

			}

			$file = Octave_Addons_Perf_Store::dir( self::DIR . '/' . $entry['folder'] ) . 'fonts.css';

			if ( ! is_readable( $file ) ) {

				continue;

			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Octave's own cache file.
			preg_match_all( '/@font-face\s*\{([^}]*)\}/i', (string) file_get_contents( $file ), $blocks );

			foreach ( $blocks[1] as $block ) {

				if ( ! preg_match( '/url\(\s*[\'"]?([^\'")\s]+\.woff2)[\'"]?\s*\)/i', $block, $font ) ) {

					continue;

				}

				if ( preg_match( '/unicode-range\s*:\s*([^;]+)/i', $block, $range ) && ! self::covers_latin( $range[1] ) ) {

					continue;

				}

				$urls[] = Octave_Addons_Perf_Store::url( self::DIR . '/' . $entry['folder'] ) . basename( $font[1] );

			}

		}

		return array_slice( array_values( array_unique( $urls ) ), 0, max( 0, $max ) );

	}

	/*
	COVERS LATIN
	-- True when a unicode-range includes the letter A (U+0041)
	---------------------------------------------------------- */

	public static function covers_latin( string $ranges ): bool {

		foreach ( explode( ',', $ranges ) as $range ) {

			if ( ! preg_match( '/U\+([0-9a-f?]+)(?:-([0-9a-f]+))?/i', trim( $range ), $match ) ) {

				continue;

			}

			$start = hexdec( str_replace( '?', '0', $match[1] ) );
			$end   = isset( $match[2] ) ? hexdec( $match[2] ) : hexdec( str_replace( '?', 'f', $match[1] ) );

			if ( $start <= 0x41 && $end >= 0x41 ) {

				return true;

			}

		}

		return false;

	}

	/*
	DOWNLOAD
	-- One HTTPS GET with no redirects, a size cap and a content-type check
	---------------------------------------------------------- */

	protected static function download( string $url, int $max_bytes, array $types ) {

		$response = wp_safe_remote_get( $url, [
			'timeout'             => 10,
			'redirection'         => 0,
			'limit_response_size' => $max_bytes + 1,
			'user-agent'          => self::USER_AGENT,
		] );

		if ( is_wp_error( $response ) ) {

			return $response;

		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {

			/* translators: 1: HTTP status code, 2: URL. */
			return new WP_Error( 'oa_font_http', sprintf( __( 'Google returned HTTP %1$d for %2$s.', 'octave-addons' ), $code, $url ) );

		}

		if ( '' === Octave_Addons_Perf::matches_any( $type, $types ) ) {

			/* translators: %s: content type. */
			return new WP_Error( 'oa_font_type', sprintf( __( 'Unexpected content type "%s".', 'octave-addons' ), $type ) );

		}

		if ( '' === $body || strlen( $body ) > $max_bytes ) {

			return new WP_Error( 'oa_font_size', __( 'The response was empty or larger than allowed.', 'octave-addons' ) );

		}

		return $body;

	}

	protected static function extension( string $url ): string {

		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		return isset( self::FORMATS[ $extension ] ) ? $extension : '';

	}

	protected static function has_signature( string $body, string $extension ): bool {

		foreach ( self::FORMATS[ $extension ] ?? [] as $signature ) {

			if ( 0 === strncmp( $body, $signature, strlen( $signature ) ) ) {

				return true;

			}

		}

		return false;

	}

	protected static function font_display(): string {

		$display = (string) ( Octave_Addons_Perf::settings( 'performance-fonts' )['font_display'] ?? 'swap' );

		return in_array( $display, [ 'swap', 'optional', 'fallback', 'block', 'auto', 'keep' ], true ) ? $display : 'swap';

	}

	/*
	REMOVE FILES
	-- Deletes named files from a stylesheet's folder, never the folder itself
	---------------------------------------------------------- */

	protected static function remove_files( string $dir, array $names ): void {

		foreach ( $names as $name ) {

			$path = $dir . basename( (string) $name );

			if ( is_file( $path ) && Octave_Addons_Perf_Store::is_inside( $path ) ) {

				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Octave's own cache file.
				@unlink( $path );

			}

		}

	}

	/*
	FAIL
	-- Records the error and attempt time against the source, keeping any
	-- previously cached folder so the last good copy continues to be served
	---------------------------------------------------------- */

	protected static function fail( string $key, string $source, string $message ): array {

		$manifest = self::manifest();
		$entry    = is_array( $manifest[ $key ] ?? null ) ? $manifest[ $key ] : [ 'source' => $source, 'folder' => '', 'files' => [], 'families' => [], 'fetched' => 0 ];

		$entry['error']        = $message;
		$entry['last_attempt'] = time();
		$manifest[ $key ]      = $entry;

		update_option( self::MANIFEST_OPTION, $manifest, false );

		Octave_Addons_Perf_Log::error( 'fonts', $message, $source );

		return [ 'ok' => false, 'message' => $message ];

	}

}
