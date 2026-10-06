<?php

/*
PERFORMANCE: BREAKDANCE HERO
-- Finds a Breakdance page's hero background from the page itself, so the
-- very first visitor already gets the right image preloaded rather than
-- waiting for browsers to report it
-- A CSS background is only requested once the stylesheet that names it has
-- arrived, and fetchpriority on a <section> or <div> does nothing for it.
-- So the first Breakdance section after the site header is located in the
-- page, and the Breakdance stylesheets the page links are read for rules
-- that give that section, or failing that one of its first inner layout
-- elements, a background image, each under its media condition
-- Those conditions are resolved into screen-width ranges, so each range
-- preloads exactly the image Breakdance's CSS shows it: with Breakdance's
-- default breakpoints, phones and tablets get the image set below 1024px
-- and larger screens the desktop one. Each range preloads one image at
-- most, so no two high-priority preloads compete on any screen
-- Anything unexpected — a media condition other than min-width or
-- max-width in px, several layered images, image-set(), an unreadable
-- stylesheet — means no preload, and the learned LCP record is used
-- The answer is cached against the stylesheets' paths, sizes and
-- modification times, so a Breakdance save is seen straight away
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Hero {

	/** Breakdance's per-element class: bde-{element}-{post id}-{node id}. */
	protected const ELEMENT_CLASS = '/^bde-([a-z0-9-]+?)-\d+-\d+$/';

	/** Elements that can be or hold the hero background. */
	protected const LAYOUT_ELEMENTS = [ 'section', 'div', 'columns', 'column', 'container' ];

	/** Inner elements checked when the section itself has no background. */
	protected const MAX_INNER = 6;

	/** Largest stylesheet read. */
	protected const MAX_BYTES = 512 * KB_IN_BYTES;

	/** Most preconnects added for the hero's own images and fonts. */
	public const MAX_ORIGINS = 2;

	/*
	DISCOVER
	-- [ 'element' => class, 'preloads' => [ [ 'url', 'media' ], … ],
	-- 'origins' => [ origin => cors ] ] for a Breakdance page, or []
	---------------------------------------------------------- */

	public static function discover( string $html ): array {

		if ( ! Octave_Addons_Perf::has_html_api() || false === strpos( $html, 'bde-' ) ) {

			return [];

		}

		$elements = self::hero_elements( $html );

		if ( empty( $elements ) ) {

			return [];

		}

		$sources = self::sources( $html );

		if ( empty( $sources ) ) {

			return [];

		}

		$key    = 'oa_perf_hero_' . md5( wp_json_encode( [ $elements, array_column( $sources, 'key' ), Octave_Addons_Perf_Cache::generation() ] ) );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {

			return $cached;

		}

		$result = [];

		foreach ( $elements as $class ) {

			$preloads = self::preloads( $class, $sources );

			if ( null === $preloads ) {

				break;

			}

			if ( ! empty( $preloads ) ) {

				$result = [ 'element' => $class, 'preloads' => $preloads ];

				break;

			}

		}

		set_transient( $key, $result, DAY_IN_SECONDS );

		return $result;

	}

	/*
	HERO ELEMENTS
	-- The first Breakdance section outside the site header, then its first
	-- few inner layout elements, as their bde-…-ID-ID classes
	---------------------------------------------------------- */

	public static function hero_elements( string $html ): array {

		$tags     = new WP_HTML_Tag_Processor( $html );
		$header   = 0;
		$skip     = 0;
		$found    = [];
		$depth    = 0;
		$in_hero  = false;

		while ( $tags->next_tag( [ 'tag_closers' => 'visit' ] ) ) {

			$tag    = $tags->get_tag();
			$closer = $tags->is_tag_closer();

			if ( 'HEADER' === $tag || 'NAV' === $tag || 'FOOTER' === $tag || 'ASIDE' === $tag || 'TEMPLATE' === $tag ) {

				$header += $closer ? -1 : 1;

				continue;

			}

			if ( $header > 0 || ! in_array( $tag, [ 'SECTION', 'DIV' ], true ) ) {

				continue;

			}

			if ( $in_hero ) {

				$depth += $closer ? -1 : 1;

				if ( $depth <= 0 || count( $found ) > self::MAX_INNER ) {

					break;

				}

			}

			if ( $closer ) {

				continue;

			}

			$class = self::element_class( $tags );

			// Popups and the header builder are never the page's hero.
			if ( '' === $class || 0 === strpos( $class, 'bde-popup' ) || 0 === strpos( $class, 'bde-header' ) ) {

				continue;

			}

			if ( ! $in_hero ) {

				if ( 0 !== strpos( $class, 'bde-section-' ) ) {

					continue;

				}

				$in_hero = true;
				$depth   = 1;

			}

			$found[] = $class;

		}

		return $found;

	}

	protected static function element_class( WP_HTML_Tag_Processor $tags ): string {

		foreach ( preg_split( '/\s+/', trim( (string) $tags->get_attribute( 'class' ) ) ) as $class ) {

			if ( preg_match( self::ELEMENT_CLASS, $class, $match ) && in_array( $match[1], self::LAYOUT_ELEMENTS, true ) ) {

				return $class;

			}

		}

		return '';

	}

	/*
	SOURCES
	-- The Breakdance stylesheets the page links in its <head>, in order,
	-- read from disk: [ [ 'url', 'css', 'key' ], … ]
	---------------------------------------------------------- */

	public static function sources( string $html ): array {

		$end  = stripos( $html, '</head>' );
		$head = false === $end ? '' : substr( $html, 0, $end );

		preg_match_all( '#<link\b[^>]*>#i', $head, $links );

		$sources = [];

		foreach ( $links[0] as $link ) {

			$tags = new WP_HTML_Tag_Processor( $link );

			if ( ! $tags->next_tag( 'LINK' ) || false === stripos( (string) $tags->get_attribute( 'rel' ), 'stylesheet' ) ) {

				continue;

			}

			$href = html_entity_decode( trim( (string) $tags->get_attribute( 'href' ) ) );
			$path = (string) wp_parse_url( $href, PHP_URL_PATH );

			if ( false === strpos( $path, '/breakdance/' ) || ! Octave_Addons_Perf::is_same_origin( $href ) ) {

				continue;

			}

			$file = Octave_Addons_Perf_Admin::local_path( $href );

			if ( '' === $file || 'css' !== strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) || filesize( $file ) > self::MAX_BYTES ) {

				continue;

			}

			$url = 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ? home_url( $href ) : $href;

			$sources[] = [
				'url'   => $url,
				'file'  => $file,
				'media' => trim( (string) $tags->get_attribute( 'media' ) ),
				'key'   => $file . '|' . filemtime( $file ) . '|' . filesize( $file ),
			];

		}

		return $sources;

	}

	/*
	PRELOADS
	-- The preloads for one element: [] when no stylesheet gives it a
	-- background image, null when its rules cannot be resolved safely
	---------------------------------------------------------- */

	public static function preloads( string $class, array $sources ): ?array {

		$rules = [];
		$order = 0;

		foreach ( $sources as $source ) {

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			$css    = isset( $source['css'] ) ? (string) $source['css'] : (string) file_get_contents( $source['file'] );
			$parsed = Octave_Addons_Perf_Css::rules( $css );

			if ( null === $parsed ) {

				return null;

			}

			foreach ( $parsed as $rule ) {

				if ( ! Octave_Addons_Perf_Css::targets( $rule['selector'], $class ) ) {

					continue;

				}

				$value = Octave_Addons_Perf_Css::declaration( $rule['body'], 'background-image' );
				$value = '' !== $value ? $value : Octave_Addons_Perf_Css::declaration( $rule['body'], 'background' );

				if ( '' === $value ) {

					continue;

				}

				$media = trim( implode( ' and ', array_filter( [ 'all' !== strtolower( (string) ( $source['media'] ?? '' ) ) ? (string) ( $source['media'] ?? '' ) : '', $rule['media'] ] ) ) );

				$rules[] = [ 'media' => $media, 'url' => self::image( $value, $source['url'] ), 'order' => $order++ ];

			}

		}

		if ( empty( $rules ) ) {

			return [];

		}

		return self::ranges( $rules );

	}

	/*
	IMAGE
	-- The one image a background value shows, '' for none, or null when it
	-- cannot be told (several layers, image-set(), a variable)
	---------------------------------------------------------- */

	protected static function image( string $value, string $base ): ?string {

		$value = trim( (string) preg_replace( '/!important\s*$/i', '', $value ) );

		if ( preg_match( '/image-set\(|var\(|^(?:inherit|initial|unset|revert)$/i', $value ) ) {

			return null;

		}

		$urls = Octave_Addons_Perf_Css::urls( $value );

		if ( count( $urls ) > 1 ) {

			return null;

		}

		if ( empty( $urls ) || 0 === stripos( $urls[0], 'data:' ) ) {

			return '';

		}

		return Octave_Addons_Perf_Css::resolve( $urls[0], $base );

	}

	/*
	RANGES
	-- Turns ordered (media, image) rules into one preload per image, scoped
	-- to the widths where that image is the one shown. The widths are split
	-- at every breakpoint and each slice takes the last rule matching it,
	-- as the cascade would
	---------------------------------------------------------- */

	public static function ranges( array $rules ): ?array {

		$bounds = [ 0 ];

		foreach ( $rules as $index => $rule ) {

			$conditions = self::conditions( $rule['media'] );

			if ( null === $conditions ) {

				return null;

			}

			$rules[ $index ]['conditions'] = $conditions;

			foreach ( $conditions as [ $type, $px ] ) {

				$bounds[] = 'max' === $type ? $px + 1 : $px;

			}

		}

		$bounds = array_values( array_unique( $bounds ) );

		sort( $bounds );

		$slices = [];

		foreach ( $bounds as $i => $low ) {

			$high  = isset( $bounds[ $i + 1 ] ) ? $bounds[ $i + 1 ] - 1 : null;
			$image = '';

			foreach ( $rules as $rule ) {

				if ( self::applies( $rule['conditions'], $low ) ) {

					$image = $rule['url'];

				}

			}

			// An undecidable image in any slice withholds every preload.
			if ( null === $image ) {

				return null;

			}

			$last = count( $slices ) - 1;

			if ( $last >= 0 && $slices[ $last ]['url'] === $image ) {

				$slices[ $last ]['high'] = $high;

				continue;

			}

			$slices[] = [ 'url' => $image, 'low' => $low, 'high' => $high ];

		}

		$preloads = [];

		foreach ( $slices as $slice ) {

			if ( '' === $slice['url'] ) {

				continue;

			}

			$media = array_filter( [
				$slice['low'] > 0 ? '(min-width: ' . $slice['low'] . 'px)' : '',
				null !== $slice['high'] ? '(max-width: ' . $slice['high'] . 'px)' : '',
			] );

			$preloads[] = [ 'url' => $slice['url'], 'media' => implode( ' and ', $media ) ];

		}

		// One image across several separate ranges is one preload with a media list.
		$merged = [];

		foreach ( $preloads as $preload ) {

			$merged[ $preload['url'] ][] = $preload['media'];

		}

		$result = [];

		foreach ( $merged as $url => $media ) {

			$result[] = [ 'url' => (string) $url, 'media' => in_array( '', $media, true ) ? '' : implode( ', ', $media ) ];

		}

		return $result;

	}

	/*
	CONDITIONS
	-- A media condition as [ [ 'min'|'max', px ], … ], [] for every width,
	-- or null when it holds anything else
	---------------------------------------------------------- */

	protected static function conditions( string $media ): ?array {

		$media = strtolower( trim( $media ) );
		$media = (string) preg_replace( '/^(?:only\s+)?(?:screen|all)\s+and\s+/', '', $media );

		if ( '' === $media || 'all' === $media || 'screen' === $media ) {

			return [];

		}

		$conditions = [];

		foreach ( preg_split( '/\s+and\s+/', $media ) as $part ) {

			if ( ! preg_match( '/^\(\s*(min|max)-width\s*:\s*(\d+(?:\.\d+)?)px\s*\)$/', trim( $part ), $match ) ) {

				return null;

			}

			$conditions[] = [ $match[1], (int) floor( (float) $match[2] ) ];

		}

		return $conditions;

	}

	protected static function applies( array $conditions, int $width ): bool {

		foreach ( $conditions as [ $type, $px ] ) {

			if ( ( 'min' === $type && $width < $px ) || ( 'max' === $type && $width > $px ) ) {

				return false;

			}

		}

		return true;

	}

	/*
	MARKUP
	-- The <head> links for a discovery result: preconnects for the hero's
	-- other hosts first, then one high-priority preload per width range.
	-- Nothing when the page already preloads a high-priority image
	---------------------------------------------------------- */

	public static function markup( array $hero, string $html ): string {

		if ( empty( $hero['preloads'] ) || self::has_competing_preload( $html ) ) {

			return '';

		}

		$markup = '';

		foreach ( self::origins( $hero, $html ) as $origin => $cors ) {

			$markup .= '<link rel="preconnect" href="' . esc_url( $origin ) . '"' . ( $cors ? ' crossorigin' : '' ) . '>' . "\n";

		}

		foreach ( $hero['preloads'] as $preload ) {

			$markup .= '<link rel="preload" as="image" href="' . esc_url( $preload['url'] ) . '" fetchpriority="high"' . ( '' !== $preload['media'] ? ' media="' . esc_attr( $preload['media'] ) . '"' : '' ) . ' data-oa-hero>' . "\n";

		}

		return $markup;

	}

	public static function has_competing_preload( string $html ): bool {

		return (bool) preg_match( '#<link\b(?=[^>]*rel=["\']?preload)(?=[^>]*as=["\']?image)(?=[^>]*fetchpriority=["\']?high)[^>]*>#i', $html );

	}

	/*
	ORIGINS
	-- At most two: another host serving the hero image, then Google's font
	-- host when the <head> links a Google Fonts stylesheet, since every
	-- heading waits on it. Hosts the page already preconnects to are
	-- skipped, and none are added once the page has two preconnects
	---------------------------------------------------------- */

	public static function origins( array $hero, string $html ): array {

		$end      = stripos( $html, '</head>' );
		$head     = false === $end ? $html : substr( $html, 0, $end );
		$existing = preg_match_all( '#<link\b[^>]*rel=["\']?preconnect#i', $head );
		$room     = self::MAX_ORIGINS - (int) $existing;
		$origins  = [];

		foreach ( (array) ( $hero['preloads'] ?? [] ) as $preload ) {

			$url = (string) $preload['url'];

			if ( ! Octave_Addons_Perf::is_same_origin( $url ) && preg_match( '#^(https://[^/]+)#i', $url, $match ) ) {

				$origins[ strtolower( $match[1] ) ] = false;

			}

		}

		if ( preg_match( '#<link\b[^>]*rel=["\']?stylesheet[^>]*fonts\.googleapis\.com#i', $head ) ) {

			$origins['https://fonts.gstatic.com'] = true;

		}

		foreach ( array_keys( $origins ) as $origin ) {

			if ( preg_match( '#<link\b[^>]*rel=["\']?preconnect[^>]*' . preg_quote( (string) wp_parse_url( $origin, PHP_URL_HOST ), '#' ) . '#i', $head ) ) {

				unset( $origins[ $origin ] );

			}

		}

		return array_slice( $origins, 0, max( 0, $room ), true );

	}

}
