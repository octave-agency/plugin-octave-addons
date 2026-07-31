<?php

/*
ADMIN UI
-- Adds a top-level "Octave Addons" menu entry with one tab per module.
-- Each module owns its own tab, its own settings section, and is toggled
-- on/off independently. The UI is generated dynamically from whatever
-- modules are discovered, so new modules appear automatically.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Admin {

	protected Octave_Addons_Module_Manager $modules;

	public function __construct( Octave_Addons_Module_Manager $modules ) {

		$this->modules = $modules;

		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_head',            [ $this, 'print_global_admin_icon_css' ] );
		add_filter( 'plugin_action_links_' . OCTAVE_ADDONS_BASENAME, [ $this, 'plugin_action_links' ] );
		add_action( 'wp_ajax_oa_icon_sets',    [ $this, 'ajax_icon_sets' ] );
		add_action( 'wp_ajax_oa_icons_search', [ $this, 'ajax_icons_search' ] );

	}

	public function register_menu(): void {

		$icon_url = OCTAVE_ADDONS_URL . 'assets/admin-icon.png';

		add_menu_page(
			__( 'Octave Addons', 'octave-addons' ),
			__( 'Octave Addons', 'octave-addons' ),
			'manage_options',
			OCTAVE_ADDONS_SLUG,
			[ $this, 'render_page' ],
			$icon_url,
			80
		);

	}

	public function register_settings(): void {

		register_setting(
			'octave_addons_settings_group',
			OCTAVE_ADDONS_OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this->modules, 'sanitize_all' ],
				'default'           => [],
			]
		);

	}

	public function enqueue_admin_assets( string $hook ): void {

		if ( 'toplevel_page_' . OCTAVE_ADDONS_SLUG !== $hook ) {

			return;

		}
		$assets_dir = OCTAVE_ADDONS_DIR . 'assets/';
		$css_path   = $assets_dir . 'admin.css';
		$js_path    = $assets_dir . 'admin.js';

		wp_enqueue_style(
			'octave-addons-admin',
			OCTAVE_ADDONS_URL . 'assets/admin.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : OCTAVE_ADDONS_VERSION
		);

		wp_enqueue_script(
			'octave-addons-admin',
			OCTAVE_ADDONS_URL . 'assets/admin.js',
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : OCTAVE_ADDONS_VERSION,
			true
		);

		wp_localize_script( 'octave-addons-admin', 'oaAdmin', [
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'oa_icon_picker' ),
			'breakdanceActive' => function_exists( 'Breakdance\Icons\find_icons' ),
			'savedText'        => __( 'All changes saved', 'octave-addons' ),
			'unsavedText'      => __( 'Unsaved changes', 'octave-addons' ),
			'savingText'       => __( 'Saving changes…', 'octave-addons' ),
		] );

	}

	public function print_global_admin_icon_css(): void {

		?>

		<style id="octave-addons-admin-icon-css">
			#toplevel_page_<?= esc_html( OCTAVE_ADDONS_SLUG ); ?> .wp-menu-image img {
				width: auto;
				height: auto;
				padding: 7px 0 0;
				opacity: 1;
				box-sizing: border-box;
				max-width: 20px;
				object-fit: contain;

			}
		</style>
		<?php

	}

	public function plugin_action_links( array $links ): array {

		$url      = admin_url( 'admin.php?page=' . OCTAVE_ADDONS_SLUG );
		$settings = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'octave-addons' ) );
		array_unshift( $links, $settings );
		return $links;

	}

	public function ajax_icon_sets(): void {

		check_ajax_referer( 'oa_icon_picker' );
		if ( ! current_user_can( 'manage_options' ) ) {

			wp_die( '', '', [ 'response' => 403 ] );

		}
		if ( ! function_exists( 'Breakdance\Icons\get_icon_sets' ) ) {

			wp_send_json_error( [ 'message' => 'Breakdance not active' ] );

		}
		wp_send_json_success( \Breakdance\Icons\get_icon_sets() );

	}

	public function ajax_icons_search(): void {

		check_ajax_referer( 'oa_icon_picker' );
		if ( ! current_user_can( 'manage_options' ) ) {

			wp_die( '', '', [ 'response' => 403 ] );

		}
		if ( ! function_exists( 'Breakdance\Icons\find_icons' ) ) {

			wp_send_json_error( [ 'message' => 'Breakdance not active' ] );

		}
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$set    = isset( $_POST['set'] )    ? sanitize_text_field( wp_unslash( $_POST['set'] ) )    : '';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		wp_send_json_success(
			\Breakdance\Icons\find_icons(
				[
					'search_term'   => $search ?: null,
					'icon_set_slug' => $set ?: null,
					'offset'        => $offset,
				],
				48
			)
		);

	}

	protected function current_tab(): string {

		$all = $this->modules->visible_in_admin();
		if ( empty( $all ) ) {

			return '';

		}

		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( $requested && isset( $all[ $requested ] ) ) {

			return $requested;

		}

		return (string) array_key_first( $all );

	}

	public function render_page(): void {

		if ( ! current_user_can( 'manage_options' ) ) {

			return;

		}

		$all        = $this->modules->visible_in_admin();
		$active_tab = $this->current_tab();
		$icon_url   = OCTAVE_ADDONS_URL . 'assets/admin-icon.png';

		$module_settings = [];
		$enabled_count   = 0;

		foreach ( $all as $id => $module ) {

			$module_settings[ $id ] = $this->modules->settings_for( $id );

			if ( ! empty( $module_settings[ $id ]['enabled'] ) ) {

				$enabled_count++;

			}

		}

		?>

		<div class="wrap octave-addons-wrap">

			<div class="oa-app">

				<aside class="oa-sidebar">
					<div class="oa-sidebar-brand">
						<span class="oa-brand-mark">
							<img src="<?= esc_url( $icon_url ); ?>" alt="" class="oa-brand-icon">
						</span>
						<div class="oa-brand-info">
							<span class="oa-brand-name"><?php esc_html_e( 'Octave Addons', 'octave-addons' ); ?></span>
							<span class="oa-brand-version"><?php esc_html_e( 'Site toolkit', 'octave-addons' ); ?> · v<?= esc_html( OCTAVE_ADDONS_VERSION ); ?></span>
						</div>
					</div>

					<?php

					if ( ! empty( $all ) ) :

					?>

					<nav class="oa-nav" aria-label="<?php esc_attr_e( 'Modules', 'octave-addons' ); ?>">
						<span class="oa-nav-heading"><?php esc_html_e( 'Modules', 'octave-addons' ); ?></span>
						<?php

						foreach ( $all as $id => $module ) :
							$settings  = $module_settings[ $id ];
							$enabled   = ! empty( $settings['enabled'] );
							$url       = add_query_arg( [ 'page' => OCTAVE_ADDONS_SLUG, 'tab' => $id ], admin_url( 'admin.php' ) );
							$is_active = ( $id === $active_tab );
						?>

							<a href="<?= esc_url( $url ); ?>"
							   class="oa-nav-item<?= $is_active ? ' is-active' : ''; ?>"
							   data-module="<?= esc_attr( $id ); ?>">
								<span class="oa-dot <?= $enabled ? 'is-on' : 'is-off'; ?>" aria-hidden="true"></span>
								<span class="oa-nav-label"><?= esc_html( $module->get_title() ); ?></span>
							</a>
						<?php

						endforeach;

						?>

					</nav>

					<select class="oa-nav-select" aria-label="<?php esc_attr_e( 'Navigate modules', 'octave-addons' ); ?>">
						<?php

						foreach ( $all as $id => $module ) :
							$url       = add_query_arg( [ 'page' => OCTAVE_ADDONS_SLUG, 'tab' => $id ], admin_url( 'admin.php' ) );
							$is_active = ( $id === $active_tab );
						?>

							<option value="<?= esc_url( $url ); ?>"<?php selected( $is_active ); ?>>
								<?= esc_html( $module->get_title() ); ?>
							</option>
						<?php

						endforeach;

						?>

					</select>
					<?php

					endif;

					?>

					<div class="oa-sidebar-footer">
						<span class="oa-sidebar-status" aria-hidden="true"></span>
						<span>
							<strong class="oa-enabled-count"><?= esc_html( (string) $enabled_count ); ?></strong>
							<?php

							printf(
								/* translators: %d: total number of modules. */
								esc_html__( 'of %d modules active', 'octave-addons' ),
								count( $all )
							);

							?>
						</span>
					</div>

				</aside>

				<div class="oa-content">
					<section class="oa-hero">
						<div class="oa-hero-copy">
							<span class="oa-eyebrow"><?php esc_html_e( 'Octave site toolkit', 'octave-addons' ); ?></span>
							<h1><?php esc_html_e( 'Shape a better WordPress experience.', 'octave-addons' ); ?></h1>
							<p><?php esc_html_e( 'Activate focused enhancements, tune their behaviour, and keep every site capability organised in one place.', 'octave-addons' ); ?></p>
						</div>
						<div class="oa-hero-stats">
							<div class="oa-stat">
								<strong class="oa-enabled-count"><?= esc_html( (string) $enabled_count ); ?></strong>
								<span><?php esc_html_e( 'Active modules', 'octave-addons' ); ?></span>
							</div>
							<div class="oa-stat">
								<strong><?= esc_html( (string) count( $all ) ); ?></strong>
								<span><?php esc_html_e( 'Available tools', 'octave-addons' ); ?></span>
							</div>
							<div class="oa-stat oa-stat-status">
								<strong><span class="oa-live-dot"></span><?php esc_html_e( 'Ready', 'octave-addons' ); ?></strong>
								<span><?php esc_html_e( 'Configuration status', 'octave-addons' ); ?></span>
							</div>
						</div>
					</section>

					<?php settings_errors(); ?>
					<?php

					if ( empty( $all ) ) :

					?>

						<div class="notice notice-warning inline">
							<p><?php esc_html_e( 'No modules found. Drop a folder into /modules/ with a class-module.php file to add one.', 'octave-addons' ); ?></p>
						</div>

					<?php

					else :

					?>

					<form method="post" action="options.php" class="oa-form">
						<?php settings_fields( 'octave_addons_settings_group' ); ?>
						<?php

						foreach ( $all as $id => $module ) :
							$settings  = $module_settings[ $id ];
							$is_active = ( $id === $active_tab );
						?>

							<div class="oa-panel<?= $is_active ? '' : ' oa-hidden'; ?>" id="oa-panel-<?= esc_attr( $id ); ?>">
								<div class="oa-panel-head">
									<div class="oa-panel-head-text">
										<span class="oa-panel-kicker"><?php esc_html_e( 'Module settings', 'octave-addons' ); ?></span>
										<h2 class="oa-panel-title"><?= esc_html( $module->get_title() ); ?></h2>
										<p class="oa-panel-desc"><?= esc_html( $module->get_description() ); ?></p>
									</div>
									<div class="oa-enable-wrap">
										<span class="oa-enable-label"><?php esc_html_e( 'Enable', 'octave-addons' ); ?></span>
										<label class="oa-switch">
											<input type="checkbox"
											       class="oa-enable-toggle"
											       id="<?= esc_attr( 'oa-' . $id . '-enabled' ); ?>"
											       name="<?= esc_attr( OCTAVE_ADDONS_OPTION_KEY . '[' . $id . '][enabled]' ); ?>"
											       value="1"
											       data-panel="oa-panel-<?= esc_attr( $id ); ?>"
											       data-module="<?= esc_attr( $id ); ?>"
											       <?php checked( ! empty( $settings['enabled'] ) ); ?>>
											<span class="oa-switch-slider"></span>
										</label>
									</div>
								</div>

								<div class="oa-settings-body<?= empty( $settings['enabled'] ) ? ' oa-hidden' : ''; ?>">
									<?php $module->render_settings( $settings ); ?>
								</div>

								<div class="oa-settings-locked<?= ! empty( $settings['enabled'] ) ? ' oa-hidden' : ''; ?>">
									<p><?php esc_html_e( 'Enable this add-on to configure its settings.', 'octave-addons' ); ?></p>
								</div>

							</div>
						<?php

						endforeach;

						?>

						<div class="oa-save-bar">
							<div class="oa-save-state" role="status" aria-live="polite">
								<span class="oa-save-state-dot" aria-hidden="true"></span>
								<span class="oa-save-state-text"><?php esc_html_e( 'All changes saved', 'octave-addons' ); ?></span>
							</div>
							<?php submit_button( __( 'Save settings', 'octave-addons' ), 'primary', 'submit', false ); ?>
						</div>

					</form>

					<?php

					endif;

					?>

				</div><!-- .oa-content -->

			</div><!-- .oa-app -->

		</div><!-- .wrap -->
		<?php

	}

}
