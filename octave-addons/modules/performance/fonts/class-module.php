<?php

/*
PERFORMANCE: FONTS
-- Two independent tools:
-- Preload — outputs <link rel="preload" as="font"> for the font files an
-- administrator lists, and only those. Nothing is preloaded just because it
-- exists, since every preload competes with the page's other resources
-- Self-host Google Fonts — serves cached local copies of Google Fonts
-- stylesheets and their font files, so visitors never contact Google. Until
-- a stylesheet has been cached successfully, Google's URL is kept
-- Adobe Fonts, Typekit and other licensed services are never touched
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once dirname( __DIR__ ) . '/services/bootstrap.php';

class Octave_Addons_Module_Performance_Fonts extends Octave_Addons_Module {

	use Octave_Addons_Perf_Field_Rows;

	/** More selected preloads than this shows a warning. */
	public const PRELOAD_ADVICE = 3;

	protected const MIME = [
		'woff2' => 'font/woff2',
		'woff'  => 'font/woff',
	];

	protected array $settings = [];

	public function get_id(): string {

		return 'performance-fonts';

	}

	public function get_title(): string {

		return __( 'Fonts', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Preloads the font files you choose and can serve Google Fonts from this site instead of Google.', 'octave-addons' );

	}

	public function get_order(): int {

		return 50;

	}

	public function get_defaults(): array {

		return [
			'enabled'      => false,
			'preload'      => '',
			'self_host'    => false,
			'font_display' => 'swap',
			'refresh_days' => 30,
		];

	}

	/*
	SANITIZE
	-- Preload entries must be http(s) or root-relative WOFF2/WOFF URLs. Query
	-- strings are kept, since they can be part of the real file URL
	---------------------------------------------------------- */

	public function sanitize( $input ): array {

		$clean = parent::sanitize( $input );
		$urls  = [];

		foreach ( Octave_Addons_Perf::lines( wp_unslash( $input['preload'] ?? '' ) ) as $url ) {

			$url = self::clean_font_url( $url );

			if ( '' !== $url ) {

				$urls[ $url ] = true;

			}

		}

		$display = sanitize_key( $input['font_display'] ?? 'swap' );

		$clean['preload']      = implode( "\n", array_keys( $urls ) );
		$clean['self_host']    = ! empty( $input['self_host'] );
		$clean['font_display'] = in_array( $display, [ 'swap', 'optional', 'fallback', 'block', 'auto', 'keep' ], true ) ? $display : 'swap';
		$clean['refresh_days'] = max( 1, min( 365, absint( $input['refresh_days'] ?? 30 ) ) );

		return $clean;

	}

	/*
	CLEAN FONT URL
	-- A storable preload URL, or '' when it is not a WOFF2 or WOFF file
	---------------------------------------------------------- */

	public static function clean_font_url( string $url ): string {

		$url = trim( $url );

		if ( 0 !== strpos( $url, '/' ) || 0 === strpos( $url, '//' ) ) {

			$url = esc_url_raw( $url, [ 'http', 'https' ] );

		}

		return '' !== self::font_type( $url ) ? $url : '';

	}

	public static function font_type( string $url ): string {

		$extension = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

		return self::MIME[ $extension ] ?? '';

	}

	/*
	RUN
	---------------------------------------------------------- */

	public function run( array $s ): void {

		$this->settings = $s;

		if ( '' !== trim( (string) $s['preload'] ) || has_filter( 'octave_addons_perf_preload_fonts' ) ) {

			if ( function_exists( 'wp_preload_resources' ) ) {

				add_filter( 'wp_preload_resources', [ $this, 'filter_preload_resources' ] );

			} else {

				add_action( 'wp_head', [ $this, 'print_preload_tags' ], 2 );

			}

		}

		if ( ! empty( $s['self_host'] ) ) {

			Octave_Addons_Perf_Html::register( 'fonts', [ $this, 'transform' ], 40 );

			add_action( Octave_Addons_Perf_Google_Fonts::FETCH_HOOK, [ 'Octave_Addons_Perf_Google_Fonts', 'process_queue' ] );
			add_action( Octave_Addons_Perf_Google_Fonts::REFRESH_HOOK, [ $this, 'refresh_stale' ] );
			add_action( 'octave_addons_perf_fonts_changed', [ __CLASS__, 'on_fonts_changed' ] );

			if ( ! wp_next_scheduled( Octave_Addons_Perf_Google_Fonts::REFRESH_HOOK ) ) {

				wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', Octave_Addons_Perf_Google_Fonts::REFRESH_HOOK );

			}

		}

	}

	public function run_disabled( array $s ): void {

		self::unschedule();

	}

	public static function unschedule(): void {

		wp_clear_scheduled_hook( Octave_Addons_Perf_Google_Fonts::REFRESH_HOOK );

	}

	public function refresh_stale(): void {

		Octave_Addons_Perf_Google_Fonts::refresh( (int) $this->settings['refresh_days'] * DAY_IN_SECONDS );

	}

	public static function on_fonts_changed(): void {

		Octave_Addons_Perf_Cache::purge_all( 'octave', 'fonts' );

	}

	/*
	PRELOAD LIST
	-- The final, de-duplicated list, after developers have had their say
	---------------------------------------------------------- */

	public function preload_list(): array {

		/**
		 * Filters the font URLs preloaded on the frontend.
		 *
		 * @param string[] $urls Font file URLs, WOFF2 or WOFF.
		 */
		$urls  = (array) apply_filters( 'octave_addons_perf_preload_fonts', Octave_Addons_Perf::lines( $this->settings['preload'] ?? '' ) );
		$clean = [];

		foreach ( $urls as $url ) {

			$url = self::clean_font_url( (string) $url );

			if ( '' !== $url ) {

				$clean[ $url ] = true;

			}

		}

		return array_keys( $clean );

	}

	/*
	FILTER PRELOAD RESOURCES
	-- WordPress (6.1+) prints these and drops any href it has already seen
	---------------------------------------------------------- */

	public function filter_preload_resources( $resources ) {

		if ( ! Octave_Addons_Perf_Context::can_optimize( 'fonts' ) ) {

			return $resources;

		}

		$resources = is_array( $resources ) ? $resources : [];
		$existing  = wp_list_pluck( array_filter( $resources, 'is_array' ), 'href' );

		foreach ( $this->preload_list() as $url ) {

			if ( in_array( $url, $existing, true ) ) {

				continue;

			}

			$resources[] = [
				'href'        => $url,
				'as'          => 'font',
				'type'        => self::font_type( $url ),
				'crossorigin' => 'anonymous',
			];

		}

		return $resources;

	}

	/*
	PRINT PRELOAD TAGS
	-- Fallback for WordPress before 6.1
	---------------------------------------------------------- */

	public function print_preload_tags(): void {

		if ( ! Octave_Addons_Perf_Context::can_optimize( 'fonts' ) ) {

			return;

		}

		foreach ( $this->preload_list() as $url ) {

			printf(
				'<link rel="preload" href="%1$s" as="font" type="%2$s" crossorigin="anonymous">' . "\n",
				esc_url( $url ),
				esc_attr( self::font_type( $url ) )
			);

		}

	}

	/*
	TRANSFORM
	-- Points Google Fonts stylesheet links at their local copies. When every
	-- Google stylesheet on the page was replaced, preconnect and dns-prefetch
	-- hints for Google's font hosts are disabled too, so no connection to
	-- Google is opened at all
	---------------------------------------------------------- */

	public function transform( string $html ): string {

		if ( ! Octave_Addons_Perf::has_html_api() || false === stripos( $html, Octave_Addons_Perf_Google_Fonts::CSS_HOST ) ) {

			return $html;

		}

		$tags     = new WP_HTML_Tag_Processor( $html );
		$missing  = 0;
		$replaced = 0;

		while ( $tags->next_tag( 'LINK' ) ) {

			$href = (string) $tags->get_attribute( 'href' );

			if ( ! Octave_Addons_Perf_Google_Fonts::is_stylesheet_url( $href ) ) {

				continue;

			}

			$local = Octave_Addons_Perf_Google_Fonts::local_url( $href );

			Octave_Addons_Perf_Log::note( 'fonts', [ 'stylesheet' => $href, 'local' => $local ] );

			if ( '' === $local ) {

				$missing++;

				continue;

			}

			$tags->set_attribute( 'href', $local );
			$tags->set_attribute( 'data-oa-google-fonts', '' );
			$replaced++;

		}

		if ( 0 === $replaced ) {

			return $html;

		}

		$html = $tags->get_updated_html();

		if ( $missing > 0 ) {

			return $html;

		}

		$tags = new WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag( 'LINK' ) ) {

			$rel  = strtolower( (string) $tags->get_attribute( 'rel' ) );
			$host = strtolower( (string) wp_parse_url( Octave_Addons_Perf_Google_Fonts::normalize( (string) $tags->get_attribute( 'href' ) ), PHP_URL_HOST ) );

			if ( in_array( $rel, [ 'preconnect', 'dns-prefetch' ], true ) && in_array( $host, [ Octave_Addons_Perf_Google_Fonts::CSS_HOST, Octave_Addons_Perf_Google_Fonts::FILE_HOST ], true ) ) {

				$tags->set_attribute( 'data-oa-removed-href', (string) $tags->get_attribute( 'href' ) );
				$tags->remove_attribute( 'href' );

			}

		}

		return $tags->get_updated_html();

	}

	/*
	RENDER SETTINGS
	---------------------------------------------------------- */

	public function render_settings( array $s ): void {

		$selected = Octave_Addons_Perf::lines( $s['preload'] );
		$cached   = Octave_Addons_Perf_Google_Fonts::cached_files();
		$manifest = Octave_Addons_Perf_Google_Fonts::manifest();

		?>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			Octave_Addons_Fields::section( [ 'label' => __( 'Font preloading', 'octave-addons' ), 'first' => true ] );

			Octave_Addons_Fields::row( [
				'label' => __( 'Fonts to preload', 'octave-addons' ),
				'for'   => $this->field_id( 'preload' ),
				'field' => function () use ( $s, $selected ) {

					Octave_Addons_Fields::textarea( [
						'name'       => $this->field_name( 'preload' ),
						'id'         => $this->field_id( 'preload' ),
						'value'      => $s['preload'],
						'rows'       => 4,
						'class'      => 'large-text code',
						'spellcheck' => false,
						'help'       => __( 'One WOFF2 (preferred) or WOFF URL per line. Choose only the two or three files used for text visible before scrolling, usually the body and heading weights.', 'octave-addons' ),
					] );

					?>

					<p class="oa-perf-warning<?= count( $selected ) > self::PRELOAD_ADVICE ? '' : ' oa-hidden'; ?>" data-oa-perf-font-warning role="status"><?php esc_html_e( 'More than three fonts are selected. Extra preloads compete with images and scripts and can slow the page down.', 'octave-addons' ); ?></p>

					<?php

				},
			] );

			Octave_Addons_Fields::row( [
				'label' => __( 'Available fonts', 'octave-addons' ),
				'field' => function () use ( $cached ) {

					?>

					<div class="oa-perf-font-picker" data-oa-perf-font-picker data-target="<?= esc_attr( $this->field_id( 'preload' ) ); ?>">
						<ul class="oa-perf-font-list" data-oa-perf-font-list>
							<?php

							foreach ( $cached as $file ) :

							?>

							<li>
								<code><?= esc_html( basename( $file['url'] ) ); ?></code>
								<span><?= esc_html( $file['families'] . ' · ' . strtoupper( $file['format'] ) . ' · ' . size_format( $file['size'] ) ); ?></span>
								<button type="button" class="button button-small" data-oa-perf-add-font="<?= esc_attr( $file['url'] ); ?>"><?php esc_html_e( 'Add to preload list', 'octave-addons' ); ?></button>
							</li>

							<?php

							endforeach;

							?>
						</ul>
						<span class="oa-help"><?php esc_html_e( 'Self-hosted Google Fonts files appear here. Run diagnostics at the top of the page to also list local fonts found in the stylesheets of a scanned page.', 'octave-addons' ); ?></span>
					</div>

					<?php

				},
			] );

			Octave_Addons_Fields::section( [ 'label' => __( 'Self-host Google Fonts', 'octave-addons' ) ] );

			$this->switch_row( 'self_host', __( 'Serve Google Fonts locally', 'octave-addons' ), __( 'Downloads Google Fonts stylesheets and their WOFF2 files to this site, so visitors never contact Google. Until a stylesheet is cached, the page keeps using Google; if Google is unavailable the last good copy stays in use.', 'octave-addons' ), $s );

			$this->select_row( 'font_display', __( 'font-display', 'octave-addons' ), [
				'swap'     => __( 'swap (recommended)', 'octave-addons' ),
				'optional' => 'optional',
				'fallback' => 'fallback',
				'block'    => 'block',
				'auto'     => 'auto',
				'keep'     => __( 'Keep Google\'s value', 'octave-addons' ),
			], __( 'Added to @font-face rules that do not set their own. A value chosen in the stylesheet URL is always kept. Changes apply on the next refresh.', 'octave-addons' ), $s );

			Octave_Addons_Fields::row( [
				'label' => __( 'Refresh every', 'octave-addons' ),
				'for'   => $this->field_id( 'refresh_days' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::number( [
						'name'   => $this->field_name( 'refresh_days' ),
						'id'     => $this->field_id( 'refresh_days' ),
						'value'  => $s['refresh_days'],
						'min'    => 1,
						'max'    => 365,
						'suffix' => __( 'days', 'octave-addons' ),
					] );

				},
			] );

			Octave_Addons_Fields::row( [
				'label' => __( 'Cached stylesheets', 'octave-addons' ),
				'field' => function () use ( $manifest ) {

					if ( empty( $manifest ) ) {

						echo '<p class="oa-help">' . esc_html__( 'Nothing cached yet. Stylesheets are found as logged-out visitors view pages, then downloaded in the background.', 'octave-addons' ) . '</p>';

					}

					?>

					<ul class="oa-perf-font-sources">
						<?php

						foreach ( $manifest as $entry ) :

						?>

						<li>
							<strong><?= esc_html( implode( ', ', (array) ( $entry['families'] ?? [] ) ) ?: __( 'Not cached', 'octave-addons' ) ); ?></strong>
							<span>
								<?php

								printf(
									/* translators: 1: number of files, 2: relative time. */
									esc_html__( '%1$d files · fetched %2$s', 'octave-addons' ),
									count( (array) ( $entry['files'] ?? [] ) ),
									! empty( $entry['fetched'] ) ? esc_html( Octave_Addons_Perf_Admin::time_ago( (int) $entry['fetched'] ) ) : esc_html__( 'never', 'octave-addons' )
								);

								?>
							</span>
							<code><?= esc_html( (string) ( $entry['source'] ?? '' ) ); ?></code>
							<?php

							if ( ! empty( $entry['error'] ) ) :

							?>

							<span class="oa-perf-warning"><?= esc_html( (string) $entry['error'] ); ?></span>

							<?php

							endif;

							?>
						</li>

						<?php

						endforeach;

						?>
					</ul>
					<button type="button" class="button" data-oa-perf-action="oa_perf_fonts_refresh" data-result="oa-perf-fonts-result"><?php esc_html_e( 'Refresh self-hosted fonts', 'octave-addons' ); ?></button>
					<div class="oa-perf-result" id="oa-perf-fonts-result" role="status" aria-live="polite"></div>

					<?php

				},
			] );

			?>
		</table>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Fonts();
