<?php

/*
PERFORMANCE LOG
-- Records failures for administrators without touching the frontend: a
-- capped, non-autoloaded list of recent errors, each written at most once a
-- day so a failing file does not cause a database write on every request
-- Also collects the diagnostics report for an administrator's page scan
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Log {

	public const OPTION = 'octave_addons_perf_log';

	/** Query argument carrying a one-time diagnostics scan token. */
	public const SCAN_ARG = 'oa_perf_scan';

	protected const MAX = 50;

	/** @var array|null Notes gathered while a scan request renders. */
	protected static ?array $report = null;

	protected static string $token = '';

	/*
	ERROR
	-- Stores one failure. Repeats of the same failure within a day only move
	-- its timestamp in memory, never into the database
	---------------------------------------------------------- */

	public static function error( string $feature, string $message, string $context = '' ): void {

		$entries = self::entries();
		$key     = md5( $feature . '|' . $message . '|' . $context );

		foreach ( $entries as $entry ) {

			if ( ( $entry['key'] ?? '' ) === $key && ( time() - (int) ( $entry['time'] ?? 0 ) ) < DAY_IN_SECONDS ) {

				return;

			}

		}

		array_unshift( $entries, [
			'key'     => $key,
			'time'    => time(),
			'feature' => sanitize_key( $feature ),
			'message' => wp_strip_all_tags( $message ),
			'context' => wp_strip_all_tags( $context ),
		] );

		update_option( self::OPTION, array_slice( $entries, 0, self::MAX ), false );

	}

	public static function entries(): array {

		$entries = get_option( self::OPTION, [] );

		return is_array( $entries ) ? $entries : [];

	}

	public static function clear(): void {

		delete_option( self::OPTION );

	}

	/*
	START SCAN
	-- Creates the token an administrator's loopback scan carries. The report
	-- is stored against it and can only be read back by the admin endpoint
	---------------------------------------------------------- */

	public static function start_scan(): string {

		$token = wp_generate_password( 24, false, false );

		set_transient( 'oa_perf_scan_' . $token, [ 'status' => 'pending' ], 5 * MINUTE_IN_SECONDS );

		return $token;

	}

	/*
	MAYBE BEGIN REPORT
	-- Called early on frontend requests. Only a valid pending token opens a
	-- report, so a guessed or replayed token records nothing
	---------------------------------------------------------- */

	public static function maybe_begin_report(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token-checked diagnostic request.
		$token = isset( $_GET[ self::SCAN_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::SCAN_ARG ] ) ) : '';

		if ( '' === $token ) {

			return;

		}

		$stored = get_transient( 'oa_perf_scan_' . $token );

		if ( ! is_array( $stored ) || 'pending' !== ( $stored['status'] ?? '' ) ) {

			return;

		}

		self::$token  = $token;
		self::$report = [];

	}

	public static function is_reporting(): bool {

		return null !== self::$report;

	}

	/*
	NOTE
	-- Adds one decision to the open report, if any
	---------------------------------------------------------- */

	public static function note( string $feature, array $item ): void {

		if ( null === self::$report ) {

			return;

		}

		self::$report[ $feature ][] = $item;

	}

	/*
	SAVE REPORT
	-- Stores the finished report once the page has been processed
	---------------------------------------------------------- */

	public static function save_report( string $bypass_reason = '' ): void {

		if ( null === self::$report || '' === self::$token ) {

			return;

		}

		set_transient( 'oa_perf_scan_' . self::$token, [
			'status' => 'done',
			'bypass' => $bypass_reason,
			'report' => self::$report,
		], 5 * MINUTE_IN_SECONDS );

		self::$report = null;

	}

	/*
	READ SCAN
	-- Returns and forgets a finished report
	---------------------------------------------------------- */

	public static function read_scan( string $token ): array {

		$key    = 'oa_perf_scan_' . sanitize_key( $token );
		$stored = get_transient( $key );

		if ( is_array( $stored ) && 'done' === ( $stored['status'] ?? '' ) ) {

			delete_transient( $key );

			return $stored;

		}

		return [];

	}

}
