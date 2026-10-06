<?php

/*
MODULE: ACCESSIBILITY TREE REPAIRS
-- Fills gaps Breakdance leaves in the accessibility tree, so browser agents
-- and assistive technology both get a control they can name and operate.
-- Repairs run in the browser from one small script. They only ever add a
-- missing name or role, never replace one, and Breakdance's own markup and
-- behaviour are left untouched.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Accessibility_Tree extends Octave_Addons_Module {


	public function get_id(): string {

		return 'accessibility-tree';

	}

	public function get_title(): string {

		return __( 'Accessibility Tree Repairs', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Gives unlabelled Breakdance controls, such as Content Toggle switches, a proper name and role, so browser agents can navigate them reliably and screen reader users hear what each control does.', 'octave-addons' );

	}

	public function get_order(): int {

		return 40;

	}

	/*
	GET DEFAULTS
	-- On by default like the rest of the area. A repair only adds what is
	-- missing, so it cannot take anything away from a page that is already
	-- accessible.
	---------------------------------------------------------- */

	public function get_defaults(): array {

		return [ 'enabled' => true ];

	}

	public function run( array $settings ): void {

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

	}

	/*
	ENQUEUE ASSETS
	-- Frontend only, and never inside the builder, where Breakdance owns the
	-- markup being edited.
	---------------------------------------------------------- */

	public function enqueue_assets(): void {

		if ( self::is_builder_request() ) {

			return;

		}

		$asset = self::asset( 'modules/ai-agents/accessibility-tree/assets/accessibility-tree.js' );

		wp_enqueue_script( 'octave-accessibility-tree', $asset['url'], [], $asset['version'], true );

	}

	public function render_settings( array $settings ): void {

		?>

		<ul class="oa-help">
			<li><?php esc_html_e( 'Content Toggle: the checkbox is named by the visible label for its on state and exposed as a switch, so a Monthly / Yearly toggle reads as "Yearly, switch, off".', 'octave-addons' ); ?></li>
			<li><?php esc_html_e( 'Controls that already have a name are left exactly as they are, and toggles added later by popups or AJAX are repaired as they appear.', 'octave-addons' ); ?></li>
		</ul>

		<?php

	}

}

return new Octave_Addons_Module_Accessibility_Tree();
