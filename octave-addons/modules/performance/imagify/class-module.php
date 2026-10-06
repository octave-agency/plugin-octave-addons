<?php

/*
PERFORMANCE: IMAGIFY NEXT-GENERATION IMAGES
-- Chooses Imagify's next-generation format (WebP or AVIF) and how Imagify
-- delivers it (rewrite rules or picture tags) from the Performance page,
-- and shows whether that delivery actually works on this server
-- Imagify stays the owner of everything it does: it generates the files,
-- stores the settings and writes its own rewrite rules. Changes go through
-- the Octave_Addons_Perf_Imagify adapter, which runs Imagify's own settings
-- lifecycle. Octave never generates images or writes image rewrite rules
-- Shown only while Imagify and the APIs the adapter relies on are present
-- Both settings default to Keep, so switching the module on changes nothing
-- in Imagify until a value is chosen
-- Imagify is only touched when these settings change, when Imagify is
-- activated or updated and its expected rules are missing, or when an
-- administrator asks; never during a page view
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once dirname( __DIR__ ) . '/services/bootstrap.php';

class Octave_Addons_Module_Performance_Imagify extends Octave_Addons_Module {

	use Octave_Addons_Perf_Field_Rows;

	protected const FORMATS    = [ 'keep', 'off', 'webp', 'avif' ];
	protected const DELIVERIES = [ 'keep', 'auto', 'rewrite', 'picture' ];

	public function get_id(): string {

		return 'performance-imagify';

	}

	public function get_title(): string {

		return __( 'Imagify Next-Gen Images', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Chooses the WebP or AVIF format and delivery method Imagify uses, and tests that browsers receive it.', 'octave-addons' );

	}

	public function get_order(): int {

		return 15;

	}

	public function get_defaults(): array {

		return [
			'enabled'  => false,
			'format'   => 'keep',
			'delivery' => 'keep',
		];

	}

	public function show_in_admin(): bool {

		return Octave_Addons_Perf_Imagify::available();

	}

	public function sanitize( $input ): array {

		$clean    = parent::sanitize( $input );
		$format   = sanitize_key( $input['format'] ?? 'keep' );
		$delivery = sanitize_key( $input['delivery'] ?? 'keep' );

		$clean['format']   = in_array( $format, self::FORMATS, true ) ? $format : 'keep';
		$clean['delivery'] = in_array( $delivery, self::DELIVERIES, true ) ? $delivery : 'keep';

		return $clean;

	}

	/*
	RUN
	-- The listeners are needed whether or not the module is on, since the
	-- save that switches it on happens while it is still off
	---------------------------------------------------------- */

	public function run( array $s ): void {

		self::listen();

	}

	public function run_disabled( array $s ): void {

		self::listen();

	}

	public static function listen(): void {

		if ( ! Octave_Addons_Perf_Imagify::is_active() ) {

			return;

		}

		add_action( 'update_option_' . OCTAVE_ADDONS_OPTION_KEY, [ __CLASS__, 'on_settings_update' ], 20, 2 );
		add_action( 'activated_plugin', [ __CLASS__, 'on_plugin_activated' ] );
		add_action( 'admin_init', [ 'Octave_Addons_Perf_Imagify', 'maybe_schedule_verify' ] );
		add_action( Octave_Addons_Perf_Imagify::VERIFY_HOOK, [ 'Octave_Addons_Perf_Imagify', 'verify' ] );

	}

	/*
	ON SETTINGS UPDATE
	-- Synchronises only when this module's own settings changed
	---------------------------------------------------------- */

	public static function on_settings_update( $old, $new ): void {

		$before = is_array( $old['performance-imagify'] ?? null ) ? $old['performance-imagify'] : [];
		$after  = is_array( $new['performance-imagify'] ?? null ) ? $new['performance-imagify'] : [];

		if ( $before === $after || empty( $after['enabled'] ) ) {

			return;

		}

		Octave_Addons_Perf_Imagify::apply( Octave_Addons_Perf_Imagify::desired( $after ), 'settings' );

	}

	/*
	ON PLUGIN ACTIVATED
	-- Forgets the recorded Imagify version, so the next admin page checks
	-- its rules in the background once Imagify has finished activating
	---------------------------------------------------------- */

	public static function on_plugin_activated( $plugin ): void {

		if ( false !== strpos( (string) $plugin, 'imagify' ) ) {

			delete_option( Octave_Addons_Perf_Imagify::VERSION_OPTION );

		}

	}

	/*
	SYNC NOW
	-- The Repair/synchronize action: applies the chosen settings, and when
	-- Imagify already matches them, asks it to restore any missing rules
	---------------------------------------------------------- */

	public static function sync_now(): array {

		$s = Octave_Addons_Perf::settings( 'performance-imagify' );

		if ( ! empty( $s['enabled'] ) ) {

			$result = Octave_Addons_Perf_Imagify::apply( Octave_Addons_Perf_Imagify::desired( $s ), 'manual' );

			if ( ! $result['ok'] || $result['changed'] ) {

				return $result;

			}

		}

		return Octave_Addons_Perf_Imagify::repair( 'manual' );

	}

	/*
	RENDER SETTINGS
	---------------------------------------------------------- */

	public function render_settings( array $s ): void {

		$formats = [
			'keep' => __( 'Keep Imagify\'s setting', 'octave-addons' ),
			'off'  => __( 'Off', 'octave-addons' ),
			'webp' => 'WebP',
		];

		if ( Octave_Addons_Perf_Imagify::supports_avif() ) {

			$formats['avif'] = 'AVIF';

		}

		?>

		<p class="oa-help oa-help--intro"><?php esc_html_e( 'Imagify creates the WebP and AVIF files and writes its own rewrite rules. These settings only choose what Imagify does; nothing here generates images or edits .htaccess itself.', 'octave-addons' ); ?></p>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			Octave_Addons_Fields::section( [ 'label' => __( 'Next-generation images', 'octave-addons' ), 'first' => true ] );

			$this->select_row( 'format', __( 'Output format', 'octave-addons' ), $formats, __( 'AVIF files are smaller than WebP; every current browser supports both. Changing the format makes Imagify create the new files as images are optimised again.', 'octave-addons' ), $s );

			$this->select_row( 'delivery', __( 'Delivery', 'octave-addons' ), [
				'keep'    => __( 'Keep Imagify\'s setting', 'octave-addons' ),
				'auto'    => __( 'Automatic (recommended)', 'octave-addons' ),
				'rewrite' => __( 'Rewrite rules', 'octave-addons' ),
				'picture' => __( 'Picture tags', 'octave-addons' ),
			], __( 'Rewrite rules keep image URLs unchanged and need Apache or LiteSpeed with a writable .htaccess and no CDN in front of images. Picture tags work on every server. Automatic picks rewrite rules only when all of that holds.', 'octave-addons' ), $s );

			?>
		</table>

		<?php

		self::render_status();

	}

	/*
	RENDER STATUS
	-- Everything that decides whether next-generation delivery works here
	---------------------------------------------------------- */

	public static function render_status(): void {

		$config   = Octave_Addons_Perf_Imagify::config();
		$server   = Octave_Addons_Perf_Imagify::server();
		$htaccess = Octave_Addons_Perf_Imagify::htaccess();
		$expected = Octave_Addons_Perf_Imagify::expected_markers( $config );
		$choice   = Octave_Addons_Perf_Imagify::choose_method();
		$status   = Octave_Addons_Perf_Imagify::status();
		$cdn      = Octave_Addons_Perf_Imagify::cdn();
		$format   = (string) ( $config['optimization_format'] ?? 'off' );
		$display  = ! empty( $config['display_nextgen'] );
		$method   = (string) ( $config['display_nextgen_method'] ?? 'picture' );
		$yes      = __( 'Yes', 'octave-addons' );
		$no       = __( 'No', 'octave-addons' );

		$markers = [];

		foreach ( $htaccess['markers'] as $marker_format => $present ) {

			$markers[] = strtoupper( $marker_format ) . ': ' . ( $present ? __( 'present', 'octave-addons' ) : __( 'missing', 'octave-addons' ) );

		}

		$rows = [
			__( 'Imagify', 'octave-addons' )                 => sprintf( /* translators: %s: version. */ __( 'Active, version %s', 'octave-addons' ), Octave_Addons_Perf_Imagify::version() ),
			__( 'Output format', 'octave-addons' )           => 'off' === $format ? __( 'Off', 'octave-addons' ) : strtoupper( $format ),
			__( 'Automatic optimisation', 'octave-addons' )  => ! empty( $config['auto_optimize'] ) ? $yes : $no,
			__( 'Delivery', 'octave-addons' )                => $display ? ( 'rewrite' === $method ? __( 'Rewrite rules', 'octave-addons' ) : __( 'Picture tags', 'octave-addons' ) ) : __( 'Not displayed', 'octave-addons' ),
			__( 'Web server', 'octave-addons' )              => ucfirst( $server ),
			__( '.htaccess', 'octave-addons' )               => Octave_Addons_Perf_Imagify::reads_htaccess() ? ( $htaccess['exists'] ? ( $htaccess['writable'] ? __( 'Exists, writable', 'octave-addons' ) : __( 'Exists, not writable', 'octave-addons' ) ) : ( $htaccess['writable'] ? __( 'Missing, can be created', 'octave-addons' ) : __( 'Missing, cannot be created', 'octave-addons' ) ) ) : __( 'Not read by this server', 'octave-addons' ),
			__( 'Imagify rule markers', 'octave-addons' )    => empty( $expected ) ? implode( ', ', $markers ) . ' ' . __( '(not needed for the current delivery)', 'octave-addons' ) : implode( ', ', $markers ),
			__( 'CDN and Cloudflare', 'octave-addons' )      => '' !== $cdn || Octave_Addons_Perf_Imagify::behind_cloudflare() ? __( 'Present: rewrite rules could mix formats between browsers', 'octave-addons' ) : __( 'None detected', 'octave-addons' ),
			__( 'Automatic choice', 'octave-addons' )        => ( 'rewrite' === $choice['method'] ? __( 'Rewrite rules', 'octave-addons' ) : __( 'Picture tags', 'octave-addons' ) ) . ( empty( $choice['reasons'] ) ? '' : ' — ' . implode( ' ', $choice['reasons'] ) ),
			__( 'Last synchronisation', 'octave-addons' )    => self::describe( $status['sync'] ?? [] ),
			__( 'Last delivery test', 'octave-addons' )      => self::describe( $status['test'] ?? [] ),
		];

		if ( ! empty( $htaccess['competing'] ) ) {

			$rows[ __( 'Competing rules', 'octave-addons' ) ] = sprintf( /* translators: %s: plugin names. */ __( '%s also rewrites image formats in .htaccess. Use one plugin for next-generation delivery.', 'octave-addons' ), implode( ', ', $htaccess['competing'] ) );

		}

		?>

		<section class="oa-perf-section">
			<div class="oa-perf-section-copy">
				<h3><?php esc_html_e( 'Imagify status', 'octave-addons' ); ?></h3>
			</div>
			<table class="widefat striped oa-perf-table">
				<tbody>
					<?php

					foreach ( $rows as $label => $value ) :

					?>

					<tr>
						<th scope="row"><?= esc_html( $label ); ?></th>
						<td><?= nl2br( esc_html( (string) $value ) ); ?></td>
					</tr>

					<?php

					endforeach;

					?>
				</tbody>
			</table>
			<div class="oa-perf-actions">
				<button type="button" class="button" data-oa-perf-action="oa_perf_imagify_sync" data-result="oa-perf-imagify-result"><?php esc_html_e( 'Repair/synchronize Imagify rules', 'octave-addons' ); ?></button>
				<button type="button" class="button" data-oa-perf-action="oa_perf_imagify_test" data-result="oa-perf-imagify-result"><?php esc_html_e( 'Test next-generation delivery', 'octave-addons' ); ?></button>
			</div>
			<div id="oa-perf-imagify-result" data-oa-perf-result role="status" aria-live="polite"></div>

			<?php

			if ( 'nginx' === $server && $display && 'rewrite' === $method ) :

				$nginx = Octave_Addons_Perf_Imagify::nginx_rules();

			?>

			<div class="notice notice-warning inline oa-inline-notice">
				<p><?php esc_html_e( 'Nginx does not read .htaccess, so nothing was written for it. Include the configuration Imagify generated in this site\'s server block and reload Nginx.', 'octave-addons' ); ?></p>
			</div>

			<?php

			foreach ( $nginx as $rules ) :

			?>

			<p><code><?= esc_html( $rules['path'] ); ?></code></p>
			<pre class="oa-perf-code"><?= esc_html( $rules['rules'] ); ?></pre>

			<?php

			endforeach;

			endif;

			?>
		</section>

		<?php

	}

	/*
	DESCRIBE
	-- One line for a recorded result
	---------------------------------------------------------- */

	protected static function describe( array $result ): string {

		if ( empty( $result['time'] ) ) {

			return __( 'Not run yet', 'octave-addons' );

		}

		$state = ! empty( $result['ok'] ) ? __( 'OK', 'octave-addons' ) : __( 'Failed', 'octave-addons' );

		return $state . ', ' . Octave_Addons_Perf_Admin::time_ago( (int) $result['time'] ) . "\n" . (string) ( $result['message'] ?? '' );

	}

}

return new Octave_Addons_Module_Performance_Imagify();
