<?php

/*
PERFORMANCE: BREAKDANCE SESSIONS
-- Breakdance counts page views and sessions for its "Page View Count" and
-- "Session Count" conditions. To do it, every front-end view starts a PHP
-- session and sets three cookies, and a response that starts a session or
-- sets cookies cannot be kept by a page cache
-- While Performance is on, this switches that counting off through
-- Breakdance's own breakdance_disable_track_view_and_session_counts filter,
-- but only once a scan of Breakdance's saved documents and global data has
-- confirmed that neither condition is used anywhere. If one is used, or the
-- scan cannot be sure, counting stays on and the Performance page warns
-- that full-page caching cannot work safely
-- The scan matches the rule slugs Breakdance stores ("ruleSlug":
-- "page_views" / "session_count") in published documents, templates,
-- headers, footers, global blocks and popups, and in Breakdance's options.
-- Its answer is kept and thrown away whenever Breakdance saves, so a newly
-- added condition is seen straight away
-- The builder and the admin keep counting's conditions available, so an
-- editor can still choose them; doing so turns counting back on
-- Headers and cookies set by anything else are never touched
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Sessions {

	public const OPTION = 'octave_addons_perf_bd_sessions';

	/** Breakdance's condition slugs that read the counts. */
	public const SLUGS = [ 'page_views', 'session_count' ];

	/** Post meta holding Breakdance documents and template conditions. */
	public const META_KEYS = [ '_breakdance_data', '_breakdance_template_settings', '_oxygen_data', '_oxygen_template_settings' ];

	/** The scan is repeated after this long even when nothing was saved. */
	public const TTL = DAY_IN_SECONDS;

	/** Most candidate rows inspected; more than this cannot be confirmed safely. */
	protected const MAX_ROWS = 200;

	/*
	BOOT
	---------------------------------------------------------- */

	public static function boot(): void {

		if ( ! Octave_Addons_Perf::is_enabled() ) {

			return;

		}

		add_filter( 'breakdance_disable_track_view_and_session_counts', [ __CLASS__, 'filter_disable' ], 20 );

		add_action( 'breakdance_after_save_document', [ __CLASS__, 'forget' ] );
		add_action( 'trashed_post', [ __CLASS__, 'forget' ] );
		add_action( 'deleted_post', [ __CLASS__, 'forget' ] );
		add_action( 'wp_ajax_breakdance_regenerate_global_settings_cache', [ __CLASS__, 'forget' ], 1 );

		foreach ( Octave_Addons_Perf_Cache::BREAKDANCE_GLOBALS as $field ) {

			add_action( 'breakdance_option_updated_' . $field, [ __CLASS__, 'forget' ] );

		}

	}

	/*
	FILTER DISABLE
	-- Front-end page views only, and only when the scan found no condition
	-- reading the counts. Another plugin turning counting off is kept
	---------------------------------------------------------- */

	public static function filter_disable( $disabled ) {

		if ( $disabled ) {

			return $disabled;

		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || Octave_Addons_Module::is_builder_request() ) {

			return $disabled;

		}

		return 'unused' === self::state()['state'];

	}

	/*
	STATE
	-- [ 'state' => unused|used|unknown, 'where' => post ids or option names,
	-- 'time' => int ]. Scanned when missing or older than a day
	---------------------------------------------------------- */

	public static function state(): array {

		$saved = get_option( self::OPTION, [] );

		if ( is_array( $saved ) && isset( $saved['state'] ) && (int) ( $saved['time'] ?? 0 ) > time() - self::TTL ) {

			return $saved;

		}

		$state = self::scan();

		update_option( self::OPTION, $state, true );

		return $state;

	}

	public static function forget(): void {

		delete_option( self::OPTION );

	}

	/*
	SCAN
	-- A LIKE search finds rows that mention a slug; each is then checked for
	-- the slug as a rule, so a page that merely mentions "page_views" in its
	-- text does not count. A database error, or too many candidates to check,
	-- answers unknown
	---------------------------------------------------------- */

	public static function scan(): array {

		global $wpdb;

		$unknown = [ 'state' => 'unknown', 'where' => [], 'time' => time() ];

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {

			return $unknown;

		}

		$likes = [];

		foreach ( self::SLUGS as $slug ) {

			$likes[] = '%' . $wpdb->esc_like( $slug ) . '%';

		}

		$keys = implode( ',', array_fill( 0, count( self::META_KEYS ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
		$meta = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID AS id, m.meta_value AS value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key IN ($keys) AND p.post_status IN ('publish','private','future') AND ( m.meta_value LIKE %s OR m.meta_value LIKE %s ) LIMIT %d",
			array_merge( self::META_KEYS, $likes, [ self::MAX_ROWS + 1 ] )
		), ARRAY_A );

		$error = (string) $wpdb->last_error;

		$options = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name AS id, option_value AS value FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND ( option_value LIKE %s OR option_value LIKE %s ) LIMIT %d",
			$wpdb->esc_like( 'breakdance_' ) . '%',
			$wpdb->esc_like( 'oxygen_' ) . '%',
			$likes[0],
			$likes[1],
			self::MAX_ROWS + 1
		), ARRAY_A );
		// phpcs:enable

		$error .= (string) $wpdb->last_error;

		if ( '' !== $error || ! is_array( $meta ) || ! is_array( $options ) || count( $meta ) > self::MAX_ROWS || count( $options ) > self::MAX_ROWS ) {

			return $unknown;

		}

		$where = [];

		foreach ( array_merge( $meta, $options ) as $row ) {

			if ( self::uses_condition( (string) ( $row['value'] ?? '' ) ) ) {

				$where[] = (string) $row['id'];

			}

		}

		return [ 'state' => empty( $where ) ? 'unused' : 'used', 'where' => array_values( array_unique( $where ) ), 'time' => time() ];

	}

	/*
	USES CONDITION
	-- Whether stored Breakdance data holds a rule for either slug. Documents
	-- store their tree as JSON inside JSON, so quotes may be escaped once or
	-- more
	---------------------------------------------------------- */

	public static function uses_condition( string $value ): bool {

		return (bool) preg_match( '/ruleSlug\\\\*"\s*:\s*\\\\*"(?:page_views|session_count)\\\\*"/', $value );

	}

	/*
	CACHE BLOCKERS
	-- Readable reasons a response cannot be kept by a page cache: a PHP
	-- session, cookies, or Cache-Control asking caches not to store it.
	-- Cookie names only, never their values
	---------------------------------------------------------- */

	public static function cache_blockers( array $set_cookies, string $cache_control ): array {

		$reasons = [];
		$names   = [];

		foreach ( $set_cookies as $cookie ) {

			$name = trim( (string) strtok( (string) $cookie, '=' ) );

			if ( '' !== $name ) {

				$names[ $name ] = true;

			}

		}

		if ( isset( $names[ (string) ini_get( 'session.name' ) ] ) || isset( $names['PHPSESSID'] ) ) {

			$reasons[] = __( 'Every visitor is tracked with a session, so pages cannot be saved for quick loading.', 'octave-addons' );

		}

		if ( isset( $names['breakdance_view_count'] ) || isset( $names['breakdance_session_count'] ) ) {

			$reasons[] = __( 'Breakdance is counting each visitor\'s page views and visits.', 'octave-addons' );

		}

		if ( ! empty( $names ) ) {

			/* translators: %s: cookie names. */
			$reasons[] = sprintf( __( 'Cookies the page sets: %s', 'octave-addons' ), implode( ', ', array_keys( $names ) ) );

		}

		if ( preg_match( '/\b(no-store|private|no-cache)\b/i', $cache_control ) ) {

			/* translators: %s: Cache-Control header value. */
			$reasons[] = sprintf( __( 'The page asks not to be saved: %s', 'octave-addons' ), $cache_control );

		}

		return $reasons;

	}

	/*
	WARNING
	-- The Performance page's warning, or '' when there is nothing to say
	---------------------------------------------------------- */

	public static function warning(): string {

		if ( ! class_exists( 'Octave_Addons' ) || ! Octave_Addons::is_breakdance_active() || ! Octave_Addons_Perf::is_enabled() ) {

			return '';

		}

		return self::message( self::state() );

	}

	public static function message( array $state ): string {

		if ( 'used' === ( $state['state'] ?? '' ) ) {

			/* translators: %s: post IDs or option names. */
			return sprintf( __( 'Pages cannot be saved for quick loading: a Breakdance design shows something based on how many pages or visits someone has made (%s). To do that Breakdance tracks every visitor, which stops pages being saved. Remove those Page View Count or Session Count conditions to fix this.', 'octave-addons' ), implode( ', ', array_slice( (array) ( $state['where'] ?? [] ), 0, 5 ) ) );

		}

		if ( 'unknown' === ( $state['state'] ?? '' ) ) {

			return __( 'Pages may not be saved for quick loading: Octave could not confirm whether any Breakdance design relies on visit counts, so Breakdance keeps tracking every visitor. Octave checks again the next time you save in Breakdance.', 'octave-addons' );

		}

		return '';

	}

}
