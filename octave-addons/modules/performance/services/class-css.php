<?php

/*
PERFORMANCE: CSS READER
-- Reads the style rules out of a stylesheet, each with the media
-- condition it sits under, for the Breakdance-aware features that need to
-- know which rule styles which element. It only reads; stylesheets are
-- never rewritten from what it returns
-- Rules inside @supports, @container, @layer, @keyframes, @font-face and
-- other at-rules are left out, as their conditions cannot be resolved
-- here. Unbalanced braces or an unclosed string make the whole sheet
-- unreadable, and callers then do nothing
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Css {

	/*
	RULES
	-- [ [ 'media' => string, 'selector' => string, 'body' => string ], … ]
	-- in source order, or null when the sheet cannot be read safely
	---------------------------------------------------------- */

	public static function rules( string $css ): ?array {

		$css    = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$length = strlen( $css );
		$rules  = [];
		$stack  = [];
		$buffer = '';
		$quote  = '';

		for ( $i = 0; $i < $length; $i++ ) {

			$char = $css[ $i ];

			if ( '' !== $quote ) {

				$buffer .= $char;

				if ( '\\' === $char && $i + 1 < $length ) {

					$buffer .= $css[ ++$i ];

				} elseif ( $char === $quote ) {

					$quote = '';

				}

				continue;

			}

			if ( '"' === $char || "'" === $char ) {

				$quote   = $char;
				$buffer .= $char;

				continue;

			}

			if ( '{' === $char ) {

				$stack[] = trim( $buffer );
				$buffer  = '';

				continue;

			}

			if ( '}' === $char ) {

				if ( empty( $stack ) ) {

					return null;

				}

				$prelude = (string) array_pop( $stack );
				$media   = self::media( $stack );

				if ( '' !== $prelude && '@' !== $prelude[0] && null !== $media ) {

					$rules[] = [ 'media' => $media, 'selector' => $prelude, 'body' => trim( $buffer ) ];

				}

				$buffer = '';

				continue;

			}

			// A statement such as @import or @charset ends at its semicolon.
			if ( ';' === $char && ( empty( $stack ) || '@' === ( end( $stack )[0] ?? '' ) ) ) {

				$buffer = '';

				continue;

			}

			$buffer .= $char;

		}

		return empty( $stack ) && '' === $quote ? $rules : null;

	}

	/*
	MEDIA
	-- The combined @media condition of the open blocks, '' for none, or
	-- null when any open block is another kind of at-rule
	---------------------------------------------------------- */

	protected static function media( array $stack ): ?string {

		$parts = [];

		foreach ( $stack as $prelude ) {

			if ( 0 !== stripos( (string) $prelude, '@media' ) ) {

				return null;

			}

			$parts[] = trim( substr( (string) $prelude, 6 ) );

		}

		return implode( ' and ', $parts );

	}

	/*
	TARGETS
	-- Whether a selector list styles an element carrying $class itself,
	-- rather than a descendant or a pseudo-element or state of it
	---------------------------------------------------------- */

	public static function targets( string $selectors, string $class ): bool {

		foreach ( explode( ',', $selectors ) as $selector ) {

			$parts = preg_split( '/[\s>+~]+/', trim( $selector ) );
			$last  = (string) end( $parts );

			if ( false !== strpos( $last, ':' ) || false !== strpos( $last, '[' ) ) {

				continue;

			}

			if ( preg_match( '/\.' . preg_quote( $class, '/' ) . '(?![\w-])/', $last ) ) {

				return true;

			}

		}

		return false;

	}

	/*
	DECLARATION
	-- The last value given to $property in a rule body, '' when absent.
	-- Strings and url()s are kept intact while splitting on semicolons
	---------------------------------------------------------- */

	public static function declaration( string $body, string $property ): string {

		$found = '';

		preg_match_all( '/(?:^|;)\s*([a-z-]+)\s*:\s*((?:[^;"\'(]|"[^"]*"|\'[^\']*\'|\([^)]*\))*)/i', $body, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {

			if ( 0 === strcasecmp( $match[1], $property ) ) {

				$found = trim( $match[2] );

			}

		}

		return $found;

	}

	/*
	URLS
	-- Every url() in a value, unquoted
	---------------------------------------------------------- */

	public static function urls( string $value ): array {

		preg_match_all( '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', $value, $matches );

		return array_map( 'trim', $matches[2] );

	}

	/*
	RESOLVE
	-- A URL from a stylesheet made absolute against the sheet's own URL
	---------------------------------------------------------- */

	public static function resolve( string $url, string $base ): string {

		$url = trim( $url );

		if ( '' === $url || preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//)#i', $url ) ) {

			return 0 === strpos( $url, '//' ) ? 'https:' . $url : $url;

		}

		$origin = (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', $base );

		if ( '/' === $url[0] ) {

			return $origin . $url;

		}

		$dir   = substr( (string) preg_replace( '/[?#].*$/', '', $base ), 0, (int) strrpos( (string) preg_replace( '/[?#].*$/', '', $base ), '/' ) + 1 );
		$path  = (string) wp_parse_url( $dir . $url, PHP_URL_PATH );
		$parts = [];

		foreach ( explode( '/', $path ) as $segment ) {

			if ( '..' === $segment ) {

				array_pop( $parts );

			} elseif ( '.' !== $segment ) {

				$parts[] = $segment;

			}

		}

		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		return $origin . implode( '/', $parts ) . ( '' !== $query ? '?' . $query : '' );

	}

}
