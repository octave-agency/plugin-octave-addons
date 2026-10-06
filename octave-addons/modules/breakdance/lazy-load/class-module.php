<?php

/*
BREAKDANCE LAZY LOAD
-- Breakdance's own "Lazy Load" toggles are removed from the builder and
-- forced off in the defaults and the rendered output, so Breakdance's lazy
-- load script and markup never reach the page. Breakdance lazy loads by
-- toggle with no knowledge of what starts in the viewport; Octave owns lazy
-- loading instead
-- Images and iframes get native lazy loading from the Performance > Media
-- Lazy Loading module when it is on, or are left to a third-party plugin
-- Videos are the exception and are lazy loaded by default: Breakdance Video
-- elements use their lightweight YouTube/Vimeo players, HTML5 and section
-- background videos start with preload="none" and are loaded by a small
-- viewport observer, and provider iframes receive loading="lazy"
-- Rewrites run only on known video markup through WP_HTML_Tag_Processor,
-- never on the whole page
-- Always on and hidden from the admin. The Media Lazy Loading module can
-- switch video handling off (octave_addons_lazy_videos) and its exclusions
-- apply here too (octave_addons_perf_skip_lazy)
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

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

		return __( 'Removes and disables every Breakdance Lazy Load toggle, so images are lazy loaded natively by Performance > Media Lazy Loading or a third-party plugin, while videos load lazily as they approach the viewport.', 'octave-addons' );

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
	-- Removes every Lazy Load toggle from the builder panels, so editors are
	-- not offered a switch that would be overridden at render anyway
	---------------------------------------------------------- */

	public static function filter_controls( $controls ) {

		return is_array( $controls ) ? self::remove_lazy_toggles( $controls ) : $controls;

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
	-- Elements with no defaults return false rather than an array
	---------------------------------------------------------- */

	public static function filter_default_properties( $properties ) {

		if ( ! is_array( $properties ) ) {

			return $properties;

		}

		$properties = self::disable_lazy_load( $properties );

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
	-- editor has switched back on
	---------------------------------------------------------- */

	public static function filter_render_node( $node ) {

		if ( ! is_array( $node ) || empty( $node['data']['properties'] ) || ! is_array( $node['data']['properties'] ) ) {

			return $node;

		}

		$node['data']['properties'] = self::disable_lazy_load( $node['data']['properties'] );

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

		$tags = new WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag( [ 'class_name' => 'section-background-video' ] ) ) {

			if ( $tags->next_tag() && 'VIDEO' === $tags->get_tag() ) {

				self::defer_video( $tags );

			}

		}

		return $tags->get_updated_html();

	}

	/*
	FILTER VIDEO MARKUP
	-- Shared by the Breakdance Video element, the core video and embed blocks,
	-- the [video] shortcode and oEmbed output. Each filter hands over one small
	-- fragment of known video markup rather than the whole page.
	-- Without WP_HTML_Tag_Processor (WordPress before 6.2) markup is untouched
	---------------------------------------------------------- */

	public static function filter_video_markup( $html ) {

		if ( ! is_string( $html ) || '' === $html || ! self::should_rewrite() ) {

			return $html;

		}

		if ( false === stripos( $html, '<video' ) && false === stripos( $html, '<iframe' ) ) {

			return $html;

		}

		$tags = new WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag() ) {

			$tag = $tags->get_tag();

			if ( 'VIDEO' === $tag ) {

				self::defer_video( $tags );

				continue;

			}

			if ( 'IFRAME' === $tag && null === $tags->get_attribute( 'loading' ) && null !== $tags->get_attribute( 'src' ) && ! self::is_opted_out( $tags ) ) {

				$tags->set_attribute( 'loading', 'lazy' );

			}

		}

		return $tags->get_updated_html();

	}

	/*
	DEFER VIDEO
	-- Starts an HTML5 video with preload="none" and parks autoplay in a data
	-- attribute, so an offscreen autoplay video neither downloads nor plays
	-- until the viewport loader activates it. src, poster, controls, tracks,
	-- loop, muted and playsinline are untouched, so without JavaScript the
	-- video keeps its poster and dimensions and still plays from its controls.
	-- Videos opted out of lazy loading, or excluded in Media Lazy Loading, are skipped
	---------------------------------------------------------- */

	protected static function defer_video( WP_HTML_Tag_Processor $tags ): void {

		if ( null !== $tags->get_attribute( 'data-oa-lazy-video' ) ) {

			return;

		}

		if ( self::is_opted_out( $tags ) ) {

			return;

		}

		$preload = strtolower( (string) $tags->get_attribute( 'preload' ) );

		$tags->set_attribute( 'data-oa-lazy-video', '' );
		$tags->set_attribute( 'data-oa-preload', in_array( $preload, [ 'auto', 'metadata' ], true ) ? $preload : 'metadata' );
		$tags->set_attribute( 'preload', 'none' );

		if ( null !== $tags->get_attribute( 'autoplay' ) ) {

			$tags->remove_attribute( 'autoplay' );
			$tags->set_attribute( 'data-oa-autoplay', '' );

		}

		self::enqueue_script();

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

		$path = OCTAVE_ADDONS_DIR . 'modules/breakdance/lazy-load/assets/lazy-video.js';

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			OCTAVE_ADDONS_URL . 'modules/breakdance/lazy-load/assets/lazy-video.js',
			[],
			self::file_version( $path ),
			true
		);

	}

	/*
	PRINT LATE SCRIPT
	-- A video rendered after the footer scripts printed (inside a late footer
	-- template, for instance) still gets its loader, so autoplay is never lost
	---------------------------------------------------------- */

	public static function print_late_script(): void {

		if ( self::$needs_script && ! wp_script_is( self::SCRIPT_HANDLE, 'done' ) ) {

			wp_print_scripts( self::SCRIPT_HANDLE );

		}

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
