<?php

/*
BREAKDANCE ICONS
-- Backs the Icon post field. Icons come from Breakdance's own icon library
-- (its stock sets and any set uploaded in Breakdance > Icons), searched
-- through an editor-only AJAX endpoint so the picker never loads thousands
-- of SVGs at once
-- A chosen icon is stored as its SVG, so the value renders anywhere,
-- Breakdance Dynamic Data included, even if the set is later removed. Its
-- name and set ride along as data attributes for the picker to show
-- Every stored SVG is rebuilt from an allowlist of SVG tags and attributes:
-- no scripts, event handlers, links, styles or text survive, whoever sent it
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Breakdance_Icons {

	public const AJAX_ACTION = 'octave_addons_icon_search';
	public const NONCE       = 'octave_addons_icon_search';

	/** Icons returned per search page. */
	public const PER_PAGE = 60;

	/** @var array<string, string>|null Icon sets, per request. */
	protected static ?array $sets = null;

	/** Largest SVG accepted, in bytes. */
	protected const MAX_BYTES = 100 * KB_IN_BYTES;

	/** SVG elements kept, lowercased as the HTML API reports them. */
	protected const TAGS = [ 'svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect', 'defs', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'symbol' ];

	/** SVG names that are case-sensitive outside HTML, restored from the lowercase the HTML API reports. */
	protected const CAMEL_CASE = [
		'lineargradient'      => 'linearGradient',
		'radialgradient'      => 'radialGradient',
		'clippath'            => 'clipPath',
		'viewbox'             => 'viewBox',
		'preserveaspectratio' => 'preserveAspectRatio',
		'gradientunits'       => 'gradientUnits',
		'gradienttransform'   => 'gradientTransform',
		'maskunits'           => 'maskUnits',
		'clippathunits'       => 'clipPathUnits',
	];

	/** Attributes kept on any allowed element. */
	protected const ATTRIBUTES = [
		'xmlns', 'viewbox', 'width', 'height', 'preserveaspectratio', 'class', 'id', 'role', 'aria-hidden', 'focusable',
		'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'points', 'transform', 'offset',
		'fill', 'fill-rule', 'fill-opacity', 'clip-rule', 'clip-path', 'mask', 'opacity',
		'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity',
		'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform', 'maskunits', 'clippathunits',
		'data-oa-icon-name', 'data-oa-icon-set',
	];

	/*
	BOOT
	---------------------------------------------------------- */

	public static function boot(): void {

		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ __CLASS__, 'ajax_search' ] );

	}

	/*
	AVAILABLE
	-- Whether Breakdance's icon library can be searched on this site
	---------------------------------------------------------- */

	public static function available(): bool {

		return function_exists( '\\Breakdance\\Icons\\find_icons' ) && function_exists( '\\Breakdance\\Icons\\get_icon_sets' );

	}

	/*
	SETS
	-- [ slug => name ] for every icon set in the library
	---------------------------------------------------------- */

	public static function sets(): array {

		if ( null !== self::$sets ) {

			return self::$sets;

		}

		if ( ! self::available() ) {

			return [];

		}

		$sets = [];

		foreach ( (array) \Breakdance\Icons\get_icon_sets() as $set ) {

			if ( is_array( $set ) && ! empty( $set['slug'] ) ) {

				$sets[ (string) $set['slug'] ] = (string) ( $set['name'] ?? $set['slug'] );

			}

		}

		return self::$sets = $sets;

	}

	/*
	SEARCH
	-- One page of icons as [ name, set, value ], value being the stored SVG
	---------------------------------------------------------- */

	public static function search( string $term, string $set, int $offset ): array {

		if ( ! self::available() ) {

			return [];

		}

		$icons = (array) \Breakdance\Icons\find_icons(
			[
				'search_term'   => '' !== $term ? $term : null,
				'icon_set_slug' => '' !== $set ? $set : null,
				'offset'        => max( 0, $offset ),
				'suggestions'   => null,
			],
			self::PER_PAGE
		);

		$results = [];

		foreach ( $icons as $icon ) {

			$value = self::value( (string) ( $icon['svgCode'] ?? '' ), (string) ( $icon['name'] ?? '' ), (string) ( $icon['iconSetSlug'] ?? '' ) );

			if ( '' !== $value ) {

				$results[] = [ 'name' => self::name( $value ), 'set' => self::set( $value ), 'value' => $value ];

			}

		}

		return $results;

	}

	/*
	AJAX SEARCH
	-- Editors only, behind a nonce
	---------------------------------------------------------- */

	public static function ajax_search(): void {

		if ( false === check_ajax_referer( self::NONCE, 'nonce', false ) || ! current_user_can( 'edit_posts' ) ) {

			wp_send_json_error( [ 'message' => __( 'You do not have permission to browse icons.', 'octave-addons' ) ], 403 );

		}

		if ( ! self::available() ) {

			wp_send_json_error( [ 'message' => __( 'Breakdance is not active, so its icons cannot be browsed.', 'octave-addons' ) ] );

		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$term   = isset( $_POST['search'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['search'] ) ), 0, 80 ) : '';
		$set    = isset( $_POST['set'] ) ? sanitize_text_field( wp_unslash( $_POST['set'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		// phpcs:enable

		$set   = array_key_exists( $set, self::sets() ) ? $set : '';
		$icons = self::search( $term, $set, $offset );

		wp_send_json_success( [ 'icons' => $icons, 'more' => count( $icons ) >= self::PER_PAGE ] );

	}

	/*
	VALUE
	-- The stored form of one icon: its sanitised SVG, carrying its name and
	-- set as data attributes. '' when the SVG is unusable
	---------------------------------------------------------- */

	public static function value( string $svg, string $name, string $set ): string {

		$clean = self::sanitize( $svg );

		if ( '' === $clean ) {

			return '';

		}

		$tags = new WP_HTML_Tag_Processor( $clean );

		$tags->next_tag( 'svg' );
		$tags->set_attribute( 'data-oa-icon-name', trim( $name ) );
		$tags->set_attribute( 'data-oa-icon-set', trim( $set ) );

		return $tags->get_updated_html();

	}

	/*
	SANITIZE
	-- Rebuilds the first <svg> from allowed elements and attributes only.
	-- Text is never copied, so nothing inside a stripped element survives
	---------------------------------------------------------- */

	public static function sanitize( $svg ): string {

		$svg = trim( (string) $svg );

		if ( '' === $svg || strlen( $svg ) > self::MAX_BYTES || false === stripos( $svg, '<svg' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {

			return '';

		}

		$tags   = new WP_HTML_Tag_Processor( $svg );
		$output = '';
		$depth  = 0;
		$open   = [];

		while ( $tags->next_tag( [ 'tag_closers' => 'visit' ] ) ) {

			$tag = strtolower( (string) $tags->get_tag() );

			if ( 0 === $depth && 'svg' !== $tag ) {

				continue;

			}

			if ( ! in_array( $tag, self::TAGS, true ) ) {

				continue;

			}

			if ( $tags->is_tag_closer() ) {

				// Only close what was opened, so stray closers cannot unbalance the markup.
				if ( end( $open ) === $tag ) {

					array_pop( $open );
					$output .= '</' . ( self::CAMEL_CASE[ $tag ] ?? $tag ) . '>';
					$depth--;

				}

				if ( 0 === $depth ) {

					break;

				}

				continue;

			}

			$output .= '<' . ( self::CAMEL_CASE[ $tag ] ?? $tag ) . self::attributes( $tags );

			if ( $tags->has_self_closing_flag() && 'svg' !== $tag ) {

				$output .= '/>';

				continue;

			}

			$output .= '>';
			$open[]  = $tag;
			$depth++;

		}

		while ( ! empty( $open ) ) {

			$tag     = array_pop( $open );
			$output .= '</' . ( self::CAMEL_CASE[ $tag ] ?? $tag ) . '>';

		}

		return 0 === strpos( $output, '<svg' ) ? $output : '';

	}

	protected static function attributes( WP_HTML_Tag_Processor $tags ): string {

		$markup = '';

		foreach ( (array) $tags->get_attribute_names_with_prefix( '' ) as $name ) {

			$name  = strtolower( (string) $name );
			$value = $tags->get_attribute( $name );

			if ( ! in_array( $name, self::ATTRIBUTES, true ) || ! is_string( $value ) ) {

				continue;

			}

			// A paint or clip reference may only point inside the icon.
			if ( false !== stripos( $value, 'url(' ) && ! preg_match( '/^\s*url\(\s*#[\w-]+\s*\)\s*$/i', $value ) ) {

				continue;

			}

			if ( preg_match( '/javascript:|expression\s*\(/i', $value ) ) {

				continue;

			}

			$markup .= ' ' . ( self::CAMEL_CASE[ $name ] ?? $name ) . '="' . esc_attr( $value ) . '"';

		}

		return $markup;

	}

	/*
	NAME AND SET
	-- Read back from a stored value for the picker's label
	---------------------------------------------------------- */

	public static function name( string $value ): string {

		return self::data( $value, 'data-oa-icon-name' );

	}

	public static function set( string $value ): string {

		return self::data( $value, 'data-oa-icon-set' );

	}

	protected static function data( string $value, string $attribute ): string {

		if ( '' === $value || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {

			return '';

		}

		$tags = new WP_HTML_Tag_Processor( $value );

		return $tags->next_tag( 'svg' ) ? (string) $tags->get_attribute( $attribute ) : '';

	}

}
