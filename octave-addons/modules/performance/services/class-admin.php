<?php

/*
PERFORMANCE ADMIN
-- Everything the Performance page adds around the module panels: the status
-- cards with a clear action for each enabled cache, diagnostics,
-- the admin bar shortcut, and the AJAX endpoints behind every async button
-- Every endpoint checks the oa_perf_admin nonce and manage_options before
-- doing anything, and answers with plain data the browser renders as text
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Admin {

	public const NONCE       = 'oa_perf_admin';
	public const BAR_ACTION  = 'oa_perf_admin_bar';
	public const PAGE_SLUG   = 'octave-addons-performance';

	/** AJAX action => handler method. */
	protected const ENDPOINTS = [
		'oa_perf_purge'          => 'ajax_purge',
		'oa_perf_scan'           => 'ajax_scan',
		'oa_perf_log_clear'      => 'ajax_log_clear',
		'oa_perf_cf_test'        => 'ajax_cf_test',
		'oa_perf_fonts_refresh'  => 'ajax_fonts_refresh',
		'oa_perf_db_counts'      => 'ajax_db_counts',
		'oa_perf_db_run'         => 'ajax_db_run',
	];

	/*
	BOOT
	---------------------------------------------------------- */

	public static function boot(): void {

		add_action( 'octave_addons_render_entry_intro', [ __CLASS__, 'render_header' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar' ], 90 );
		add_action( 'admin_post_' . self::BAR_ACTION, [ __CLASS__, 'handle_admin_bar' ] );
		add_action( 'admin_notices', [ __CLASS__, 'print_purge_notice' ] );

		foreach ( self::ENDPOINTS as $action => $method ) {

			add_action( 'wp_ajax_' . $action, [ __CLASS__, $method ] );

		}

	}

	/*
	GUARD
	-- Shared nonce and capability check for every endpoint
	---------------------------------------------------------- */

	public static function guard(): void {

		if ( false === check_ajax_referer( self::NONCE, 'nonce', false ) ) {

			wp_send_json_error( [ 'message' => __( 'Your session has expired. Reload the page and try again.', 'octave-addons' ) ], 403 );

		}

		if ( ! current_user_can( 'manage_options' ) ) {

			wp_send_json_error( [ 'message' => __( 'You do not have permission to do that.', 'octave-addons' ) ], 403 );

		}

	}

	/*
	SEND RESULT
	-- Answers with success or error data depending on the outcome
	---------------------------------------------------------- */

	protected static function send_result( bool $ok, array $payload ): void {

		if ( $ok ) {

			wp_send_json_success( $payload );

		}

		wp_send_json_error( $payload );

	}

	/*
	ENQUEUE ASSETS
	-- Loads only on the Performance page, after the shared admin script so the
	-- confirmation dialog and notifications are available
	---------------------------------------------------------- */

	public static function enqueue_assets(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page check.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::PAGE_SLUG !== $page ) {

			return;

		}

		wp_enqueue_style( 'octave-addons-performance', Octave_Addons_Perf::asset_url( 'admin.css' ), [ 'octave-addons-admin' ], Octave_Addons_Perf::asset_version( 'admin.css' ) );
		wp_enqueue_script( 'octave-addons-performance', Octave_Addons_Perf::asset_url( 'admin.js' ), [ 'octave-addons-admin' ], Octave_Addons_Perf::asset_version( 'admin.js' ), true );

		wp_localize_script( 'octave-addons-performance', 'oaPerf', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			'i18n'    => [
				'working'          => __( 'Working…', 'octave-addons' ),
				'failed'           => __( 'The request failed. Check your connection and try again.', 'octave-addons' ),
				'dbTitle'          => __( 'Run database cleanup?', 'octave-addons' ),
				'dbText'           => __( 'The selected data is permanently deleted and cannot be restored. Take a database backup first if you may need any of it.', 'octave-addons' ),
				'dbAction'         => __( 'Delete permanently', 'octave-addons' ),
				'cfEverythingTitle'  => __( 'Purge everything from Cloudflare?', 'octave-addons' ),
				'cfEverythingText'   => __( 'Every cached file in the zone is dropped, so the next visitors are served from the origin while Cloudflare refills its cache.', 'octave-addons' ),
				'cfEverythingAction' => __( 'Purge everything', 'octave-addons' ),
				'dbNothing'        => __( 'Select at least one item to clean up.', 'octave-addons' ),
				/* translators: 1: item label, 2: number removed so far. */
				'dbProgress'       => __( '%1$s: %2$d removed so far…', 'octave-addons' ),
				'dbDone'           => __( 'Cleanup finished.', 'octave-addons' ),
				'scanEmpty'        => __( 'Nothing was delayed, lazy loaded or rewritten on that page.', 'octave-addons' ),
				'scanBypass'       => __( 'The scanned request was not optimised. Reason:', 'octave-addons' ),
				'scanScripts'      => __( 'Scripts', 'octave-addons' ),
				'scanMedia'        => __( 'Media', 'octave-addons' ),
				'scanFonts'        => __( 'Google Fonts stylesheets', 'octave-addons' ),
				'scanQueued'       => __( 'not cached yet, queued for download', 'octave-addons' ),
			],
		] );

	}

	/*
	RENDER HEADER
	-- Status cards and global actions above the Performance module panels
	---------------------------------------------------------- */

	public static function render_header( string $entry_id ): void {

		if ( Octave_Addons_Perf::GROUP !== $entry_id ) {

			return;

		}

		$size       = Octave_Addons_Perf_Store::size();
		$last_purge = Octave_Addons_Perf_Cache::last_purge();
		$cf_status  = Octave_Addons_Perf_Cloudflare::status();
		$cf_enabled = ! empty( Octave_Addons_Perf::settings( 'performance-cloudflare' )['enabled'] );
		$files_on   = ! empty( Octave_Addons_Perf::settings( 'performance-files' )['enabled'] );
		$fonts      = Octave_Addons_Perf_Google_Fonts::manifest();
		$db_last    = Octave_Addons_Perf_Cleanup::last();
		$errors     = array_slice( Octave_Addons_Perf_Log::entries(), 0, 10 );

		$font_files = 0;

		foreach ( $fonts as $font ) {

			$font_files += count( (array) ( $font['files'] ?? [] ) );

		}

		?>

		<div class="oa-perf" data-oa-perf-local>

			<?php

			if ( ! Octave_Addons_Perf::has_html_api() ) :

			?>

			<div class="notice notice-warning inline oa-inline-notice">
				<p><?php esc_html_e( 'Page markup optimisations need WordPress 6.2 or newer. Until WordPress is updated, lazy loading, script delay and font rewriting leave pages untouched.', 'octave-addons' ); ?></p>
			</div>

			<?php

			endif;

			?>

			<div class="oa-perf-status-grid">

				<div class="oa-perf-card">
					<span class="oa-panel-kicker"><?php esc_html_e( 'Minified files', 'octave-addons' ); ?></span>
					<strong><?= esc_html( size_format( $size['bytes'] ) ?: '0 B' ); ?></strong>
					<span>
						<?php

						printf(
							/* translators: 1: number of files, 2: cache generation number. */
							esc_html__( '%1$d files · generation %2$d', 'octave-addons' ),
							(int) $size['files'],
							(int) Octave_Addons_Perf_Cache::generation()
						);

						?>
					</span>
					<?php

					self::render_last_purge( $last_purge );

					if ( $files_on ) :

					?>

					<div class="oa-perf-actions">
						<button type="button" class="button" data-oa-perf-action="oa_perf_purge" data-scope="files" data-result="oa-perf-purge-result">
							<?php esc_html_e( 'Clear minified files', 'octave-addons' ); ?>
						</button>
					</div>

					<?php

					endif;

					?>
				</div>

				<div class="oa-perf-card">
					<span class="oa-panel-kicker"><?php esc_html_e( 'Cloudflare', 'octave-addons' ); ?></span>
					<?php

					if ( ! $cf_enabled ) :

					?>

					<strong><?php esc_html_e( 'Off', 'octave-addons' ); ?></strong>

					<?php

					elseif ( ! Octave_Addons_Perf_Cloudflare::is_configured() ) :

					?>

					<strong><?php esc_html_e( 'Needs credentials', 'octave-addons' ); ?></strong>

					<?php

					else :

					?>

					<strong><?= ! empty( $cf_status['ok'] ) ? esc_html__( 'Connected', 'octave-addons' ) : esc_html__( 'Configured', 'octave-addons' ); ?></strong>
					<span><?= esc_html( (string) ( $cf_status['message'] ?? __( 'Not tested yet.', 'octave-addons' ) ) ); ?></span>
					<div class="oa-perf-actions">
						<button type="button" class="button" data-oa-perf-action="oa_perf_purge" data-scope="cloudflare" data-confirm="cfEverything" data-result="oa-perf-purge-result">
							<?php esc_html_e( 'Purge Cloudflare', 'octave-addons' ); ?>
						</button>
					</div>
					<div class="oa-perf-inline-form">
						<label for="oa-perf-purge-url"><?php esc_html_e( 'Purge one URL', 'octave-addons' ); ?></label>
						<input type="url" id="oa-perf-purge-url" class="regular-text" placeholder="<?= esc_attr( home_url( '/' ) ); ?>">
						<button type="button" class="button" data-oa-perf-action="oa_perf_purge" data-scope="url" data-input="oa-perf-purge-url" data-result="oa-perf-purge-result">
							<?php esc_html_e( 'Purge URL', 'octave-addons' ); ?>
						</button>
					</div>

					<?php

					endif;

					?>
				</div>

				<div class="oa-perf-card">
					<span class="oa-panel-kicker"><?php esc_html_e( 'Self-hosted fonts', 'octave-addons' ); ?></span>
					<strong>
						<?php

						printf(
							/* translators: %d: number of stylesheets. */
							esc_html( _n( '%d stylesheet', '%d stylesheets', count( $fonts ), 'octave-addons' ) ),
							count( $fonts )
						);

						?>
					</strong>
					<span>
						<?php

						printf(
							/* translators: %d: number of font files. */
							esc_html( _n( '%d font file cached', '%d font files cached', $font_files, 'octave-addons' ) ),
							(int) $font_files
						);

						?>
					</span>
				</div>

				<div class="oa-perf-card">
					<span class="oa-panel-kicker"><?php esc_html_e( 'Database cleanup', 'octave-addons' ); ?></span>
					<?php

					if ( empty( $db_last['time'] ) ) :

					?>

					<strong><?php esc_html_e( 'Never run', 'octave-addons' ); ?></strong>

					<?php

					else :

					?>

					<strong><?= esc_html( self::time_ago( (int) $db_last['time'] ) ); ?></strong>
					<span>
						<?php

						printf(
							/* translators: %d: number of rows removed. */
							esc_html__( '%d entries removed', 'octave-addons' ),
							(int) array_sum( (array) ( $db_last['totals'] ?? [] ) )
						);

						?>
					</span>

					<?php

					endif;

					?>
				</div>

			</div>

			<div id="oa-perf-purge-result" data-oa-perf-result role="status" aria-live="polite"></div>

			<section class="oa-perf-section">
				<div class="oa-perf-section-copy">
					<h3><?php esc_html_e( 'Troubleshooting', 'octave-addons' ); ?></h3>
					<p>
						<?php

						printf(
							/* translators: %s: query argument. */
							esc_html__( 'Add %s to any page while logged in as an administrator to see it without performance optimisations.', 'octave-addons' ),
							'<code>?' . esc_html( Octave_Addons_Perf_Context::BYPASS_ARG ) . '=1</code>'
						);

						?>
					</p>
				</div>
				<div class="oa-perf-inline-form">
					<label for="oa-perf-scan-url"><?php esc_html_e( 'Scan a page as a logged-out visitor', 'octave-addons' ); ?></label>
					<input type="url" id="oa-perf-scan-url" class="regular-text" value="<?= esc_attr( home_url( '/' ) ); ?>">
					<button type="button" class="button" data-oa-perf-action="oa_perf_scan" data-input="oa-perf-scan-url" data-result="oa-perf-scan-result">
						<?php esc_html_e( 'Run diagnostics', 'octave-addons' ); ?>
					</button>
				</div>
				<div id="oa-perf-scan-result" data-oa-perf-result role="status" aria-live="polite"></div>

				<?php

				if ( ! empty( $errors ) ) :

				?>

				<h4><?php esc_html_e( 'Recent optimisation errors', 'octave-addons' ); ?></h4>
				<ul class="oa-perf-log">
					<?php

					foreach ( $errors as $error ) :

					?>

					<li>
						<strong><?= esc_html( (string) $error['feature'] ); ?></strong>
						<span><?= esc_html( (string) $error['message'] ); ?></span>
						<code><?= esc_html( (string) $error['context'] ); ?></code>
						<time><?= esc_html( self::time_ago( (int) $error['time'] ) ); ?></time>
					</li>

					<?php

					endforeach;

					?>
				</ul>
				<button type="button" class="button button-small" data-oa-perf-action="oa_perf_log_clear" data-result="oa-perf-log-result">
					<?php esc_html_e( 'Clear error log', 'octave-addons' ); ?>
				</button>
				<div id="oa-perf-log-result" data-oa-perf-result role="status" aria-live="polite"></div>

				<?php

				endif;

				?>
			</section>

		</div>

		<?php

	}

	/*
	RENDER LAST PURGE
	-- One line per layer from the last manual purge
	---------------------------------------------------------- */

	protected static function render_last_purge( array $last ): void {

		if ( empty( $last['time'] ) ) {

			echo '<span>' . esc_html__( 'No manual purge yet.', 'octave-addons' ) . '</span>';

			return;

		}

		?>

		<span>
			<?php

			printf(
				/* translators: 1: purge type, 2: relative time. */
				esc_html__( 'Last: %1$s, %2$s', 'octave-addons' ),
				esc_html( (string) $last['label'] ),
				esc_html( self::time_ago( (int) $last['time'] ) )
			);

			?>
		</span>
		<ul class="oa-perf-layers">
			<?php

			foreach ( (array) ( $last['report'] ?? [] ) as $layer ) :

			?>

			<li class="is-<?= esc_attr( (string) ( $layer['status'] ?? 'skipped' ) ); ?>"><?= esc_html( (string) ( $layer['label'] ?? '' ) ); ?></li>

			<?php

			endforeach;

			?>
		</ul>

		<?php

	}

	public static function time_ago( int $time ): string {

		/* translators: %s: human readable time difference. */
		return sprintf( __( '%s ago', 'octave-addons' ), human_time_diff( $time, time() ) );

	}

	/*
	AJAX PURGE
	---------------------------------------------------------- */

	public static function ajax_purge(): void {

		self::guard();

		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all';
		$url   = isset( $_POST['value'] ) ? esc_url_raw( wp_unslash( $_POST['value'] ) ) : '';

		if ( 'url' === $scope && empty( Octave_Addons_Perf_Cache::normalize_urls( [ $url ] ) ) ) {

			wp_send_json_error( [ 'message' => __( 'Enter a full URL on this site.', 'octave-addons' ) ] );

		}

		wp_send_json_success( [ 'layers' => array_values( self::purge_scope( $scope, $url ) ) ] );

	}

	/*
	PURGE SCOPE
	-- One manual purge: a single URL, the minified files, Cloudflare alone, or
	-- every layer. Shared by the Performance page and the admin bar
	---------------------------------------------------------- */

	protected static function purge_scope( string $scope, string $url = '' ): array {

		if ( 'url' === $scope ) {

			return Octave_Addons_Perf_Cache::purge_urls( [ $url ], 'manual' );

		}

		if ( 'cloudflare' === $scope ) {

			$label = __( 'Cloudflare', 'octave-addons' );

			if ( ! Octave_Addons_Perf_Cloudflare::is_configured() ) {

				return [ 'cloudflare' => [ 'label' => $label, 'status' => 'error', 'message' => __( 'Not configured: add a Zone ID and API token.', 'octave-addons' ) ] ];

			}

			$result = Octave_Addons_Perf_Cloudflare::purge_everything();

			return [ 'cloudflare' => [ 'label' => $label, 'status' => $result['ok'] ? 'success' : 'error', 'message' => $result['message'] ] ];

		}

		return Octave_Addons_Perf_Cache::purge_all( 'files' === $scope ? 'files' : 'all', 'manual' );

	}

	/*
	AJAX SCAN
	-- Requests a page as a logged-out visitor with a one-time token, then reads
	-- back what each module decided while rendering it
	---------------------------------------------------------- */

	public static function ajax_scan(): void {

		self::guard();

		$url = isset( $_POST['value'] ) ? esc_url_raw( wp_unslash( $_POST['value'] ) ) : home_url( '/' );

		if ( empty( Octave_Addons_Perf_Cache::normalize_urls( [ $url ] ) ) ) {

			wp_send_json_error( [ 'message' => __( 'Enter a full URL on this site.', 'octave-addons' ) ] );

		}

		$token    = Octave_Addons_Perf_Log::start_scan();
		$response = wp_remote_get( add_query_arg( [ Octave_Addons_Perf_Log::SCAN_ARG => $token, 'oa_cb' => time() ], $url ), [
			'timeout'   => 20,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'cookies'   => [],
		] );

		if ( is_wp_error( $response ) ) {

			wp_send_json_error( [ 'message' => sprintf(
				/* translators: %s: error message. */
				__( 'The site could not request its own page: %s', 'octave-addons' ),
				$response->get_error_message()
			) ] );

		}

		$scan = Octave_Addons_Perf_Log::read_scan( $token );

		if ( empty( $scan ) ) {

			$code = (int) wp_remote_retrieve_response_code( $response );

			wp_send_json_error( [ 'message' => 200 === $code
				? __( 'The page answered but no report was recorded. A server or CDN cache may have answered instead of WordPress; exclude URLs containing oa_perf_scan from it and try again.', 'octave-addons' )
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The page answered with HTTP %d, so no report was recorded. Check the URL opens for a logged-out visitor.', 'octave-addons' ),
					$code
				) ] );

		}

		wp_send_json_success( $scan );

	}

	/*
	LOCAL PATH
	-- Maps a same-origin asset URL to a readable file inside the WordPress
	-- install, or '' for anything dynamic, missing or outside it
	---------------------------------------------------------- */

	public static function local_path( string $url ): string {

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '' === $path ) {

			return '';

		}

		$candidates = [
			[ (string) wp_parse_url( content_url(), PHP_URL_PATH ), WP_CONTENT_DIR ],
			[ (string) wp_parse_url( site_url(), PHP_URL_PATH ), untrailingslashit( ABSPATH ) ],
		];

		foreach ( $candidates as [ $prefix, $dir ] ) {

			$prefix = untrailingslashit( $prefix );

			if ( '' !== $prefix && 0 !== strpos( $path, $prefix . '/' ) ) {

				continue;

			}

			$file = realpath( $dir . substr( $path, strlen( $prefix ) ) );
			$root = realpath( untrailingslashit( ABSPATH ) );
			$wpc  = realpath( WP_CONTENT_DIR );

			if ( false === $file || ! is_file( $file ) || ! is_readable( $file ) ) {

				continue;

			}

			if ( ( $root && 0 === strpos( $file, $root . DIRECTORY_SEPARATOR ) ) || ( $wpc && 0 === strpos( $file, $wpc . DIRECTORY_SEPARATOR ) ) ) {

				return $file;

			}

		}

		return '';

	}

	public static function ajax_log_clear(): void {

		self::guard();

		Octave_Addons_Perf_Log::clear();

		wp_send_json_success( [ 'message' => __( 'Error log cleared.', 'octave-addons' ) ] );

	}

	/*
	AJAX CLOUDFLARE
	---------------------------------------------------------- */

	public static function ajax_cf_test(): void {

		self::guard();

		// Unsaved field values, so a connection can be tested before saving. A blank token falls back to the saved one.
		$zone_id = isset( $_POST['zone_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['zone_id'] ) ) ) : null;
		$token   = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		$result  = Octave_Addons_Perf_Cloudflare::test( $zone_id, '' === $token ? null : $token );

		self::send_result( $result['ok'], [ 'message' => $result['message'] ] );

	}

	/*
	AJAX FONTS REFRESH
	---------------------------------------------------------- */

	public static function ajax_fonts_refresh(): void {

		self::guard();

		$results = Octave_Addons_Perf_Google_Fonts::refresh();

		if ( empty( $results ) ) {

			wp_send_json_success( [ 'message' => __( 'No Google Fonts were found on the home page. Fonts on other pages are picked up as logged-out visitors view them, or run diagnostics on one of those pages, then refresh again.', 'octave-addons' ) ] );

		}

		$lines = [];
		$ok    = true;

		foreach ( $results as $source => $result ) {

			$ok      = $ok && $result['ok'];
			$lines[] = $result['message'] . ' (' . $source . ')';

		}

		$payload = [ 'message' => implode( "\n", $lines ) ];

		self::send_result( $ok, $payload );

	}

	/*
	AJAX DATABASE
	---------------------------------------------------------- */

	public static function ajax_db_counts(): void {

		self::guard();

		$counts = [];

		foreach ( array_keys( Octave_Addons_Perf_Cleanup::items() ) as $item ) {

			$counts[ $item ] = Octave_Addons_Perf_Cleanup::count( $item );

		}

		wp_send_json_success( [ 'counts' => $counts ] );

	}

	public static function ajax_db_run(): void {

		self::guard();

		$item   = isset( $_POST['item'] ) ? sanitize_key( wp_unslash( $_POST['item'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		if ( ! Octave_Addons_Perf_Cleanup::is_item( $item ) || empty( $_POST['confirmed'] ) ) {

			wp_send_json_error( [ 'message' => __( 'Unknown or unconfirmed cleanup.', 'octave-addons' ) ] );

		}

		$batch = Octave_Addons_Perf_Cleanup::run_batch( $item, Octave_Addons_Perf_Cleanup::BATCH, $offset );

		if ( 0 === $batch['remaining'] || 0 === $batch['done'] ) {

			$last                    = Octave_Addons_Perf_Cleanup::last();
			$totals                  = 'manual' === ( $last['source'] ?? '' ) && ( time() - (int) $last['time'] ) < HOUR_IN_SECONDS ? (array) $last['totals'] : [];
			$totals[ $item ]         = (int) ( $totals[ $item ] ?? 0 ) + $batch['done'];

			Octave_Addons_Perf_Cleanup::record( $totals, 'manual' );

		}

		wp_send_json_success( $batch );

	}

	/*
	ADMIN BAR
	-- Shortcut for administrators to the Performance page and its purges
	---------------------------------------------------------- */

	public static function admin_bar( WP_Admin_Bar $bar ): void {

		if ( ! current_user_can( 'manage_options' ) ) {

			return;

		}

		$base = add_query_arg( [
			'action'   => self::BAR_ACTION,
			'_wpnonce' => wp_create_nonce( self::BAR_ACTION ),
		], admin_url( 'admin-post.php' ) );

		$bar->add_node( [
			'id'    => 'oa-performance',
			'title' => esc_html__( 'Performance', 'octave-addons' ),
			'href'  => Octave_Addons_Admin::entry_url( Octave_Addons_Perf::GROUP ),
		] );

		$bar->add_node( [
			'parent' => 'oa-performance',
			'id'     => 'oa-performance-clear',
			'title'  => esc_html__( 'Clear Performance Cache', 'octave-addons' ),
			'href'   => add_query_arg( 'scope', 'all', $base ),
		] );

		if ( ! empty( Octave_Addons_Perf::settings( 'performance-files' )['enabled'] ) ) {

			$bar->add_node( [
				'parent' => 'oa-performance',
				'id'     => 'oa-performance-files',
				'title'  => esc_html__( 'Clear minified files', 'octave-addons' ),
				'href'   => add_query_arg( 'scope', 'files', $base ),
			] );

		}

		if ( ! empty( Octave_Addons_Perf::settings( 'performance-cloudflare' )['enabled'] ) && Octave_Addons_Perf_Cloudflare::is_configured() ) {

			$bar->add_node( [
				'parent' => 'oa-performance',
				'id'     => 'oa-performance-cloudflare',
				'title'  => esc_html__( 'Purge Cloudflare', 'octave-addons' ),
				'href'   => add_query_arg( 'scope', 'cloudflare', $base ),
			] );

		}

		if ( ! is_admin() ) {

			$request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

			$bar->add_node( [
				'parent' => 'oa-performance',
				'id'     => 'oa-performance-url',
				'title'  => esc_html__( 'Purge this URL', 'octave-addons' ),
				'href'   => add_query_arg( [ 'scope' => 'url', 'url' => rawurlencode( home_url( $request ) ) ], $base ),
			] );

		}

	}

	/*
	HANDLE ADMIN BAR
	-- Runs the purge, keeps the report for a one-time notice, and returns the
	-- administrator to the page they came from
	---------------------------------------------------------- */

	public static function handle_admin_bar(): void {

		check_admin_referer( self::BAR_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {

			wp_die( esc_html__( 'You do not have permission to do that.', 'octave-addons' ), '', [ 'response' => 403 ] );

		}

		$scope  = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'all';
		$url    = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '';
		$report = self::purge_scope( $scope, $url );

		set_transient( 'oa_perf_notice_' . get_current_user_id(), $report, MINUTE_IN_SECONDS );

		wp_safe_redirect( wp_get_referer() ?: admin_url() );

		exit;

	}

	/*
	PRINT PURGE NOTICE
	---------------------------------------------------------- */

	public static function print_purge_notice(): void {

		$key    = 'oa_perf_notice_' . get_current_user_id();
		$report = get_transient( $key );

		if ( ! is_array( $report ) ) {

			return;

		}

		delete_transient( $key );

		$failed = false;

		foreach ( $report as $layer ) {

			$failed = $failed || 'error' === ( $layer['status'] ?? '' );

		}

		?>

		<div class="notice <?= $failed ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
			<p><strong><?php esc_html_e( 'Performance cache', 'octave-addons' ); ?></strong></p>
			<ul>
				<?php

				foreach ( $report as $layer ) :

				?>

				<li><?= esc_html( ( $layer['label'] ?? '' ) . ': ' . ( $layer['message'] ?? '' ) ); ?></li>

				<?php

				endforeach;

				?>
			</ul>
		</div>

		<?php

	}

}
