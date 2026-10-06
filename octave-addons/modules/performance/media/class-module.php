<?php

/*
PERFORMANCE: MEDIA LAZY LOADING
-- Native browser lazy loading for images and iframes. Works with the
-- always-on Breakdance Lazy Load module rather than beside it: that module
-- keeps handling HTML5 and background videos, and this one decides whether it may
-- Only attributes are added (loading, decoding, fetchpriority). src, srcset,
-- sizes and picture/source markup are never touched, so WebP and AVIF
-- sources, CDN rewrites and responsive images keep working as delivered
-- The likely hero image is kept eager with high priority. Every other image
-- is left to the browser, which loads lazy images already in view straight away
-- Three optional fixes for what page builders print: images in the site
-- header always load eagerly, images without dimensions get width and height
-- from the file, so the space is held before they arrive, and video posters
-- point at a smaller size WordPress has already generated
-- Posters of lazy videos below the hero wait until they near the viewport
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

	/** Only this many images from the top of the page can become the hero image. */
	protected const PRIORITY_WINDOW = 3;

	/** Poster widths offered; 0 leaves posters as they are. */
	public const POSTER_WIDTHS = [ 0, 768, 1024 ];

	/** A generated size narrower than this is a thumbnail, never a poster. */
	protected const POSTER_MIN_WIDTH = 400;

	protected array $settings = [];

	public function get_id(): string {

		return 'performance-media';

	}

	public function get_title(): string {

		return __( 'Media Lazy Loading', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Native lazy loading for images, iframes and videos, with the hero image fetched first.', 'octave-addons' );

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
			'header_eager'       => true,
			'dimensions'         => true,
			'poster_width'       => 768,
			'lazy_posters'       => true,
			'exclude_classes'    => '',
			'exclude_attributes' => '',
			'exclude_urls'       => '',
		];

	}

	public function sanitize( $input ): array {

		$clean = parent::sanitize( $input );

		foreach ( [ 'images', 'iframes', 'videos', 'header_eager', 'dimensions', 'lazy_posters' ] as $key ) {

			$clean[ $key ] = ! empty( $input[ $key ] );

		}

		$width                 = absint( $input['poster_width'] ?? 0 );
		$clean['poster_width'] = in_array( $width, self::POSTER_WIDTHS, true ) ? $width : 0;

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

		if ( ! empty( $s['images'] ) || ! empty( $s['iframes'] ) || ! empty( $s['header_eager'] ) || ! empty( $s['dimensions'] ) || ! empty( $s['poster_width'] ) || ! empty( $s['lazy_posters'] ) ) {

			Octave_Addons_Perf_Html::register( 'media', [ $this, 'transform' ], 20 );

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
		$tags        = new WP_HTML_Tag_Processor( $html );
		$image_index = 0;
		$notes       = 0;
		$prioritise  = $do_images && ! preg_match( '/fetchpriority\s*=\s*["\']?high/i', $html );
		$poster      = (int) ( $s['poster_width'] ?? 0 );
		$lazy_poster = ! empty( $s['lazy_posters'] ) && ! empty( $s['videos'] );
		$hero_seen   = false;
		$videos      = 0;

		// Only the first <header> is the site header; later ones belong to articles.
		$header_depth = 0;
		$header_done  = false;

		while ( $tags->next_tag( [ 'tag_closers' => 'visit' ] ) ) {

			$tag = $tags->get_tag();

			if ( 'HEADER' === $tag && ! $header_done ) {

				if ( $tags->is_tag_closer() ) {

					$header_depth--;
					$header_done = $header_depth <= 0;

				} else {

					$header_depth++;

				}

				continue;

			}

			if ( $tags->is_tag_closer() ) {

				continue;

			}

			if ( 'VIDEO' === $tag ) {

				if ( $poster > 0 ) {

					self::resize_poster( $tags, $poster );

				}

				// The first video is the hero unless an image already is, so it keeps its poster.
				if ( $lazy_poster && null !== $tags->get_attribute( 'data-oa-lazy-video' ) && $header_depth <= 0 && ( $hero_seen || ++$videos > 1 ) ) {

					self::defer_poster( $tags );

				}

				continue;

			}

			if ( 'IMG' !== $tag && 'IFRAME' !== $tag ) {

				continue;

			}

			if ( 'IMG' === $tag ) {

				$image_index++;

				if ( $header_depth <= 0 && 'high' === strtolower( (string) $tags->get_attribute( 'fetchpriority' ) ) ) {

					$hero_seen = true;

				}

				if ( ! empty( $s['dimensions'] ) ) {

					self::add_dimensions( $tags );

				}

				if ( ! empty( $s['header_eager'] ) && $header_depth > 0 && self::load_eagerly( $tags ) ) {

					Octave_Addons_Perf_Log::note( 'media', [ 'tag' => 'img', 'src' => (string) $tags->get_attribute( 'src' ), 'action' => 'skipped', 'reason' => 'site-header' ] );

					continue;

				}

			}

			if ( ( 'IMG' === $tag && ! $do_images ) || ( 'IFRAME' === $tag && ! $do_iframes ) ) {

				continue;

			}

			$reason = self::skip_reason( $tags );

			if ( $prioritise && 'IMG' === $tag && $image_index <= self::PRIORITY_WINDOW && ! in_array( $reason, [ 'no-src', 'other-lazy-loader' ], true ) && self::is_priority_candidate( $tags ) ) {

				$tags->set_attribute( 'fetchpriority', 'high' );
				$prioritise = false;
				$hero_seen  = true;
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

			if ( 'IMG' === $tag && null === $tags->get_attribute( 'decoding' ) ) {

				$tags->set_attribute( 'decoding', 'async' );

			}

		}

		return $tags->get_updated_html();

	}

	/*
	LOAD EAGERLY
	-- An image in the site header is in view on every page, so a builder's
	-- loading="lazy" only delays it. Opted-out images are left as they are
	---------------------------------------------------------- */

	public static function load_eagerly( WP_HTML_Tag_Processor $tags ): bool {

		if ( '' === trim( (string) $tags->get_attribute( 'src' ) . (string) $tags->get_attribute( 'srcset' ) ) ) {

			return false;

		}

		foreach ( self::SKIP_ATTRIBUTES as $attribute ) {

			if ( null !== $tags->get_attribute( $attribute ) ) {

				return false;

			}

		}

		if ( 'lazy' === strtolower( (string) $tags->get_attribute( 'loading' ) ) ) {

			$tags->remove_attribute( 'loading' );

		}

		return true;

	}

	/*
	ADD DIMENSIONS
	-- Gives an image with neither width nor height the size of its file, as
	-- WordPress does for content images, so the browser holds its space. The
	-- size comes from the -WIDTHxHEIGHT suffix of a generated size, or from
	-- the local file itself. Remote files and SVGs are left alone
	---------------------------------------------------------- */

	public static function add_dimensions( WP_HTML_Tag_Processor $tags ): void {

		if ( null !== $tags->get_attribute( 'width' ) || null !== $tags->get_attribute( 'height' ) ) {

			return;

		}

		$url = trim( (string) $tags->get_attribute( 'src' ) );

		if ( '' === $url || 0 === stripos( $url, 'data:' ) ) {

			$url = trim( (string) strtok( (string) $tags->get_attribute( 'srcset' ), ' ,' ) );

		}

		$size = '' !== $url ? self::image_size( $url ) : [];

		if ( ! empty( $size ) ) {

			$tags->set_attribute( 'width', (string) $size[0] );
			$tags->set_attribute( 'height', (string) $size[1] );

		}

	}

	/*
	IMAGE SIZE
	-- [ width, height ] of a same-origin image, or [] when it cannot be known
	---------------------------------------------------------- */

	public static function image_size( string $url ): array {

		static $sizes = [];

		if ( isset( $sizes[ $url ] ) ) {

			return $sizes[ $url ];

		}

		$sizes[ $url ] = [];

		if ( ! Octave_Addons_Perf::is_same_origin( $url ) ) {

			return [];

		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( preg_match( '/-(\d{2,5})x(\d{2,5})\.(?:jpe?g|png|gif|webp|avif)$/i', $path, $match ) ) {

			return $sizes[ $url ] = [ (int) $match[1], (int) $match[2] ];

		}

		$file = Octave_Addons_Perf_Admin::local_path( $url );
		$info = '' !== $file && preg_match( '/\.(?:jpe?g|png|gif|webp|avif)$/i', $file ) ? @getimagesize( $file ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable images are skipped.

		if ( is_array( $info ) && $info[0] > 0 && $info[1] > 0 ) {

			$sizes[ $url ] = [ (int) $info[0], (int) $info[1] ];

		}

		return $sizes[ $url ];

	}

	/*
	RESIZE POSTER
	-- Swaps a full-size video poster for the widest size WordPress generated
	-- from the same upload that is no wider than $max and keeps its shape.
	-- Found on disk beside the original, so it needs no database lookup
	---------------------------------------------------------- */

	public static function resize_poster( WP_HTML_Tag_Processor $tags, int $max ): void {

		$poster = trim( (string) $tags->get_attribute( 'poster' ) );

		if ( '' === $poster || null !== $tags->get_attribute( 'data-oa-no-lazy' ) ) {

			return;

		}

		$smaller = self::smaller_image( $poster, $max );

		if ( '' !== $smaller ) {

			$tags->set_attribute( 'poster', $smaller );

		}

	}

	/*
	DEFER POSTER
	-- Parks the poster in data-oa-poster for the lazy video loader to restore
	-- as the video nears the viewport. A video without width and height takes
	-- the poster's shape as its aspect ratio, so its space is held meanwhile
	---------------------------------------------------------- */

	public static function defer_poster( WP_HTML_Tag_Processor $tags ): void {

		$poster = trim( (string) $tags->get_attribute( 'poster' ) );

		if ( '' === $poster || null !== $tags->get_attribute( 'data-oa-poster' ) ) {

			return;

		}

		$tags->set_attribute( 'data-oa-poster', $poster );
		$tags->remove_attribute( 'poster' );

		$style = trim( (string) $tags->get_attribute( 'style' ) );

		if ( null !== $tags->get_attribute( 'width' ) || null !== $tags->get_attribute( 'height' ) || false !== stripos( $style, 'aspect-ratio' ) ) {

			return;

		}

		$size = self::image_size( $poster );

		if ( ! empty( $size ) ) {

			$tags->set_attribute( 'style', ( '' === $style ? '' : rtrim( $style, ';' ) . '; ' ) . 'aspect-ratio: ' . $size[0] . ' / ' . $size[1] . ';' );

		}

	}

	public static function smaller_image( string $url, int $max ): string {

		static $found = [];

		$key = $max . '|' . $url;

		if ( isset( $found[ $key ] ) ) {

			return $found[ $key ];

		}

		$found[ $key ] = '';
		$path          = (string) wp_parse_url( $url, PHP_URL_PATH );

		// Already a generated size, or not an image this can work with.
		if ( preg_match( '/-\d{2,5}x\d{2,5}\.[a-z0-9]+$/i', $path ) || ! preg_match( '/\.(jpe?g|png|webp)$/i', $path, $extension ) ) {

			return '';

		}

		$file     = Octave_Addons_Perf_Admin::local_path( $url );
		$original = '' !== $file ? @getimagesize( $file ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable images are skipped.

		if ( ! is_array( $original ) || $original[0] <= $max || $original[1] <= 0 ) {

			return '';

		}

		// Generated sizes are named after the original upload, before -scaled or -rotated.
		$stem  = preg_replace( '/-(?:scaled|rotated)$/', '', pathinfo( $file, PATHINFO_FILENAME ) );
		$ratio = $original[0] / $original[1];
		$best  = 0;
		$name  = '';

		foreach ( (array) glob( dirname( $file ) . '/' . addcslashes( $stem, '*?[]\\' ) . '-*x*.' . $extension[1] ) as $candidate ) {

			if ( ! preg_match( '/^' . preg_quote( $stem, '/' ) . '-(\d+)x(\d+)\.' . preg_quote( $extension[1], '/' ) . '$/i', basename( (string) $candidate ), $size ) ) {

				continue;

			}

			$width  = (int) $size[1];
			$height = max( 1, (int) $size[2] );

			if ( $width > $max || $width < self::POSTER_MIN_WIDTH || $width <= $best || abs( $width / $height - $ratio ) > 0.02 * $ratio ) {

				continue;

			}

			$best = $width;
			$name = basename( (string) $candidate );

		}

		if ( '' !== $name ) {

			$found[ $key ] = substr( $url, 0, (int) strrpos( $url, '/' ) + 1 ) . $name;

		}

		return $found[ $key ];

	}

	/*
	IS PRIORITY CANDIDATE
	-- The likely Largest Contentful Paint image: the first image near the top
	-- of the page at least 400px wide that nothing has marked lazy or low
	-- priority. Logos and icons are narrower, so they are passed over
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
	RENDER SETTINGS
	---------------------------------------------------------- */

	public function render_settings( array $s ): void {

		$lazy_owner = Octave_Addons_Perf::handled_elsewhere( 'lazy_images' );

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

			$this->switch_row( 'images', __( 'Images', 'octave-addons' ), __( 'Safe. Adds loading="lazy" and decoding="async" to images. The hero image is fetched first with fetchpriority="high"; add data-oa-no-priority to an image to pass it over. Images marked eager, high priority or opted out are left alone.', 'octave-addons' ), $s );
			$this->switch_row( 'iframes', __( 'Iframes', 'octave-addons' ), __( 'Safe. Maps, embeds and other iframes load as they approach the viewport.', 'octave-addons' ), $s );
			$this->switch_row( 'videos', __( 'Videos', 'octave-addons' ), __( 'Safe. HTML5 and Breakdance background videos wait until the page has loaded and they near the viewport before downloading; autoplay resumes then.', 'octave-addons' ), $s );

			Octave_Addons_Fields::section( [ 'label' => __( 'Builder fixes', 'octave-addons' ) ] );

			$this->switch_row( 'header_eager', __( 'Load header images straight away', 'octave-addons' ), __( 'Safe. The logo and other images in the site header are in view on every page, so a loading="lazy" a builder adds to them is removed.', 'octave-addons' ), $s );
			$this->switch_row( 'dimensions', __( 'Add missing image dimensions', 'octave-addons' ), __( 'Adds width and height from the file to images that have neither, so their space is held while they load and the page does not jump. Check images still keep their shape after turning this on.', 'octave-addons' ), $s );

			$this->select_row( 'poster_width', __( 'Video poster size', 'octave-addons' ), [
				768  => __( 'Up to 768px wide (recommended)', 'octave-addons' ),
				1024 => __( 'Up to 1024px wide', 'octave-addons' ),
				0    => __( 'Full size, as uploaded', 'octave-addons' ),
			], __( 'Video posters use a smaller size WordPress has already made from the same upload, instead of the full-size image. Posters that are not media library uploads are left alone.', 'octave-addons' ), $s );

			$this->switch_row( 'lazy_posters', __( 'Load video posters as they near the viewport', 'octave-addons' ), __( 'Needs Videos above. The first video keeps its poster, unless a hero image comes before it, as do videos in the site header. Every other poster waits until its video is close to view. Add data-oa-no-lazy to a video to keep its poster.', 'octave-addons' ), $s );

			Octave_Addons_Fields::section( [ 'label' => __( 'Exclusions', 'octave-addons' ) ] );

			$this->textarea_row( 'exclude_classes', __( 'Classes', 'octave-addons' ), __( 'One class per line. skip-lazy, no-lazy and oa-no-lazy are always excluded.', 'octave-addons' ), $s );
			$this->textarea_row( 'exclude_attributes', __( 'Attributes', 'octave-addons' ), __( 'One per line, as name or name=value. data-no-lazy, data-skip-lazy and data-oa-no-lazy are always excluded.', 'octave-addons' ), $s );
			$this->textarea_row( 'exclude_urls', __( 'URL patterns', 'octave-addons' ), __( 'One per line. Any image, iframe or video whose URL contains the text is excluded.', 'octave-addons' ), $s );

			?>
		</table>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Media();
