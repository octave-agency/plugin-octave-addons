<?php

/*
PERFORMANCE FIELD ROWS
-- Settings-row shortcuts shared by every Performance module, built on the
-- plugin's central field renderers so markup stays consistent
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

trait Octave_Addons_Perf_Field_Rows {


	protected function switch_row( string $key, string $label, string $help, array $s ): void {

		Octave_Addons_Fields::row( [
			'label' => $label,
			'for'   => $this->field_id( $key ),
			'field' => function () use ( $key, $help, $s ) {

				Octave_Addons_Fields::switch_field( [
					'name'    => $this->field_name( $key ),
					'id'      => $this->field_id( $key ),
					'checked' => ! empty( $s[ $key ] ),
					'help'    => $help,
				] );

			},
		] );

	}

	protected function textarea_row( string $key, string $label, string $help, array $s ): void {

		Octave_Addons_Fields::row( [
			'label' => $label,
			'for'   => $this->field_id( $key ),
			'field' => function () use ( $key, $help, $s ) {

				Octave_Addons_Fields::textarea( [
					'name'       => $this->field_name( $key ),
					'id'         => $this->field_id( $key ),
					'value'      => $s[ $key ],
					'rows'       => 3,
					'class'      => 'large-text code',
					'spellcheck' => false,
					'help'       => $help,
				] );

			},
		] );

	}

	protected function select_row( string $key, string $label, array $options, string $help, array $s ): void {

		Octave_Addons_Fields::row( [
			'label' => $label,
			'for'   => $this->field_id( $key ),
			'field' => function () use ( $key, $options, $help, $s ) {

				?>

				<select id="<?= esc_attr( $this->field_id( $key ) ); ?>" name="<?= esc_attr( $this->field_name( $key ) ); ?>">
					<?php

					foreach ( $options as $value => $option_label ) :

					?>

					<option value="<?= esc_attr( (string) $value ); ?>"<?php selected( (string) $s[ $key ], (string) $value ); ?>><?= esc_html( $option_label ); ?></option>

					<?php

					endforeach;

					?>
				</select>

				<?php

				if ( '' !== $help ) :

				?>

				<span class="oa-help"><?= esc_html( $help ); ?></span>

				<?php

				endif;

			},
		] );

	}

}
