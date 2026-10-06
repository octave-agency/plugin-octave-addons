<?php

/*
PERFORMANCE: MEDIA LAZY LOADING
-- Native browser lazy loading for images and iframes, plus optional click-to-
-- play facades for YouTube and Vimeo embeds. Works with the always-on
-- Breakdance Lazy Load module rather than beside it: that module keeps
-- handling HTML5 and background videos, and this one decides whether it may
-- Only attributes are added (loading, decoding). src, srcset, sizes, width,
-- height and picture/source markup are never touched, so WebP and AVIF
-- sources, CDN rewrites and responsive images keep working as delivered
-- The first images in the page are left alone, as they are likely the logo
-- and hero image that decide how quickly the page appears
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once dirname( __DIR__ ) . '/services/bootstrap.php';

class Octave_Addons_Module_Performance_Media extends Octave_Addons_Module {

	use Octave_Addons_Perf_Field_Rows;

	/** Classes that opt an element out of lazy loading on every site. */
	protected const SKIP_CLASSES = [ 'skip-lazy', 'no-lazy', 'oa-no-lazy' ];

	/** Attributes that opt an element out of lazy loading. */
	protected const SKIP_ATTRIBUTES = [ 'data-no-lazy', 'data-skip-lazy', 'data-oa-no-lazy' ];

	/** Attributes another lazy-loading script relies on; such elements are left to it. */
	protected const FOREIGN_LAZY_ATTRIBUTES = [ 'data-src', 'data-lazy-src', 'data-srcset', 'data-lazy-srcset' ];

	protected const NOTE_LIMIT = 150;

	protected array $settings = [];

	protected static bool $facade_assets = false;

	public function get_id(): string {

		return 'performance-media';

	}

	public function get_title(): string {

		return __( 'Media Lazy Loading', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Native lazy loading for images and iframes, lazy video loading, and optional click-to-play YouTube and Vimeo facades.', 'octave-addons' );

	}

	public function get_order(): int {

		return 10;

	}

	public function get_defaults(): array {

		return [
			'enabled'            => false,
			'images'             => true,
			'iframes'            => true,
			'videos'             => true,
			'decoding'           => true,
			'skip_first'         => 3,
			'priority'           => true,
			'facades'            => false,
			'exclude_classes'    => '',
			'exclude_attributes' => '',
			'exclude_urls'       => '',
		];

	}

	public function sanitize( $input ): array {

		$clean = parent::sanitize( $input );

		foreach ( [ 'images', 'iframes', 'videos', 'decoding', 'priority', 'facades' ] as $key ) {

			$clean[ $key ] = ! empty( $input[ $key ] );

		}

		$clean['skip_first'] = min( 20, absint( $input['skip_first'] ?? 3 ) );

		foreach ( [ 'exclude_classes', 'exclude_attributes', 'exclude_urls' ] as $key ) {

			$clean[ $key ] = Octave_Addons_Perf::sanitize_lines( $input[ $key ] ?? '' );

		}

		return $clean;

	}

	/*
	RUN
	---------------------------------------------------------- */

	public function run( array $s ): void {

		$this->settings = $s;

		add_filter( 'octave_addons_lazy_videos', static function () use ( $s ): bool {

			return ! empty( $s['videos'] );

		} );

		add_filter( 'octave_addons_perf_skip_lazy', [ $this, 'filter_user_exclusions' ], 10, 2 );

		if ( ! empty( $s['images'] ) || ! empty( $s['iframes'] ) ) {

			Octave_Addons_Perf_Html::register( 'media', [ $this, 'transform' ], 20 );

		}

		if ( ! empty( $s['facades'] ) ) {

			add_filter( 'embed_oembed_html', [ $this, 'filter_embed_facade' ], 20 );
			add_filter( 'render_block_core/embed', [ $this, 'filter_embed_facade' ], 20 );
			add_action( 'wp_footer', [ __CLASS__, 'print_late_facade_assets' ], 100 );

		}

	}

	/*
	TRANSFORM
	-- One pass over the page with WP_HTML_Tag_Processor
	---------------------------------------------------------- */

	public function transform( string $html ): string {

		if ( ! Octave_Addons_Perf::has_html_api() ) {

			return $html;

		}

		$s           = $this->settings;
		$do_images   = ! empty( $s['images'] ) && '' === Octave_Addons_Perf::handled_elsewhere( 'lazy_images' );
		$do_iframes  = ! empty( $s['iframes'] ) && '' === Octave_Addons_Perf::handled_elsewhere( 'lazy_iframes' );
		$skip_first  = (int) ( $s['skip_first'] ?? 3 );
		$tags        = new WP_HTML_Tag_Processor( $html );
		$image_index = 0;
		$notes       = 0;
		$prioritise  = $do_images && ! empty( $s['priority'] ) && ! preg_match( '/fetchpriority\s*=\s*["\']?high/i', $html );

		while ( $tags->next_tag() ) {

			$tag = $tags->get_tag();

			if ( 'IMG' !== $tag && 'IFRAME' !== $tag ) {

				continue;

			}

			if ( 'IMG' === $tag ) {

				$image_index++;

			}

			if ( ( 'IMG' === $tag && ! $do_images ) || ( 'IFRAME' === $tag && ! $do_iframes ) ) {

				continue;

			}

			$reason = 'IMG' === $tag && $image_index <= $skip_first ? 'likely-above-the-fold' : self::skip_reason( $tags );

			if ( $prioritise && 'likely-above-the-fold' === $reason && self::is_priority_candidate( $tags ) ) {

				$tags->set_attribute( 'fetchpriority', 'high' );
				$prioritise = false;
				$reason     = 'fetchpriority-high';

			}

			if ( $notes < self::NOTE_LIMIT ) {

				$notes++;

				Octave_Addons_Perf_Log::note( 'media', [
					'tag'    => strtolower( $tag ),
					'src'    => (string) $tags->get_attribute( 'src' ),
					'action' => '' === $reason ? 'lazy' : 'skipped',
					'reason' => $reason,
				] );

			}

			if ( '' !== $reason ) {

				continue;

			}

			$tags->set_attribute( 'loading', 'lazy' );

			if ( 'IMG' === $tag && ! empty( $s['decoding'] ) && null === $tags->get_attribute( 'decoding' ) ) {

				$tags->set_attribute( 'decoding', 'async' );

			}

		}

		return $tags->get_updated_html();

	}

	/*
	IS PRIORITY CANDIDATE
	-- The likely Largest Contentful Paint image: the first eager image at least
	-- 400px wide that nothing has marked lazy or low priority. Logos and icons
	-- are narrower, so they are passed over
	---------------------------------------------------------- */

	public static function is_priority_candidate( WP_HTML_Tag_Processor $tags ): bool {

		if ( null !== $tags->get_attribute( 'fetchpriority' ) || 'lazy' === strtolower( (string) $tags->get_attribute( 'loading' ) ) ) {

			return false;

		}

		if ( null !== $tags->get_attribute( 'data-oa-no-priority' ) ) {

			return false;

		}

		// ponytail: width heuristic, add a per-page LCP selector if it picks the wrong image.
		return (int) $tags->get_attribute( 'width' ) >= 400;

	}

	/*
	SKIP REASON
	-- Why an image or iframe keeps loading eagerly, or '' to lazy load it.
	-- Any existing loading value is respected, eager and lazy alike
	---------------------------------------------------------- */

	public static function skip_reason( WP_HTML_Tag_Processor $tags ): string {

		if ( null !== $tags->get_attribute( 'loading' ) ) {

			return 'has-loading';

		}

		if ( 'high' === strtolower( (string) $tags->get_attribute( 'fetchpriority' ) ) ) {

			return 'fetchpriority-high';

		}

		foreach ( self::SKIP_ATTRIBUTES as $attribute ) {

			if ( null !== $tags->get_attribute( $attribute ) ) {

				return 'opted-out';

			}

		}

		foreach ( self::SKIP_CLASSES as $class ) {

			if ( $tags->has_class( $class ) ) {

				return 'opted-out';

			}

		}

		foreach ( self::FOREIGN_LAZY_ATTRIBUTES as $attribute ) {

			if ( null !== $tags->get_attribute( $attribute ) ) {

				return 'other-lazy-loader';

			}

		}

		$src = (string) $tags->get_attribute( 'src' );

		if ( '' === trim( $src ) || 0 === stripos( trim( $src ), 'data:' ) ) {

			return 'no-src';

		}

		/**
		 * Filters whether an element is excluded from lazy loading.
		 *
		 * @param bool                  $skip Whether to skip it.
		 * @param WP_HTML_Tag_Processor $tags Processor positioned on the element.
		 */
		if ( apply_filters( 'octave_addons_perf_skip_lazy', false, $tags ) ) {

			return 'excluded';

		}

		return '';

	}

	/*
	FILTER USER EXCLUSIONS
	-- Applies the class, attribute and URL fields. Also reached by the video
	-- lazy loader, so one set of exclusions covers every kind of media
	---------------------------------------------------------- */

	public function filter_user_exclusions( $skip, $tags ) {

		if ( $skip || ! $tags instanceof WP_HTML_Tag_Processor ) {

			return $skip;

		}

		$s = $this->settings;

		foreach ( Octave_Addons_Perf::lines( $s['exclude_classes'] ?? '' ) as $class ) {

			if ( $tags->has_class( ltrim( $class, '.' ) ) ) {

				return true;

			}

		}

		foreach ( Octave_Addons_Perf::lines( $s['exclude_attributes'] ?? '' ) as $rule ) {

			$parts = array_map( 'trim', explode( '=', $rule, 2 ) );
			$value = $tags->get_attribute( $parts[0] );

			if ( null !== $value && ( ! isset( $parts[1] ) || (string) $value === trim( $parts[1], '"\'' ) ) ) {

				return true;

			}

		}

		$urls = (string) $tags->get_attribute( 'src' ) . ' ' . (string) $tags->get_attribute( 'srcset' ) . ' ' . (string) $tags->get_attribute( 'poster' );

		return '' !== Octave_Addons_Perf::matches_any( $urls, Octave_Addons_Perf::lines( $s['exclude_urls'] ?? '' ) );

	}

	/*
	FILTER EMBED FACADE
	-- Swaps a YouTube or Vimeo oEmbed iframe for a click-to-play facade. Runs
	-- on the embed's own small fragment, never on the whole page
	---------------------------------------------------------- */

	public function filter_embed_facade( $html ) {

		if ( ! is_string( $html ) || false === stripos( $html, '<iframe' ) || ! Octave_Addons_Perf::has_html_api() ) {

			return $html;

		}

		if ( ! Octave_Addons_Perf_Context::can_optimize( 'media' ) ) {

			return $html;

		}

		$facade = self::facade( $html );

		if ( $facade !== $html ) {

			self::enqueue_facade_assets();

		}

		return $facade;

	}

	/*
	FACADE
	-- The original iframe is kept twice: in a <template> the script copies
	-- from on click, and in <noscript> so the real player still shows without
	-- JavaScript. Autoplaying embeds are left alone, since a facade cannot
	-- honour autoplay
	---------------------------------------------------------- */

	public static function facade( string $html ): string {

		$tags = new WP_HTML_Tag_Processor( $html );

		if ( ! $tags->next_tag( 'IFRAME' ) || '' !== self::skip_reason_for_facade( $tags ) ) {

			return $html;

		}

		$src   = (string) $tags->get_attribute( 'src' );
		$video = self::video_from_url( $src );

		if ( empty( $video ) ) {

			return $html;

		}

		$start = stripos( $html, '<iframe' );
		$end   = false === $start ? false : stripos( $html, '</iframe>', $start );

		if ( false === $start || false === $end ) {

			return $html;

		}

		$end    += strlen( '</iframe>' );
		$iframe  = substr( $html, $start, $end - $start );
		$width   = absint( $tags->get_attribute( 'width' ) ) ?: 16;
		$height  = absint( $tags->get_attribute( 'height' ) ) ?: 9;
		$title   = trim( (string) $tags->get_attribute( 'title' ) );
		$label   = '' !== $title
			/* translators: %s: video title. */
			? sprintf( __( 'Play video: %s', 'octave-addons' ), $title )
			: __( 'Play video', 'octave-addons' );

		ob_start();

		?>

		<div class="oa-video-facade oa-video-facade--<?= esc_attr( $video['provider'] ); ?>" data-oa-facade style="aspect-ratio: <?= (int) $width; ?> / <?= (int) $height; ?>;">
			<button type="button" class="oa-video-facade-button" aria-label="<?= esc_attr( $label ); ?>">
				<?php

				if ( 'youtube' === $video['provider'] ) :

				?>

				<img src="<?= esc_url( 'https://i.ytimg.com/vi/' . $video['id'] . '/hqdefault.jpg' ); ?>" alt="" width="480" height="360" loading="lazy" decoding="async">

				<?php

				elseif ( '' !== $title ) :

				?>

				<span class="oa-video-facade-title"><?= esc_html( $title ); ?></span>

				<?php

				endif;

				?>
				<span class="oa-video-facade-play" aria-hidden="true"></span>
			</button>
			<template><?= $iframe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the embed's own iframe markup, moved unchanged. ?></template>
			<noscript><?= $iframe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the embed's own iframe markup, moved unchanged. ?></noscript>
		</div>

		<?php

		$facade = trim( (string) ob_get_clean() );

		return substr( $html, 0, $start ) . $facade . substr( $html, $end );

	}

	protected static function skip_reason_for_facade( WP_HTML_Tag_Processor $tags ): string {

		foreach ( self::SKIP_ATTRIBUTES as $attribute ) {

			if ( null !== $tags->get_attribute( $attribute ) ) {

				return 'opted-out';

			}

		}

		$query = (string) wp_parse_url( (string) $tags->get_attribute( 'src' ), PHP_URL_QUERY );

		parse_str( $query, $args );

		if ( ! empty( $args['autoplay'] ) && '0' !== (string) $args['autoplay'] ) {

			return 'autoplay';

		}

		return '';

	}

	/*
	VIDEO FROM URL
	-- Provider and id for YouTube (including youtube-nocookie) and Vimeo
	-- player URLs, or an empty array for anything else
	---------------------------------------------------------- */

	public static function video_from_url( string $url ): array {

		$parts = wp_parse_url( html_entity_decode( $url ) );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path  = (string) ( $parts['path'] ?? '' );

		if ( in_array( $host, [ 'youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com' ], true )
			&& preg_match( '#^/embed/([A-Za-z0-9_-]{6,20})$#', $path, $match ) ) {

			return [ 'provider' => 'youtube', 'id' => $match[1] ];

		}

		if ( 'player.vimeo.com' === $host && preg_match( '#^/video/(\d+)$#', $path, $match ) ) {

			return [ 'provider' => 'vimeo', 'id' => $match[1] ];

		}

		return [];

	}

	protected static function enqueue_facade_assets(): void {

		if ( self::$facade_assets ) {

			return;

		}

		self::$facade_assets = true;

		wp_enqueue_style( 'octave-addons-video-facade', Octave_Addons_Perf::asset_url( 'facade.css' ), [], Octave_Addons_Perf::asset_version( 'facade.css' ) );
		wp_enqueue_script( 'octave-addons-video-facade', Octave_Addons_Perf::asset_url( 'facade.js' ), [], Octave_Addons_Perf::asset_version( 'facade.js' ), true );

	}

	/*
	PRINT LATE FACADE ASSETS
	-- An embed rendered after the footer scripts printed still gets its assets
	---------------------------------------------------------- */

	public static function print_late_facade_assets(): void {

		if ( ! self::$facade_assets ) {

			return;

		}

		if ( ! wp_style_is( 'octave-addons-video-facade', 'done' ) ) {

			wp_print_styles( 'octave-addons-video-facade' );

		}

		if ( ! wp_script_is( 'octave-addons-video-facade', 'done' ) ) {

			wp_print_scripts( 'octave-addons-video-facade' );

		}

	}

	/*
	IMAGE PLUGINS
	-- Active image optimisation plugins, named for the WebP and AVIF guidance
	---------------------------------------------------------- */

	protected function image_plugins(): array {

		$known = [
			'imagify/imagify.php'                                 => 'Imagify',
			'shortpixel-image-optimiser/wp-shortpixel.php'        => 'ShortPixel',
			'ewww-image-optimizer/ewww-image-optimizer.php'       => 'EWWW Image Optimizer',
			'wp-smushit/wp-smush.php'                             => 'Smush',
			'optimole-wp/optimole-wp.php'                         => 'Optimole',
			'webp-express/webp-express.php'                       => 'WebP Express',
			'webp-converter-for-media/webp-converter-for-media.php' => 'Converter for Media',
			'wp-rocket/wp-rocket.php'                             => 'WP Rocket',
		];

		$active = (array) get_option( 'active_plugins', [] );

		return array_values( array_intersect_key( $known, array_flip( $active ) ) );

	}

	/*
	RENDER SETTINGS
	---------------------------------------------------------- */

	public function render_settings( array $s ): void {

		$lazy_owner   = Octave_Addons_Perf::handled_elsewhere( 'lazy_images' );
		$image_plugins = $this->image_plugins();

		if ( '' !== $lazy_owner ) :

		?>

		<div class="notice notice-info inline oa-inline-notice">
			<p>
				<?php

				printf(
					/* translators: %s: plugin name. */
					esc_html__( '%s is already lazy loading images, so Octave leaves images to it to avoid processing them twice.', 'octave-addons' ),
					esc_html( $lazy_owner )
				);

				?>
			</p>
		</div>

		<?php

		endif;

		?>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			Octave_Addons_Fields::section( [ 'label' => __( 'What to lazy load', 'octave-addons' ), 'first' => true ] );

			$this->switch_row( 'images', __( 'Images', 'octave-addons' ), __( 'Safe. Adds loading="lazy" and decoding="async" to images below the first few. Images marked eager, high priority or opted out are left alone.', 'octave-addons' ), $s );
			$this->switch_row( 'iframes', __( 'Iframes', 'octave-addons' ), __( 'Safe. Maps, embeds and other iframes load as they approach the viewport.', 'octave-addons' ), $s );
			$this->switch_row( 'videos', __( 'Videos', 'octave-addons' ), __( 'Safe. HTML5 and Breakdance background videos wait until they near the viewport before downloading; autoplay resumes then.', 'octave-addons' ), $s );
			$this->switch_row( 'priority', __( 'Prioritise the main image', 'octave-addons' ), __( 'Recommended. Adds fetchpriority="high" to the first image at least 400px wide among those always loaded, so the browser fetches the hero image first. Skipped when the page already marks an image high priority. Add data-oa-no-priority to an image to pass it over.', 'octave-addons' ), $s );
			$this->switch_row( 'decoding', __( 'Asynchronous decoding', 'octave-addons' ), __( 'Lets the browser decode lazy images off the main thread.', 'octave-addons' ), $s );

			Octave_Addons_Fields::row( [
				'label' => __( 'Always load the first', 'octave-addons' ),
				'for'   => $this->field_id( 'skip_first' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::number( [
						'name'   => $this->field_name( 'skip_first' ),
						'id'     => $this->field_id( 'skip_first' ),
						'value'  => $s['skip_first'],
						'min'    => 0,
						'max'    => 20,
						'suffix' => __( 'images', 'octave-addons' ),
						'help'   => __( 'Logos and hero images usually come first and decide how fast the page appears, so they are never lazy loaded.', 'octave-addons' ),
					] );

				},
			] );

			$this->switch_row( 'facades', __( 'YouTube and Vimeo facades', 'octave-addons' ), __( 'Advanced. Embedded videos show a thumbnail and play button; the real player loads when clicked. Autoplaying embeds are skipped. Breakdance Video elements already use their own lightweight player.', 'octave-addons' ), $s );

			Octave_Addons_Fields::section( [ 'label' => __( 'Exclusions', 'octave-addons' ) ] );

			$this->textarea_row( 'exclude_classes', __( 'Classes', 'octave-addons' ), __( 'One class per line. skip-lazy, no-lazy and oa-no-lazy are always excluded.', 'octave-addons' ), $s );
			$this->textarea_row( 'exclude_attributes', __( 'Attributes', 'octave-addons' ), __( 'One per line, as name or name=value. data-no-lazy, data-skip-lazy and data-oa-no-lazy are always excluded.', 'octave-addons' ), $s );
			$this->textarea_row( 'exclude_urls', __( 'URL patterns', 'octave-addons' ), __( 'One per line. Any image, iframe or video whose URL contains the text is excluded.', 'octave-addons' ), $s );

			Octave_Addons_Fields::section( [ 'label' => __( 'WebP and AVIF', 'octave-addons' ) ] );

			Octave_Addons_Fields::row( [
				'label' => __( 'Modern formats', 'octave-addons' ),
				'field' => function () use ( $image_plugins ) {

					?>

					<p class="oa-help"><?php esc_html_e( 'Octave does not create WebP or AVIF files. It works with the ones you already have: picture and source elements, srcset and image CDN URLs pass through untouched, and pages are never cached separately per browser.', 'octave-addons' ); ?></p>
					<p class="oa-help">
						<?php

						if ( empty( $image_plugins ) ) {

							esc_html_e( 'No image optimisation plugin was detected. To serve WebP or AVIF, use one that outputs picture markup or an image CDN, or upload WebP/AVIF files directly (WordPress 6.5+ supports AVIF uploads).', 'octave-addons' );

						} else {

							printf(
								/* translators: %s: plugin names. */
								esc_html__( 'Detected: %s. Let it handle conversion and delivery; Octave only adds loading attributes.', 'octave-addons' ),
								esc_html( implode( ', ', $image_plugins ) )
							);

						}

						?>
					</p>
					<p class="oa-help"><?php esc_html_e( 'Browser caching: modern images are safe to cache for a year. On Apache, add to .htaccess:', 'octave-addons' ); ?></p>
					<pre class="oa-perf-code">&lt;IfModule mod_expires.c&gt;
  ExpiresActive On
  ExpiresByType image/webp "access plus 1 year"
  ExpiresByType image/avif "access plus 1 year"
&lt;/IfModule&gt;
AddType image/avif .avif</pre>
					<p class="oa-help"><?php esc_html_e( 'On Nginx, ask your host to add image/avif to mime.types and a long expires rule for webp and avif files.', 'octave-addons' ); ?></p>

					<?php

				},
			] );

			?>
		</table>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Media();
