<?php

/*
PERFORMANCE: USED CSS
-- Trims a stylesheet to the rules one page can use. Deliberately relaxed:
-- a rule goes only when its selector names a class or id that appears
-- nowhere in the page's HTML, nowhere in its scripts and matches none of
-- the state words JavaScript commonly adds (open, active, sticky, ...).
-- Tags, attributes, pseudo-classes and combinators are never judged, and
-- :is(), :where(), :has(), nested rules, @font-face, @keyframes and other
-- at-rules are always kept
-- Anything the scanner cannot read returns null, and the caller keeps the
-- original stylesheet
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Used_Css {

	/** Class and id fragments kept wherever they appear, because scripts add them after the page loads. */
	public const SAFELIST = [
		'active', 'open', 'show', 'visible', 'hidden', 'hide', 'collaps', 'expand', 'toggle', 'select', 'check',
		'focus', 'hover', 'load', 'animat', 'sticky', 'fixed', 'scroll', 'mobile', 'popup', 'modal', 'overlay',
		'lightbox', 'dropdown', 'current', 'error', 'invalid', 'valid', 'disabled', 'menu', 'fade', 'slide',
		'in-view', 'inview', 'is-', 'has-', 'js', 'swiper', 'slick', 'splide', 'glide', 'flickity', 'owl-',
		'aos', 'mfp', 'fancybox', 'lity', 'tippy', 'tooltip', 'gform', 'gfield', 'ginput', 'gf_', 'cky-',
		'cookie', 'onetap', 'wpcf7', 'woocommerce', 'wc-', 'divi-area', 'da-', 'dap', 'et-db', 'et-fb',
		'admin-bar', 'screen-reader', 'sr-only', 'oa-',
	];

	/*
	PAGE TOKENS
	-- Every class and id in the page, and the text of its scripts, inline
	-- and local files, where class names added later are written
	---------------------------------------------------------- */

	public static function page_tokens( string $html ): array {

		$tokens = [ 'class' => [], 'id' => [], 'text' => '', 'sources' => [] ];
		$tags   = new WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag() ) {

			foreach ( preg_split( '/\s+/', trim( (string) $tags->get_attribute( 'class' ) ) ) as $class ) {

				if ( '' !== $class ) {

					$tokens['class'][ $class ] = true;

				}

			}

			$id = trim( (string) $tags->get_attribute( 'id' ) );

			if ( '' !== $id ) {

				$tokens['id'][ $id ] = true;

			}

			$src = 'SCRIPT' === $tags->get_tag() ? html_entity_decode( (string) $tags->get_attribute( 'src' ) ) : '';
			$src = '' !== $src ? $src : html_entity_decode( (string) $tags->get_attribute( 'data-oa-src' ) );

			if ( '' !== $src ) {

				$tokens['sources'][] = $src;

			}

			if ( '' !== $src && Octave_Addons_Perf::is_same_origin( $src ) ) {

				$path = Octave_Addons_Perf_Admin::local_path( $src );

				if ( '' !== $path && 'js' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) && filesize( $path ) <= MB_IN_BYTES ) {

					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
					$tokens['text'] .= (string) file_get_contents( $path ) . "\n";

				}

			}

		}

		if ( preg_match_all( '#<script\b[^>]*>(.*?)</script>#is', $html, $scripts ) ) {

			$tokens['text'] .= implode( "\n", $scripts[1] );

		}

		/**
		 * Filters the class and id fragments the used-CSS pass always keeps.
		 *
		 * @param string[] $safelist Case-insensitive fragments.
		 */
		$tokens['safelist'] = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'octave_addons_perf_used_css_safelist', self::SAFELIST ) ) ) );

		return $tokens;

	}

	/*
	FINGERPRINT
	-- Changes when the page's markup or scripts change, so a trimmed copy is
	-- reused across views. Inline script text and names carrying long
	-- numbers are left out: nonces and generated ids differ between views
	-- without changing which rules the page can use
	---------------------------------------------------------- */

	public static function fingerprint( array $tokens ): string {

		$stable = static function ( array $names ): array {

			$names = array_values( array_filter( array_map( 'strval', array_keys( $names ) ), static function ( string $name ): bool {

				return ! preg_match( '/\d{4,}|[a-f0-9]{8,}/i', $name );

			} ) );

			sort( $names );

			return $names;

		};

		$sources = array_map( static function ( string $src ): string {

			return (string) preg_replace( '/[?#].*$/', '', $src );

		}, $tokens['sources'] );

		sort( $sources );

		return md5( implode( ' ', $stable( $tokens['class'] ) ) . '#' . implode( ' ', $stable( $tokens['id'] ) ) . '|' . implode( ' ', array_unique( $sources ) ) . '|' . implode( ',', $tokens['safelist'] ) );

	}

	/*
	REDUCE
	-- The stylesheet without rules this page cannot use, or null when it
	-- cannot be read safely
	---------------------------------------------------------- */

	public static function reduce( string $css, array $tokens ): ?string {

		$out    = '';
		$length = strlen( $css );
		$i      = 0;

		while ( $i < $length ) {

			$i = self::skip_space( $css, $i );

			if ( $i >= $length ) {

				break;

			}

			if ( '}' === $css[ $i ] ) {

				return null;

			}

			$stop = self::find( $css, $i, '{;' );

			if ( null === $stop ) {

				return '@' === $css[ $i ] ? $out . substr( $css, $i ) : null;

			}

			$prelude = trim( substr( $css, $i, $stop - $i ) );

			// @charset, @import, @layer a, b; and stray semicolons.
			if ( ';' === $css[ $stop ] ) {

				$out .= '' !== $prelude ? $prelude . ';' : '';
				$i    = $stop + 1;

				continue;

			}

			$close = self::block_end( $css, $stop );

			if ( null === $close ) {

				return null;

			}

			$body = substr( $css, $stop + 1, $close - $stop - 1 );
			$i    = $close + 1;

			if ( '@' === $prelude[0] ) {

				if ( preg_match( '/^@(?:media|supports|container|layer|document|-moz-document)\b/i', $prelude ) ) {

					$inner = self::reduce( $body, $tokens );

					if ( null === $inner ) {

						return null;

					}

					$out .= '' !== trim( $inner ) ? $prelude . '{' . $inner . '}' : '';

					continue;

				}

				$out .= $prelude . '{' . $body . '}';

				continue;

			}

			// Nested CSS is kept whole rather than judged.
			if ( false !== strpos( $body, '{' ) || self::keep_rule( $prelude, $tokens ) ) {

				$out .= $prelude . '{' . $body . '}';

			}

		}

		return $out;

	}

	/*
	KEEP RULE
	-- Whether any selector in a list could match this page
	---------------------------------------------------------- */

	public static function keep_rule( string $selectors, array $tokens ): bool {

		foreach ( self::split( $selectors ) as $selector ) {

			if ( self::keep_selector( $selector, $tokens ) ) {

				return true;

			}

		}

		return false;

	}

	public static function keep_selector( string $selector, array $tokens ): bool {

		if ( preg_match( '/:(?:is|where|has|host|global|matches|-webkit-any|-moz-any)\(/i', $selector ) ) {

			return true;

		}

		$selector = (string) preg_replace( '/:not\((?:[^()]|\([^()]*\))*\)/i', '', $selector );
		$selector = (string) preg_replace( '/\[[^\]]*\]/', '', $selector );
		$selector = (string) preg_replace( '/::?[a-z-]+(?:\((?:[^()]|\([^()]*\))*\))?/i', '', $selector );

		preg_match_all( '/([.#])((?:\\\\.|[\w-]|[^\x00-\x7F])+)/', $selector, $names, PREG_SET_ORDER );

		foreach ( $names as $name ) {

			if ( ! self::known( (string) preg_replace( '/\\\\(.)/', '$1', $name[2] ), '.' === $name[1] ? 'class' : 'id', $tokens ) ) {

				return false;

			}

		}

		return true;

	}

	protected static function known( string $name, string $kind, array $tokens ): bool {

		if ( isset( $tokens[ $kind ][ $name ] ) || false !== strpos( $tokens['text'], $name ) ) {

			return true;

		}

		foreach ( $tokens['safelist'] as $fragment ) {

			if ( false !== stripos( $name, $fragment ) ) {

				return true;

			}

		}

		return false;

	}

	/*
	SCANNING
	-- Positions in CSS that skip strings, comments and anything inside
	-- brackets, so url(data:...;...) and "a{" never end a rule early
	---------------------------------------------------------- */

	protected static function skip_space( string $css, int $i ): int {

		$length = strlen( $css );

		while ( $i < $length ) {

			if ( ctype_space( $css[ $i ] ) ) {

				$i++;

			} elseif ( '/' === $css[ $i ] && '*' === ( $css[ $i + 1 ] ?? '' ) ) {

				$end = strpos( $css, '*/', $i + 2 );
				$i   = false === $end ? $length : $end + 2;

			} else {

				break;

			}

		}

		return $i;

	}

	protected static function find( string $css, int $i, string $stops ): ?int {

		$length = strlen( $css );
		$depth  = 0;

		for ( ; $i < $length; $i++ ) {

			$char = $css[ $i ];

			if ( '"' === $char || "'" === $char ) {

				$i = self::string_end( $css, $i );

				if ( null === $i ) {

					return null;

				}

			} elseif ( '/' === $char && '*' === ( $css[ $i + 1 ] ?? '' ) ) {

				$end = strpos( $css, '*/', $i + 2 );

				if ( false === $end ) {

					return null;

				}

				$i = $end + 1;

			} elseif ( '(' === $char || '[' === $char ) {

				$depth++;

			} elseif ( ')' === $char || ']' === $char ) {

				$depth = max( 0, $depth - 1 );

			} elseif ( 0 === $depth && false !== strpos( $stops, $char ) ) {

				return $i;

			} elseif ( 0 === $depth && '}' === $char ) {

				return null;

			}

		}

		return null;

	}

	protected static function block_end( string $css, int $open ): ?int {

		$length = strlen( $css );
		$depth  = 0;

		for ( $i = $open; $i < $length; $i++ ) {

			$char = $css[ $i ];

			if ( '"' === $char || "'" === $char ) {

				$i = self::string_end( $css, $i );

				if ( null === $i ) {

					return null;

				}

			} elseif ( '/' === $char && '*' === ( $css[ $i + 1 ] ?? '' ) ) {

				$end = strpos( $css, '*/', $i + 2 );

				if ( false === $end ) {

					return null;

				}

				$i = $end + 1;

			} elseif ( '{' === $char ) {

				$depth++;

			} elseif ( '}' === $char && 0 === --$depth ) {

				return $i;

			}

		}

		return null;

	}

	protected static function string_end( string $css, int $start ): ?int {

		$quote  = $css[ $start ];
		$length = strlen( $css );

		for ( $i = $start + 1; $i < $length; $i++ ) {

			if ( '\\' === $css[ $i ] ) {

				$i++;

			} elseif ( $quote === $css[ $i ] ) {

				return $i;

			}

		}

		return null;

	}

	/*
	SPLIT
	-- A selector list on its top-level commas
	---------------------------------------------------------- */

	protected static function split( string $selectors ): array {

		$parts   = [];
		$depth   = 0;
		$current = '';
		$length  = strlen( $selectors );

		for ( $i = 0; $i < $length; $i++ ) {

			$char = $selectors[ $i ];

			if ( '\\' === $char ) {

				$current .= $char . ( $selectors[ $i + 1 ] ?? '' );
				$i++;

				continue;

			}

			if ( '(' === $char || '[' === $char ) {

				$depth++;

			} elseif ( ')' === $char || ']' === $char ) {

				$depth = max( 0, $depth - 1 );

			} elseif ( ',' === $char && 0 === $depth ) {

				$parts[] = trim( $current );
				$current = '';

				continue;

			}

			$current .= $char;

		}

		$parts[] = trim( $current );

		return array_filter( $parts, 'strlen' );

	}

}
