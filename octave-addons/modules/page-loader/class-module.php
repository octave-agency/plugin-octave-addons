<?php

/*
MODULE: PAGE TRANSITIONS
-- Two independent systems sharing one colour palette:
-- An initial page loader shown on a cold, full page load, rendered early
-- through wp_body_open and removed on real readiness signals
-- Internal page transitions that cover the page on same-origin link clicks,
-- navigate normally, and reveal the destination
-- Every loader timing derives from one base value, the Loader duration
-- A core script that custom code cannot replace owns the lifecycle, the
-- hard timeouts and the scroll lock, and a CSS failsafe releases the page
-- even if that script never runs
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Page_Loader extends Octave_Addons_Module {

	/**
	 * Asset folder relative to the plugin root.
	 */
	protected const ASSETS = 'modules/page-loader/assets/';

	/**
	 * How long each transition takes to cover the page, in ms. Navigation
	 * starts as soon as this has passed.
	 */
	protected const COVER_MS = [
		'slide-up'       => 420,
		'curve-rise'     => 560,
		'circle-reveal'  => 520,
		'column-stagger' => 580,
		'diagonal-sweep' => 520,
		'split-curtain'  => 520,
		'brand-wipe'     => 460,
		'soft-fade'      => 320,
		'custom'         => 400,
	];

	/**
	 * How long each transition takes to reveal the destination, in ms.
	 */
	protected const REVEAL_MS = [
		'slide-up'       => 620,
		'curve-rise'     => 760,
		'circle-reveal'  => 760,
		'column-stagger' => 720,
		'diagonal-sweep' => 700,
		'split-curtain'  => 740,
		'brand-wipe'     => 720,
		'soft-fade'      => 460,
		'replay-loader'  => 0,
		'custom'         => 600,
	];

	/**
	 * Strips in the Column Stagger transition.
	 */
	protected const COLUMNS = 5;

	/**
	 * Whether the overlays were printed through wp_body_open.
	 */
	protected $rendered = false;

	public function get_id(): string {

		return 'page-loader';

	}

	public function get_title(): string {

		return __( 'Page Transitions', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'An initial loader for full page loads and quick, separate transitions between internal pages.', 'octave-addons' );

	}

	public function get_defaults(): array {

		return [
			'enabled'                => false,

			'loader_enabled'         => false,
			'loader_type'            => 'brand-counter',
			'loader_frequency'       => 'session',
			'loader_duration'        => 900,
			'loader_text'            => '',
			'loader_show_progress'   => true,
			'loader_logo'            => 0,
			'loader_image'           => 0,
			'loader_css'             => '',
			'loader_js'              => '',

			'transitions_enabled'    => false,
			'transition_type'        => 'slide-up',
			'transition_loader_type' => 'match',
			'transition_css'         => '',
			'transition_js'          => '',

			'accent_source'          => 'brand',
			'accent_color'           => '#3B82F6',
			'background_source'      => 'custom',
			'background_color'       => '#0E0E0F',
			'text_source'            => 'custom',
			'text_color'             => '#F4F2EE',
		];

	}

	/*
	LOADER TYPES
	-- Initial-loader presets, in dropdown order
	---------------------------------------------------------- */

	protected function loader_types(): array {

		return [
			'brand-counter' => __( 'Brand Counter', 'octave-addons' ),
			'logo-mask'     => __( 'Logo Mask', 'octave-addons' ),
			'image-window'  => __( 'Image Window', 'octave-addons' ),
			'curtain'       => __( 'Curtain Reveal', 'octave-addons' ),
			'orbital'       => __( 'Orbital Signal', 'octave-addons' ),
			'custom'        => __( 'Custom only', 'octave-addons' ),
		];

	}

	/*
	TRANSITION TYPES
	-- Internal page-transition presets, in dropdown order
	---------------------------------------------------------- */

	protected function transition_types(): array {

		return [
			'slide-up'       => __( 'Slide Up', 'octave-addons' ),
			'curve-rise'     => __( 'Curve Rise', 'octave-addons' ),
			'circle-reveal'  => __( 'Circle Reveal', 'octave-addons' ),
			'column-stagger' => __( 'Column Stagger', 'octave-addons' ),
			'diagonal-sweep' => __( 'Diagonal Sweep', 'octave-addons' ),
			'split-curtain'  => __( 'Split Curtain', 'octave-addons' ),
			'brand-wipe'     => __( 'Brand Wipe', 'octave-addons' ),
			'soft-fade'      => __( 'Frosted Fade', 'octave-addons' ),
			'replay-loader'  => __( 'Replay Loader', 'octave-addons' ),
			'custom'         => __( 'Custom only', 'octave-addons' ),
		];

	}

	public function sanitize( $input ): array {

		$clean    = $this->get_defaults();
		$defaults = $clean;

		$clean['enabled']              = ! empty( $input['enabled'] );
		$clean['loader_enabled']       = ! empty( $input['loader_enabled'] );
		$clean['loader_show_progress'] = ! empty( $input['loader_show_progress'] );
		$clean['transitions_enabled']  = ! empty( $input['transitions_enabled'] );

		$loader_type          = sanitize_key( $input['loader_type'] ?? '' );
		$clean['loader_type'] = array_key_exists( $loader_type, $this->loader_types() ) ? $loader_type : $defaults['loader_type'];

		$transition_type          = sanitize_key( $input['transition_type'] ?? '' );
		$clean['transition_type'] = array_key_exists( $transition_type, $this->transition_types() ) ? $transition_type : $defaults['transition_type'];

		$replay                          = sanitize_key( $input['transition_loader_type'] ?? '' );
		$clean['transition_loader_type'] = 'match' === $replay || array_key_exists( $replay, $this->loader_types() ) ? $replay : 'match';

		$clean['loader_frequency'] = 'every' === ( $input['loader_frequency'] ?? '' ) ? 'every' : 'session';
		$clean['loader_duration']  = max( 400, min( 2000, absint( $input['loader_duration'] ?? 900 ) ) );
		$clean['loader_text']      = sanitize_text_field( $input['loader_text'] ?? '' );

		$clean['loader_logo']  = Octave_Addons_Fields::sanitize_media_asset( $input['loader_logo'] ?? 0 );
		$clean['loader_image'] = Octave_Addons_Fields::sanitize_media_asset( $input['loader_image'] ?? 0 );

		foreach ( [ 'accent', 'background', 'text' ] as $role ) {

			$clean[ $role . '_source' ] = Octave_Addons_Colors::sanitize_source( $input[ $role . '_source' ] ?? '', $defaults[ $role . '_source' ] );
			$clean[ $role . '_color' ]  = sanitize_hex_color( $input[ $role . '_color' ] ?? '' ) ?: $defaults[ $role . '_color' ];

		}

		// Raw CSS/JS, like the Scroll Animations overrides: only users with
		// manage_options can save this screen, and KSES would break the code.
		foreach ( [ 'loader_css', 'loader_js', 'transition_css', 'transition_js' ] as $key ) {

			$clean[ $key ] = isset( $input[ $key ] ) ? (string) $input[ $key ] : '';

		}

		return $clean;

	}

	/*
	RENDER SETTINGS
	-- Rows only appear when the selected system and type use them
	---------------------------------------------------------- */

	public function render_settings( array $s ): void {

		$colors   = Octave_Addons_Colors::resolved_values();
		$controls = [
			'loaderType'       => $this->field_id( 'loader_type' ),
			'transitionType'   => $this->field_id( 'transition_type' ),
			'replayType'       => $this->field_id( 'transition_loader_type' ),
			'progress'         => $this->field_id( 'loader_show_progress' ),
			'text'             => $this->field_id( 'loader_text' ),
			'duration'         => $this->field_id( 'loader_duration' ),
			'logo'             => $this->field_id( 'loader_logo' ),
			'image'            => $this->field_id( 'loader_image' ),
			'accentSource'     => $this->field_id( 'accent_source' ),
			'accentColor'      => $this->field_id( 'accent_color' ) . '-value',
			'backgroundSource' => $this->field_id( 'background_source' ),
			'backgroundColor'  => $this->field_id( 'background_color' ) . '-value',
			'textSource'       => $this->field_id( 'text_source' ),
			'textColor'        => $this->field_id( 'text_color' ) . '-value',
			'siteName'         => get_bloginfo( 'name' ),
		];

		?>

		<table class="form-table oa-form-table" role="presentation">

			<?php

			Octave_Addons_Fields::section( [ 'label' => __( 'Initial page loader', 'octave-addons' ), 'first' => true ] );

			Octave_Addons_Fields::row( [
				'label' => __( 'Initial page loader', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'name'    => $this->field_name( 'loader_enabled' ),
						'checked' => ! empty( $s['loader_enabled'] ),
						'data'    => [ 'controls-row' => 'oaPlRowLoaderType,oaPlRowLoaderPreview,oaPlRowFrequency,oaPlRowDuration,oaPlRowText,oaPlRowProgress,oaPlRowLogo,oaPlRowImage,oaPlRowLoaderCss,oaPlRowLoaderJs' ],
						'help'    => __( 'Shown on a cold, full page load. Off by default.', 'octave-addons' ),
					] );

				},
			] );

			Octave_Addons_Fields::row( [
				'id'    => 'oaPlRowLoaderType',
				'for'   => $this->field_id( 'loader_type' ),
				'label' => __( 'Loader type', 'octave-addons' ),
				'field' => function () use ( $s ) {

					$this->render_select(
						'loader_type',
						$this->loader_types(),
						$s['loader_type'],
						'oaPlRowText:brand-counter|curtain|custom,oaPlRowProgress:brand-counter,oaPlRowLogo:logo-mask,oaPlRowImage:image-window'
					);

				},
			] );

			Octave_Addons_Fields::row( [
				'id'    => 'oaPlRowLoaderPreview',
				'label' => __( 'Preview', 'octave-addons' ),
				'field' => function () use ( $controls, $colors ) {

					Octave_Addons_Fields::motion_preview( 'loader', $controls, $colors );

				},
			] );

			Octave_Addons_Fields::row( [
				'id'    => 'oaPlRowText',
				'for'   => $this->field_id( 'loader_text' ),
				'label' => __( 'Loader text', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::text( [
						'id'          => $this->field_id( 'loader_text' ),
						'name'        => $this->field_name( 'loader_text' ),
						'value'       => $s['loader_text'],
						'placeholder' => get_bloginfo( 'name' ),
						'help'        => __( 'Leave blank to use the site title.', 'octave-addons' ),
					] );

				},
			] );

			Octave_Addons_Fields::row( [
				'id'    => 'oaPlRowProgress',
				'label' => __( 'Progress number', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'id'      => $this->field_id( 'loader_show_progress' ),
						'name'    => $this->field_name( 'loader_show_progress' ),
						'checked' => ! empty( $s['loader_show_progress'] ),
						'help'    => __( 'Shows real loading progress as a number beside the progress line.', 'octave-addons' ),
					] );

				},
			] );

			Octave_Addons_Fields::row( [
				'id'    => 'oaPlRowLogo',
				'for'   => $this->field_id( 'loader_logo' ) . '-select',
				'label' => __( 'Logo', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::media_asset( [
						'id'    => $this->field_id( 'loader_logo' ),
						'name'  => $this->field_name( 'loader_logo' ),
						'value' => $s['loader_logo'],
						'title' => __( 'Choose a logo', 'octave-addons' ),
						'help'  => __( 'A raster image or, where the site allows SVG uploads, an SVG. Revealed through a mask. Without one, the loader text is used.', 'octave-addons' ),
					] );

				},
			] );

			Octave_Addons_Fields::row( [
				'id'    => 'oaPlRowImage',
				'for'   => $this->field_id( 'loader_image' ) . '-select',
				'label' => __( 'Image', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::media_asset( [
						'id'    => $this->field_id( 'loader_image' ),
						'name'  => $this->field_name( 'loader_image' ),
						'value' => $s['loader_image'],
						'help'  => __( 'Revealed inside a changing crop window while the page loads.', 'octave-addons' ),
					] );

				},
			] );

			Octave_Addons_Fields::row( [
				'id'    => 'oaPlRowFrequency',
				'for'   => $this->field_id( 'loader_frequency' ),
				'label' => __( 'Display frequency', 'octave-addons' ),
				'field' => function () use ( $s ) {

					$this->render_select( 'loader_frequency', [
						'session' => __( 'First visit in session', 'octave-addons' ),
						'every'   => __( 'Every full page load', 'octave-addons' ),
					], $s['loader_frequency'] );

				},
			] );

			Octave_Addons_Fields::row( [
				'id'    => 'oaPlRowDuration',
				'for'   => $this->field_id( 'loader_duration' ),
				'label' => __( 'Loader duration', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::number( [
						'id'     => $this->field_id( 'loader_duration' ),
						'name'   => $this->field_name( 'loader_duration' ),
						'value'  => $s['loader_duration'],
						'min'    => 400,
						'max'    => 2000,
						'step'   => 50,
						'suffix' => 'ms',
						'help'   => __( 'One base value for the whole loader: its entrance, the minimum time it stays up (about 1.7× this, so a fast page still shows the full animation), progress pacing and the reveal all scale from it. Higher is slower; 900ms is the default. The loader always gives up after 8 seconds.', 'octave-addons' ),
					] );

				},
			] );

			Octave_Addons_Fields::section( [ 'label' => __( 'Internal page transitions', 'octave-addons' ) ] );

			Octave_Addons_Fields::row( [
				'label' => __( 'Internal page transitions', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'name'    => $this->field_name( 'transitions_enabled' ),
						'checked' => ! empty( $s['transitions_enabled'] ),
						'data'    => [ 'controls-row' => 'oaPlRowTransitionType,oaPlRowTransitionPreview,oaPlRowReplay,oaPlRowTransitionCss,oaPlRowTransitionJs' ],
						'help'    => __( 'Covers the page on same-origin link clicks and reveals the destination. Normal page loads, so forms, analytics and plugins are unaffected.', 'octave-addons' ),
					] );

				},
			] );

			Octave_Addons_Fields::row( [
				'id'    => 'oaPlRowTransitionType',
				'for'   => $this->field_id( 'transition_type' ),
				'label' => __( 'Transition type', 'octave-addons' ),
				'field' => function () use ( $s ) {

					$this->render_select( 'transition_type', $this->transition_types(), $s['transition_type'], 'oaPlRowReplay:replay-loader' );

					?>

					<span class="oa-help"><?php esc_html_e( 'Links with data-oa-no-transition (on the link or a parent) are skipped.', 'octave-addons' ); ?></span>
					<?php

				},
			] );

			Octave_Addons_Fields::row( [
				'id'    => 'oaPlRowTransitionPreview',
				'label' => __( 'Preview', 'octave-addons' ),
				'field' => function () use ( $controls, $colors ) {

					Octave_Addons_Fields::motion_preview( 'transition', $controls, $colors );

				},
			] );

			Octave_Addons_Fields::row( [
				'id'    => 'oaPlRowReplay',
				'for'   => $this->field_id( 'transition_loader_type' ),
				'label' => __( 'Loader to replay', 'octave-addons' ),
				'field' => function () use ( $s ) {

					$this->render_select( 'transition_loader_type', array_merge(
						[ 'match' => __( 'Same as the initial loader', 'octave-addons' ) ],
						$this->loader_types()
					), $s['transition_loader_type'] );

					?>

					<span class="oa-help"><?php esc_html_e( 'Plays a loader on every internal navigation, using the loader text, media and colours above. While the initial loader is on, its own style is replayed so one loader serves both.', 'octave-addons' ); ?></span>
					<?php

				},
			] );

			Octave_Addons_Fields::section( [ 'label' => __( 'Colours', 'octave-addons' ) ] );

			$this->render_color_rows( 'accent', __( 'Accent colour', 'octave-addons' ), __( 'Progress lines, wipes and highlights.', 'octave-addons' ), $s );
			$this->render_color_rows( 'background', __( 'Background colour', 'octave-addons' ), __( 'The loader and transition surface.', 'octave-addons' ), $s );
			$this->render_color_rows( 'text', __( 'Text colour', 'octave-addons' ), __( 'Loader text and numbers on presets that show text.', 'octave-addons' ), $s );

			Octave_Addons_Fields::custom_setup( [ 'oaPlRowLoaderCss', 'oaPlRowLoaderJs', 'oaPlRowTransitionCss', 'oaPlRowTransitionJs' ] );

			$this->render_code_row( 'oaPlRowLoaderCss', 'loader_css', __( 'Loader CSS', 'octave-addons' ), '#oa-page-loader { }', __( 'Printed after the loader preset. States: html.oa-loader-active while shown, html.oa-loader-exit while revealing; --oa-progress runs from 0 to 1.', 'octave-addons' ), $s );
			$this->render_code_row( 'oaPlRowLoaderJs', 'loader_js', __( 'Loader JavaScript', 'octave-addons' ), "document.addEventListener( 'oa-loader:progress', function ( event ) { } );", __( 'Runs after the lifecycle script, which cannot be replaced. Events: oa-loader:start, oa-loader:progress (detail.progress), oa-loader:exit and oa-loader:done. The 8-second safety timeout always applies.', 'octave-addons' ), $s );

			$this->render_code_row( 'oaPlRowTransitionCss', 'transition_css', __( 'Transition CSS', 'octave-addons' ), '#oa-page-transition { }', __( 'Printed after the transition preset. States: html.oa-transition-out while covering, html.oa-transition-in then html.oa-transition-reveal on the destination.', 'octave-addons' ), $s );
			$this->render_code_row( 'oaPlRowTransitionJs', 'transition_js', __( 'Transition JavaScript', 'octave-addons' ), "document.addEventListener( 'oa-transition:out', function ( event ) { } );", __( 'Runs after the lifecycle script, which cannot be replaced. Events: oa-transition:out (detail.url), oa-transition:in and oa-transition:done.', 'octave-addons' ), $s );

			?>

		</table>
		<?php

	}

	/*
	RENDER SELECT
	-- A plain select, optionally driving conditional rows
	---------------------------------------------------------- */

	protected function render_select( string $key, array $options, string $selected, string $controls = '' ): void {

		?>

		<select id="<?= esc_attr( $this->field_id( $key ) ); ?>"
		        name="<?= esc_attr( $this->field_name( $key ) ); ?>"<?= $controls ? ' data-controls-row="' . esc_attr( $controls ) . '"' : ''; ?>>
			<?php

			foreach ( $options as $value => $label ) {

				?>

				<option value="<?= esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>><?= esc_html( $label ); ?></option>
				<?php

			}

			?>

		</select>
		<?php

	}

	/*
	RENDER CODE ROW
	-- A custom CSS or JavaScript textarea row
	---------------------------------------------------------- */

	protected function render_code_row( string $row_id, string $key, string $label, string $placeholder, string $help, array $s ): void {

		Octave_Addons_Fields::row( [
			'id'    => $row_id,
			'for'   => $this->field_id( $key ),
			'label' => $label,
			'field' => function () use ( $key, $placeholder, $help, $s ) {

				Octave_Addons_Fields::textarea( [
					'id'          => $this->field_id( $key ),
					'name'        => $this->field_name( $key ),
					'value'       => $s[ $key ],
					'class'       => 'oa-code-editor',
					'rows'        => 8,
					'spellcheck'  => false,
					'placeholder' => $placeholder,
					'help'        => $help,
				] );

			},
		] );

	}

	/*
	RENDER COLOR ROWS
	-- A colour-source select plus a custom hex row shown only for Custom,
	-- following the Text Selection architecture
	---------------------------------------------------------- */

	protected function render_color_rows( string $role, string $label, string $help, array $s ): void {

		$row_id = 'oaPlRowColor' . ucfirst( $role );

		Octave_Addons_Fields::row( [
			'for'   => $this->field_id( $role . '_source' ),
			'label' => $label,
			'field' => function () use ( $role, $row_id, $help, $s ) {

				?>

				<select id="<?= esc_attr( $this->field_id( $role . '_source' ) ); ?>"
				        name="<?= esc_attr( $this->field_name( $role . '_source' ) ); ?>"
				        data-controls-row="<?= esc_attr( $row_id ); ?>" data-controls-value="custom">
					<?php Octave_Addons_Colors::render_options( $s[ $role . '_source' ] ); ?>
					<option value="custom" <?php selected( $s[ $role . '_source' ], 'custom' ); ?>><?php esc_html_e( 'Custom colour', 'octave-addons' ); ?></option>
				</select>
				<span class="oa-help"><?= esc_html( $help ); ?></span>
				<?php

			},
		] );

		Octave_Addons_Fields::row( [
			'id'    => $row_id,
			'for'   => $this->field_id( $role . '_color' ),
			'label' => sprintf(
				/* translators: %s: colour role, e.g. Accent colour. */
				__( 'Custom %s', 'octave-addons' ),
				strtolower( $label )
			),
			'field' => function () use ( $role, $s ) {

				Octave_Addons_Fields::color( [
					'id'    => $this->field_id( $role . '_color' ),
					'name'  => $this->field_name( $role . '_color' ),
					'value' => $s[ $role . '_color' ],
				] );

			},
		] );

	}

	/*
	RUN
	-- Registers the frontend hooks when either system is on
	---------------------------------------------------------- */

	public function run( array $s ): void {

		if ( empty( $s['loader_enabled'] ) && empty( $s['transitions_enabled'] ) ) {

			return;

		}

		add_action( 'wp_enqueue_scripts', function () use ( $s ) {

			$this->enqueue_assets( $s );

		} );

		add_action( 'wp_body_open', function () use ( $s ) {

			$this->render_overlays( $s );

		}, 1 );

	}

	/*
	SHOULD RUN
	-- Frontend HTML pages only: never the admin, login, REST, feeds, AJAX,
	-- previews, builder or editor canvases, embeds, or WooCommerce cart,
	-- checkout and account views
	---------------------------------------------------------- */

	protected function should_run(): bool {

		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {

			return false;

		}

		if ( is_feed() || is_embed() || is_preview() || is_customize_preview() || is_robots() ) {

			return false;

		}

		return ! self::is_builder_request() && ! self::is_sensitive_woocommerce_view();

	}

	/*
	ACTIVE TYPES
	-- Resolves what this request needs: the loader type (for the initial
	-- loader or a Replay Loader transition) and the transition type
	---------------------------------------------------------- */

	protected function active_types( array $s ): array {

		$transition = ! empty( $s['transitions_enabled'] ) ? $s['transition_type'] : '';
		$loader     = ! empty( $s['loader_enabled'] ) ? $s['loader_type'] : '';

		// One loader element serves both systems, so the initial loader's own
		// style replays whenever it is on.
		if ( 'replay-loader' === $transition && '' === $loader ) {

			$loader = 'match' === $s['transition_loader_type'] ? $s['loader_type'] : $s['transition_loader_type'];

		}

		return [ $loader, $transition ];

	}

	/*
	ENQUEUE ASSETS
	-- Core CSS and the lifecycle script load in the head so the overlays are
	-- styled before first paint. Only the selected presets' CSS loads.
	---------------------------------------------------------- */

	protected function enqueue_assets( array $s ): void {

		if ( ! $this->should_run() ) {

			return;

		}

		list( $loader, $transition ) = $this->active_types( $s );

		$this->enqueue_style( 'octave-addons-page-motion', 'core.css', [] );

		$css_handle = 'octave-addons-page-motion';

		if ( '' !== $loader && 'custom' !== $loader ) {

			$css_handle = 'octave-addons-page-loader';
			$this->enqueue_style( $css_handle, 'loaders/' . $loader . '.css', [ 'octave-addons-page-motion' ] );

		}

		if ( '' !== $transition && ! in_array( $transition, [ 'custom', 'replay-loader' ], true ) ) {

			$this->enqueue_style( 'octave-addons-page-transition', 'transitions/' . $transition . '.css', [ 'octave-addons-page-motion' ] );

		}

		wp_add_inline_style( 'octave-addons-page-motion', $this->root_css( $s ) );

		if ( ! empty( $s['loader_enabled'] ) && '' !== trim( $s['loader_css'] ) ) {

			wp_add_inline_style( $css_handle, $s['loader_css'] );

		}

		if ( '' !== $transition && '' !== trim( $s['transition_css'] ) ) {

			$handle = wp_style_is( 'octave-addons-page-transition', 'enqueued' ) ? 'octave-addons-page-transition' : $css_handle;

			wp_add_inline_style( $handle, $s['transition_css'] );

		}

		$path = OCTAVE_ADDONS_DIR . self::ASSETS . 'core.js';

		wp_enqueue_script( 'octave-addons-page-motion', OCTAVE_ADDONS_URL . self::ASSETS . 'core.js', [], self::file_version( $path ), false );
		wp_add_inline_script( 'octave-addons-page-motion', 'window.oaPageMotion = ' . wp_json_encode( $this->script_config( $s, $loader, $transition ) ) . ';', 'before' );

		$custom = [
			'loader'     => ! empty( $s['loader_enabled'] ) ? $s['loader_js'] : '',
			'transition' => '' !== $transition ? $s['transition_js'] : '',
		];

		foreach ( $custom as $system => $code ) {

			if ( '' === trim( $code ) ) {

				continue;

			}

			$handle = 'octave-addons-page-' . $system . '-custom';

			wp_register_script( $handle, false, [ 'octave-addons-page-motion' ], null, true );
			wp_enqueue_script( $handle );
			wp_add_inline_script( $handle, $code );

		}

	}

	/*
	ROOT CSS
	-- Colours and the reveal duration as custom properties. A Breakdance
	-- source follows its variable, with the saved hex as fallback for sites
	-- where the variable is missing.
	---------------------------------------------------------- */

	protected function root_css( array $s ): string {

		return sprintf(
			':root{--oa-pm-accent:%s;--oa-pm-bg:%s;--oa-pm-fg:%s;--oa-loader-duration:%dms;}',
			$this->color_value( 'accent', $s ),
			$this->color_value( 'background', $s ),
			$this->color_value( 'text', $s ),
			(int) $s['loader_duration']
		);

	}

	protected function color_value( string $role, array $s ): string {

		$sources = Octave_Addons_Colors::sources();
		$source  = $s[ $role . '_source' ];
		$hex     = sanitize_hex_color( $s[ $role . '_color' ] ) ?: $this->get_defaults()[ $role . '_color' ];

		return isset( $sources[ $source ] ) ? 'var(' . $sources[ $source ]['variable'] . ', ' . $hex . ')' : $hex;

	}

	/*
	SCRIPT CONFIG
	-- Whitelisted values only; nothing typed into a text field reaches it
	---------------------------------------------------------- */

	protected function script_config( array $s, string $loader, string $transition ): array {

		$exclude = [ wp_parse_url( wp_login_url(), PHP_URL_PATH ), wp_parse_url( admin_url(), PHP_URL_PATH ) ];

		foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {

			if ( function_exists( 'wc_get_page_id' ) && wc_get_page_id( $page ) > 0 ) {

				$exclude[] = wp_parse_url( (string) get_permalink( wc_get_page_id( $page ) ), PHP_URL_PATH );

			}

		}

		return [
			'loader'     => ! empty( $s['loader_enabled'] ),
			'frequency'  => $s['loader_frequency'],
			'duration'   => (int) $s['loader_duration'],
			'transition' => $transition,
			'replay'     => 'replay-loader' === $transition,
			'cover'      => 'replay-loader' === $transition ? (int) round( $s['loader_duration'] / 3 ) : ( self::COVER_MS[ $transition ] ?? 400 ),
			'reveal'     => self::REVEAL_MS[ $transition ] ?? 600,
			'exclude'    => array_values( array_unique( array_filter( $exclude ) ) ),
		];

	}

	/*
	RENDER OVERLAYS
	-- Printed at the top of the body. Both overlays are hidden by CSS unless
	-- the head script decides to show them, so without JavaScript nothing
	-- appears. A theme without wp_body_open simply gets no overlay, and the
	-- head script releases its scroll lock on DOMContentLoaded.
	---------------------------------------------------------- */

	protected function render_overlays( array $s ): void {

		if ( $this->rendered || ! $this->should_run() ) {

			return;

		}

		$this->rendered = true;

		list( $loader, $transition ) = $this->active_types( $s );

		$text = '' !== $s['loader_text'] ? $s['loader_text'] : get_bloginfo( 'name' );

		if ( '' !== $loader ) {

			$this->render_loader( $loader, $text, $s );

		}

		if ( '' !== $transition && 'replay-loader' !== $transition ) {

			?>

			<div id="oa-page-transition" class="oa-transition oa-transition--<?= esc_attr( $transition ); ?>" aria-hidden="true">
				<span class="oa-transition__layer"></span>
				<span class="oa-transition__layer oa-transition__layer--b"></span>
				<?php

				if ( 'column-stagger' === $transition ) {

					for ( $i = 0; $i < self::COLUMNS; $i++ ) {

						printf( '<span class="oa-transition__col" style="--oa-i:%d"></span>', (int) $i );

					}

				}

				if ( 'brand-wipe' === $transition ) :

				?>

				<span class="oa-transition__brand"><?= esc_html( $text ); ?></span>
				<?php

				endif;

				?>

			</div>
			<?php

		}

	}

	/*
	RENDER LOADER
	-- Decorative markup per preset, kept out of the accessibility tree.
	-- Media presets fall back to the loader text when their attachment is
	-- missing, so a deleted image never leaves a broken frame.
	---------------------------------------------------------- */

	protected function render_loader( string $type, string $text, array $s ): void {

		$logo  = 'logo-mask' === $type ? Octave_Addons_Fields::media_asset_url( $s['loader_logo'] ) : '';
		$image = 'image-window' === $type ? Octave_Addons_Fields::media_asset_url( $s['loader_image'] ) : '';

		?>

		<div id="oa-page-loader" class="oa-loader oa-loader--<?= esc_attr( $type ); ?>" aria-hidden="true">
			<div class="oa-loader__inner">
				<?php

				if ( 'brand-counter' === $type ) :

				?>

				<span class="oa-loader__brand"><?= esc_html( $text ); ?></span>
				<span class="oa-loader__meter">
					<span class="oa-loader__line"><span class="oa-loader__bar"></span></span>
					<?php

					if ( ! empty( $s['loader_show_progress'] ) ) :

					?>

					<span class="oa-loader__count"><span data-oa-loader-count>0</span></span>
					<?php

					endif;

					?>

				</span>
				<?php

				elseif ( 'logo-mask' === $type ) :

				?>

				<span class="oa-loader__logo">
					<?php

					if ( '' !== $logo ) :

					?>

					<img src="<?= esc_url( $logo ); ?>" alt="" decoding="async">
					<?php

					else :

					?>

					<span class="oa-loader__brand"><?= esc_html( $text ); ?></span>
					<?php

					endif;

					?>

				</span>
				<span class="oa-loader__line"><span class="oa-loader__bar"></span></span>
				<?php

				elseif ( 'image-window' === $type ) :

				?>

				<span class="oa-loader__window<?= '' === $image ? ' oa-loader__window--empty' : ''; ?>">
					<?php

					if ( '' !== $image ) :

					?>

					<img src="<?= esc_url( $image ); ?>" alt="" decoding="async">
					<?php

					endif;

					?>

				</span>
				<?php

				elseif ( 'curtain' === $type ) :

				?>

				<span class="oa-loader__panel oa-loader__panel--a"></span>
				<span class="oa-loader__panel oa-loader__panel--b"></span>
				<span class="oa-loader__brand"><?= esc_html( $text ); ?></span>
				<?php

				elseif ( 'orbital' === $type ) :

				?>

				<svg class="oa-loader__orbit" viewBox="0 0 120 120" focusable="false">
					<circle class="oa-loader__ring" cx="60" cy="60" r="44"></circle>
					<circle class="oa-loader__arc" cx="60" cy="60" r="44" pathLength="100"></circle>
					<g class="oa-loader__tracker"><circle cx="60" cy="16" r="3"></circle></g>
				</svg>
				<?php

				else :

				?>

				<span class="oa-loader__brand" data-oa-loader-text><?= esc_html( $text ); ?></span>
				<?php

				endif;

				?>

			</div>
		</div>
		<?php

	}

	/*
	ENQUEUE STYLE
	-- Enqueues a bundled stylesheet versioned by its modification time
	---------------------------------------------------------- */

	protected function enqueue_style( string $handle, string $file, array $deps ): void {

		wp_enqueue_style( $handle, OCTAVE_ADDONS_URL . self::ASSETS . $file, $deps, self::file_version( OCTAVE_ADDONS_DIR . self::ASSETS . $file ) );

	}

}

return new Octave_Addons_Module_Page_Loader();
