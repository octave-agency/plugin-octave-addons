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
-- Two optional fixes for what page builders print: images in the site
-- header always load eagerly, images without dimensions get width and height
-- from the file, so the space is held before they arrive
-- Posters of lazy videos below the hero wait until they near the viewport
-- On Breakdance pages the hero section's background is read from the
-- page's own Breakdance CSS and preloaded per screen width from the first
-- view (see class-hero.php). Each page's real Largest Contentful Paint
-- image, as browsers report it, then corrects any guess: an <img> is
-- fetched first, and a CSS background or video poster is preloaded for the
-- screen sizes that reported it
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

	protected array $settings = [];

	public function get_id(): string {

		return 'performance-media';

	}

	public function get_title(): string {

		return __( 'Lazy Loading', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Loads images, videos and embeds only as visitors scroll to them, while the main image at the top loads first.', 'octave-addons' );

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
			'lazy_posters'       => true,
			'lcp'                => true,
			'exclude_classes'    => '',
			'exclude_attributes' => '',
			'exclude_urls'       => '',
		];

	}

	public function sanitize( $input ): array {

		$clean = parent::sanitize( $input );

		foreach ( [ 'images', 'iframes', 'videos', 'header_eager', 'dimensions', 'lazy_posters', 'lcp' ] as $key ) {

			$clean[ $key ] = ! empty( $input[ $key ] );

		}

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

		if ( ! empty( $s['lcp'] ) ) {

			Octave_Addons_Perf_Lcp::boot();

		}

		if ( ! empty( $s['images'] ) || ! empty( $s['iframes'] ) || ! empty( $s['header_eager'] ) || ! empty( $s['dimensions'] ) || ! empty( $s['lazy_posters'] ) || ! empty( $s['lcp'] ) ) {

			Octave_Addons_Perf_Html::register( 'media', [ $this, 'transform' ], 20, [ $this, 'is_needed' ] );

		}

	}

	/*
	IS NEEDED
	-- False when another plugin lazy loads every kind of media this module
	-- would and none of its other fixes is on, so no buffer is started
	---------------------------------------------------------- */

	public function is_needed(): bool {

		$s = $this->settings;

		foreach ( [ 'header_eager', 'dimensions', 'lazy_posters', 'lcp' ] as $key ) {

			if ( ! empty( $s[ $key ] ) ) {

				return true;

			}

		}

		return ( ! empty( $s['images'] ) && '' === Octave_Addons_Perf::handled_elsewhere( 'lazy_images' ) )
			|| ( ! empty( $s['iframes'] ) && '' === Octave_Addons_Perf::handled_elsewhere( 'lazy_iframes' ) );

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
		// Only an image or a preload can use fetchpriority; on a <section> or <div> it does nothing.
		$prioritise  = $do_images && ! preg_match( '#<(?:img|link)\b[^>]*\bfetchpriority\s*=\s*["\']?high#i', $html );
		$lazy_poster = ! empty( $s['lazy_posters'] ) && ! empty( $s['videos'] );
		$hero_seen   = false;
		$videos      = 0;
		$lcp_path    = ! empty( $s['lcp'] ) ? Octave_Addons_Perf_Lcp::request_path() : '';
		$lcp         = '' !== $lcp_path ? Octave_Addons_Perf_Lcp::entry( $lcp_path ) : [];
		$lcp_images  = [];
		$lcp_posters = [];

		foreach ( Octave_Addons_Perf_Lcp::DEVICES as $device ) {

			$kind = (string) ( $lcp[ $device ]['kind'] ?? '' );

			if ( 'img' === $kind ) {

				$lcp_images[] = (string) $lcp[ $device ]['url'];

			} elseif ( 'poster' === $kind ) {

				$lcp_posters[] = (string) $lcp[ $device ]['url'];

			}

		}

		$hero = ! empty( $s['lcp'] ) ? self::hero( $html, $lcp ) : [];

		// A reported image, or a Breakdance hero background found in the page, replaces the guess.
		if ( ! empty( $lcp_images ) || ! empty( $hero ) ) {

			$prioritise = false;

		}

		// With the hero known to be a background, no video poster is the hero, so the first one may wait too.
		$hero_seen = ! empty( $hero );

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

				// The reported LCP poster, or else the first video unless an image is the hero, keeps its poster.
				if ( self::matches_any_file( (string) $tags->get_attribute( 'poster' ), $lcp_posters ) ) {

					continue;

				}

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

				$high = 'high' === strtolower( (string) $tags->get_attribute( 'fetchpriority' ) );

				// A builder can print both; lazy wins in the browser and the hero waits.
				if ( $high && 'lazy' === strtolower( (string) $tags->get_attribute( 'loading' ) ) ) {

					$tags->remove_attribute( 'loading' );

				}

				if ( $header_depth <= 0 && $high ) {

					$hero_seen = true;

				}

				if ( ! empty( $lcp_images ) && self::is_lcp_image( $tags, $lcp_images ) ) {

					$tags->set_attribute( 'fetchpriority', 'high' );
					$tags->remove_attribute( 'loading' );
					$hero_seen = true;

					Octave_Addons_Perf_Log::note( 'media', [ 'tag' => 'img', 'src' => (string) $tags->get_attribute( 'src' ), 'action' => 'skipped', 'reason' => 'reported-lcp' ] );

					continue;

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

		$html = $tags->get_updated_html();

		// The page's own hero goes in first, so a learned preload for the same image is not repeated.
		$hero_markup = ! empty( $hero ) ? Octave_Addons_Perf_Hero::markup( $hero, $html ) : '';

		if ( '' !== $hero_markup ) {

			$html = self::inject_in_head( $html, $hero_markup );

			Octave_Addons_Perf_Log::summary( 'lcp_hero', $hero );

		}

		if ( '' === $lcp_path ) {

			return $html;

		}

		$head = Octave_Addons_Perf_Lcp::preload_markup( $lcp, $html );

		if ( '' !== $head ) {

			$html = self::inject_in_head( $html, $head );

		}

		$stale = Octave_Addons_Perf_Lcp::stale_devices( $lcp );

		if ( ! empty( $stale ) ) {

			$html = Octave_Addons_Perf_Html::inject_before_body_end( $html, Octave_Addons_Perf_Lcp::reporter( $lcp_path, $stale ) );

		}

		return $html;

	}

	/*
	HERO
	-- The Breakdance hero background found in the page itself, so the first
	-- visitor is already served its preload. Browsers' reports stay in
	-- charge: when a fresh report names a different image for either
	-- screen size, the page's guess is set aside and the report is used
	---------------------------------------------------------- */

	protected static function hero( string $html, array $lcp ): array {

		$hero = Octave_Addons_Perf_Hero::discover( $html );

		if ( empty( $hero['preloads'] ) ) {

			return [];

		}

		$urls = array_column( $hero['preloads'], 'url' );

		foreach ( Octave_Addons_Perf_Lcp::DEVICES as $device ) {

			$record = (array) ( $lcp[ $device ] ?? [] );

			if ( Octave_Addons_Perf_Lcp::is_stale( $lcp, $device ) || ! in_array( $record['kind'] ?? '', [ 'img', 'bg', 'poster' ], true ) ) {

				continue;

			}

			if ( ! self::matches_any_file( (string) ( $record['url'] ?? '' ), $urls ) ) {

				return [];

			}

		}

		return $hero;

	}

	/*
	LCP HELPERS
	-- Matching against reported URLs ignores host and query, so a CDN
	-- rewrite or cache-busting version still matches
	---------------------------------------------------------- */

	protected static function matches_any_file( string $url, array $urls ): bool {

		foreach ( $urls as $candidate ) {

			if ( Octave_Addons_Perf_Lcp::same_file( $url, $candidate ) ) {

				return true;

			}

		}

		return false;

	}

	protected static function is_lcp_image( WP_HTML_Tag_Processor $tags, array $urls ): bool {

		$src    = (string) $tags->get_attribute( 'src' );
		$srcset = (string) $tags->get_attribute( 'srcset' );

		foreach ( $urls as $url ) {

			if ( Octave_Addons_Perf_Lcp::same_file( $src, $url ) || ( '' !== $srcset && Octave_Addons_Perf_Lcp::in_srcset( $srcset, $url ) ) ) {

				return true;

			}

		}

		return false;

	}

	/*
	INJECT IN HEAD
	-- Straight after the charset declaration (or the <head> tag), so the
	-- preload is found before any stylesheet is requested
	---------------------------------------------------------- */

	public static function inject_in_head( string $html, string $markup ): string {

		$end = stripos( $html, '</head>' );

		if ( false === $end ) {

			return $html;

		}

		if ( preg_match( '#<meta\b[^>]*charset[^>]*>#i', substr( $html, 0, $end ), $match, PREG_OFFSET_CAPTURE ) ) {

			$at = $match[0][1] + strlen( $match[0][0] );

		} elseif ( preg_match( '#<head\b[^>]*>#i', $html, $match, PREG_OFFSET_CAPTURE ) && $match[0][1] < $end ) {

			$at = $match[0][1] + strlen( $match[0][0] );

		} else {

			$at = $end;

		}

		return substr( $html, 0, $at ) . "\n" . $markup . substr( $html, $at );

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
					esc_html__( '%s already loads images as visitors scroll, so Octave leaves images to it.', 'octave-addons' ),
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

			Octave_Addons_Fields::section( [ 'label' => __( 'Load as visitors scroll', 'octave-addons' ), 'first' => true ] );

			$this->switch_row( 'images', __( 'Images', 'octave-addons' ), __( 'Recommended. Images further down the page wait until visitors scroll near them, and the main image at the top loads first. Images that are already set to load straight away are left as they are. Tip: add data-oa-no-priority to an image that should not be treated as the main image.', 'octave-addons' ), $s );
			$this->switch_row( 'iframes', __( 'Iframes', 'octave-addons' ), __( 'Recommended. Maps, videos from YouTube or Vimeo, and other embeds load as visitors scroll near them.', 'octave-addons' ), $s );
			$this->switch_row( 'videos', __( 'Videos', 'octave-addons' ), ( Octave_Addons_Builders::breakdance() ? __( 'Recommended. Videos (from WordPress, and Breakdance videos and background videos) download nothing until the page has finished loading and the video is on screen. Autoplaying videos start then.', 'octave-addons' ) : __( 'Recommended. Videos added through WordPress download nothing until the page has finished loading and the video is on screen. Autoplaying videos start then.', 'octave-addons' ) ) . ' ' . __( 'On slow connections or with data saving on, visitors see the cover image and the video loads only when they press play.', 'octave-addons' ), $s );

			Octave_Addons_Fields::section( [ 'label' => __( 'Page-builder fixes', 'octave-addons' ) ] );

			$this->switch_row( 'header_eager', __( 'Load header images straight away', 'octave-addons' ), __( 'Recommended. Your logo and other header images are visible on every page, so they always load straight away, even if your page builder set them to wait.', 'octave-addons' ), $s );
			$this->switch_row( 'dimensions', __( 'Add missing image dimensions', 'octave-addons' ), __( 'Reserves the right space for images that have no size set, so the page does not jump as they appear. After turning this on, check your images still look the right shape.', 'octave-addons' ), $s );


			$this->switch_row( 'lcp', __( 'Load the main image first', 'octave-addons' ), ( empty( Octave_Addons_Builders::active() ) ? __( 'Safe.', 'octave-addons' ) . ' ' : sprintf(
				/* translators: %s: page builder names, e.g. Breakdance or Divi. */
				__( 'Recommended. On %s pages, the background image of the first section starts loading straight away, with the right size for phones, tablets and computers.', 'octave-addons' ) . ' ',
				implode( ' / ', array_map( [ 'Octave_Addons_Builders', 'label' ], Octave_Addons_Builders::active() ) )
			) ) . __( 'Octave also learns which image appears biggest as each page loads, separately for phones and larger screens, and checks again every week, so that image is always fetched first.', 'octave-addons' ), $s );
			$this->switch_row( 'lazy_posters', __( 'Load video cover images as visitors scroll', 'octave-addons' ), __( 'Needs Videos above. Each video\'s cover image waits until visitors scroll near it, except for videos in the header, and the first video when nothing above it is the main image. Tip: add data-oa-no-lazy to a video to always load its cover image.', 'octave-addons' ), $s );

			Octave_Addons_Fields::section( [ 'label' => __( 'Always load straight away', 'octave-addons' ) ] );

			$this->textarea_row( 'exclude_classes', __( 'Classes', 'octave-addons' ), __( 'Images or videos with any of these CSS classes always load straight away. One class per line. skip-lazy, no-lazy and oa-no-lazy are always included.', 'octave-addons' ), $s );
			$this->textarea_row( 'exclude_attributes', __( 'Attributes', 'octave-addons' ), __( 'For developers: elements with any of these HTML attributes always load straight away. One per line, as name or name=value. data-no-lazy, data-skip-lazy and data-oa-no-lazy are always included.', 'octave-addons' ), $s );
			$this->textarea_row( 'exclude_urls', __( 'URL patterns', 'octave-addons' ), __( 'Any image, video or embed whose address contains one of these words always loads straight away. One per line.', 'octave-addons' ), $s );

			?>
		</table>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Media();
