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
		'oa_perf_imagify_sync'   => 'ajax_imagify_sync',
		'oa_perf_imagify_test'   => 'ajax_imagify_test',
		'oa_perf_static_test'    => 'ajax_static_test',
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
		add_action( 'wp_footer', [ __CLASS__, 'print_frontend_notice' ], 999 );

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
				'dbText'           => __( 'The selected items are deleted for good and cannot be brought back. Make a database backup first if you might need any of them.', 'octave-addons' ),
				'dbAction'         => __( 'Delete permanently', 'octave-addons' ),
				'cfEverythingTitle'  => __( 'Clear everything from Cloudflare?', 'octave-addons' ),
				'cfEverythingText'   => __( 'Cloudflare forgets every saved copy of this site, so the next few visitors will load pages a little more slowly while it saves fresh copies.', 'octave-addons' ),
				'cfEverythingAction' => __( 'Clear everything', 'octave-addons' ),
				'dbNothing'        => __( 'Select at least one item to clean up.', 'octave-addons' ),
				/* translators: 1: item label, 2: number removed so far. */
				'dbProgress'       => __( '%1$s: %2$d removed so far…', 'octave-addons' ),
				'dbDone'           => __( 'Cleanup finished.', 'octave-addons' ),
				'scanEmpty'        => __( 'Octave did not need to change anything on that page.', 'octave-addons' ),
				'scanBypass'       => __( 'Octave left that page as it was. Reason:', 'octave-addons' ),
				'scanScripts'      => __( 'Scripts', 'octave-addons' ),
				'scanMedia'        => __( 'Media', 'octave-addons' ),
				'scanFonts'        => __( 'Google Fonts', 'octave-addons' ),
				'scanQueued'       => __( 'not copied yet, it will be shortly', 'octave-addons' ),
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
				<p><?php esc_html_e( 'Some speed features need WordPress 6.2 or newer. Until WordPress is updated, lazy loading, script delay and font changes are paused.', 'octave-addons' ); ?></p>
			</div>

			<?php

			endif;

			?>

			<?php

			$session_warning = Octave_Addons_Perf_Sessions::warning();

			if ( '' !== $session_warning ) :

			?>

			<div class="notice notice-error inline oa-inline-notice">
				<p><strong><?= esc_html( $session_warning ); ?></strong></p>
			</div>

			<?php

			endif;

			?>

			<div class="oa-perf-status-grid">

				<div class="oa-perf-card">
					<span class="oa-panel-kicker"><?php esc_html_e( 'Smaller copies of files', 'octave-addons' ); ?></span>
					<strong><?= esc_html( size_format( $size['bytes'] ) ?: '0 B' ); ?></strong>
					<span>
						<?php

						printf(
							/* translators: 1: number of files, 2: cache generation number. */
							esc_html__( '%1$d files, version %2$d', 'octave-addons' ),
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
							<?php esc_html_e( 'Clear smaller copies', 'octave-addons' ); ?>
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

					<strong><?php esc_html_e( 'Needs setting up', 'octave-addons' ); ?></strong>

					<?php

					else :

					?>

					<strong><?= ! empty( $cf_status['ok'] ) ? esc_html__( 'Connected', 'octave-addons' ) : esc_html__( 'Configured', 'octave-addons' ); ?></strong>
					<span><?= esc_html( (string) ( $cf_status['message'] ?? __( 'Not tested yet.', 'octave-addons' ) ) ); ?></span>
					<div class="oa-perf-actions">
						<button type="button" class="button" data-oa-perf-action="oa_perf_purge" data-scope="cloudflare" data-confirm="cfEverything" data-result="oa-perf-purge-result">
							<?php esc_html_e( 'Clear Cloudflare', 'octave-addons' ); ?>
						</button>
					</div>
					<div class="oa-perf-inline-form">
						<label for="oa-perf-purge-url"><?php esc_html_e( 'Clear one page', 'octave-addons' ); ?></label>
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
					<span class="oa-panel-kicker"><?php esc_html_e( 'Fonts on your site', 'octave-addons' ); ?></span>
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
							esc_html( _n( '%d font file copied', '%d font files copied', $font_files, 'octave-addons' ) ),
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
							esc_html__( '%d items removed', 'octave-addons' ),
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

			<?php

			self::render_page_cache();
			self::render_ownership();
			self::render_static_delivery();
			self::render_breakdance_audit();
			self::render_divi_audit();

			?>

			<section class="oa-perf-section">
				<div class="oa-perf-section-copy">
					<h3><?php esc_html_e( 'Troubleshooting', 'octave-addons' ); ?></h3>
					<p>
						<?php

						printf(
							/* translators: %s: query argument. */
							esc_html__( 'To see any page without Octave\'s speed changes, add %s to its address while logged in as an administrator.', 'octave-addons' ),
							'<code>?' . esc_html( Octave_Addons_Perf_Context::BYPASS_ARG ) . '=1</code>'
						);

						?>
					</p>
					<p>
						<?php

						printf(
							/* translators: %s: query argument with its key. */
							esc_html__( 'For developers: add %s to a page\'s address while logged out to see how long Octave spent on it (in the Server-Timing header). Keep this key private.', 'octave-addons' ),
							'<code>?' . esc_html( Octave_Addons_Perf_Html::TIMING_ARG . '=' . Octave_Addons_Perf_Html::timing_key() ) . '</code>'
						);

						?>
					</p>
				</div>
				<div class="oa-perf-inline-form">
					<label for="oa-perf-scan-url"><?php esc_html_e( 'Check a page as a visitor sees it', 'octave-addons' ); ?></label>
					<input type="url" id="oa-perf-scan-url" class="regular-text" value="<?= esc_attr( home_url( '/' ) ); ?>">
					<button type="button" class="button" data-oa-perf-action="oa_perf_scan" data-input="oa-perf-scan-url" data-result="oa-perf-scan-result">
						<?php esc_html_e( 'Check page', 'octave-addons' ); ?>
					</button>
				</div>
				<div id="oa-perf-scan-result" data-oa-perf-result role="status" aria-live="polite"></div>

				<?php

				if ( ! empty( $errors ) ) :

				?>

				<h4><?php esc_html_e( 'Recent problems', 'octave-addons' ); ?></h4>
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
					<?php esc_html_e( 'Clear this list', 'octave-addons' ); ?>
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
	RENDER PAGE CACHE
	-- Which page cache serves the site, what can stop it keeping pages,
	-- the last full and targeted clears with any failed layer, and how the
	-- background refill is going
	---------------------------------------------------------- */

	protected static function render_page_cache(): void {

		$warm     = Octave_Addons_Perf_Page_Cache::state();
		$last     = (array) ( $warm['log'][0] ?? [] );
		$clears   = Octave_Addons_Perf_Cache::last_clears();
		$sessions = class_exists( 'Octave_Addons' ) && Octave_Addons::is_breakdance_active() ? Octave_Addons_Perf_Sessions::state() : [];
		$disk     = Octave_Addons_Perf_Disk_Cache::status();
		$kinds    = [
			'full'     => __( 'Last full clear', 'octave-addons' ),
			'targeted' => __( 'Last single-page clear', 'octave-addons' ),
		];

		?>

		<section class="oa-perf-section">
			<div class="oa-perf-section-copy">
				<h3><?php esc_html_e( 'Page cache', 'octave-addons' ); ?></h3>
				<p><?php esc_html_e( 'Saved copies of your pages make them open much faster. When you change something, the affected pages are cleared and quietly rebuilt. Clear cache in the toolbar clears everything at once.', 'octave-addons' ); ?></p>
			</div>
			<table class="widefat striped oa-perf-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Page cache', 'octave-addons' ); ?></th>
						<td><?= esc_html( Octave_Addons_Perf_Page_Cache::active_label() ); ?></td>
					</tr>

					<?php

					if ( 'off' !== $disk['code'] ) :

					?>

					<tr>
						<th scope="row"><?php esc_html_e( 'Octave page cache', 'octave-addons' ); ?></th>
						<td><?= esc_html( $disk['message'] ); ?></td>
					</tr>

					<?php

					endif;

					if ( ! empty( $sessions ) ) :

					?>

					<tr>
						<th scope="row"><?php esc_html_e( 'Breakdance visit counting', 'octave-addons' ); ?></th>
						<td>
							<?php

							if ( 'unused' === $sessions['state'] && Octave_Addons_Perf::is_enabled() ) {

								esc_html_e( 'Off. Your designs do not show anything based on how many pages or visits someone has made, so Breakdance does not need to track visitors, and pages can be saved and served quickly.', 'octave-addons' );

							} elseif ( 'used' === $sessions['state'] ) {

								esc_html_e( 'On. A design shows something based on how many pages or visits someone has made, so Breakdance tracks every visitor and pages cannot be saved for quick loading.', 'octave-addons' );

							} else {

								esc_html_e( 'On. Octave could not confirm your designs do not rely on visit counts, so Breakdance keeps tracking visitors and pages cannot be saved for quick loading.', 'octave-addons' );

							}

							?>
						</td>
					</tr>

					<?php

					endif;

					?>

					<tr>
						<th scope="row"><?php esc_html_e( 'Also clears', 'octave-addons' ); ?></th>
						<td><?= esc_html( implode( ', ', array_merge( Octave_Addons_Perf_Disk_Cache::is_active() ? [ Octave_Addons_Perf_Disk_Cache::LABEL ] : [], array_keys( Octave_Addons_Perf_Page_Cache::connectors() ) ) ) ?: __( 'Nothing else to clear', 'octave-addons' ) ); ?></td>
					</tr>

					<?php

					foreach ( $kinds as $kind => $label ) :

						$clear = (array) ( $clears[ $kind ] ?? [] );

					?>

					<tr>
						<th scope="row"><?= esc_html( $label ); ?></th>
						<td>
							<?php

							if ( empty( $clear['time'] ) ) {

								esc_html_e( 'None yet.', 'octave-addons' );

							} else {

								/* translators: 1: relative time, 2: reason code. */
								echo esc_html( sprintf( __( '%1$s (%2$s)', 'octave-addons' ), self::time_ago( (int) $clear['time'] ), (string) $clear['reason'] ) );

								if ( ! empty( $clear['count'] ) ) {

									/* translators: %d: number of URLs. */
									echo esc_html( ' · ' . sprintf( _n( '%d URL', '%d URLs', (int) $clear['count'], 'octave-addons' ), (int) $clear['count'] ) );

								}

								foreach ( (array) ( $clear['failed'] ?? [] ) as $failure ) {

									echo '<br><span class="oa-perf-failed">' . esc_html( '⚠ ' . (string) $failure ) . '</span>';

								}

							}

							?>
						</td>
					</tr>

					<?php

					endforeach;

					?>

					<tr>
						<th scope="row"><?php esc_html_e( 'Rebuilding pages', 'octave-addons' ); ?></th>
						<td>
							<?php

							if ( ! empty( $warm['queue'] ) ) {

								/* translators: %d: number of URLs. */
								echo esc_html( sprintf( _n( 'In progress: %d page left', 'In progress: %d pages left', count( $warm['queue'] ), 'octave-addons' ), count( $warm['queue'] ) ) );

							} elseif ( ! empty( $last['time'] ) ) {

								/* translators: 1: relative time, 2: pages warmed, 3: failures. */
								echo esc_html( sprintf( __( 'Last run %1$s: %2$d pages rebuilt, %3$d failed', 'octave-addons' ), self::time_ago( (int) $last['time'] ), (int) $last['done'], count( (array) $last['failed'] ) ) );

								foreach ( (array) $last['failed'] as $url => $error ) {

									echo '<br><code>' . esc_html( (string) $url ) . '</code> ' . esc_html( (string) $error );

								}

							} else {

								echo esc_html( Octave_Addons_Perf_Page_Cache::should_warm() ? __( 'Waiting. Runs after the next clear.', 'octave-addons' ) : __( 'Not needed: there are no saved pages or files to rebuild.', 'octave-addons' ) );

							}

							?>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="oa-help"><?php esc_html_e( 'Use Check page below to see whether a page is being served from a saved copy, and if not, why.', 'octave-addons' ); ?></p>
		</section>

		<?php

	}

	/*
	RENDER OWNERSHIP
	-- Who owns each optimisation, including lazy loading per kind of media,
	-- with any duplicate optimisation flagged
	---------------------------------------------------------- */

	protected static function render_ownership(): void {

		$report = Octave_Addons_Perf_Owners::report();
		$lazy   = class_exists( 'Octave_Addons_Module_Breakdance_Lazy_Load' ) ? Octave_Addons_Module_Breakdance_Lazy_Load::ownership() : [];
		$states = [
			'none'     => __( 'Not in use', 'octave-addons' ),
			'octave'   => __( 'Octave', 'octave-addons' ),
			'external' => __( 'Another plugin does this', 'octave-addons' ),
			'deferred' => __( 'Another plugin does this, so Octave holds back', 'octave-addons' ),
			'conflict' => __( 'Two plugins are doing this: choose one', 'octave-addons' ),
		];
		$media  = [
			'lazy_images'  => __( 'Images load as visitors scroll', 'octave-addons' ),
			'lazy_iframes' => __( 'Embeds load as visitors scroll', 'octave-addons' ),
			'lazy_videos'  => __( 'Videos load as visitors scroll', 'octave-addons' ),
		];

		?>

		<section class="oa-perf-section">
			<div class="oa-perf-section-copy">
				<h3><?php esc_html_e( 'Who does what', 'octave-addons' ); ?></h3>
				<p><?php esc_html_e( 'Each speed feature should be handled by just one plugin. Octave holds back wherever another plugin already does the same job, and flags it here if two other plugins are both doing it.', 'octave-addons' ); ?></p>
			</div>
			<table class="widefat striped oa-perf-table">
				<tbody>
					<?php

					foreach ( $media as $key => $label ) :

						if ( ! isset( $lazy[ $key ] ) ) {

							continue;

						}

						$status = (string) ( $report[ $key ]['status'] ?? '' );

					?>

					<tr class="<?= 'conflict' === $status ? 'oa-perf-conflict' : ''; ?>">
						<th scope="row"><?= esc_html( $label ); ?></th>
						<td><?= esc_html( $lazy[ $key ] ); ?></td>
						<td><?= esc_html( $states[ $status ] ?? '' ); ?></td>
					</tr>

					<?php

					endforeach;

					foreach ( $report as $feature => $row ) :

						if ( in_array( $feature, [ 'lazy_images', 'lazy_iframes' ], true ) && ! empty( $lazy ) ) {

							continue;

						}

					?>

					<tr class="<?= 'conflict' === $row['status'] ? 'oa-perf-conflict' : ''; ?>">
						<th scope="row"><?= esc_html( $row['label'] ); ?></th>
						<td><?= esc_html( implode( ', ', $row['owners'] ) ?: ( $row['octave'] ? __( 'Octave', 'octave-addons' ) : '—' ) ); ?></td>
						<td><?= esc_html( $states[ $row['status'] ] ?? '' ); ?></td>
					</tr>

					<?php

					endforeach;

					?>
				</tbody>
			</table>
		</section>

		<?php

	}

	/*
	RENDER STATIC DELIVERY
	-- Compression, browser caching, MIME types, CORS and Vary, checked on
	-- request so the page itself makes no outbound calls
	---------------------------------------------------------- */

	protected static function render_static_delivery(): void {

		?>

		<section class="oa-perf-section">
			<div class="oa-perf-section-copy">
				<h3><?php esc_html_e( 'How files are delivered', 'octave-addons' ); ?></h3>
				<p><?php esc_html_e( 'Checks a few of your site\'s files are compressed, kept by browsers between visits, and served correctly, including fonts and modern image formats. This only reports: Octave never changes your server.', 'octave-addons' ); ?></p>
			</div>
			<div class="oa-perf-actions">
				<button type="button" class="button" data-oa-perf-action="oa_perf_static_test" data-result="oa-perf-static-result"><?php esc_html_e( 'Check files', 'octave-addons' ); ?></button>
			</div>
			<div id="oa-perf-static-result" data-oa-perf-result role="status" aria-live="polite"></div>
		</section>

		<?php

	}

	/*
	RENDER DIVI AUDIT
	-- Divi's own performance settings, read only, with a link to them
	---------------------------------------------------------- */

	protected static function render_divi_audit(): void {

		$rows = Octave_Addons_Perf_Diagnostics::divi_audit();

		if ( empty( $rows ) ) {

			return;

		}

		?>

		<section class="oa-perf-section">
			<div class="oa-perf-section-copy">
				<h3><?php esc_html_e( 'Divi speed settings', 'octave-addons' ); ?></h3>
				<p>
					<?php esc_html_e( 'Divi\'s own speed settings, for reference. Octave never changes or repeats them.', 'octave-addons' ); ?>
					<a href="<?= esc_url( Octave_Addons_Perf_Diagnostics::divi_settings_url() ); ?>"><?php esc_html_e( 'Open Divi theme options', 'octave-addons' ); ?></a>
				</p>
			</div>
			<table class="widefat striped oa-perf-table">
				<tbody>
					<?php

					foreach ( $rows as $row ) :

					?>

					<tr>
						<th scope="row"><?= esc_html( $row['label'] ); ?></th>
						<td><?= esc_html( $row['status'] ); ?></td>
						<td><?= esc_html( $row['note'] ); ?></td>
					</tr>

					<?php

					endforeach;

					?>
				</tbody>
			</table>
		</section>

		<?php

	}

	/*
	RENDER BREAKDANCE AUDIT
	-- Breakdance's own performance settings, read only, with a link to them
	---------------------------------------------------------- */

	protected static function render_breakdance_audit(): void {

		$rows = Octave_Addons_Perf_Diagnostics::breakdance_audit();

		if ( empty( $rows ) ) {

			return;

		}

		?>

		<section class="oa-perf-section">
			<div class="oa-perf-section-copy">
				<h3><?php esc_html_e( 'Breakdance speed settings', 'octave-addons' ); ?></h3>
				<p>
					<?php esc_html_e( 'Breakdance\'s own speed settings, for reference. Octave never changes or repeats them.', 'octave-addons' ); ?>
					<a href="<?= esc_url( Octave_Addons_Perf_Diagnostics::breakdance_settings_url() ); ?>"><?php esc_html_e( 'Open Breakdance settings', 'octave-addons' ); ?></a>
				</p>
			</div>
			<table class="widefat striped oa-perf-table">
				<tbody>
					<?php

					foreach ( $rows as $row ) :

					?>

					<tr>
						<th scope="row"><?= esc_html( $row['label'] ); ?></th>
						<td><?= esc_html( $row['status'] ); ?></td>
						<td><?= esc_html( $row['note'] ); ?></td>
					</tr>

					<?php

					endforeach;

					?>
				</tbody>
			</table>
		</section>

		<?php

	}

	/*
	RENDER LAST PURGE
	-- One line per layer from the last manual purge
	---------------------------------------------------------- */

	protected static function render_last_purge( array $last ): void {

		if ( empty( $last['time'] ) ) {

			echo '<span>' . esc_html__( 'Not cleared by hand yet.', 'octave-addons' ) . '</span>';

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

			wp_send_json_error( [ 'message' => __( 'Enter the full address of a page on this site.', 'octave-addons' ) ] );

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

				return [ 'cloudflare' => [ 'label' => $label, 'status' => 'error', 'message' => __( 'Not set up yet: add your Zone ID and API token.', 'octave-addons' ) ] ];

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

			wp_send_json_error( [ 'message' => __( 'Enter the full address of a page on this site.', 'octave-addons' ) ] );

		}

		$token    = Octave_Addons_Perf_Log::start_scan();
		$started  = microtime( true );
		$response = wp_remote_get( add_query_arg( [ Octave_Addons_Perf_Log::SCAN_ARG => $token, 'oa_cb' => time() ], $url ), [
			'timeout'   => 20,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'cookies'   => [],
		] );

		if ( is_wp_error( $response ) ) {

			wp_send_json_error( [ 'message' => sprintf(
				/* translators: %s: error message. */
				__( 'Your site could not open its own page: %s', 'octave-addons' ),
				$response->get_error_message()
			) ] );

		}

		$scan_ms = ( microtime( true ) - $started ) * 1000;
		$scan    = Octave_Addons_Perf_Log::read_scan( $token );

		if ( empty( $scan ) ) {

			$code = (int) wp_remote_retrieve_response_code( $response );

			wp_send_json_error( [ 'message' => 200 === $code
				? __( 'The page opened, but Octave could not check it, probably because a saved copy was shown instead. Ask your host to skip saved copies for addresses containing oa_perf_scan, then try again.', 'octave-addons' )
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The page could not be checked (error %d). Make sure it opens for someone who is not logged in.', 'octave-addons' ),
					$code
				) ] );

		}

		// The same page as an ordinary visitor, to see what a page cache or CDN answers.
		$started = microtime( true );
		$plain   = wp_remote_get( $url, [
			'timeout'   => 20,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'cookies'   => [],
		] );

		$scan['details'] = Octave_Addons_Perf_Diagnostics::scan_details( $url, $scan, $scan_ms, $plain, ( microtime( true ) - $started ) * 1000 );

		wp_send_json_success( $scan );

	}

	/*
	AJAX STATIC TEST
	---------------------------------------------------------- */

	public static function ajax_static_test(): void {

		self::guard();

		wp_send_json_success( [ 'message' => implode( "\n", Octave_Addons_Perf_Diagnostics::static_delivery() ) ] );

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

			wp_send_json_success( [ 'message' => __( 'No Google Fonts were found on your home page. Fonts on other pages are found as visitors browse, or use Check page on one of them, then refresh again.', 'octave-addons' ) ] );

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
	AJAX IMAGIFY
	-- Synchronise or repair Imagify's delivery, or test it from outside
	---------------------------------------------------------- */

	public static function ajax_imagify_sync(): void {

		self::guard();

		$result = Octave_Addons_Module_Performance_Imagify::sync_now();

		self::send_result( $result['ok'], [ 'message' => $result['message'] ] );

	}

	public static function ajax_imagify_test(): void {

		self::guard();

		$result = Octave_Addons_Perf_Imagify::test_delivery();

		self::send_result( $result['ok'], [ 'message' => $result['message'] ] );

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

			wp_send_json_error( [ 'message' => __( 'That cleanup was not recognised or not confirmed.', 'octave-addons' ) ] );

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
	-- Only while a Performance feature is switched on, and only for
	-- administrators: "Performance", linking to its settings, with one
	-- "Clear cache" action. Per-layer controls stay on the settings page
	---------------------------------------------------------- */

	public static function admin_bar( WP_Admin_Bar $bar ): void {

		if ( ! Octave_Addons_Perf::is_enabled() || ! current_user_can( 'manage_options' ) ) {

			return;

		}

		$bar->add_node( [
			'id'    => 'oa-performance',
			'title' => esc_html__( 'Performance', 'octave-addons' ),
			'href'  => Octave_Addons_Admin::entry_url( Octave_Addons_Perf::GROUP ),
		] );

		$bar->add_node( [
			'parent' => 'oa-performance',
			'id'     => 'oa-performance-clear',
			'title'  => esc_html__( 'Clear cache', 'octave-addons' ),
			'href'   => self::clear_url(),
		] );

	}

	public static function clear_url(): string {

		return add_query_arg( [
			'action'   => self::BAR_ACTION,
			'_wpnonce' => wp_create_nonce( self::BAR_ACTION ),
		], admin_url( 'admin-post.php' ) );

	}

	/*
	HANDLE ADMIN BAR
	-- Checks the nonce and capability, clears every active layer, keeps the
	-- report for a one-time notice and returns the administrator to the
	-- page they came from
	---------------------------------------------------------- */

	public static function handle_admin_bar(): void {

		self::clear_from_toolbar();

		wp_safe_redirect( wp_get_referer() ?: admin_url() );

		exit;

	}

	public static function clear_from_toolbar(): array {

		check_admin_referer( self::BAR_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {

			wp_die( esc_html__( 'You do not have permission to do that.', 'octave-addons' ), '', [ 'response' => 403 ] );

		}

		$report = Octave_Addons_Perf_Cache::purge_all( 'all', 'manual' );

		set_transient( 'oa_perf_notice_' . get_current_user_id(), $report, MINUTE_IN_SECONDS );

		return $report;

	}

	/*
	NOTICE TEXT
	-- "Cache cleared.", plus one short line naming any layer that failed
	---------------------------------------------------------- */

	public static function notice_text( array $report ): array {

		$failed = [];

		foreach ( $report as $layer ) {

			if ( 'error' === ( $layer['status'] ?? '' ) ) {

				$failed[] = (string) ( $layer['label'] ?? '' );

			}

		}

		return [
			'message' => __( 'Cache cleared.', 'octave-addons' ),
			/* translators: %s: names of the cache layers that failed. */
			'warning' => empty( $failed ) ? '' : sprintf( __( 'Could not clear: %s. See Performance for details.', 'octave-addons' ), implode( ', ', array_filter( $failed ) ) ),
		];

	}

	public static function print_frontend_notice(): void {

		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {

			self::print_purge_notice();

		}

	}

	/*
	PRINT PURGE NOTICE
	-- Shown once after the toolbar action, in the admin or, for an
	-- administrator who cleared from the site, at the foot of the page
	---------------------------------------------------------- */

	public static function print_purge_notice(): void {

		$key    = 'oa_perf_notice_' . get_current_user_id();
		$report = get_transient( $key );

		if ( ! is_array( $report ) ) {

			return;

		}

		delete_transient( $key );

		$text = self::notice_text( $report );

		if ( is_admin() ) :

		?>

		<div class="notice <?= '' !== $text['warning'] ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
			<p><?= esc_html( $text['message'] ); ?><?= '' !== $text['warning'] ? ' ' . esc_html( $text['warning'] ) : ''; ?></p>
		</div>

		<?php

		else :

		?>

		<div class="oa-perf-toast" role="status" style="position:fixed;z-index:100000;right:16px;bottom:16px;max-width:360px;padding:12px 16px;border-radius:6px;background:#1d2327;color:#fff;font:14px/1.4 -apple-system,BlinkMacSystemFont,sans-serif;box-shadow:0 4px 16px rgba(0,0,0,.2)">
			<?= esc_html( $text['message'] ); ?><?= '' !== $text['warning'] ? ' ' . esc_html( $text['warning'] ) : ''; ?>
		</div>

		<?php

		endif;

	}

}
