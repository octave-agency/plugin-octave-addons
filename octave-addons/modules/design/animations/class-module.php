<?php

/*
MODULE: ANIMATIONS
-- Enqueues one scroll-animation preset on the frontend: a shared controller,
-- the shared base stylesheet, and the selected preset's CSS and JS
-- The controller is never replaced by custom code, so content always becomes
-- visible: it reveals every hidden target as it scrolls into view, releases
-- everything if anything fails, and does nothing for reduced-motion visitors
-- The CSS override prints after the selected preset, and the JS override
-- replaces the preset-specific script while the controller keeps running
-- Custom only loads no preset and runs only the overrides; Off loads nothing
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Animations extends Octave_Addons_Module {

	/**
	 * Bundled presets, each shipping presets/<key>.css and presets/<key>.js.
	 */
	protected const PRESETS = [ 'luxury', 'editorial', 'creative', 'cinematic', 'minimal' ];

	/**
	 * Asset folder relative to the plugin root.
	 */
	protected const ASSETS = 'modules/design/animations/assets/';

	public function get_id(): string {

		return 'animations';

	}

	public function get_title(): string {

		return __( 'Scroll Animations', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Reveals Breakdance headings, images, text, columns, grids, loops and FAQ items as they scroll into view, using one curated motion preset or your own CSS/JS.', 'octave-addons' );

	}

	public function get_defaults(): array {

		return [
			'enabled'        => false,
			'type'           => 'luxury',
			'css_override'   => '',
			'js_override'    => '',
			'load_in_editor' => false,
		];

	}

	/*
	TYPES
	-- Every selectable scroll animation type, in dropdown order
	---------------------------------------------------------- */

	protected function types(): array {

		return [
			'luxury'    => __( 'Luxury', 'octave-addons' ),
			'editorial' => __( 'Editorial', 'octave-addons' ),
			'creative'  => __( 'Creative', 'octave-addons' ),
			'cinematic' => __( 'Cinematic', 'octave-addons' ),
			'minimal'   => __( 'Minimal', 'octave-addons' ),
			'custom'    => __( 'Custom only', 'octave-addons' ),
			'off'       => __( 'Off', 'octave-addons' ),
		];

	}

	/*
	GET SETTINGS
	-- Settings saved before 3.23.0 carry Load CSS / Load JavaScript switches
	-- instead of a type, so they are mapped to the closest safe type here
	---------------------------------------------------------- */

	public function get_settings( array $saved ): array {

		if ( $saved && ! isset( $saved['type'] ) ) {

			$saved['type'] = $this->legacy_type( $saved );

		}

		unset( $saved['load_css'], $saved['load_js'] );

		return parent::get_settings( $saved );

	}

	/*
	LEGACY TYPE
	-- Bundled CSS (with the bundled or a custom script) maps to Luxury, the
	-- preset closest to the old fade/slide and word-mask motion. Bundled JS
	-- alone only did visible work alongside a CSS override, so it maps to
	-- Luxury when one exists. Neither switch on means only the overrides ran,
	-- which is exactly Custom only. Override content is carried over untouched.
	---------------------------------------------------------- */

	protected function legacy_type( array $saved ): string {

		$load_css = ! array_key_exists( 'load_css', $saved ) || ! empty( $saved['load_css'] );
		$load_js  = ! array_key_exists( 'load_js', $saved ) || ! empty( $saved['load_js'] );

		if ( $load_css ) {

			return 'luxury';

		}

		if ( $load_js && '' !== trim( (string) ( $saved['css_override'] ?? '' ) ) ) {

			return 'luxury';

		}

		return 'custom';

	}

	public function sanitize( $input ): array {

		$clean                   = $this->get_defaults();
		$clean['enabled']        = ! empty( $input['enabled'] );
		$clean['load_in_editor'] = ! empty( $input['load_in_editor'] );

		$type          = sanitize_key( $input['type'] ?? '' );
		$clean['type'] = array_key_exists( $type, $this->types() ) ? $type : 'luxury';

		// Overrides are raw CSS/JS — we do NOT KSES them (that would
		// destroy valid CSS/JS). They're only settable by users with
		// manage_options, same as the theme's Additional CSS field.
		$clean['css_override'] = isset( $input['css_override'] ) ? (string) $input['css_override'] : '';
		$clean['js_override']  = isset( $input['js_override'] ) ? (string) $input['js_override'] : '';

		return $clean;

	}

	public function render_settings( array $s ): void {

		$active = 'luxury,editorial,creative,cinematic,minimal,custom';

		?>

		<table class="form-table oa-form-table" role="presentation">

			<?php

			Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'type' ),
				'label' => __( 'Scroll animation type', 'octave-addons' ),
				'field' => function () use ( $s ) {

					?>

					<select id="<?= esc_attr( $this->field_id( 'type' ) ); ?>"
					        name="<?= esc_attr( $this->field_name( 'type' ) ); ?>"
					        data-controls-row="oaAnimRowEditor,oaAnimRowCss,oaAnimRowJs"
					        data-controls-value="luxury,editorial,creative,cinematic,minimal,custom">
						<?php

						foreach ( $this->types() as $key => $label ) {

							?>

							<option value="<?= esc_attr( $key ); ?>" <?php selected( $s['type'], $key ); ?>><?= esc_html( $label ); ?></option>
							<?php

						}

						?>

					</select>
					<span class="oa-help"><?php esc_html_e( 'Only the selected preset\'s CSS and JavaScript load. Custom only runs just your overrides, and Off loads nothing and leaves all content visible.', 'octave-addons' ); ?></span>
					<?php

					Octave_Addons_Fields::motion_preview( 'scroll', [ 'type' => $this->field_id( 'type' ) ] );

				},
			] );

			Octave_Addons_Fields::row( [
				'id'    => 'oaAnimRowEditor',
				'label' => __( 'Load in page builder', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'name'    => $this->field_name( 'load_in_editor' ),
						'checked' => ! empty( $s['load_in_editor'] ),
						'help'    => __( 'Also load inside block editor previews. Breakdance builder canvases are always excluded.', 'octave-addons' ),
					] );

				},
			] );

			Octave_Addons_Fields::custom_setup( [ 'oaAnimRowCss', 'oaAnimRowJs' ] );

			Octave_Addons_Fields::row( [
				'id'    => 'oaAnimRowCss',
				'for'   => $this->field_id( 'css_override' ),
				'label' => __( 'CSS override', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::textarea( [
						'id'          => $this->field_id( 'css_override' ),
						'name'        => $this->field_name( 'css_override' ),
						'value'       => $s['css_override'],
						'class'       => 'oa-code-editor',
						'rows'        => 12,
						'spellcheck'  => false,
						'placeholder' => '.oa-anim-ready [data-oa-anim="media"] { --oa-media-dur: 1200ms; }',
						'help'        => __( 'Printed after the selected preset. Targets carry data-oa-anim (heading, media, text or item) and gain .visible when revealed. Add .oa-no-anim to an element to skip it and everything inside it.', 'octave-addons' ),
					] );

				},
			] );

			Octave_Addons_Fields::row( [
				'id'    => 'oaAnimRowJs',
				'for'   => $this->field_id( 'js_override' ),
				'label' => __( 'JavaScript override', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::textarea( [
						'id'          => $this->field_id( 'js_override' ),
						'name'        => $this->field_name( 'js_override' ),
						'value'       => $s['js_override'],
						'class'       => 'oa-code-editor',
						'rows'        => 14,
						'spellcheck'  => false,
						'placeholder' => "OctaveAnimations.preset( { split: 'words', stagger: 90 } );",
						'help'        => __( 'Replaces the preset script. The safety controller still runs, so content is always revealed; configure it with OctaveAnimations.preset() or reveal elements with OctaveAnimations.reveal().', 'octave-addons' ),
					] );

				},
			] );

			?>

		</table>
		<?php

	}

	public function run( array $s ): void {

		add_action( 'wp_enqueue_scripts', function () use ( $s ) {

			$this->enqueue_assets( $s );

		} );

		if ( ! empty( $s['load_in_editor'] ) ) {

			add_action( 'enqueue_block_editor_assets', function () use ( $s ) {

				$this->enqueue_assets( $s );

			} );

		}

	}

	/*
	ENQUEUE ASSETS
	-- Loads the controller, base sheet and selected preset only, never the
	-- other presets. The CSS override is attached to the last sheet so it
	-- prints after the preset; the JS override stands in for the preset script.
	---------------------------------------------------------- */

	protected function enqueue_assets( array $s ): void {

		$type = (string) ( $s['type'] ?? 'luxury' );

		if ( 'off' === $type || self::is_builder_request() ) {

			return;

		}

		// The reveal targets include list and loop items that WooCommerce
		// replaces via AJAX on these views, so animations stay off them.
		if ( self::is_sensitive_woocommerce_view() ) {

			return;

		}

		$is_preset    = in_array( $type, self::PRESETS, true );
		$css_override = trim( (string) ( $s['css_override'] ?? '' ) );
		$js_override  = trim( (string) ( $s['js_override'] ?? '' ) );

		if ( ! $is_preset && '' === $css_override && '' === $js_override ) {

			return;

		}

		// -------- Controller --------
		$controller = 'octave-addons-animations-controller';

		if ( $is_preset || '' !== $js_override ) {

			$this->enqueue_script( $controller, 'controller.js', [], false );

		}

		// -------- CSS --------
		$css_handle = 'octave-addons-animations';

		if ( $is_preset ) {

			$this->enqueue_style( 'octave-addons-animations-base', 'presets/base.css', [] );
			$this->enqueue_style( $css_handle, 'presets/' . $type . '.css', [ 'octave-addons-animations-base' ] );

		} elseif ( '' !== $css_override ) {

			wp_register_style( $css_handle, false, [], null );
			wp_enqueue_style( $css_handle );

		}

		if ( '' !== $css_override ) {

			wp_add_inline_style( $css_handle, $css_override );

		}

		// -------- JS --------
		$js_handle = 'octave-addons-animations';

		if ( '' !== $js_override ) {

			wp_register_script( $js_handle, false, [ $controller ], null, true );
			wp_enqueue_script( $js_handle );
			wp_add_inline_script( $js_handle, $js_override );

		} elseif ( $is_preset ) {

			$this->enqueue_script( $js_handle, 'presets/' . $type . '.js', [ $controller ], true );

		}

	}

	/*
	ENQUEUE STYLE
	-- Enqueues a bundled stylesheet versioned by its modification time
	---------------------------------------------------------- */

	protected function enqueue_style( string $handle, string $file, array $deps ): void {

		wp_enqueue_style( $handle, OCTAVE_ADDONS_URL . self::ASSETS . $file, $deps, self::file_version( OCTAVE_ADDONS_DIR . self::ASSETS . $file ) );

	}

	/*
	ENQUEUE SCRIPT
	-- The controller loads in the head so hidden states never flash; preset
	-- scripts load in the footer and only configure it
	---------------------------------------------------------- */

	protected function enqueue_script( string $handle, string $file, array $deps, bool $in_footer ): void {

		wp_enqueue_script( $handle, OCTAVE_ADDONS_URL . self::ASSETS . $file, $deps, self::file_version( OCTAVE_ADDONS_DIR . self::ASSETS . $file ), $in_footer );

	}

}

return new Octave_Addons_Module_Animations();
