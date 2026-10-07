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

		return __( 'Gives Breakdance switches, such as the Content Toggle, a proper label, so screen readers and AI assistants can tell what each one does.', 'octave-addons' );

	}

	/*
	GET REQUIRES
	-- It repairs Breakdance controls only
	---------------------------------------------------------- */

	public function get_requires(): ?array {

		return [ Octave_Addons_Builders::BREAKDANCE ];

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
			<li><?php esc_html_e( 'Content Toggle: a Monthly / Yearly toggle is read out as "Yearly, switch, off" instead of an unlabelled checkbox.', 'octave-addons' ); ?></li>
			<li><?php esc_html_e( 'Anything that is already labelled is left alone, and toggles that appear later, such as in popups, are fixed too.', 'octave-addons' ); ?></li>
		</ul>

		<?php

	}

}

return new Octave_Addons_Module_Accessibility_Tree();
