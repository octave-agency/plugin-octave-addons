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
	protected Octave_Addons_Admin_Experience $admin_experience;

	/** @var string[] Hook suffixes of every Octave Addons admin page. */
	protected array $page_hooks = [];

	public function __construct( Octave_Addons_Module_Manager $modules, Octave_Addons_Admin_Experience $admin_experience ) {

		$this->modules          = $modules;
		$this->admin_experience = $admin_experience;

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

		$link = sprintf(
			'<a class="oa-admin-footer-link" href="https://www.octaveagency.com/" target="_blank" rel="noopener noreferrer">%1$s<span class="dashicons dashicons-external" aria-hidden="true"></span><span class="screen-reader-text">%2$s</span></a>',
			esc_html__( 'Octave Agency', 'octave-addons' ),
			esc_html__( '(opens in a new tab)', 'octave-addons' )
		);

		return sprintf(
			'<span class="oa-admin-footer-credit">%s</span>',
			sprintf(
				/* translators: %s: Octave Agency website link. */
				__( 'Thank you for working with %s', 'octave-addons' ),
				$link
			)
		);

	}

	/*
	REGISTER MENU
	-- The top-level page is the dashboard, followed by one submenu item per
	-- admin entry, so the WordPress menu always mirrors the discovered modules.
	-- Every route shares render_page(), which reads the entry from the slug.
	---------------------------------------------------------- */

	public function register_menu(): void {

		$icon_url = OCTAVE_ADDONS_URL . 'assets/images/admin-icon.png';

		$this->page_hooks[] = add_menu_page(
			__( 'Octave Addons', 'octave-addons' ),
			__( 'Octave Addons', 'octave-addons' ),
			'manage_options',
			OCTAVE_ADDONS_SLUG,
			[ $this, 'render_page' ],
			$icon_url,
			80
		);

		// Sharing the parent slug replaces the duplicate item WordPress would
		// otherwise add at the top of the submenu.
		add_submenu_page(
			OCTAVE_ADDONS_SLUG,
			__( 'Octave Addons', 'octave-addons' ),
			__( 'Dashboard', 'octave-addons' ),
			'manage_options',
			OCTAVE_ADDONS_SLUG,
			[ $this, 'render_page' ]
		);

		foreach ( $this->modules->admin_entries() as $entry_id => $entry ) {

			$title = $this->entry_meta( $entry )['title'];

			$this->page_hooks[] = add_submenu_page(
				OCTAVE_ADDONS_SLUG,
				$title,
				$title,
				'manage_options',
				self::entry_slug( $entry_id ),
				[ $this, 'render_page' ]
			);

		}

		add_action( 'load-' . $this->page_hooks[0], [ $this, 'redirect_legacy_tab' ] );

	}

	/*
	ENTRY SLUG
	-- The admin page slug for one navigation entry.
	---------------------------------------------------------- */

	public static function entry_slug( string $entry_id ): string {

		return OCTAVE_ADDONS_SLUG . '-' . $entry_id;

	}

	/*
	ENTRY URL
	-- The admin URL for one navigation entry, or the dashboard when no entry
	-- is given.
	---------------------------------------------------------- */

	public static function entry_url( string $entry_id = '' ): string {

		$slug = '' !== $entry_id ? self::entry_slug( $entry_id ) : OCTAVE_ADDONS_SLUG;

		return add_query_arg( [ 'page' => $slug ], admin_url( 'admin.php' ) );

	}

	/*
	REDIRECT LEGACY TAB
	-- Sends ?page=octave-addons&tab={id} links to the entry's own submenu page,
	-- keeping any other query arguments, so old links and bookmarks still land
	-- on the right module and WordPress highlights its submenu item. A tab
	-- that no longer resolves stays on the dashboard.
	---------------------------------------------------------- */

	public function redirect_legacy_tab(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$entry_id  = '' !== $requested ? $this->modules->entry_id_for( $requested ) : '';

		if ( '' === $entry_id ) {

			return;

		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect.
		$args = urlencode_deep( wp_unslash( $_GET ) );

		unset( $args['page'], $args['tab'] );

		wp_safe_redirect( add_query_arg( $args, self::entry_url( $entry_id ) ) );

		exit;

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

		if ( ! in_array( $hook, $this->page_hooks, true ) ) {

			return;

		}

		$css_path = OCTAVE_ADDONS_DIR . 'assets/css/admin.css';
		$js_path  = OCTAVE_ADDONS_DIR . 'assets/js/admin.js';

		wp_enqueue_media();

		wp_enqueue_style(
			'octave-addons-admin',
			OCTAVE_ADDONS_URL . 'assets/css/admin.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : OCTAVE_ADDONS_VERSION
		);

		wp_enqueue_script(
			'octave-addons-admin',
			OCTAVE_ADDONS_URL . 'assets/js/admin.js',
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : OCTAVE_ADDONS_VERSION,
			true
		);

		wp_localize_script( 'octave-addons-admin', 'oaAdmin', [
			'enabledElsewhere'    => $this->enabled_modules_outside_current_tab(),
			'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
			'nonce'               => wp_create_nonce( 'oa_icon_picker' ),
			'breakdanceActive'    => function_exists( 'Breakdance\Icons\find_icons' ),
			'savedText'           => __( 'All changes saved', 'octave-addons' ),
			'unsavedText'         => __( 'Unsaved changes', 'octave-addons' ),
			'savingText'          => __( 'Saving changes…', 'octave-addons' ),
			'selectImageTitle'    => __( 'Choose an image', 'octave-addons' ),
			'useImageText'        => __( 'Use this image', 'octave-addons' ),
			'selectImageText'     => __( 'Select image', 'octave-addons' ),
			'replaceImageText'    => __( 'Replace image', 'octave-addons' ),
			'previewCustomLoader'     => __( 'Custom only: styled by your Loader CSS.', 'octave-addons' ),
			'previewCustomTransition' => __( 'Custom only: styled by your Transition CSS.', 'octave-addons' ),
			'previewCustomScroll'     => __( 'Custom only: animated by your CSS and JavaScript overrides.', 'octave-addons' ),
			'previewOff'              => __( 'Off: content is shown with no animation.', 'octave-addons' ),
			'previewReduced'          => __( 'Reduced motion is on, so the final frame is shown.', 'octave-addons' ),
			'searchOptionsText'   => __( 'Search options…', 'octave-addons' ),
			'confirmTitleText'    => __( 'Please confirm', 'octave-addons' ),
			'confirmActionText'   => __( 'Confirm', 'octave-addons' ),
			'cancelActionText'    => __( 'Cancel', 'octave-addons' ),
			'removePostTypeTitle' => __( 'Remove custom post type?', 'octave-addons' ),
			'removePostTypeText'  => __( 'Remove this custom post type? Existing content will remain in the database but will be hidden until its post type is registered again.', 'octave-addons' ),
			'removeActionText'    => __( 'Remove post type', 'octave-addons' ),
			'postTypeMovedText'   => __( 'Post type order updated.', 'octave-addons' ),
			'taxonomyMovedText'   => __( 'Category order updated for this post type.', 'octave-addons' ),
			'newPostTypeText'     => __( 'New post type', 'octave-addons' ),
			'newSubFieldText'     => __( 'New item field', 'octave-addons' ),
			'renameKeyTitle'         => __( 'Edit this key?', 'octave-addons' ),
			'renameKeyAction'        => __( 'Edit key', 'octave-addons' ),
			'removeDefinitionTitle'  => __( 'Remove definition?', 'octave-addons' ),
			'removeDefinitionText'   => __( 'Saved content values and terms will remain in the database, but this definition will no longer be registered.', 'octave-addons' ),
			'removeDefinitionAction' => __( 'Remove', 'octave-addons' ),
			'moduleEnabledText'      => __( 'Module enabled. Save settings to apply it.', 'octave-addons' ),
			'moduleDisabledText'     => __( 'Module disabled. Save settings to apply it.', 'octave-addons' ),
			'postTypeAddedText'      => __( 'Post type added. Save settings to create it.', 'octave-addons' ),
			'postTypeRemovedText'    => __( 'Post type removed. Save settings to apply it.', 'octave-addons' ),
			'categoryAddedText'      => __( 'Taxonomy added. Save settings to create it.', 'octave-addons' ),
			'categoryRemovedText'    => __( 'Taxonomy removed. Save settings to apply it.', 'octave-addons' ),
			'fieldAddedText'         => __( 'Content field added. Save settings to create it.', 'octave-addons' ),
			'fieldRemovedText'       => __( 'Content field removed. Save settings to apply it.', 'octave-addons' ),
			'fieldMovedText'         => __( 'Content field order updated. Save settings to apply it.', 'octave-addons' ),
			'fieldGroupedText'       => __( 'Field moved into the group. Save settings to apply it.', 'octave-addons' ),
			'subFieldAddedText'      => __( 'Item field added. Save settings to create it.', 'octave-addons' ),
			'subFieldRemovedText'    => __( 'Item field removed. Save settings to apply it.', 'octave-addons' ),
			'invalidFormText'        => __( 'Nothing was saved. Some settings still need attention — the first one has been opened for you.', 'octave-addons' ),
			'newBannerText'          => __( 'New banner', 'octave-addons' ),
			'bannerAlwaysOnText'     => __( 'Always on', 'octave-addons' ),
			/* translators: %s: start date. */
			'bannerFromText'         => __( 'From %s', 'octave-addons' ),
			/* translators: %s: end date. */
			'bannerUntilText'        => __( 'Until %s', 'octave-addons' ),
			/* translators: 1: start date, 2: end date. */
			'bannerRangeText'        => __( '%1$s to %2$s', 'octave-addons' ),
			'bannerAddedText'        => __( 'Banner added. Save settings to publish it.', 'octave-addons' ),
			'bannerRemovedText'      => __( 'Banner removed. Save settings to apply it.', 'octave-addons' ),
			'removeBannerTitle'      => __( 'Remove banner?', 'octave-addons' ),
			'removeBannerText'       => __( 'This banner and its schedule will be deleted when the settings are saved.', 'octave-addons' ),
			'removeBannerAction'     => __( 'Remove banner', 'octave-addons' ),
		] );

	}

	/*
	ENABLED MODULES OUTSIDE CURRENT TAB
	-- Counts the active modules the open page does not render, so the browser
	-- can keep the totals right while only holding one entry's toggles.
	---------------------------------------------------------- */

	protected function enabled_modules_outside_current_tab(): int {

		$active_tab = $this->current_tab();
		$count      = 0;

		foreach ( $this->modules->admin_entries() as $entry_id => $entry ) {

			if ( $entry_id === $active_tab ) {

				continue;

			}

			foreach ( $entry['modules'] as $module_id => $module ) {

				$settings = $this->modules->settings_for( $module_id );

				if ( $module->is_always_enabled() || ! empty( $settings['enabled'] ) ) {

					$count++;

				}

			}

		}

		return $count;

	}

	/*
	DEACTIVATION GUARD
	-- Loads the confirmation dialog on the Plugins screen so the plugin
	-- cannot be deactivated by a single stray click.
	-- Covers Octave Addons and the client specific plugin when installed.
	---------------------------------------------------------- */

	public function enqueue_deactivation_guard( string $hook ): void {

		if ( 'plugins.php' !== $hook ) {

			return;

		}

		$guarded = $this->get_guarded_plugins();

		if ( empty( $guarded ) ) {

			return;

		}

		$css_path = OCTAVE_ADDONS_DIR . 'assets/css/deactivate.css';
		$js_path  = OCTAVE_ADDONS_DIR . 'assets/js/deactivate.js';

		wp_enqueue_style(
			'octave-addons-deactivate',
			OCTAVE_ADDONS_URL . 'assets/css/deactivate.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : OCTAVE_ADDONS_VERSION
		);

		wp_enqueue_script(
			'octave-addons-deactivate',
			OCTAVE_ADDONS_URL . 'assets/js/deactivate.js',
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : OCTAVE_ADDONS_VERSION,
			true
		);

		wp_localize_script( 'octave-addons-deactivate', 'oaDeactivate', [
			'plugins'     => array_values( $guarded ),
			'title'       => __( 'Are you sure?', 'octave-addons' ),
			'bulkMessage' => __( 'Your selection includes plugins the site depends on. Deactivating them can break the site. Are you sure you want to continue?', 'octave-addons' ),
			'confirmText' => __( 'Yes, deactivate', 'octave-addons' ),
			'cancelText'  => __( 'Keep it active', 'octave-addons' ),
		] );

	}

	/*
	GUARDED PLUGINS
	-- Builds the list of plugins the confirmation dialog protects
	-- Only includes plugins the current user is actually able to deactivate
	---------------------------------------------------------- */

	private function get_guarded_plugins(): array {

		$guarded = [];

		if ( current_user_can( 'deactivate_plugin', OCTAVE_ADDONS_BASENAME ) ) {

			$guarded[ OCTAVE_ADDONS_BASENAME ] = [
				'basename' => OCTAVE_ADDONS_BASENAME,
				'message'  => __( 'Deactivating Octave Addons turns off every enabled module at once. Custom posts, elements, filtering and animations will stop working, and this can break the site. Are you sure you want to deactivate it?', 'octave-addons' ),
			];

		}

		$client_specific = $this->get_client_specific_basename();

		if ( $client_specific && current_user_can( 'deactivate_plugin', $client_specific ) ) {

			$guarded[ $client_specific ] = [
				'basename' => $client_specific,
				'message'  => __( 'Deactivating the Octave client specific plugin turns off the custom functionality built for this site. Post types, templates and site specific features will stop working, and this can break the site. Are you sure you want to deactivate it?', 'octave-addons' ),
			];

		}

		return $guarded;

	}

	/*
	CLIENT SPECIFIC BASENAME
	-- Finds the installed octave-client-specific plugin
	-- The main file is named per client, so the folder is what we match on
	---------------------------------------------------------- */

	private function get_client_specific_basename(): string {

		if ( ! function_exists( 'get_plugins' ) ) {

			require_once ABSPATH . 'wp-admin/includes/plugin.php';

		}

		foreach ( array_keys( get_plugins() ) as $basename ) {

			if ( 'octave-client-specific' === dirname( $basename ) ) {

				return $basename;

			}

		}

		return '';

	}

	public function print_global_admin_icon_css(): void {

		?>

		<style id="octave-addons-admin-icon-css">
			/*
			OCTAVE BUTTON ICONS
			---------------------------------------------------------- */

			body.wp-admin button[class*="oa-"] .dashicons,
			body.wp-admin a[class*="oa-"] .dashicons,
			body.wp-admin .oa-app .button .dashicons,
			body.wp-admin .oa-post-fields button .dashicons {
				line-height: 1 !important;
			}

			/*
			ADMIN MENU ICON
			---------------------------------------------------------- */

			#toplevel_page_<?= esc_html( OCTAVE_ADDONS_SLUG ); ?> .wp-menu-image img {
				width: auto;
				height: auto;
				padding: 7px 0 0;
				opacity: 1;
				box-sizing: border-box;
				max-width: 20px;
				object-fit: contain;

			}

			/*
			ADMIN MENU DASHBOARD ITEM
			-- Hidden rather than removed: WordPress links the top-level item to
			-- the first submenu entry, so it must stay registered.
			---------------------------------------------------------- */

			#toplevel_page_<?= esc_html( OCTAVE_ADDONS_SLUG ); ?> .wp-submenu .wp-first-item {
				display: none;
			}

			/*
			ADMIN FOOTER CREDIT
			---------------------------------------------------------- */

			#wpfooter #footer-left .oa-admin-footer-link {
				display: inline-flex;
				align-items: center;
				gap: 3px;
				color: #00875a;
				text-decoration: none;
			}

			#wpfooter #footer-left .oa-admin-footer-link:hover,
			#wpfooter #footer-left .oa-admin-footer-link:focus {
				color: #00d084;
				text-decoration: underline;
			}

			#wpfooter #footer-left .oa-admin-footer-link .dashicons {
				width: 13px;
				height: 13px;
				font-size: 13px;
				line-height: 13px;
			}
		</style>
		<?php

	}

	public function plugin_action_links( array $links ): array {

		$url      = self::entry_url();
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

	/*
	CURRENT TAB
	-- The entry the open page shows, read from its submenu slug, or from the
	-- legacy tab argument on the top-level page. Anything unknown falls back
	-- to the dashboard.
	---------------------------------------------------------- */

	protected function current_tab(): string {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$prefix    = OCTAVE_ADDONS_SLUG . '-';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( 0 === strpos( $page, $prefix ) ) {

			$requested = substr( $page, strlen( $prefix ) );

		}

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
			'ai-agents' => [
				'title'       => __( 'AI Agents', 'octave-addons' ),
				'description' => __( 'What an AI agent gets when it asks the site for a page. These add-ons hand agents clean, structured text instead of leaving them to scrape a dense builder layout, while every browser carries on receiving the normal HTML.', 'octave-addons' ),
				'requires'    => '',
			],
			'breakdance' => [
				'title'       => __( 'Breakdance', 'octave-addons' ),
				'description' => __( 'Everything that plugs into the Breakdance builder — AJAX filtering for post loops, default element spacing, and the custom element library.', 'octave-addons' ),
				'requires'    => 'breakdance',
			],
			'content' => [
				'title'       => __( 'Content', 'octave-addons' ),
				'description' => __( 'How content is structured and managed — custom post types and fields, comments, and spotting empty links.', 'octave-addons' ),
				'requires'    => '',
			],
			'design' => [
				'title'       => __( 'Design', 'octave-addons' ),
				'description' => __( 'How the site and the WordPress admin look — the admin refresh, the login screen, page loading and scroll animations, and the colours a visitor sees when they select text.', 'octave-addons' ),
				'requires'    => '',
			],
			'engagement' => [
				'title'       => __( 'Engagement', 'octave-addons' ),
				'description' => __( 'Ways to reach visitors on the page — a notifications bar across the site and a contact popup on mobile.', 'octave-addons' ),
				'requires'    => '',
			],
			'performance' => [
				'title'       => __( 'Performance', 'octave-addons' ),
				'description' => __( 'Compatibility-first speed tools. Every feature switches on independently, Breakdance and WordPress core assets are never delayed or rewritten, and anything that cannot be processed safely is served exactly as it was.', 'octave-addons' ),
				'requires'    => '',
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
	-- Returns the icon kit name used for a module or group on the dashboard.
	---------------------------------------------------------- */

	protected function module_icon( string $id ): string {

		$icons = [
			'ai-agents'                  => 'bot',
			'animations'                 => 'sparkles',
			'api-catalog'                => 'blocks',
			'auth-discovery'             => 'lock',
			'breakdance'                 => 'layout',
			'breakdance-ajax-filtering'  => 'filter',
			'breakdance-custom-elements' => 'blocks',
			'breakdance-lazy-load'       => 'zap',
			'custom-login'               => 'lock',
			'content'                    => 'file-text',
			'custom-post-types'          => 'layers',
			'design'                     => 'palette',
			'disable-comments'           => 'message-off',
			'empty-link-highlighter'     => 'unlink',
			'engagement'                 => 'megaphone',
			'markdown-negotiation'       => 'file-text',
			'mobile-contact-popup'       => 'smartphone',
			'notifications-bar'          => 'megaphone',
			'page-loader'                => 'page-loader',
			'performance'                => 'gauge',
		];

		return $icons[ $id ] ?? 'sliders';

	}

	public function render_page(): void {

		if ( ! current_user_can( 'manage_options' ) ) {

			return;

		}

		$all           = $this->modules->visible_in_admin();
		$entries       = $this->modules->admin_entries();
		$active_tab    = $this->current_tab();
		$icon_url      = OCTAVE_ADDONS_URL . 'assets/images/admin-icon.png';
		$dashboard_url = self::entry_url();
		$is_themed     = $this->admin_experience->is_enabled();

		$module_settings = [];

		foreach ( $all as $id => $module ) {

			$module_settings[ $id ] = $this->modules->settings_for( $id );

		}

		$module_count  = count( $all );
		$enabled_count = 0;

		foreach ( $all as $id => $module ) {

			if ( $module->is_always_enabled() || ! empty( $module_settings[ $id ]['enabled'] ) ) {

				$enabled_count++;

			}

		}

		// Settings API notices keep their WordPress styling, so they are printed
		// in their own wrap above the plugin interface rather than inside it.
		ob_start();
		settings_errors();
		$notices = trim( (string) ob_get_clean() );

		// WordPress moves every admin notice to the first heading it finds inside
		// a wrap, which would drop third-party notices into a module panel. This
		// wrap owns the first heading and closes with the core wp-header-end
		// marker, so notices always land above the interface.

		?>

		<div class="wrap oa-notices">

			<h1 class="screen-reader-text"><?php esc_html_e( 'Octave Addons', 'octave-addons' ); ?></h1>

			<?= $notices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Settings API markup, already escaped. ?>

			<hr class="wp-header-end">

		</div>

		<div class="wrap octave-addons-wrap<?= $is_themed ? ' octave-addons-wrap--themed' : ''; ?>">

			<div class="oa-app<?= $is_themed ? ' oa-app--themed' : ''; ?>">

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
							<?php Octave_Addons_Icons::render( 'grid', 16, 'oa-nav-icon' ); ?>
							<span class="oa-nav-label"><?php esc_html_e( 'Dashboard', 'octave-addons' ); ?></span>
						</a>

						<span class="oa-nav-heading"><?php esc_html_e( 'Modules', 'octave-addons' ); ?></span>
						<?php

						foreach ( $entries as $entry_id => $entry ) :

							$meta      = $this->entry_meta( $entry );
							$enabled   = $this->entry_is_enabled( $entry, $module_settings );
							$url       = self::entry_url( $entry_id );
							$is_active = ( $entry_id === $active_tab );

							if ( '' === $entry['group'] ) :

						?>

							<a href="<?= esc_url( $url ); ?>"
							   class="oa-nav-item<?= $is_active ? ' is-active' : ''; ?>"
							   data-entry="<?= esc_attr( $entry_id ); ?>">
								<span class="oa-dot <?= $enabled ? 'is-on' : 'is-off'; ?>" aria-hidden="true"></span>
								<span class="oa-nav-label"><?= esc_html( $meta['title'] ); ?></span>
							</a>

						<?php

							else :

							// A shared name makes the browser keep only one group open at a time.

						?>

							<details class="oa-nav-group" name="oa-nav-group"<?= $is_active ? ' open' : ''; ?>>
								<summary class="oa-nav-item<?= $is_active ? ' is-active' : ''; ?>"
								         data-entry="<?= esc_attr( $entry_id ); ?>">
									<span class="oa-dot <?= $enabled ? 'is-on' : 'is-off'; ?>" aria-hidden="true"></span>
									<span class="oa-nav-label"><?= esc_html( $meta['title'] ); ?></span>
									<?php Octave_Addons_Icons::render( 'chevron-down', 14, 'oa-nav-chevron' ); ?>
								</summary>

								<div class="oa-nav-sub">

									<?php

									foreach ( $entry['modules'] as $module_id => $module ) :

										$module_on = $module->is_always_enabled() || ! empty( $module_settings[ $module_id ]['enabled'] );

									?>

									<a href="<?= esc_url( $url . '#oa-panel-' . $module_id ); ?>"
									   class="oa-nav-subitem"
									   data-module="<?= esc_attr( $module_id ); ?>">
										<span class="oa-dot <?= $module_on ? 'is-on' : 'is-off'; ?>" aria-hidden="true"></span>
										<span class="oa-nav-label"><?= esc_html( $module->get_title() ); ?></span>
									</a>

									<?php

									endforeach;

									?>

								</div>
							</details>

						<?php

							endif;

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
							$url       = self::entry_url( $entry_id );
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
								$module_count
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
							<span class="oa-eyebrow"><?php esc_html_e( 'Octave Addons', 'octave-addons' ); ?></span>
							<h1><?php esc_html_e( 'Manage your site toolkit.', 'octave-addons' ); ?></h1>
							<p><?php esc_html_e( 'Review what is active, open a module to adjust its settings, and manage the WordPress admin appearance for this site.', 'octave-addons' ); ?></p>
						</div>
						<div class="oa-hero-visual" aria-hidden="true">
							<span class="oa-orbit oa-orbit-one"></span>
							<span class="oa-orbit oa-orbit-two"></span>
							<span class="oa-hero-core"><?= esc_html( (string) $module_count ); ?></span>
						</div>
						<div class="oa-hero-stats">
							<div class="oa-stat">
								<strong class="oa-enabled-count"><?= esc_html( (string) $enabled_count ); ?></strong>
								<span><?php esc_html_e( 'Active modules', 'octave-addons' ); ?></span>
							</div>
							<div class="oa-stat">
								<strong><?= esc_html( (string) $module_count ); ?></strong>
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
							$url     = self::entry_url( $entry_id );

						?>

						<a href="<?= esc_url( $url ); ?>" class="oa-module-card">
							<span class="oa-module-card-icon" aria-hidden="true">
								<?php Octave_Addons_Icons::render( $meta['icon'], 20 ); ?>
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
									<?php Octave_Addons_Icons::render( 'arrow-right', 14 ); ?>
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
						<input type="hidden" name="<?= esc_attr( OCTAVE_ADDONS_OPTION_KEY . '[' . Octave_Addons_Module_Manager::SUBMITTED_FIELD . ']' ); ?>" value="<?= esc_attr( implode( ',', array_keys( $entries[ $active_tab ]['modules'] ?? [] ) ) ); ?>">
						<?php

						foreach ( $entries as $entry_id => $entry ) :

							// Only the open entry is put in the form, so a save carries its modules alone.
							if ( $entry_id !== $active_tab ) {

								continue;

							}

							$meta     = $this->entry_meta( $entry );
							$is_group = '' !== $entry['group'];

						?>

						<div class="oa-entry" id="oa-entry-<?= esc_attr( $entry_id ); ?>">

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

							/**
							 * Fires above an entry's module panels, inside the settings form.
							 * Anything printed here must not use named inputs, or they are saved.
							 *
							 * @param string $entry_id Navigation entry id.
							 * @param array  $entry    Entry with its modules.
							 */
							do_action( 'octave_addons_render_entry_intro', $entry_id, $entry );

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
								$show_settings = $always || ! empty( $settings['enabled'] );

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
										       <?php checked( $always || ! empty( $settings['enabled'] ) ); ?>
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
