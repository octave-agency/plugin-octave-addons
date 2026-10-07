<?php

/*
PERFORMANCE: PAGE CACHE
-- Octave's own filesystem page cache, for sites that have no other. Steps
-- aside for Cloudways Varnish, a page-cache plugin or a managed host's
-- cache, so only one page cache ever runs. The work is done by
-- Octave_Addons_Perf_Disk_Cache
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once dirname( __DIR__ ) . '/services/bootstrap.php';

class Octave_Addons_Module_Performance_Page_Cache extends Octave_Addons_Module {

	use Octave_Addons_Perf_Field_Rows;

	/** Lifespans offered, in hours. */
	public const LIFESPANS = [ 2, 10, 24, 72 ];

	public function get_id(): string {

		return Octave_Addons_Perf_Disk_Cache::MODULE;

	}

	public function get_title(): string {

		return __( 'Page Cache', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Saves a ready-made copy of each page and shows it to visitors instantly, without building the page each time. Only used when your host or another plugin is not already doing this.', 'octave-addons' );

	}

	public function get_order(): int {

		return 5;

	}

	public function get_defaults(): array {

		return [
			'enabled'  => false,
			'lifespan' => 10,
		];

	}

	public function sanitize( $input ): array {

		$clean = parent::sanitize( $input );

		$lifespan          = absint( $input['lifespan'] ?? 10 );
		$clean['lifespan'] = in_array( $lifespan, self::LIFESPANS, true ) ? $lifespan : 10;

		return $clean;

	}

	public function run( array $s ): void {

		Octave_Addons_Perf_Disk_Cache::boot();

	}

	/*
	RUN DISABLED
	-- Takes Octave's drop-in and stored pages away once switched off
	---------------------------------------------------------- */

	public function run_disabled( array $s ): void {

		add_action( 'admin_init', [ 'Octave_Addons_Perf_Disk_Cache', 'maintain' ] );

	}

	/*
	RENDER SETTINGS
	---------------------------------------------------------- */

	public function render_settings( array $s ): void {

		$status = Octave_Addons_Perf_Disk_Cache::status();

		?>

		<p class="oa-help oa-help--intro"><?= esc_html( $status['message'] ); ?></p>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			$this->select_row( 'lifespan', __( 'Keep pages for', 'octave-addons' ), [
				2  => __( '2 hours', 'octave-addons' ),
				10 => __( '10 hours (recommended)', 'octave-addons' ),
				24 => __( '24 hours', 'octave-addons' ),
				72 => __( '3 days', 'octave-addons' ),
			], __( 'Saved pages are refreshed automatically whenever you change content, menus, designs or settings, so this is only the longest a page nobody edited is kept. Forms can stop working on pages kept for more than about 12 hours, so keep this shorter if your site relies on forms.', 'octave-addons' ), $s );

			?>
		</table>

		<p class="oa-help"><?php esc_html_e( 'Never saved: pages for logged-in people, baskets, checkouts and accounts, password-protected pages, previews and search results. For developers: the DONOTCACHEPAGE constant keeps a page out.', 'octave-addons' ); ?></p>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Page_Cache();
