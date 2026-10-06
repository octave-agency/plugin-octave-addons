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

		return __( 'Stores finished pages as files and serves them to visitors before WordPress loads. Used only when the host or another plugin is not already caching pages.', 'octave-addons' );

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
			], __( 'Pages are also cleared whenever content, menus, Breakdance designs or Performance settings change, so this only limits how old a page that nobody edited can get. Forms and other features that use security tokens expire after about 12 hours, so keep this shorter than that on sites that rely on them.', 'octave-addons' ), $s );

			?>
		</table>

		<p class="oa-help"><?php esc_html_e( 'Never cached: logged-in visitors, carts, checkouts and accounts, password-protected pages, previews, searches, URLs with query strings and any response that sets cookies or asks not to be cached. Code can keep a page out with the DONOTCACHEPAGE constant.', 'octave-addons' ); ?></p>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Page_Cache();
