<?php

/*
MODULE: MODERN WORDPRESS ADMIN
-- The settings face of the site-wide admin refresh. The refresh itself lives
-- in Octave_Addons_Admin_Experience, which loads far earlier than modules do,
-- so this module owns the stored values and that class reads them back.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Modern_Admin extends Octave_Addons_Module {

	public function get_id(): string {

		return 'modern-admin';

	}

	public function get_title(): string {

		return __( 'Modern WordPress Admin', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Refreshes the complete WordPress admin and lets each user switch between light and dark mode from the admin bar.', 'octave-addons' );

	}

	public function get_group(): string {

		return 'branding';

	}

	public function get_order(): int {

		return 10;

	}

	public function get_defaults(): array {

		return Octave_Addons_Admin_Experience::get_setting_defaults();

	}

	public function sanitize( $input ): array {

		return Octave_Addons_Admin_Experience::sanitize_settings( $input );

	}

	/*
	RUN
	-- Nothing to hook. Octave_Addons_Admin_Experience registers itself on
	-- construction and reads these settings when it enqueues.
	---------------------------------------------------------- */

	public function run( array $s ): void {

	}

	public function render_settings( array $s ): void {

		?>

		<table class="form-table oa-form-table" role="presentation">

			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Appearance', 'octave-addons' ), 'first' => true ] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'default_theme' ),
				'label' => __( 'Default appearance', 'octave-addons' ),
				'field' => function () use ( $s ) {

					?>

					<select id="<?= esc_attr( $this->field_id( 'default_theme' ) ); ?>"
					        name="<?= esc_attr( $this->field_name( 'default_theme' ) ); ?>">
						<option value="system" <?php selected( $s['default_theme'], 'system' ); ?>><?php esc_html_e( 'Follow the operating system', 'octave-addons' ); ?></option>
						<option value="light"  <?php selected( $s['default_theme'], 'light' ); ?>><?php esc_html_e( 'Light', 'octave-addons' ); ?></option>
						<option value="dark"   <?php selected( $s['default_theme'], 'dark' ); ?>><?php esc_html_e( 'Dark', 'octave-addons' ); ?></option>
					</select>
					<span class="oa-help"><?php esc_html_e( 'Where a browser starts before anyone touches the admin bar toggle. Each person’s own choice is remembered per device and always wins.', 'octave-addons' ); ?></span>
					<?php

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Admin bar toggle', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'name'    => $this->field_name( 'show_toggle' ),
						'checked' => $s['show_toggle'],
						'help'    => __( 'Show the light and dark mode control in the admin bar. Turn it off to hold everyone on the default appearance.', 'octave-addons' ),
					] );

				},
			] ); ?>

			<?php Octave_Addons_Fields::section( [ 'label' => __( 'Accent colour', 'octave-addons' ) ] ); ?>
			<?php Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'accent_source' ),
				'label' => __( 'Accent', 'octave-addons' ),
				'field' => function () use ( $s ) {

					?>

					<select id="<?= esc_attr( $this->field_id( 'accent_source' ) ); ?>"
					        name="<?= esc_attr( $this->field_name( 'accent_source' ) ); ?>"
					        data-controls-row="oaMaRowAccentColor" data-controls-value="custom">
						<option value="default" <?php selected( $s['accent_source'], 'default' ); ?>><?php esc_html_e( 'Octave default blue', 'octave-addons' ); ?></option>
						<option value="custom"  <?php selected( $s['accent_source'], 'custom' ); ?>><?php esc_html_e( 'Custom brand colour', 'octave-addons' ); ?></option>
					</select>
					<span class="oa-help"><?php esc_html_e( 'Drives buttons, links, focus rings and active states across the refreshed admin. Light and dark variants are derived automatically.', 'octave-addons' ); ?></span>
					<?php

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'id'    => 'oaMaRowAccentColor',
				'for'   => $this->field_id( 'accent_color' ),
				'label' => __( 'Brand colour', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::color( [
						'id'    => $this->field_id( 'accent_color' ),
						'name'  => $this->field_name( 'accent_color' ),
						'value' => $s['accent_color'],
						'help'  => __( 'Pick a mid-tone. Very light or very dark values leave too little contrast in one of the two modes.', 'octave-addons' ),
					] );

				},
			] ); ?>
		</table>
		<?php

	}

}

return new Octave_Addons_Module_Modern_Admin();
