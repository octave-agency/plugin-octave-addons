<?php

/*
PERFORMANCE: CACHE & SAFETY
-- Headline module of the Performance page. Always on, because it only
-- manages the plugin's own cache directory and the purge coordination other
-- layers hook into, and it holds the settings every Performance feature
-- shares: whether logged-in visitors are optimised, and the admin bar shortcut
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once dirname( __DIR__ ) . '/services/bootstrap.php';

class Octave_Addons_Module_Performance_Cache extends Octave_Addons_Module {

	public function get_id(): string {

		return 'performance-cache';

	}

	public function get_title(): string {

		return __( 'Cache & Safety', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Coordinates cache purges across the minified files and any connected layers, and sets who receives optimised pages.', 'octave-addons' );

	}

	public function get_order(): int {

		return 0;

	}

	public function is_always_enabled(): bool {

		return true;

	}

	/*
	GET DEFAULTS
	-- Logged-in visitors see unoptimised pages, so editors and shop staff are
	-- never the first to meet a compatibility problem
	---------------------------------------------------------- */

	public function get_defaults(): array {

		return [
			'enabled'            => true,
			'admin_bar'          => true,
			'optimize_logged_in' => false,
		];

	}

	public function sanitize( $input ): array {

		$clean = parent::sanitize( $input );

		$clean['admin_bar']          = ! empty( $input['admin_bar'] );
		$clean['optimize_logged_in'] = ! empty( $input['optimize_logged_in'] );

		return $clean;

	}

	public function run( array $s ): void {

		Octave_Addons_Perf_Cache::register_invalidation();

	}

	public function render_settings( array $s ): void {

		?>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			Octave_Addons_Fields::row( [
				'label' => __( 'Admin bar shortcut', 'octave-addons' ),
				'for'   => $this->field_id( 'admin_bar' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'name'    => $this->field_name( 'admin_bar' ),
						'id'      => $this->field_id( 'admin_bar' ),
						'checked' => $s['admin_bar'],
						'help'    => __( 'Adds a Performance menu to the admin bar for administrators, with Clear Performance Cache and Purge this URL.', 'octave-addons' ),
					] );

				},
			] );

			Octave_Addons_Fields::row( [
				'label' => __( 'Optimise for logged-in users', 'octave-addons' ),
				'for'   => $this->field_id( 'optimize_logged_in' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'name'    => $this->field_name( 'optimize_logged_in' ),
						'id'      => $this->field_id( 'optimize_logged_in' ),
						'checked' => $s['optimize_logged_in'],
						'help'    => __( 'Advanced. Off by default: logged-in users, including editors and customers in their account, see pages exactly as WordPress renders them. Turn on only to test optimisations while logged in.', 'octave-addons' ),
					] );

				},
			] );

			?>
		</table>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Cache();
