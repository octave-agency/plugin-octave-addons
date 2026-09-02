<?php

/*
MODULE: TEXT SELECTION
-- Sets the highlight colour and the text colour people see when they select
-- text on the frontend. Both colours default to the Breakdance brand primary
-- variable so a site picks up its own palette without any configuration.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Text_Selection extends Octave_Addons_Module {

	public function get_id(): string {

		return 'text-selection';

	}

	public function get_title(): string {

		return __( 'Text Selection', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Choose the highlight colour and the text colour used when a visitor selects text on the frontend.', 'octave-addons' );

	}

	public function get_group(): string {

		return 'branding';

	}

	public function get_order(): int {

		return 30;

	}

	/*
	COLOR SOURCES
	-- The Breakdance variables a colour can follow. Each one names the global
	-- settings path Breakdance builds the variable from, so the admin preview
	-- can show the real colour, and the fallback Breakdance itself applies when
	-- that path is empty. Sources without a native settings path can resolve
	-- directly from a matching variable in Breakdance's global colour palette.
	---------------------------------------------------------- */

	protected function color_sources(): array {

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
		];

	}

	public function get_defaults(): array {

		return [
			'enabled'            => false,
			'background_source'  => 'brand',    // a colour source key, or custom
			'background_color'   => '#1769C2',
			'text_source'        => 'custom',   // a colour source key, custom or inherit
			'text_color'         => '#FFFFFF',
		];

	}

	public function sanitize( $input ): array {

		$clean            = $this->get_defaults();
		$clean['enabled'] = ! empty( $input['enabled'] );

		$sources = array_keys( $this->color_sources() );

		$clean['background_source'] = in_array( $input['background_source'] ?? '', array_merge( $sources, [ 'custom' ] ), true )
			? $input['background_source'] : 'brand';

		$clean['text_source'] = in_array( $input['text_source'] ?? '', array_merge( $sources, [ 'custom', 'inherit' ] ), true )
			? $input['text_source'] : 'custom';

		$clean['background_color'] = sanitize_hex_color( $input['background_color'] ?? '#1769C2' ) ?: '#1769C2';
		$clean['text_color']       = sanitize_hex_color( $input['text_color'] ?? '#FFFFFF' ) ?: '#FFFFFF';

		return $clean;

	}

	public function render_settings( array $s ): void {

		$resolved   = $this->resolved_source_colors();
		$background = $this->preview_color( $s['background_source'], $s['background_color'], $resolved );
		$text       = $this->preview_color( $s['text_source'], $s['text_color'], $resolved );

		$unresolved = '' === ( $resolved[ $s['background_source'] ]['value'] ?? 'n/a' )
			|| '' === ( $resolved[ $s['text_source'] ]['value'] ?? 'n/a' );

		$sample = '<span class="oa-selection-preview"'
			. ' style="' . esc_attr( 'background-color: ' . $background . ';' . ( '' !== $text ? ' color: ' . $text . ';' : '' ) ) . '">'
			. esc_html__( 'like this', 'octave-addons' )
			. '</span>';

		?>

		<div class="oa-selection-demo" data-oa-selection-preview data-colors="<?= esc_attr( (string) wp_json_encode( $resolved ) ); ?>">
			<span class="oa-selection-demo-kicker"><?php esc_html_e( 'Live preview', 'octave-addons' ); ?></span>
			<p class="oa-selection-demo-text">
				<?php

				printf(
					/* translators: %s: sample words rendered with the chosen selection colours. */
					esc_html__( 'Text a visitor highlights on the frontend looks %s.', 'octave-addons' ),
					$sample // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
				);

				?>
			</p>
			<span class="oa-help oa-selection-demo-note<?= $unresolved ? '' : ' oa-hidden'; ?>">
				<?php esc_html_e( 'A chosen Breakdance colour is not set in the site global settings, so it is previewed here with the custom colour instead. The frontend still follows the variable.', 'octave-addons' ); ?>
			</span>
		</div>

		<table class="form-table oa-form-table" role="presentation">

			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Highlight colour', 'octave-addons' ), 'first' => true ] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'background_source' ),
				'label' => __( 'Selection colour', 'octave-addons' ),
				'field' => function () use ( $s ) {

					?>

					<select id="<?= esc_attr( $this->field_id( 'background_source' ) ); ?>"
					        name="<?= esc_attr( $this->field_name( 'background_source' ) ); ?>"
					        data-controls-row="oaTsRowBackgroundColor" data-controls-value="custom">
						<?php $this->render_source_options( $s['background_source'] ); ?>
						<option value="custom" <?php selected( $s['background_source'], 'custom' ); ?>><?php esc_html_e( 'Custom colour', 'octave-addons' ); ?></option>
					</select>
					<span class="oa-help"><?php esc_html_e( 'A Breakdance colour follows its CSS variable, so the highlight changes with the site palette.', 'octave-addons' ); ?></span>
					<?php

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'id'    => 'oaTsRowBackgroundColor',
				'for'   => $this->field_id( 'background_color' ),
				'label' => __( 'Custom selection colour', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::color( [
						'id'    => $this->field_id( 'background_color' ),
						'name'  => $this->field_name( 'background_color' ),
						'value' => $s['background_color'],
						'help'  => __( 'Painted behind the selected text.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Selected text', 'octave-addons' ) ] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'text_source' ),
				'label' => __( 'Text colour when selected', 'octave-addons' ),
				'field' => function () use ( $s ) {

					?>

					<select id="<?= esc_attr( $this->field_id( 'text_source' ) ); ?>"
					        name="<?= esc_attr( $this->field_name( 'text_source' ) ); ?>"
					        data-controls-row="oaTsRowTextColor" data-controls-value="custom">
						<option value="custom" <?php selected( $s['text_source'], 'custom' ); ?>><?php esc_html_e( 'Custom colour', 'octave-addons' ); ?></option>
						<?php $this->render_source_options( $s['text_source'] ); ?>
						<option value="inherit" <?php selected( $s['text_source'], 'inherit' ); ?>><?php esc_html_e( 'Leave the text colour unchanged', 'octave-addons' ); ?></option>
					</select>
					<span class="oa-help"><?php esc_html_e( 'Keep enough contrast against the highlight colour or the selected words become hard to read.', 'octave-addons' ); ?></span>
					<?php

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'id'    => 'oaTsRowTextColor',
				'for'   => $this->field_id( 'text_color' ),
				'label' => __( 'Custom text colour', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::color( [
						'id'    => $this->field_id( 'text_color' ),
						'name'  => $this->field_name( 'text_color' ),
						'value' => $s['text_color'],
						'help'  => __( 'Used for the words inside the selection.', 'octave-addons' ),
					] );

				},
			] ); ?>
		</table>
		<?php

	}

	/*
	RENDER SOURCE OPTIONS
	-- Each option names the variable it writes so the mapping is visible
	-- without leaving the settings page.
	---------------------------------------------------------- */

	protected function render_source_options( string $selected ): void {

		foreach ( $this->color_sources() as $key => $source ) {

			?>

			<option value="<?= esc_attr( $key ); ?>" <?php selected( $selected, $key ); ?>>
				<?php

				printf(
					/* translators: 1: colour name, 2: Breakdance CSS variable name. */
					esc_html__( 'Breakdance %1$s (%2$s)', 'octave-addons' ),
					esc_html( $source['label'] ),
					esc_html( $source['variable'] )
				);

				?>
			</option>
			<?php

		}

	}

	/*
	RUN
	-- Prints the selection rules on the frontend.
	---------------------------------------------------------- */

	public function run( array $s ): void {

		add_action( 'wp_enqueue_scripts', function () use ( $s ) {

			$this->inject_styles( $s, 'oa-text-selection' );

		} );

	}

	/*
	INJECT STYLES
	-- ::selection and ::-moz-selection have to be printed as separate rules,
	-- because a browser that does not know one of them drops the whole
	-- selector list rather than just the part it cannot parse.
	---------------------------------------------------------- */

	protected function inject_styles( array $s, string $handle ): void {

		$css = $this->build_css( $s );

		if ( '' === $css ) {

			return;

		}

		wp_register_style( $handle, false, [], OCTAVE_ADDONS_VERSION );
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, $css );

	}

	/*
	BUILD CSS
	-- Produces the declaration block once and reuses it for both selectors.
	---------------------------------------------------------- */

	protected function build_css( array $s ): string {

		$background = $this->resolve_color( $s['background_source'] ?? 'brand', $s['background_color'] ?? '' );
		$text       = $this->resolve_color( $s['text_source'] ?? 'custom', $s['text_color'] ?? '' );

		$declarations = '';

		if ( '' !== $background ) {

			$declarations .= 'background-color: ' . $background . ';';

		}

		if ( '' !== $text ) {

			$declarations .= 'color: ' . $text . ';';

		}

		if ( '' === $declarations ) {

			return '';

		}

		return '::selection { ' . $declarations . ' } ::-moz-selection { ' . $declarations . ' }';

	}

	/*
	RESOLVED SOURCE COLORS
	-- Reads each Breakdance colour straight out of the global settings so the
	-- admin preview can show it, since Breakdance only prints the variables on
	-- the frontend. A colour that cannot be read is returned as an empty
	-- string, which the preview treats as unknown.
	---------------------------------------------------------- */

	protected function resolved_source_colors(): array {

		$settings = function_exists( 'Breakdance\\Data\\get_global_settings_array' )
			? \Breakdance\Data\get_global_settings_array()['settings'] ?? []
			: null;

		$resolved = [];

		foreach ( $this->color_sources() as $key => $source ) {

			$resolved[ $key ] = [
				'variable' => $source['variable'],
				'value'    => is_array( $settings ) ? $this->read_color( $settings, $source ) : '',
			];

		}

		return $resolved;

	}

	/*
	READ COLOR
	-- Walks the global settings paths in the order Breakdance falls back
	-- through them. A source without a native path is matched directly against
	-- the global palette by CSS variable name, while a path value can still
	-- follow one level of palette indirection.
	---------------------------------------------------------- */

	protected function read_color( array $settings, array $source ): string {

		$value = '';

		foreach ( $source['paths'] as $path ) {

			$value = $this->flatten_color( $this->dig( $settings, $path ) );

			if ( '' !== $value ) {

				break;

			}

		}

		if ( '' === $value ) {

			$value = $this->read_palette_color( $settings, ltrim( $source['variable'], '-' ) );

		}

		if ( '' === $value ) {

			return $source['fallback'];

		}

		if ( ! preg_match( '/^var\(\s*--([A-Za-z0-9_-]+)/', $value, $match ) ) {

			return $value;

		}

		$value = $this->read_palette_color( $settings, $match[1] );

		return 0 === strpos( $value, 'var(' ) ? '' : $value;

	}

	/*
	READ PALETTE COLOR
	-- Finds a Breakdance global colour by the CSS variable name it emits.
	---------------------------------------------------------- */

	protected function read_palette_color( array $settings, string $variable ): string {

		foreach ( $settings['colors']['palette']['colors'] ?? [] as $entry ) {

			if ( ( $entry['cssVariableName'] ?? '' ) !== $variable ) {

				continue;

			}

			return $this->flatten_color( $entry['value'] ?? '' );

		}

		return '';

	}

	/*
	DIG
	-- Reads a nested settings value without tripping over a missing branch.
	---------------------------------------------------------- */

	protected function dig( array $settings, array $path ) {

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
	-- wrap the same string in a value key.
	---------------------------------------------------------- */

	protected function flatten_color( $color ): string {

		if ( is_array( $color ) ) {

			$color = $color['value'] ?? '';

		}

		return is_string( $color ) ? trim( $color ) : '';

	}

	/*
	PREVIEW COLOR
	-- The Breakdance variables are only defined on the frontend, so the sample
	-- uses the colour read from the global settings and names the saved hex as
	-- its fallback rather than rendering as nothing.
	---------------------------------------------------------- */

	protected function preview_color( string $source, string $color, array $resolved ): string {

		if ( ! isset( $resolved[ $source ] ) ) {

			return $this->resolve_color( $source, $color );

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
	RESOLVE COLOR
	-- Turns a source and its saved hex into the value written to CSS. A
	-- Breakdance source stays a variable so it keeps tracking the site palette.
	---------------------------------------------------------- */

	protected function resolve_color( string $source, string $color ): string {

		$sources = $this->color_sources();

		if ( isset( $sources[ $source ] ) ) {

			return 'var(' . $sources[ $source ]['variable'] . ')';

		}

		if ( 'custom' === $source ) {

			return sanitize_hex_color( $color ) ?: '';

		}

		return '';

	}

}

return new Octave_Addons_Module_Text_Selection();
