<?php

/*
MODULE: ANIMATIONS
-- Enqueues the Octave scroll-animation CSS/JS on the frontend. Both can
-- be individually toggled on/off, and either can be fully overridden by
-- entering custom CSS or JS in the admin. When the override field is
-- non-empty it is used verbatim *instead of* the bundled asset, which
-- lets the animation behaviour be tweaked per-site without editing
-- plugin source.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Animations extends Octave_Addons_Module {


	public function get_id(): string {

		return 'animations';

	}

	public function get_title(): string {

		return __( 'Scroll Animations', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Adds the Octave fade/slide-in scroll animations to Bricks columns, grids, FAQ items, headings and images. Each layer can be toggled on or overridden with your own CSS/JS.', 'octave-addons' );

	}

	public function get_defaults(): array {
		return [
			'enabled'       => false,
			'load_css'      => true,
			'load_js'       => true,
			'css_override'  => '',
			'js_override'   => '',
			'load_in_editor'=> false,  // Load in Bricks builder / block editor previews
		];

	}

	public function sanitize( $input ): array {

		$clean                   = $this->get_defaults();
		$clean['enabled']        = ! empty( $input['enabled'] );
		$clean['load_css']       = ! empty( $input['load_css'] );
		$clean['load_js']        = ! empty( $input['load_js'] );
		$clean['load_in_editor'] = ! empty( $input['load_in_editor'] );

		// Overrides are raw CSS/JS — we do NOT KSES them (that would
		// destroy valid CSS/JS). They're only settable by users with
		// manage_options, same as the theme's Additional CSS field.
		$clean['css_override'] = isset( $input['css_override'] ) ? (string) $input['css_override'] : '';
		$clean['js_override']  = isset( $input['js_override'] )  ? (string) $input['js_override'] : '';

		return $clean;

	}

	public function render_settings( array $s ): void {

		$default_css_url = OCTAVE_ADDONS_URL . 'modules/animations/assets/animation.css';
		$default_js_url  = OCTAVE_ADDONS_URL . 'modules/animations/assets/animation.js';
		?>

		<table class="form-table oa-form-table" role="presentation">

			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Load CSS', 'octave-addons' ),
				'field' => function () use ( $s, $default_css_url ) {
					Octave_Addons_Fields::switch_field( [
						'name'    => $this->field_name( 'load_css' ),
						'checked' => ! empty( $s['load_css'] ),
						'help'    => __( 'Enqueue the bundled animation.css on the frontend', 'octave-addons' ),
					] );
					?><span class="oa-help"><?php printf(
						/* translators: %s is a URL to the default file */
						esc_html__( 'Default file: %s', 'octave-addons' ),
						'<a href="' . esc_url( $default_css_url ) . '" target="_blank" rel="noopener">animation.css</a>'
					); ?></span><?php
				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Load JavaScript', 'octave-addons' ),
				'field' => function () use ( $s, $default_js_url ) {
					Octave_Addons_Fields::switch_field( [
						'name'    => $this->field_name( 'load_js' ),
						'checked' => ! empty( $s['load_js'] ),
						'help'    => __( 'Enqueue the bundled animation.js on the frontend', 'octave-addons' ),
					] );
					?><span class="oa-help"><?php printf(
						/* translators: %s is a URL to the default file */
						esc_html__( 'Default file: %s', 'octave-addons' ),
						'<a href="' . esc_url( $default_js_url ) . '" target="_blank" rel="noopener">animation.js</a>'
					); ?></span><?php
				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
				'label' => __( 'Load in page builder', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'name'    => $this->field_name( 'load_in_editor' ),
						'checked' => ! empty( $s['load_in_editor'] ),
						'help'    => __( 'Also load inside Bricks builder / block editor previews', 'octave-addons' ),
					] );
					?><span class="oa-help"><?php esc_html_e( 'Off by default — animations often fight the in-editor preview.', 'octave-addons' ); ?></span><?php
				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
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
						'placeholder' => __( 'Replaces the bundled animation.css.', 'octave-addons' ),
						'help'        => __( 'Used as the main animation CSS instead of the bundled file, even when Load CSS is disabled.', 'octave-addons' ),
					] );

				},
			] ); ?>
			<?php Octave_Addons_Fields::row( [
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
						'placeholder' => __( 'Replaces the bundled animation.js.', 'octave-addons' ),
						'help'        => __( 'Used as the main animation JavaScript instead of the bundled file, even when Load JavaScript is disabled.', 'octave-addons' ),
					] );

				},
			] ); ?>
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

	protected function enqueue_assets( array $s ): void {

		if ( $this->is_breakdance_builder_request() ) {

			return;

		}

		// WooCommerce cart/checkout/account views replace parts of the DOM via
		// AJAX. The generic stagger rules in this module can catch payment-method
		// and notice list items, leaving fresh fragments hidden or offset after
		// WooCommerce updates them. Keep animations off these sensitive flows.
		if ( $this->is_sensitive_woocommerce_view() ) {

			return;

		}

		// -------- CSS --------
		$css_handle   = 'octave-addons-animations';
		$css_override = (string) ( $s['css_override'] ?? '' );

		if ( '' !== trim( $css_override ) ) {

			wp_register_style( $css_handle, false, [], null );
			wp_enqueue_style( $css_handle );
			wp_add_inline_style( $css_handle, $css_override );

		} elseif ( ! empty( $s['load_css'] ) ) {

			$css_url = OCTAVE_ADDONS_URL . 'modules/animations/assets/animation.css';
			$css_ver = $this->file_version( OCTAVE_ADDONS_DIR . 'modules/animations/assets/animation.css' );

			wp_enqueue_style( $css_handle, $css_url, [], $css_ver );

		}

		// -------- JS --------
		$js_handle   = 'octave-addons-animations';
		$js_override = (string) ( $s['js_override'] ?? '' );

		if ( '' !== trim( $js_override ) ) {

			wp_register_script( $js_handle, false, [], null, true );
			wp_enqueue_script( $js_handle );
			wp_add_inline_script( $js_handle, $js_override );

		} elseif ( ! empty( $s['load_js'] ) ) {

			$js_url = OCTAVE_ADDONS_URL . 'modules/animations/assets/animation.js';
			$js_ver = $this->file_version( OCTAVE_ADDONS_DIR . 'modules/animations/assets/animation.js' );

			wp_enqueue_script( $js_handle, $js_url, [], $js_ver, true );

		}

	}

	/**
	 * Detect actual Breakdance builder requests without disabling animations
	 * across the whole WordPress admin.
	 */
	protected function is_breakdance_builder_request(): bool {

		$breakdance_mode = isset( $_GET['breakdance'] ) ? sanitize_key( wp_unslash( $_GET['breakdance'] ) ) : '';
		$admin_page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$iframe_mode     = isset( $_GET['breakdance_iframe'] ) ? sanitize_key( wp_unslash( $_GET['breakdance_iframe'] ) ) : '';

		if ( 'builder' === $breakdance_mode || isset( $_GET['breakdance_frame'] ) ) {

			return true;

		}

		if ( '' !== $iframe_mode || isset( $_GET['breakdance_open_document'] ) ) {

			return true;

		}

		if ( is_admin() && false !== strpos( $admin_page, 'breakdance' ) ) {

			return true;

		}

		return false;

	}

	/**
	 * Skip animation assets on WooCommerce views that rely on AJAX fragment
	 * replacement during critical purchase/account flows.
	 */
	protected function is_sensitive_woocommerce_view(): bool {

		$conditional_tags = [
			'is_cart',
			'is_checkout',
			'is_account_page',
		];

		foreach ( $conditional_tags as $tag ) {

			if ( function_exists( $tag ) && $tag() ) {

				return true;

			}

		}

		return false;

	}

	/**
	 * Use the file mtime as asset version so edits bust browser caches
	 * without having to bump the plugin version.
	 */
	protected function file_version( string $path ): string {

		if ( file_exists( $path ) ) {

			return (string) filemtime( $path );

		}
		return OCTAVE_ADDONS_VERSION;

	}

}

return new Octave_Addons_Module_Animations();
