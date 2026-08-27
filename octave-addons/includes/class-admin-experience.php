<?php

/*
ADMIN EXPERIENCE
-- Owns the optional site-wide WordPress admin refresh and per-device theme.
-- Keeping the feature isolated makes it safe to disable or remove later.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Admin_Experience {

	const THEME_COOKIE      = 'oa_admin_theme';
	const THEME_COOKIE_DAYS = 365;

	public function __construct() {

		add_action( 'admin_init',            [ $this, 'register_setting' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_bar_menu',        [ $this, 'add_theme_toggle' ], 998 );

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

	public function enqueue_assets( string $hook ): void {

		if ( ! $this->is_enabled() ) {

			return;

		}

		$css_path          = OCTAVE_ADDONS_DIR . 'assets/css/admin-experience.css';
		$integrations_path = OCTAVE_ADDONS_DIR . 'assets/css/admin-experience-integrations.css';
		$woo_path          = OCTAVE_ADDONS_DIR . 'assets/css/admin-experience-woocommerce.css';
		$js_path           = OCTAVE_ADDONS_DIR . 'assets/js/admin-experience.js';
		$theme             = $this->get_theme();

		wp_enqueue_style(
			'octave-addons-admin-experience',
			OCTAVE_ADDONS_URL . 'assets/css/admin-experience.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : OCTAVE_ADDONS_VERSION
		);

		wp_enqueue_style(
			'octave-addons-admin-experience-integrations',
			OCTAVE_ADDONS_URL . 'assets/css/admin-experience-integrations.css',
			[ 'octave-addons-admin-experience' ],
			file_exists( $integrations_path ) ? (string) filemtime( $integrations_path ) : OCTAVE_ADDONS_VERSION
		);

		if ( $this->is_woocommerce_active() ) {

			wp_enqueue_style(
				'octave-addons-admin-experience-woocommerce',
				OCTAVE_ADDONS_URL . 'assets/css/admin-experience-woocommerce.css',
				[ 'octave-addons-admin-experience' ],
				file_exists( $woo_path ) ? (string) filemtime( $woo_path ) : OCTAVE_ADDONS_VERSION
			);

		}

		wp_enqueue_script(
			'octave-addons-admin-experience',
			OCTAVE_ADDONS_URL . 'assets/js/admin-experience.js',
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : OCTAVE_ADDONS_VERSION,
			false
		);

		wp_localize_script( 'octave-addons-admin-experience', 'oaAdminExperience', [
			'theme'           => $theme,
			'cookieName'      => self::THEME_COOKIE,
			'cookieDays'      => (string) self::THEME_COOKIE_DAYS,
			'cookiePath'      => $this->get_cookie_path(),
			'cookieDomain'    => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '',
			'cookieSecure'    => is_ssl() ? '1' : '',
			'darkModeText'    => __( 'Use dark mode', 'octave-addons' ),
			'lightModeText'   => __( 'Use light mode', 'octave-addons' ),
			'mediaSearchText' => __( 'Search media…', 'octave-addons' ),
		] );

	}

	/*
	IS WOOCOMMERCE ACTIVE
	-- Gates the WooCommerce sheet so it never loads on a store-free site.
	---------------------------------------------------------- */

	public function is_woocommerce_active(): bool {

		return class_exists( 'WooCommerce' );

	}

	/*
	ADD THEME TOGGLE
	-- Places an icon-only per-user light and dark mode control in the admin bar.
	---------------------------------------------------------- */

	public function add_theme_toggle( WP_Admin_Bar $admin_bar ): void {

		if ( ! is_admin() || ! is_user_logged_in() || ! $this->is_enabled() ) {

			return;

		}

		$sun_icon  = '<span class="oa-theme-icon oa-theme-icon-sun" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"></path></svg></span>';
		$moon_icon = '<span class="oa-theme-icon oa-theme-icon-moon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M20.5 14.3A8.5 8.5 0 0 1 9.7 3.5 8.5 8.5 0 1 0 20.5 14.3Z"></path></svg></span>';

		$admin_bar->add_node( [
			'id'     => 'oa-theme-toggle',
			'parent' => 'top-secondary',
			'title'  => $sun_icon . $moon_icon,
			'href'   => '#',
			'meta'   => [
				'class' => 'oa-theme-toggle',
				'title' => __( 'Toggle light or dark mode', 'octave-addons' ),
			],
		] );

	}

	/*
	GET THEME
	-- Reads the appearance choice from the browser so shared accounts stay per device.
	---------------------------------------------------------- */

	public function get_theme(): string {

		$theme = isset( $_COOKIE[ self::THEME_COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ self::THEME_COOKIE ] ) ) : '';

		if ( ! in_array( $theme, [ 'light', 'dark' ], true ) ) {

			return 'system';

		}

		return $theme;

	}

	/*
	GET COOKIE PATH
	-- Scopes the cookie to the whole install so wp-admin and the front end agree.
	---------------------------------------------------------- */

	public function get_cookie_path(): string {

		if ( defined( 'SITECOOKIEPATH' ) && SITECOOKIEPATH ) {

			return (string) SITECOOKIEPATH;

		}

		return '/';

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
					<p><?php esc_html_e( 'Refresh the complete WordPress admin and let each user switch between light and dark mode from the admin bar.', 'octave-addons' ); ?></p>
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
				<button type="submit" class="oa-button oa-button--primary"><?php esc_html_e( 'Save', 'octave-addons' ); ?></button>
			</form>
		</section>

		<?php

	}

}
