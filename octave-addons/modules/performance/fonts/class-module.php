<?php

/*
PERFORMANCE: FONTS
-- Two independent tools:
-- Preload — outputs <link rel="preload" as="font"> for the font files the
-- front end reports it downloaded while loading the page, at most three, so
-- preloads never compete with the page's other resources. The list is
-- refreshed daily by the first visitor after it goes stale
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

	/** Most fonts preloaded; extra preloads compete with images and scripts. */
	public const PRELOAD_MAX = 3;

	public const DETECTED_OPTION = 'octave_addons_perf_fonts_detected';
	public const DETECT_ACTION   = 'oa_perf_fonts_detect';

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

		return __( 'Preloads the fonts each page needs first and can serve Google Fonts from this site instead of Google.', 'octave-addons' );

	}

	public function get_order(): int {

		return 50;

	}

	public function get_defaults(): array {

		return [
			'enabled'      => false,
			'self_host'    => false,
			'font_display' => 'swap',
			'refresh_days' => 30,
		];

	}

	public function sanitize( $input ): array {

		$clean   = parent::sanitize( $input );
		$display = sanitize_key( $input['font_display'] ?? 'swap' );

		$clean['self_host']    = ! empty( $input['self_host'] );
		$clean['font_display'] = in_array( $display, [ 'swap', 'optional', 'fallback', 'block', 'auto', 'keep' ], true ) ? $display : 'swap';
		$clean['refresh_days'] = max( 1, min( 365, absint( $input['refresh_days'] ?? 30 ) ) );

		return $clean;

	}

	/*
	CLEAN FONT URL
	-- A storable preload URL, or '' when it is not a WOFF2 or WOFF file. Query
	-- strings are kept, since they can be part of the real file URL
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

		if ( function_exists( 'wp_preload_resources' ) ) {

			add_filter( 'wp_preload_resources', [ $this, 'filter_preload_resources' ] );

		} else {

			add_action( 'wp_head', [ $this, 'print_preload_tags' ], 2 );

		}

		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_detector' ] );
		add_action( 'wp_ajax_' . self::DETECT_ACTION, [ __CLASS__, 'ajax_detect' ] );
		add_action( 'wp_ajax_nopriv_' . self::DETECT_ACTION, [ __CLASS__, 'ajax_detect' ] );

		if ( ! empty( $s['self_host'] ) ) {

			Octave_Addons_Perf_Html::register( 'fonts', [ $this, 'transform' ], 40 );

			add_action( Octave_Addons_Perf_Google_Fonts::FETCH_HOOK, [ 'Octave_Addons_Perf_Google_Fonts', 'process_queue' ] );
			add_action( Octave_Addons_Perf_Google_Fonts::DISCOVER_HOOK, [ 'Octave_Addons_Perf_Google_Fonts', 'discover_and_fetch' ] );
			add_action( Octave_Addons_Perf_Google_Fonts::REFRESH_HOOK, [ $this, 'refresh_stale' ] );
			add_action( 'octave_addons_perf_fonts_changed', [ __CLASS__, 'on_fonts_changed' ] );

			if ( ! wp_next_scheduled( Octave_Addons_Perf_Google_Fonts::REFRESH_HOOK ) ) {

				wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', Octave_Addons_Perf_Google_Fonts::REFRESH_HOOK );

			}

			// Nothing cached yet: look at the home page in the background rather
			// than wait for an uncached page view, at most once a day.
			if ( empty( Octave_Addons_Perf_Google_Fonts::manifest() ) && false === get_transient( Octave_Addons_Perf_Google_Fonts::DISCOVERED_FLAG ) && ! wp_next_scheduled( Octave_Addons_Perf_Google_Fonts::DISCOVER_HOOK ) ) {

				wp_schedule_single_event( time() + 10, Octave_Addons_Perf_Google_Fonts::DISCOVER_HOOK );

			}

		}

	}

	public function run_disabled( array $s ): void {

		self::unschedule();

	}

	public static function unschedule(): void {

		wp_clear_scheduled_hook( Octave_Addons_Perf_Google_Fonts::REFRESH_HOOK );
		wp_clear_scheduled_hook( Octave_Addons_Perf_Google_Fonts::DISCOVER_HOOK );

	}

	public function refresh_stale(): void {

		Octave_Addons_Perf_Google_Fonts::refresh( (int) $this->settings['refresh_days'] * DAY_IN_SECONDS );

	}

	public static function on_fonts_changed(): void {

		Octave_Addons_Perf_Cache::purge_all( 'files', 'fonts' );

	}

	/*
	DETECTED / NEEDS DETECTION
	-- The stored list is refreshed once it is a day old
	---------------------------------------------------------- */

	public static function detected(): array {

		$detected = get_option( self::DETECTED_OPTION, [] );

		return is_array( $detected ) ? $detected : [];

	}

	public static function needs_detection(): bool {

		return (int) ( self::detected()['time'] ?? 0 ) < time() - DAY_IN_SECONDS;

	}

	/*
	ENQUEUE DETECTOR
	-- Only while the list is stale. That page view skips the preloads, so the
	-- report reflects the fonts the page really uses rather than old preloads
	---------------------------------------------------------- */

	public static function enqueue_detector(): void {

		if ( ! self::needs_detection() || ! Octave_Addons_Perf_Context::can_optimize( 'fonts' ) ) {

			return;

		}

		wp_enqueue_script( 'octave-addons-font-detect', Octave_Addons_Perf::asset_url( 'font-detect.js' ), [], Octave_Addons_Perf::asset_version( 'font-detect.js' ), true );

		wp_localize_script( 'octave-addons-font-detect', 'oaFontDetect', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::DETECT_ACTION,
			'max'     => self::PRELOAD_MAX,
		] );

	}

	/*
	AJAX DETECT
	-- Public, since visitors report it. No nonce, as cached pages outlive
	-- them; instead only a stale list is ever replaced, and only with
	-- same-origin WOFF2/WOFF URLs, so the worst a forged report can do is
	-- preload one of the site's own font files for a day
	---------------------------------------------------------- */

	public static function ajax_detect(): void {

		if ( ! self::needs_detection() ) {

			wp_send_json_success();

		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public report, validated below.
		$raw  = isset( $_POST['urls'] ) && is_array( $_POST['urls'] ) ? wp_unslash( $_POST['urls'] ) : [];
		$urls = [];

		foreach ( $raw as $url ) {

			$url = self::clean_font_url( (string) $url );

			if ( '' !== $url && Octave_Addons_Perf::is_same_origin( $url ) ) {

				$urls[ $url ] = true;

			}

		}

		update_option( self::DETECTED_OPTION, [ 'urls' => array_slice( array_keys( $urls ), 0, self::PRELOAD_MAX ), 'time' => time() ], false );

		wp_send_json_success();

	}

	/*
	PRELOAD LIST
	-- The final, de-duplicated list, after developers have had their say
	---------------------------------------------------------- */

	public function preload_list(): array {

		$urls = self::needs_detection() ? [] : (array) ( self::detected()['urls'] ?? [] );

		// Nothing reported yet: the self-hosted Latin fonts are the best guess,
		// and they are already on this site, so a wrong guess costs little.
		if ( empty( $urls ) && ! self::needs_detection() && ! empty( $this->settings['self_host'] ) ) {

			$urls = Octave_Addons_Perf_Google_Fonts::latin_files( self::PRELOAD_MAX );

		}

		/**
		 * Filters the font URLs preloaded on the frontend.
		 *
		 * @param string[] $urls Font file URLs, WOFF2 or WOFF.
		 */
		$urls  = (array) apply_filters( 'octave_addons_perf_preload_fonts', $urls );
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
	-- Points every Google Fonts stylesheet the page loads at its local copy:
	-- <link> tags, @import rules in inline styles, and WebFont.load() calls,
	-- which become the loader's custom module so its loading classes and
	-- events still fire. When every Google stylesheet on the page was
	-- replaced, preconnect and dns-prefetch hints for Google's font hosts are
	-- disabled too, so no connection to Google is opened at all
	---------------------------------------------------------- */

	public function transform( string $html ): string {

		$host = Octave_Addons_Perf_Google_Fonts::CSS_HOST;

		if ( ! Octave_Addons_Perf::has_html_api() || ( false === stripos( $html, $host ) && false === stripos( $html, 'WebFont' ) ) ) {

			return $html;

		}

		$missing  = 0;
		$replaced = 0;

		$local_for = static function ( string $source ) use ( &$missing ): string {

			$local = Octave_Addons_Perf_Google_Fonts::local_url( $source );

			Octave_Addons_Perf_Log::note( 'fonts', [ 'stylesheet' => Octave_Addons_Perf_Google_Fonts::normalize( $source ), 'local' => $local ] );

			if ( '' === $local ) {

				$missing++;

			}

			return $local;

		};

		$tags = new WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag( 'LINK' ) ) {

			$href = (string) $tags->get_attribute( 'href' );

			if ( ! Octave_Addons_Perf_Google_Fonts::is_stylesheet_url( $href ) ) {

				continue;

			}

			$local = $local_for( $href );

			if ( '' === $local ) {

				continue;

			}

			$tags->set_attribute( 'href', $local );
			$tags->set_attribute( 'data-oa-google-fonts', '' );
			$replaced++;

		}

		$html = $tags->get_updated_html();

		// @import url(...) or @import "..." of a Google stylesheet.
		$html = (string) preg_replace_callback( '#@import\s+(?:url\(\s*)?([\'"]?)((?:https?:)?//' . preg_quote( $host, '#' ) . '/[^\'")\s;]+)\1\s*\)?#i', static function ( array $match ) use ( $local_for, &$replaced ): string {

			if ( ! Octave_Addons_Perf_Google_Fonts::is_stylesheet_url( $match[2] ) ) {

				return $match[0];

			}

			$local = $local_for( $match[2] );

			if ( '' === $local ) {

				return $match[0];

			}

			$replaced++;

			return '@import url("' . $local . '")';

		}, $html );

		foreach ( Octave_Addons_Perf_Google_Fonts::webfont_configs( $html ) as $config ) {

			$local = $local_for( $config['url'] );

			if ( '' === $local ) {

				continue;

			}

			$names  = array_values( array_unique( array_map( static function ( string $family ): string {

				return trim( explode( ':', $family )[0] );

			}, $config['families'] ) ) );
			$custom = 'custom: ' . wp_json_encode( [ 'families' => $names, 'urls' => [ $local ] ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG );
			$html   = str_replace( $config['match'], $custom, $html );

			$replaced++;

		}

		if ( 0 === $replaced || $missing > 0 ) {

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

		$preloads = (array) ( self::detected()['urls'] ?? [] );
		$manifest = Octave_Addons_Perf_Google_Fonts::manifest();

		?>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			Octave_Addons_Fields::section( [ 'label' => __( 'Font preloading', 'octave-addons' ), 'first' => true ] );

			Octave_Addons_Fields::row( [
				'label' => __( 'Preloaded fonts', 'octave-addons' ),
				'field' => function () use ( $preloads ) {

					if ( empty( $preloads ) ) {

						echo '<p class="oa-help">' . esc_html__( 'Detected automatically. The fonts a page downloads while loading are reported by the next visitor and preloaded from then on, refreshed daily.', 'octave-addons' ) . '</p>';

						return;

					}

					?>

					<ul class="oa-perf-font-list">
						<?php

						foreach ( $preloads as $url ) :

						?>

						<li><code><?= esc_html( (string) $url ); ?></code></li>

						<?php

						endforeach;

						?>
					</ul>

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

						echo '<p class="oa-help">' . esc_html__( 'Nothing cached yet. Google Fonts on the home page are found automatically, others as logged-out visitors view pages, then downloaded in the background. Refresh to look again now.', 'octave-addons' ) . '</p>';

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
					<div id="oa-perf-fonts-result" data-oa-perf-result role="status" aria-live="polite"></div>

					<?php

				},
			] );

			?>
		</table>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Fonts();
