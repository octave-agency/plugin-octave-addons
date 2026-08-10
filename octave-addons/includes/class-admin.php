<?php

/*
ADMIN UI
-- Adds a top-level dashboard with quick links and one settings view per module.
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
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_deactivation_guard' ] );
		add_action( 'admin_head',            [ $this, 'print_global_admin_icon_css' ] );
		add_filter( 'admin_footer_text',      [ $this, 'admin_footer_text' ] );
		add_filter( 'plugin_action_links_' . OCTAVE_ADDONS_BASENAME, [ $this, 'plugin_action_links' ] );
		add_action( 'wp_ajax_oa_icon_sets',    [ $this, 'ajax_icon_sets' ] );
		add_action( 'wp_ajax_oa_icons_search', [ $this, 'ajax_icons_search' ] );

	}

	/*
	ADMIN FOOTER TEXT
	-- Replaces the default WordPress credit with the Octave Agency credit.
	---------------------------------------------------------- */

	public function admin_footer_text(): string {

		return sprintf(
			/* translators: %s: Octave Agency website link. */
			__( 'Thank you for working with %s', 'octave-addons' ),
			'<a href="https://www.octaveagency.com/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Octave Agency', 'octave-addons' ) . '</a>'
		);

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

		wp_enqueue_media();

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
			'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
			'nonce'               => wp_create_nonce( 'oa_icon_picker' ),
			'breakdanceActive'    => function_exists( 'Breakdance\Icons\find_icons' ),
			'savedText'           => __( 'All changes saved', 'octave-addons' ),
			'unsavedText'         => __( 'Unsaved changes', 'octave-addons' ),
			'savingText'          => __( 'Saving changes…', 'octave-addons' ),
			'selectImageTitle'    => __( 'Choose a custom logo', 'octave-addons' ),
			'useImageText'        => __( 'Use this image', 'octave-addons' ),
			'selectImageText'     => __( 'Select image', 'octave-addons' ),
			'replaceImageText'    => __( 'Replace image', 'octave-addons' ),
			'searchOptionsText'   => __( 'Search options…', 'octave-addons' ),
			'confirmTitleText'    => __( 'Please confirm', 'octave-addons' ),
			'confirmActionText'   => __( 'Confirm', 'octave-addons' ),
			'cancelActionText'    => __( 'Cancel', 'octave-addons' ),
			'removePostTypeTitle' => __( 'Remove custom post type?', 'octave-addons' ),
			'removePostTypeText'  => __( 'Remove this custom post type? Existing content will remain in the database but will be hidden until its post type is registered again.', 'octave-addons' ),
			'removeActionText'    => __( 'Remove post type', 'octave-addons' ),
			'postTypeMovedText'   => __( 'Post type order updated.', 'octave-addons' ),
			'newPostTypeText'     => __( 'New post type', 'octave-addons' ),
			'removeDefinitionTitle'  => __( 'Remove definition?', 'octave-addons' ),
			'removeDefinitionText'   => __( 'Saved content values and terms will remain in the database, but this definition will no longer be registered.', 'octave-addons' ),
			'removeDefinitionAction' => __( 'Remove', 'octave-addons' ),
		] );

	}

	/*
	DEACTIVATION GUARD
	-- Loads the confirmation dialog on the Plugins screen so the plugin
	-- cannot be deactivated by a single stray click.
	---------------------------------------------------------- */

	public function enqueue_deactivation_guard( string $hook ): void {

		if ( 'plugins.php' !== $hook || ! current_user_can( 'deactivate_plugin', OCTAVE_ADDONS_BASENAME ) ) {

			return;

		}

		$assets_dir = OCTAVE_ADDONS_DIR . 'assets/';
		$css_path   = $assets_dir . 'deactivate.css';
		$js_path    = $assets_dir . 'deactivate.js';

		wp_enqueue_style(
			'octave-addons-deactivate',
			OCTAVE_ADDONS_URL . 'assets/deactivate.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : OCTAVE_ADDONS_VERSION
		);

		wp_enqueue_script(
			'octave-addons-deactivate',
			OCTAVE_ADDONS_URL . 'assets/deactivate.js',
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : OCTAVE_ADDONS_VERSION,
			true
		);

		wp_localize_script( 'octave-addons-deactivate', 'oaDeactivate', [
			'basename'    => OCTAVE_ADDONS_BASENAME,
			'title'       => __( 'Are you sure?', 'octave-addons' ),
			'message'     => __( 'Deactivating Octave Addons turns off every enabled module at once. Custom posts, elements, filtering and animations will stop working, and this can break the site. Are you sure you want to deactivate it?', 'octave-addons' ),
			'bulkMessage' => __( 'Your selection includes Octave Addons. Deactivating it turns off every enabled module at once, and this can break the site. Are you sure you want to continue?', 'octave-addons' ),
			'confirmText' => __( 'Yes, deactivate', 'octave-addons' ),
			'cancelText'  => __( 'Keep it active', 'octave-addons' ),
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( '' === $requested ) {

			return 'dashboard';

		}

		// A module that has since joined a group resolves to the group's tab, so
		// existing links and bookmarks keep working.
		$entry = $this->modules->entry_id_for( $requested );

		return '' !== $entry ? $entry : 'dashboard';

	}

	/*
	GROUP CONFIG
	-- Presentation details for a module group: the shared page title, the copy
	-- above its panels, and the dependency the whole page needs.
	---------------------------------------------------------- */

	protected function group_config( string $group ): array {

		$groups = [
			'breakdance' => [
				'title'       => __( 'Breakdance', 'octave-addons' ),
				'description' => __( 'Everything that plugs into the Breakdance builder — AJAX filtering for post loops, and the custom element library.', 'octave-addons' ),
				'requires'    => 'breakdance',
			],
		];

		if ( isset( $groups[ $group ] ) ) {

			return $groups[ $group ];

		}

		return [
			'title'       => ucwords( str_replace( '-', ' ', $group ) ),
			'description' => '',
			'requires'    => '',
		];

	}

	/*
	ENTRY META
	-- Title, description, icon and lock state for one navigation entry,
	-- whether it is a single module or a group of them.
	---------------------------------------------------------- */

	protected function entry_meta( array $entry ): array {

		$is_group = '' !== $entry['group'];

		if ( ! $is_group ) {

			$module = reset( $entry['modules'] );

			return [
				'title'       => $module->get_title(),
				'description' => $module->get_description(),
				'icon'        => $this->module_icon( $entry['id'] ),
				'locked'      => false,
			];

		}

		$config = $this->group_config( $entry['group'] );

		return [
			'title'       => $config['title'],
			'description' => $config['description'],
			'icon'        => $this->module_icon( $entry['id'] ),
			'locked'      => 'breakdance' === $config['requires'] && ! Octave_Addons::is_breakdance_active(),
		];

	}

	/*
	ENTRY IS ENABLED
	-- An entry counts as active when any module inside it is enabled.
	---------------------------------------------------------- */

	protected function entry_is_enabled( array $entry, array $module_settings ): bool {

		foreach ( $entry['modules'] as $id => $module ) {

			if ( ! empty( $module_settings[ $id ]['enabled'] ) ) {

				return true;

			}

		}

		return false;

	}

	/*
	MODULE ICON
	-- Returns the Dashicons class used for a module or group on the dashboard.
	---------------------------------------------------------- */

	protected function module_icon( string $id ): string {

		$icons = [
			'animations'                    => 'dashicons-image-rotate',
			'breakdance'                    => 'dashicons-layout',
			'breakdance-ajax-filtering'     => 'dashicons-filter',
			'breakdance-custom-elements'    => 'dashicons-layout',
			'custom-login'                  => 'dashicons-lock',
			'custom-post-types'             => 'dashicons-admin-post',
			'disable-comments'              => 'dashicons-no-alt',
			'empty-link-highlighter'        => 'dashicons-warning',
			'mobile-contact-popup'          => 'dashicons-smartphone',
		];

		return $icons[ $id ] ?? 'dashicons-admin-generic';

	}

	public function render_page(): void {

		if ( ! current_user_can( 'manage_options' ) ) {

			return;

		}

		$all           = $this->modules->visible_in_admin();
		$entries       = $this->modules->admin_entries();
		$active_tab    = $this->current_tab();
		$icon_url      = OCTAVE_ADDONS_URL . 'assets/admin-icon.png';
		$dashboard_url = add_query_arg( [ 'page' => OCTAVE_ADDONS_SLUG ], admin_url( 'admin.php' ) );

		$module_settings = [];

		foreach ( $all as $id => $module ) {

			$module_settings[ $id ] = $this->modules->settings_for( $id );

		}

		// Counts follow navigation entries, so a grouped page reads as one item
		// rather than as the modules hidden inside it.
		$entry_count   = count( $entries );
		$enabled_count = 0;

		foreach ( $entries as $entry ) {

			if ( $this->entry_is_enabled( $entry, $module_settings ) ) {

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

					<nav class="oa-nav" aria-label="<?php esc_attr_e( 'Octave Addons navigation', 'octave-addons' ); ?>">
						<a href="<?= esc_url( $dashboard_url ); ?>"
						   class="oa-nav-item oa-dashboard-nav-item<?= 'dashboard' === $active_tab ? ' is-active' : ''; ?>">
							<span class="dashicons dashicons-dashboard oa-nav-icon" aria-hidden="true"></span>
							<span class="oa-nav-label"><?php esc_html_e( 'Dashboard', 'octave-addons' ); ?></span>
						</a>

						<span class="oa-nav-heading"><?php esc_html_e( 'Modules', 'octave-addons' ); ?></span>
						<?php

						foreach ( $entries as $entry_id => $entry ) :
							$meta      = $this->entry_meta( $entry );
							$enabled   = $this->entry_is_enabled( $entry, $module_settings );
							$url       = add_query_arg( [ 'page' => OCTAVE_ADDONS_SLUG, 'tab' => $entry_id ], admin_url( 'admin.php' ) );
							$is_active = ( $entry_id === $active_tab );
						?>

							<a href="<?= esc_url( $url ); ?>"
							   class="oa-nav-item<?= $is_active ? ' is-active' : ''; ?>"
							   data-entry="<?= esc_attr( $entry_id ); ?>">
								<span class="oa-dot <?= $enabled ? 'is-on' : 'is-off'; ?>" aria-hidden="true"></span>
								<span class="oa-nav-label"><?= esc_html( $meta['title'] ); ?></span>
							</a>
						<?php

						endforeach;

						?>

					</nav>

					<select class="oa-nav-select" aria-label="<?php esc_attr_e( 'Navigate modules', 'octave-addons' ); ?>">
						<option value="<?= esc_url( $dashboard_url ); ?>"<?php selected( 'dashboard' === $active_tab ); ?>>
							<?php esc_html_e( 'Dashboard', 'octave-addons' ); ?>
						</option>
						<?php

						foreach ( $entries as $entry_id => $entry ) :
							$meta      = $this->entry_meta( $entry );
							$url       = add_query_arg( [ 'page' => OCTAVE_ADDONS_SLUG, 'tab' => $entry_id ], admin_url( 'admin.php' ) );
							$is_active = ( $entry_id === $active_tab );
						?>

							<option value="<?= esc_url( $url ); ?>"<?php selected( $is_active ); ?>>
								<?= esc_html( $meta['title'] ); ?>
							</option>
						<?php

						endforeach;

						?>

					</select>

					<div class="oa-sidebar-footer">
						<span class="oa-sidebar-status" aria-hidden="true"></span>
						<span>
							<strong class="oa-enabled-count"><?= esc_html( (string) $enabled_count ); ?></strong>
							<?php

							printf(
								/* translators: %d: total number of modules. */
								esc_html__( 'of %d modules active', 'octave-addons' ),
								$entry_count
							);

							?>
						</span>
					</div>

				</aside>

				<div class="oa-content">
					<?php

					if ( 'dashboard' === $active_tab ) :

					?>

					<section class="oa-hero">
						<div class="oa-hero-copy">
							<span class="oa-eyebrow"><?php esc_html_e( 'Octave site toolkit', 'octave-addons' ); ?></span>
							<h1><?php esc_html_e( 'Shape a better WordPress experience.', 'octave-addons' ); ?></h1>
							<p><?php esc_html_e( 'Activate focused enhancements, tune their behaviour, and keep every site capability organised in one place.', 'octave-addons' ); ?></p>
						</div>
						<div class="oa-hero-visual" aria-hidden="true">
							<span class="oa-orbit oa-orbit-one"></span>
							<span class="oa-orbit oa-orbit-two"></span>
							<span class="oa-hero-core"><?= esc_html( (string) $entry_count ); ?></span>
						</div>
						<div class="oa-hero-stats">
							<div class="oa-stat">
								<strong class="oa-enabled-count"><?= esc_html( (string) $enabled_count ); ?></strong>
								<span><?php esc_html_e( 'Active modules', 'octave-addons' ); ?></span>
							</div>
							<div class="oa-stat">
								<strong><?= esc_html( (string) $entry_count ); ?></strong>
								<span><?php esc_html_e( 'Available tools', 'octave-addons' ); ?></span>
							</div>
							<div class="oa-stat oa-stat-status">
								<strong><span class="oa-live-dot"></span><?php esc_html_e( 'Ready', 'octave-addons' ); ?></strong>
								<span><?php esc_html_e( 'Configuration status', 'octave-addons' ); ?></span>
							</div>
						</div>
					</section>

					<div class="oa-dashboard-heading">
						<div>
							<span class="oa-panel-kicker"><?php esc_html_e( 'Quick access', 'octave-addons' ); ?></span>
							<h2><?php esc_html_e( 'Addon settings', 'octave-addons' ); ?></h2>
						</div>
						<p><?php esc_html_e( 'Choose an addon to review its status and configuration.', 'octave-addons' ); ?></p>
					</div>

					<?php

					if ( empty( $all ) ) :

					?>

						<div class="notice notice-warning inline">
							<p><?php esc_html_e( 'No modules found. Drop a folder into /modules/ with a class-module.php file to add one.', 'octave-addons' ); ?></p>
						</div>

					<?php

					else :

					?>

					<div class="oa-module-grid">
						<?php

						foreach ( $entries as $entry_id => $entry ) :

							$meta    = $this->entry_meta( $entry );
							$enabled = $this->entry_is_enabled( $entry, $module_settings );
							$url     = add_query_arg( [ 'page' => OCTAVE_ADDONS_SLUG, 'tab' => $entry_id ], admin_url( 'admin.php' ) );

						?>

						<a href="<?= esc_url( $url ); ?>" class="oa-module-card">
							<span class="oa-module-card-icon" aria-hidden="true">
								<span class="dashicons <?= esc_attr( $meta['icon'] ); ?>"></span>
							</span>
							<span class="oa-module-card-copy">
								<strong><?= esc_html( $meta['title'] ); ?></strong>
								<span><?= esc_html( $meta['description'] ); ?></span>
							</span>
							<span class="oa-module-card-footer">
								<span class="oa-module-card-status <?= $enabled ? 'is-on' : 'is-off'; ?>">
									<span class="oa-dot <?= $enabled ? 'is-on' : 'is-off'; ?>" aria-hidden="true"></span>
									<?= $enabled ? esc_html__( 'Enabled', 'octave-addons' ) : esc_html__( 'Disabled', 'octave-addons' ); ?>
								</span>
								<span class="oa-module-card-link">
									<?php esc_html_e( 'Open settings', 'octave-addons' ); ?>
									<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
								</span>
							</span>
						</a>

						<?php

						endforeach;

						?>
					</div>

					<?php

					endif;

					else :

						settings_errors();

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

						foreach ( $entries as $entry_id => $entry ) :

							$meta      = $this->entry_meta( $entry );
							$is_group  = '' !== $entry['group'];
							$is_active = ( $entry_id === $active_tab );

						?>

						<div class="oa-entry<?= $is_active ? '' : ' oa-hidden'; ?>" id="oa-entry-<?= esc_attr( $entry_id ); ?>">

							<?php

							if ( $is_group ) :

							?>

							<div class="oa-entry-head">
								<span class="oa-panel-kicker"><?php esc_html_e( 'Module group', 'octave-addons' ); ?></span>
								<h2 class="oa-entry-title"><?= esc_html( $meta['title'] ); ?></h2>
								<p class="oa-entry-desc"><?= esc_html( $meta['description'] ); ?></p>
							</div>

							<?php

							endif;

							if ( $meta['locked'] ) :

							?>

							<div class="notice notice-error inline oa-inline-notice">
								<p><strong><?php esc_html_e( 'Breakdance is not installed or active.', 'octave-addons' ); ?></strong></p>
								<p><?php esc_html_e( 'These settings are locked and nothing on this page runs until Breakdance is available. Saved values are kept exactly as they are.', 'octave-addons' ); ?></p>
							</div>

							<?php

							endif;

							foreach ( $entry['modules'] as $id => $module ) :

								$settings      = $module_settings[ $id ];
								$always        = $module->is_always_enabled();
								$configurable  = $module->settings_available_when_disabled();
								$show_settings = ! empty( $settings['enabled'] ) || $configurable;

							?>

							<div class="oa-panel" id="oa-panel-<?= esc_attr( $id ); ?>">
								<div class="oa-panel-head">
									<div class="oa-panel-head-text">
										<span class="oa-panel-kicker"><?php esc_html_e( 'Module settings', 'octave-addons' ); ?></span>
										<h2 class="oa-panel-title"><?= esc_html( $module->get_title() ); ?></h2>
										<p class="oa-panel-desc"><?= esc_html( $module->get_description() ); ?></p>
									</div>
									<div class="oa-enable-wrap">
										<span class="oa-enable-label">
											<?= $always ? esc_html__( 'Always on', 'octave-addons' ) : esc_html__( 'Enable', 'octave-addons' ); ?>
										</span>
										<label class="oa-switch<?= $always ? ' oa-switch--always' : ''; ?>">
											<input type="checkbox"
											       class="oa-enable-toggle"
											       id="<?= esc_attr( 'oa-' . $id . '-enabled' ); ?>"
											       name="<?= esc_attr( OCTAVE_ADDONS_OPTION_KEY . '[' . $id . '][enabled]' ); ?>"
											       value="1"
											       data-panel="oa-panel-<?= esc_attr( $id ); ?>"
										       data-module="<?= esc_attr( $id ); ?>"
										       data-entry="<?= esc_attr( $entry_id ); ?>"
										       data-settings-always-visible="<?= $configurable ? 'true' : 'false'; ?>"
											       <?php checked( ! empty( $settings['enabled'] ) ); ?>
											       <?php disabled( $always ); ?>>
											<span class="oa-switch-slider"></span>
										</label>
									</div>
								</div>

								<div class="oa-settings-body<?= $show_settings ? '' : ' oa-hidden'; ?><?= $meta['locked'] ? ' oa-locked' : ''; ?>"<?= $meta['locked'] ? ' inert' : ''; ?>>
									<?php $module->render_settings( $settings ); ?>
								</div>

								<div class="oa-settings-locked<?= $show_settings ? ' oa-hidden' : ''; ?>">
									<p><?php esc_html_e( 'Enable this add-on to configure its settings.', 'octave-addons' ); ?></p>
								</div>

							</div>

							<?php

							endforeach;

							?>

						</div>
						<?php

						endforeach;

						?>

						<div class="oa-save-bar">
							<div class="oa-save-state" role="status" aria-live="polite">
								<span class="oa-save-state-dot" aria-hidden="true"></span>
								<span class="oa-save-state-text"><?php esc_html_e( 'All changes saved', 'octave-addons' ); ?></span>
							</div>
							<button type="submit" name="submit" class="button button-primary oa-save-button">
								<span class="oa-save-button-label"><?php esc_html_e( 'Save settings', 'octave-addons' ); ?></span>
							</button>
						</div>

					</form>

					<?php

					endif;

					endif;

					?>

				</div><!-- .oa-content -->

			</div><!-- .oa-app -->

		</div><!-- .wrap -->
		<?php

	}

}
