<?php

/*
PERFORMANCE: MEDIA LAZY LOADING
-- Native browser lazy loading for images and iframes. Works with the
-- always-on Breakdance Lazy Load module rather than beside it: that module
-- keeps handling HTML5 and background videos, and this one decides whether it may
-- Only attributes are added (loading, decoding, fetchpriority). src, srcset,
-- sizes, width, height and picture/source markup are never touched, so WebP
-- and AVIF sources, CDN rewrites and responsive images keep working as delivered
-- The likely hero image is kept eager with high priority. Every other image
-- is left to the browser, which loads lazy images already in view straight away
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
			'exclude_classes'    => '',
			'exclude_attributes' => '',
			'exclude_urls'       => '',
		];

	}

	public function sanitize( $input ): array {

		$clean = parent::sanitize( $input );

		foreach ( [ 'images', 'iframes', 'videos' ] as $key ) {

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

		if ( ! empty( $s['images'] ) || ! empty( $s['iframes'] ) ) {

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

			$reason = self::skip_reason( $tags );

			if ( $prioritise && 'IMG' === $tag && $image_index <= self::PRIORITY_WINDOW && ! in_array( $reason, [ 'no-src', 'other-lazy-loader' ], true ) && self::is_priority_candidate( $tags ) ) {

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

			if ( 'IMG' === $tag && null === $tags->get_attribute( 'decoding' ) ) {

				$tags->set_attribute( 'decoding', 'async' );

			}

		}

		return $tags->get_updated_html();

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
			$this->switch_row( 'videos', __( 'Videos', 'octave-addons' ), __( 'Safe. HTML5 and Breakdance background videos wait until they near the viewport before downloading; autoplay resumes then.', 'octave-addons' ), $s );

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
