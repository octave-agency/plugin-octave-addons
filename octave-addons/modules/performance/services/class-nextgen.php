<?php

/*
PERFORMANCE: NEXT-GENERATION IMAGE URLS
-- Delivers Imagify's WebP or AVIF copies without server rules or picture
-- tags, for servers such as Cloudways where Nginx answers image requests
-- itself and never reads .htaccess, and for layouts a <picture> wrapper
-- would break. Chosen with Delivery: "Octave URL rewriting" in the Imagify
-- module, which also stops Imagify delivering images itself
-- Imagify still creates the files beside each original, as photo.jpg.avif.
-- Wherever such a copy exists, the page points at it directly:
--   <img> src and srcset, video posters (parked ones included), inline
--   style url()s and image preloads, which also gain a type attribute so a
--   browser without the format skips the preload
-- The page builder's stylesheets (Breakdance's, and Divi's theme and et-cache
-- CSS) are served as copies in which every background
-- image declaration is followed, inside the same rule, by an image-set()
-- offering the copy with the original as fallback. The cascade is
-- untouched, and a browser that cannot show the format keeps the original
-- An <img> has no fallback without a <picture> wrapper, so browsers that
-- cannot show the format at all (Safari before 16.4 for AVIF) would miss
-- those images. Every current browser supports both formats
-- Markup is the same for every visitor, so page caches and Varnish keep
-- working; nothing depends on what a browser says it accepts
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Nextgen {

	/** Cache sub-folder for rewritten page-builder stylesheets. Each copy keeps its original path tail and name, so inlining and bundling treat it exactly like the original, and a full purge clears it with the minified files. */
	public const CSS_DIR = Octave_Addons_Perf_Cache::MIN_DIR . '/nextgen';

	/** Image extensions Imagify makes copies of. */
	protected const SOURCES = [ 'jpg', 'jpeg', 'png', 'gif' ];

	/** Original MIME type per extension, for image-set() fallbacks. */
	protected const TYPES = [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif' ];

	/** Largest stylesheet copied. */
	protected const MAX_CSS_BYTES = 512 * KB_IN_BYTES;

	/** @var array<string, string> Original URL => copy URL ('' for none), per request. */
	protected static array $copies = [];

	/*
	BOOT
	-- After lazy loading and hero preloads (20), before CSS bundling (45)
	---------------------------------------------------------- */

	public static function boot(): void {

		if ( self::active() ) {

			Octave_Addons_Perf_Html::register( 'nextgen', [ __CLASS__, 'transform' ], 25 );

		}

	}

	public static function reset(): void {

		self::$copies = [];

	}

	/*
	ACTIVE AND FORMAT
	-- Octave delivery is chosen and Imagify is making WebP or AVIF copies
	---------------------------------------------------------- */

	public static function active(): bool {

		return Octave_Addons_Perf_Imagify::octave_delivery() && '' !== self::format();

	}

	public static function format(): string {

		$format = (string) ( Octave_Addons_Perf_Imagify::config()['optimization_format'] ?? '' );

		return in_array( $format, [ 'webp', 'avif' ], true ) ? $format : '';

	}

	/*
	COPY URL
	-- The next-generation copy of a local image URL, or '' when there is
	-- none on disk. A query string is kept after the new extension
	---------------------------------------------------------- */

	public static function copy_url( string $url, string $format = '' ): string {

		$format = '' !== $format ? $format : self::format();
		$url    = trim( html_entity_decode( $url ) );
		$key    = $format . '|' . $url;

		if ( isset( self::$copies[ $key ] ) ) {

			return self::$copies[ $key ];

		}

		self::$copies[ $key ] = '';

		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( '' === $format || '' === $path || ! in_array( $extension, self::SOURCES, true ) || ! Octave_Addons_Perf::is_same_origin( $url ) ) {

			return '';

		}

		$file = Octave_Addons_Perf_Admin::local_path( $url );

		if ( '' === $file || ! file_exists( $file . '.' . $format ) ) {

			return '';

		}

		$query = strpos( $url, '?' );

		return self::$copies[ $key ] = false === $query ? $url . '.' . $format : substr( $url, 0, $query ) . '.' . $format . substr( $url, $query );

	}

	/*
	TRANSFORM
	-- One pass over the page. Images inside a <picture> are left to it
	---------------------------------------------------------- */

	public static function transform( string $html ): string {

		if ( ! Octave_Addons_Perf::has_html_api() || ! self::active() ) {

			return $html;

		}

		$tags    = new WP_HTML_Tag_Processor( $html );
		$picture = 0;
		$changed = 0;

		while ( $tags->next_tag( [ 'tag_closers' => 'visit' ] ) ) {

			$tag = $tags->get_tag();

			if ( 'PICTURE' === $tag ) {

				$picture += $tags->is_tag_closer() ? -1 : 1;

				continue;

			}

			if ( $tags->is_tag_closer() ) {

				continue;

			}

			if ( 'IMG' === $tag && $picture <= 0 ) {

				$changed += self::swap( $tags, 'src' ) + self::swap_srcset( $tags, 'srcset' );

			} elseif ( 'VIDEO' === $tag ) {

				$changed += self::swap( $tags, 'poster' ) + self::swap( $tags, 'data-oa-poster' ) + self::swap_srcset( $tags, 'data-oa-poster-srcset' );

			} elseif ( 'LINK' === $tag ) {

				$changed += self::link( $tags );

			}

			$style = $tags->get_attribute( 'style' );

			if ( is_string( $style ) && false !== stripos( $style, 'url(' ) ) {

				$updated = self::style_urls( $style );

				if ( $updated !== $style ) {

					$tags->set_attribute( 'style', $updated );
					$changed++;

				}

			}

		}

		Octave_Addons_Perf_Log::summary( 'nextgen_urls', $changed );

		return $tags->get_updated_html();

	}

	protected static function swap( WP_HTML_Tag_Processor $tags, string $attribute ): int {

		$value = $tags->get_attribute( $attribute );
		$copy  = is_string( $value ) ? self::copy_url( $value ) : '';

		if ( '' === $copy ) {

			return 0;

		}

		$tags->set_attribute( $attribute, $copy );

		return 1;

	}

	protected static function swap_srcset( WP_HTML_Tag_Processor $tags, string $attribute ): int {

		$value = $tags->get_attribute( $attribute );

		if ( ! is_string( $value ) || '' === trim( $value ) ) {

			return 0;

		}

		$updated = self::srcset( $value );

		if ( $updated === $value ) {

			return 0;

		}

		$tags->set_attribute( $attribute, $updated );

		return 1;

	}

	/*
	SRCSET
	-- Each candidate URL swapped for its copy, descriptors kept
	---------------------------------------------------------- */

	public static function srcset( string $srcset ): string {

		$candidates = [];

		foreach ( explode( ',', $srcset ) as $candidate ) {

			$parts = preg_split( '/\s+/', trim( $candidate ), 2 );

			if ( '' === ( $parts[0] ?? '' ) ) {

				continue;

			}

			$copy         = self::copy_url( $parts[0] );
			$candidates[] = trim( ( '' !== $copy ? $copy : $parts[0] ) . ' ' . ( $parts[1] ?? '' ) );

		}

		return implode( ', ', $candidates );

	}

	/*
	LINK
	-- Image preloads point at the copy and name its type; page-builder
	-- stylesheets are swapped for their rewritten copies
	---------------------------------------------------------- */

	protected static function link( WP_HTML_Tag_Processor $tags ): int {

		$rel = strtolower( (string) $tags->get_attribute( 'rel' ) );

		if ( 'preload' === trim( $rel ) && 'image' === strtolower( (string) $tags->get_attribute( 'as' ) ) ) {

			$changed = self::swap( $tags, 'href' ) + self::swap_srcset( $tags, 'imagesrcset' );

			if ( $changed && null === $tags->get_attribute( 'type' ) ) {

				$tags->set_attribute( 'type', 'image/' . self::format() );

			}

			return $changed;

		}

		if ( ! preg_match( '/(^|\s)stylesheet(\s|$)/', $rel ) || null !== $tags->get_attribute( 'integrity' ) ) {

			return 0;

		}

		$href = html_entity_decode( (string) $tags->get_attribute( 'href' ) );

		$path     = (string) wp_parse_url( $href, PHP_URL_PATH );
		$builders = array_merge( [], ...array_values( Octave_Addons_Builders::css_paths() ) );

		if ( '' === Octave_Addons_Perf::matches_any( $path, $builders ) || 0 === strpos( $path, (string) wp_parse_url( Octave_Addons_Perf_Store::url( self::CSS_DIR ), PHP_URL_PATH ) ) ) {

			return 0;

		}

		$copy = self::css_copy( $href );

		if ( '' === $copy ) {

			return 0;

		}

		$tags->set_attribute( 'href', $copy );

		return 1;

	}

	/*
	STYLE URLS
	-- url()s in an inline style attribute swapped for their copies
	---------------------------------------------------------- */

	public static function style_urls( string $style ): string {

		return (string) preg_replace_callback( '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', static function ( array $match ): string {

			$copy = self::copy_url( html_entity_decode( $match[2] ) );

			return '' !== $copy ? 'url(' . $match[1] . $copy . $match[1] . ')' : $match[0];

		}, $style );

	}

	/*
	CSS COPY
	-- URL of a page-builder stylesheet's rewritten copy, written on first use
	-- and kept until the source, the format or the cache generation changes.
	-- '' when nothing in it has a copy, or it cannot be read or written
	---------------------------------------------------------- */

	public static function css_copy( string $href ): string {

		$file = Octave_Addons_Perf_Admin::local_path( $href );

		if ( '' === $file || 'css' !== strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) || filesize( $file ) > self::MAX_CSS_BYTES ) {

			return '';

		}

		$key  = substr( md5( implode( '|', [ $file, filemtime( $file ), filesize( $file ), self::format(), Octave_Addons_Perf_Cache::generation(), OCTAVE_ADDONS_VERSION ] ) ), 0, 12 );
		$name = sanitize_file_name( basename( $file ) );
		$sub  = self::CSS_DIR . '/' . $key . '/' . self::folder( $href );
		$dir  = Octave_Addons_Perf_Store::dir( $sub );
		$url  = Octave_Addons_Perf_Store::url( $sub ) . $name;

		if ( file_exists( $dir . $name ) ) {

			return $url;

		}

		if ( get_transient( 'oa_perf_nextgen_none_' . $key ) ) {

			return '';

		}

		$base = 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ? home_url( $href ) : $href;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$css  = self::css( (string) file_get_contents( $file ), $base );

		if ( '' === $css ) {

			set_transient( 'oa_perf_nextgen_none_' . $key, 1, DAY_IN_SECONDS );

			return '';

		}

		return Octave_Addons_Perf_Store::write( $dir . $name, $css ) ? $url : '';

	}

	/*
	FOLDER
	-- The stylesheet's folder below wp-content, e.g. uploads/breakdance/css
	-- or et-cache/42, kept in the copy's path so path-based rules (bundling,
	-- Breakdance's per-page inlining) treat the copy like the original
	---------------------------------------------------------- */

	protected static function folder( string $href ): string {

		$path    = (string) dirname( (string) wp_parse_url( $href, PHP_URL_PATH ) );
		$content = untrailingslashit( (string) wp_parse_url( content_url(), PHP_URL_PATH ) );

		if ( '' !== $content && 0 === strpos( $path, $content . '/' ) ) {

			$path = substr( $path, strlen( $content ) );

		}

		$folder = trim( (string) preg_replace( '#[^A-Za-z0-9/_.-]+|\.\.+#', '', $path ), '/' );

		return '' !== $folder ? $folder : 'css';

	}

	/*
	CSS
	-- The stylesheet with an image-set() declaration added after every
	-- background image that has a copy, or '' when none has. Every URL is
	-- made absolute first, since the copy lives in another folder.
	-- A shorthand whose image cannot be told apart from gradients or
	-- several layers is left as it is
	---------------------------------------------------------- */

	public static function css( string $css, string $base ): string {

		$changed = false;

		// Resolved and normalised, so "../" segments never reach a file lookup.
		$css = (string) preg_replace_callback( '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', static function ( array $match ) use ( $base ): string {

			$url = trim( $match[2] );

			if ( preg_match( '#^(?:data:|\#|%23)#i', $url ) ) {

				return $match[0];

			}

			return 'url(' . $match[1] . Octave_Addons_Perf_Css::resolve( $url, $base ) . $match[1] . ')';

		}, $css );

		$updated = (string) preg_replace_callback( '/(?<=[{;\s])(background(?:-image)?)\s*:\s*((?:[^;{}"\'(]|"[^"]*"|\'[^\']*\'|\([^)]*\))*url\((?:[^;{}"\'(]|"[^"]*"|\'[^\']*\'|\([^)]*\))*)(?=[;}])/i', static function ( array $match ) use ( &$changed ): string {

			$value = self::image_set_value( strtolower( $match[1] ), $match[2] );

			if ( '' === $value ) {

				return $match[0];

			}

			$changed = true;

			return $match[0] . ';background-image:' . $value;

		}, $css );

		return $changed ? $updated : '';

	}

	/*
	IMAGE SET VALUE
	-- The background-image value offering each copy with its original as
	-- fallback, '' when nothing changes. !important is carried over
	---------------------------------------------------------- */

	public static function image_set_value( string $property, string $value ): string {

		$important = (bool) preg_match( '/!\s*important\s*$/i', $value );
		$value     = trim( (string) preg_replace( '/!\s*important\s*$/i', '', $value ) );
		$urls      = Octave_Addons_Perf_Css::urls( $value );

		// A shorthand only converts when its one url() is its only image.
		if ( 'background' === $property && ( 1 !== count( $urls ) || preg_match( '/gradient\(|image-set\(|cross-fade\(|element\(/i', $value ) ) ) {

			return '';

		}

		$changed = false;
		$images  = 'background' === $property ? 'url(' . $urls[0] . ')' : $value;

		$images = (string) preg_replace_callback( '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', static function ( array $match ) use ( &$changed ): string {

			$original = trim( $match[2] );
			$copy     = self::copy_url( $original );

			if ( '' === $copy ) {

				return $match[0];

			}

			$changed   = true;
			$extension = strtolower( pathinfo( (string) wp_parse_url( $original, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

			return 'image-set(url("' . $copy . '") type("image/' . self::format() . '"), url("' . $original . '") type("' . self::TYPES[ $extension ] . '"))';

		}, $images );

		return $changed ? $images . ( $important ? ' !important' : '' ) : '';

	}

}
