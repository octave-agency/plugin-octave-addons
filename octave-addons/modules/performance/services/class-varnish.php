<?php

/*
PERFORMANCE: CLOUDWAYS VARNISH
-- Clears the Varnish page cache Cloudways runs in front of WordPress. The
-- application talks to the Varnish service on its own server, so no
-- Cloudways API credentials are needed
-- Protocol: an HTTP PURGE request to 127.0.0.1:8080 carrying the site's
-- public host in the Host header. A targeted purge names one path; a full
-- purge names the site's root path followed by .* and asks for a regex
-- match through the X-Purge-Method header
-- Cloudways is recognised by the cw_allowed_ip value its servers add to
-- every PHP request, and Varnish by the X-Varnish and X-Application request
-- headers it adds while it is switched on. Cron and WP-CLI requests carry
-- no headers, so the last answer from a web request is remembered for
-- them. With no answer at all, Varnish is treated as absent
-- Requests use a short timeout. When one fails or times out the rest are
-- skipped, so a stopped service can never hold up the request for long
-- Only paths built from URLs on this site are ever purged
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Varnish {

	/** Where the Cloudways Varnish service listens. */
	public const ENDPOINT = '127.0.0.1:8080';

	/** Seconds one purge request may take. */
	public const TIMEOUT = 2;

	/** Remembered state, for requests that carry no Varnish headers. */
	public const STATE_OPTION = 'octave_addons_perf_varnish';

	public const LABEL = 'Cloudways Varnish';

	/*
	IS CLOUDWAYS
	---------------------------------------------------------- */

	public static function is_cloudways(): bool {

		return isset( $_SERVER['cw_allowed_ip'] );

	}

	/*
	IS RUNNING
	-- Whether Cloudways Varnish is switched on for this application. A web
	-- request answers from its headers and records the answer; anything
	-- else uses the recorded answer
	---------------------------------------------------------- */

	public static function is_running(): bool {

		$state = self::request_state();

		if ( '' !== $state ) {

			self::remember( $state );

			return 'on' === $state;

		}

		$saved = get_option( self::STATE_OPTION, [] );

		return is_array( $saved ) && 'on' === ( $saved['state'] ?? '' );

	}

	/*
	REQUEST STATE
	-- 'on', 'off', or '' when this request cannot tell. Varnish marks a
	-- request it passed straight through, with caching switched off, as
	-- X-Application: varnishpass
	---------------------------------------------------------- */

	public static function request_state(): string {

		if ( ! self::is_cloudways() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {

			return '';

		}

		$varnish     = isset( $_SERVER['HTTP_X_VARNISH'] );
		$application = isset( $_SERVER['HTTP_X_APPLICATION'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_APPLICATION'] ) ) ) ) : '';

		return $varnish && '' !== $application && 'varnishpass' !== $application ? 'on' : 'off';

	}

	protected static function remember( string $state ): void {

		$saved = get_option( self::STATE_OPTION, [] );

		if ( is_array( $saved ) && $state === ( $saved['state'] ?? '' ) && (int) ( $saved['time'] ?? 0 ) > time() - HOUR_IN_SECONDS ) {

			return;

		}

		update_option( self::STATE_OPTION, [ 'state' => $state, 'time' => time() ], false );

	}

	/*
	HANDLED BY
	-- Another plugin that already purges Cloudways Varnish whenever Octave
	-- asks it to clear, so a second PURGE would only repeat the work. WP
	-- Rocket switches its Varnish purge on by itself on Cloudways; Breeze
	-- does it when its Varnish auto-purge option is on
	---------------------------------------------------------- */

	public static function handled_by(): string {

		$owner = '';

		if ( defined( 'WP_ROCKET_VERSION' ) && function_exists( 'rocket_clean_domain' ) ) {

			$owner = 'WP Rocket';

		} elseif ( defined( 'BREEZE_VERSION' ) ) {

			$varnish = get_option( 'breeze_varnish_cache', [] );

			$owner = is_array( $varnish ) && ! empty( $varnish['auto-purge-varnish'] ) ? 'Breeze' : '';

		}

		/**
		 * Filters which plugin, if any, already purges Cloudways Varnish.
		 *
		 * @param string $owner Plugin name, or '' for Octave to purge it.
		 */
		return (string) apply_filters( 'octave_addons_perf_varnish_handled_by', $owner );

	}

	/*
	PURGE URLS
	-- One PURGE per unique path. Returns one purge-layer result
	---------------------------------------------------------- */

	public static function purge_urls( array $urls ): array {

		$paths = [];

		foreach ( Octave_Addons_Perf_Cache::normalize_urls( $urls ) as $url ) {

			$path = self::path( $url );

			if ( '' !== $path ) {

				$paths[ $path ] = true;

			}

		}

		if ( empty( $paths ) ) {

			return self::result( 'skipped', __( 'No pages on this site to clear.', 'octave-addons' ) );

		}

		foreach ( array_keys( $paths ) as $path ) {

			$error = self::send( $path, 'default' );

			if ( '' !== $error ) {

				return self::result( 'error', $error );

			}

		}

		/* translators: %d: number of URLs. */
		return self::result( 'success', sprintf( _n( 'Cleared %d page.', 'Cleared %d pages.', count( $paths ), 'octave-addons' ), count( $paths ) ) );

	}

	/*
	PURGE ALL
	-- Everything under the site's root path, by regex
	---------------------------------------------------------- */

	public static function purge_all(): array {

		$error = self::send( self::path( home_url( '/' ) ) . '.*', 'regex' );

		return '' === $error ? self::result( 'success', __( 'Cleared everything.', 'octave-addons' ) ) : self::result( 'error', $error );

	}

	/*
	PATH
	-- The request path and query Varnish caches a URL under. Anything that
	-- could alter the request line is refused
	---------------------------------------------------------- */

	public static function path( string $url ): string {

		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$path  = '' === $path ? '/' : $path;

		if ( '/' !== $path[0] || preg_match( '/[\s\x00-\x1f\x7f]/', $path . $query ) ) {

			return '';

		}

		return $path . ( '' !== $query ? '?' . $query : '' );

	}

	/*
	SEND
	-- One PURGE request. Returns '' on success or a readable error
	---------------------------------------------------------- */

	protected static function send( string $path, string $method ): string {

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( '' === $host || '' === $path ) {

			return __( 'Your site address could not be read.', 'octave-addons' );

		}

		$response = wp_remote_request( 'http://' . self::ENDPOINT . $path, [
			'method'      => 'PURGE',
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'blocking'    => true,
			'headers'     => [
				'Host'           => $host,
				'X-Purge-Method' => $method,
			],
		] );

		if ( is_wp_error( $response ) ) {

			Octave_Addons_Perf_Log::error( 'page-cache', $response->get_error_message(), self::LABEL );

			/* translators: %s: error message. */
			return sprintf( __( 'Cloudways Varnish did not respond: %s', 'octave-addons' ), $response->get_error_message() );

		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {

			/* translators: %d: HTTP status code. */
			return sprintf( __( 'Cloudways Varnish would not clear the page (error %d).', 'octave-addons' ), $code );

		}

		return '';

	}

	protected static function result( string $status, string $message ): array {

		return [ 'label' => self::LABEL, 'status' => $status, 'message' => $message ];

	}

}
