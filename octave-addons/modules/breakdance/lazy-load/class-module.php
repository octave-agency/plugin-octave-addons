<?php

/*
BREAKDANCE LAZY LOAD
-- Decides who lazy loads Breakdance media. Breakdance's own image "Lazy
-- Load" toggles use a script and data-src markup, so when Octave's Media
-- Lazy Loading or a detected third-party plugin lazy loads images, those
-- toggles are removed from the builder and forced off in the defaults and
-- the rendered output, so the page is never processed twice. With no other
-- owner, Breakdance keeps its toggles, defaults and saved values untouched
-- Breakdance has no iframe lazy-load toggles; iframes belong to the Media
-- module, a third-party plugin or the browser
-- Videos are always handled here and lazy loaded by default: Breakdance
-- Video elements use their lightweight YouTube/Vimeo players, HTML5 and
-- section background videos have their src and <source src> parked in
-- data-oa-src with preload="none", so nothing downloads until a small
-- viewport loader activates them once they are actually in view, and
-- provider iframes receive loading="lazy". A <noscript> copy of each
-- parked video keeps it playable without JavaScript
-- Rewrites run only on known video markup through WP_HTML_Tag_Processor,
-- never on the whole page
-- Always on and hidden from the admin. The Media Lazy Loading module can
-- switch video handling off (octave_addons_lazy_videos) and its exclusions
-- apply here too (octave_addons_perf_skip_lazy)
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once OCTAVE_ADDONS_DIR . 'modules/performance/services/bootstrap.php';

class Octave_Addons_Module_Breakdance_Lazy_Load extends Octave_Addons_Module {

	/**
	 * Property key every Breakdance Lazy Load toggle writes to.
	 */
	protected const LAZY_KEY = 'lazy_load';

	/**
	 * Breakdance Video element type.
	 */
	protected const VIDEO_TYPE = 'EssentialElements\\Video';

	/**
	 * Script handle for the viewport video loader.
	 */
	protected const SCRIPT_HANDLE = 'octave-addons-lazy-video';

	/**
	 * Whether this request rendered a video that needs the viewport loader.
	 */
	protected static $needs_script = false;

	/**
	 * Who lazy loads images this request, once decided.
	 */
	protected static ?string $image_owner = null;

	/*
	GET ID
	-- Returns the module settings key
	---------------------------------------------------------- */

	public function get_id(): string {

		return 'breakdance-lazy-load';

	}

	/*
	GET TITLE
	-- Names the module for anything that lists modules internally
	---------------------------------------------------------- */

	public function get_title(): string {

		return __( 'Breakdance Lazy Load', 'octave-addons' );

	}

	/*
	GET DESCRIPTION
	-- Describes the module for anything that lists modules internally
	---------------------------------------------------------- */

	public function get_description(): string {

		return __( 'Hands Breakdance image lazy loading to Performance > Lazy Loading or a third-party plugin when one owns it, and loads videos lazily as they approach the viewport.', 'octave-addons' );

	}

	/*
	GET REQUIRES
	-- None: besides Breakdance's toggles it lazy loads WordPress video blocks,
	-- the [video] shortcode and oEmbed players on every site
	---------------------------------------------------------- */

	public function get_requires(): ?array {

		return [];

	}

	/*
	LAZY OWNER
	-- Who lazy loads images or iframes: a third-party plugin first, since
	-- Octave steps aside for it, then Media Lazy Loading. '' means nobody,
	-- which for images leaves the job to Breakdance's own toggles
	---------------------------------------------------------- */

	public static function lazy_owner( string $feature ): string {

		$other = Octave_Addons_Perf::handled_elsewhere( $feature );

		if ( '' !== $other ) {

			return $other;

		}

		$media = Octave_Addons_Perf::settings( 'performance-media' );
		$key   = 'lazy_iframes' === $feature ? 'iframes' : 'images';

		return ! empty( $media['enabled'] ) && ! empty( $media[ $key ] ) ? __( 'Octave Media Lazy Loading', 'octave-addons' ) : '';

	}

	/*
	OWNS BREAKDANCE IMAGES
	-- Whether Breakdance's image toggles are taken over. Decided once per
	-- request, so the builder and the rendered page always agree
	---------------------------------------------------------- */

	public static function suppresses_breakdance_toggles(): bool {

		if ( null === self::$image_owner ) {

			self::$image_owner = self::lazy_owner( 'lazy_images' );

		}

		return '' !== self::$image_owner;

	}

	public static function reset(): void {

		self::$image_owner  = null;
		self::$needs_script = false;

	}

	/*
	OWNERSHIP
	-- Who lazy loads each kind of media, for the Performance page
	---------------------------------------------------------- */

	public static function ownership(): array {

		$images  = self::lazy_owner( 'lazy_images' );
		$iframes = self::lazy_owner( 'lazy_iframes' );

		if ( '' === $images ) {

			$images = class_exists( 'Octave_Addons' ) && Octave_Addons::is_breakdance_active() ? __( 'Breakdance (its own Lazy Load toggles)', 'octave-addons' ) : __( 'Nobody (browser default)', 'octave-addons' );

		}

		/** This filter is documented in modules/breakdance/lazy-load/class-module.php */
		$videos = (bool) apply_filters( 'octave_addons_lazy_videos', true );

		return [
			'lazy_images'  => $images,
			'lazy_iframes' => '' !== $iframes ? $iframes : __( 'Nobody (browser default)', 'octave-addons' ),
			'lazy_videos'  => $videos ? __( 'Octave Breakdance video loader', 'octave-addons' ) : __( 'Nobody (switched off in Lazy Loading)', 'octave-addons' ),
		];

	}

	/*
	SHOW IN ADMIN
	-- Hidden: the policy is fixed, so there is nothing to present
	---------------------------------------------------------- */

	public function show_in_admin(): bool {

		return false;

	}

	/*
	IS ALWAYS ENABLED
	-- Runs regardless of saved settings
	---------------------------------------------------------- */

	public function is_always_enabled(): bool {

		return true;

	}

	/*
	RUN
	-- Registers the Breakdance property filters and the video markup filters
	---------------------------------------------------------- */

	public function run( array $s ): void {

		add_filter( 'breakdance_element_controls', [ __CLASS__, 'filter_controls' ] );
		add_filter( 'breakdance_element_default_properties', [ __CLASS__, 'filter_default_properties' ] );
		add_filter( 'breakdance_before_render_node', [ __CLASS__, 'filter_render_node' ] );

		add_filter( 'breakdance_render_element_html', [ __CLASS__, 'filter_video_element_html' ], 10, 2 );
		add_filter( 'render_block_core/video', [ __CLASS__, 'filter_video_markup' ] );
		add_filter( 'wp_video_shortcode', [ __CLASS__, 'filter_video_markup' ] );
		add_filter( 'render_block_core/embed', [ __CLASS__, 'filter_video_markup' ] );
		add_filter( 'embed_oembed_html', [ __CLASS__, 'filter_video_markup' ] );

		add_action( 'wp_footer', [ __CLASS__, 'print_late_script' ], 100 );

	}

	/*
	FILTER CONTROLS
	-- Removes every Lazy Load toggle from the builder panels while another
	-- owner lazy loads images, so editors are not offered a switch that
	-- would be overridden at render anyway
	---------------------------------------------------------- */

	public static function filter_controls( $controls ) {

		if ( ! is_array( $controls ) || ! self::suppresses_breakdance_toggles() ) {

			return $controls;

		}

		return self::remove_lazy_toggles( $controls );

	}

	/*
	REMOVE LAZY TOGGLES
	-- Only toggles named lazy_load go. The Video element's Lazy Load section
	-- styles its play button, so sections of that name are kept
	---------------------------------------------------------- */

	protected static function remove_lazy_toggles( array $controls ): array {

		$is_list = array_keys( $controls ) === range( 0, count( $controls ) - 1 );

		foreach ( $controls as $key => $control ) {

			if ( ! is_array( $control ) ) {

				continue;

			}

			if ( self::LAZY_KEY === ( $control['slug'] ?? '' ) && 'toggle' === ( $control['options']['type'] ?? '' ) ) {

				unset( $controls[ $key ] );

				continue;

			}

			$controls[ $key ] = self::remove_lazy_toggles( $control );

		}

		return $is_list ? array_values( $controls ) : $controls;

	}

	/*
	FILTER DEFAULT PROPERTIES
	-- Breakdance hands the builder each element's starting properties here, so
	-- an element dropped onto the canvas arrives with Lazy Load already off
	-- while another owner lazy loads images
	-- Elements with no defaults return false rather than an array
	---------------------------------------------------------- */

	public static function filter_default_properties( $properties ) {

		if ( ! is_array( $properties ) ) {

			return $properties;

		}

		if ( self::suppresses_breakdance_toggles() ) {

			$properties = self::disable_lazy_load( $properties );

		}

		// Defaults carry no element type, so a Video is recognised by its shape.
		if ( isset( $properties['content']['video']['video'] ) ) {

			$properties = self::apply_video_defaults( $properties );

		}

		return $properties;

	}

	/*
	FILTER RENDER NODE
	-- Catches everything the defaults filter cannot reach: saved pages, nested
	-- child elements shipped inside sliders and accordions, and any toggle an
	-- editor has switched back on. Saved values are only overridden at render,
	-- never rewritten, so they return when nobody else owns lazy loading
	---------------------------------------------------------- */

	public static function filter_render_node( $node ) {

		if ( ! is_array( $node ) || empty( $node['data']['properties'] ) || ! is_array( $node['data']['properties'] ) ) {

			return $node;

		}

		if ( self::suppresses_breakdance_toggles() ) {

			$node['data']['properties'] = self::disable_lazy_load( $node['data']['properties'] );

		}

		if ( self::VIDEO_TYPE === ( $node['data']['type'] ?? '' ) ) {

			$node['data']['properties'] = self::apply_video_defaults( $node['data']['properties'] );

		}

		return $node;

	}

	/*
	APPLY VIDEO DEFAULTS
	-- Fills an unset Load Method with the lightest option that keeps the
	-- element's behaviour: YouTube and Vimeo use their lightweight facades, so
	-- the real player loads only on click, unless autoplay is on, which the
	-- facades cannot honour, so those use Breakdance's viewport lazy load
	-- An explicit choice an editor made is always respected
	---------------------------------------------------------- */

	protected static function apply_video_defaults( array $properties ): array {

		$methods = [
			'youtube'     => 'lightweight',
			'vimeo'       => 'lightweight',
			'dailymotion' => 'lazyload',
		];

		foreach ( $methods as $provider => $method ) {

			$settings = $properties['content'][ $provider ] ?? [];

			if ( ! is_array( $settings ) || ! empty( $settings['loading_method'] ) ) {

				continue;

			}

			if ( 'lightweight' === $method && ! empty( $settings['autoplay'] ) ) {

				$method = 'lazyload';

			}

			$properties['content'][ $provider ]['loading_method'] = $method;

		}

		return $properties;

	}

	/*
	FILTER VIDEO ELEMENT HTML
	-- Applies the video markup policy to Breakdance Video elements, and to
	-- the background video of any element using a video background
	---------------------------------------------------------- */

	public static function filter_video_element_html( $html, $node ) {

		if ( ! is_array( $node ) ) {

			return $html;

		}

		if ( self::VIDEO_TYPE === ( $node['data']['type'] ?? '' ) ) {

			return self::filter_video_markup( $html );

		}

		return self::filter_background_video( $html );

	}

	/*
	FILTER BACKGROUND VIDEO
	-- Breakdance prints background videos with a hardcoded autoplay, so each
	-- one downloads in full on page load wherever it sits on the page
	-- An element's HTML includes its children, so only the video directly
	-- inside .section-background-video is touched, never other child markup
	---------------------------------------------------------- */

	protected static function filter_background_video( $html ) {

		if ( ! is_string( $html ) || false === strpos( $html, 'section-background-video' ) || ! self::should_rewrite() ) {

			return $html;

		}

		$tags   = new WP_HTML_Tag_Processor( $html );
		$parked = [];
		$index  = 0;

		while ( $tags->next_tag( [ 'tag_closers' => 'visit' ] ) ) {

			$tag = $tags->get_tag();

			if ( 'VIDEO' === $tag && ! $tags->is_tag_closer() ) {

				$index++;

				continue;

			}

			if ( $tags->is_tag_closer() || ! $tags->has_class( 'section-background-video' ) ) {

				continue;

			}

			if ( $tags->next_tag() && 'VIDEO' === $tags->get_tag() ) {

				$index++;

				if ( self::defer_video( $tags ) ) {

					$parked[] = $index;

					self::park_sources( $tags );

				}

			}

		}

		return self::add_fallbacks( $html, $tags->get_updated_html(), $parked );

	}

	/*
	FILTER VIDEO MARKUP
	-- Shared by the Breakdance Video element, the core video and embed blocks,
	-- the [video] shortcode and oEmbed output. Each filter hands over one small
	-- fragment of known video markup rather than the whole page.
	-- Videos inside <noscript>, such as the fallback copies added here, are
	-- left as they are. Without WP_HTML_Tag_Processor (WordPress before 6.2)
	-- markup is untouched
	---------------------------------------------------------- */

	public static function filter_video_markup( $html ) {

		if ( ! is_string( $html ) || '' === $html || ! self::should_rewrite() ) {

			return $html;

		}

		if ( false === stripos( $html, '<video' ) && false === stripos( $html, '<iframe' ) ) {

			return $html;

		}

		$tags     = new WP_HTML_Tag_Processor( $html );
		$noscript = 0;
		$parked   = [];
		$index    = 0;

		while ( $tags->next_tag( [ 'tag_closers' => 'visit' ] ) ) {

			$tag = $tags->get_tag();

			if ( 'NOSCRIPT' === $tag ) {

				$noscript += $tags->is_tag_closer() ? -1 : 1;

				continue;

			}

			if ( $tags->is_tag_closer() ) {

				continue;

			}

			if ( 'VIDEO' === $tag ) {

				$index++;

				if ( $noscript <= 0 && self::defer_video( $tags ) ) {

					$parked[] = $index;

					self::park_sources( $tags );

				}

				continue;

			}

			if ( 'IFRAME' === $tag && $noscript <= 0 && null === $tags->get_attribute( 'loading' ) && null !== $tags->get_attribute( 'src' ) && ! self::is_opted_out( $tags ) ) {

				$tags->set_attribute( 'loading', 'lazy' );

			}

		}

		return self::add_fallbacks( $html, $tags->get_updated_html(), $parked );

	}

	/*
	DEFER VIDEO
	-- Parks an HTML5 video until the viewport loader activates it: its src
	-- moves to data-oa-src and autoplay to data-oa-autoplay, and it starts
	-- with preload="none", so nothing downloads speculatively and an
	-- offscreen autoplay video neither loads nor plays. Poster, controls,
	-- tracks, loop, muted and playsinline are untouched, and a local poster
	-- with WordPress sizes gets data-oa-poster-srcset so the loader can pick
	-- the size it is shown at. Returns whether the video was parked here.
	-- Videos opted out of lazy loading, or excluded in Lazy Loading, are skipped
	---------------------------------------------------------- */

	protected static function defer_video( WP_HTML_Tag_Processor $tags ): bool {

		if ( null !== $tags->get_attribute( 'data-oa-lazy-video' ) ) {

			return false;

		}

		if ( self::is_opted_out( $tags ) ) {

			return false;

		}

		$preload = strtolower( (string) $tags->get_attribute( 'preload' ) );
		$src     = trim( (string) $tags->get_attribute( 'src' ) );

		$tags->set_attribute( 'data-oa-lazy-video', '' );
		$tags->set_attribute( 'data-oa-preload', in_array( $preload, [ 'auto', 'metadata' ], true ) ? $preload : 'metadata' );
		$tags->set_attribute( 'preload', 'none' );

		if ( '' !== $src ) {

			$tags->set_attribute( 'data-oa-src', $src );
			$tags->remove_attribute( 'src' );

			self::note_deferred( $src );

		}

		if ( null !== $tags->get_attribute( 'autoplay' ) ) {

			$tags->remove_attribute( 'autoplay' );
			$tags->set_attribute( 'data-oa-autoplay', '' );

		}

		$srcset = self::poster_srcset( (string) $tags->get_attribute( 'poster' ) );

		if ( '' !== $srcset ) {

			$tags->set_attribute( 'data-oa-poster-srcset', $srcset );

		}

		self::enqueue_script();

		return true;

	}

	/*
	PARK SOURCES
	-- Moves the src of every <source> inside the video just parked to
	-- data-oa-src, stopping at its closing tag. <track> keeps its src
	---------------------------------------------------------- */

	protected static function park_sources( WP_HTML_Tag_Processor $tags ): void {

		while ( $tags->next_tag( [ 'tag_closers' => 'visit' ] ) ) {

			$tag = $tags->get_tag();

			if ( 'VIDEO' === $tag && $tags->is_tag_closer() ) {

				return;

			}

			if ( 'SOURCE' !== $tag || $tags->is_tag_closer() ) {

				continue;

			}

			$src = trim( (string) $tags->get_attribute( 'src' ) );

			if ( '' !== $src ) {

				$tags->set_attribute( 'data-oa-src', $src );
				$tags->remove_attribute( 'src' );

				self::note_deferred( $src );

			}

		}

	}

	/*
	ADD FALLBACKS
	-- Without JavaScript a parked video could never load, so each one is
	-- followed by its original markup inside <noscript>. With JavaScript
	-- that copy is never parsed; without it, a stylesheet printed in the
	-- footer hides the parked video and the original plays instead.
	-- Skipped when the videos cannot be paired up one to one
	---------------------------------------------------------- */

	protected static function add_fallbacks( string $original, string $updated, array $parked ): string {

		if ( empty( $parked ) ) {

			return $updated;

		}

		$pattern = '#<video\b[^>]*>.*?</video>#is';

		preg_match_all( $pattern, $original, $before );

		if ( count( $before[0] ) !== preg_match_all( $pattern, $updated ) ) {

			return $updated;

		}

		$index = 0;

		return (string) preg_replace_callback( $pattern, static function ( array $match ) use ( &$index, $parked, $before ): string {

			$index++;

			return in_array( $index, $parked, true ) ? $match[0] . '<noscript>' . $before[0][ $index - 1 ] . '</noscript>' : $match[0];

		}, $updated );

	}

	/*
	POSTER SRCSET
	-- The WordPress sizes of a local poster image, as a srcset, or ''
	---------------------------------------------------------- */

	protected static function poster_srcset( string $poster ): string {

		if ( '' === $poster || ! function_exists( 'attachment_url_to_postid' ) || ! function_exists( 'wp_get_attachment_image_srcset' ) || ! Octave_Addons_Perf::is_same_origin( $poster ) ) {

			return '';

		}

		$cached = wp_cache_get( md5( $poster ), 'octave_addons_poster_srcset' );

		if ( is_string( $cached ) ) {

			return $cached;

		}

		// Generated sizes carry -WIDTHxHEIGHT; the attachment is found from the original's URL.
		$id     = (int) attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $poster ) );
		$srcset = $id > 0 ? (string) wp_get_attachment_image_srcset( $id, 'full' ) : '';

		wp_cache_set( md5( $poster ), $srcset, 'octave_addons_poster_srcset', DAY_IN_SECONDS );

		return $srcset;

	}

	/*
	NOTE DEFERRED
	-- Records a parked video file for diagnostics, with its size when it
	-- is a local file
	---------------------------------------------------------- */

	protected static function note_deferred( string $src ): void {

		if ( ! class_exists( 'Octave_Addons_Perf_Log' ) || ! Octave_Addons_Perf_Log::is_reporting() ) {

			return;

		}

		$file = Octave_Addons_Perf_Admin::local_path( $src );

		Octave_Addons_Perf_Log::note( 'videos', [ 'src' => $src, 'bytes' => '' !== $file ? (int) filesize( $file ) : 0 ] );

	}

	/*
	SHOULD REWRITE
	-- Frontend page requests only: builder canvases, Breakdance server-side
	-- renders, AJAX fragments, REST responses and feeds keep the raw markup
	---------------------------------------------------------- */

	protected static function should_rewrite(): bool {

		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {

			return false;

		}

		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {

			return false;

		}

		if ( is_feed() || self::is_builder_request() ) {

			return false;

		}

		if ( class_exists( 'Octave_Addons_Perf_Context' ) && Octave_Addons_Perf_Context::bypass_requested() ) {

			return false;

		}

		/**
		 * Filters whether videos and their embeds are lazy loaded.
		 *
		 * @param bool $enabled Defaults to true.
		 */
		return (bool) apply_filters( 'octave_addons_lazy_videos', true );

	}

	/*
	IS OPTED OUT
	-- Media opted out of lazy loading by an attribute, by another performance
	-- plugin, or by the Media Lazy Loading module's exclusions
	---------------------------------------------------------- */

	protected static function is_opted_out( WP_HTML_Tag_Processor $tags ): bool {

		foreach ( [ 'data-no-lazy', 'data-skip-lazy', 'data-oa-no-lazy' ] as $attribute ) {

			if ( null !== $tags->get_attribute( $attribute ) ) {

				return true;

			}

		}

		return (bool) apply_filters( 'octave_addons_perf_skip_lazy', false, $tags );

	}

	/*
	ENQUEUE SCRIPT
	-- Loads the viewport loader only on pages that rendered a deferred video
	---------------------------------------------------------- */

	protected static function enqueue_script(): void {

		if ( self::$needs_script ) {

			return;

		}

		self::$needs_script = true;

		$asset = self::asset( 'modules/breakdance/lazy-load/assets/lazy-video.js' );

		wp_enqueue_script( self::SCRIPT_HANDLE, $asset['url'], [], $asset['version'], true );

	}

	/*
	PRINT LATE SCRIPT
	-- A video rendered after the footer scripts printed (inside a late footer
	-- template, for instance) still gets its loader, so autoplay is never lost
	---------------------------------------------------------- */

	public static function print_late_script(): void {

		if ( ! self::$needs_script ) {

			return;

		}

		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'done' ) ) {

			wp_print_scripts( self::SCRIPT_HANDLE );

		}

		// Without JavaScript the <noscript> copy plays, so the parked video steps aside.
		echo '<noscript><style>video[data-oa-lazy-video]{display:none!important}</style></noscript>';

	}

	/*
	DISABLE LAZY LOAD
	-- Walks a property tree and forces every lazy_load value to false
	-- A lazy_load holding an array is a control section rather than a toggle
	-- (the Video element names one that way), so those are recursed into and
	-- left intact
	---------------------------------------------------------- */

	protected static function disable_lazy_load( array $properties ): array {

		foreach ( $properties as $key => $value ) {

			if ( is_array( $value ) ) {

				$properties[ $key ] = self::disable_lazy_load( $value );

				continue;

			}

			if ( self::LAZY_KEY === $key ) {

				$properties[ $key ] = false;

			}

		}

		return $properties;

	}

}

return new Octave_Addons_Module_Breakdance_Lazy_Load();
