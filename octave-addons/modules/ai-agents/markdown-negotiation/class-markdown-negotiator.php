<?php

/*
MARKDOWN NEGOTIATOR
-- Decides whether an incoming request should be answered with Markdown, by
-- reading the Accept header the way RFC 9110 says a server should: every
-- media range carries a quality value, and the highest one the server can
-- produce wins.
-- The rule that matters in practice is that a browser never names
-- text/markdown. It offers text/html at q=1 and a wildcard catch-all lower
-- down, so a browser is never tipped into the Markdown variant by accident,
-- while an agent that asks for Markdown by name always gets it.
---------------------------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Markdown_Negotiator {


	/** The media types this server will answer a Markdown request with. */
	public const MARKDOWN_TYPES = [ 'text/markdown', 'text/x-markdown' ];

	/** The media type sent back on a Markdown response. */
	public const CONTENT_TYPE = 'text/markdown; charset=utf-8';

	/*
	WANTS MARKDOWN
	-- True when the client named a Markdown type explicitly and rated it at
	-- least as highly as HTML. Both halves are needed: the first stops a
	-- wildcard from standing in for a real preference, the second honours a
	-- client that lists Markdown only as a fallback behind HTML.
	---------------------------------------------------------------------------- */

	public static function wants_markdown( string $accept ): bool {

		$ranges = self::parse( $accept );

		if ( ! $ranges ) {

			return false;

		}

		$markdown = self::quality( $ranges, self::MARKDOWN_TYPES, false );

		if ( $markdown <= 0.0 ) {

			return false;

		}

		return $markdown >= self::quality( $ranges, [ 'text/html', 'application/xhtml+xml' ], true );

	}

	/*
	PARSE
	-- Turns an Accept header into media ranges keyed by their quality value.
	-- A range without a q parameter is q=1 by definition, and anything that
	-- does not look like a media range at all is skipped rather than guessed
	-- at, since a malformed header should not change what gets served.
	---------------------------------------------------------------------------- */

	public static function parse( string $accept ): array {

		$accept = trim( $accept );

		if ( '' === $accept ) {

			return [];

		}

		$ranges = [];

		foreach ( explode( ',', $accept ) as $entry ) {

			$parts = explode( ';', $entry );
			$type  = strtolower( trim( array_shift( $parts ) ) );

			if ( '' === $type || false === strpos( $type, '/' ) ) {

				continue;

			}

			$quality = 1.0;

			foreach ( $parts as $parameter ) {

				if ( ! preg_match( '/^\s*q\s*=\s*([0-9]*\.?[0-9]+)\s*$/i', $parameter, $match ) ) {

					continue;

				}

				$quality = min( 1.0, max( 0.0, (float) $match[1] ) );

			}

			// A type repeated at different weights keeps the strongest of them.
			$ranges[ $type ] = isset( $ranges[ $type ] ) ? max( $ranges[ $type ], $quality ) : $quality;

		}

		return $ranges;

	}

	/*
	QUALITY
	-- The best quality value the client gave for a set of media types. With
	-- $wildcards on, the subtype and full wildcard ranges count as a match,
	-- which is how HTML scores on a browser that only spells out a catch-all.
	---------------------------------------------------------------------------- */

	public static function quality( array $ranges, array $types, bool $wildcards ): float {

		$best = 0.0;

		foreach ( $types as $type ) {

			$candidates = [ $type ];

			if ( $wildcards ) {

				$candidates[] = strtok( $type, '/' ) . '/*';
				$candidates[] = '*/*';

			}

			foreach ( $candidates as $candidate ) {

				if ( isset( $ranges[ $candidate ] ) ) {

					$best = max( $best, $ranges[ $candidate ] );

				}

			}

		}

		return $best;

	}

	/*
	REQUEST ACCEPT HEADER
	-- Reads the Accept header off the current request.
	---------------------------------------------------------------------------- */

	public static function request_accept(): string {

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitised -- parsed into media ranges below.
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? wp_unslash( $_SERVER['HTTP_ACCEPT'] ) : '';

		return is_string( $accept ) ? $accept : '';

	}

	/*
	ESTIMATE TOKENS
	-- A rough token count for the x-markdown-tokens and x-original-tokens
	-- headers. Four characters per token is the usual approximation for
	-- English prose and is all these headers are meant to convey — an agent
	-- uses them to decide whether a fetch is worth its budget, not to bill on.
	---------------------------------------------------------------------------- */

	public static function estimate_tokens( string $text ): int {

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );

		return (int) ceil( $length / 4 );

	}

}
