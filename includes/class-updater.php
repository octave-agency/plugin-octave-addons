<?php

/*
EXTERNAL UPDATE CHECKER
-- Hosts the plugin anywhere that can serve two things:
-- 1. A JSON manifest (the "info" endpoint) — see /update.json.example.
-- 2. A zip archive of the plugin folder.
-- How the "zip date" trigger works
-- --------------------------------
-- The manifest has a `last_updated` field (ISO-8601 date string). The
-- updater stores the value we last installed in a WP option. On every
-- check it fetches the manifest, and if `last_updated` is newer than the
-- stored value, it registers an update with WordPress.
-- That means you don't even have to bump the plugin version to trigger an
-- update — just replace the zip and update `last_updated`. (Version is
-- still bumped automatically to satisfy WP's internal comparison.)
-- Manifest format
-- ---------------
-- {
-- "name":          "Octave Addons",
-- "slug":          "octave-addons",
-- "version":       "1.0.1",
-- "last_updated":  "2026-04-23 10:00:00",
-- "download_url":  "https://.../octave-addons.zip",
-- "requires":      "5.8",
-- "tested":        "6.5",
-- "requires_php":  "7.4",
-- "author":        "Octave Agency",
-- "homepage":      "https://octaveagency.com",
-- "sections": {
-- "description": "A modular collection of Octave site add-ons.",
-- "changelog":   "= 1.0.1 =\n* Bug fixes.\n"
-- }
-- }
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {
	exit;

}

class Octave_Addons_Updater {


	protected string $plugin_file;
	protected string $plugin_basename;
	protected string $plugin_slug;
	protected string $current_version;
	protected string $manifest_url;

	/** @var array|null In-memory cache of the fetched manifest. */
	protected ?array $manifest = null;

	/** Cache manifest fetches for this many seconds. */
	protected int $cache_ttl = 6 * HOUR_IN_SECONDS;

	/** Temp path where custom elements are backed up before an update overwrites the plugin folder. */
	protected ?string $custom_backup_path = null;

	public function __construct( string $plugin_file, string $manifest_url, string $current_version ) {

		$this->plugin_file     = $plugin_file;
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->plugin_slug     = dirname( $this->plugin_basename ); // "octave-addons"
		$this->manifest_url    = $manifest_url;
		$this->current_version = $current_version;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api',                          [ $this, 'plugins_api' ], 10, 3 );
		add_filter( 'upgrader_pre_install',                 [ $this, 'before_update' ], 10, 2 );
		add_action( 'upgrader_process_complete',            [ $this, 'after_update' ], 10, 2 );

		// Admin action to force a fresh check: /wp-admin/?octave_addons_check_update=1
		add_action( 'admin_init', [ $this, 'maybe_force_check' ] );

	}

	// ---------------------------------------------------------------------
	// Core update injection
	// ---------------------------------------------------------------------

	public function inject_update( $transient ) {

		if ( empty( $transient->checked ) ) {
			return $transient;

		}

		$manifest = $this->fetch_manifest();
		if ( ! $manifest ) {

			return $transient;

		}

		$remote_version = $this->effective_version( $manifest );

		if ( version_compare( $remote_version, $this->current_version, '>' ) ) {

			$transient->response[ $this->plugin_basename ] = (object) [
				'id'            => $this->plugin_basename,
				'slug'          => $this->plugin_slug,
				'plugin'        => $this->plugin_basename,
				'new_version'   => $remote_version,
				'url'           => $manifest['homepage']    ?? '',
				'package'       => $manifest['download_url'] ?? '',
				'tested'        => $manifest['tested']       ?? '',
				'requires'      => $manifest['requires']     ?? '',
				'requires_php'  => $manifest['requires_php'] ?? '',
			];

		} else {
			// Make sure we appear in the "no update" list so WP caches the check.
			$transient->no_update[ $this->plugin_basename ] = (object) [
				'id'            => $this->plugin_basename,
				'slug'          => $this->plugin_slug,
				'plugin'        => $this->plugin_basename,
				'new_version'   => $this->current_version,
				'url'           => $manifest['homepage'] ?? '',
				'package'       => '',
				'requires'      => $manifest['requires']     ?? '',
				'requires_php'  => $manifest['requires_php'] ?? '',
			];

		}

		return $transient;

	}

	/**
	 * Provide info for the "View details" modal in wp-admin → Plugins.
	 */
	public function plugins_api( $result, string $action, $args ) {

		if ( 'plugin_information' !== $action ) {

			return $result;

		}
		if ( empty( $args->slug ) || $args->slug !== $this->plugin_slug ) {

			return $result;

		}

		$manifest = $this->fetch_manifest();
		if ( ! $manifest ) {
			return $result;

		}

		return (object) [
			'name'          => $manifest['name']          ?? 'Octave Addons',
			'slug'          => $this->plugin_slug,
			'version'       => $this->effective_version( $manifest ),
			'author'        => $manifest['author']        ?? '',
			'homepage'      => $manifest['homepage']      ?? '',
			'requires'      => $manifest['requires']      ?? '',
			'tested'        => $manifest['tested']        ?? '',
			'requires_php'  => $manifest['requires_php']  ?? '',
			'last_updated'  => $manifest['last_updated']  ?? '',
			'download_link' => $manifest['download_url']  ?? '',
			'sections'      => (array) ( $manifest['sections'] ?? [
				'description' => $manifest['description'] ?? '',
			] ),
		];

	}

	/**
	 * Back up the custom elements folder before WordPress deletes the plugin directory.
	 *
	 * Fires on the `upgrader_pre_install` filter, which runs before the destination
	 * is cleared. The backup is restored in after_update().
	 *
	 * @param mixed $response Pass-through filter value.
	 * @param array $hook_extra Upgrader context.
	 * @return mixed Unchanged $response.
	 */
	public function before_update( $response, array $hook_extra ) {

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {

			return $response;

		}

		$custom_dir = WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/modules/breakdance-custom-elements/elements';
		if ( ! is_dir( $custom_dir ) ) {
			return $response;

		}

		$backup = get_temp_dir() . 'octave_custom_elements_' . time();
		if ( $this->recursive_copy( $custom_dir, $backup ) ) {

			$this->custom_backup_path = $backup;

		}

		return $response;

	}

	/**
	 * Record the remote last_updated value so we can detect future changes.
	 * Also restores custom elements backed up by before_update().
	 */
	public function after_update( $upgrader, array $hook_extra ): void {

		// WordPress passes 'plugin' (string) for single updates and 'plugins' (array) for bulk/auto updates.
		$is_this_plugin = (
			( ! empty( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->plugin_basename )
			|| ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) && in_array( $this->plugin_basename, $hook_extra['plugins'], true ) )
		);
		if ( ! $is_this_plugin ) {

			return;

		}

		// Restore custom elements that were backed up before the update.
		if ( ! empty( $this->custom_backup_path ) && is_dir( $this->custom_backup_path ) ) {

			$custom_dir = WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/modules/breakdance-custom-elements/elements';
			$this->recursive_copy( $this->custom_backup_path, $custom_dir );
			$this->recursive_delete( $this->custom_backup_path );
			$this->custom_backup_path = null;

		}

		$manifest = $this->fetch_manifest( true );
		if ( $manifest && ! empty( $manifest['last_updated'] ) ) {

			update_option( 'octave_addons_installed_last_updated', $manifest['last_updated'] );

		}

		// Flush caches.
		delete_transient( 'octave_addons_remote_manifest' );
		delete_site_transient( 'update_plugins' );

	}

	// ---------------------------------------------------------------------
	// Version logic
	// ---------------------------------------------------------------------

	/**
	 * Compute the effective "remote" version WordPress should compare against.
	 *
	 * - If manifest version > installed version → use manifest version directly.
	 * - Else if manifest.last_updated > stored last_updated → bump the
	 *   PATCH component of the installed version so WP sees an update,
	 *   even though the manifest author forgot to bump the version.
	 *   This is how we honour "check for updates as and when the zip
	 *   date is changed".
	 */
	protected function effective_version( array $manifest ): string {

		$manifest_version = isset( $manifest['version'] ) ? (string) $manifest['version'] : $this->current_version;

		if ( version_compare( $manifest_version, $this->current_version, '>' ) ) {

			return $manifest_version;

		}

		$remote_last_updated    = isset( $manifest['last_updated'] ) ? strtotime( (string) $manifest['last_updated'] ) : 0;
		$installed_last_updated = strtotime( (string) get_option( 'octave_addons_installed_last_updated', '' ) );

		if ( $remote_last_updated && $remote_last_updated > $installed_last_updated ) {

			return $this->bump_patch( $this->current_version );

		}

		return $manifest_version;

	}

	protected function bump_patch( string $version ): string {

		$parts = array_map( 'intval', explode( '.', $version ) );
		while ( count( $parts ) < 3 ) {

			$parts[] = 0;

		}
		$parts[2] = $parts[2] + 1;
		return implode( '.', $parts );

	}

	// ---------------------------------------------------------------------
	// Fetching
	// ---------------------------------------------------------------------

	protected function fetch_manifest( bool $force = false ): ?array {

		if ( ! $force && null !== $this->manifest ) {
			return $this->manifest;

		}

		if ( ! $force ) {

			$cached = get_transient( 'octave_addons_remote_manifest' );
			if ( is_array( $cached ) ) {

				$this->manifest = $cached;
				return $cached;

			}

		}

		if ( empty( $this->manifest_url ) ) {

			return null;

		}

		$response = wp_remote_get( $this->manifest_url, [
			'timeout' => 12,
			'headers' => [
				'Accept'        => 'application/json',
				'Cache-Control' => 'no-cache',
			],
		] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {

			return null;

		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {

			return null;

		}

		set_transient( 'octave_addons_remote_manifest', $decoded, $this->cache_ttl );
		$this->manifest = $decoded;
		return $decoded;

	}

	public function maybe_force_check(): void {

		if ( ! current_user_can( 'manage_options' ) ) {

			return;

		}
		if ( empty( $_GET['octave_addons_check_update'] ) ) {

			return;

		}
		check_admin_referer( 'octave_addons_check_update' );

		delete_transient( 'octave_addons_remote_manifest' );
		delete_site_transient( 'update_plugins' );
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;

	}

	// ---------------------------------------------------------------------
	// Filesystem helpers
	// ---------------------------------------------------------------------

	protected function recursive_copy( string $src, string $dst ): bool {

		if ( ! is_dir( $src ) ) {

			return false;

		}
		if ( ! wp_mkdir_p( $dst ) ) {

			return false;

		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {

			$target = $dst . '/' . $iterator->getSubPathname();
			if ( $item->isDir() ) {

				wp_mkdir_p( $target );

			} else {

				copy( $item->getPathname(), $target );

			}

		}
		return true;

	}

	protected function recursive_delete( string $dir ): void {

		if ( ! is_dir( $dir ) ) {

			return;

		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {

			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );

		}
		rmdir( $dir );

	}

}
