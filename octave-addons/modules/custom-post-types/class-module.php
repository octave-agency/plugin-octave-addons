<?php

/*
MODULE: CUSTOM POST TYPES
-- Registers optional content types managed from the Octave Addons dashboard
-- Landing Pages behave like pages and use root-level site URLs
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Custom_Post_Types extends Octave_Addons_Module {

	protected const LANDING_PAGE_POST_TYPE = 'octave_landing_page';
	protected const CASE_STUDY_POST_TYPE    = 'octave_case_study';
	protected const CASE_STUDY_TAXONOMY     = 'octave_case_category';

	protected string $landing_page_slug = '';

	/*
	CONSTRUCTOR
	-- Refreshes rewrite rules only when custom post type routing changes
	---------------------------------------------------------- */

	public function __construct() {

		add_action( 'init', [ $this, 'maybe_refresh_rewrite_rules' ], 99 );

	}

	/*
	GET ID
	-- Returns the module settings key
	---------------------------------------------------------- */

	public function get_id(): string {

		return 'custom-post-types';

	}

	/*
	GET TITLE
	-- Returns the admin navigation label
	---------------------------------------------------------- */

	public function get_title(): string {

		return __( 'Custom Post Types', 'octave-addons' );

	}

	/*
	GET DESCRIPTION
	-- Describes the optional site content types
	---------------------------------------------------------- */

	public function get_description(): string {

		return __( 'Create purpose-built content areas with clean URLs and the familiar WordPress editing experience.', 'octave-addons' );

	}

	/*
	GET DEFAULTS
	-- Enables the settings area and Landing Pages for new installations
	---------------------------------------------------------- */

	public function get_defaults(): array {

		return [
			'enabled'                 => true,
			'landing_pages'           => true,
			'landing_page_slug'       => '',
			'case_studies'            => true,
			'case_study_post_slug'    => 'case-study',
			'case_study_archive_slug' => 'case-studies',
			'case_study_categories'   => true,
		];

	}

	/*
	SANITIZE
	-- Converts the module switches into stored booleans
	---------------------------------------------------------- */

	public function sanitize( $input ): array {

		return [
			'enabled'                 => ! empty( $input['enabled'] ),
			'landing_pages'           => ! empty( $input['landing_pages'] ),
			'landing_page_slug'       => sanitize_title( wp_unslash( (string) ( $input['landing_page_slug'] ?? '' ) ) ),
			'case_studies'            => ! empty( $input['case_studies'] ),
			'case_study_post_slug'    => self::sanitize_rewrite_slug( $input['case_study_post_slug'] ?? '', 'case-study' ),
			'case_study_archive_slug' => self::sanitize_rewrite_slug( $input['case_study_archive_slug'] ?? '', 'case-studies' ),
			'case_study_categories'   => ! empty( $input['case_study_categories'] ),
		];

	}

	/*
	RENDER SETTINGS
	-- Displays the available custom post types and their URL behaviour
	---------------------------------------------------------- */

	public function render_settings( array $settings ): void {

		$landing_page_slug = sanitize_title( (string) ( $settings['landing_page_slug'] ?? '' ) );
		$landing_page_path = '' !== $landing_page_slug ? $landing_page_slug . '/campaign-name' : 'campaign-name';
		$example_path      = user_trailingslashit( $landing_page_path, 'single' );
		$example_url       = home_url( '/' . ltrim( $example_path, '/' ) );

		$case_study_post_slug    = self::sanitize_rewrite_slug( $settings['case_study_post_slug'] ?? '', 'case-study' );
		$case_study_archive_slug = self::sanitize_rewrite_slug( $settings['case_study_archive_slug'] ?? '', 'case-studies' );
		$case_study_path         = user_trailingslashit( $case_study_post_slug . '/example-project', 'single' );
		$case_study_archive_path = user_trailingslashit( $case_study_archive_slug, 'post_type_archive' );
		$case_study_url          = home_url( '/' . ltrim( $case_study_path, '/' ) );
		$case_study_archive_url  = home_url( '/' . ltrim( $case_study_archive_path, '/' ) );

		?>

			<div class="notice notice-info inline oa-inline-notice">
				<p><strong><?php esc_html_e( 'Flexible Landing Page URLs', 'octave-addons' ); ?></strong></p>
			<p>
				<?php esc_html_e( 'Landing Pages use the same editor capabilities as Pages and are available to visual builders such as Breakdance.', 'octave-addons' ); ?>
			</p>
		</div>

		<table class="form-table oa-form-table" role="presentation">

			<?php

			Octave_Addons_Fields::section(
				[
					'label' => __( 'Available post types', 'octave-addons' ),
					'first' => true,
				]
			);

			Octave_Addons_Fields::row(
				[
					'label' => __( 'Landing Pages', 'octave-addons' ),
					'field' => function () use ( $settings, $example_url ) {

						Octave_Addons_Fields::switch_field(
							[
								'name'    => $this->field_name( 'landing_pages' ),
								'checked' => ! empty( $settings['landing_pages'] ),
								'data'    => [ 'controls-row' => 'oaCptLandingPageSlug' ],
								'help'    => __( 'Adds a page-style content area for campaign and conversion-focused pages.', 'octave-addons' ),
							]
						);

						?>

						<span class="oa-help">
							<?php esc_html_e( 'Example URL:', 'octave-addons' ); ?>
							<code><?= esc_html( $example_url ); ?></code>
						</span>

						<?php

					},
				]
			);

			Octave_Addons_Fields::row(
				[
					'id'    => 'oaCptLandingPageSlug',
					'for'   => $this->field_id( 'landing_page_slug' ),
					'label' => __( 'Landing Page slug', 'octave-addons' ),
					'field' => function () use ( $landing_page_slug, $example_url ) {

						Octave_Addons_Fields::text(
							[
								'id'          => $this->field_id( 'landing_page_slug' ),
								'name'        => $this->field_name( 'landing_page_slug' ),
								'value'       => $landing_page_slug,
								'placeholder' => __( 'Leave blank for root-level URLs', 'octave-addons' ),
								'help'        => __( 'Optional URL prefix. Landing Pages do not use a category taxonomy.', 'octave-addons' ),
							]
						);

						?>

						<span class="oa-help">
							<?php esc_html_e( 'Example URL:', 'octave-addons' ); ?>
							<code><?= esc_html( $example_url ); ?></code>
						</span>

						<?php

					},
				]
			);

			Octave_Addons_Fields::section( [ 'label' => __( 'Case studies', 'octave-addons' ) ] );

			Octave_Addons_Fields::row(
				[
					'label' => __( 'Case Studies', 'octave-addons' ),
					'field' => function () use ( $settings ) {

						Octave_Addons_Fields::switch_field(
							[
								'name'    => $this->field_name( 'case_studies' ),
								'checked' => ! empty( $settings['case_studies'] ),
								'data'    => [
									'controls-row' => 'oaCptCaseStudyPostSlug,oaCptCaseStudyArchiveSlug,oaCptCaseStudyCategories',
								],
								'help'    => __( 'Adds a public Case Studies content area with configurable single and archive URLs.', 'octave-addons' ),
							]
						);

					},
				]
			);

			Octave_Addons_Fields::row(
				[
					'id'    => 'oaCptCaseStudyPostSlug',
					'for'   => $this->field_id( 'case_study_post_slug' ),
					'label' => __( 'Post slug', 'octave-addons' ),
					'field' => function () use ( $case_study_post_slug, $case_study_url ) {

						Octave_Addons_Fields::text(
							[
								'id'          => $this->field_id( 'case_study_post_slug' ),
								'name'        => $this->field_name( 'case_study_post_slug' ),
								'value'       => $case_study_post_slug,
								'placeholder' => 'case-study',
								'help'        => __( 'URL prefix used before an individual Case Study name.', 'octave-addons' ),
							]
						);

						?>

						<span class="oa-help">
							<?php esc_html_e( 'Example URL:', 'octave-addons' ); ?>
							<code><?= esc_html( $case_study_url ); ?></code>
						</span>

						<?php

					},
				]
			);

			Octave_Addons_Fields::row(
				[
					'id'    => 'oaCptCaseStudyArchiveSlug',
					'for'   => $this->field_id( 'case_study_archive_slug' ),
					'label' => __( 'Archive slug', 'octave-addons' ),
					'field' => function () use ( $case_study_archive_slug, $case_study_archive_url ) {

						Octave_Addons_Fields::text(
							[
								'id'          => $this->field_id( 'case_study_archive_slug' ),
								'name'        => $this->field_name( 'case_study_archive_slug' ),
								'value'       => $case_study_archive_slug,
								'placeholder' => 'case-studies',
								'help'        => __( 'URL used for the Case Studies archive and its pagination.', 'octave-addons' ),
							]
						);

						?>

						<span class="oa-help">
							<?php esc_html_e( 'Archive URL:', 'octave-addons' ); ?>
							<code><?= esc_html( $case_study_archive_url ); ?></code>
						</span>

						<?php

					},
				]
			);

			Octave_Addons_Fields::row(
				[
					'id'    => 'oaCptCaseStudyCategories',
					'label' => __( 'Case Study Categories', 'octave-addons' ),
					'field' => function () use ( $settings ) {

						Octave_Addons_Fields::switch_field(
							[
								'name'    => $this->field_name( 'case_study_categories' ),
								'checked' => ! empty( $settings['case_study_categories'] ),
								'help'    => __( 'Adds a hierarchical category taxonomy to Case Studies.', 'octave-addons' ),
							]
						);

					},
				]
			);

			?>

		</table>

		<?php

	}

	/*
	RUN
	-- Registers enabled content types during WordPress initialization
	---------------------------------------------------------- */

	public function run( array $settings ): void {

		if ( ! empty( $settings['landing_pages'] ) ) {

			$this->landing_page_slug = sanitize_title( (string) ( $settings['landing_page_slug'] ?? '' ) );

			$this->register_landing_pages( $settings );

			add_filter( 'post_type_link', [ $this, 'filter_landing_page_link' ], 10, 4 );
			add_filter( 'request', [ $this, 'resolve_landing_page_request' ] );

		}

		if ( ! empty( $settings['case_studies'] ) ) {

			$this->register_case_studies( $settings );

		}

	}

	/*
	REGISTER CASE STUDIES
	-- Creates the public Case Studies post type and its optional taxonomy
	---------------------------------------------------------- */

	protected function register_case_studies( array $settings ): void {

		$post_slug    = self::sanitize_rewrite_slug( $settings['case_study_post_slug'] ?? '', 'case-study' );
		$archive_slug = self::sanitize_rewrite_slug( $settings['case_study_archive_slug'] ?? '', 'case-studies' );
		$taxonomies   = [];

		if ( ! empty( $settings['case_study_categories'] ) ) {

			$this->register_case_study_categories( $archive_slug );
			$taxonomies[] = self::CASE_STUDY_TAXONOMY;

		}

		$labels = [
			'name'                  => __( 'Case Studies', 'octave-addons' ),
			'singular_name'         => __( 'Case Study', 'octave-addons' ),
			'menu_name'             => __( 'Case Studies', 'octave-addons' ),
			'name_admin_bar'        => __( 'Case Study', 'octave-addons' ),
			'add_new'               => __( 'Add New', 'octave-addons' ),
			'add_new_item'          => __( 'Add New Case Study', 'octave-addons' ),
			'new_item'              => __( 'New Case Study', 'octave-addons' ),
			'edit_item'             => __( 'Edit Case Study', 'octave-addons' ),
			'view_item'             => __( 'View Case Study', 'octave-addons' ),
			'all_items'             => __( 'All Case Studies', 'octave-addons' ),
			'search_items'          => __( 'Search Case Studies', 'octave-addons' ),
			'not_found'             => __( 'No case studies found.', 'octave-addons' ),
			'not_found_in_trash'    => __( 'No case studies found in Trash.', 'octave-addons' ),
			'featured_image'        => __( 'Case Study image', 'octave-addons' ),
			'set_featured_image'    => __( 'Set Case Study image', 'octave-addons' ),
			'remove_featured_image' => __( 'Remove Case Study image', 'octave-addons' ),
			'use_featured_image'    => __( 'Use as Case Study image', 'octave-addons' ),
			'archives'              => __( 'Case Study archives', 'octave-addons' ),
		];

		register_post_type(
			self::CASE_STUDY_POST_TYPE,
			[
				'labels'              => $labels,
				'description'         => __( 'Project outcomes, client stories, and completed work.', 'octave-addons' ),
				'public'              => true,
				'hierarchical'        => false,
				'exclude_from_search' => false,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_admin_bar'   => true,
				'show_in_nav_menus'   => true,
				'show_in_rest'        => true,
				'menu_position'       => 22,
				'menu_icon'           => 'dashicons-portfolio',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'query_var'           => true,
				'rewrite'             => [
					'slug'       => $post_slug,
					'with_front' => false,
				],
				'has_archive'         => $archive_slug,
				'taxonomies'          => $taxonomies,
				'supports'            => [
					'title',
					'editor',
					'author',
					'thumbnail',
					'excerpt',
					'revisions',
					'custom-fields',
				],
			]
		);

	}

	/*
	REGISTER CASE STUDY CATEGORIES
	-- Adds hierarchical categories under the configured archive URL
	---------------------------------------------------------- */

	protected function register_case_study_categories( string $archive_slug ): void {

		$labels = [
			'name'              => __( 'Case Study Categories', 'octave-addons' ),
			'singular_name'     => __( 'Case Study Category', 'octave-addons' ),
			'search_items'      => __( 'Search Case Study Categories', 'octave-addons' ),
			'all_items'         => __( 'All Case Study Categories', 'octave-addons' ),
			'parent_item'       => __( 'Parent Case Study Category', 'octave-addons' ),
			'parent_item_colon' => __( 'Parent Case Study Category:', 'octave-addons' ),
			'edit_item'         => __( 'Edit Case Study Category', 'octave-addons' ),
			'update_item'       => __( 'Update Case Study Category', 'octave-addons' ),
			'add_new_item'      => __( 'Add New Case Study Category', 'octave-addons' ),
			'new_item_name'     => __( 'New Case Study Category Name', 'octave-addons' ),
			'menu_name'         => __( 'Categories', 'octave-addons' ),
		];

		register_taxonomy(
			self::CASE_STUDY_TAXONOMY,
			[ self::CASE_STUDY_POST_TYPE ],
			[
				'labels'            => $labels,
				'public'            => true,
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => true,
				'show_tagcloud'     => false,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => [
					'slug'         => $archive_slug . '/category',
					'with_front'   => false,
					'hierarchical' => true,
				],
			]
		);

	}

	/*
	REGISTER LANDING PAGES
	-- Creates a public page-style post type without a prefixed rewrite slug
	---------------------------------------------------------- */

	protected function register_landing_pages( array $settings ): void {

		$landing_page_slug = sanitize_title( (string) ( $settings['landing_page_slug'] ?? '' ) );
		$rewrite           = false;

		if ( '' !== $landing_page_slug ) {

			$rewrite = [
				'slug'       => $landing_page_slug,
				'with_front' => false,
			];

		}

		$labels = [
			'name'                  => __( 'Landing Pages', 'octave-addons' ),
			'singular_name'         => __( 'Landing Page', 'octave-addons' ),
			'menu_name'             => __( 'Landing Pages', 'octave-addons' ),
			'name_admin_bar'        => __( 'Landing Page', 'octave-addons' ),
			'add_new'               => __( 'Add New', 'octave-addons' ),
			'add_new_item'          => __( 'Add New Landing Page', 'octave-addons' ),
			'new_item'              => __( 'New Landing Page', 'octave-addons' ),
			'edit_item'             => __( 'Edit Landing Page', 'octave-addons' ),
			'view_item'             => __( 'View Landing Page', 'octave-addons' ),
			'all_items'             => __( 'All Landing Pages', 'octave-addons' ),
			'search_items'          => __( 'Search Landing Pages', 'octave-addons' ),
			'not_found'             => __( 'No landing pages found.', 'octave-addons' ),
			'not_found_in_trash'    => __( 'No landing pages found in Trash.', 'octave-addons' ),
			'featured_image'        => __( 'Featured image', 'octave-addons' ),
			'set_featured_image'    => __( 'Set featured image', 'octave-addons' ),
			'remove_featured_image' => __( 'Remove featured image', 'octave-addons' ),
			'use_featured_image'    => __( 'Use as featured image', 'octave-addons' ),
		];

		register_post_type(
			self::LANDING_PAGE_POST_TYPE,
			[
				'labels'              => $labels,
				'description'         => __( 'Campaign and conversion-focused pages with configurable clean URLs.', 'octave-addons' ),
				'public'              => true,
				'hierarchical'        => false,
				'exclude_from_search' => false,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_admin_bar'   => true,
				'show_in_nav_menus'   => true,
				'show_in_rest'        => true,
				'menu_position'       => 21,
				'menu_icon'           => 'dashicons-layout',
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'query_var'           => true,
				'rewrite'             => $rewrite,
				'has_archive'         => false,
				'supports'            => [
					'title',
					'editor',
					'author',
					'thumbnail',
					'excerpt',
					'revisions',
					'custom-fields',
					'page-attributes',
				],
			]
		);

	}

	/*
	FILTER LANDING PAGE LINK
	-- Builds domain/post-name URLs using the site's trailing-slash preference
	---------------------------------------------------------- */

	public function filter_landing_page_link( string $post_link, WP_Post $post, bool $leave_name, bool $sample ): string {

		if ( self::LANDING_PAGE_POST_TYPE !== $post->post_type ) {

			return $post_link;

		}

		$slug = $leave_name ? '%postname%' : $post->post_name;

		if ( '' === $slug ) {

			$slug = sanitize_title( $post->post_title );

		}

		$path_parts = array_filter( [ $this->landing_page_slug, $slug ] );
		$path       = user_trailingslashit( implode( '/', $path_parts ), 'single' );

		return home_url( '/' . ltrim( $path, '/' ) );

	}

	/*
	RESOLVE LANDING PAGE REQUEST
	-- Maps an otherwise unused root slug to a published Landing Page
	-- Existing Pages, Posts, and attachments keep priority on collisions
	---------------------------------------------------------- */

	public function resolve_landing_page_request( array $query_vars ): array {

		if ( is_admin() || '' !== $this->landing_page_slug ) {

			return $query_vars;

		}

		$slug = $this->get_requested_root_slug( $query_vars );

		if ( '' === $slug || $this->has_existing_content( $slug ) ) {

			return $query_vars;

		}

		$landing_page = get_page_by_path( $slug, OBJECT, self::LANDING_PAGE_POST_TYPE );

		if ( ! $landing_page instanceof WP_Post || 'publish' !== $landing_page->post_status ) {

			return $query_vars;

		}

		unset( $query_vars['pagename'], $query_vars['page_id'] );

		$query_vars['post_type'] = self::LANDING_PAGE_POST_TYPE;
		$query_vars['name']      = $slug;

		return $query_vars;

	}

	/*
	GET REQUESTED ROOT SLUG
	-- Extracts only single-segment page or post requests
	---------------------------------------------------------- */

	protected function get_requested_root_slug( array $query_vars ): string {

		$slug = '';

		if ( ! empty( $query_vars['pagename'] ) ) {

			$slug = (string) $query_vars['pagename'];

		} elseif ( ! empty( $query_vars['name'] ) && empty( $query_vars['post_type'] ) ) {

			$slug = (string) $query_vars['name'];

		}

		$slug = trim( $slug, '/' );

		if ( '' === $slug || false !== strpos( $slug, '/' ) ) {

			return '';

		}

		return sanitize_title( $slug );

	}

	/*
	HAS EXISTING CONTENT
	-- Prevents Landing Pages from taking over established root URLs
	---------------------------------------------------------- */

	protected function has_existing_content( string $slug ): bool {

		$existing = get_page_by_path( $slug, OBJECT, [ 'post', 'page', 'attachment' ] );

		return $existing instanceof WP_Post;

	}

	/*
	MAYBE REFRESH REWRITE RULES
	-- Flushes once when post type routing settings or module state change
	---------------------------------------------------------- */

	public function maybe_refresh_rewrite_rules(): void {

		$all_settings = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );
		$saved        = isset( $all_settings[ $this->get_id() ] ) && is_array( $all_settings[ $this->get_id() ] )
			? $all_settings[ $this->get_id() ]
			: [];
		$settings     = $this->get_settings( $saved );

		$rewrite_settings = [
			'enabled'                 => ! empty( $settings['enabled'] ),
			'landing_pages'           => ! empty( $settings['landing_pages'] ),
			'landing_page_slug'       => sanitize_title( (string) ( $settings['landing_page_slug'] ?? '' ) ),
			'case_studies'            => ! empty( $settings['case_studies'] ),
			'case_study_post_slug'    => self::sanitize_rewrite_slug( $settings['case_study_post_slug'] ?? '', 'case-study' ),
			'case_study_archive_slug' => self::sanitize_rewrite_slug( $settings['case_study_archive_slug'] ?? '', 'case-studies' ),
			'case_study_categories'   => ! empty( $settings['case_study_categories'] ),
		];

		$signature        = md5( wp_json_encode( $rewrite_settings ) );
		$stored_signature = (string) get_option( 'octave_addons_cpt_rewrite_signature', '' );

		if ( '' !== $stored_signature && hash_equals( $stored_signature, $signature ) ) {

			return;

		}

		flush_rewrite_rules( false );
		update_option( 'octave_addons_cpt_rewrite_signature', $signature, false );

	}

	/*
	SANITIZE REWRITE SLUG
	-- Converts a user-entered URL segment into a safe non-empty slug
	---------------------------------------------------------- */

	protected static function sanitize_rewrite_slug( $value, string $fallback ): string {

		$slug = sanitize_title( wp_unslash( (string) $value ) );

		return '' !== $slug ? $slug : $fallback;

	}

}

return new Octave_Addons_Module_Custom_Post_Types();
