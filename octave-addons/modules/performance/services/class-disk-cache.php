<?php

/*
PERFORMANCE: OCTAVE PAGE CACHE
-- A filesystem page cache for sites with no other one. It only runs when
-- the Page Cache module is on and no other page cache was found: Cloudways
-- Varnish, a page-cache plugin or a managed host always wins, and an
-- advanced-cache.php written by anything else means another cache owns
-- the site, so Octave never stacks a second cache in front of it
-- Pages are stored after every Octave optimisation has run, written beside
-- their final name and renamed into place, under
-- wp-content/cache/octave-addons/pages/. Octave's advanced-cache.php
-- drop-in answers from them before WordPress loads; without the drop-in
-- (WP_CACHE off, or wp-content not writable) the plugin answers at init,
-- which still skips the theme, the main query and Breakdance rendering
-- wp-config.php is never edited. A page is only stored for an anonymous
-- GET of a page-like URL that WordPress answered with 200 and no cookies,
-- no Cache-Control: no-store, private or no-cache, no DONOTCACHEPAGE, no
-- password and none of the request types Octave never optimises (admin,
-- AJAX, REST, feeds, previews, login, cart, checkout and account pages).
-- A page whose optimised files were not ready yet is not stored either
-- Purges delete files; expired pages are removed by an hourly cron job, so
-- no request ever scans the directory
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Disk_Cache {

	public const MODULE    = 'performance-page-cache';
	public const DIR       = 'pages';
	public const GC_HOOK   = 'octave_addons_perf_page_cache_gc';
	public const LABEL     = 'Octave page cache';
	public const MARKER    = 'OCTAVE_ADDONS_PAGE_CACHE_DROP_IN';
	public const DROP_IN   = 'advanced-cache.php';
	public const HEADER    = 'X-Octave-Cache';

	/** Cookie name prefixes that always reach WordPress. */
	public const BYPASS_COOKIES = [
		'wordpress_logged_in_',
		'wordpress_sec_',
		'wp-postpass_',
		'comment_author_',
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
		'wp_woocommerce_session_',
		'edd_items_in_cart',
		'wp-resetpass-',
	];

	/** Query arguments that never change a page, so a request carrying only these is still cached. */
	public const IGNORED_QUERY = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid', '_ga', 'mc_cid', 'mc_eid' ];

	/** Path fragments that are never cached. */
	public const SKIP_PATHS = [ '/wp-admin', '/wp-login', '/wp-json', '/wp-content/', '/wp-includes/', 'xmlrpc', 'wp-cron', '/feed', '/cart', '/checkout', '/my-account', '/basket', '/account' ];

	/** @var bool Whether this request's buffer was started. */
	protected static bool $started = false;

	/** @var string Why the page in progress must not be stored, or ''. */
	protected static string $skip = '';

	/*
	BOOT
	-- Called by the Page Cache module on every request it is switched on
	---------------------------------------------------------- */

	public static function boot(): void {

		add_filter( 'octave_addons_perf_purge_all_layers', [ __CLASS__, 'purge_all_layer' ], 15, 2 );
		add_filter( 'octave_addons_perf_purge_url_layers', [ __CLASS__, 'purge_url_layer' ], 15, 3 );
		add_action( 'octave_addons_perf_purged_all', [ __CLASS__, 'on_purged_all' ], 5, 3 );
		add_action( self::GC_HOOK, [ __CLASS__, 'collect_garbage' ] );
		add_action( 'admin_init', [ __CLASS__, 'maintain' ] );

		if ( ! self::is_active() ) {

			return;

		}

		if ( ! defined( self::MARKER ) ) {

			self::serve_from_plugin();

		}

		add_action( 'template_redirect', [ __CLASS__, 'start' ], 1 );

		if ( ! wp_next_scheduled( self::GC_HOOK ) ) {

			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::GC_HOOK );

		}

	}

	public static function reset(): void {

		self::$started = false;
		self::$skip    = '';

	}

	/*
	SETTINGS
	---------------------------------------------------------- */

	public static function settings(): array {

		return Octave_Addons_Perf::settings( self::MODULE );

	}

	public static function lifespan(): int {

		return max( 0, (int) ( self::settings()['lifespan'] ?? 10 ) ) * HOUR_IN_SECONDS;

	}

	/*
	STATUS
	-- [ 'code' => string, 'message' => string ]. Codes: off, deferred,
	-- conflict, unwritable, plugin (serving without the drop-in) and active
	---------------------------------------------------------- */

	public static function status(): array {

		if ( empty( self::settings()['enabled'] ) ) {

			return [ 'code' => 'off', 'message' => __( 'Off.', 'octave-addons' ) ];

		}

		$owner = Octave_Addons_Perf::handled_elsewhere( 'page_cache' );

		if ( '' !== $owner ) {

			/* translators: %s: cache name. */
			return [ 'code' => 'deferred', 'message' => sprintf( __( 'Not used: %s already caches pages, and two page caches would only get in each other\'s way.', 'octave-addons' ), $owner ) ];

		}

		if ( self::foreign_drop_in() ) {

			return [ 'code' => 'conflict', 'message' => __( 'Not used: wp-content/advanced-cache.php belongs to another plugin, which means another page cache is installed. Remove that plugin\'s cache, or switch this module off.', 'octave-addons' ) ];

		}

		if ( ! wp_mkdir_p( Octave_Addons_Perf_Store::dir( self::DIR ) ) || ! is_writable( Octave_Addons_Perf_Store::dir( self::DIR ) ) ) {

			return [ 'code' => 'unwritable', 'message' => __( 'Not used: wp-content/cache/octave-addons/ is not writable. Make it writable by the web server.', 'octave-addons' ) ];

		}

		if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {

			return [ 'code' => 'plugin', 'message' => __( 'Caching. Pages are answered once plugins have loaded. For the fastest answers add define( \'WP_CACHE\', true ); to wp-config.php, above the line that says to stop editing; Octave never edits that file itself.', 'octave-addons' ) ];

		}

		if ( ! self::own_drop_in() ) {

			return [ 'code' => 'plugin', 'message' => __( 'Caching. Pages are answered once plugins have loaded, because wp-content/advanced-cache.php could not be written. Make wp-content writable to let Octave answer before WordPress loads.', 'octave-addons' ) ];

		}

		return [ 'code' => 'active', 'message' => __( 'Caching. Pages are answered before WordPress loads.', 'octave-addons' ) ];

	}

	/*
	IS ACTIVE
	-- Cheap enough for every request: settings, owners and one file check
	---------------------------------------------------------- */

	public static function is_active(): bool {

		$active = ! empty( self::settings()['enabled'] )
			&& '' === Octave_Addons_Perf::handled_elsewhere( 'page_cache' )
			&& ! self::foreign_drop_in();

		/**
		 * Filters whether Octave's own page cache stores and serves pages.
		 *
		 * @param bool $active Whether it runs.
		 */
		return (bool) apply_filters( 'octave_addons_perf_page_cache_active', $active );

	}

	/*
	DROP-IN OWNERSHIP
	-- A loaded drop-in that is not Octave's defines no marker. One that is
	-- not loaded is read to see whose it is
	---------------------------------------------------------- */

	public static function drop_in_path(): string {

		return trailingslashit( WP_CONTENT_DIR ) . self::DROP_IN;

	}

	public static function foreign_drop_in(): bool {

		$path = self::drop_in_path();

		if ( ! file_exists( $path ) ) {

			return false;

		}

		// WordPress loads the drop-in itself while WP_CACHE is on, and Octave's defines the marker.
		if ( defined( 'WP_CACHE' ) && WP_CACHE && defined( self::MARKER ) ) {

			return false;

		}

		return ! self::own_drop_in();

	}

	public static function own_drop_in(): bool {

		$path = self::drop_in_path();

		if ( ! is_file( $path ) ) {

			return false;

		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return false !== strpos( (string) file_get_contents( $path, false, null, 0, 1024 ), self::MARKER );

	}

	/*
	CONFIG
	-- Everything the standalone serve code needs, baked into the drop-in
	---------------------------------------------------------- */

	public static function config(): array {

		$hosts = [ strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ];
		$port  = wp_parse_url( home_url(), PHP_URL_PORT );

		if ( $port ) {

			$hosts = [ $hosts[0] . ':' . (int) $port ];

		}

		return [
			'dir'          => Octave_Addons_Perf_Store::dir( self::DIR ),
			'lifespan'     => self::lifespan(),
			'hosts'        => $hosts,
			'charset'      => (string) get_bloginfo( 'charset' ) ?: 'UTF-8',

			/**
			 * Filters the cookie name prefixes that always bypass Octave's page cache.
			 *
			 * @param string[] $prefixes Cookie name prefixes.
			 */
			'cookies'      => array_values( array_filter( array_map( 'strval', (array) apply_filters( 'octave_addons_perf_page_cache_bypass_cookies', self::BYPASS_COOKIES ) ) ) ),

			/**
			 * Filters the query arguments ignored when deciding whether a request can use the page cache.
			 *
			 * @param string[] $arguments Argument names.
			 */
			'ignore_query' => array_values( array_map( 'strval', (array) apply_filters( 'octave_addons_perf_page_cache_ignored_query', self::IGNORED_QUERY ) ) ),

			/**
			 * Filters the URL path fragments Octave's page cache never stores or serves.
			 *
			 * @param string[] $paths Case-insensitive path fragments.
			 */
			'skip_paths'   => array_values( array_map( 'strval', (array) apply_filters( 'octave_addons_perf_page_cache_skip_paths', self::SKIP_PATHS ) ) ),
		];

	}

	/*
	SERVE FROM PLUGIN
	-- The drop-in's job, done at init when the drop-in is not in place
	---------------------------------------------------------- */

	protected static function serve_from_plugin(): void {

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {

			return;

		}

		require_once __DIR__ . '/page-cache-serve.php';

		octave_addons_page_cache_serve( self::config() );

	}

	/*
	MAINTAIN
	-- In the admin only: puts Octave's drop-in in place while the cache is
	-- active, takes it away when not, and never touches anyone else's
	---------------------------------------------------------- */

	public static function maintain(): void {

		if ( ! current_user_can( 'manage_options' ) ) {

			return;

		}

		if ( self::is_active() ) {

			self::install();

			return;

		}

		self::remove_drop_in();
		wp_clear_scheduled_hook( self::GC_HOOK );

		if ( is_dir( Octave_Addons_Perf_Store::dir( self::DIR ) ) ) {

			Octave_Addons_Perf_Store::delete( self::DIR );

		}

	}

	/*
	INSTALL
	-- Writes the drop-in when it is missing or out of date. Written beside
	-- its final name and renamed into place
	---------------------------------------------------------- */

	public static function install(): bool {

		$path = self::drop_in_path();

		if ( file_exists( $path ) && ! self::own_drop_in() ) {

			return false;

		}

		$contents = self::drop_in();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( is_file( $path ) && (string) file_get_contents( $path ) === $contents ) {

			return true;

		}

		if ( ! is_writable( dirname( $path ) ) || ( file_exists( $path ) && ! is_writable( $path ) ) ) {

			return false;

		}

		$tmp = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- atomic replace of Octave's own drop-in.
		if ( strlen( $contents ) !== file_put_contents( $tmp, $contents, LOCK_EX ) || ! @rename( $tmp, $path ) ) {

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Octave's own temporary file.
			@unlink( $tmp );

			return false;

		}

		return true;

	}

	public static function remove_drop_in(): void {

		if ( self::own_drop_in() ) {

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Octave's own drop-in only.
			@unlink( self::drop_in_path() );

		}

	}

	/*
	UNINSTALL
	-- On deactivation: Octave's drop-in, its stored pages and its cron job
	---------------------------------------------------------- */

	public static function uninstall(): void {

		self::remove_drop_in();
		Octave_Addons_Perf_Store::delete( self::DIR );
		wp_clear_scheduled_hook( self::GC_HOOK );

	}

	/*
	DROP IN
	-- The drop-in's source. It only loads the serve code when the plugin is
	-- still installed, so removing the plugin can never break the site
	---------------------------------------------------------- */

	public static function drop_in(): string {

		return "<?php\n\n"
			. "/*\nOCTAVE ADDONS PAGE CACHE\n-- " . self::MARKER . "\n"
			. "-- Written by the Octave Addons Page Cache module and removed when it is\n"
			. "-- switched off or the plugin is deactivated. Edits are overwritten\n"
			. "---------------------------------------------------------- */\n\n"
			. "if ( ! defined( 'ABSPATH' ) ) {\n\n\texit;\n\n}\n\n"
			. "define( '" . self::MARKER . "', true );\n\n"
			. '$octave_addons_page_cache_serve = ' . var_export( __DIR__ . '/page-cache-serve.php', true ) . ";\n\n"
			. "if ( is_file( \$octave_addons_page_cache_serve ) ) {\n\n"
			. "\trequire_once \$octave_addons_page_cache_serve;\n\n"
			. "\toctave_addons_page_cache_serve( " . var_export( self::config(), true ) . " );\n\n"
			. "}\n\n"
			. "unset( \$octave_addons_page_cache_serve );\n";

	}

	/*
	START
	-- Wraps Octave's own HTML buffer, which starts later, so the page stored
	-- is the optimised one. Bypassed requests say why in X-Octave-Cache
	---------------------------------------------------------- */

	public static function start(): void {

		if ( self::$started ) {

			return;

		}

		$reason = self::request_bypass();

		if ( '' !== $reason ) {

			if ( ! headers_sent() ) {

				header( self::HEADER . ': BYPASS' );

			}

			return;

		}

		self::$started = true;
		self::$skip    = '';

		if ( ! headers_sent() ) {

			header( self::HEADER . ': MISS' );

		}

		ob_start( [ __CLASS__, 'buffer' ] );

	}

	/*
	REQUEST BYPASS
	-- Why this request is never stored, or ''
	---------------------------------------------------------- */

	public static function request_bypass(): string {

		require_once __DIR__ . '/page-cache-serve.php';

		$reason = Octave_Addons_Perf_Context::bypass_reason( 'page-cache' );

		if ( '' !== $reason ) {

			return $reason;

		}

		if ( '' === octave_addons_page_cache_key( self::config(), $_SERVER, $_COOKIE ) ) {

			return 'request';

		}

		return defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ? 'donotcachepage' : '';

	}

	/*
	SKIP
	-- Lets other code keep the page in progress out of the cache, such as
	-- an optimisation that is still waiting for its files
	---------------------------------------------------------- */

	public static function skip( string $reason ): void {

		if ( '' === self::$skip ) {

			self::$skip = $reason;

		}

	}

	/*
	BUFFER
	-- Collects the page and stores it once complete. Always returns the
	-- page unchanged
	---------------------------------------------------------- */

	public static function buffer( string $chunk, int $phase ) {

		static $pending = '';

		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {

			$pending = '';

		}

		$pending .= $chunk;

		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {

			return '';

		}

		$html    = $pending;
		$pending = '';

		try {

			self::store( $html );

		} catch ( \Throwable $error ) {

			Octave_Addons_Perf_Log::error( 'page-cache', $error->getMessage(), self::LABEL );

		}

		return $html;

	}

	/*
	STORE
	-- Writes the page when every response check passes. Returns the reason
	-- it was not stored, or '' once written
	---------------------------------------------------------- */

	public static function store( string $html ): string {

		$reason = self::response_bypass( $html );

		if ( '' !== $reason ) {

			return $reason;

		}

		$key = octave_addons_page_cache_key( self::config(), $_SERVER, $_COOKIE );

		if ( '' === $key ) {

			return 'request';

		}

		$html .= "\n<!-- " . self::LABEL . ': ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC -->';

		return Octave_Addons_Perf_Store::write( Octave_Addons_Perf_Store::dir( self::DIR ) . $key, $html ) ? '' : 'unwritable';

	}

	/*
	RESPONSE BYPASS
	-- Checks made once the page is complete: status, headers, page type
	---------------------------------------------------------- */

	public static function response_bypass( string $html ): string {

		if ( '' !== self::$skip ) {

			return self::$skip;

		}

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {

			return 'donotcachepage';

		}

		$code = (int) http_response_code();

		if ( 200 !== $code && 0 !== $code ) {

			return 'status';

		}

		if ( function_exists( 'is_404' ) && is_404() ) {

			return 'status';

		}

		if ( function_exists( 'is_search' ) && is_search() ) {

			return 'search';

		}

		if ( function_exists( 'post_password_required' ) && is_singular() && post_password_required() ) {

			return 'password';

		}

		if ( is_user_logged_in() ) {

			return 'logged-in';

		}

		$reason = self::headers_bypass( headers_list() );

		if ( '' !== $reason ) {

			return $reason;

		}

		if ( ! Octave_Addons_Perf_Html::is_html_document( $html ) || false === stripos( $html, '</html>' ) ) {

			return 'non-html';

		}

		return '';

	}

	/*
	HEADERS BYPASS
	-- Cookies, a redirect, non-HTML content or a Cache-Control that asks
	-- caches not to keep the response. Nothing here removes a header
	---------------------------------------------------------- */

	public static function headers_bypass( array $headers ): string {

		foreach ( $headers as $header ) {

			$header = strtolower( (string) $header );

			if ( 0 === strpos( $header, 'set-cookie:' ) ) {

				return 'cookie';

			}

			if ( 0 === strpos( $header, 'location:' ) ) {

				return 'redirect';

			}

			if ( 0 === strpos( $header, 'content-type:' ) && false === strpos( $header, 'text/html' ) ) {

				return 'non-html';

			}

			if ( 0 === strpos( $header, 'cache-control:' ) && preg_match( '/\b(no-store|private|no-cache)\b/', $header ) ) {

				return 'cache-control';

			}

		}

		return '';

	}

	/*
	PURGE LAYERS
	-- Reported only while the cache is active or still holds pages
	---------------------------------------------------------- */

	public static function purge_all_layer( $report, $reason ): array {

		$report = (array) $report;

		if ( ! self::is_active() && ! is_dir( Octave_Addons_Perf_Store::dir( self::DIR ) ) ) {

			return $report;

		}

		$deleted = Octave_Addons_Perf_Store::delete( self::DIR );

		$report['octave-page-cache'] = [
			'label'   => self::LABEL,
			'status'  => 'success',
			/* translators: %d: number of pages. */
			'message' => sprintf( _n( 'Cleared %d page.', 'Cleared %d pages.', (int) $deleted['files'], 'octave-addons' ), (int) $deleted['files'] ),
		];

		return $report;

	}

	public static function purge_url_layer( $report, $urls, $reason ): array {

		$report = (array) $report;

		if ( ! self::is_active() && ! is_dir( Octave_Addons_Perf_Store::dir( self::DIR ) ) ) {

			return $report;

		}

		$deleted = 0;

		foreach ( (array) $urls as $url ) {

			foreach ( self::files_for( (string) $url ) as $file ) {

				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Octave's own cached page.
				if ( is_file( $file ) && Octave_Addons_Perf_Store::is_inside( $file ) && @unlink( $file ) ) {

					$deleted++;

				}

			}

		}

		$report['octave-page-cache'] = [
			'label'   => self::LABEL,
			'status'  => 'success',
			/* translators: %d: number of pages. */
			'message' => sprintf( _n( 'Cleared %d page.', 'Cleared %d pages.', $deleted, 'octave-addons' ), $deleted ),
		];

		return $report;

	}

	/*
	ON PURGED ALL
	-- Clearing only the minified files still empties the page cache, since
	-- stored pages point at the files just deleted
	---------------------------------------------------------- */

	public static function on_purged_all( $report, $reason, $scope ): void {

		if ( 'files' === $scope ) {

			Octave_Addons_Perf_Store::delete( self::DIR );

		}

	}

	/*
	FILES FOR
	-- Every stored variant of one URL
	---------------------------------------------------------- */

	public static function files_for( string $url ): array {

		require_once __DIR__ . '/page-cache-serve.php';

		$config = self::config();
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port   = wp_parse_url( $url, PHP_URL_PORT );
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$files  = [];

		foreach ( [ '', 'on' ] as $https ) {

			$server = [
				'REQUEST_METHOD' => 'GET',
				'REQUEST_URI'    => '' !== $path ? $path : '/',
				'HTTP_HOST'      => strtolower( $host ) . ( $port ? ':' . (int) $port : '' ),
				'HTTPS'          => $https,
			];

			$key = octave_addons_page_cache_key( $config, $server, [] );

			if ( '' !== $key ) {

				$files[] = Octave_Addons_Perf_Store::dir( self::DIR ) . $key;

			}

		}

		return $files;

	}

	/*
	COLLECT GARBAGE
	-- Hourly: removes pages older than the lifespan. Returns how many
	---------------------------------------------------------- */

	public static function collect_garbage(): int {

		$lifespan = self::lifespan();
		$dir      = untrailingslashit( Octave_Addons_Perf_Store::dir( self::DIR ) );
		$removed  = 0;

		if ( $lifespan <= 0 || ! is_dir( $dir ) ) {

			return 0;

		}

		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $items as $item ) {

			if ( $item->isDir() ) {

				// Empty folders go too; rmdir refuses any that still hold pages.
				@rmdir( $item->getPathname() );

				continue;

			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Octave's own expired page.
			if ( ! $item->isLink() && $item->getMTime() < time() - $lifespan && @unlink( $item->getPathname() ) ) {

				$removed++;

			}

		}

		return $removed;

	}

}
