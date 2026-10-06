<?php

/*
PERFORMANCE: THIRD-PARTY SCRIPT DELAY
-- Holds back the third-party scripts an administrator selects (analytics,
-- pixels, chat and review widgets, embeds) until the visitor first
-- interacts, so they stop competing with the page's own content on load
-- Allowlist only: nothing is delayed until a service is selected or an
-- include pattern is added. Breakdance, Octave, WordPress core, jQuery,
-- consent, payment, form and CAPTCHA scripts are always protected
-- If the page cannot be processed it is served unchanged
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once dirname( __DIR__ ) . '/services/bootstrap.php';
require_once __DIR__ . '/class-script-delayer.php';

class Octave_Addons_Module_Performance_Delay extends Octave_Addons_Module {

	use Octave_Addons_Perf_Field_Rows;

	protected const NOTE_LIMIT = 200;

	protected array $settings = [];

	public function get_id(): string {

		return 'performance-delay';

	}

	public function get_title(): string {

		return __( 'Script Delay', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Loads the analytics, pixels and widgets you select after the first scroll, tap, click or key press instead of during page load.', 'octave-addons' );

	}

	public function get_order(): int {

		return 20;

	}

	public function get_defaults(): array {

		return [
			'enabled'  => false,
			'services' => [],
			'include'  => '',
			'exclude'  => '',
		];

	}

	public function sanitize( $input ): array {

		$clean    = parent::sanitize( $input );
		$services = is_array( $input['services'] ?? null ) ? array_map( 'sanitize_key', $input['services'] ) : [];

		$clean['services'] = array_values( array_intersect( $services, array_keys( Octave_Addons_Perf_Script_Delayer::services() ) ) );
		$clean['include']  = Octave_Addons_Perf::sanitize_lines( $input['include'] ?? '' );
		$clean['exclude']  = Octave_Addons_Perf::sanitize_lines( $input['exclude'] ?? '' );

		return $clean;

	}

	public function run( array $s ): void {

		$this->settings = $s;

		if ( empty( $s['services'] ) && '' === trim( (string) $s['include'] ) ) {

			return;

		}

		Octave_Addons_Perf_Html::register( 'delay', [ $this, 'transform' ], 30, static function (): bool {

			return '' === Octave_Addons_Perf::handled_elsewhere( 'delay' );

		} );

	}

	/*
	TRANSFORM
	-- Rewrites selected scripts and adds the loader before </body>. Needs the
	-- HTML API's script text access (WordPress 6.5) to match inline snippets
	---------------------------------------------------------- */

	public function transform( string $html ): string {

		if ( ! Octave_Addons_Perf::has_html_api() || '' !== Octave_Addons_Perf::handled_elsewhere( 'delay' ) ) {

			return $html;

		}

		$result = Octave_Addons_Perf_Script_Delayer::process( $html, [
			'services' => $this->settings['services'],
			'include'  => Octave_Addons_Perf::lines( $this->settings['include'] ),
			'exclude'  => Octave_Addons_Perf::lines( $this->settings['exclude'] ),
		] );

		foreach ( array_slice( $result['decisions'], 0, self::NOTE_LIMIT ) as $decision ) {

			Octave_Addons_Perf_Log::note( 'delay', $decision );

		}

		if ( 0 === $result['delayed'] ) {

			return $html;

		}

		$loader = sprintf(
			'<script src="%s" defer data-oa-no-delay id="oa-delay-loader"></script>',
			esc_url( add_query_arg( 'ver', Octave_Addons_Perf::asset_version( 'delay.js' ), Octave_Addons_Perf::asset_url( 'delay.js' ) ) )
		);

		return Octave_Addons_Perf_Html::inject_before_body_end( $result['html'], $loader );

	}

	/*
	RENDER SETTINGS
	---------------------------------------------------------- */

	public function render_settings( array $s ): void {

		$owner      = Octave_Addons_Perf::handled_elsewhere( 'delay' );
		$categories = [
			'analytics'  => __( 'Analytics', 'octave-addons' ),
			'marketing'  => __( 'Marketing pixels', 'octave-addons' ),
			'widgets'    => __( 'Widgets and embeds', 'octave-addons' ),
			'contextual' => __( 'Contextual', 'octave-addons' ),
		];
		$services   = Octave_Addons_Perf_Script_Delayer::services();

		if ( '' !== $owner ) :

		?>

		<div class="notice notice-warning inline oa-inline-notice">
			<p>
				<?php

				printf(
					/* translators: %s: plugin name. */
					esc_html__( '%s is already delaying JavaScript, so Octave leaves scripts alone to avoid delaying them twice.', 'octave-addons' ),
					esc_html( $owner )
				);

				?>
			</p>
		</div>

		<?php

		endif;

		?>

		<p class="oa-help oa-help--intro"><?php esc_html_e( 'Delayed tools start after the visitor first scrolls, taps, clicks or presses a key. Analytics may record fewer visits from people who leave without interacting.', 'octave-addons' ); ?></p>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			Octave_Addons_Fields::section( [ 'label' => __( 'Services to delay', 'octave-addons' ), 'first' => true ] );

			foreach ( $categories as $category => $category_label ) {

				Octave_Addons_Fields::row( [
					'label' => $category_label,
					'field' => function () use ( $category, $services, $s ) {

						?>

						<fieldset class="oa-perf-checklist" data-oa-perf-select-group>
							<label class="oa-perf-select-all">
								<input type="checkbox" data-oa-perf-select-all>
								<?php esc_html_e( 'Select all', 'octave-addons' ); ?>
							</label>
							<?php

							foreach ( $services as $id => $service ) :

								if ( $category !== ( $service['category'] ?? '' ) ) {

									continue;

								}

							?>

							<label>
								<input type="checkbox" name="<?= esc_attr( $this->field_name( 'services' ) ); ?>[]" value="<?= esc_attr( (string) $id ); ?>"<?php checked( in_array( $id, (array) $s['services'], true ) ); ?>>
								<?= esc_html( (string) $service['label'] ); ?>
							</label>

							<?php

							endforeach;

							?>
						</fieldset>

						<?php

						if ( 'contextual' === $category ) :

						?>

						<span class="oa-help"><?php esc_html_e( 'Test maps and every form after enabling these. CAPTCHA scripts are otherwise never delayed, and a form plugin that expects its CAPTCHA immediately may need it excluded.', 'octave-addons' ); ?></span>

						<?php

						endif;

					},
				] );

			}

			Octave_Addons_Fields::section( [ 'label' => __( 'Fine-tuning', 'octave-addons' ) ] );

			$this->textarea_row( 'include', __( 'Also delay', 'octave-addons' ), __( 'Advanced. One pattern per line, matched against script URLs, ids and inline code. This is the only way a script from your own domain is delayed. Protected scripts are never delayed.', 'octave-addons' ), $s );
			$this->textarea_row( 'exclude', __( 'Never delay', 'octave-addons' ), __( 'One pattern per line. A script can also opt out with the data-oa-no-delay attribute.', 'octave-addons' ), $s );

			?>
		</table>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Delay();
