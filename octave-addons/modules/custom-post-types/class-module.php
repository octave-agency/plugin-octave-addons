<?php

/*
MODULE: CUSTOM POSTS
-- Manages custom post types, reusable taxonomies, and typed post fields.
-- Existing Case Studies settings retain their original database identifiers.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once __DIR__ . '/class-post-fields.php';

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
	SETTINGS AVAILABLE WHEN DISABLED
	-- Lets administrators define content structure before registering it.
	---------------------------------------------------------- */

	public function settings_available_when_disabled(): bool {

		return true;

	}

	/*
	GET TITLE
	-- Returns the admin navigation label.
	---------------------------------------------------------- */

	public function get_title(): string {

		return __( 'Custom Posts', 'octave-addons' );

	}

	/*
	GET DESCRIPTION
	-- Describes the managed WordPress content areas.
	---------------------------------------------------------- */

	public function get_description(): string {

		return __( 'Create post types, shared categories, and editor-friendly custom fields in one place.', 'octave-addons' );

	}

	/*
	GET DEFAULTS
	-- Starts new installations with an empty custom post type collection.
	---------------------------------------------------------- */

	public function get_defaults(): array {

		return [
			'enabled'           => false,
			'page_categories'   => true,
			'custom_post_types' => [],
			'custom_taxonomies' => [],
			'custom_fields'     => [],
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

		if ( ! array_key_exists( 'custom_taxonomies', $saved ) ) {

			$settings['custom_taxonomies'] = $this->migrate_embedded_taxonomies( $settings['custom_post_types'] );

		}

		$settings['custom_taxonomies'] = $this->normalise_taxonomies( $settings['custom_taxonomies'] ?? [], $settings['custom_post_types'] );
		$settings['custom_fields']     = $this->normalise_fields( $settings['custom_fields'] ?? [], $settings['custom_post_types'] );

		return $settings;

	}

	/*
	SANITIZE
	-- Validates ordered post type definitions and prevents duplicate keys.
	---------------------------------------------------------- */

	public function sanitize( $input ): array {

		$input = is_array( $input ) ? $input : [];

		$post_types = $this->normalise_post_types( $input['custom_post_types'] ?? [] );

		return [
			'enabled'           => ! empty( $input['enabled'] ),
			'page_categories'   => ! empty( $input['page_categories'] ),
			'custom_post_types' => $post_types,
			'custom_taxonomies' => $this->normalise_taxonomies( $input['custom_taxonomies'] ?? [], $post_types ),
			'custom_fields'     => $this->normalise_fields( $input['custom_fields'] ?? [], $post_types ),
		];

	}

	/*
	RENDER SETTINGS
	-- Displays Blog naming, Page Categories, and the sortable post type editor.
	---------------------------------------------------------- */

	public function render_settings( array $settings ): void {

		$page_terms_url     = admin_url( 'edit-tags.php?taxonomy=' . self::PAGE_TAXONOMY . '&post_type=page' );
		$custom_types       = $this->normalise_post_types( $settings['custom_post_types'] ?? [] );
		$custom_taxonomies  = $this->normalise_taxonomies( $settings['custom_taxonomies'] ?? [], $custom_types );
		$custom_fields      = $this->normalise_fields( $settings['custom_fields'] ?? [], $custom_types );
		$post_type_options  = $this->post_type_options( $custom_types );
		$template_values    = [
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

		<div class="oa-cpt-section oa-custom-posts-box">
			<div class="oa-cpt-section-head">
				<div>
					<span class="oa-panel-kicker"><?php esc_html_e( 'Custom Posts', 'octave-addons' ); ?></span>
					<h3><?php esc_html_e( 'Post Types', 'octave-addons' ); ?></h3>
					<p><?php esc_html_e( 'Add multiple content types and drag them into the order they should appear in the WordPress admin menu.', 'octave-addons' ); ?></p>
				</div>
				<button type="button" class="button oa-cpt-add">
					<span class="oa-cpt-add-icon" aria-hidden="true">+</span>
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

		$this->render_taxonomy_editor( $custom_taxonomies, $post_type_options );
		$this->render_field_editor( $custom_fields, $post_type_options );

		?>

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
		$enabled                = ! empty( $post_type['enabled'] );

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
				<button type="button" class="oa-cpt-expand" aria-expanded="false">
					<span class="oa-cpt-expand-copy">
						<strong class="oa-cpt-item-title"><?= esc_html( '' !== $name ? $name : __( 'New post type', 'octave-addons' ) ); ?></strong>
						<span class="oa-cpt-key-preview"><?= esc_html( $key ); ?></span>
					</span>
					<span class="dashicons dashicons-arrow-down-alt2 oa-cpt-expand-icon" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php esc_html_e( 'Toggle post type settings', 'octave-addons' ); ?></span>
				</button>
				<div class="oa-cpt-enabled-summary">
					<span><?php esc_html_e( 'Enabled', 'octave-addons' ); ?></span>
					<label class="oa-switch">
						<input type="checkbox" class="oa-cpt-enabled-toggle" name="<?= esc_attr( $this->cpt_field_name( $index, 'enabled' ) ); ?>" value="1"<?= checked( $enabled, true, false ); ?>>
						<span class="oa-switch-slider"></span>
					</label>
				</div>
				<button type="button" class="oa-cpt-remove" aria-label="<?php esc_attr_e( 'Remove this post type', 'octave-addons' ); ?>">
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
				</button>
			</div>

			<div class="oa-cpt-groups oa-hidden">
				<fieldset class="oa-cpt-group oa-cpt-group--visibility">
					<legend><?php esc_html_e( 'Identity', 'octave-addons' ); ?></legend>
					<p class="oa-cpt-group-description"><?php esc_html_e( 'The labels editors see and the permanent key WordPress stores.', 'octave-addons' ); ?></p>
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

						<label class="oa-cpt-field oa-cpt-field--full">
							<span><?php esc_html_e( 'Post type key', 'octave-addons' ); ?></span>
							<input type="text" data-cpt-field="post_type" name="<?= esc_attr( $this->cpt_field_name( $index, 'post_type' ) ); ?>" value="<?= esc_attr( $key ); ?>" maxlength="20" pattern="[a-z0-9_]+" required<?= $saved ? ' readonly' : ''; ?>>
							<small><?= $saved ? esc_html__( 'Permanent after saving to protect existing content.', 'octave-addons' ) : esc_html__( 'Use an oa_ prefix; maximum 20 characters.', 'octave-addons' ); ?></small>
						</label>
					</div>
				</fieldset>

				<fieldset class="oa-cpt-group">
					<legend><?php esc_html_e( 'Visibility', 'octave-addons' ); ?></legend>
					<p class="oa-cpt-group-description"><?php esc_html_e( 'Control whether the type is registered and available publicly.', 'octave-addons' ); ?></p>
					<div class="oa-cpt-fields oa-cpt-fields--switches">
						<div class="oa-cpt-field oa-cpt-switch-field">
							<span><?php esc_html_e( 'Public', 'octave-addons' ); ?></span>
							<label class="oa-switch">
								<input type="checkbox" name="<?= esc_attr( $this->cpt_field_name( $index, 'public' ) ); ?>" value="1"<?= checked( $public, true, false ); ?>>
								<span class="oa-switch-slider"></span>
							</label>
							<small><?php esc_html_e( 'Allow frontend URLs, searches and navigation use.', 'octave-addons' ); ?></small>
						</div>
					</div>
				</fieldset>

				<fieldset class="oa-cpt-group">
					<legend><?php esc_html_e( 'URLs', 'octave-addons' ); ?></legend>
					<p class="oa-cpt-group-description"><?php esc_html_e( 'Set the individual item path and optionally expose a listing archive.', 'octave-addons' ); ?></p>
					<div class="oa-cpt-fields">
						<label class="oa-cpt-field">
							<span><?php esc_html_e( 'Single URL slug', 'octave-addons' ); ?></span>
							<input type="text" data-cpt-field="post_slug" name="<?= esc_attr( $this->cpt_field_name( $index, 'post_slug' ) ); ?>" value="<?= esc_attr( $post_slug ); ?>" placeholder="project" required>
							<small><?php esc_html_e( 'Used before an individual item’s URL.', 'octave-addons' ); ?></small>
						</label>

						<div class="oa-cpt-field oa-cpt-switch-field">
							<span><?php esc_html_e( 'Has Archive', 'octave-addons' ); ?></span>
							<label class="oa-switch">
								<input type="checkbox" class="oa-cpt-archive-toggle" name="<?= esc_attr( $this->cpt_field_name( $index, 'has_archive' ) ); ?>" value="1"<?= checked( $has_archive, true, false ); ?>>
								<span class="oa-switch-slider"></span>
							</label>
							<small><?php esc_html_e( 'Enable a public listing page.', 'octave-addons' ); ?></small>
						</div>

						<label class="oa-cpt-field oa-cpt-field--full oa-cpt-archive-field<?= $has_archive ? '' : ' oa-hidden'; ?>">
							<span><?php esc_html_e( 'Archive slug', 'octave-addons' ); ?></span>
							<input type="text" data-cpt-field="archive_slug" name="<?= esc_attr( $this->cpt_field_name( $index, 'archive_slug' ) ); ?>" value="<?= esc_attr( $archive_slug ); ?>" placeholder="projects">
							<small><?php esc_html_e( 'The archive URL path, without slashes.', 'octave-addons' ); ?></small>
						</label>
					</div>
				</fieldset>
			</div>
		</article>

		<?php

	}

	/*
	RENDER TAXONOMY EDITOR
	-- Displays reusable category definitions and multi-post assignments.
	---------------------------------------------------------- */

	protected function render_taxonomy_editor( array $taxonomies, array $post_types ): void {

		$template = [
			'enabled'      => true,
			'name'         => '',
			'singular_name' => '',
			'taxonomy'     => 'oa_category',
			'slug'         => '',
			'hierarchical' => true,
			'public'       => true,
			'post_types'   => [],
		];

		?>

		<div class="oa-cpt-section oa-custom-posts-box oa-collection" data-collection="custom_taxonomies" data-new-label="<?php esc_attr_e( 'New post category', 'octave-addons' ); ?>">
			<div class="oa-cpt-section-head">
				<div>
					<span class="oa-panel-kicker"><?php esc_html_e( 'Custom Posts', 'octave-addons' ); ?></span>
					<h3><?php esc_html_e( 'Post Categories', 'octave-addons' ); ?></h3>
					<p><?php esc_html_e( 'Create reusable taxonomies, then assign each one to any combination of posts, pages, or custom post types.', 'octave-addons' ); ?></p>
				</div>
				<button type="button" class="button oa-cpt-add oa-collection-add"><span class="oa-cpt-add-icon" aria-hidden="true">+</span><?php esc_html_e( 'Add post category', 'octave-addons' ); ?></button>
			</div>

			<div class="oa-cpt-list oa-collection-list" data-empty-text="<?php esc_attr_e( 'No custom post categories have been added.', 'octave-addons' ); ?>">

				<?php

				foreach ( $taxonomies as $index => $taxonomy ) {

					$this->render_taxonomy_card( (string) $index, $taxonomy, $post_types, true );

				}

				?>

			</div>

			<template class="oa-collection-template"><?php $this->render_taxonomy_card( '__INDEX__', $template, $post_types, false ); ?></template>
		</div>

		<?php

	}

	/*
	RENDER TAXONOMY CARD
	-- Outputs one taxonomy definition with friendly assignment controls.
	---------------------------------------------------------- */

	protected function render_taxonomy_card( string $index, array $taxonomy, array $post_types, bool $saved ): void {

		$name     = (string) ( $taxonomy['name'] ?? '' );
		$key      = (string) ( $taxonomy['taxonomy'] ?? '' );
		$assigned = is_array( $taxonomy['post_types'] ?? null ) ? $taxonomy['post_types'] : [];

		?>

		<article class="oa-cpt-item oa-collection-item" data-saved="<?= $saved ? 'true' : 'false'; ?>">
			<div class="oa-cpt-item-head">
				<button type="button" class="oa-cpt-expand oa-collection-expand" aria-expanded="<?= $saved ? 'false' : 'true'; ?>">
					<span class="oa-cpt-expand-copy"><strong class="oa-cpt-item-title"><?= esc_html( '' !== $name ? $name : __( 'New post category', 'octave-addons' ) ); ?></strong><span class="oa-cpt-key-preview"><?= esc_html( $key ); ?></span></span>
					<span class="dashicons dashicons-arrow-down-alt2 oa-cpt-expand-icon" aria-hidden="true"></span>
				</button>
				<div class="oa-cpt-enabled-summary"><span><?php esc_html_e( 'Enabled', 'octave-addons' ); ?></span><label class="oa-switch"><input type="checkbox" data-role="enabled" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'enabled' ) ); ?>" value="1"<?= checked( ! empty( $taxonomy['enabled'] ), true, false ); ?>><span class="oa-switch-slider"></span></label></div>
				<button type="button" class="oa-cpt-remove oa-collection-remove" aria-label="<?php esc_attr_e( 'Remove this post category', 'octave-addons' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
			</div>

			<div class="oa-cpt-groups oa-collection-body<?= $saved ? ' oa-hidden' : ''; ?>">
				<fieldset class="oa-cpt-group">
					<legend><?php esc_html_e( 'Identity and URLs', 'octave-addons' ); ?></legend>
					<p class="oa-cpt-group-description"><?php esc_html_e( 'The key becomes permanent after the first save. Categories behave hierarchically; tags do not.', 'octave-addons' ); ?></p>
					<div class="oa-cpt-fields">
						<label class="oa-cpt-field"><span><?php esc_html_e( 'Plural name', 'octave-addons' ); ?></span><input type="text" data-role="title" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'name' ) ); ?>" value="<?= esc_attr( $name ); ?>" placeholder="Project Categories" required></label>
						<label class="oa-cpt-field"><span><?php esc_html_e( 'Singular name', 'octave-addons' ); ?></span><input type="text" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'singular_name' ) ); ?>" value="<?= esc_attr( (string) ( $taxonomy['singular_name'] ?? '' ) ); ?>" placeholder="Project Category" required></label>
						<label class="oa-cpt-field"><span><?php esc_html_e( 'Taxonomy key', 'octave-addons' ); ?></span><input type="text" data-role="key" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'taxonomy' ) ); ?>" value="<?= esc_attr( $key ); ?>" maxlength="32" pattern="[a-z0-9_]+" required<?= $saved ? ' readonly' : ''; ?>></label>
						<label class="oa-cpt-field"><span><?php esc_html_e( 'URL slug', 'octave-addons' ); ?></span><input type="text" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'slug' ) ); ?>" value="<?= esc_attr( (string) ( $taxonomy['slug'] ?? '' ) ); ?>" placeholder="project-category" required></label>
						<div class="oa-cpt-field oa-cpt-switch-field"><span><?php esc_html_e( 'Hierarchical', 'octave-addons' ); ?></span><label class="oa-switch"><input type="checkbox" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'hierarchical' ) ); ?>" value="1"<?= checked( ! empty( $taxonomy['hierarchical'] ), true, false ); ?>><span class="oa-switch-slider"></span></label><small><?php esc_html_e( 'Enable parent and child terms like Categories.', 'octave-addons' ); ?></small></div>
						<div class="oa-cpt-field oa-cpt-switch-field"><span><?php esc_html_e( 'Public archives', 'octave-addons' ); ?></span><label class="oa-switch"><input type="checkbox" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'public' ) ); ?>" value="1"<?= checked( ! empty( $taxonomy['public'] ), true, false ); ?>><span class="oa-switch-slider"></span></label><small><?php esc_html_e( 'Expose term archive URLs and navigation options.', 'octave-addons' ); ?></small></div>
					</div>
				</fieldset>

				<?php $this->render_assignment_group( 'custom_taxonomies', $index, $assigned, $post_types ); ?>
			</div>
		</article>

		<?php

	}

	/*
	RENDER FIELD EDITOR
	-- Displays ACF-style field definitions assigned to custom post types.
	---------------------------------------------------------- */

	protected function render_field_editor( array $fields, array $post_types ): void {

		$template = [ 'enabled' => true, 'label' => '', 'name' => 'field_name', 'type' => 'text', 'default_value' => '', 'choices' => '', 'description' => '', 'required' => false, 'post_types' => [] ];

		?>

		<div class="oa-cpt-section oa-custom-posts-box oa-collection" data-collection="custom_fields" data-new-label="<?php esc_attr_e( 'New post field', 'octave-addons' ); ?>">
			<div class="oa-cpt-section-head">
				<div>
					<span class="oa-panel-kicker"><?php esc_html_e( 'Custom Posts', 'octave-addons' ); ?></span>
					<h3><?php esc_html_e( 'Post Fields', 'octave-addons' ); ?></h3>
					<p><?php esc_html_e( 'Define typed fields for post editors. Values are stored as registered WordPress post meta and appear under Octave in Breakdance Dynamic Data.', 'octave-addons' ); ?></p>
				</div>
				<button type="button" class="button oa-cpt-add oa-collection-add"><span class="oa-cpt-add-icon" aria-hidden="true">+</span><?php esc_html_e( 'Add post field', 'octave-addons' ); ?></button>
			</div>

			<div class="oa-cpt-list oa-collection-list" data-empty-text="<?php esc_attr_e( 'No custom post fields have been added.', 'octave-addons' ); ?>">

				<?php

				foreach ( $fields as $index => $field ) {

					$this->render_field_card( (string) $index, $field, $post_types, true );

				}

				?>

			</div>

			<template class="oa-collection-template"><?php $this->render_field_card( '__INDEX__', $template, $post_types, false ); ?></template>
		</div>

		<?php

	}

	/*
	RENDER FIELD CARD
	-- Outputs one typed custom field definition.
	---------------------------------------------------------- */

	protected function render_field_card( string $index, array $field, array $post_types, bool $saved ): void {

		$label    = (string) ( $field['label'] ?? '' );
		$name     = (string) ( $field['name'] ?? '' );
		$type     = (string) ( $field['type'] ?? 'text' );
		$assigned = is_array( $field['post_types'] ?? null ) ? $field['post_types'] : [];
		$types    = $this->field_types();

		?>

		<article class="oa-cpt-item oa-collection-item" data-saved="<?= $saved ? 'true' : 'false'; ?>">
			<div class="oa-cpt-item-head">
				<button type="button" class="oa-cpt-expand oa-collection-expand" aria-expanded="<?= $saved ? 'false' : 'true'; ?>"><span class="oa-cpt-expand-copy"><strong class="oa-cpt-item-title"><?= esc_html( '' !== $label ? $label : __( 'New post field', 'octave-addons' ) ); ?></strong><span class="oa-cpt-key-preview"><?= esc_html( $name ); ?></span></span><span class="dashicons dashicons-arrow-down-alt2 oa-cpt-expand-icon" aria-hidden="true"></span></button>
				<div class="oa-cpt-enabled-summary"><span><?php esc_html_e( 'Enabled', 'octave-addons' ); ?></span><label class="oa-switch"><input type="checkbox" data-role="enabled" name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'enabled' ) ); ?>" value="1"<?= checked( ! empty( $field['enabled'] ), true, false ); ?>><span class="oa-switch-slider"></span></label></div>
				<button type="button" class="oa-cpt-remove oa-collection-remove" aria-label="<?php esc_attr_e( 'Remove this post field', 'octave-addons' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
			</div>

			<div class="oa-cpt-groups oa-collection-body<?= $saved ? ' oa-hidden' : ''; ?>">
				<fieldset class="oa-cpt-group">
					<legend><?php esc_html_e( 'Field settings', 'octave-addons' ); ?></legend>
					<p class="oa-cpt-group-description"><?php esc_html_e( 'The field name becomes the permanent, namespaced post-meta key after saving.', 'octave-addons' ); ?></p>
					<div class="oa-cpt-fields">
						<label class="oa-cpt-field"><span><?php esc_html_e( 'Label', 'octave-addons' ); ?></span><input type="text" data-role="title" name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'label' ) ); ?>" value="<?= esc_attr( $label ); ?>" placeholder="Client name" required></label>
						<label class="oa-cpt-field"><span><?php esc_html_e( 'Field name', 'octave-addons' ); ?></span><input type="text" data-role="key" name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'name' ) ); ?>" value="<?= esc_attr( $name ); ?>" maxlength="40" pattern="[a-z0-9_]+" required<?= $saved ? ' readonly' : ''; ?>></label>
						<label class="oa-cpt-field"><span><?php esc_html_e( 'Field type', 'octave-addons' ); ?></span><select data-field-type name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'type' ) ); ?>"><?php foreach ( $types as $type_key => $type_label ) : ?><option value="<?= esc_attr( $type_key ); ?>"<?= selected( $type, $type_key, false ); ?>><?= esc_html( $type_label ); ?></option><?php endforeach; ?></select></label>
						<label class="oa-cpt-field"><span><?php esc_html_e( 'Default value', 'octave-addons' ); ?></span><input type="text" name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'default_value' ) ); ?>" value="<?= esc_attr( (string) ( $field['default_value'] ?? '' ) ); ?>"><small><?php esc_html_e( 'Shown until a post has its own saved value.', 'octave-addons' ); ?></small></label>
						<label class="oa-cpt-field oa-cpt-field--full oa-field-choices<?= in_array( $type, [ 'select', 'multiselect', 'radio' ], true ) ? '' : ' oa-hidden'; ?>"><span><?php esc_html_e( 'Choices', 'octave-addons' ); ?></span><textarea name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'choices' ) ); ?>" rows="5" placeholder="featured : Featured&#10;standard : Standard"><?= esc_textarea( (string) ( $field['choices'] ?? '' ) ); ?></textarea><small><?php esc_html_e( 'One per line. Use value : Label or a simple value.', 'octave-addons' ); ?></small></label>
						<label class="oa-cpt-field oa-cpt-field--full"><span><?php esc_html_e( 'Instructions for editors', 'octave-addons' ); ?></span><textarea name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'description' ) ); ?>" rows="3"><?= esc_textarea( (string) ( $field['description'] ?? '' ) ); ?></textarea></label>
						<div class="oa-cpt-field oa-cpt-switch-field"><span><?php esc_html_e( 'Required', 'octave-addons' ); ?></span><label class="oa-switch"><input type="checkbox" name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'required' ) ); ?>" value="1"<?= checked( ! empty( $field['required'] ), true, false ); ?>><span class="oa-switch-slider"></span></label><small><?php esc_html_e( 'Prompts editors to complete the field in the post screen.', 'octave-addons' ); ?></small></div>
					</div>
				</fieldset>

				<?php $this->render_assignment_group( 'custom_fields', $index, $assigned, $post_types, true ); ?>
			</div>
		</article>

		<?php

	}

	/*
	RENDER ASSIGNMENT GROUP
	-- Provides clear checkbox assignments for taxonomies and fields.
	---------------------------------------------------------- */

	protected function render_assignment_group( string $collection, string $index, array $assigned, array $post_types, bool $custom_only = false ): void {

		$assignable = $custom_only ? array_diff_key( $post_types, array_flip( [ 'post', 'page' ] ) ) : $post_types;

		?>

		<fieldset class="oa-cpt-group">
			<legend><?php esc_html_e( 'Assigned post types', 'octave-addons' ); ?></legend>
			<p class="oa-cpt-group-description"><?= $custom_only ? esc_html__( 'Choose where editors should see this field.', 'octave-addons' ) : esc_html__( 'Choose every content area that should use this taxonomy.', 'octave-addons' ); ?></p>
			<div class="oa-assignment-grid">

				<?php

				if ( empty( $assignable ) ) :

				?>

				<p class="oa-assignment-empty"><?php esc_html_e( 'Create and save a post type before assigning custom fields.', 'octave-addons' ); ?></p>

				<?php

				endif;

				foreach ( $assignable as $post_type => $label ) :

				?>

				<label class="oa-assignment-option"><input type="checkbox" name="<?= esc_attr( $this->collection_field_name( $collection, $index, 'post_types' ) ); ?>[]" value="<?= esc_attr( $post_type ); ?>"<?= checked( in_array( $post_type, $assigned, true ), true, false ); ?>><span><strong><?= esc_html( $label ); ?></strong><small><?= esc_html( $post_type ); ?></small></span></label>

				<?php

				endforeach;

				?>

			</div>
		</fieldset>

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

		$post_types = $this->normalise_post_types( $settings['custom_post_types'] ?? [] );

		foreach ( $this->normalise_taxonomies( $settings['custom_taxonomies'] ?? [], $post_types ) as $taxonomy ) {

			if ( ! empty( $taxonomy['enabled'] ) ) {

				$this->register_custom_taxonomy( $taxonomy );

			}

		}

		$fields = $this->normalise_fields( $settings['custom_fields'] ?? [], $post_types );

		new Octave_Addons_Custom_Post_Fields( $fields, $this->post_type_options( $post_types, false ) );

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
		$is_public    = ! empty( $post_type['public'] );

		if ( post_type_exists( $key ) ) {

			return;

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
	-- Registers one reusable taxonomy against all selected post types.
	---------------------------------------------------------- */

	protected function register_custom_taxonomy( array $definition ): void {

		$taxonomy = $definition['taxonomy'];
		$name     = $definition['name'];
		$singular = $definition['singular_name'];
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
			$definition['post_types'],
			[
				'labels'            => $labels,
				'description'       => sprintf( __( 'Octave-managed %s.', 'octave-addons' ), $name ),
				'public'            => ! empty( $definition['public'] ),
				'hierarchical'      => ! empty( $definition['hierarchical'] ),
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => ! empty( $definition['public'] ),
				'show_tagcloud'     => false,
				'show_in_rest'      => true,
				'query_var'         => ! empty( $definition['public'] ),
				'rewrite'           => ! empty( $definition['public'] )
					? [
						'slug'         => $definition['slug'],
						'with_front'   => false,
						'hierarchical' => ! empty( $definition['hierarchical'] ),
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
			'custom_taxonomies' => $settings['custom_taxonomies'],
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
	NORMALISE TAXONOMIES
	-- Produces unique reusable taxonomy definitions and safe assignments.
	---------------------------------------------------------- */

	protected function normalise_taxonomies( $taxonomies, array $post_types ): array {

		if ( ! is_array( $taxonomies ) ) {

			return [];

		}

		$clean     = [];
		$used      = [];
		$available = array_keys( $this->post_type_options( $post_types ) );

		foreach ( array_slice( $taxonomies, 0, 30 ) as $index => $taxonomy ) {

			if ( ! is_array( $taxonomy ) ) {

				continue;

			}

			$name     = sanitize_text_field( wp_unslash( (string) ( $taxonomy['name'] ?? '' ) ) );
			$singular = sanitize_text_field( wp_unslash( (string) ( $taxonomy['singular_name'] ?? '' ) ) );
			$key      = substr( sanitize_key( wp_unslash( (string) ( $taxonomy['taxonomy'] ?? '' ) ) ), 0, 32 );

			if ( self::CASE_STUDY_TAXONOMY !== $key ) {

				$key = 'oa_' . ltrim( preg_replace( '/^oa_+/', '', $key ), '_' );

			}

			if ( 'oa_' === $key ) {

				$key = 'oa_category_' . ( $index + 1 );

			}

			if ( '' === $name || '' === $singular || isset( $used[ $key ] ) ) {

				continue;

			}

			$assigned = isset( $taxonomy['post_types'] ) && is_array( $taxonomy['post_types'] )
				? array_values( array_intersect( array_map( 'sanitize_key', $taxonomy['post_types'] ), $available ) )
				: [];

			if ( empty( $assigned ) ) {

				continue;

			}

			$used[ $key ] = true;
			$clean[]      = [
				'enabled'      => ! empty( $taxonomy['enabled'] ),
				'name'         => $name,
				'singular_name' => $singular,
				'taxonomy'     => $key,
				'slug'         => self::sanitize_rewrite_path( $taxonomy['slug'] ?? '', sanitize_title( $singular ) ),
				'hierarchical' => ! empty( $taxonomy['hierarchical'] ),
				'public'       => ! empty( $taxonomy['public'] ),
				'post_types'   => $assigned,
			];

		}

		return $clean;

	}

	/*
	NORMALISE FIELDS
	-- Produces safe typed meta definitions with immutable names.
	---------------------------------------------------------- */

	protected function normalise_fields( $fields, array $post_types ): array {

		if ( ! is_array( $fields ) ) {

			return [];

		}

		$clean     = [];
		$used      = [];
		$available = array_diff( array_keys( $this->post_type_options( $post_types ) ), [ 'post', 'page' ] );
		$types     = array_keys( $this->field_types() );

		foreach ( array_slice( $fields, 0, 50 ) as $index => $field ) {

			if ( ! is_array( $field ) ) {

				continue;

			}

			$label = sanitize_text_field( wp_unslash( (string) ( $field['label'] ?? '' ) ) );
			$name  = substr( sanitize_key( wp_unslash( (string) ( $field['name'] ?? '' ) ) ), 0, 40 );
			$type  = sanitize_key( $field['type'] ?? 'text' );

			if ( '' === $name ) {

				$name = 'field_' . ( $index + 1 );

			}

			if ( '' === $label || isset( $used[ $name ] ) ) {

				continue;

			}

			$type     = in_array( $type, $types, true ) ? $type : 'text';
			$assigned = isset( $field['post_types'] ) && is_array( $field['post_types'] )
				? array_values( array_intersect( array_map( 'sanitize_key', $field['post_types'] ), $available ) )
				: [];

			if ( empty( $assigned ) ) {

				continue;

			}

			$used[ $name ] = true;
			$clean[]       = [
				'enabled'       => ! empty( $field['enabled'] ),
				'label'         => $label,
				'name'          => $name,
				'meta_key'      => '_octave_' . $name,
				'type'          => $type,
				'default_value' => 'wysiwyg' === $type
					? wp_kses_post( wp_unslash( (string) ( $field['default_value'] ?? '' ) ) )
					: sanitize_text_field( wp_unslash( (string) ( $field['default_value'] ?? '' ) ) ),
				'choices'       => sanitize_textarea_field( wp_unslash( (string) ( $field['choices'] ?? '' ) ) ),
				'description'   => sanitize_textarea_field( wp_unslash( (string) ( $field['description'] ?? '' ) ) ),
				'required'      => ! empty( $field['required'] ),
				'post_types'    => $assigned,
			];

		}

		return $clean;

	}

	/*
	MIGRATE EMBEDDED TAXONOMIES
	-- Converts the previous one-taxonomy-per-post-type schema without new keys.
	---------------------------------------------------------- */

	protected function migrate_embedded_taxonomies( array $post_types ): array {

		$taxonomies = [];

		foreach ( $post_types as $post_type ) {

			if ( empty( $post_type['categories'] ) ) {

				continue;

			}

			$taxonomies[] = [
				'enabled'       => true,
				'name'          => $post_type['taxonomy_name'],
				'singular_name' => $post_type['taxonomy_singular_name'],
				'taxonomy'      => $post_type['taxonomy'],
				'slug'          => $post_type['taxonomy_slug'],
				'hierarchical'  => true,
				'public'        => ! empty( $post_type['public'] ),
				'post_types'    => [ $post_type['post_type'] ],
			];

		}

		return $taxonomies;

	}

	/*
	POST TYPE OPTIONS
	-- Returns built-in and managed content areas for assignment controls.
	---------------------------------------------------------- */

	protected function post_type_options( array $post_types, bool $include_builtin = true ): array {

		$options = $include_builtin
			? [
				'post' => __( 'Blogs', 'octave-addons' ),
				'page' => __( 'Pages', 'octave-addons' ),
			]
			: [];

		foreach ( $post_types as $post_type ) {

			$options[ $post_type['post_type'] ] = $post_type['name'];

		}

		return $options;

	}

	/*
	FIELD TYPES
	-- Lists supported standard controls and their admin labels.
	---------------------------------------------------------- */

	protected function field_types(): array {

		return [
			'text'        => __( 'Text', 'octave-addons' ),
			'textarea'    => __( 'Textarea', 'octave-addons' ),
			'wysiwyg'     => __( 'WYSIWYG editor', 'octave-addons' ),
			'email'       => __( 'Email', 'octave-addons' ),
			'url'         => __( 'URL', 'octave-addons' ),
			'tel'         => __( 'Telephone', 'octave-addons' ),
			'number'      => __( 'Number', 'octave-addons' ),
			'date'        => __( 'Date', 'octave-addons' ),
			'time'        => __( 'Time', 'octave-addons' ),
			'datetime'    => __( 'Date and time', 'octave-addons' ),
			'month'       => __( 'Month', 'octave-addons' ),
			'week'        => __( 'Week', 'octave-addons' ),
			'color'       => __( 'Colour', 'octave-addons' ),
			'range'       => __( 'Range', 'octave-addons' ),
			'checkbox'    => __( 'True / false', 'octave-addons' ),
			'radio'       => __( 'Radio buttons', 'octave-addons' ),
			'select'      => __( 'Select', 'octave-addons' ),
			'multiselect' => __( 'Multi-select', 'octave-addons' ),
			'image'       => __( 'Image', 'octave-addons' ),
			'file'        => __( 'File', 'octave-addons' ),
		];

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
	COLLECTION FIELD NAME
	-- Produces a nested Settings API name for taxonomies and post fields.
	---------------------------------------------------------- */

	protected function collection_field_name( string $collection, string $index, string $key ): string {

		return sprintf( '%s[%s][%s][%s][%s]', OCTAVE_ADDONS_OPTION_KEY, $this->get_id(), $collection, $index, $key );

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
