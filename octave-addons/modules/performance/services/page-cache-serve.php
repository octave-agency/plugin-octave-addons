<?php

/*
OCTAVE PAGE CACHE: SERVE
-- Standalone: loaded by the advanced-cache.php drop-in before WordPress has
-- loaded anything, so it uses plain PHP only. Decides whether a request may
-- be answered from a stored page and, when one exists and is fresh, sends
-- it and stops. Anything uncertain returns false and WordPress renders the
-- page as normal
-- Only anonymous GET and HEAD requests for page-like paths with no query
-- string, other than ignored marketing arguments, are ever answered. Login,
-- cart, comment-author and password cookies, an Authorization header and a
-- warming request always reach WordPress
-- The same key function names the file the plugin writes, so the two can
-- never disagree about where a page lives
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

if ( ! function_exists( 'octave_addons_page_cache_key' ) ) {

	/*
	KEY
	-- "host/path/index[-https].html" for a cacheable request, or ''
	---------------------------------------------------------- */

	function octave_addons_page_cache_key( array $config, array $server, array $cookies ): string {

		$method = strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) );

		if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) || ! empty( $server['HTTP_AUTHORIZATION'] ) ) {

			return '';

		}

		foreach ( array_keys( $cookies ) as $name ) {

			foreach ( (array) ( $config['cookies'] ?? [] ) as $prefix ) {

				if ( '' !== (string) $prefix && 0 === strpos( (string) $name, (string) $prefix ) ) {

					return '';

				}

			}

		}

		$uri   = (string) ( $server['REQUEST_URI'] ?? '' );
		$path  = (string) parse_url( $uri, PHP_URL_PATH );
		$query = (string) parse_url( $uri, PHP_URL_QUERY );

		if ( '' !== $query ) {

			parse_str( $query, $args );

			foreach ( (array) ( $config['ignore_query'] ?? [] ) as $ignored ) {

				unset( $args[ $ignored ] );

			}

			if ( ! empty( $args ) ) {

				return '';

			}

		}

		if ( '' === $path || '/' !== $path[0] || false !== strpos( $path, '..' ) || strlen( $path ) > 512 || ! preg_match( '#^[A-Za-z0-9/_.~%-]+$#', $path ) ) {

			return '';

		}

		foreach ( (array) ( $config['skip_paths'] ?? [] ) as $skip ) {

			if ( '' !== (string) $skip && false !== stripos( $path, (string) $skip ) ) {

				return '';

			}

		}

		// Pages only: a path ending in a slash, with no extension, or in .html.
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( '/' !== substr( $path, -1 ) && '' !== $extension && 'html' !== $extension ) {

			return '';

		}

		$host = strtolower( (string) ( $server['HTTP_HOST'] ?? '' ) );

		if ( '' === $host || ! preg_match( '/^[a-z0-9.-]+(:\d{1,5})?$/', $host ) || ! in_array( $host, (array) ( $config['hosts'] ?? [] ), true ) ) {

			return '';

		}

		$https = ( ! empty( $server['HTTPS'] ) && 'off' !== strtolower( (string) $server['HTTPS'] ) ) || 'https' === strtolower( (string) ( $server['HTTP_X_FORWARDED_PROTO'] ?? '' ) );

		// Percent-encoded as requested, so the name matches the permalink a purge is given.
		// A path without its trailing slash is its own file: WordPress may redirect it.
		$segment = trim( $path, '/' );
		$variant = ( '' !== $segment && '/' !== substr( $path, -1 ) ? '-noslash' : '' ) . ( $https ? '-https' : '' );

		return str_replace( ':', '_', $host ) . ( '' !== $segment ? '/' . $segment : '' ) . '/index' . $variant . '.html';

	}

	/*
	SERVE
	-- Sends a stored page and exits, or returns false
	---------------------------------------------------------- */

	function octave_addons_page_cache_serve( array $config ): bool {

		try {

			if ( ! empty( $_SERVER['HTTP_X_OCTAVE_WARM'] ) || headers_sent() ) {

				return false;

			}

			$key = octave_addons_page_cache_key( $config, $_SERVER, $_COOKIE );

			if ( '' === $key || false !== strpos( $key, '..' ) ) {

				return false;

			}

			$file  = rtrim( (string) $config['dir'], '/' ) . '/' . ltrim( $key, '/' );
			$mtime = is_file( $file ) ? (int) @filemtime( $file ) : 0;

			if ( ! $mtime || ( (int) $config['lifespan'] > 0 && $mtime < time() - (int) $config['lifespan'] ) ) {

				return false;

			}

			header( 'Content-Type: text/html; charset=' . (string) ( $config['charset'] ?? 'UTF-8' ) );
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
			header( 'X-Octave-Cache: HIT' );

			if ( 'HEAD' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {

				readfile( $file );

			}

			exit;

		} catch ( \Throwable $error ) {

			return false;

		}

	}

}
