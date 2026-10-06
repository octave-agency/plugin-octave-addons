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

		if ( 'all' === $scope ) {

			/**
			 * Lets cache layers purge everything and report back.
			 *
			 * Each layer adds [ 'label' => string, 'status' => success|error|skipped|queued, 'message' => string ].
			 *
			 * @param array  $report Per-layer results so far.
			 * @param string $reason manual, settings, theme, plugins or fonts.
			 */
			$report = (array) apply_filters( 'octave_addons_perf_purge_all_layers', $report, $reason );

		}

		do_action( 'octave_addons_perf_purged_all', $report, $reason, $scope );

		if ( 'manual' === $reason ) {

			self::record( 'all' === $scope ? __( 'Full purge', 'octave-addons' ) : __( 'Minified files', 'octave-addons' ), $report );

		}

		return $report;

	}

	/*
	PURGE URLS
	-- Targeted invalidation. No per-page HTML is kept, so the files layer
	-- has nothing URL-specific to clear; the URLs go to the other layers
	---------------------------------------------------------- */

	public static function purge_urls( array $urls, string $reason = 'content' ): array {

		/**
		 * Filters the URLs a targeted purge covers.
		 *
		 * @param string[] $urls   Absolute URLs.
		 * @param string   $reason content, menu, manual.
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
		 * @param string   $reason content, menu, manual.
		 */
		$report = (array) apply_filters( 'octave_addons_perf_purge_url_layers', $report, $urls, $reason );

		do_action( 'octave_addons_perf_purged_urls', $urls, $report, $reason );

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
	-- Content edits purge their own URLs. Theme and plugin switches clear
	-- the minified files, since they change which assets a page loads. Settings
	-- changes are handled where the option is saved
	---------------------------------------------------------- */

	public static function register_invalidation(): void {

		add_action( 'transition_post_status', [ __CLASS__, 'on_transition_post_status' ], 20, 3 );
		add_action( 'before_delete_post', [ __CLASS__, 'on_delete_post' ], 20, 1 );
		add_action( 'wp_update_nav_menu', [ __CLASS__, 'on_menu_update' ] );
		add_action( 'switch_theme', [ __CLASS__, 'on_environment_change' ] );
		add_action( 'activated_plugin', [ __CLASS__, 'on_environment_change' ] );
		add_action( 'deactivated_plugin', [ __CLASS__, 'on_environment_change' ] );
		add_action( 'update_option_' . OCTAVE_ADDONS_OPTION_KEY, [ __CLASS__, 'on_settings_update' ], 10, 2 );

	}

	public static function on_transition_post_status( $new_status, $old_status, $post ): void {

		if ( ! $post instanceof WP_Post || ( 'publish' !== $new_status && 'publish' !== $old_status ) ) {

			return;

		}

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {

			return;

		}

		self::purge_urls( self::post_urls( $post ), 'content' );

	}

	public static function on_delete_post( $post_id ): void {

		$post = get_post( $post_id );

		if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {

			self::purge_urls( self::post_urls( $post ), 'content' );

		}

	}

	public static function on_menu_update(): void {

		self::purge_urls( [ home_url( '/' ) ], 'menu' );

	}

	public static function on_environment_change(): void {

		self::purge_all( 'files', 'environment' );

	}

	/*
	ON SETTINGS UPDATE
	-- Clears the minified files when any Performance module's settings changed
	---------------------------------------------------------- */

	public static function on_settings_update( $old, $new ): void {

		$old = is_array( $old ) ? $old : [];
		$new = is_array( $new ) ? $new : [];

		foreach ( $new as $id => $settings ) {

			if ( 0 === strpos( (string) $id, 'performance-' ) && ( $old[ $id ] ?? null ) !== $settings ) {

				self::purge_all( 'files', 'settings' );

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
