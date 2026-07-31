<?php

/*
MODULE: BREAKDANCE CUSTOM ELEMENTS
-- Registers an Element Studio save location for client-specific Breakdance
-- elements. The elements/ folder is preserved across plugin updates.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Breakdance_Custom_Elements extends Octave_Addons_Module {


	/** Prevent duplicate registration if Breakdance is already loaded. */
	protected bool $has_registered = false;

	public function __construct() {

		// Register unconditionally — the save location must always be available
		// in Element Studio regardless of the admin toggle.
		add_action( 'breakdance_loaded', [ $this, 'register_breakdance_elements' ], 9 );
		if ( did_action( 'breakdance_loaded' ) ) {

			$this->register_breakdance_elements();

		}

	}

	public function get_id(): string {

		return 'breakdance-custom-elements';

	}

	public function get_defaults(): array {

		return [ 'enabled' => true ];

	}

	public function get_title(): string {

		return __( 'Breakdance Custom Elements', 'octave-addons' );

	}

	public function get_description(): string {
		return __( 'Registers a Breakdance Element Studio save location for custom elements. The elements folder is preserved when the plugin is updated.', 'octave-addons' );

	}

	public function show_in_admin(): bool {

		return false;

	}

	public function is_always_enabled(): bool {

		return true;

	}

	public function render_settings( array $settings ): void {

		?>

		<table class="form-table oa-form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Save location', 'octave-addons' ); ?></th>
				<td>
					<code>octave-addons/modules/breakdance-custom-elements/elements</code>
					<p class="description">
						<?php esc_html_e( 'Elements saved here appear under the "Octave Elements" category and are preserved when the plugin is updated.', 'octave-addons' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php

	}

	public function run( array $settings ): void {

		// Registration is handled unconditionally in __construct().

	}

	public function register_breakdance_elements(): void {

		if ( $this->has_registered ) {
			return;

		}

		if ( ! function_exists( '\Breakdance\ElementStudio\registerSaveLocation' ) || ! class_exists( '\Breakdance\Elements\ElementCategoriesController' ) ) {
			return;

		}

		// Ensure the elements directory exists so Breakdance can write new elements.
		$elements_dir = OCTAVE_ADDONS_DIR . 'modules/breakdance-custom-elements/elements';
		if ( ! is_dir( $elements_dir ) ) {

			wp_mkdir_p( $elements_dir );

		}

		foreach ( $this->get_save_locations() as $location ) {

			\Breakdance\ElementStudio\registerSaveLocation(
				$location,
				'OctaveCustomElements',
				'element',
				'Octave Elements',
				false
			);

		}

		\Breakdance\Elements\ElementCategoriesController::getInstance()->registerCategory(
			'oa_custom_elements',
			'Octave Elements'
		);

		$this->has_registered = true;

	}

	/**
	 * Keep a single save location so Element Studio shows one "Octave Elements"
	 * entry and saves client elements into the preserved module folder.
	 *
	 * @return string[]
	 */
	protected function get_save_locations(): array {

		return [ 'octave-addons/modules/breakdance-custom-elements/elements' ];

	}

}

return new Octave_Addons_Module_Breakdance_Custom_Elements();
