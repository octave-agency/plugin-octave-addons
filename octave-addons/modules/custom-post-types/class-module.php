<?php

/*
MODULE: CUSTOM POST TYPES
-- Registers optional content types managed from the Octave Addons dashboard
-- Page Categories group ordinary Pages into campaigns (ppc, landing, and so
-- on) and plug straight into WordPress's own filtering rather than adding a
-- separate post type
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Custom_Post_Types extends Octave_Addons_Module {

	protected const PAGE_TAXONOMY        = 'octave_page_category';
	protected const CASE_STUDY_POST_TYPE = 'octave_case_study';
	protected const CASE_STUDY_TAXONOMY  = 'octave_case_category';

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

		return __( 'Group Pages into campaign categories and add purpose-built content areas with clean URLs.', 'octave-addons' );

	}

	/*
	GET DEFAULTS
	-- Enables the settings area and Page Categories for new installations
	---------------------------------------------------------- */

	public function get_defaults(): array {

		return [
			'enabled'                 => true,
			'page_categories'         => true,
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
			'page_categories'         => ! empty( $input['page_categories'] ),
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

		$page_terms_url = admin_url( 'edit-tags.php?taxonomy=' . self::PAGE_TAXONOMY . '&post_type=page' );

		$case_study_post_slug    = self::sanitize_rewrite_slug( $settings['case_study_post_slug'] ?? '', 'case-study' );
		$case_study_archive_slug = self::sanitize_rewrite_slug( $settings['case_study_archive_slug'] ?? '', 'case-studies' );
		$case_study_path         = user_trailingslashit( $case_study_post_slug . '/example-project', 'single' );
		$case_study_archive_path = user_trailingslashit( $case_study_archive_slug, 'post_type_archive' );
		$case_study_url          = home_url( '/' . ltrim( $case_study_path, '/' ) );
		$case_study_archive_url  = home_url( '/' . ltrim( $case_study_archive_path, '/' ) );

		?>

			<div class="notice notice-info inline oa-inline-notice">
				<p><strong><?php esc_html_e( 'Campaign categories for Pages', 'octave-addons' ); ?></strong></p>
			<p>
				<?php esc_html_e( 'Group ordinary Pages into campaigns such as PPC or Landing. Categories appear as one-click filters above the Pages list, as a column, in Quick Edit, and in the block editor — all using standard WordPress filtering, so builders and query loops pick them up automatically.', 'octave-addons' ); ?>
			</p>
		</div>

		<table class="form-table oa-form-table" role="presentation">

			<?php

			Octave_Addons_Fields::section(
				[
					'label' => __( 'Pages', 'octave-addons' ),
					'first' => true,
				]
			);

			Octave_Addons_Fields::row(
				[
					'label' => __( 'Page Categories', 'octave-addons' ),
					'field' => function () use ( $settings, $page_terms_url ) {

						Octave_Addons_Fields::switch_field(
							[
								'name'    => $this->field_name( 'page_categories' ),
								'checked' => ! empty( $settings['page_categories'] ),
								'help'    => __( 'Adds a hierarchical category taxonomy to the built-in Pages post type. Admin only — categories have no public archives or links.', 'octave-addons' ),
							]
						);

						if ( empty( $settings['page_categories'] ) ) {

							return;

						}

						?>

						<span class="oa-help">
							<a href="<?= esc_url( $page_terms_url ); ?>"><?php esc_html_e( 'Manage page categories', 'octave-addons' ); ?></a>
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

		if ( ! empty( $settings['page_categories'] ) ) {

			$this->register_page_categories( $settings );

			add_filter( 'views_edit-page', [ $this, 'add_page_category_views' ] );
			add_action( 'restrict_manage_posts', [ $this, 'render_page_category_filter' ], 10, 2 );

		}

		if ( ! empty( $settings['case_studies'] ) ) {

			$this->register_case_studies( $settings );

		}

	}

	/*
	REGISTER PAGE CATEGORIES
	-- Adds a hierarchical taxonomy to the built-in Pages post type.
	-- Admin-only: no public archives, rewrite rules, or nav menu entries, so
	-- visitors never get a category link. WordPress keeps the query var
	-- registered inside wp-admin, so list table filtering, REST, and builder
	-- query loops still work without any extra glue.
	---------------------------------------------------------- */

	protected function register_page_categories( array $settings ): void {

		$labels = [
			'name'              => __( 'Page Categories', 'octave-addons' ),
			'singular_name'     => __( 'Page Category', 'octave-addons' ),
			'search_items'      => __( 'Search Page Categories', 'octave-addons' ),
			'all_items'         => __( 'All Page Categories', 'octave-addons' ),
			'parent_item'       => __( 'Parent Page Category', 'octave-addons' ),
			'parent_item_colon' => __( 'Parent Page Category:', 'octave-addons' ),
			'edit_item'         => __( 'Edit Page Category', 'octave-addons' ),
			'update_item'       => __( 'Update Page Category', 'octave-addons' ),
			'add_new_item'      => __( 'Add New Page Category', 'octave-addons' ),
			'new_item_name'     => __( 'New Page Category Name', 'octave-addons' ),
			'not_found'         => __( 'No page categories found.', 'octave-addons' ),
			'back_to_items'     => __( 'Back to Page Categories', 'octave-addons' ),
			'menu_name'         => __( 'Categories', 'octave-addons' ),
		];

		register_taxonomy(
			self::PAGE_TAXONOMY,
			[ 'page' ],
			[
				'labels'             => $labels,
				'description'        => __( 'Campaign groupings for Pages, such as PPC or Landing.', 'octave-addons' ),
				'public'             => false,
				'publicly_queryable' => false,
				'hierarchical'       => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_admin_column'  => true,
				'show_in_quick_edit' => true,
				'show_in_nav_menus'  => false,
				'show_tagcloud'      => false,
				'show_in_rest'       => true,
				'query_var'          => true,
				'rewrite'            => false,
			]
		);

	}

	/*
	ADD PAGE CATEGORY VIEWS
	-- Puts every category alongside All / Published / Draft at the top of the
	-- Pages list, so switching campaign is a single click rather than a
	-- dropdown and a Filter button.
	---------------------------------------------------------- */

	public function add_page_category_views( $views ): array {

		$views = is_array( $views ) ? $views : [];

		$terms = get_terms(
			[
				'taxonomy'   => self::PAGE_TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
			]
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {

			return $views;

		}

		$current = $this->get_current_page_category();

		// WordPress leaves "All" highlighted whenever no post_status is set, so
		// clear it while a category is doing the filtering.
		if ( '' !== $current && isset( $views['all'] ) ) {

			$views['all'] = str_replace( ' class="current"', '', $views['all'] );
			$views['all'] = str_replace( ' aria-current="page"', '', $views['all'] );

		}

		foreach ( $terms as $term ) {

			if ( ! $term instanceof WP_Term ) {

				continue;

			}

			$url = add_query_arg(
				[
					'post_type'          => 'page',
					self::PAGE_TAXONOMY => $term->slug,
				],
				admin_url( 'edit.php' )
			);

			$is_current = ( $current === $term->slug );

			$views[ 'oa_page_category_' . $term->slug ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $url ),
				$is_current ? ' class="current" aria-current="page"' : '',
				esc_html( $term->name ),
				esc_html( number_format_i18n( $term->count ) )
			);

		}

		return $views;

	}

	/*
	RENDER PAGE CATEGORY FILTER
	-- Standard taxonomy dropdown in the Pages toolbar. Values are slugs so the
	-- submitted query var is the one WP_Query already parses.
	---------------------------------------------------------- */

	public function render_page_category_filter( $post_type, $which = 'top' ): void {

		if ( 'page' !== $post_type || 'top' !== $which ) {

			return;

		}

		if ( ! taxonomy_exists( self::PAGE_TAXONOMY ) ) {

			return;

		}

		?>

		<label class="screen-reader-text" for="<?= esc_attr( self::PAGE_TAXONOMY ); ?>">
			<?php esc_html_e( 'Filter by page category', 'octave-addons' ); ?>
		</label>

		<?php

		wp_dropdown_categories(
			[
				'show_option_all' => __( 'All page categories', 'octave-addons' ),
				'taxonomy'        => self::PAGE_TAXONOMY,
				'name'            => self::PAGE_TAXONOMY,
				'id'              => self::PAGE_TAXONOMY,
				'value_field'     => 'slug',
				'selected'        => $this->get_current_page_category(),
				'hierarchical'    => true,
				'depth'           => 3,
				'orderby'         => 'name',
				'show_count'      => true,
				'hide_empty'      => false,
				'hide_if_empty'   => true,
			]
		);

	}

	/*
	GET CURRENT PAGE CATEGORY
	-- The category slug the Pages list is currently filtered by, if any.
	---------------------------------------------------------- */

	protected function get_current_page_category(): string {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list table filter.
		if ( empty( $_GET[ self::PAGE_TAXONOMY ] ) ) {

			return '';

		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list table filter.
		return sanitize_title( wp_unslash( (string) $_GET[ self::PAGE_TAXONOMY ] ) );

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
			'page_categories'         => ! empty( $settings['page_categories'] ),
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
