<?php

/*
PERFORMANCE: FONTS
-- Two independent tools:
-- Preload — outputs <link rel="preload" as="font"> for the font files the
-- front end reports it needed before its Largest Contentful Paint, kept
-- separately for each kind of template (front page, blog, each post type,
-- archives), so a page only preloads faces its own kind of page uses above
-- the fold. One face by default; up to three when the report shows that
-- many were needed first. WOFF2 is preferred over WOFF. Each list is
-- refreshed daily by the first visitor after it goes stale
-- Breakdance custom fonts are already served from this site, so they are
-- preloaded like any other local font and never treated as Google Fonts
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

	/** Most fonts ever preloaded; extra preloads compete with images and scripts. */
	public const PRELOAD_MAX = 3;

	/** Template families remembered; the oldest is dropped beyond this. */
	public const MAX_FAMILIES = 30;

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

		return __( 'Loads the fonts each page needs straight away, and can serve Google Fonts from your own site instead of Google.', 'octave-addons' );

	}

	public function get_order(): int {

		return 50;

	}

	public function get_defaults(): array {

		return [
			'enabled'      => false,
			'preload_max'  => 1,
			'self_host'    => false,
			'font_display' => 'swap',
			'refresh_days' => 30,
		];

	}

	public function sanitize( $input ): array {

		$clean   = parent::sanitize( $input );
		$display = sanitize_key( $input['font_display'] ?? 'swap' );

		$clean['preload_max']  = max( 1, min( self::PRELOAD_MAX, absint( $input['preload_max'] ?? 1 ) ) );
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

			Octave_Addons_Perf_Html::register( 'fonts', [ $this, 'transform' ], 40, static function (): bool {

				return '' === Octave_Addons_Perf::handled_elsewhere( 'google_fonts' );

			} );

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

	/*
	ON FONTS CHANGED
	-- New font files change every page, so cached pages and every family's
	-- detection record are retired with a full purge
	---------------------------------------------------------- */

	public static function on_fonts_changed(): void {

		Octave_Addons_Perf_Cache::purge_all( 'all', 'fonts' );

	}

	/*
	FAMILY
	-- The kind of template this request renders, which keys the preloads
	---------------------------------------------------------- */

	public static function family(): string {

		if ( is_front_page() ) {

			$family = 'front';

		} elseif ( is_home() ) {

			$family = 'blog';

		} elseif ( is_singular() ) {

			$family = 'single-' . sanitize_key( (string) get_post_type() );

		} elseif ( is_archive() || is_search() ) {

			$family = 'archive';

		} else {

			$family = 'other';

		}

		/**
		 * Filters the template family whose fonts are preloaded on this request.
		 *
		 * @param string $family front, blog, single-{post type}, archive or other.
		 */
		return self::clean_family( (string) apply_filters( 'octave_addons_perf_font_family', $family ) );

	}

	public static function clean_family( string $family ): string {

		return preg_match( '/^[a-z0-9_-]{1,40}$/', $family ) ? $family : 'other';

	}

	/*
	DETECTED / NEEDS DETECTION
	-- One record per template family, refreshed once it is a day old
	---------------------------------------------------------- */

	public static function detected( ?string $family = null ): array {

		$all    = get_option( self::DETECTED_OPTION, [] );
		$record = is_array( $all ) ? ( $all['families'][ $family ?? self::family() ] ?? [] ) : [];

		return is_array( $record ) ? $record : [];

	}

	public static function needs_detection( ?string $family = null ): bool {

		return (int) ( self::detected( $family )['time'] ?? 0 ) < time() - DAY_IN_SECONDS;

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
			'family'  => self::family(),
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public report, validated by clean_family().
		$family = self::clean_family( isset( $_POST['family'] ) ? sanitize_key( wp_unslash( $_POST['family'] ) ) : 'other' );

		if ( ! self::needs_detection( $family ) ) {

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

		$all      = get_option( self::DETECTED_OPTION, [] );
		$families = is_array( $all['families'] ?? null ) ? $all['families'] : [];

		unset( $families[ $family ] );

		$families[ $family ] = [ 'urls' => array_slice( array_keys( $urls ), 0, self::PRELOAD_MAX ), 'time' => time() ];
		$families            = array_slice( $families, -self::MAX_FAMILIES, null, true );

		update_option( self::DETECTED_OPTION, [ 'families' => $families ], false );

		wp_send_json_success();

	}

	/*
	PRELOAD LIST
	-- The final, de-duplicated list for this template family, after
	-- developers have had their say: WOFF2 first, a WOFF dropped when the
	-- same face exists as WOFF2, and no more than the setting allows
	---------------------------------------------------------- */

	public function preload_list(): array {

		$family = self::family();
		$fresh  = ! self::needs_detection( $family );
		$urls   = $fresh ? (array) ( self::detected( $family )['urls'] ?? [] ) : [];

		// Nothing reported yet: the self-hosted Latin fonts are the best guess,
		// and they are already on this site, so a wrong guess costs little.
		if ( empty( $urls ) && $fresh && ! empty( $this->settings['self_host'] ) ) {

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

		return array_slice( self::prefer_woff2( array_keys( $clean ) ), 0, max( 1, min( self::PRELOAD_MAX, (int) ( $this->settings['preload_max'] ?? 1 ) ) ) );

	}

	/*
	PREFER WOFF2
	-- WOFF2 files first, keeping their order, and a WOFF left out when the
	-- same file name exists as WOFF2
	---------------------------------------------------------- */

	public static function prefer_woff2( array $urls ): array {

		$woff2 = [];
		$woff  = [];

		foreach ( $urls as $url ) {

			$base = (string) preg_replace( '/\.woff2?$/i', '', (string) wp_parse_url( $url, PHP_URL_PATH ) );

			if ( 'font/woff2' === self::font_type( $url ) ) {

				$woff2[ $base ] = $url;

			} else {

				$woff[ $base ] = $url;

			}

		}

		return array_values( array_merge( $woff2, array_diff_key( $woff, $woff2 ) ) );

	}

	/*
	FONT ORIGIN
	-- A short note on where a preloaded file comes from, for the admin
	---------------------------------------------------------- */

	public static function font_origin( string $url ): string {

		if ( false !== strpos( $url, '/cache/octave-addons/fonts/' ) ) {

			return __( 'Google Font, served from your site by Octave', 'octave-addons' );

		}

		if ( false !== strpos( $url, '/breakdance/' ) ) {

			return __( 'Breakdance custom font, already served from your site', 'octave-addons' );

		}

		return __( 'Local font', 'octave-addons' );

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

		Octave_Addons_Perf_Log::summary( 'preloaded_fonts', $this->preload_list() );

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

		Octave_Addons_Perf_Log::summary( 'preloaded_fonts', $this->preload_list() );

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

		if ( ! Octave_Addons_Perf::has_html_api() || '' !== Octave_Addons_Perf::handled_elsewhere( 'google_fonts' ) || ( false === stripos( $html, $host ) && false === stripos( $html, 'WebFont' ) ) ) {

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

		$all      = get_option( self::DETECTED_OPTION, [] );
		$families = is_array( $all['families'] ?? null ) ? $all['families'] : [];
		$manifest = Octave_Addons_Perf_Google_Fonts::manifest();

		?>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			Octave_Addons_Fields::section( [ 'label' => __( 'Load fonts early', 'octave-addons' ), 'first' => true ] );

			$this->select_row( 'preload_max', __( 'Fonts to load early', 'octave-addons' ), [
				1 => __( 'The one most important font (recommended)', 'octave-addons' ),
				2 => __( 'Up to two, when the top of the page uses them', 'octave-addons' ),
				3 => __( 'Up to three, when the top of the page uses them', 'octave-addons' ),
			], __( 'Each font loaded early competes with your main image, so one is best. More are only loaded early when visitors\' browsers show the top of the page really uses them.', 'octave-addons' ), $s );

			Octave_Addons_Fields::row( [
				'label' => __( 'Fonts found', 'octave-addons' ),
				'field' => function () use ( $families ) {

					if ( empty( $families ) ) {

						echo '<p class="oa-help">' . esc_html__( 'Found automatically for each kind of page: visitors\' browsers report which fonts the top of the page uses, and those are loaded early from then on. Checked again daily.', 'octave-addons' ) . '</p>';

						return;

					}

					?>

					<ul class="oa-perf-font-list">
						<?php

						foreach ( $families as $family => $record ) :

							$urls = (array) ( $record['urls'] ?? [] );

						?>

						<li>
							<strong><?= esc_html( (string) $family ); ?></strong>

							<?php

							if ( empty( $urls ) ) :

							?>

							<span><?php esc_html_e( 'No fonts needed at the top of the page', 'octave-addons' ); ?></span>

							<?php

							endif;

							foreach ( $urls as $url ) :

							?>

							<code><?= esc_html( (string) $url ); ?></code>
							<span><?= esc_html( self::font_origin( (string) $url ) ); ?></span>

							<?php

							endforeach;

							?>
						</li>

						<?php

						endforeach;

						?>
					</ul>

					<?php

				},
			] );

			Octave_Addons_Fields::section( [ 'label' => __( 'Serve Google Fonts from your site', 'octave-addons' ) ] );

			$this->switch_row( 'self_host', __( 'Serve Google Fonts from your site', 'octave-addons' ), __( 'Copies the Google Fonts your site uses onto your own site, so visitors\' browsers never contact Google, which is faster and better for privacy. Until a font has been copied, Google is used as before.', 'octave-addons' ), $s );

			$this->select_row( 'font_display', __( 'While fonts load', 'octave-addons' ), [
				'swap'     => __( 'Show text straight away in a backup font (recommended)', 'octave-addons' ),
				'optional' => 'optional',
				'fallback' => 'fallback',
				'block'    => 'block',
				'auto'     => 'auto',
				'keep'     => __( 'Use Google\'s setting', 'octave-addons' ),
			], __( 'What visitors see before a font has arrived. Showing text straight away means nothing is hidden while fonts load. Applies from the next refresh.', 'octave-addons' ), $s );

			Octave_Addons_Fields::row( [
				'label' => __( 'Check for font updates every', 'octave-addons' ),
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
				'label' => __( 'Fonts copied to your site', 'octave-addons' ),
				'field' => function () use ( $manifest ) {

					if ( empty( $manifest ) ) {

						echo '<p class="oa-help">' . esc_html__( 'Nothing copied yet. Google Fonts on your home page are found automatically, and others as visitors browse, then copied in the background. Use Refresh to check now.', 'octave-addons' ) . '</p>';

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
					<button type="button" class="button" data-oa-perf-action="oa_perf_fonts_refresh" data-result="oa-perf-fonts-result"><?php esc_html_e( 'Refresh copied fonts', 'octave-addons' ); ?></button>
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
