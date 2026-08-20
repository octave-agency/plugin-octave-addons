<?php

/*
GITHUB RELEASE UPDATER
-- Connects published GitHub releases to WordPress plugin update checks.
-- Preserves locally saved Breakdance custom elements during upgrades.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Updater {

	protected string $plugin_file;
	protected string $plugin_basename;
	protected string $plugin_slug;
	protected string $current_version;
	protected string $repository;
	protected string $repository_url;
	protected string $api_url;
	protected int $cache_ttl = 5 * MINUTE_IN_SECONDS;
	protected ?string $custom_backup_path = null;
	protected ?string $library_backup_path = null;
	protected array $library_backup_slugs = [];

	/** @var array|null|false In-memory cache of the GitHub release response. */
	protected $release = null;

	/*
	CONSTRUCTOR
	-- Registers WordPress update, plugin details, and upgrade lifecycle hooks.
	---------------------------------------------------------- */

	public function __construct( string $plugin_file, string $repository, string $current_version ) {

		$this->plugin_file     = $plugin_file;
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->plugin_slug     = dirname( $this->plugin_basename );
		$this->current_version = $current_version;
		$this->repository      = trim( $repository, '/' );
		$this->repository_url  = 'https://github.com/' . $this->repository;
		$this->api_url         = 'https://api.github.com/repos/' . $this->repository . '/releases/latest';

		add_filter( 'update_plugins_github.com', [ $this, 'check_update' ], 10, 4 );
		add_filter( 'plugins_api', [ $this, 'plugins_api' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'normalise_source_directory' ], 10, 4 );
		add_filter( 'upgrader_pre_install', [ $this, 'before_update' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ $this, 'after_update' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'maybe_force_check' ] );

	}

	/*
	CHECK UPDATE
	-- Returns release data to WordPress when the latest GitHub tag is newer.
	---------------------------------------------------------- */

	public function check_update( $update, array $plugin_data, string $plugin_file, array $locales ) {

		if ( $this->plugin_basename !== $plugin_file ) {

			return $update;

		}

		$release = $this->fetch_release();
		$remote_version = $release ? $this->get_release_version( $release ) : '';

		if ( ! $remote_version ) {

			$remote_version = $this->current_version;

		}

		return [
			'id'           => $this->repository_url,
			'slug'         => $this->plugin_slug,
			'version'      => $remote_version,
			'url'          => $release['html_url'] ?? $this->repository_url,
			'package'      => $release ? $this->get_download_url( $release ) : '',
			'requires'     => $plugin_data['RequiresWP'] ?? '',
			'requires_php' => $plugin_data['RequiresPHP'] ?? '',
			'icons'        => $this->get_icons(),
			'banners'      => $this->get_banners(),
		];

	}

	/*
	PLUGIN DETAILS
	-- Supplies the Plugins screen details modal from the latest release.
	---------------------------------------------------------- */

	public function plugins_api( $result, string $action, $args ) {

		if ( 'plugin_information' !== $action ) {

			return $result;

		}

		if ( empty( $args->slug ) || $this->plugin_slug !== $args->slug ) {

			return $result;

		}

		$release = $this->fetch_release();

		if ( ! $release ) {

			return $result;

		}

		$release_notes = isset( $release['body'] ) ? (string) $release['body'] : '';

		return (object) [
			'name'          => 'Octave Addons',
			'slug'          => $this->plugin_slug,
			'version'       => $this->get_release_version( $release ),
			'author'        => '<a href="https://octaveagency.com">Octave Agency</a>',
			'homepage'      => $this->repository_url,
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published_at'] ?? '',
			'download_link' => $this->get_download_url( $release ),
			'banners'       => $this->get_banners(),
			'icons'         => $this->get_icons(),
			'sections'      => [
				'description' => 'A modular collection of Octave site add-ons.',
				'changelog'   => nl2br( esc_html( $release_notes ) ),
			],
		];

	}

	/*
	NORMALISE SOURCE DIRECTORY
	-- Renames GitHub's generated ZIP folder when no packaged release asset exists.
	---------------------------------------------------------- */

	public function normalise_source_directory( $source, string $remote_source, $upgrader, array $hook_extra ) {

		if ( empty( $hook_extra['plugin'] ) || $this->plugin_basename !== $hook_extra['plugin'] ) {

			return $source;

		}

		if ( $this->plugin_slug === basename( untrailingslashit( $source ) ) ) {

			return $source;

		}

		global $wp_filesystem;

		$normalised_source = trailingslashit( $remote_source ) . $this->plugin_slug . '/';

		if ( $wp_filesystem->move( $source, $normalised_source, true ) ) {

			return $normalised_source;

		}

		return new WP_Error(
			'octave_addons_source_directory',
			__( 'The GitHub release could not be prepared for installation.', 'octave-addons' )
		);

	}

	/*
	BEFORE UPDATE
	-- Backs up locally saved Breakdance custom elements before replacement,
	-- plus any shipped library element that has been edited on this site so
	-- the update cannot overwrite local work.
	---------------------------------------------------------- */

	public function before_update( $response, array $hook_extra ) {

		if ( empty( $hook_extra['plugin'] ) || $this->plugin_basename !== $hook_extra['plugin'] ) {

			return $response;

		}

		$this->backup_customised_elements();

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

	/*
	BACKUP CUSTOMISED ELEMENTS
	-- Copies every library element whose files no longer match the shipped
	-- fingerprint into a temporary folder, ready to be put back afterwards.
	---------------------------------------------------------- */

	protected function backup_customised_elements(): void {

		if ( ! class_exists( 'Octave_Addons_Elements_Manifest' ) ) {

			return;

		}

		$customised = Octave_Addons_Elements_Manifest::customised_slugs();

		if ( empty( $customised ) ) {

			return;

		}

		$library = Octave_Addons_Elements_Manifest::library_dir();
		$backup  = get_temp_dir() . 'octave_library_elements_' . time();

		$saved = [];

		foreach ( $customised as $slug ) {

			if ( $this->recursive_copy( $library . '/' . $slug, $backup . '/' . $slug ) ) {

				$saved[] = $slug;

			}

		}

		if ( empty( $saved ) ) {

			return;

		}

		$this->library_backup_path = $backup;
		$this->library_backup_slugs = $saved;

	}

	/*
	AFTER UPDATE
	-- Restores custom elements and clears cached GitHub release data.
	-- The manifest is rebuilt first, while the freshly installed library is
	-- still pristine, so the edited copies restored on top stay recognisable
	-- as customised and keep their protection on the next update.
	---------------------------------------------------------- */

	public function after_update( $upgrader, array $hook_extra ): void {

		$is_this_plugin = (
			( ! empty( $hook_extra['plugin'] ) && $this->plugin_basename === $hook_extra['plugin'] )
			|| ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) && in_array( $this->plugin_basename, $hook_extra['plugins'], true ) )
		);

		if ( ! $is_this_plugin ) {

			return;

		}

		$this->restore_customised_elements();

		if ( ! empty( $this->custom_backup_path ) && is_dir( $this->custom_backup_path ) ) {

			$custom_dir = WP_PLUGIN_DIR . '/' . $this->plugin_slug . '/modules/breakdance-custom-elements/elements';

			$this->recursive_copy( $this->custom_backup_path, $custom_dir );
			$this->recursive_delete( $this->custom_backup_path );

			$this->custom_backup_path = null;

		}

		$this->clear_cache();

	}

	/*
	RESTORE CUSTOMISED ELEMENTS
	-- Rebuilds the shipped fingerprints, then puts the edited element folders
	-- back over the top of the newly installed ones.
	---------------------------------------------------------- */

	protected function restore_customised_elements(): void {

		if ( ! class_exists( 'Octave_Addons_Elements_Manifest' ) ) {

			return;

		}

		Octave_Addons_Elements_Manifest::build();

		if ( empty( $this->library_backup_path ) || ! is_dir( $this->library_backup_path ) ) {

			return;

		}

		$library = Octave_Addons_Elements_Manifest::library_dir();

		foreach ( $this->library_backup_slugs as $slug ) {

			$source = $this->library_backup_path . '/' . $slug;

			if ( ! is_dir( $source ) ) {

				continue;

			}

			$this->recursive_copy( $source, $library . '/' . $slug );

		}

		$this->recursive_delete( $this->library_backup_path );

		$this->library_backup_path  = null;
		$this->library_backup_slugs = [];

	}

	/*
	ASSET URL
	-- Absolute URL for a packaged image, used for the update banners and icons.
	---------------------------------------------------------- */

	protected function asset_url( string $file ): string {

		return plugins_url( 'assets/images/' . $file, $this->plugin_file );

	}

	/*
	BANNERS
	-- Header artwork shown in the plugin details modal.
	---------------------------------------------------------- */

	protected function get_banners(): array {

		return [
			'low'  => $this->asset_url( 'banner-772x250.png' ),
			'high' => $this->asset_url( 'banner-1544x500.png' ),
		];

	}

	/*
	ICONS
	-- Square mark shown beside the plugin on update screens.
	---------------------------------------------------------- */

	protected function get_icons(): array {

		return [
			'1x'      => $this->asset_url( 'icon-128x128.png' ),
			'2x'      => $this->asset_url( 'icon-256x256.png' ),
			'default' => $this->asset_url( 'icon-256x256.png' ),
		];

	}

	/*
	FETCH RELEASE
	-- Retrieves and caches the latest published, non-prerelease GitHub release.
	---------------------------------------------------------- */

	protected function fetch_release( bool $force = false ): ?array {

		if ( ! $force && false === $this->release ) {

			return null;

		}

		if ( ! $force && is_array( $this->release ) ) {

			return $this->release;

		}

		if ( ! $force ) {

			$cached = get_site_transient( 'octave_addons_github_release' );

			if ( is_array( $cached ) ) {

				$this->release = $cached ?: false;

				return $cached ?: null;

			}

		}

		$headers = [
			'Accept'              => 'application/vnd.github+json',
			'Cache-Control'        => 'no-cache',
			'User-Agent'           => 'Octave-Addons/' . $this->current_version,
			'X-GitHub-Api-Version' => '2026-03-10',
		];

		$response = wp_remote_get( $this->api_url, [
			'timeout' => 12,
			'headers' => $headers,
		] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {

			$this->release = false;
			set_site_transient( 'octave_addons_github_release', [], $this->cache_ttl );

			return null;

		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {

			$this->release = false;
			set_site_transient( 'octave_addons_github_release', [], $this->cache_ttl );

			return null;

		}

		$this->release = $release;
		set_site_transient( 'octave_addons_github_release', $release, $this->cache_ttl );

		return $release;

	}

	/*
	GET RELEASE VERSION
	-- Converts a release tag such as v1.2.3 into a comparable version.
	---------------------------------------------------------- */

	protected function get_release_version( array $release ): string {

		$version = isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '';

		return preg_replace( '/^[vV]/', '', trim( $version ) );

	}

	/*
	GET DOWNLOAD URL
	-- Prefers the packaged plugin asset and falls back to GitHub's source ZIP.
	---------------------------------------------------------- */

	protected function get_download_url( array $release ): string {

		$assets = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : [];

		foreach ( $assets as $asset ) {

			if ( 'octave-addons.zip' !== ( $asset['name'] ?? '' ) ) {

				continue;

			}

			return esc_url_raw( $asset['browser_download_url'] ?? '' );

		}

		return esc_url_raw( $release['zipball_url'] ?? '' );

	}

	/*
	FORCE CHECK
	-- Clears cached release data from the existing authenticated admin action.
	---------------------------------------------------------- */

	public function maybe_force_check(): void {

		if ( ! current_user_can( 'manage_options' ) ) {

			return;

		}

		if ( empty( $_GET['octave_addons_check_update'] ) ) {

			return;

		}

		check_admin_referer( 'octave_addons_check_update' );

		$this->clear_cache();

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;

	}

	/*
	CLEAR CACHE
	-- Resets GitHub and WordPress plugin update cache values.
	---------------------------------------------------------- */

	protected function clear_cache(): void {

		$this->release = null;

		delete_site_transient( 'octave_addons_github_release' );
		delete_site_transient( 'update_plugins' );

	}

	/*
	RECURSIVE COPY
	-- Copies custom element files to or from the temporary backup folder.
	---------------------------------------------------------- */

	protected function recursive_copy( string $source, string $destination ): bool {

		if ( ! is_dir( $source ) ) {

			return false;

		}

		if ( ! wp_mkdir_p( $destination ) ) {

			return false;

		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {

			$target = $destination . '/' . $iterator->getSubPathname();

			if ( $item->isDir() ) {

				wp_mkdir_p( $target );

			} else {

				copy( $item->getPathname(), $target );

			}

		}

		return true;

	}

	/*
	RECURSIVE DELETE
	-- Removes the temporary custom element backup after restoration.
	---------------------------------------------------------- */

	protected function recursive_delete( string $directory ): void {

		if ( ! is_dir( $directory ) ) {

			return;

		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {

			if ( $item->isDir() ) {

				rmdir( $item->getPathname() );

			} else {

				unlink( $item->getPathname() );

			}

		}

		rmdir( $directory );

	}

}
