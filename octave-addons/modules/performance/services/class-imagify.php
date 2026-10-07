<?php

/*
PERFORMANCE: IMAGIFY ADAPTER
-- Lets Octave choose Imagify's next-generation format and delivery method
-- without taking any of Imagify's work. Imagify generates the WebP and AVIF
-- files, owns its delivery settings and writes its own marked rewrite rules;
-- Octave never writes an image rewrite rule of its own
-- A change goes through the lifecycle Imagify's settings page uses: its
-- imagify_settings_on_save handlers run while the previous values are still
-- stored, so they can compare old and new and add or remove their rules,
-- then the values are saved through Imagify's own option API
-- Only optimization_format, display_nextgen and display_nextgen_method are
-- ever changed. Every other Imagify setting is read and written back as is
-- Before a change the current settings and .htaccess are kept in a private
-- option. If the site answers HTTP 500 afterwards, both are put back
-- Every expected Imagify API is checked first; anything missing returns an
-- error result and changes nothing
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Imagify {

	/** First release with optimization_format, AVIF and display_nextgen. */
	public const MIN_VERSION = '2.2.2';

	public const STATUS_OPTION  = 'octave_addons_perf_imagify_status';
	public const BACKUP_OPTION  = 'octave_addons_perf_imagify_backup';
	public const LOCK_OPTION    = 'octave_addons_perf_imagify_lock';
	public const VERSION_OPTION = 'octave_addons_perf_imagify_version';
	public const VERIFY_HOOK    = 'octave_addons_perf_imagify_verify';

	/** The only Imagify settings Octave controls. */
	public const KEYS = [ 'optimization_format', 'display_nextgen', 'display_nextgen_method' ];

	/** Start markers Imagify writes around its rewrite rules in .htaccess. */
	public const MARKERS = [
		'webp' => '# BEGIN Imagify: rewrite rules for webp',
		'avif' => '# BEGIN Imagify: rewrite rules for avif',
	];

	/** Other plugins' next-generation image blocks that would compete with Imagify's. */
	protected const COMPETING = [
		'# BEGIN EWWWIO'       => 'EWWW Image Optimizer',
		'# BEGIN WebP Express' => 'WebP Express',
		'# BEGIN ShortPixel'   => 'ShortPixel',
	];

	/** A lock older than this is assumed abandoned. */
	protected const LOCK_TTL = 120;

	/*
	AVAILABILITY
	---------------------------------------------------------- */

	public static function is_active(): bool {

		return defined( 'IMAGIFY_VERSION' );

	}

	public static function version(): string {

		return self::is_active() ? (string) IMAGIFY_VERSION : '';

	}

	/*
	UNAVAILABLE REASON
	-- '' when every API the adapter calls exists
	---------------------------------------------------------- */

	public static function unavailable_reason(): string {

		if ( ! self::is_active() ) {

			return __( 'Imagify is not active.', 'octave-addons' );

		}

		$has_api = class_exists( 'Imagify_Options' )
			&& method_exists( 'Imagify_Options', 'get_instance' )
			&& method_exists( 'Imagify_Options', 'set' )
			&& method_exists( 'Imagify_Options', 'get_all' )
			&& function_exists( 'get_imagify_option' );

		return self::api_problem( self::version(), $has_api );

	}

	/*
	API PROBLEM
	-- Why an installed Imagify cannot be managed, or ''
	---------------------------------------------------------- */

	public static function api_problem( string $version, bool $has_api ): string {

		if ( version_compare( $version, self::MIN_VERSION, '<' ) ) {

			return sprintf(
				/* translators: 1: installed version, 2: required version. */
				__( 'Imagify %1$s is installed; please update it to %2$s or newer so Octave can set it up.', 'octave-addons' ),
				$version,
				self::MIN_VERSION
			);

		}

		if ( ! $has_api ) {

			return __( 'This version of Imagify cannot be set up by Octave, so nothing was changed. Updating Imagify should fix this.', 'octave-addons' );

		}

		return '';

	}

	public static function available(): bool {

		return '' === self::unavailable_reason();

	}

	public static function supports_avif(): bool {

		return self::available() && class_exists( 'Imagify\Avif\RewriteRules\Display' );

	}

	/*
	CONFIG
	-- Imagify's complete current settings
	---------------------------------------------------------- */

	public static function config(): array {

		return self::available() ? (array) Imagify_Options::get_instance()->get_all() : [];

	}

	/*
	SERVER
	-- apache, litespeed, nginx, iis or other, from the server's own name
	---------------------------------------------------------- */

	public static function server(): string {

		$software = strtolower( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '' );
		$server   = 'other';

		foreach ( [ 'litespeed', 'apache', 'nginx', 'iis' ] as $name ) {

			if ( false !== strpos( $software, $name ) || ( 'iis' === $name && false !== strpos( $software, 'microsoft' ) ) ) {

				$server = $name;

				break;

			}

		}

		/**
		 * Filters the detected web server.
		 *
		 * @param string $server apache, litespeed, nginx, iis or other.
		 */
		return (string) apply_filters( 'octave_addons_perf_server', $server );

	}

	public static function reads_htaccess(): bool {

		return in_array( self::server(), [ 'apache', 'litespeed' ], true );

	}

	/*
	HTACCESS
	-- Where Imagify writes, whether it can, which of its markers are there,
	-- and any other plugin's next-generation block competing with it
	---------------------------------------------------------- */

	public static function htaccess_path(): string {

		$root = class_exists( 'Imagify_Filesystem' ) && method_exists( 'Imagify_Filesystem', 'get_instance' )
			? (string) Imagify_Filesystem::get_instance()->get_site_root()
			: ABSPATH;

		return trailingslashit( $root ) . '.htaccess';

	}

	public static function htaccess(): array {

		$path     = self::htaccess_path();
		$exists   = file_exists( $path );
		$contents = $exists ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$markers  = [];

		foreach ( self::MARKERS as $format => $marker ) {

			$markers[ $format ] = false !== strpos( $contents, $marker );

		}

		$competing = [];

		foreach ( self::COMPETING as $marker => $name ) {

			if ( false !== strpos( $contents, $marker ) ) {

				$competing[] = $name;

			}

		}

		return [
			'path'      => $path,
			'exists'    => $exists,
			'writable'  => $exists ? is_writable( $path ) : is_writable( dirname( $path ) ),
			'markers'   => $markers,
			'competing' => $competing,
		];

	}

	/*
	EXPECTED MARKERS
	-- The formats whose rule blocks Imagify adds in rewrite mode. Both are
	-- written whichever format is generated, as Imagify does
	---------------------------------------------------------- */

	public static function expected_markers( array $config ): array {

		if ( empty( $config['display_nextgen'] ) || 'rewrite' !== ( $config['display_nextgen_method'] ?? '' ) || ! self::reads_htaccess() ) {

			return [];

		}

		return self::supports_avif() ? [ 'webp', 'avif' ] : [ 'webp' ];

	}

	public static function missing_markers( array $config ): array {

		$markers = self::htaccess()['markers'];

		return array_values( array_filter( self::expected_markers( $config ), static function ( string $format ) use ( $markers ): bool {

			return empty( $markers[ $format ] );

		} ) );

	}

	/*
	CDN AND CLOUDFLARE
	-- Rewrite rules answer one URL with a different file per browser. A CDN
	-- that caches by URL alone can then hand an AVIF to a browser that asked
	-- for JPEG, so either of these rules rewrite delivery out
	---------------------------------------------------------- */

	public static function cdn(): string {

		$config = self::config();

		if ( '' !== trim( (string) ( $config['cdn_url'] ?? '' ) ) ) {

			return (string) wp_parse_url( (string) $config['cdn_url'], PHP_URL_HOST );

		}

		$uploads = wp_upload_dir( null, false );
		$baseurl = (string) ( $uploads['baseurl'] ?? '' );

		if ( '' !== $baseurl && ! Octave_Addons_Perf::is_same_origin( $baseurl ) ) {

			return (string) wp_parse_url( $baseurl, PHP_URL_HOST );

		}

		return '';

	}

	public static function behind_cloudflare(): bool {

		if ( class_exists( 'Octave_Addons_Perf_Cloudflare' ) && Octave_Addons_Perf_Cloudflare::is_configured() && ! empty( Octave_Addons_Perf::settings( 'performance-cloudflare' )['enabled'] ) ) {

			return true;

		}

		return 'cloudflare' === ( self::status()['edge'] ?? '' );

	}

	/*
	CHOOSE METHOD
	-- Automatic delivery: Imagify's rewrite rules only when every condition
	-- for them holds, otherwise Octave's URL rewriting, which works on any
	-- server and, unlike picture tags, never changes the page's layout. Each
	-- reason a condition failed is returned for the admin
	---------------------------------------------------------- */

	public static function choose_method(): array {

		$reasons  = [];
		$htaccess = self::htaccess();

		if ( ! self::reads_htaccess() ) {

			$reasons[] = 'nginx' === self::server()
				? __( 'This server (Nginx) cannot use Imagify\'s server rules on its own.', 'octave-addons' )
				: __( 'This server cannot use Imagify\'s server rules.', 'octave-addons' );

		} elseif ( ! $htaccess['writable'] ) {

			$reasons[] = $htaccess['exists'] ? __( 'The server rules file (.htaccess) cannot be changed.', 'octave-addons' ) : __( 'The server rules file (.htaccess) is missing and cannot be created.', 'octave-addons' );

		}

		$cdn = self::cdn();

		if ( '' !== $cdn ) {

			/* translators: %s: CDN host name. */
			$reasons[] = sprintf( __( 'Images come from %s, which could send one browser\'s format to another.', 'octave-addons' ), $cdn );

		}

		if ( self::behind_cloudflare() ) {

			$reasons[] = __( 'Cloudflare could send one browser\'s image format to another.', 'octave-addons' );

		}

		return [ 'method' => empty( $reasons ) ? 'rewrite' : 'octave', 'reasons' => $reasons ];

	}

	/*
	OCTAVE DELIVERY
	-- Whether the Imagify module hands delivery to Octave's URL rewriting
	-- (class-nextgen.php) instead of Imagify's rewrite rules or picture tags
	---------------------------------------------------------- */

	public static function octave_delivery(): bool {

		$s = Octave_Addons_Perf::settings( 'performance-imagify' );

		if ( empty( $s['enabled'] ) || ! self::available() ) {

			return false;

		}

		$delivery = (string) ( $s['delivery'] ?? 'auto' );

		return 'octave' === $delivery || ( 'auto' === $delivery && 'octave' === self::choose_method()['method'] );

	}

	/*
	DESIRED
	-- The Imagify values the module's settings ask for. Keep leaves a
	-- setting to Imagify, so turning the module on changes nothing by itself.
	-- Octave delivery keeps Imagify making the files but stops it delivering
	-- them, so the two never rewrite the same image
	---------------------------------------------------------- */

	public static function desired( array $s ): array {

		$values = [];
		$format = (string) ( $s['format'] ?? 'keep' );

		if ( 'avif' === $format && ! self::supports_avif() ) {

			$format = 'keep';

		}

		if ( in_array( $format, [ 'off', 'webp', 'avif' ], true ) ) {

			$values['optimization_format'] = $format;
			$values['display_nextgen']     = 'off' === $format ? 0 : 1;

		}

		$delivery = (string) ( $s['delivery'] ?? 'keep' );

		if ( 'auto' === $delivery ) {

			$delivery = self::choose_method()['method'];

		}

		if ( in_array( $delivery, [ 'rewrite', 'picture' ], true ) ) {

			$values['display_nextgen_method'] = $delivery;

		}

		if ( 'octave' === $delivery ) {

			$values['display_nextgen'] = 0;

		}

		return $values;

	}

	/*
	APPLY
	-- The adapter. Returns a result whichever way it ends
	---------------------------------------------------------- */

	public static function apply( array $changes, string $trigger = 'settings' ): array {

		$reason = self::unavailable_reason();

		if ( '' !== $reason ) {

			return self::record( 'sync', self::result( false, false, $reason, $trigger ) );

		}

		$options = Imagify_Options::get_instance();
		$current = (array) $options->get_all();
		$changes = array_intersect_key( $changes, array_flip( self::KEYS ) );

		// Loose comparison: Imagify stores its switches as 0/1 integers or strings.
		$diff = array_filter( $changes, static function ( $value, $key ) use ( $current ): bool {

			return (string) ( $current[ $key ] ?? '' ) !== (string) $value;

		}, ARRAY_FILTER_USE_BOTH );

		if ( empty( $diff ) ) {

			return self::record( 'sync', self::result( true, false, __( 'Imagify is already set up this way.', 'octave-addons' ), $trigger ) );

		}

		if ( ! self::lock() ) {

			return self::result( false, false, __( 'Imagify is already being updated. Try again in a minute.', 'octave-addons' ), $trigger );

		}

		try {

			$file   = self::read_htaccess();
			$values = array_merge( $current, $diff );

			self::backup( $current, $file );

			// Imagify's settings form leaves an unticked switch out entirely, and
			// some of its handlers test for the key rather than its value.
			$submitted = $values;

			if ( empty( $submitted['display_nextgen'] ) ) {

				unset( $submitted['display_nextgen'] );

			}

			/** This filter is documented in Imagify's inc/classes/class-imagify-settings.php */
			apply_filters( 'imagify_settings_on_save', $submitted );

			$values['display_nextgen'] = empty( $values['display_nextgen'] ) ? 0 : 1;

			$options->set( $values );
			Octave_Addons_Perf_Owners::reset();

			$saved = (array) $options->get_all();

			foreach ( $diff as $key => $value ) {

				if ( (string) ( $saved[ $key ] ?? '' ) !== (string) ( 'display_nextgen' === $key ? (int) ! empty( $value ) : $value ) ) {

					self::restore( $current, $file );

					/* translators: %s: setting name. */
					return self::record( 'sync', self::result( false, false, sprintf( __( 'Imagify did not accept the change to %s, so its previous settings were put back.', 'octave-addons' ), $key ), $trigger ) );

				}

			}

			return self::record( 'sync', self::finish( $saved, $file, $trigger, __( 'Imagify is set up.', 'octave-addons' ) ) );

		} catch ( \Throwable $error ) {

			self::restore( $current, $file ?? null );

			Octave_Addons_Perf_Log::error( 'imagify', $error->getMessage(), $trigger );

			return self::record( 'sync', self::result( false, false, __( 'Imagify reported a problem, so its previous settings were put back.', 'octave-addons' ), $trigger ) );

		} finally {

			self::unlock();

		}

	}

	/*
	REPAIR
	-- Asks Imagify to put its rewrite rules back when they are expected but
	-- missing. Imagify's activation lifecycle re-adds exactly its own blocks
	---------------------------------------------------------- */

	public static function repair( string $trigger = 'manual' ): array {

		$reason = self::unavailable_reason();

		if ( '' !== $reason ) {

			return self::record( 'sync', self::result( false, false, $reason, $trigger ) );

		}

		$config  = self::config();
		$missing = self::missing_markers( $config );

		if ( 'rewrite' !== ( $config['display_nextgen_method'] ?? '' ) || empty( $config['display_nextgen'] ) ) {

			return self::record( 'sync', self::result( true, false, __( 'Imagify is not using server rules, so there is nothing to reapply.', 'octave-addons' ), $trigger ) );

		}

		if ( ! self::reads_htaccess() ) {

			return self::record( 'sync', self::result( false, false, __( 'This server (Nginx) cannot use Imagify\'s server rules. Choose Automatic or Octave Addons Rewriting instead, or ask your host to add the rules.', 'octave-addons' ), $trigger ) );

		}

		if ( empty( $missing ) ) {

			return self::record( 'sync', self::result( true, false, __( 'Imagify\'s server rules are in place.', 'octave-addons' ), $trigger ) );

		}

		if ( ! self::htaccess()['writable'] ) {

			return self::record( 'sync', self::result( false, false, __( 'Imagify cannot add its server rules because the .htaccess file cannot be changed. Choose Automatic or Octave Addons Rewriting instead, or ask your host to make it writable.', 'octave-addons' ), $trigger ) );

		}

		if ( ! self::lock() ) {

			return self::result( false, false, __( 'Imagify is already being updated. Try again in a minute.', 'octave-addons' ), $trigger );

		}

		try {

			$file = self::read_htaccess();

			self::backup( $config, $file );

			/** This action is documented in Imagify's classes/Plugin.php */
			do_action( 'imagify_activation', get_current_user_id() );

			return self::record( 'sync', self::finish( $config, $file, $trigger, __( 'Imagify\'s server rules were put back.', 'octave-addons' ) ) );

		} catch ( \Throwable $error ) {

			self::restore( $config, $file ?? null );

			Octave_Addons_Perf_Log::error( 'imagify', $error->getMessage(), $trigger );

			return self::record( 'sync', self::result( false, false, __( 'Imagify reported a problem, so the server rules file was put back as it was.', 'octave-addons' ), $trigger ) );

		} finally {

			self::unlock();

		}

	}

	/*
	FINISH
	-- After Imagify has run: confirm its markers, check the site still
	-- answers when .htaccess changed, and purge caches only on success
	---------------------------------------------------------- */

	protected static function finish( array $config, ?string $before, string $trigger, string $message ): array {

		$after   = self::read_htaccess();
		$details = [];

		if ( $after !== $before ) {

			$health = self::health_check();

			if ( 500 === $health ) {

				self::restore( (array) ( get_option( self::BACKUP_OPTION, [] )['settings'] ?? $config ), $before );

				return self::result( false, false, __( 'Your site stopped working after Imagify changed its server rules, so everything was put back as it was.', 'octave-addons' ), $trigger );

			}

			$details[] = __( 'Imagify added its server rules and your site still works.', 'octave-addons' );

		}

		$missing = self::missing_markers( $config );

		if ( ! empty( $missing ) ) {

			/* translators: %s: formats, e.g. webp, avif. */
			$details[] = sprintf( __( 'Imagify\'s %s server rules are missing. Make sure the .htaccess file can be changed, then use Reapply Imagify settings.', 'octave-addons' ), implode( ', ', $missing ) );

		}

		if ( 'nginx' === self::server() && 'rewrite' === ( $config['display_nextgen_method'] ?? '' ) && ! empty( $config['display_nextgen'] ) ) {

			$details[] = __( 'This server (Nginx) cannot use Imagify\'s server rules on its own. Choose Automatic or Octave Addons Rewriting, or ask your host to add the rules shown on this page.', 'octave-addons' );

		}

		Octave_Addons_Perf_Cache::purge_all( 'all', 'imagify' );

		return self::result( empty( $missing ), true, trim( $message . "\n" . implode( "\n", $details ) ), $trigger );

	}

	/*
	HEALTH CHECK
	-- The home page's HTTP status, or 0 when it could not be requested
	---------------------------------------------------------- */

	public static function health_check(): int {

		$response = wp_remote_get( add_query_arg( 'oa_health', time(), home_url( '/' ) ), [
			'timeout'     => 10,
			'redirection' => 2,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			'cookies'     => [],
		] );

		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

	}

	/*
	BACKUP / RESTORE
	-- A private, non-autoloaded copy: an .htaccess can hold rules that are
	-- not for public eyes, so it is never written under a public folder
	---------------------------------------------------------- */

	protected static function backup( array $settings, ?string $htaccess ): void {

		update_option( self::BACKUP_OPTION, [
			'time'     => time(),
			'settings' => $settings,
			'path'     => self::htaccess_path(),
			'htaccess' => $htaccess,
		], false );

	}

	protected static function restore( array $settings, ?string $htaccess ): void {

		if ( self::available() ) {

			Imagify_Options::get_instance()->set( $settings );

		}

		$path = self::htaccess_path();

		if ( self::read_htaccess() === $htaccess || '.htaccess' !== basename( $path ) ) {

			return;

		}

		if ( null === $htaccess ) {

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes the file Imagify just created.
			@unlink( $path );

			return;

		}

		if ( is_writable( $path ) ) {

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- restores the exact previous bytes.
			file_put_contents( $path, $htaccess, LOCK_EX );

		}

	}

	protected static function read_htaccess(): ?string {

		$path = self::htaccess_path();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return file_exists( $path ) ? (string) file_get_contents( $path ) : null;

	}

	/*
	LOCK
	-- add_option() fails when the row exists, so only one request syncs
	---------------------------------------------------------- */

	protected static function lock(): bool {

		$held = (int) get_option( self::LOCK_OPTION, 0 );

		if ( $held && $held < time() - self::LOCK_TTL ) {

			delete_option( self::LOCK_OPTION );
			$held = 0;

		}

		return ! $held && add_option( self::LOCK_OPTION, time(), '', false );

	}

	protected static function unlock(): void {

		delete_option( self::LOCK_OPTION );

	}

	/*
	VERIFY
	-- After Imagify is activated or updated: put its rules back if they are
	-- expected and missing, never otherwise
	---------------------------------------------------------- */

	public static function maybe_schedule_verify(): void {

		if ( ! self::is_active() || get_option( self::VERSION_OPTION ) === self::version() ) {

			return;

		}

		update_option( self::VERSION_OPTION, self::version(), false );

		if ( ! wp_next_scheduled( self::VERIFY_HOOK ) ) {

			wp_schedule_single_event( time() + 30, self::VERIFY_HOOK );

		}

	}

	public static function verify(): array {

		if ( ! self::available() || empty( self::missing_markers( self::config() ) ) ) {

			return self::result( true, false, '', 'update' );

		}

		return self::repair( 'update' );

	}

	/*
	TEST DELIVERY
	-- Finds a local image Imagify has a next-generation copy of and asks for
	-- it the way a browser would. Rewrite delivery must answer the original
	-- URL with the new format; picture delivery must serve the copy itself
	-- with the right MIME type
	---------------------------------------------------------- */

	public static function test_delivery(): array {

		$reason = self::unavailable_reason();

		if ( '' !== $reason ) {

			return self::record( 'test', self::result( false, false, $reason, 'test' ) );

		}

		$config = self::config();
		$format = (string) ( $config['optimization_format'] ?? 'off' );
		$method = self::octave_delivery() ? 'octave' : (string) ( $config['display_nextgen_method'] ?? 'picture' );

		if ( 'off' === $format || ( empty( $config['display_nextgen'] ) && 'octave' !== $method ) ) {

			return self::record( 'test', self::result( false, false, __( 'Imagify is not set to use WebP or AVIF images, so there is nothing to check.', 'octave-addons' ), 'test' ) );

		}

		$image = self::find_test_image( $format );

		if ( empty( $image ) ) {

			/* translators: %s: WEBP or AVIF. */
			return self::record( 'test', self::result( false, false, sprintf( __( 'No image with a %s copy was found yet. Optimise at least one image with Imagify, then check again.', 'octave-addons' ), strtoupper( $format ) ), 'test' ) );

		}

		$rewrite  = 'rewrite' === $method;
		$url      = $rewrite ? $image['url'] : $image['url'] . '.' . $format;
		$response = wp_remote_get( add_query_arg( 'oa_nextgen_test', time(), $url ), [
			'timeout'   => 10,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'headers'   => [ 'Accept' => 'avif' === $format ? 'image/avif,image/webp,image/*;q=0.8' : 'image/webp,image/*;q=0.8' ],
		] );

		if ( is_wp_error( $response ) ) {

			/* translators: %s: error message. */
			return self::record( 'test', self::result( false, false, sprintf( __( 'The image could not be opened: %s', 'octave-addons' ), $response->get_error_message() ), 'test' ) );

		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$type     = strtolower( trim( (string) strtok( (string) wp_remote_retrieve_header( $response, 'content-type' ), ';' ) ) );
		$vary     = (string) wp_remote_retrieve_header( $response, 'vary' );
		$edge     = '' !== (string) wp_remote_retrieve_header( $response, 'cf-ray' ) ? 'cloudflare' : '';
		$expected = 'image/' . $format;
		$ok       = 200 === $code && $expected === $type;
		$lines    = [ $url ];

		if ( 200 !== $code ) {

			/* translators: %d: HTTP status. */
			$lines[] = sprintf( __( 'The image did not load (error %d).', 'octave-addons' ), $code );

		} elseif ( $ok ) {

			/* translators: %s: MIME type. */
			$lines[] = sprintf( __( 'Served as %s.', 'octave-addons' ), $type );

		} else {

			/* translators: 1: received MIME type, 2: expected MIME type. */
			$lines[] = sprintf( __( 'Served as %1$s instead of %2$s.', 'octave-addons' ), '' !== $type ? $type : '?', $expected );
			$lines[] = $rewrite ? __( 'Imagify\'s server rules are not working here. Choose Automatic or Octave Addons Rewriting to fix this.', 'octave-addons' ) : __( 'Your server does not recognise this image format. Ask your host to add it.', 'octave-addons' );

		}

		if ( 'octave' === $method && $ok ) {

			$lines[] = __( 'Octave points your pages straight at this copy, so no server rules are needed.', 'octave-addons' );

		}

		if ( 'octave' === $method && $ok ) {

			$lines[] = __( 'Octave points your pages straight at this copy, so no server rules are needed.', 'octave-addons' );

		}

		if ( $rewrite && $ok && false === stripos( $vary, 'accept' ) ) {

			$lines[] = __( 'Some caches could send this format to browsers that cannot show it. Ask your host to add a "Vary: Accept" header for images.', 'octave-addons' );

		}

		$result         = self::result( $ok, false, implode( "\n", $lines ), 'test' );
		$result['edge'] = $edge;

		return self::record( 'test', $result );

	}

	/*
	FIND TEST IMAGE
	-- A recent JPEG or PNG attachment Imagify marked successful whose
	-- next-generation file exists beside it and whose URL is local
	---------------------------------------------------------- */

	public static function find_test_image( string $format ): array {

		$ids = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => [ 'image/jpeg', 'image/png' ],
			'meta_key'       => '_imagify_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin-only diagnostic.
			'meta_value'     => 'success', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- admin-only diagnostic.
			'numberposts'    => 20,
			'fields'         => 'ids',
		] );

		foreach ( (array) $ids as $id ) {

			$file = (string) get_attached_file( (int) $id );
			$url  = (string) wp_get_attachment_url( (int) $id );

			if ( '' !== $file && '' !== $url && file_exists( $file . '.' . $format ) && Octave_Addons_Perf::is_same_origin( $url ) ) {

				return [ 'id' => (int) $id, 'url' => $url ];

			}

		}

		return [];

	}

	/*
	NGINX RULES
	-- The configuration Imagify generates for Nginx, to copy into the server
	---------------------------------------------------------- */

	public static function nginx_rules(): array {

		$rules = [];

		foreach ( [ 'webp' => 'Imagify\Webp\RewriteRules\Nginx', 'avif' => 'Imagify\Avif\RewriteRules\Nginx' ] as $format => $class ) {

			if ( ! class_exists( $class ) ) {

				continue;

			}

			try {

				$conf = new $class();

				$rules[ $format ] = [ 'path' => (string) $conf->get_file_path(), 'rules' => (string) $conf->get_new_contents() ];

			} catch ( \Throwable $error ) {

				continue;

			}

		}

		return $rules;

	}

	/*
	STATUS
	-- Last synchronisation and delivery test, and the edge last seen
	---------------------------------------------------------- */

	public static function status(): array {

		$status = get_option( self::STATUS_OPTION, [] );

		return is_array( $status ) ? $status : [];

	}

	protected static function record( string $kind, array $result ): array {

		$status          = self::status();
		$status[ $kind ] = $result;

		if ( isset( $result['edge'] ) ) {

			$status['edge'] = $result['edge'];

		}

		update_option( self::STATUS_OPTION, $status, false );

		return $result;

	}

	protected static function result( bool $ok, bool $changed, string $message, string $trigger ): array {

		return [ 'ok' => $ok, 'changed' => $changed, 'message' => $message, 'trigger' => $trigger, 'time' => time() ];

	}

}
