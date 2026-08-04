<?php

/*
MODULE: CUSTOM POST TYPES
-- Renames the built-in Posts area to Blogs and manages an ordered collection
-- of public custom post types with optional archives and categories.
-- Existing Case Studies settings retain their original database identifiers.
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
	-- Refreshes rewrite rules only when custom routing changes.
	---------------------------------------------------------- */

	public function __construct() {

		add_action( 'init', [ $this, 'maybe_refresh_rewrite_rules' ], 99 );

	}

	/*
	GET ID
	-- Returns the module settings key.
	---------------------------------------------------------- */

	public function get_id(): string {

		return 'custom-post-types';

	}

	/*
	GET TITLE
	-- Returns the admin navigation label.
	---------------------------------------------------------- */

	public function get_title(): string {

		return __( 'Custom Post Types', 'octave-addons' );

	}

	/*
	GET DESCRIPTION
	-- Describes the managed WordPress content areas.
	---------------------------------------------------------- */

	public function get_description(): string {

		return __( 'Rename Posts to Blogs, group Pages into categories, and manage ordered custom content types.', 'octave-addons' );

	}

	/*
	GET DEFAULTS
	-- Starts new installations with an empty custom post type collection.
	---------------------------------------------------------- */

	public function get_defaults(): array {

		return [
			'enabled'           => true,
			'page_categories'   => true,
			'custom_post_types' => [],
		];

	}

	/*
	GET SETTINGS
	-- Converts the former single Case Studies fields into the repeatable schema
	-- without changing the post type or taxonomy stored in the database.
	---------------------------------------------------------- */

	public function get_settings( array $saved ): array {

		$settings = wp_parse_args( $saved, $this->get_defaults() );

		if ( ! array_key_exists( 'custom_post_types', $saved ) && $this->has_legacy_case_study_settings( $saved ) ) {

			$settings['custom_post_types'] = [ $this->legacy_case_study( $saved ) ];

		}

		$settings['custom_post_types'] = $this->normalise_post_types( $settings['custom_post_types'] ?? [] );

		return $settings;

	}

	/*
	SANITIZE
	-- Validates ordered post type definitions and prevents duplicate keys.
	---------------------------------------------------------- */

	public function sanitize( $input ): array {

		$input = is_array( $input ) ? $input : [];

		return [
			'enabled'           => ! empty( $input['enabled'] ),
			'page_categories'   => ! empty( $input['page_categories'] ),
			'custom_post_types' => $this->normalise_post_types( $input['custom_post_types'] ?? [] ),
		];

	}

	/*
	RENDER SETTINGS
	-- Displays Blog naming, Page Categories, and the sortable post type editor.
	---------------------------------------------------------- */

	public function render_settings( array $settings ): void {

		$page_terms_url  = admin_url( 'edit-tags.php?taxonomy=' . self::PAGE_TAXONOMY . '&post_type=page' );
		$custom_types    = $this->normalise_post_types( $settings['custom_post_types'] ?? [] );
		$template_values = [
			'enabled'                => true,
			'name'                   => '',
			'singular_name'          => '',
			'post_type'              => 'oa_content',
			'post_slug'              => '',
			'public'                 => true,
			'has_archive'            => true,
			'archive_slug'           => '',
			'categories'             => true,
			'taxonomy'               => '',
			'taxonomy_name'          => '',
			'taxonomy_singular_name' => '',
			'taxonomy_slug'          => '',
		];

		?>

		<div class="notice notice-info inline oa-inline-notice">
			<p><strong><?php esc_html_e( 'Built-in content naming', 'octave-addons' ); ?></strong></p>
			<p><?php esc_html_e( 'WordPress Posts are displayed as Blogs in the admin. The underlying post type remains “post”, so existing content, queries, templates and URLs continue to work.', 'octave-addons' ); ?></p>
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
								'help'    => __( 'Adds an admin-only hierarchical category taxonomy to Pages.', 'octave-addons' ),
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

			?>

		</table>

		<div class="oa-cpt-section">
			<div class="oa-cpt-section-head">
				<div>
					<span class="oa-panel-kicker"><?php esc_html_e( 'Content structure', 'octave-addons' ); ?></span>
					<h3><?php esc_html_e( 'Custom Post Types', 'octave-addons' ); ?></h3>
					<p><?php esc_html_e( 'Add multiple content types and drag them into the order they should appear in the WordPress admin menu.', 'octave-addons' ); ?></p>
				</div>
				<button type="button" class="button oa-cpt-add">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
					<?php esc_html_e( 'Add post type', 'octave-addons' ); ?>
				</button>
			</div>

			<div class="oa-cpt-list" data-empty-text="<?php esc_attr_e( 'No custom post types have been added.', 'octave-addons' ); ?>">

				<?php

				foreach ( $custom_types as $index => $post_type ) {

					$this->render_post_type_card( (string) $index, $post_type, true );

				}

				?>

			</div>
			<div class="oa-cpt-order-status screen-reader-text" role="status" aria-live="polite"></div>

			<template class="oa-cpt-template">
				<?php $this->render_post_type_card( '__INDEX__', $template_values, false ); ?>
			</template>
		</div>

		<?php

	}

	/*
	RENDER POST TYPE CARD
	-- Outputs one sortable custom post type definition.
	---------------------------------------------------------- */

	protected function render_post_type_card( string $index, array $post_type, bool $saved ): void {

		$name                   = (string) ( $post_type['name'] ?? '' );
		$singular_name          = (string) ( $post_type['singular_name'] ?? '' );
		$key                    = (string) ( $post_type['post_type'] ?? '' );
		$post_slug              = (string) ( $post_type['post_slug'] ?? '' );
		$archive_slug           = (string) ( $post_type['archive_slug'] ?? '' );
		$public                 = ! empty( $post_type['public'] );
		$has_archive            = ! empty( $post_type['has_archive'] );
		$categories             = ! empty( $post_type['categories'] );
		$enabled                = ! empty( $post_type['enabled'] );
		$taxonomy_name          = (string) ( $post_type['taxonomy_name'] ?? '' );
		$taxonomy_singular_name = (string) ( $post_type['taxonomy_singular_name'] ?? '' );
		$taxonomy_slug          = (string) ( $post_type['taxonomy_slug'] ?? '' );

		?>

		<article class="oa-cpt-item" draggable="false" data-saved="<?= $saved ? 'true' : 'false'; ?>">
			<div class="oa-cpt-item-head">
				<button type="button" class="oa-cpt-drag-handle" aria-label="<?php esc_attr_e( 'Drag to reorder this post type, or use the move buttons', 'octave-addons' ); ?>">
					<span class="dashicons dashicons-menu" aria-hidden="true"></span>
				</button>
				<button type="button" class="oa-cpt-move oa-cpt-move-up" aria-label="<?php esc_attr_e( 'Move this post type up', 'octave-addons' ); ?>">
					<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
				</button>
				<button type="button" class="oa-cpt-move oa-cpt-move-down" aria-label="<?php esc_attr_e( 'Move this post type down', 'octave-addons' ); ?>">
					<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
				</button>
				<strong class="oa-cpt-item-title"><?= esc_html( '' !== $name ? $name : __( 'New post type', 'octave-addons' ) ); ?></strong>
				<span class="oa-cpt-key-preview"><?= esc_html( $key ); ?></span>
				<button type="button" class="oa-cpt-remove" aria-label="<?php esc_attr_e( 'Remove this post type', 'octave-addons' ); ?>">
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
				</button>
			</div>

			<div class="oa-cpt-fields">
				<label class="oa-cpt-field">
					<span><?php esc_html_e( 'Name', 'octave-addons' ); ?></span>
					<input type="text" data-cpt-field="name" name="<?= esc_attr( $this->cpt_field_name( $index, 'name' ) ); ?>" value="<?= esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'Projects', 'octave-addons' ); ?>" required>
					<small><?php esc_html_e( 'Plural name shown in the admin menu.', 'octave-addons' ); ?></small>
				</label>

				<label class="oa-cpt-field">
					<span><?php esc_html_e( 'Singular name', 'octave-addons' ); ?></span>
					<input type="text" data-cpt-field="singular_name" name="<?= esc_attr( $this->cpt_field_name( $index, 'singular_name' ) ); ?>" value="<?= esc_attr( $singular_name ); ?>" placeholder="<?php esc_attr_e( 'Project', 'octave-addons' ); ?>" required>
					<small><?php esc_html_e( 'Used for Add New and Edit labels.', 'octave-addons' ); ?></small>
				</label>

				<label class="oa-cpt-field">
					<span><?php esc_html_e( 'Post type key', 'octave-addons' ); ?></span>
					<input type="text" data-cpt-field="post_type" name="<?= esc_attr( $this->cpt_field_name( $index, 'post_type' ) ); ?>" value="<?= esc_attr( $key ); ?>" maxlength="20" pattern="[a-z0-9_]+" required<?= $saved ? ' readonly' : ''; ?>>
					<small><?= $saved ? esc_html__( 'Permanent after saving to protect existing content.', 'octave-addons' ) : esc_html__( 'Use an oa_ prefix; maximum 20 characters.', 'octave-addons' ); ?></small>
				</label>

				<label class="oa-cpt-field">
					<span><?php esc_html_e( 'Single URL slug', 'octave-addons' ); ?></span>
					<input type="text" data-cpt-field="post_slug" name="<?= esc_attr( $this->cpt_field_name( $index, 'post_slug' ) ); ?>" value="<?= esc_attr( $post_slug ); ?>" placeholder="project" required>
					<small><?php esc_html_e( 'Used before an individual item’s URL.', 'octave-addons' ); ?></small>
				</label>

				<div class="oa-cpt-field oa-cpt-switch-field">
					<span><?php esc_html_e( 'Enabled', 'octave-addons' ); ?></span>
					<label class="oa-switch">
						<input type="checkbox" name="<?= esc_attr( $this->cpt_field_name( $index, 'enabled' ) ); ?>" value="1"<?= checked( $enabled, true, false ); ?>>
						<span class="oa-switch-slider"></span>
					</label>
					<small><?php esc_html_e( 'Register this content type.', 'octave-addons' ); ?></small>
				</div>

				<div class="oa-cpt-field oa-cpt-switch-field">
					<span><?php esc_html_e( 'Public', 'octave-addons' ); ?></span>
					<label class="oa-switch">
						<input type="checkbox" name="<?= esc_attr( $this->cpt_field_name( $index, 'public' ) ); ?>" value="1"<?= checked( $public, true, false ); ?>>
						<span class="oa-switch-slider"></span>
					</label>
					<small><?php esc_html_e( 'Allow frontend URLs, searches and navigation use.', 'octave-addons' ); ?></small>
				</div>

				<div class="oa-cpt-field oa-cpt-switch-field">
					<span><?php esc_html_e( 'Archive URL', 'octave-addons' ); ?></span>
					<label class="oa-switch">
						<input type="checkbox" class="oa-cpt-archive-toggle" name="<?= esc_attr( $this->cpt_field_name( $index, 'has_archive' ) ); ?>" value="1"<?= checked( $has_archive, true, false ); ?>>
						<span class="oa-switch-slider"></span>
					</label>
					<small><?php esc_html_e( 'Enable a public listing page.', 'octave-addons' ); ?></small>
				</div>

				<label class="oa-cpt-field oa-cpt-archive-field<?= $has_archive ? '' : ' oa-hidden'; ?>">
					<span><?php esc_html_e( 'Archive slug', 'octave-addons' ); ?></span>
					<input type="text" data-cpt-field="archive_slug" name="<?= esc_attr( $this->cpt_field_name( $index, 'archive_slug' ) ); ?>" value="<?= esc_attr( $archive_slug ); ?>" placeholder="projects">
					<small><?php esc_html_e( 'The archive URL path, without slashes.', 'octave-addons' ); ?></small>
				</label>

				<div class="oa-cpt-field oa-cpt-switch-field">
					<span><?php esc_html_e( 'Custom taxonomy', 'octave-addons' ); ?></span>
					<label class="oa-switch">
						<input type="checkbox" class="oa-cpt-taxonomy-toggle" name="<?= esc_attr( $this->cpt_field_name( $index, 'categories' ) ); ?>" value="1"<?= checked( $categories, true, false ); ?>>
						<span class="oa-switch-slider"></span>
					</label>
					<small><?php esc_html_e( 'Add one hierarchical taxonomy to this type.', 'octave-addons' ); ?></small>
				</div>

				<label class="oa-cpt-field oa-cpt-taxonomy-field<?= $categories ? '' : ' oa-hidden'; ?>">
					<span><?php esc_html_e( 'Taxonomy name', 'octave-addons' ); ?></span>
					<input type="text" data-cpt-field="taxonomy_name" name="<?= esc_attr( $this->cpt_field_name( $index, 'taxonomy_name' ) ); ?>" value="<?= esc_attr( $taxonomy_name ); ?>" placeholder="<?php esc_attr_e( 'Project Categories', 'octave-addons' ); ?>">
					<small><?php esc_html_e( 'Plural taxonomy label.', 'octave-addons' ); ?></small>
				</label>

				<label class="oa-cpt-field oa-cpt-taxonomy-field<?= $categories ? '' : ' oa-hidden'; ?>">
					<span><?php esc_html_e( 'Taxonomy singular name', 'octave-addons' ); ?></span>
					<input type="text" data-cpt-field="taxonomy_singular_name" name="<?= esc_attr( $this->cpt_field_name( $index, 'taxonomy_singular_name' ) ); ?>" value="<?= esc_attr( $taxonomy_singular_name ); ?>" placeholder="<?php esc_attr_e( 'Project Category', 'octave-addons' ); ?>">
					<small><?php esc_html_e( 'Singular taxonomy label.', 'octave-addons' ); ?></small>
				</label>

				<label class="oa-cpt-field oa-cpt-taxonomy-field<?= $categories ? '' : ' oa-hidden'; ?>">
					<span><?php esc_html_e( 'Taxonomy URL slug', 'octave-addons' ); ?></span>
					<input type="text" data-cpt-field="taxonomy_slug" name="<?= esc_attr( $this->cpt_field_name( $index, 'taxonomy_slug' ) ); ?>" value="<?= esc_attr( $taxonomy_slug ); ?>" placeholder="project-category">
					<small><?php esc_html_e( 'Public URL path for taxonomy terms.', 'octave-addons' ); ?></small>
				</label>
			</div>
		</article>

		<?php

	}

	/*
	RUN
	-- Renames Posts and registers enabled content types in saved order.
	---------------------------------------------------------- */

	public function run( array $settings ): void {

		add_filter( 'post_type_labels_post', [ $this, 'rename_post_labels' ] );

		$post_type_object = get_post_type_object( 'post' );

		if ( $post_type_object ) {

			$post_type_object->labels = $this->rename_post_labels( $post_type_object->labels );
			$post_type_object->label  = __( 'Blogs', 'octave-addons' );

		}

		if ( ! empty( $settings['page_categories'] ) ) {

			$this->register_page_categories();

			add_filter( 'views_edit-page', [ $this, 'add_page_category_views' ] );
			add_action( 'restrict_manage_posts', [ $this, 'render_page_category_filter' ], 10, 2 );

		}

		foreach ( $this->normalise_post_types( $settings['custom_post_types'] ?? [] ) as $index => $post_type ) {

			if ( empty( $post_type['enabled'] ) ) {

				continue;

			}

			$this->register_custom_post_type( $post_type, $this->menu_position_for_index( (int) $index ) );

		}

	}

	/*
	RENAME POST LABELS
	-- Changes admin-facing labels only; the built-in database key stays post.
	---------------------------------------------------------- */

	public function rename_post_labels( $labels ) {

		$labels->name                  = __( 'Blogs', 'octave-addons' );
		$labels->singular_name         = __( 'Blog', 'octave-addons' );
		$labels->menu_name             = __( 'Blogs', 'octave-addons' );
		$labels->name_admin_bar        = __( 'Blog', 'octave-addons' );
		$labels->add_new_item          = __( 'Add New Blog', 'octave-addons' );
		$labels->new_item              = __( 'New Blog', 'octave-addons' );
		$labels->edit_item             = __( 'Edit Blog', 'octave-addons' );
		$labels->view_item             = __( 'View Blog', 'octave-addons' );
		$labels->all_items             = __( 'All Blogs', 'octave-addons' );
		$labels->search_items          = __( 'Search Blogs', 'octave-addons' );
		$labels->parent_item_colon     = __( 'Parent Blogs:', 'octave-addons' );
		$labels->not_found             = __( 'No blogs found.', 'octave-addons' );
		$labels->not_found_in_trash    = __( 'No blogs found in Trash.', 'octave-addons' );
		$labels->archives              = __( 'Blog archives', 'octave-addons' );
		$labels->attributes            = __( 'Blog attributes', 'octave-addons' );
		$labels->insert_into_item      = __( 'Insert into blog', 'octave-addons' );
		$labels->uploaded_to_this_item = __( 'Uploaded to this blog', 'octave-addons' );
		$labels->filter_items_list     = __( 'Filter blogs list', 'octave-addons' );
		$labels->items_list_navigation = __( 'Blogs list navigation', 'octave-addons' );
		$labels->items_list            = __( 'Blogs list', 'octave-addons' );

		return $labels;

	}

	/*
	REGISTER PAGE CATEGORIES
	-- Adds an admin-only hierarchical taxonomy to Pages.
	---------------------------------------------------------- */

	protected function register_page_categories(): void {

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
			'menu_name'         => $name,
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
	-- Adds category shortcuts above the Pages list.
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
	-- Adds a standard category dropdown to the Pages list toolbar.
	---------------------------------------------------------- */

	public function render_page_category_filter( $post_type, $which = 'top' ): void {

		if ( 'page' !== $post_type || 'top' !== $which || ! taxonomy_exists( self::PAGE_TAXONOMY ) ) {

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
	-- Returns the category slug currently filtering the Pages list.
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
	REGISTER CUSTOM POST TYPE
	-- Registers one user-defined type and its optional category taxonomy.
	---------------------------------------------------------- */

	protected function register_custom_post_type( array $post_type, int $menu_position ): void {

		$key          = $post_type['post_type'];
		$name         = $post_type['name'];
		$singular     = $post_type['singular_name'];
		$post_slug    = $post_type['post_slug'];
		$archive_slug = $post_type['archive_slug'];
		$taxonomy     = $post_type['taxonomy'];
		$is_public    = ! empty( $post_type['public'] );
		$taxonomies   = [];

		if ( post_type_exists( $key ) ) {

			return;

		}

		if ( ! empty( $post_type['categories'] ) ) {

			$this->register_custom_taxonomy( $post_type, $key );
			$taxonomies[] = $taxonomy;

		}

		$labels = [
			'name'                  => $name,
			'singular_name'         => $singular,
			'menu_name'             => $name,
			'name_admin_bar'        => $singular,
			'add_new'               => __( 'Add New', 'octave-addons' ),
			'add_new_item'          => sprintf( __( 'Add New %s', 'octave-addons' ), $singular ),
			'new_item'              => sprintf( __( 'New %s', 'octave-addons' ), $singular ),
			'edit_item'             => sprintf( __( 'Edit %s', 'octave-addons' ), $singular ),
			'view_item'             => sprintf( __( 'View %s', 'octave-addons' ), $singular ),
			'all_items'             => sprintf( __( 'All %s', 'octave-addons' ), $name ),
			'search_items'          => sprintf( __( 'Search %s', 'octave-addons' ), $name ),
			'not_found'             => sprintf( __( 'No %s found.', 'octave-addons' ), strtolower( $name ) ),
			'not_found_in_trash'    => sprintf( __( 'No %s found in Trash.', 'octave-addons' ), strtolower( $name ) ),
			'featured_image'        => sprintf( __( '%s image', 'octave-addons' ), $singular ),
			'set_featured_image'    => sprintf( __( 'Set %s image', 'octave-addons' ), $singular ),
			'remove_featured_image' => sprintf( __( 'Remove %s image', 'octave-addons' ), $singular ),
			'use_featured_image'    => sprintf( __( 'Use as %s image', 'octave-addons' ), $singular ),
			'archives'              => sprintf( __( '%s archives', 'octave-addons' ), $singular ),
		];

		register_post_type(
			$key,
			[
				'labels'              => $labels,
				'public'              => $is_public,
				'hierarchical'        => false,
				'exclude_from_search' => ! $is_public,
				'publicly_queryable'  => $is_public,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_admin_bar'   => true,
				'show_in_nav_menus'   => $is_public,
				'show_in_rest'        => true,
				'menu_position'       => $menu_position,
				'menu_icon'           => 'dashicons-admin-post',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'query_var'           => $is_public,
				'rewrite'             => $is_public
					? [
						'slug'       => $post_slug,
						'with_front' => false,
					]
					: false,
				'has_archive'         => $is_public && ! empty( $post_type['has_archive'] ) ? $archive_slug : false,
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
	REGISTER CUSTOM TAXONOMY
	-- Adds the single configured hierarchical taxonomy for one post type.
	---------------------------------------------------------- */

	protected function register_custom_taxonomy( array $definition, string $post_type ): void {

		$taxonomy = $definition['taxonomy'];
		$name     = $definition['taxonomy_name'];
		$singular = $definition['taxonomy_singular_name'];
		$labels   = [
			'name'              => $name,
			'singular_name'     => $singular,
			'search_items'      => sprintf( __( 'Search %s', 'octave-addons' ), $name ),
			'all_items'         => sprintf( __( 'All %s', 'octave-addons' ), $name ),
			'parent_item'       => sprintf( __( 'Parent %s', 'octave-addons' ), $singular ),
			'parent_item_colon' => sprintf( __( 'Parent %s:', 'octave-addons' ), $singular ),
			'edit_item'         => sprintf( __( 'Edit %s', 'octave-addons' ), $singular ),
			'update_item'       => sprintf( __( 'Update %s', 'octave-addons' ), $singular ),
			'add_new_item'      => sprintf( __( 'Add New %s', 'octave-addons' ), $singular ),
			'new_item_name'     => sprintf( __( 'New %s Name', 'octave-addons' ), $singular ),
			'menu_name'         => __( 'Categories', 'octave-addons' ),
		];

		register_taxonomy(
			$taxonomy,
			[ $post_type ],
			[
				'labels'            => $labels,
				'description'       => sprintf( __( '%s for %s.', 'octave-addons' ), $name, $definition['name'] ),
				'public'            => ! empty( $definition['public'] ),
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => ! empty( $definition['public'] ),
				'show_tagcloud'     => false,
				'show_in_rest'      => true,
				'query_var'         => ! empty( $definition['public'] ),
				'rewrite'           => ! empty( $definition['public'] )
					? [
						'slug'         => $definition['taxonomy_slug'],
						'with_front'   => false,
						'hierarchical' => true,
					]
					: false,
			]
		);

	}

	/*
	MAYBE REFRESH REWRITE RULES
	-- Flushes once when post type routing or taxonomy settings change.
	---------------------------------------------------------- */

	public function maybe_refresh_rewrite_rules(): void {

		$all_settings = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );
		$saved        = isset( $all_settings[ $this->get_id() ] ) && is_array( $all_settings[ $this->get_id() ] )
			? $all_settings[ $this->get_id() ]
			: [];
		$settings     = $this->get_settings( $saved );

		$rewrite_settings = [
			'enabled'           => ! empty( $settings['enabled'] ),
			'page_categories'   => ! empty( $settings['page_categories'] ),
			'custom_post_types' => $settings['custom_post_types'],
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
	NORMALISE POST TYPES
	-- Produces safe, unique definitions while preserving submitted order.
	---------------------------------------------------------- */

	protected function normalise_post_types( $post_types ): array {

		if ( ! is_array( $post_types ) ) {

			return [];

		}

		$clean = [];
		$used  = [];

		foreach ( array_slice( $post_types, 0, 20 ) as $index => $post_type ) {

			if ( ! is_array( $post_type ) ) {

				continue;

			}

			$name          = sanitize_text_field( wp_unslash( (string) ( $post_type['name'] ?? '' ) ) );
			$singular_name = sanitize_text_field( wp_unslash( (string) ( $post_type['singular_name'] ?? '' ) ) );
			$key           = $this->sanitize_post_type_key( $post_type['post_type'] ?? '', (int) $index );

			if ( '' === $name || '' === $singular_name || isset( $used[ $key ] ) ) {

				continue;

			}

			$used[ $key ] = true;

			$post_slug    = self::sanitize_rewrite_slug( $post_type['post_slug'] ?? '', sanitize_title( $singular_name ) );
			$archive_slug = self::sanitize_rewrite_slug( $post_type['archive_slug'] ?? '', sanitize_title( $name ) );
			$taxonomy     = self::CASE_STUDY_POST_TYPE === $key
				? self::CASE_STUDY_TAXONOMY
				: substr( $key . '_category', 0, 32 );
			$taxonomy_name          = sanitize_text_field( wp_unslash( (string) ( $post_type['taxonomy_name'] ?? '' ) ) );
			$taxonomy_singular_name = sanitize_text_field( wp_unslash( (string) ( $post_type['taxonomy_singular_name'] ?? '' ) ) );
			$taxonomy_slug          = self::sanitize_rewrite_path( $post_type['taxonomy_slug'] ?? '', $post_slug . '-category' );

			if ( '' === $taxonomy_name ) {

				$taxonomy_name = sprintf( __( '%s Categories', 'octave-addons' ), $singular_name );

			}

			if ( '' === $taxonomy_singular_name ) {

				$taxonomy_singular_name = sprintf( __( '%s Category', 'octave-addons' ), $singular_name );

			}

			$clean[] = [
				'enabled'                => ! empty( $post_type['enabled'] ),
				'name'                   => $name,
				'singular_name'          => $singular_name,
				'post_type'              => $key,
				'post_slug'              => $post_slug,
				'public'                 => ! empty( $post_type['public'] ),
				'has_archive'            => ! empty( $post_type['has_archive'] ),
				'archive_slug'           => $archive_slug,
				'categories'             => ! empty( $post_type['categories'] ),
				'taxonomy'               => $taxonomy,
				'taxonomy_name'          => $taxonomy_name,
				'taxonomy_singular_name' => $taxonomy_singular_name,
				'taxonomy_slug'          => $taxonomy_slug,
			];

		}

		return $clean;

	}

	/*
	SANITIZE POST TYPE KEY
	-- Allows the legacy Case Studies key and prefixes new keys with oa_.
	---------------------------------------------------------- */

	protected function sanitize_post_type_key( $value, int $index ): string {

		$key = substr( sanitize_key( wp_unslash( (string) $value ) ), 0, 20 );

		if ( self::CASE_STUDY_POST_TYPE === $key ) {

			return $key;

		}

		$key = preg_replace( '/^oa_+/', '', $key );
		$key = 'oa_' . ltrim( (string) $key, '_' );

		if ( 'oa_' === $key ) {

			$key = 'oa_content_' . ( $index + 1 );

		}

		return substr( $key, 0, 20 );

	}

	/*
	DEFAULT CASE STUDY
	-- Defines the original type using its existing database identifiers.
	---------------------------------------------------------- */

	protected function default_case_study(): array {

		return [
			'enabled'                => true,
			'name'                   => __( 'Case Studies', 'octave-addons' ),
			'singular_name'          => __( 'Case Study', 'octave-addons' ),
			'post_type'              => self::CASE_STUDY_POST_TYPE,
			'post_slug'              => 'case-study',
			'public'                 => true,
			'has_archive'            => true,
			'archive_slug'           => 'case-studies',
			'categories'             => true,
			'taxonomy'               => self::CASE_STUDY_TAXONOMY,
			'taxonomy_name'          => __( 'Case Study Categories', 'octave-addons' ),
			'taxonomy_singular_name' => __( 'Case Study Category', 'octave-addons' ),
			'taxonomy_slug'          => 'case-studies/category',
		];

	}

	/*
	LEGACY CASE STUDY
	-- Maps the former flat settings keys into one collection entry.
	---------------------------------------------------------- */

	protected function legacy_case_study( array $saved ): array {

		$case_study = $this->default_case_study();

		$case_study['enabled']      = ! empty( $saved['case_studies'] );
		$case_study['post_slug']    = self::sanitize_rewrite_slug( $saved['case_study_post_slug'] ?? '', 'case-study' );
		$case_study['archive_slug'] = self::sanitize_rewrite_slug( $saved['case_study_archive_slug'] ?? '', 'case-studies' );
		$case_study['categories']   = ! empty( $saved['case_study_categories'] );

		return $case_study;

	}

	/*
	HAS LEGACY CASE STUDY SETTINGS
	-- Detects saved settings created before the repeatable editor existed.
	---------------------------------------------------------- */

	protected function has_legacy_case_study_settings( array $saved ): bool {

		return array_key_exists( 'case_studies', $saved )
			|| array_key_exists( 'case_study_post_slug', $saved )
			|| array_key_exists( 'case_study_archive_slug', $saved )
			|| array_key_exists( 'case_study_categories', $saved );

	}

	/*
	MENU POSITION FOR INDEX
	-- Places managed types after Pages while skipping WordPress Comments.
	---------------------------------------------------------- */

	protected function menu_position_for_index( int $index ): int {

		$position = 21 + $index;

		return $position >= 25 ? $position + 1 : $position;

	}

	/*
	CPT FIELD NAME
	-- Produces a nested Settings API field name for one repeatable entry.
	---------------------------------------------------------- */

	protected function cpt_field_name( string $index, string $key ): string {

		return sprintf( '%s[%s][custom_post_types][%s][%s]', OCTAVE_ADDONS_OPTION_KEY, $this->get_id(), $index, $key );

	}

	/*
	SANITIZE REWRITE SLUG
	-- Converts a URL path into a safe non-empty slug.
	---------------------------------------------------------- */

	protected static function sanitize_rewrite_slug( $value, string $fallback ): string {

		$slug = sanitize_title( wp_unslash( (string) $value ) );

		return '' !== $slug ? $slug : $fallback;

	}

	/*
	SANITIZE REWRITE PATH
	-- Sanitizes each segment while allowing an intentional nested URL path.
	---------------------------------------------------------- */

	protected static function sanitize_rewrite_path( $value, string $fallback ): string {

		$segments = array_filter( explode( '/', wp_unslash( (string) $value ) ) );
		$segments = array_filter( array_map( 'sanitize_title', $segments ) );
		$path     = implode( '/', $segments );

		return '' !== $path ? $path : $fallback;

	}

}

return new Octave_Addons_Module_Custom_Post_Types();
