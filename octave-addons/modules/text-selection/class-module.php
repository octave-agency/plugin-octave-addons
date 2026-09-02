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

	const BRAND_VARIABLE = '--bde-brand-primary-color';

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

	public function get_defaults(): array {

		return [
			'enabled'            => false,
			'background_source'  => 'brand',    // brand | custom
			'background_color'   => '#1769C2',
			'text_source'        => 'custom',   // brand | custom | inherit
			'text_color'         => '#FFFFFF',
			'include_admin'      => false,
		];

	}

	public function sanitize( $input ): array {

		$clean                  = $this->get_defaults();
		$clean['enabled']       = ! empty( $input['enabled'] );
		$clean['include_admin'] = ! empty( $input['include_admin'] );

		$clean['background_source'] = in_array( $input['background_source'] ?? '', [ 'brand', 'custom' ], true )
			? $input['background_source'] : 'brand';

		$clean['text_source'] = in_array( $input['text_source'] ?? '', [ 'brand', 'custom', 'inherit' ], true )
			? $input['text_source'] : 'custom';

		$clean['background_color'] = sanitize_hex_color( $input['background_color'] ?? '#1769C2' ) ?: '#1769C2';
		$clean['text_color']       = sanitize_hex_color( $input['text_color'] ?? '#FFFFFF' ) ?: '#FFFFFF';

		return $clean;

	}

	public function render_settings( array $s ): void {

		?>

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
						<option value="brand"  <?php selected( $s['background_source'], 'brand' ); ?>><?php esc_html_e( 'Breakdance brand primary colour', 'octave-addons' ); ?></option>
						<option value="custom" <?php selected( $s['background_source'], 'custom' ); ?>><?php esc_html_e( 'Custom colour', 'octave-addons' ); ?></option>
					</select>
					<span class="oa-help">
						<?php

						printf(
							/* translators: %s: Breakdance CSS variable name. */
							esc_html__( 'The brand option follows the %s variable, so the highlight changes with the site palette.', 'octave-addons' ),
							'<code>var(' . esc_html( self::BRAND_VARIABLE ) . ')</code>'
						);

						?>
					</span>
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
						<option value="custom"  <?php selected( $s['text_source'], 'custom' ); ?>><?php esc_html_e( 'Custom colour', 'octave-addons' ); ?></option>
						<option value="brand"   <?php selected( $s['text_source'], 'brand' ); ?>><?php esc_html_e( 'Breakdance brand primary colour', 'octave-addons' ); ?></option>
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

			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Where it applies', 'octave-addons' ) ] ); ?>
			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Include the WordPress admin', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'name'    => $this->field_name( 'include_admin' ),
						'checked' => $s['include_admin'],
						'help'    => __( 'Off by default so the admin keeps its own selection colour.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Preview', 'octave-addons' ),
				'field' => function () use ( $s ) {

					$background = $this->preview_color( $s['background_source'], $s['background_color'] );
					$text       = $this->preview_color( $s['text_source'], $s['text_color'] );

					?>

					<span class="oa-selection-preview" style="
						<?= 'background: ' . esc_attr( $background ) . ';'; ?>
						<?= '' !== $text ? 'color: ' . esc_attr( $text ) . ';' : ''; ?>
					"><?php esc_html_e( 'Selected text looks like this', 'octave-addons' ); ?></span>
					<span class="oa-help"><?php esc_html_e( 'The brand option resolves on the frontend, so it may render differently here.', 'octave-addons' ); ?></span>
					<?php

				},
			] ); ?>
		</table>
		<?php

	}

	/*
	RUN
	-- Prints the selection rules on the frontend, and in the admin only when
	-- the extra switch asks for it.
	---------------------------------------------------------- */

	public function run( array $s ): void {

		add_action( 'wp_enqueue_scripts', function () use ( $s ) {

			$this->inject_styles( $s, 'oa-text-selection' );

		} );

		if ( empty( $s['include_admin'] ) ) {

			return;

		}

		add_action( 'admin_enqueue_scripts', function () use ( $s ) {

			$this->inject_styles( $s, 'oa-text-selection-admin' );

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
	PREVIEW COLOR
	-- The Breakdance variable is only defined on the frontend, so the swatch
	-- names the saved hex as its fallback rather than rendering as nothing.
	---------------------------------------------------------- */

	protected function preview_color( string $source, string $color ): string {

		if ( 'brand' !== $source ) {

			return $this->resolve_color( $source, $color );

		}

		$fallback = sanitize_hex_color( $color ) ?: '';

		return '' !== $fallback
			? 'var(' . self::BRAND_VARIABLE . ', ' . $fallback . ')'
			: 'var(' . self::BRAND_VARIABLE . ')';

	}

	/*
	RESOLVE COLOR
	-- Turns a source and its saved hex into the value written to CSS. The
	-- brand source stays a variable so it keeps tracking the site palette.
	---------------------------------------------------------- */

	protected function resolve_color( string $source, string $color ): string {

		if ( 'brand' === $source ) {

			return 'var(' . self::BRAND_VARIABLE . ')';

		}

		if ( 'custom' === $source ) {

			return sanitize_hex_color( $color ) ?: '';

		}

		return '';

	}

}

return new Octave_Addons_Module_Text_Selection();
