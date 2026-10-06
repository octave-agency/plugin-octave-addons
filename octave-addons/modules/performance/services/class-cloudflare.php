<?php

/*
CLOUDFLARE CLIENT
-- A minimal cache-purge client for one zone, authenticated with a scoped API
-- token (Zone > Cache Purge). Credentials come from wp-config constants when
-- defined, otherwise from the saved settings; the token sits in its own
-- non-autoloaded option and is never sent to the browser
-- Requests are single attempts with a short timeout. Automatic purges are
-- queued and sent from cron in batches, so a slow or failing API never holds
-- up or fails a content save
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Cloudflare {

	public const API_BASE     = 'https://api.cloudflare.com/client/v4/';
	public const TOKEN_OPTION = 'octave_addons_perf_cf_token';
	public const QUEUE_OPTION = 'octave_addons_perf_cf_queue';
	public const STATUS_OPTION = 'octave_addons_perf_cf_status';
	public const FLUSH_HOOK   = 'octave_addons_perf_cf_flush';

	/** Files per purge request, within Cloudflare's per-request limit on every plan. */
	public const BATCH = 30;

	/** Most URLs kept waiting in the queue; beyond this the oldest are dropped. */
	protected const QUEUE_MAX = 500;

	/*
	ZONE ID / TOKEN
	-- wp-config constants win over saved settings
	---------------------------------------------------------- */

	public static function zone_id(): string {

		if ( defined( 'OCTAVE_ADDONS_CLOUDFLARE_ZONE_ID' ) ) {

			return (string) OCTAVE_ADDONS_CLOUDFLARE_ZONE_ID;

		}

		return (string) ( Octave_Addons_Perf::settings( 'performance-cloudflare' )['zone_id'] ?? '' );

	}

	public static function token(): string {

		if ( defined( 'OCTAVE_ADDONS_CLOUDFLARE_API_TOKEN' ) ) {

			return (string) OCTAVE_ADDONS_CLOUDFLARE_API_TOKEN;

		}

		return (string) get_option( self::TOKEN_OPTION, '' );

	}

	public static function is_valid_zone_id( string $zone_id ): bool {

		return 1 === preg_match( '/^[a-f0-9]{32}$/', $zone_id );

	}

	public static function is_configured(): bool {

		return self::is_valid_zone_id( self::zone_id() ) && '' !== self::token();

	}

	/*
	TEST CONNECTION
	-- Verifies the token. A Cache Purge-only token cannot read zone details,
	-- so the zone is checked for format rather than fetched
	---------------------------------------------------------- */

	public static function test(): array {

		if ( ! self::is_valid_zone_id( self::zone_id() ) ) {

			return self::result( false, __( 'The Zone ID should be the 32-character ID shown on the zone\'s Overview page.', 'octave-addons' ) );

		}

		if ( '' === self::token() ) {

			return self::result( false, __( 'No API token is saved.', 'octave-addons' ) );

		}

		$result = self::request( 'GET', 'user/tokens/verify' );

		if ( $result['ok'] && 'active' !== ( $result['data']['result']['status'] ?? '' ) ) {

			$result = self::result( false, __( 'The token exists but is not active.', 'octave-addons' ) );

		}

		if ( $result['ok'] ) {

			$result['message'] = __( 'Connected. The token is active.', 'octave-addons' );

		}

		update_option( self::STATUS_OPTION, [ 'time' => time(), 'ok' => $result['ok'], 'message' => $result['message'] ], false );

		return $result;

	}

	/*
	PURGE FILES
	-- Targeted purge, sent in batches
	---------------------------------------------------------- */

	public static function purge_files( array $urls ): array {

		$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );

		if ( empty( $urls ) ) {

			return self::result( true, __( 'No URLs to purge.', 'octave-addons' ) );

		}

		foreach ( array_chunk( $urls, self::BATCH ) as $batch ) {

			$result = self::request( 'POST', self::purge_path(), self::files_payload( $batch ) );

			if ( ! $result['ok'] ) {

				return $result;

			}

		}

		return self::result( true, sprintf(
			/* translators: %d: number of URLs. */
			_n( 'Purged %d URL.', 'Purged %d URLs.', count( $urls ), 'octave-addons' ),
			count( $urls )
		) );

	}

	public static function purge_everything(): array {

		$result = self::request( 'POST', self::purge_path(), [ 'purge_everything' => true ] );

		if ( $result['ok'] ) {

			$result['message'] = __( 'Purged everything in the zone.', 'octave-addons' );

		}

		return $result;

	}

	public static function files_payload( array $urls ): array {

		return [ 'files' => array_values( $urls ) ];

	}

	protected static function purge_path(): string {

		return 'zones/' . rawurlencode( self::zone_id() ) . '/purge_cache';

	}

	/*
	QUEUE / FLUSH
	-- Automatic purges add to a queue and ask cron to send it shortly after,
	-- so several saves in a row collapse into one request
	---------------------------------------------------------- */

	public static function queue( array $urls ): void {

		$queue = get_option( self::QUEUE_OPTION, [] );
		$queue = array_values( array_unique( array_merge( is_array( $queue ) ? $queue : [], $urls ) ) );

		update_option( self::QUEUE_OPTION, array_slice( $queue, -self::QUEUE_MAX ), false );

		if ( ! wp_next_scheduled( self::FLUSH_HOOK ) ) {

			wp_schedule_single_event( time() + 30, self::FLUSH_HOOK );

		}

	}

	public static function flush_queue(): array {

		$queue = get_option( self::QUEUE_OPTION, [] );

		delete_option( self::QUEUE_OPTION );

		if ( empty( $queue ) || ! is_array( $queue ) || ! self::is_configured() ) {

			return self::result( true, '' );

		}

		$result = self::purge_files( $queue );

		if ( ! $result['ok'] ) {

			Octave_Addons_Perf_Log::error( 'cloudflare', $result['message'], 'queue' );

		}

		update_option( self::STATUS_OPTION, [ 'time' => time(), 'ok' => $result['ok'], 'message' => $result['message'] ], false );

		return $result;

	}

	public static function status(): array {

		$status = get_option( self::STATUS_OPTION, [] );

		return is_array( $status ) ? $status : [];

	}

	/*
	REQUEST
	-- One attempt. Validates the transport, the HTTP status and Cloudflare's
	-- own success flag, and turns any failure into a readable message
	---------------------------------------------------------- */

	protected static function request( string $method, string $path, ?array $body = null ): array {

		$args = [
			'method'      => $method,
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => [
				'Authorization' => 'Bearer ' . self::token(),
				'Content-Type'  => 'application/json',
			],
		];

		if ( null !== $body ) {

			$args['body'] = wp_json_encode( $body );

		}

		$response = wp_remote_request( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {

			return self::result( false, sprintf(
				/* translators: %s: transport error message. */
				__( 'Could not reach Cloudflare: %s', 'octave-addons' ),
				$response->get_error_message()
			) );

		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && is_array( $data ) && ! empty( $data['success'] ) ) {

			return self::result( true, '', $data );

		}

		$error = is_array( $data ) && ! empty( $data['errors'][0]['message'] ) ? (string) $data['errors'][0]['message'] : '';

		return self::result( false, sprintf(
			/* translators: 1: HTTP status code, 2: Cloudflare error message. */
			__( 'Cloudflare returned HTTP %1$d. %2$s', 'octave-addons' ),
			$code,
			'' !== $error ? $error : __( 'Check the Zone ID and that the token has Cache Purge permission for this zone.', 'octave-addons' )
		) );

	}

	protected static function result( bool $ok, string $message, array $data = [] ): array {

		return [ 'ok' => $ok, 'message' => $message, 'data' => $data ];

	}

}
