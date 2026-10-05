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

	/**
	 * Shared colour sources this module offers.
	 */
	protected const SOURCE_KEYS = [ 'brand', 'brand_secondary', 'body_text', 'headings' ];

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
	-- The Breakdance variables a selection colour can follow, from the shared
	-- colour-source list
	---------------------------------------------------------- */

	protected function color_sources(): array {

		return array_intersect_key( Octave_Addons_Colors::sources(), array_flip( self::SOURCE_KEYS ) );

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
	-- Prints the Breakdance colour options this module offers
	---------------------------------------------------------- */

	protected function render_source_options( string $selected ): void {

		Octave_Addons_Colors::render_options( $selected, self::SOURCE_KEYS );

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
	-- Reads the real Breakdance colours for the admin preview
	---------------------------------------------------------- */

	protected function resolved_source_colors(): array {

		return array_intersect_key( Octave_Addons_Colors::resolved_values(), array_flip( self::SOURCE_KEYS ) );

	}

	/*
	PREVIEW COLOR
	-- Shows the resolved colour in the admin, falling back to the saved hex
	---------------------------------------------------------- */

	protected function preview_color( string $source, string $color, array $resolved ): string {

		return Octave_Addons_Colors::preview( $source, $color, $resolved );

	}

	/*
	RESOLVE COLOR
	-- Turns a source and its saved hex into the value written to CSS
	---------------------------------------------------------- */

	protected function resolve_color( string $source, string $color ): string {

		return Octave_Addons_Colors::resolve( $source, $color );

	}

}

return new Octave_Addons_Module_Text_Selection();
