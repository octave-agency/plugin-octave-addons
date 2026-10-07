<?php

/*
COLOUR SOURCES
-- Shared Breakdance colour-source architecture for any module that lets a
-- colour follow a Breakdance CSS variable or fall back to a custom hex
-- Frontend values stay as CSS variables so they keep tracking the palette,
-- while the admin preview reads the real colours from the global settings
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Colors {

	/*
	SOURCES
	-- The Breakdance variables a colour can follow. Each one names the global
	-- settings path Breakdance builds the variable from, so the admin preview
	-- can show the real colour, and the fallback Breakdance itself applies when
	-- that path is empty. Sources without a native settings path can resolve
	-- directly from a matching variable in Breakdance's global colour palette.
	---------------------------------------------------------- */

	public static function sources(): array {

		return [
			'brand' => [
				'label'    => __( 'brand primary', 'octave-addons' ),
				'variable' => '--bde-brand-primary-color',
				'paths'    => [ [ 'colors', 'brand' ] ],
				'fallback' => '#3B82F6',
			],
			'brand_secondary' => [
				'label'    => __( 'brand secondary', 'octave-addons' ),
				'variable' => '--bde-brand-secondary-color',
				'paths'    => [],
				'fallback' => '',
			],
			'body_text' => [
				'label'    => __( 'body text', 'octave-addons' ),
				'variable' => '--bde-body-text-color',
				'paths'    => [ [ 'colors', 'text' ], [ 'typography', 'advanced', 'body', 'color' ] ],
				'fallback' => '#374151',
			],
			'headings' => [
				'label'    => __( 'headings', 'octave-addons' ),
				'variable' => '--bde-headings-color',
				'paths'    => [ [ 'colors', 'headings' ] ],
				'fallback' => '#111827',
			],
			'background' => [
				'label'    => __( 'background', 'octave-addons' ),
				'variable' => '--bde-background-color',
				'paths'    => [ [ 'colors', 'background' ] ],
				'fallback' => '#F9FAFB',
			],
		];

	}

	/*
	SANITIZE SOURCE
	-- Accepts a known source key or one of the extra keys a caller allows
	---------------------------------------------------------- */

	public static function sanitize_source( $value, string $default, array $extra = [ 'custom' ] ): string {

		$allowed = array_merge( array_keys( self::sources() ), $extra );

		return in_array( $value, $allowed, true ) ? $value : $default;

	}

	/*
	RENDER OPTIONS
	-- Keeps the human-readable colour name in the dropdown while the source key
	-- retains its CSS variable mapping behind the scenes
	---------------------------------------------------------- */

	public static function render_options( string $selected, array $only = [] ): void {

		// Without Breakdance its variables do not exist, so only the caller's own options remain.
		if ( ! Octave_Addons_Builders::breakdance() ) {

			return;

		}

		foreach ( self::sources() as $key => $source ) {

			if ( $only && ! in_array( $key, $only, true ) ) {

				continue;

			}

			?>

			<option value="<?= esc_attr( $key ); ?>" <?php selected( $selected, $key ); ?>>
				<?php

				printf(
					/* translators: %s: Breakdance colour name. */
					esc_html__( 'Breakdance %s', 'octave-addons' ),
					esc_html( $source['label'] )
				);

				?>
			</option>
			<?php

		}

	}

	/*
	RESOLVE
	-- Turns a source and its saved hex into the value written to CSS. A
	-- Breakdance source stays a variable so it keeps tracking the site palette,
	-- with the saved hex as its fallback; without Breakdance it is that hex
	---------------------------------------------------------- */

	public static function resolve( string $source, string $color ): string {

		$sources = self::sources();

		if ( isset( $sources[ $source ] ) ) {

			$hex = sanitize_hex_color( $color ) ?: '';

			// Saved while Breakdance was active: without it the saved colour, or the variable's own fallback, stands in.
			if ( ! Octave_Addons_Builders::breakdance() ) {

				return '' !== $hex ? $hex : (string) $sources[ $source ]['fallback'];

			}

			return 'var(' . $sources[ $source ]['variable'] . ( '' !== $hex ? ', ' . $hex : '' ) . ')';

		}

		if ( 'custom' === $source ) {

			return sanitize_hex_color( $color ) ?: '';

		}

		return '';

	}

	/*
	RESOLVED VALUES
	-- Reads each Breakdance colour straight out of the global settings so the
	-- admin preview can show it, since Breakdance only prints the variables on
	-- the frontend. A colour that cannot be read is returned as an empty
	-- string, which the preview treats as unknown.
	---------------------------------------------------------- */

	public static function resolved_values(): array {

		// Without Breakdance there is no palette to read; previews use the saved colours.
		if ( ! Octave_Addons_Builders::breakdance() ) {

			return [];

		}

		$settings = function_exists( 'Breakdance\\Data\\get_global_settings_array' )
			? \Breakdance\Data\get_global_settings_array()['settings'] ?? []
			: null;

		$resolved = [];

		foreach ( self::sources() as $key => $source ) {

			$resolved[ $key ] = [
				'variable' => $source['variable'],
				'value'    => is_array( $settings ) ? self::read_color( $settings, $source ) : '',
			];

		}

		return $resolved;

	}

	/*
	PREVIEW
	-- The Breakdance variables are only defined on the frontend, so a preview
	-- uses the colour read from the global settings and names the saved hex as
	-- its fallback rather than rendering as nothing
	---------------------------------------------------------- */

	public static function preview( string $source, string $color, array $resolved ): string {

		if ( ! isset( $resolved[ $source ] ) ) {

			return self::resolve( $source, $color );

		}

		if ( '' !== $resolved[ $source ]['value'] ) {

			return $resolved[ $source ]['value'];

		}

		$fallback = sanitize_hex_color( $color ) ?: '';

		return '' !== $fallback
			? 'var(' . $resolved[ $source ]['variable'] . ', ' . $fallback . ')'
			: 'var(' . $resolved[ $source ]['variable'] . ')';

	}

	/*
	READ COLOR
	-- Walks the global settings paths in the order Breakdance falls back
	-- through them. A source without a native path is matched directly against
	-- the global palette by CSS variable name, while a path value can still
	-- follow one level of palette indirection.
	---------------------------------------------------------- */

	protected static function read_color( array $settings, array $source ): string {

		$value = '';

		foreach ( $source['paths'] as $path ) {

			$value = self::flatten_color( self::dig( $settings, $path ) );

			if ( '' !== $value ) {

				break;

			}

		}

		if ( '' === $value ) {

			$value = self::read_palette_color( $settings, ltrim( $source['variable'], '-' ) );

		}

		if ( '' === $value ) {

			return $source['fallback'];

		}

		if ( ! preg_match( '/^var\(\s*--([A-Za-z0-9_-]+)/', $value, $match ) ) {

			return $value;

		}

		$value = self::read_palette_color( $settings, $match[1] );

		return 0 === strpos( $value, 'var(' ) ? '' : $value;

	}

	/*
	READ PALETTE COLOR
	-- Finds a Breakdance global colour by the CSS variable name it emits
	---------------------------------------------------------- */

	protected static function read_palette_color( array $settings, string $variable ): string {

		foreach ( $settings['colors']['palette']['colors'] ?? [] as $entry ) {

			if ( ( $entry['cssVariableName'] ?? '' ) !== $variable ) {

				continue;

			}

			return self::flatten_color( $entry['value'] ?? '' );

		}

		return '';

	}

	/*
	DIG
	-- Reads a nested settings value without tripping over a missing branch
	---------------------------------------------------------- */

	protected static function dig( array $settings, array $path ) {

		$value = $settings;

		foreach ( $path as $key ) {

			if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {

				return null;

			}

			$value = $value[ $key ];

		}

		return $value;

	}

	/*
	FLATTEN COLOR
	-- A Breakdance colour is normally a plain CSS string, but palette entries
	-- wrap the same string in a value key
	---------------------------------------------------------- */

	protected static function flatten_color( $color ): string {

		if ( is_array( $color ) ) {

			$color = $color['value'] ?? '';

		}

		return is_string( $color ) ? trim( $color ) : '';

	}

}
