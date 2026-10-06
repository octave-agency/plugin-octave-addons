<?php

/*
MINIFIER
-- Conservative per-file CSS and JavaScript minification. Both work as small
-- scanners that understand strings, comments, regular expressions and
-- template literals, so code inside them is copied byte for byte
-- Compatibility beats compression: JavaScript keeps every line break (so
-- automatic semicolon insertion behaves exactly as before) and only loses
-- comments and indentation. Anything the scanner is unsure about returns
-- null, and the caller serves the original file
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Perf_Minifier {

	/*
	CSS
	-- Drops comments (keeping /*! licence blocks), collapses whitespace and
	-- removes spaces next to { } ; , > and the last semicolon in a block
	---------------------------------------------------------- */

	public static function css( string $css ): ?string {

		$out    = '';
		$length = strlen( $css );
		$tight  = '{};,>';

		for ( $i = 0; $i < $length; $i++ ) {

			$char = $css[ $i ];

			if ( '/' === $char && '*' === ( $css[ $i + 1 ] ?? '' ) ) {

				$end = strpos( $css, '*/', $i + 2 );

				if ( false === $end ) {

					return null;

				}

				if ( '!' === ( $css[ $i + 2 ] ?? '' ) ) {

					$out .= substr( $css, $i, $end + 2 - $i ) . "\n";

				} elseif ( '' !== $out && ! ctype_space( substr( $out, -1 ) ) ) {

					$out .= ' ';

				}

				$i = $end + 1;

				continue;

			}

			if ( '"' === $char || "'" === $char ) {

				$end = self::string_end( $css, $i, $char, false );

				if ( null === $end ) {

					return null;

				}

				$out .= substr( $css, $i, $end - $i + 1 );
				$i    = $end;

				continue;

			}

			if ( ctype_space( $char ) ) {

				$last = substr( $out, -1 );

				if ( '' !== $out && ! ctype_space( $last ) && false === strpos( $tight, $last ) ) {

					$out .= ' ';

				}

				continue;

			}

			if ( false !== strpos( $tight, $char ) ) {

				$out = rtrim( $out, ' ' );

				if ( '}' === $char && ';' === substr( $out, -1 ) ) {

					$out = substr( $out, 0, -1 );

				}

			}

			$out .= $char;

		}

		return trim( $out );

	}

	/*
	JS
	-- Removes comments (keeping /*! licence blocks), indentation, trailing
	-- spaces and blank lines, and collapses runs of spaces between tokens.
	-- A comment spanning lines leaves a line break behind, so statements
	-- never join onto one line
	---------------------------------------------------------- */

	public static function js( string $js ): ?string {

		$out    = '';
		$length = strlen( $js );
		$stack  = [];

		for ( $i = 0; $i < $length; $i++ ) {

			$char = $js[ $i ];
			$next = $js[ $i + 1 ] ?? '';

			// Inside a template literal's text: copy until ${ or the closing backtick.
			if ( 'template' === end( $stack ) ) {

				if ( '\\' === $char ) {

					$out .= $char . $next;
					$i++;

					continue;

				}

				if ( '`' === $char ) {

					array_pop( $stack );

				} elseif ( '$' === $char && '{' === $next ) {

					$stack[] = 0;
					$out    .= '${';
					$i++;

					continue;

				}

				$out .= $char;

				continue;

			}

			if ( '/' === $char && '*' === $next ) {

				$end = strpos( $js, '*/', $i + 2 );

				if ( false === $end ) {

					return null;

				}

				$comment = substr( $js, $i, $end + 2 - $i );

				if ( '!' === ( $js[ $i + 2 ] ?? '' ) ) {

					$out .= $comment;

				} else {

					$out .= false !== strpos( $comment, "\n" ) ? "\n" : ' ';

				}

				$i = $end + 1;

				continue;

			}

			if ( '/' === $char && '/' === $next ) {

				$end = strpos( $js, "\n", $i );
				$i   = ( false === $end ? $length : $end ) - 1;

				continue;

			}

			if ( '"' === $char || "'" === $char ) {

				$end = self::string_end( $js, $i, $char, true );

				if ( null === $end ) {

					return null;

				}

				$out .= substr( $js, $i, $end - $i + 1 );
				$i    = $end;

				continue;

			}

			if ( '`' === $char ) {

				$stack[] = 'template';
				$out    .= $char;

				continue;

			}

			if ( '/' === $char && self::regex_allowed( $out ) ) {

				$end = self::regex_end( $js, $i );

				if ( null === $end ) {

					return null;

				}

				$out .= substr( $js, $i, $end - $i + 1 );
				$i    = $end;

				continue;

			}

			// Braces inside a ${ } expression decide when the template resumes.
			if ( ! empty( $stack ) && is_int( end( $stack ) ) ) {

				if ( '{' === $char ) {

					$stack[ count( $stack ) - 1 ]++;

				} elseif ( '}' === $char ) {

					if ( 0 === end( $stack ) ) {

						array_pop( $stack );
						$out .= $char;

						continue;

					}

					$stack[ count( $stack ) - 1 ]--;

				}

			}

			if ( "\n" === $char || "\r" === $char ) {

				$out = rtrim( $out, " \t" );

				if ( '' !== $out && "\n" !== substr( $out, -1 ) ) {

					$out .= "\n";

				}

				continue;

			}

			if ( ' ' === $char || "\t" === $char ) {

				$last = substr( $out, -1 );

				if ( '' !== $out && ' ' !== $last && "\n" !== $last ) {

					$out .= ' ';

				}

				continue;

			}

			$out .= $char;

		}

		if ( ! empty( $stack ) ) {

			return null;

		}

		return trim( $out );

	}

	/*
	STRING END
	-- Index of the closing quote, honouring backslash escapes. JavaScript
	-- strings cannot hold a raw line break, so one means the scan went wrong
	---------------------------------------------------------- */

	protected static function string_end( string $source, int $start, string $quote, bool $single_line ): ?int {

		$length = strlen( $source );

		for ( $i = $start + 1; $i < $length; $i++ ) {

			$char = $source[ $i ];

			if ( '\\' === $char ) {

				$i++;

				continue;

			}

			if ( $quote === $char ) {

				return $i;

			}

			if ( $single_line && "\n" === $char ) {

				return null;

			}

		}

		return null;

	}

	/*
	REGEX ALLOWED
	-- A slash starts a regular expression after an operator, an opening
	-- bracket, the start of input or a keyword such as return or typeof;
	-- after a value it is division
	---------------------------------------------------------- */

	protected static function regex_allowed( string $out ): bool {

		$trimmed = rtrim( $out );

		if ( '' === $trimmed ) {

			return true;

		}

		if ( false !== strpos( '(,=:[!&|?{};+-*%<>~^', substr( $trimmed, -1 ) ) ) {

			return true;

		}

		if ( preg_match( '/(?:^|[^\w$.])(return|typeof|instanceof|in|of|new|delete|void|throw|case|do|else|yield|await)$/', $trimmed ) ) {

			return true;

		}

		return false;

	}

	/*
	REGEX END
	-- Index of the closing slash, skipping escapes and [character classes]
	---------------------------------------------------------- */

	protected static function regex_end( string $source, int $start ): ?int {

		$length   = strlen( $source );
		$in_class = false;

		for ( $i = $start + 1; $i < $length; $i++ ) {

			$char = $source[ $i ];

			if ( '\\' === $char ) {

				$i++;

				continue;

			}

			if ( "\n" === $char ) {

				return null;

			}

			if ( '[' === $char ) {

				$in_class = true;

			} elseif ( ']' === $char ) {

				$in_class = false;

			} elseif ( '/' === $char && ! $in_class ) {

				return $i;

			}

		}

		return null;

	}

	/*
	ABSOLUTIZE CSS URLS
	-- A minified stylesheet is served from the cache folder, so relative
	-- url() and @import references are rewritten against the original
	-- file's URL. Absolute, root-relative, data and fragment URLs are kept
	---------------------------------------------------------- */

	public static function absolutize_css_urls( string $css, string $source_url ): string {

		$base = preg_replace( '/[?#].*$/', '', $source_url );
		$base = substr( $base, 0, (int) strrpos( $base, '/' ) + 1 );

		$resolve = static function ( string $url ) use ( $base ): string {

			$trimmed = trim( $url );

			if ( '' === $trimmed || preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//|/|\#|%23)#i', $trimmed ) ) {

				return $url;

			}

			return $base . $trimmed;

		};

		$css = preg_replace_callback( '/url\(\s*([\'"]?)([^\'")]*)\1\s*\)/i', static function ( array $match ) use ( $resolve ): string {

			return 'url(' . $match[1] . $resolve( $match[2] ) . $match[1] . ')';

		}, $css );

		return (string) preg_replace_callback( '/@import\s+([\'"])([^\'"]+)\1/i', static function ( array $match ) use ( $resolve ): string {

			return '@import ' . $match[1] . $resolve( $match[2] ) . $match[1];

		}, (string) $css );

	}

}
