<?php

/*
PERFORMANCE: PAGE CACHE INTEGRATION
-- Octave is not a page cache. This connects Octave's purges to whichever
-- page cache the site has, and refills that cache in the background
-- Purges are passed on only for changes the page cache cannot see for
-- itself: a manual purge, a Breakdance design change, an Imagify delivery
-- change, a font change, or a page whose learned LCP image changed.
-- Ordinary post edits and updates are left to the cache plugin, which
-- already purges for them
-- Each connector is guarded by the function or class it calls, wrapped so
-- a failing cache API only marks its own layer as failed
-- Warming requests public URLs as a logged-out visitor, a few at a time
-- from cron, so a full purge never turns into a request storm. Cart,
-- checkout, account, preview, admin and nonce URLs, and pages marked
-- noindex, are never requested
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Page_Cache {

	public const WARM_OPTION = 'octave_addons_perf_warm';
	public const WARM_HOOK   = 'octave_addons_perf_warm';

	/** Header a warming request carries, so Octave can do its slow work then. */
	public const WARM_HEADER = 'X-Octave-Warm';

	/** URLs requested per cron run, and the pause before the next run. */
	public const BATCH = 5;
	public const GAP   = 30;

	/** Most URLs one warming cycle visits. */
	public const MAX_URLS = 50;

	/** Purge reasons the page cache cannot notice by itself. */
	protected const PURGE_ALL_REASONS = [ 'manual', 'breakdance', 'imagify', 'fonts' ];
	protected const PURGE_URL_REASONS = [ 'manual', 'lcp' ];

	/** Full purges after which the cache is refilled. */
	protected const WARM_REASONS = [ 'manual', 'breakdance', 'imagify', 'fonts', 'settings', 'update', 'environment' ];

	/*
	BOOT
	---------------------------------------------------------- */

	public static function boot(): void {

		add_filter( 'octave_addons_perf_purge_all_layers', [ __CLASS__, 'purge_all_layer' ], 20, 2 );
		add_filter( 'octave_addons_perf_purge_url_layers', [ __CLASS__, 'purge_url_layer' ], 20, 3 );
		add_action( 'octave_addons_perf_purged_all', [ __CLASS__, 'on_purged_all' ], 10, 3 );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'on_upgrade' ], 20, 2 );
		add_action( self::WARM_HOOK, [ __CLASS__, 'warm_batch' ] );

	}

	/*
	CONNECTORS
	-- Label => [ 'all' => callable|null, 'urls' => callable|null ] for every
	-- page cache whose API is present
	---------------------------------------------------------- */

	public static function connectors(): array {

		$connectors = [];

		if ( function_exists( 'rocket_clean_domain' ) ) {

			$connectors['WP Rocket'] = [
				'all'  => 'rocket_clean_domain',
				'urls' => function_exists( 'rocket_clean_files' ) ? 'rocket_clean_files' : null,
			];

		}

		if ( defined( 'LSCWP_V' ) ) {

			$connectors['LiteSpeed Cache'] = [
				'all'  => static function (): void {

					do_action( 'litespeed_purge_all' );

				},
				'urls' => static function ( array $urls ): void {

					foreach ( $urls as $url ) {

						do_action( 'litespeed_purge_url', $url );

					}

				},
			];

		}

		if ( defined( 'BREEZE_VERSION' ) ) {

			$connectors['Breeze'] = [
				'all'  => static function (): void {

					do_action( 'breeze_clear_all_cache' );

				},
				'urls' => null,
			];

		}

		if ( class_exists( 'FlyingPress\Purge' ) && method_exists( 'FlyingPress\Purge', 'purge_everything' ) ) {

			$connectors['FlyingPress'] = [
				'all'  => [ 'FlyingPress\Purge', 'purge_everything' ],
				'urls' => method_exists( 'FlyingPress\Purge', 'purge_url' ) ? static function ( array $urls ): void {

					foreach ( $urls as $url ) {

						call_user_func( [ 'FlyingPress\Purge', 'purge_url' ], $url );

					}

				} : null,
			];

		}

		if ( class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {

			$connectors['WP Engine'] = [ 'all' => [ 'WpeCommon', 'purge_varnish_cache' ], 'urls' => null ];

		}

		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {

			$connectors['SiteGround'] = [
				'all'  => 'sg_cachepress_purge_cache',
				'urls' => static function ( array $urls ): void {

					foreach ( $urls as $url ) {

						sg_cachepress_purge_cache( $url );

					}

				},
			];

		}

		if ( function_exists( 'pantheon_wp_clear_edge_all' ) ) {

			$connectors['Pantheon'] = [ 'all' => 'pantheon_wp_clear_edge_all', 'urls' => null ];

		}

		if ( defined( 'NGINX_HELPER_BASENAME' ) ) {

			$connectors['Nginx Helper'] = [
				'all'  => static function (): void {

					do_action( 'rt_nginx_helper_purge_all' );

				},
				'urls' => null,
			];

		}

		/**
		 * Filters the page caches Octave purges.
		 *
		 * @param array $connectors Label => [ 'all' => callable|null, 'urls' => callable|null ].
		 */
		return (array) apply_filters( 'octave_addons_perf_page_cache_connectors', $connectors );

	}

	/*
	PURGE LAYERS
	---------------------------------------------------------- */

	public static function purge_all_layer( $report, $reason ): array {

		$report = (array) $report;

		if ( ! in_array( (string) $reason, self::PURGE_ALL_REASONS, true ) ) {

			return $report;

		}

		foreach ( self::connectors() as $label => $connector ) {

			$report[ 'page-cache-' . sanitize_key( $label ) ] = self::call( $label, $connector['all'] ?? null, [] );

		}

		return $report;

	}

	public static function purge_url_layer( $report, $urls, $reason ): array {

		$report = (array) $report;

		if ( ! in_array( (string) $reason, self::PURGE_URL_REASONS, true ) ) {

			return $report;

		}

		foreach ( self::connectors() as $label => $connector ) {

			$report[ 'page-cache-' . sanitize_key( $label ) ] = self::call( $label, $connector['urls'] ?? null, [ (array) $urls ] );

		}

		return $report;

	}

	/*
	CALL
	-- Runs one cache API and reports the outcome as a purge layer
	---------------------------------------------------------- */

	protected static function call( string $label, $callback, array $args ): array {

		if ( ! is_callable( $callback ) ) {

			return [ 'label' => $label, 'status' => 'skipped', 'message' => __( 'This cache has no API for a targeted purge; it clears itself when content changes.', 'octave-addons' ) ];

		}

		try {

			call_user_func_array( $callback, $args );

		} catch ( \Throwable $error ) {

			Octave_Addons_Perf_Log::error( 'page-cache', $error->getMessage(), $label );

			return [ 'label' => $label, 'status' => 'error', 'message' => $error->getMessage() ];

		}

		return [ 'label' => $label, 'status' => 'success', 'message' => __( 'Purged.', 'octave-addons' ) ];

	}

	/*
	ON PURGED ALL
	-- Refills caches after a full purge, and after a minified-file purge
	-- caused by settings or plugin changes so new copies are generated by
	-- the warmer rather than by a visitor
	---------------------------------------------------------- */

	public static function on_purged_all( $report, $reason, $scope ): void {

		if ( in_array( (string) $reason, self::WARM_REASONS, true ) ) {

			self::queue_warm( (string) $reason );

		}

	}

	/*
	ON UPGRADE
	-- A plugin, theme or core update can change any asset, so Octave's own
	-- minified copies are cleared and the cache is refilled. Page caches
	-- clear themselves on updates, so they are not purged again here
	---------------------------------------------------------- */

	public static function on_upgrade( $upgrader = null, $extra = [] ): void {

		if ( in_array( (string) ( $extra['type'] ?? '' ), [ 'plugin', 'theme', 'core' ], true ) ) {

			Octave_Addons_Perf_Cache::purge_all( 'files', 'update' );

		}

	}

	/*
	SHOULD WARM
	-- Only worth it when a page cache will keep the result, or when File
	-- Optimization has minified copies to prepare
	---------------------------------------------------------- */

	public static function should_warm(): bool {

		$files = Octave_Addons_Perf::settings( 'performance-files' );

		$warm = '' !== Octave_Addons_Perf::handled_elsewhere( 'page_cache' )
			|| ( ! empty( $files['enabled'] ) && ( ! empty( $files['minify_css'] ) || ! empty( $files['minify_js'] ) ) );

		/**
		 * Filters whether Octave refills caches after a purge.
		 *
		 * @param bool $warm Whether to warm.
		 */
		return (bool) apply_filters( 'octave_addons_perf_warm_enabled', $warm );

	}

	/*
	QUEUE WARM
	-- Adds URLs to the queue, never twice, and books the next batch. A
	-- second purge while a cycle is running only extends that cycle
	---------------------------------------------------------- */

	public static function queue_warm( string $reason, ?array $urls = null ): void {

		if ( ! self::should_warm() ) {

			return;

		}

		$state = self::state();
		$urls  = null === $urls ? self::warm_urls() : Octave_Addons_Perf_Cache::normalize_urls( $urls );
		$queue = array_values( array_unique( array_merge( (array) ( $state['queue'] ?? [] ), $urls ) ) );

		if ( empty( $queue ) ) {

			return;

		}

		if ( empty( $state['queue'] ) ) {

			$state = [ 'reason' => $reason, 'started' => time(), 'done' => 0, 'failed' => [], 'log' => (array) ( $state['log'] ?? [] ) ];

		}

		$state['queue'] = array_slice( $queue, 0, self::MAX_URLS );

		update_option( self::WARM_OPTION, $state, false );

		if ( ! wp_next_scheduled( self::WARM_HOOK ) ) {

			wp_schedule_single_event( time() + self::GAP, self::WARM_HOOK );

		}

	}

	/*
	WARM URLS
	-- The home page and the most recently changed public pages and posts,
	-- minus anything personal, transactional or noindex
	---------------------------------------------------------- */

	public static function warm_urls(): array {

		$urls = [ home_url( '/' ) ];
		$ids  = get_posts( [
			'post_type'   => [ 'page', 'post' ],
			'post_status' => 'publish',
			'numberposts' => self::MAX_URLS,
			'orderby'     => 'modified',
			'fields'      => 'ids',
		] );

		foreach ( (array) $ids as $id ) {

			if ( self::is_noindex( (int) $id ) ) {

				continue;

			}

			$link = get_permalink( (int) $id );

			if ( is_string( $link ) && '' !== $link ) {

				$urls[] = $link;

			}

		}

		/**
		 * Filters the URLs warmed after a purge.
		 *
		 * @param string[] $urls Absolute URLs.
		 */
		$urls     = Octave_Addons_Perf_Cache::normalize_urls( (array) apply_filters( 'octave_addons_perf_warm_urls', $urls ) );
		$patterns = class_exists( 'Octave_Addons_Module_Performance_Preload' ) ? Octave_Addons_Module_Performance_Preload::exclusions( [] ) : [ '/wp-admin', 'nonce', 'preview' ];

		$urls = array_filter( $urls, static function ( string $url ) use ( $patterns ): bool {

			return '' === Octave_Addons_Perf::matches_any( $url, $patterns );

		} );

		return array_slice( array_values( $urls ), 0, self::MAX_URLS );

	}

	/*
	IS NOINDEX
	-- Yoast SEO and Rank Math store a page's noindex choice in post meta
	---------------------------------------------------------- */

	protected static function is_noindex( int $id ): bool {

		if ( '1' === (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ) ) {

			return true;

		}

		return in_array( 'noindex', (array) get_post_meta( $id, 'rank_math_robots', true ), true );

	}

	/*
	WARM BATCH
	-- Requests a few queued URLs one after another, records each outcome,
	-- and books the next batch while URLs remain
	---------------------------------------------------------- */

	public static function warm_batch(): array {

		if ( false !== get_transient( 'oa_perf_warm_lock' ) ) {

			return [];

		}

		set_transient( 'oa_perf_warm_lock', 1, 2 * MINUTE_IN_SECONDS );

		$state = self::state();
		$batch = array_splice( $state['queue'], 0, self::BATCH );

		foreach ( $batch as $url ) {

			$response = wp_remote_get( $url, [
				'timeout'     => 15,
				'redirection' => 2,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'cookies'     => [],
				'user-agent'  => 'Octave Addons cache warmer; ' . home_url( '/' ),
				'headers'     => [ self::WARM_HEADER => '1' ],
			] );

			$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

			if ( $code >= 200 && $code < 400 ) {

				$state['done'] = (int) ( $state['done'] ?? 0 ) + 1;

			} else {

				$state['failed'][ $url ] = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;

			}

		}

		$state['failed'] = array_slice( (array) ( $state['failed'] ?? [] ), -10, null, true );

		if ( empty( $state['queue'] ) ) {

			array_unshift( $state['log'], [
				'time'   => time(),
				'reason' => (string) ( $state['reason'] ?? '' ),
				'done'   => (int) ( $state['done'] ?? 0 ),
				'failed' => (array) $state['failed'],
			] );

			$state['log'] = array_slice( $state['log'], 0, 5 );

		} else {

			wp_schedule_single_event( time() + self::GAP, self::WARM_HOOK );

		}

		update_option( self::WARM_OPTION, $state, false );
		delete_transient( 'oa_perf_warm_lock' );

		return $state;

	}

	/*
	STATE
	-- The queue in progress and the log of finished cycles
	---------------------------------------------------------- */

	public static function state(): array {

		$state = get_option( self::WARM_OPTION, [] );
		$state = is_array( $state ) ? $state : [];

		return array_merge( [ 'queue' => [], 'failed' => [], 'log' => [], 'done' => 0 ], $state );

	}

	/*
	IS WARM REQUEST
	-- A request made by the warmer, cron, WP-CLI or a diagnostics scan,
	-- where slow one-off work such as minifying a file is acceptable. The
	-- header can be forged, which at worst does that work for a visitor
	---------------------------------------------------------- */

	public static function is_warm_request(): bool {

		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || Octave_Addons_Perf_Log::is_reporting() ) {

			return true;

		}

		return ! empty( $_SERVER['HTTP_X_OCTAVE_WARM'] );

	}

}
