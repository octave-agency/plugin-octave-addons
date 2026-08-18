<?php

/*
ADMIN EXPERIENCE
-- Owns the optional site-wide WordPress admin refresh and per-user theme.
-- Keeping the feature isolated makes it safe to disable or remove later.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Admin_Experience {

	public function __construct() {

		add_action( 'admin_init',            [ $this, 'register_setting' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_bar_menu',        [ $this, 'add_theme_toggle' ], 998 );
		add_action( 'wp_ajax_oa_save_admin_theme', [ $this, 'ajax_save_theme' ] );

	}

	/*
	REGISTER SETTING
	-- Stores one site-wide enabled state independently of module settings.
	---------------------------------------------------------- */

	public function register_setting(): void {

		register_setting(
			'octave_addons_admin_experience_group',
			OCTAVE_ADDONS_ADMIN_EXPERIENCE_OPTION,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_setting' ],
				'default'           => '0',
			]
		);

	}

	/*
	SANITIZE SETTING
	-- Restricts the site-wide switch to an explicit enabled or disabled value.
	---------------------------------------------------------- */

	public function sanitize_setting( $value ): string {

		return '1' === (string) $value ? '1' : '0';

	}

	/*
	IS ENABLED
	-- Provides the single feature boundary used by assets and interface controls.
	---------------------------------------------------------- */

	public function is_enabled(): bool {

		return '1' === (string) get_option( OCTAVE_ADDONS_ADMIN_EXPERIENCE_OPTION, '0' );

	}

	/*
	ENQUEUE ASSETS
	-- Loads the visual refresh only while the site-wide switch is enabled.
	---------------------------------------------------------- */

	public function enqueue_assets(): void {

		if ( ! $this->is_enabled() ) {

			return;

		}

		$css_path = OCTAVE_ADDONS_DIR . 'assets/css/admin-experience.css';
		$js_path  = OCTAVE_ADDONS_DIR . 'assets/js/admin-experience.js';
		$theme    = get_user_meta( get_current_user_id(), 'oa_admin_theme', true );

		if ( ! in_array( $theme, [ 'light', 'dark' ], true ) ) {

			$theme = 'system';

		}

		wp_enqueue_style(
			'octave-addons-admin-experience',
			OCTAVE_ADDONS_URL . 'assets/css/admin-experience.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : OCTAVE_ADDONS_VERSION
		);

		wp_enqueue_script(
			'octave-addons-admin-experience',
			OCTAVE_ADDONS_URL . 'assets/js/admin-experience.js',
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : OCTAVE_ADDONS_VERSION,
			false
		);

		wp_localize_script( 'octave-addons-admin-experience', 'oaAdminExperience', [
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'oa_admin_theme' ),
			'theme'         => $theme,
			'darkModeText'  => __( 'Use dark mode', 'octave-addons' ),
			'lightModeText' => __( 'Use light mode', 'octave-addons' ),
		] );

	}

	/*
	ADD THEME TOGGLE
	-- Places the per-user light and dark mode control in the admin bar.
	---------------------------------------------------------- */

	public function add_theme_toggle( WP_Admin_Bar $admin_bar ): void {

		if ( ! is_admin() || ! is_user_logged_in() || ! $this->is_enabled() ) {

			return;

		}

		$admin_bar->add_node( [
			'id'     => 'oa-theme-toggle',
			'parent' => 'top-secondary',
			'title'  => '<span class="ab-icon dashicons dashicons-admin-appearance" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Appearance', 'octave-addons' ) . '</span>',
			'href'   => '#',
			'meta'   => [
				'class' => 'oa-theme-toggle',
				'title' => __( 'Toggle light or dark mode', 'octave-addons' ),
			],
		] );

	}

	/*
	SAVE THEME
	-- Persists an explicit light or dark choice for the current user.
	---------------------------------------------------------- */

	public function ajax_save_theme(): void {

		check_ajax_referer( 'oa_admin_theme', 'nonce' );

		$theme = isset( $_POST['theme'] ) ? sanitize_key( wp_unslash( $_POST['theme'] ) ) : '';

		if ( ! in_array( $theme, [ 'light', 'dark' ], true ) ) {

			wp_send_json_error( [
				'message' => __( 'That appearance choice is not available.', 'octave-addons' ),
			], 400 );

		}

		update_user_meta( get_current_user_id(), 'oa_admin_theme', $theme );

		wp_send_json_success( [
			'theme' => $theme,
		] );

	}

	/*
	RENDER SETTING CARD
	-- Prints the isolated site-wide switch on the plugin dashboard.
	---------------------------------------------------------- */

	public function render_setting_card(): void {

		$is_enabled = $this->is_enabled();

		?>

		<section class="oa-dashboard-setting">
			<div class="oa-dashboard-setting-copy">
				<span class="oa-dashboard-setting-icon" aria-hidden="true">
					<?php Octave_Addons_Icons::render( 'sliders', 20 ); ?>
				</span>
				<div>
					<span class="oa-panel-kicker"><?php esc_html_e( 'Site-wide appearance', 'octave-addons' ); ?></span>
					<h2><?php esc_html_e( 'Modern WordPress admin', 'octave-addons' ); ?></h2>
					<p><?php esc_html_e( 'Turn off the custom admin stylesheet and theme script across this site while keeping every functional plugin asset active.', 'octave-addons' ); ?></p>
				</div>
			</div>

			<form method="post" action="options.php" class="oa-dashboard-setting-form">
				<?php settings_fields( 'octave_addons_admin_experience_group' ); ?>
				<input type="hidden" name="<?= esc_attr( OCTAVE_ADDONS_ADMIN_EXPERIENCE_OPTION ); ?>" value="0">
				<label class="oa-switch">
					<span class="screen-reader-text"><?php esc_html_e( 'Enable modern WordPress admin', 'octave-addons' ); ?></span>
					<input type="checkbox" name="<?= esc_attr( OCTAVE_ADDONS_ADMIN_EXPERIENCE_OPTION ); ?>" value="1" <?php checked( $is_enabled ); ?>>
					<span class="oa-switch-slider"></span>
				</label>
				<span class="oa-dashboard-setting-state">
					<?= $is_enabled ? esc_html__( 'Enabled', 'octave-addons' ) : esc_html__( 'Disabled', 'octave-addons' ); ?>
				</span>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'octave-addons' ); ?></button>
			</form>
		</section>

		<?php

	}

}
