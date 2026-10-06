<?php

/*
PERFORMANCE CACHE MANAGER
-- Owns the generated minified files and the cache generation number, and
-- coordinates purges across every connected cache layer. Each purge returns
-- a report with one entry per layer, so the admin can show exactly what
-- succeeded and what did not
-- It never calls wp_cache_flush() and never deletes anything outside its
-- own directory. Other layers join through filters:
--   octave_addons_perf_purge_all_layers  (array $report, string $reason)
--   octave_addons_perf_purge_url_layers  (array $report, array $urls, string $reason)
--   octave_addons_perf_purge_urls        (array $urls, string $reason)
-- and can react afterwards through the octave_addons_perf_purged_all and
-- octave_addons_perf_purged_urls actions
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Cache {

	public const GENERATION_OPTION = 'octave_addons_perf_generation';
	public const LAST_PURGE_OPTION = 'octave_addons_perf_last_purge';

	/** Sub-folder holding minified CSS and JavaScript. */
	public const MIN_DIR = 'min';

	/** Breakdance global options whose change restyles every page. */
	public const BREAKDANCE_GLOBALS = [
		'global_settings_json_string',
		'breakdance_classes_json_string',
		'oxy_selectors_json_string',
		'presets_json_string',
		'variables_json_string',
	];

	/** Breakdance documents shown on many pages: templates, headers, footers, global blocks and popups. */
	public const BREAKDANCE_SHARED_TYPES = [ 'breakdance_template', 'breakdance_header', 'breakdance_footer', 'breakdance_block', 'breakdance_popup' ];

	/** Shortest gap between two automatic full purges from Breakdance. */
	protected const BREAKDANCE_DEBOUNCE = MINUTE_IN_SECONDS;

	/** Full-purge reasons limited to one per BREAKDANCE_DEBOUNCE. */
	protected const DEBOUNCED_REASONS = [ 'breakdance' ];

	/** @var string Reason of a full purge waiting for the end of the request, or ''. */
	protected static string $queued_full = '';

	/** @var array<string, bool> URLs waiting for the targeted purge => whether to warm them. */
	protected static array $queued_urls = [];

	/** @var bool Whether the end-of-request purge is booked. */
	protected static bool $listening = false;

	public const LAST_CLEARS_OPTION = 'octave_addons_perf_last_clears';

	/*
	GENERATION
	-- Changes on every full purge. Part of every generated file's cache key,
	-- so bumping it retires every file at once
	---------------------------------------------------------- */

	public static function generation(): int {

		return max( 1, (int) get_option( self::GENERATION_OPTION, 1 ) );

	}

	public static function bump_generation(): int {

		$next = self::generation() + 1;

		update_option( self::GENERATION_OPTION, $next, true );

		return $next;

	}

	/*
	PURGE ALL
	-- $scope 'files' clears the minified files only. 'all' also asks every
	-- connected layer. $reason tells layers why, so Cloudflare can keep a
	-- purge-everything for explicit administrator requests
	---------------------------------------------------------- */

	public static function purge_all( string $scope = 'all', string $reason = 'manual' ): array {

		$report = [ 'files' => self::clear_files() ];

		// Design-wide changes also retire what was learned from the old design.
		if ( 'all' === $scope && in_array( $reason, [ 'breakdance', 'fonts', 'manual' ], true ) ) {

			Octave_Addons_Perf_Lcp::forget_all();

			if ( class_exists( 'Octave_Addons_Module_Performance_Fonts' ) ) {

				delete_option( Octave_Addons_Module_Performance_Fonts::DETECTED_OPTION );

			}

		}

		if ( 'all' === $scope ) {

			/**
			 * Lets cache layers purge everything and report back.
			 *
			 * Each layer adds [ 'label' => string, 'status' => success|error|skipped|queued, 'message' => string ].
			 *
			 * @param array  $report Per-layer results so far.
			 * @param string $reason manual, settings, environment, fonts, breakdance, menu, imagify or update.
			 */
			$report = (array) apply_filters( 'octave_addons_perf_purge_all_layers', $report, $reason );

		}

		do_action( 'octave_addons_perf_purged_all', $report, $reason, $scope );

		if ( 'all' === $scope ) {

			self::remember_clear( 'full', $reason, $report );

		}

		if ( 'manual' === $reason ) {

			self::record( 'all' === $scope ? __( 'Full purge', 'octave-addons' ) : __( 'Minified files', 'octave-addons' ), $report );

		}

		return $report;

	}

	/*
	PURGE URLS
	-- Targeted invalidation. Minified files are shared across pages, so the
	-- files layer has nothing URL-specific to clear; the URLs go to the page
	-- caches and Cloudflare
	---------------------------------------------------------- */

	public static function purge_urls( array $urls, string $reason = 'content' ): array {

		/**
		 * Filters the URLs a targeted purge covers.
		 *
		 * @param string[] $urls   Absolute URLs.
		 * @param string   $reason content, lcp, assets or manual.
		 */
		$urls = self::normalize_urls( (array) apply_filters( 'octave_addons_perf_purge_urls', $urls, $reason ) );

		if ( empty( $urls ) ) {

			return [];

		}

		$report = [
			'files' => [
				'label'   => __( 'Minified files', 'octave-addons' ),
				'status'  => 'skipped',
				'message' => __( 'Minified files are shared across pages, so there is nothing URL-specific to clear.', 'octave-addons' ),
			],
		];

		/**
		 * Lets cache layers purge specific URLs and report back.
		 *
		 * @param array    $report Per-layer results so far.
		 * @param string[] $urls   Absolute URLs.
		 * @param string   $reason content, lcp, assets or manual.
		 */
		$report = (array) apply_filters( 'octave_addons_perf_purge_url_layers', $report, $urls, $reason );

		do_action( 'octave_addons_perf_purged_urls', $urls, $report, $reason );

		self::remember_clear( 'targeted', $reason, $report, $urls );

		if ( 'manual' === $reason ) {

			self::record( __( 'URL purge', 'octave-addons' ), $report, $urls );

		}

		return $report;

	}

	/*
	CLEAR FILES
	-- Removes generated minified files and moves the generation on. Self-hosted
	-- fonts are kept: they have their own refresh, and dropping them would send
	-- visitors back to Google until the next download
	---------------------------------------------------------- */

	protected static function clear_files(): array {

		$deleted = Octave_Addons_Perf_Store::delete( self::MIN_DIR );

		self::bump_generation();

		return [
			'label'   => __( 'Minified files', 'octave-addons' ),
			'status'  => 'success',
			'message' => sprintf(
				/* translators: 1: number of files, 2: human readable size. */
				__( 'Cleared %1$d generated files (%2$s).', 'octave-addons' ),
				$deleted['files'],
				size_format( $deleted['bytes'] ) ?: '0 B'
			),
		];

	}

	/*
	RECORD
	-- Keeps the last manual purge for the status panel
	---------------------------------------------------------- */

	protected static function record( string $label, array $report, array $urls = [] ): void {

		update_option( self::LAST_PURGE_OPTION, [
			'time'   => time(),
			'label'  => $label,
			'urls'   => array_slice( $urls, 0, 20 ),
			'report' => $report,
		], false );

	}

	/*
	REMEMBER CLEAR
	-- The last full and the last targeted clear, whatever caused them, with
	-- any layer that failed, for the Performance page
	---------------------------------------------------------- */

	protected static function remember_clear( string $kind, string $reason, array $report, array $urls = [] ): void {

		$failed = [];

		foreach ( $report as $layer ) {

			if ( 'error' === ( $layer['status'] ?? '' ) ) {

				$failed[] = (string) ( $layer['label'] ?? '' ) . ': ' . (string) ( $layer['message'] ?? '' );

			}

		}

		$clears          = self::last_clears();
		$clears[ $kind ] = [
			'time'   => time(),
			'reason' => $reason,
			'urls'   => array_slice( $urls, 0, 10 ),
			'count'  => count( $urls ),
			'failed' => $failed,
		];

		update_option( self::LAST_CLEARS_OPTION, $clears, false );

	}

	public static function last_clears(): array {

		$clears = get_option( self::LAST_CLEARS_OPTION, [] );

		return is_array( $clears ) ? $clears : [];

	}

	/*
	RESET
	-- Forgets anything queued for the end of the request
	---------------------------------------------------------- */

	public static function reset(): void {

		self::$queued_full = '';
		self::$queued_urls = [];
		self::$listening   = false;

	}

	public static function last_purge(): array {

		$last = get_option( self::LAST_PURGE_OPTION, [] );

		return is_array( $last ) ? $last : [];

	}

	/*
	NORMALIZE URLS
	-- Absolute http(s) URLs on this site, unique, without fragments
	---------------------------------------------------------- */

	public static function normalize_urls( array $urls ): array {

		$clean = [];

		foreach ( $urls as $url ) {

			$url = esc_url_raw( trim( (string) $url ), [ 'http', 'https' ] );
			$url = preg_replace( '/#.*$/', '', $url );

			if ( '' === $url || ! Octave_Addons_Perf::is_same_origin( $url ) ) {

				continue;

			}

			$clean[ $url ] = true;

		}

		return array_keys( $clean );

	}

	/*
	REGISTER INVALIDATION
	-- Content, term and menu edits purge the pages they appear on. Theme
	-- and plugin switches and Performance settings changes clear everything,
	-- since they change what every page loads
	-- Nothing is purged while a save is still running: URLs are collected
	-- and purged once, when the request ends. Breakdance saves a page with
	-- wp_update_post() before it writes the page's CSS, so purging any
	-- earlier would let a cache refill with the old styles
	---------------------------------------------------------- */

	public static function register_invalidation(): void {

		add_action( 'wp_after_insert_post', [ __CLASS__, 'on_after_insert_post' ], 20, 4 );
		add_action( 'before_delete_post', [ __CLASS__, 'on_delete_post' ], 20, 1 );
		add_action( 'edited_term', [ __CLASS__, 'on_term_change' ], 20, 3 );
		add_action( 'pre_delete_term', [ __CLASS__, 'on_term_delete' ], 20, 2 );
		add_action( 'wp_update_nav_menu', [ __CLASS__, 'on_menu_update' ] );
		add_action( 'switch_theme', [ __CLASS__, 'on_environment_change' ] );
		add_action( 'activated_plugin', [ __CLASS__, 'on_environment_change' ] );
		add_action( 'deactivated_plugin', [ __CLASS__, 'on_environment_change' ] );
		add_action( 'update_option_' . OCTAVE_ADDONS_OPTION_KEY, [ __CLASS__, 'on_settings_update' ], 10, 2 );

		// Breakdance: its documents, its global design data, its custom fonts
		// and its own cache regeneration. Breakdance's code is never changed.
		add_action( 'breakdance_after_save_document', [ __CLASS__, 'on_breakdance_document' ] );

		foreach ( self::BREAKDANCE_GLOBALS as $field ) {

			add_action( 'breakdance_option_updated_' . $field, [ __CLASS__, 'on_breakdance_global' ] );

		}

		add_action( 'wp_ajax_breakdance_save_font_families', [ __CLASS__, 'on_breakdance_global' ], 1 );
		add_action( 'wp_ajax_breakdance_regenerate_global_settings_cache', [ __CLASS__, 'on_breakdance_global' ], 1 );

	}

	/*
	ON BREAKDANCE DOCUMENT
	-- A template, header, footer, global block or popup can appear on any
	-- page, so it purges everything, at most once a minute. An ordinary
	-- page has just had its CSS written: its own URLs join the purge that
	-- runs when the save request ends, and its learned LCP record is
	-- retired so the next view relearns it
	---------------------------------------------------------- */

	public static function on_breakdance_document( $post_id ): void {

		$type = (string) get_post_type( (int) $post_id );

		if ( in_array( $type, self::BREAKDANCE_SHARED_TYPES, true ) || in_array( str_replace( 'oxygen_', 'breakdance_', $type ), self::BREAKDANCE_SHARED_TYPES, true ) ) {

			self::queue_full_purge( 'breakdance' );

			return;

		}

		Octave_Addons_Perf_Lcp::forget_post( (int) $post_id );

		$post = get_post( (int) $post_id );

		if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {

			self::queue_urls( self::post_urls( $post ) );

		}

	}

	/*
	ON BREAKDANCE GLOBAL
	-- Global settings, classes, selectors, presets, variables and fonts
	-- restyle every page. The AJAX hooks fire before Breakdance checks
	-- permissions, so only users who could make the change count
	---------------------------------------------------------- */

	public static function on_breakdance_global(): void {

		if ( wp_doing_ajax() && ! current_user_can( 'edit_posts' ) ) {

			return;

		}

		self::queue_full_purge( 'breakdance' );

	}

	/*
	QUEUE FULL PURGE
	-- One save can update several global options. They become a single full
	-- purge once the request has finished. Breakdance's purges also run at
	-- most once a minute, so a burst of builder saves clears caches once
	---------------------------------------------------------- */

	public static function queue_full_purge( string $reason ): void {

		self::listen_for_shutdown();

		// A reason that is never skipped wins over one that may be debounced away.
		if ( '' === self::$queued_full || in_array( self::$queued_full, self::DEBOUNCED_REASONS, true ) ) {

			self::$queued_full = $reason;

		}

	}

	/*
	QUEUE URLS
	-- Collects URLs for the targeted purge at the end of the request. $warm
	-- false marks URLs that no longer show the content, such as an
	-- unpublished post: they are purged but not refilled
	---------------------------------------------------------- */

	public static function queue_urls( array $urls, bool $warm = true ): void {

		foreach ( $urls as $url ) {

			$url = (string) $url;

			if ( '' === $url ) {

				continue;

			}

			self::$queued_urls[ $url ] = $warm || ! empty( self::$queued_urls[ $url ] );

		}

		self::listen_for_shutdown();

	}

	protected static function listen_for_shutdown(): void {

		if ( ! self::$listening ) {

			self::$listening = true;

			add_action( 'shutdown', [ __CLASS__, 'run_queued' ], 5 );

		}

	}

	/*
	RUN QUEUED
	-- The full purge if one was asked for, otherwise, or when a debounced
	-- full purge was skipped, one targeted purge for every collected URL,
	-- which are then queued for warming so no visitor waits on them
	---------------------------------------------------------- */

	public static function run_queued(): array {

		self::$listening = false;

		$report = self::run_queued_full_purge();

		$urls              = self::$queued_urls;
		self::$queued_urls = [];

		if ( ! empty( $report ) || empty( $urls ) ) {

			return $report;

		}

		$report = self::purge_urls( array_keys( $urls ), 'content' );

		Octave_Addons_Perf_Page_Cache::queue_warm( 'content', array_keys( array_filter( $urls ) ) );

		return $report;

	}

	public static function run_queued_full_purge(): array {

		$reason            = self::$queued_full;
		self::$queued_full = '';

		if ( '' === $reason ) {

			return [];

		}

		if ( in_array( $reason, self::DEBOUNCED_REASONS, true ) ) {

			if ( false !== get_transient( 'oa_perf_full_purge_' . $reason ) ) {

				return [];

			}

			set_transient( 'oa_perf_full_purge_' . $reason, 1, self::BREAKDANCE_DEBOUNCE );

		}

		return self::purge_all( 'all', $reason );

	}

	/*
	ON AFTER INSERT POST
	-- Runs once a post, its terms and its meta are saved. A published post
	-- purges where it appears now; a post leaving publish, trash included,
	-- purges where it used to appear, so no cache keeps showing it. A
	-- published post whose address changed purges its old address too.
	-- Revisions, autosaves, auto-drafts and drafts that stay drafts are ignored
	---------------------------------------------------------- */

	public static function on_after_insert_post( $post_id, $post = null, $update = false, $post_before = null ): void {

		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || 'auto-draft' === $post->post_status ) {

			return;

		}

		$was_public = $post_before instanceof WP_Post && 'publish' === $post_before->post_status;

		if ( 'publish' === $post->post_status ) {

			self::queue_urls( self::post_urls( $post ) );

			if ( $was_public ) {

				$old = get_permalink( $post_before );
				$new = get_permalink( $post );

				if ( is_string( $old ) && $old !== $new ) {

					self::queue_urls( [ $old ], false );

				}

			}

			return;

		}

		if ( $was_public ) {

			$urls = self::post_urls( $post_before );
			$gone = get_permalink( $post_before );

			self::queue_urls( array_diff( $urls, [ $gone ] ) );
			self::queue_urls( [ $gone ], false );

		}

	}

	public static function on_delete_post( $post_id ): void {

		$post = get_post( $post_id );

		if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {

			$gone = get_permalink( $post );

			self::queue_urls( array_diff( self::post_urls( $post ), [ $gone ] ) );
			self::queue_urls( [ $gone ], false );

		}

	}

	/*
	ON TERM CHANGE
	-- A renamed or edited public term changes its archive and the home page
	---------------------------------------------------------- */

	public static function on_term_change( $term_id, $tt_id = 0, $taxonomy = '' ): void {

		$urls = self::term_urls( (int) $term_id, (string) $taxonomy );

		if ( ! empty( $urls ) ) {

			self::queue_urls( array_merge( [ home_url( '/' ) ], $urls ) );

		}

	}

	/*
	ON TERM DELETE
	-- Read before the term goes, while its archive address still exists
	---------------------------------------------------------- */

	public static function on_term_delete( $term_id, $taxonomy = '' ): void {

		$urls = self::term_urls( (int) $term_id, (string) $taxonomy );

		if ( ! empty( $urls ) ) {

			self::queue_urls( [ home_url( '/' ) ] );
			self::queue_urls( $urls, false );

		}

	}

	protected static function term_urls( int $term_id, string $taxonomy ): array {

		$object = '' !== $taxonomy ? get_taxonomy( $taxonomy ) : false;

		if ( ! $object || empty( $object->public ) ) {

			return [];

		}

		$link = get_term_link( $term_id, $taxonomy );

		return is_string( $link ) ? [ $link ] : [];

	}

	/*
	ON MENU UPDATE
	-- Menus print in headers and footers on every page, so every page is
	-- affected. One full purge when the request ends, however many menus
	-- the save touched
	---------------------------------------------------------- */

	public static function on_menu_update(): void {

		self::queue_full_purge( 'menu' );

	}

	public static function on_environment_change(): void {

		self::queue_full_purge( 'environment' );

	}

	/*
	ON SETTINGS UPDATE
	-- Clears everything when any Performance module's settings changed:
	-- cached pages hold the markup the old settings produced
	---------------------------------------------------------- */

	public static function on_settings_update( $old, $new ): void {

		$old = is_array( $old ) ? $old : [];
		$new = is_array( $new ) ? $new : [];

		foreach ( $new as $id => $settings ) {

			if ( 0 === strpos( (string) $id, 'performance-' ) && ( $old[ $id ] ?? null ) !== $settings ) {

				self::queue_full_purge( 'settings' );

				return;

			}

		}

	}

	/*
	POST URLS
	-- The pages a published post appears on: itself, the home page, the posts
	-- page, its archive and its public terms. Breakdance templates, headers and
	-- footers have no URL of their own, so they fall back to the home page
	---------------------------------------------------------- */

	public static function post_urls( WP_Post $post ): array {

		$urls = [ home_url( '/' ) ];

		if ( is_post_type_viewable( $post->post_type ) ) {

			$urls[] = get_permalink( $post );

			$archive = get_post_type_archive_link( $post->post_type );

			if ( $archive ) {

				$urls[] = $archive;

			}

			if ( 'post' === $post->post_type && get_option( 'page_for_posts' ) ) {

				$urls[] = get_permalink( (int) get_option( 'page_for_posts' ) );

			}

			foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {

				if ( empty( $taxonomy->public ) ) {

					continue;

				}

				$terms = get_the_terms( $post, $taxonomy->name );

				foreach ( is_array( $terms ) ? $terms : [] as $term ) {

					$link = get_term_link( $term );

					if ( is_string( $link ) ) {

						$urls[] = $link;

					}

				}

			}

		}

		/**
		 * Filters the URLs purged when a post changes.
		 *
		 * @param string[] $urls URLs to purge.
		 * @param WP_Post  $post The changed post.
		 */
		return (array) apply_filters( 'octave_addons_perf_post_purge_urls', array_filter( $urls ), $post );

	}

}
