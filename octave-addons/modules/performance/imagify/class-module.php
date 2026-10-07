<?php

/*
PERFORMANCE: IMAGIFY NEXT-GENERATION IMAGES
-- Chooses Imagify's next-generation format (WebP or AVIF) and how it is
-- delivered (Imagify's rewrite rules or picture tags, or Octave's own URL
-- rewriting) from the Performance page, and shows whether that delivery
-- actually works on this server
-- Imagify stays the owner of everything it does: it generates the files,
-- stores the settings and writes its own rewrite rules. Changes go through
-- the Octave_Addons_Perf_Imagify adapter, which runs Imagify's own settings
-- lifecycle. Octave never generates images or writes image rewrite rules
-- Shown only while Imagify and the APIs the adapter relies on are present
-- The format defaults to Keep, so Imagify's choice of WebP or AVIF stands
-- until one is picked here. Delivery defaults to Automatic: Imagify's
-- rewrite rules where the server can use them, otherwise Octave's URL
-- rewriting
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
	protected const DELIVERIES = [ 'keep', 'auto', 'rewrite', 'picture', 'octave' ];

	public function get_id(): string {

		return 'performance-imagify';

	}

	public function get_title(): string {

		return __( 'Imagify Setup', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Sets up Imagify to serve your images in modern, smaller formats (WebP or AVIF), and checks visitors actually receive them.', 'octave-addons' );

	}

	public function get_order(): int {

		return 15;

	}

	public function get_defaults(): array {

		return [
			'enabled'  => false,
			'format'   => 'keep',
			'delivery' => 'auto',
		];

	}

	public function show_in_admin(): bool {

		return Octave_Addons_Perf_Imagify::available();

	}

	public function sanitize( $input ): array {

		$clean    = parent::sanitize( $input );
		$format   = sanitize_key( $input['format'] ?? 'keep' );
		$delivery = sanitize_key( $input['delivery'] ?? 'auto' );

		$clean['format']   = in_array( $format, self::FORMATS, true ) ? $format : 'keep';
		$clean['delivery'] = in_array( $delivery, self::DELIVERIES, true ) ? $delivery : 'auto';

		return $clean;

	}

	/*
	RUN
	-- The listeners are needed whether or not the module is on, since the
	-- save that switches it on happens while it is still off
	---------------------------------------------------------- */

	public function run( array $s ): void {

		self::listen();
		Octave_Addons_Perf_Nextgen::boot();

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

		<p class="oa-help oa-help--intro"><?php esc_html_e( 'Imagify makes the smaller copies of your images. These settings choose which format it makes and how those copies reach visitors.', 'octave-addons' ); ?></p>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			Octave_Addons_Fields::section( [ 'label' => __( 'Modern image formats', 'octave-addons' ), 'first' => true ] );

			$this->select_row( 'format', __( 'Format', 'octave-addons' ), $formats, __( 'AVIF images are smaller than WebP, and every current browser shows both. If you change this, Imagify makes the new copies as it optimises your images again.', 'octave-addons' ), $s );

			$this->select_row( 'delivery', __( 'How they reach visitors', 'octave-addons' ), [
				'keep'    => __( 'Keep Imagify\'s setting', 'octave-addons' ),
				'auto'    => __( 'Automatic (recommended)', 'octave-addons' ),
				'rewrite' => __( 'Server rules (Imagify)', 'octave-addons' ),
				'picture' => __( 'Picture tags (changes image markup)', 'octave-addons' ),
				'octave'  => __( 'Octave Addons Rewriting', 'octave-addons' ),
			], __( 'Automatic is best for most sites: it uses Imagify\'s server rules where your server supports them, and Octave Addons Rewriting everywhere else, such as on Cloudways. Octave Addons Rewriting points your pages straight at the smaller copies, including page-builder background images, without changing your layout. Picture tags also work everywhere, but wrap every image in extra markup, which can affect layouts. Very old browsers (Safari before version 16.4) cannot show AVIF images.', 'octave-addons' ), $s );

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
			__( 'Format', 'octave-addons' )           => 'off' === $format ? __( 'Off', 'octave-addons' ) : strtoupper( $format ),
			__( 'Automatic optimisation', 'octave-addons' )  => ! empty( $config['auto_optimize'] ) ? $yes : $no,
			__( 'How they reach visitors', 'octave-addons' )                => Octave_Addons_Perf_Imagify::octave_delivery() ? __( 'Octave Addons Rewriting', 'octave-addons' ) : ( $display ? ( 'rewrite' === $method ? __( 'Server rules (Imagify)', 'octave-addons' ) : __( 'Picture tags (changes image markup)', 'octave-addons' ) ) : __( 'Not in use', 'octave-addons' ) ),
			__( 'Web server', 'octave-addons' )              => ucfirst( $server ),
			__( 'Server rules file (.htaccess)', 'octave-addons' )               => Octave_Addons_Perf_Imagify::reads_htaccess() ? ( $htaccess['exists'] ? ( $htaccess['writable'] ? __( 'Exists, writable', 'octave-addons' ) : __( 'Exists, not writable', 'octave-addons' ) ) : ( $htaccess['writable'] ? __( 'Missing, can be created', 'octave-addons' ) : __( 'Missing, cannot be created', 'octave-addons' ) ) ) : __( 'Not used by this server', 'octave-addons' ),
			__( 'Imagify\'s server rules', 'octave-addons' )    => empty( $expected ) ? implode( ', ', $markers ) . ' ' . __( '(not needed with the current setting)', 'octave-addons' ) : implode( ', ', $markers ),
			__( 'CDN and Cloudflare', 'octave-addons' )      => '' !== $cdn || Octave_Addons_Perf_Imagify::behind_cloudflare() ? __( 'Found: server rules could send the wrong format to some browsers', 'octave-addons' ) : __( 'None detected', 'octave-addons' ),
			__( 'Automatic choice', 'octave-addons' )        => ( 'rewrite' === $choice['method'] ? __( 'Server rules (Imagify)', 'octave-addons' ) : __( 'Octave Addons Rewriting', 'octave-addons' ) ) . ( empty( $choice['reasons'] ) ? '' : ' — ' . implode( ' ', $choice['reasons'] ) ),
			__( 'Last applied', 'octave-addons' )    => self::describe( $status['sync'] ?? [] ),
			__( 'Last check', 'octave-addons' )      => self::describe( $status['test'] ?? [] ),
		];

		if ( ! empty( $htaccess['competing'] ) ) {

			$rows[ __( 'Other plugins', 'octave-addons' ) ] = sprintf( /* translators: %s: plugin names. */ __( '%s also switches image formats. Use just one plugin for this.', 'octave-addons' ), implode( ', ', $htaccess['competing'] ) );

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
				<button type="button" class="button" data-oa-perf-action="oa_perf_imagify_sync" data-result="oa-perf-imagify-result"><?php esc_html_e( 'Reapply Imagify settings', 'octave-addons' ); ?></button>
				<button type="button" class="button" data-oa-perf-action="oa_perf_imagify_test" data-result="oa-perf-imagify-result"><?php esc_html_e( 'Check images are served in the new format', 'octave-addons' ); ?></button>
			</div>
			<div id="oa-perf-imagify-result" data-oa-perf-result role="status" aria-live="polite"></div>

			<?php

			if ( 'nginx' === $server && $display && 'rewrite' === $method ) :

				$nginx = Octave_Addons_Perf_Imagify::nginx_rules();

			?>

			<div class="notice notice-warning inline oa-inline-notice">
				<p><?php esc_html_e( 'This server (Nginx) cannot use Imagify\'s server rules on its own. Either choose Automatic or Octave Addons Rewriting above, or ask your host to add the rules below to the server.', 'octave-addons' ); ?></p>
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
