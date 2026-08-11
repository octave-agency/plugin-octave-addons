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

	protected const PAGE_TAXONOMY          = 'octave_page_category';
	protected const CASE_STUDY_POST_TYPE   = 'octave_case_study';
	protected const CASE_STUDY_TAXONOMY    = 'octave_case_category';
	protected const OVERVIEW_PREVIEW_LIMIT = 6;

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
			'blog_labels'       => false,
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

		if ( ! array_key_exists( 'blog_labels', $saved ) && ! empty( $saved['enabled'] ) ) {

			$settings['blog_labels'] = true;

		}

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
		$taxonomies = array_merge(
			is_array( $input['custom_taxonomies'] ?? null ) ? $input['custom_taxonomies'] : [],
			$this->preserved_collection( $input, 'custom_taxonomies' )
		);
		$fields     = array_merge(
			is_array( $input['custom_fields'] ?? null ) ? $input['custom_fields'] : [],
			$this->preserved_collection( $input, 'custom_fields' )
		);

		$taxonomies = $this->normalise_taxonomies( $taxonomies, $post_types );
		$fields     = $this->normalise_fields( $fields, $post_types );

		$this->apply_starter_content( $input['custom_post_types'] ?? [], $post_types, $taxonomies, $fields );

		return [
			'enabled'           => ! empty( $input['enabled'] ),
			'blog_labels'       => ! empty( $input['blog_labels'] ),
			'page_categories'   => ! empty( $input['page_categories'] ),
			'custom_post_types' => $post_types,
			'custom_taxonomies' => $this->normalise_taxonomies( $taxonomies, $post_types ),
			'custom_fields'     => $this->normalise_fields( $fields, $post_types ),
		];

	}

	/*
	APPLY STARTER CONTENT
	-- Turns the optional first category and field on a brand new post type card
	-- into full reusable definitions already assigned to that post type.
	---------------------------------------------------------- */

	protected function apply_starter_content( $submitted, array $post_types, array &$taxonomies, array &$fields ): void {

		if ( ! is_array( $submitted ) ) {

			return;

		}

		$keys = array_column( $post_types, 'post_type' );

		foreach ( $submitted as $index => $post_type ) {

			if ( ! is_array( $post_type ) ) {

				continue;

			}

			$key = $this->sanitize_post_type_key( $post_type['post_type'] ?? '', (int) $index );

			if ( ! in_array( $key, $keys, true ) ) {

				continue;

			}

			$taxonomy_name = sanitize_text_field( wp_unslash( (string) ( $post_type['starter_taxonomy_name'] ?? '' ) ) );
			$field_label   = sanitize_text_field( wp_unslash( (string) ( $post_type['starter_field_label'] ?? '' ) ) );

			if ( '' !== $taxonomy_name ) {

				$singular = sanitize_text_field( wp_unslash( (string) ( $post_type['starter_taxonomy_singular_name'] ?? '' ) ) );

				$taxonomies[] = [
					'enabled'       => true,
					'name'          => $taxonomy_name,
					'singular_name' => '' !== $singular ? $singular : $taxonomy_name,
					'taxonomy'      => $this->unique_definition_key( 'oa_' . $taxonomy_name, 'oa_category', array_column( $taxonomies, 'taxonomy' ), 32 ),
					'slug'          => sanitize_title( $taxonomy_name ),
					'hierarchical'  => true,
					'public'        => true,
					'post_types'    => [ $key ],
				];

			}

			if ( '' !== $field_label ) {

				$fields[] = [
					'enabled'    => true,
					'label'      => $field_label,
					'name'       => $this->unique_definition_key( $field_label, 'field', array_column( $fields, 'name' ), 40 ),
					'type'       => sanitize_key( $post_type['starter_field_type'] ?? 'text' ),
					'post_types' => [ $key ],
				];

			}

		}

	}

	/*
	UNIQUE DEFINITION KEY
	-- Derives a storage key from a label without colliding with existing ones.
	---------------------------------------------------------- */

	protected function unique_definition_key( string $label, string $fallback, array $used, int $length ): string {

		$base = trim( str_replace( '-', '_', sanitize_title( $label ) ), '_' );

		if ( '' === $base ) {

			$base = $fallback;

		}

		$key   = substr( $base, 0, $length );
		$count = 2;

		while ( in_array( $key, $used, true ) ) {

			$suffix = '_' . $count;
			$key    = substr( $base, 0, $length - strlen( $suffix ) ) . $suffix;
			$count++;

		}

		return $key;

	}

	/*
	RENDER SETTINGS
	-- Displays Blog naming, Page Categories, the content overview, and the
	-- sortable post type editor.
	---------------------------------------------------------- */

	public function render_settings( array $settings ): void {

		$custom_types       = $this->normalise_post_types( $settings['custom_post_types'] ?? [] );
		$custom_taxonomies  = $this->normalise_taxonomies( $settings['custom_taxonomies'] ?? [], $custom_types );
		$custom_fields      = $this->normalise_fields( $settings['custom_fields'] ?? [], $custom_types );
		$post_type_options  = $this->post_type_options( $custom_types );
		$editor             = $this->current_editor( $custom_types );
		$template_values    = [
			'enabled'                => true,
			'name'                   => '',
			'singular_name'          => '',
			'post_type'              => 'oa_content',
			'menu_icon'              => 'dashicons-admin-post',
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

		if ( $editor ) {

			$this->render_focused_editor( $editor, $settings, $custom_types, $custom_taxonomies, $custom_fields, $post_type_options );

			return;

		}

		?>

		<section class="oa-builtin-content">
			<div class="oa-builtin-content-head">
				<div>
					<span class="oa-panel-kicker"><?php esc_html_e( 'WordPress content', 'octave-addons' ); ?></span>
					<h3><?php esc_html_e( 'Built-in content', 'octave-addons' ); ?></h3>
					<p><?php esc_html_e( 'Small enhancements for the Posts and Pages already provided by WordPress.', 'octave-addons' ); ?></p>
				</div>
				<span class="oa-builtin-content-badge"><?php esc_html_e( 'Core content', 'octave-addons' ); ?></span>
			</div>

			<div class="oa-builtin-content-grid">
				<article class="oa-builtin-content-card">
					<div class="oa-builtin-content-icon" aria-hidden="true">
						<span class="dashicons dashicons-admin-post"></span>
					</div>
					<div class="oa-builtin-content-copy">
						<span class="oa-builtin-content-type"><?php esc_html_e( 'Posts', 'octave-addons' ); ?></span>
						<h4 id="oa-blog-labels-label"><?php esc_html_e( 'Display as Blogs', 'octave-addons' ); ?></h4>
						<p><?php esc_html_e( 'Rename Posts to Blogs throughout the WordPress admin. The post type remains “post”, so content, queries, templates and URLs continue working normally.', 'octave-addons' ); ?></p>
					</div>
					<label class="oa-switch oa-builtin-content-switch">
						<input type="checkbox" name="<?= esc_attr( $this->field_name( 'blog_labels' ) ); ?>" value="1" aria-labelledby="oa-blog-labels-label"<?= checked( ! empty( $settings['blog_labels'] ), true, false ); ?>>
						<span class="oa-switch-slider"></span>
					</label>
				</article>

				<article class="oa-builtin-content-card">
					<div class="oa-builtin-content-icon" aria-hidden="true">
						<span class="dashicons dashicons-admin-page"></span>
					</div>
					<div class="oa-builtin-content-copy">
						<span class="oa-builtin-content-type"><?php esc_html_e( 'Pages', 'octave-addons' ); ?></span>
						<h4 id="oa-page-categories-label"><?php esc_html_e( 'Page Categories', 'octave-addons' ); ?></h4>
						<p><?php esc_html_e( 'Add an admin-only hierarchical category system for organising Pages without creating public category archives.', 'octave-addons' ); ?></p>
					</div>
					<label class="oa-switch oa-builtin-content-switch">
						<input type="checkbox" name="<?= esc_attr( $this->field_name( 'page_categories' ) ); ?>" value="1" aria-labelledby="oa-page-categories-label"<?= checked( ! empty( $settings['page_categories'] ), true, false ); ?>>
						<span class="oa-switch-slider"></span>
					</label>
				</article>
			</div>
		</section>

		<div class="oa-content-area-divider" aria-hidden="true">
			<span></span>
			<strong><?php esc_html_e( 'Custom content types', 'octave-addons' ); ?></strong>
			<span></span>
		</div>

		<?php

		$this->render_content_overview( $custom_types, $custom_taxonomies, $custom_fields );

		?>

		<div class="oa-cpt-section oa-custom-posts-box" id="oa-post-types">
			<div class="oa-cpt-section-head">
				<div>
					<span class="oa-panel-kicker"><?php esc_html_e( 'Step 1', 'octave-addons' ); ?></span>
					<h3><?php esc_html_e( 'Post Types', 'octave-addons' ); ?></h3>
					<p><?php esc_html_e( 'Add a content type, optionally give it a first category and field, then drag the cards into admin menu order.', 'octave-addons' ); ?></p>
				</div>
				<div class="oa-cpt-section-actions">
					<button type="button" class="button oa-cpt-add">
						<span class="oa-cpt-add-icon" aria-hidden="true">+</span>
						<?php esc_html_e( 'Add post type', 'octave-addons' ); ?>
					</button>
				</div>
			</div>

			<div class="oa-cpt-list" data-empty-text="<?php esc_attr_e( 'No custom post types have been added.', 'octave-addons' ); ?>">

				<?php

				foreach ( $custom_types as $index => $post_type ) {

					$this->render_post_type_card( (string) $index, $post_type, true, $custom_taxonomies, $custom_fields );

				}

				?>

			</div>
			<div class="oa-cpt-order-status screen-reader-text" role="status" aria-live="polite"></div>

			<template class="oa-cpt-template">
				<?php $this->render_post_type_card( '__INDEX__', $template_values, false ); ?>
			</template>
		</div>

		<?php

		$this->render_preserved_collection( 'custom_taxonomies', $custom_taxonomies );
		$this->render_preserved_collection( 'custom_fields', $custom_fields );

	}

	/*
	RENDER CONTENT OVERVIEW
	-- Summarises post types, categories and fields in one glanceable panel.
	-- Every card creates its own item and links out to the area that edits it,
	-- so the three parts of a content type can be built without hunting.
	---------------------------------------------------------- */

	protected function render_content_overview( array $post_types, array $taxonomies, array $fields ): void {

		$library_url    = $this->schema_url( 'library' );
		$field_types    = $this->field_types();
		$post_type_tips = [];
		$category_tips  = [];
		$field_tips     = [];

		foreach ( $post_types as $post_type ) {

			$post_type_tips[] = [
				'label'  => $post_type['name'],
				'meta'   => sprintf(
					/* translators: 1: number of categories, 2: number of content fields. */
					__( '%1$d categories · %2$d fields', 'octave-addons' ),
					count( $this->definitions_for_post_type( $taxonomies, $post_type['post_type'] ) ),
					count( $this->definitions_for_post_type( $fields, $post_type['post_type'] ) )
				),
				'url'    => '#oa-cpt-' . $post_type['post_type'],
				'target' => 'oa-cpt-' . $post_type['post_type'],
			];

		}

		foreach ( $taxonomies as $taxonomy ) {

			$category_tips[] = [
				'label' => $taxonomy['name'],
				'meta'  => empty( $taxonomy['hierarchical'] ) ? __( 'Tag', 'octave-addons' ) : __( 'Category', 'octave-addons' ),
				'url'   => $this->schema_url( 'taxonomy', $taxonomy['taxonomy'] ),
			];

		}

		foreach ( $fields as $field ) {

			$field_tips[] = [
				'label' => $field['label'],
				'meta'  => $field_types[ $field['type'] ] ?? __( 'Field', 'octave-addons' ),
				'url'   => $this->schema_url( 'field', $field['name'] ),
			];

		}

		?>

		<section class="oa-content-overview">
			<div class="oa-content-overview-head">
				<span class="oa-panel-kicker"><?php esc_html_e( 'Custom content types', 'octave-addons' ); ?></span>
				<h3><?php esc_html_e( 'Content overview', 'octave-addons' ); ?></h3>
				<p><?php esc_html_e( 'A content type is made of three parts. Create each one from here, then open any item to change its settings.', 'octave-addons' ); ?></p>
			</div>

			<div class="oa-overview-grid">

				<?php

				$this->render_overview_card(
					[
						'step'    => __( 'Step 1', 'octave-addons' ),
						'icon'    => 'dashicons-screenoptions',
						'title'   => __( 'Post types', 'octave-addons' ),
						'summary' => __( 'The content areas added to the WordPress admin menu.', 'octave-addons' ),
						'items'   => $post_type_tips,
						'empty'   => __( 'No post types yet. Add one to get started.', 'octave-addons' ),
						'more'    => '#oa-post-types',
						'actions' => [
							[
								'label'   => __( 'Add post type', 'octave-addons' ),
								'trigger' => true,
							],
						],
					]
				);

				$this->render_overview_card(
					[
						'step'    => __( 'Step 2', 'octave-addons' ),
						'icon'    => 'dashicons-category',
						'title'   => __( 'Categories', 'octave-addons' ),
						'summary' => __( 'Reusable taxonomies that group entries inside one or more post types.', 'octave-addons' ),
						'items'   => $category_tips,
						'empty'   => __( 'No categories yet. Add one and assign it to a post type.', 'octave-addons' ),
						'more'    => $library_url,
						'actions' => [
							[
								'label' => __( 'New category', 'octave-addons' ),
								'url'   => $this->schema_url( 'taxonomy', 'new' ),
							],
							[
								'label' => __( 'View all', 'octave-addons' ),
								'url'   => $library_url,
								'quiet' => true,
							],
						],
					]
				);

				$this->render_overview_card(
					[
						'step'    => __( 'Step 3', 'octave-addons' ),
						'icon'    => 'dashicons-feedback',
						'title'   => __( 'Content fields', 'octave-addons' ),
						'summary' => __( 'The typed inputs editors complete on the post screen.', 'octave-addons' ),
						'items'   => $field_tips,
						'empty'   => __( 'No content fields yet. Add one and assign it to a post type.', 'octave-addons' ),
						'more'    => $library_url,
						'actions' => [
							[
								'label' => __( 'New field', 'octave-addons' ),
								'url'   => $this->schema_url( 'field', 'new' ),
							],
							[
								'label' => __( 'View all', 'octave-addons' ),
								'url'   => $library_url,
								'quiet' => true,
							],
						],
					]
				);

				?>

			</div>
		</section>

		<?php

	}

	/*
	RENDER OVERVIEW CARD
	-- Outputs one summary pillar with its items capped to a readable preview.
	---------------------------------------------------------- */

	protected function render_overview_card( array $card ): void {

		$items   = $card['items'];
		$total   = count( $items );
		$preview = array_slice( $items, 0, self::OVERVIEW_PREVIEW_LIMIT );
		$hidden  = $total - count( $preview );

		?>

		<article class="oa-overview-card">
			<div class="oa-overview-card-head">
				<span class="oa-overview-card-icon" aria-hidden="true"><span class="dashicons <?= esc_attr( $card['icon'] ); ?>"></span></span>
				<div class="oa-overview-card-copy">
					<span class="oa-overview-card-step"><?= esc_html( $card['step'] ); ?></span>
					<h4><?= esc_html( $card['title'] ); ?></h4>
					<p><?= esc_html( $card['summary'] ); ?></p>
				</div>
				<span class="oa-overview-count"><?= esc_html( number_format_i18n( $total ) ); ?></span>
			</div>

			<div class="oa-overview-items">

				<?php

				if ( 0 === $total ) :

				?>

				<p class="oa-overview-empty"><?= esc_html( $card['empty'] ); ?></p>

				<?php

				endif;

				foreach ( $preview as $item ) :

				?>

				<a href="<?= esc_url( $item['url'] ); ?>" class="oa-overview-chip"<?= isset( $item['target'] ) ? ' data-target="' . esc_attr( $item['target'] ) . '"' : ''; ?>>
					<strong><?= esc_html( $item['label'] ); ?></strong>
					<span><?= esc_html( $item['meta'] ); ?></span>
				</a>

				<?php

				endforeach;

				if ( $hidden > 0 ) :

				?>

				<a href="<?= esc_url( $card['more'] ); ?>" class="oa-overview-chip oa-overview-chip--more">
					<?php

					printf(
						/* translators: %d: number of items not shown in the preview. */
						esc_html__( '+%d more', 'octave-addons' ),
						(int) $hidden
					);

					?>
				</a>

				<?php

				endif;

				?>

			</div>

			<div class="oa-overview-actions">

				<?php

				foreach ( $card['actions'] as $action ) :

					$classes = 'oa-overview-action' . ( empty( $action['quiet'] ) ? '' : ' is-quiet' );

					if ( ! empty( $action['trigger'] ) ) :

				?>

				<button type="button" class="<?= esc_attr( $classes ); ?>" data-oa-add-post-type><span aria-hidden="true">+</span><?= esc_html( $action['label'] ); ?></button>

				<?php

					else :

				?>

				<a href="<?= esc_url( $action['url'] ); ?>" class="<?= esc_attr( $classes ); ?>"><?= empty( $action['quiet'] ) ? '<span aria-hidden="true">+</span>' : ''; ?><?= esc_html( $action['label'] ); ?></a>

				<?php

					endif;

				endforeach;

				?>

			</div>
		</article>

		<?php

	}

	/*
	CURRENT EDITOR
	-- Resolves a focused category or field editor for a saved post type.
	---------------------------------------------------------- */

	protected function current_editor( array $post_types ): ?array {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only settings navigation.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( (string) $_GET['section'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only settings navigation.
		$key = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only settings navigation.
		$definition = isset( $_GET['definition'] ) ? sanitize_key( wp_unslash( (string) $_GET['definition'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only settings navigation.
		$context = isset( $_GET['context'] ) ? sanitize_key( wp_unslash( (string) $_GET['context'] ) ) : '';

		if ( 'library' === $section ) {

			return [ 'section' => $section ];

		}

		if ( in_array( $section, [ 'taxonomy', 'field' ], true ) && '' !== $definition ) {

			foreach ( $post_types as $post_type ) {

				if ( $context === $post_type['post_type'] ) {

					return [
						'section'    => $section,
						'definition' => $definition,
						'context'    => $context,
					];

				}

			}

			return [
				'section'    => $section,
				'definition' => $definition,
				'context'    => '',
			];

		}

		if ( ! in_array( $section, [ 'categories', 'fields' ], true ) || '' === $key ) {

			return null;

		}

		foreach ( $post_types as $post_type ) {

			if ( $key === $post_type['post_type'] ) {

				return [
					'section'   => $section,
					'post_type' => $post_type,
				];

			}

		}

		return null;

	}

	/*
	RENDER FOCUSED EDITOR
	-- Shows only the categories or fields belonging to one post type.
	---------------------------------------------------------- */

	protected function render_focused_editor( array $editor, array $settings, array $post_types, array $taxonomies, array $fields, array $post_type_options ): void {

		$section = $editor['section'];

		$this->render_hidden_value( $this->field_name( 'blog_labels' ), ! empty( $settings['blog_labels'] ) ? '1' : '0' );
		$this->render_hidden_value( $this->field_name( 'page_categories' ), ! empty( $settings['page_categories'] ) ? '1' : '0' );
		$this->render_hidden_collection( 'custom_post_types', $post_types );

		if ( 'library' === $section ) {

			$this->render_schema_library( $taxonomies, $fields, $post_type_options );
			$this->render_preserved_collection( 'custom_taxonomies', $taxonomies );
			$this->render_preserved_collection( 'custom_fields', $fields );

			return;

		}

		if ( in_array( $section, [ 'taxonomy', 'field' ], true ) ) {

			$this->render_single_definition_editor( $editor, $taxonomies, $fields, $post_type_options );

			return;

		}

		$post_type = $editor['post_type'];
		$key       = $post_type['post_type'];
		$menu_icon = $post_type['menu_icon'];
		$back_url  = $this->settings_url();

		?>

		<div class="oa-cpt-editor-nav">
			<a href="<?= esc_url( $back_url ); ?>" class="oa-cpt-back-link">
				<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
				<?php esc_html_e( 'All post types', 'octave-addons' ); ?>
			</a>
			<div class="oa-cpt-editor-context">
				<div class="oa-cpt-editor-context-icon" aria-hidden="true">
					<span class="dashicons <?= esc_attr( $menu_icon ); ?>"></span>
				</div>
				<div class="oa-cpt-editor-context-copy">
					<span class="oa-panel-kicker"><?php esc_html_e( 'Editing custom post type', 'octave-addons' ); ?></span>
					<div class="oa-cpt-editor-context-name">
						<h3><?= esc_html( $post_type['name'] ); ?></h3>
						<code><?= esc_html( $key ); ?></code>
					</div>
					<p><?= sprintf( esc_html__( 'These settings apply to every %s entry.', 'octave-addons' ), esc_html( strtolower( $post_type['singular_name'] ) ) ); ?></p>
				</div>
				<span class="oa-cpt-editor-current-section"><?= 'categories' === $section ? esc_html__( 'Editing categories', 'octave-addons' ) : esc_html__( 'Editing content fields', 'octave-addons' ); ?></span>
			</div>
			<div class="oa-cpt-editor-title">
				<h4><?= 'categories' === $section ? esc_html__( 'Post categories', 'octave-addons' ) : esc_html__( 'Content fields', 'octave-addons' ); ?></h4>
				<p><?= 'categories' === $section ? esc_html__( 'Create the categories used by this post type. A category can also be shared with other content areas.', 'octave-addons' ) : esc_html__( 'Build the content form editors will complete when creating this type of post.', 'octave-addons' ); ?></p>
			</div>
			<nav class="oa-cpt-editor-tabs" aria-label="<?php esc_attr_e( 'Post type content settings', 'octave-addons' ); ?>">
				<a href="<?= esc_url( $this->editor_url( $key, 'categories' ) ); ?>" class="<?= 'categories' === $section ? 'is-active' : ''; ?>"><?php esc_html_e( 'Categories', 'octave-addons' ); ?></a>
				<a href="<?= esc_url( $this->editor_url( $key, 'fields' ) ); ?>" class="<?= 'fields' === $section ? 'is-active' : ''; ?>"><?php esc_html_e( 'Content fields', 'octave-addons' ); ?></a>
			</nav>
		</div>

		<?php

		if ( 'categories' === $section ) {

			$this->render_assignment_manager( 'custom_taxonomies', $taxonomies, $key, $post_type_options );
			$this->render_preserved_collection( 'custom_fields', $fields );

			return;

		}

		$this->render_assignment_manager( 'custom_fields', $fields, $key, $post_type_options );
		$this->render_preserved_collection( 'custom_taxonomies', $taxonomies );

	}

	/*
	RENDER SCHEMA LIBRARY
	-- Lists reusable definitions without exposing their full settings at once.
	---------------------------------------------------------- */

	protected function render_schema_library( array $taxonomies, array $fields, array $post_types ): void {

		?>

		<div class="oa-schema-page-head">
			<a href="<?= esc_url( $this->settings_url() ); ?>" class="oa-cpt-back-link"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?php esc_html_e( 'All post types', 'octave-addons' ); ?></a>
			<span class="oa-panel-kicker"><?php esc_html_e( 'Reusable content definitions', 'octave-addons' ); ?></span>
			<h3><?php esc_html_e( 'Content schema library', 'octave-addons' ); ?></h3>
			<p><?php esc_html_e( 'Define each category or field once, then attach it to every content type that needs it. Open one definition to change its settings.', 'octave-addons' ); ?></p>
		</div>

		<?php

		$this->render_schema_collection( 'custom_taxonomies', $taxonomies, $post_types );
		$this->render_schema_collection( 'custom_fields', $fields, $post_types );

	}

	/*
	RENDER SCHEMA COLLECTION
	-- Outputs compact taxonomy or field summary cards.
	---------------------------------------------------------- */

	protected function render_schema_collection( string $collection, array $definitions, array $post_types ): void {

		$is_taxonomy = 'custom_taxonomies' === $collection;
		$title       = $is_taxonomy ? __( 'Post categories', 'octave-addons' ) : __( 'Content fields', 'octave-addons' );
		$description = $is_taxonomy
			? __( 'Reusable category and tag structures shared between content types.', 'octave-addons' )
			: __( 'Reusable typed values, including groups and repeaters, shared between post editors.', 'octave-addons' );
		$add_url     = $this->schema_url( $is_taxonomy ? 'taxonomy' : 'field', 'new' );

		?>

		<section class="oa-schema-collection">
			<div class="oa-schema-collection-head">
				<div>
					<h4><?= esc_html( $title ); ?></h4>
					<p><?= esc_html( $description ); ?></p>
				</div>
				<a href="<?= esc_url( $add_url ); ?>" class="button oa-schema-add"><span aria-hidden="true">+</span><?= $is_taxonomy ? esc_html__( 'Add category', 'octave-addons' ) : esc_html__( 'Add field', 'octave-addons' ); ?></a>
			</div>

			<div class="oa-schema-card-grid">

				<?php

				if ( empty( $definitions ) ) :

				?>

				<div class="oa-schema-empty"><?= $is_taxonomy ? esc_html__( 'No reusable post categories have been created.', 'octave-addons' ) : esc_html__( 'No reusable content fields have been created.', 'octave-addons' ); ?></div>

				<?php

				endif;

				foreach ( $definitions as $definition ) {

					$this->render_schema_card( $collection, $definition, $post_types );

				}

				?>

			</div>
		</section>

		<?php

	}

	/*
	RENDER SCHEMA CARD
	-- Summarizes one reusable definition and every assigned content area.
	---------------------------------------------------------- */

	protected function render_schema_card( string $collection, array $definition, array $post_types ): void {

		$is_taxonomy = 'custom_taxonomies' === $collection;
		$key         = $is_taxonomy ? $definition['taxonomy'] : $definition['name'];
		$label       = $is_taxonomy ? $definition['name'] : $definition['label'];
		$type        = $is_taxonomy
			? ( ! empty( $definition['hierarchical'] ) ? __( 'Category', 'octave-addons' ) : __( 'Tag', 'octave-addons' ) )
			: ( $this->field_types()[ $definition['type'] ] ?? __( 'Field', 'octave-addons' ) );
		$edit_url    = $this->schema_url( $is_taxonomy ? 'taxonomy' : 'field', $key );
		$assigned    = is_array( $definition['post_types'] ?? null ) ? $definition['post_types'] : [];

		?>

		<article class="oa-schema-card">
			<div class="oa-schema-card-icon" aria-hidden="true"><span class="dashicons <?= $is_taxonomy ? 'dashicons-category' : 'dashicons-feedback'; ?>"></span></div>
			<div class="oa-schema-card-copy">
				<div class="oa-schema-card-title"><strong><?= esc_html( $label ); ?></strong><span><?= esc_html( $type ); ?></span></div>
				<code><?= esc_html( $key ); ?></code>
				<div class="oa-schema-assignments">

					<?php

					foreach ( $assigned as $post_type ) {

						if ( isset( $post_types[ $post_type ] ) ) {

							echo '<span>' . esc_html( $post_types[ $post_type ] ) . '</span>';

						}

					}

					if ( empty( $assigned ) ) {

						echo '<span class="is-unassigned">' . esc_html__( 'Not assigned', 'octave-addons' ) . '</span>';

					}

					?>

				</div>
			</div>
			<a href="<?= esc_url( $edit_url ); ?>" class="button oa-schema-edit"><?php esc_html_e( 'Edit', 'octave-addons' ); ?><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>
		</article>

		<?php

	}

	/*
	RENDER ASSIGNMENT MANAGER
	-- Lets one post type attach reusable definitions without opening editors.
	---------------------------------------------------------- */

	protected function render_assignment_manager( string $collection, array $definitions, string $post_type, array $post_types ): void {

		$is_taxonomy = 'custom_taxonomies' === $collection;
		$title       = $is_taxonomy ? __( 'Available post categories', 'octave-addons' ) : __( 'Available content fields', 'octave-addons' );
		$add_url     = $this->schema_url( $is_taxonomy ? 'taxonomy' : 'field', 'new', $post_type );

		?>

		<section class="oa-assignment-manager">
			<div class="oa-assignment-manager-head">
				<div>
					<h3><?= esc_html( $title ); ?></h3>
					<p><?php esc_html_e( 'Switch a definition on here to use it for this post type. Its configuration remains shared everywhere it is assigned.', 'octave-addons' ); ?></p>
				</div>
				<div class="oa-assignment-manager-actions">
					<a href="<?= esc_url( $this->schema_url( 'library' ) ); ?>" class="button oa-schema-library-link"><?php esc_html_e( 'Open schema library', 'octave-addons' ); ?></a>
					<a href="<?= esc_url( $add_url ); ?>" class="button oa-schema-add"><span aria-hidden="true">+</span><?= $is_taxonomy ? esc_html__( 'New category', 'octave-addons' ) : esc_html__( 'New field', 'octave-addons' ); ?></a>
				</div>
			</div>

			<div class="oa-assignment-manager-list">

				<?php

				if ( empty( $definitions ) ) :

				?>

				<div class="oa-schema-empty"><?php esc_html_e( 'Nothing is available yet. Create the first reusable definition to get started.', 'octave-addons' ); ?></div>

				<?php

				endif;

				foreach ( array_values( $definitions ) as $index => $definition ) {

					$this->render_assignment_card( $collection, (string) $index, $definition, $post_type, $post_types );

				}

				?>

			</div>
		</section>

		<?php

	}

	/*
	RENDER ASSIGNMENT CARD
	-- Preserves definition data while exposing one post-type assignment switch.
	---------------------------------------------------------- */

	protected function render_assignment_card( string $collection, string $index, array $definition, string $post_type, array $post_types ): void {

		$is_taxonomy = 'custom_taxonomies' === $collection;
		$key         = $is_taxonomy ? $definition['taxonomy'] : $definition['name'];
		$label       = $is_taxonomy ? $definition['name'] : $definition['label'];
		$type        = $is_taxonomy
			? ( ! empty( $definition['hierarchical'] ) ? __( 'Category', 'octave-addons' ) : __( 'Tag', 'octave-addons' ) )
			: ( $this->field_types()[ $definition['type'] ] ?? __( 'Field', 'octave-addons' ) );
		$assigned    = is_array( $definition['post_types'] ?? null ) ? $definition['post_types'] : [];
		$edit_url    = $this->schema_url( $is_taxonomy ? 'taxonomy' : 'field', $key, $post_type );

		foreach ( $definition as $definition_key => $value ) {

			if ( 'post_types' !== $definition_key ) {

				$this->render_hidden_value( $this->collection_field_name( $collection, $index, $definition_key ), $value );

			}

		}

		foreach ( $assigned as $assigned_post_type ) {

			if ( $post_type !== $assigned_post_type ) {

				$this->render_hidden_value( $this->collection_field_name( $collection, $index, 'post_types' ) . '[]', $assigned_post_type );

			}

		}

		?>

		<article class="oa-assignment-card<?= in_array( $post_type, $assigned, true ) ? ' is-assigned' : ''; ?>" data-assigned-label="<?php esc_attr_e( 'Used here', 'octave-addons' ); ?>" data-unassigned-label="<?php esc_attr_e( 'Add here', 'octave-addons' ); ?>">
			<div class="oa-schema-card-icon" aria-hidden="true"><span class="dashicons <?= $is_taxonomy ? 'dashicons-category' : 'dashicons-feedback'; ?>"></span></div>
			<div class="oa-schema-card-copy">
				<div class="oa-schema-card-title"><strong><?= esc_html( $label ); ?></strong><span><?= esc_html( $type ); ?></span></div>
				<code><?= esc_html( $key ); ?></code>
				<div class="oa-schema-assignments">

					<?php

					foreach ( $assigned as $assigned_post_type ) {

						if ( $post_type !== $assigned_post_type && isset( $post_types[ $assigned_post_type ] ) ) {

							echo '<span>' . esc_html( $post_types[ $assigned_post_type ] ) . '</span>';

						}

					}

					?>

				</div>
			</div>
			<a href="<?= esc_url( $edit_url ); ?>" class="oa-assignment-edit"><?php esc_html_e( 'Edit definition', 'octave-addons' ); ?></a>
			<label class="oa-assignment-toggle">
				<span><?= in_array( $post_type, $assigned, true ) ? esc_html__( 'Used here', 'octave-addons' ) : esc_html__( 'Add here', 'octave-addons' ); ?></span>
				<span class="oa-switch"><input type="checkbox" name="<?= esc_attr( $this->collection_field_name( $collection, $index, 'post_types' ) ); ?>[]" value="<?= esc_attr( $post_type ); ?>"<?= checked( in_array( $post_type, $assigned, true ), true, false ); ?>><span class="oa-switch-slider"></span></span>
			</label>
		</article>

		<?php

	}

	/*
	RENDER SINGLE DEFINITION EDITOR
	-- Opens exactly one reusable taxonomy or field definition for editing.
	---------------------------------------------------------- */

	protected function render_single_definition_editor( array $editor, array $taxonomies, array $fields, array $post_types ): void {

		$is_taxonomy = 'taxonomy' === $editor['section'];
		$collection  = $is_taxonomy ? 'custom_taxonomies' : 'custom_fields';
		$definitions = $is_taxonomy ? $taxonomies : $fields;
		$definition  = $editor['definition'];
		$context     = (string) ( $editor['context'] ?? '' );
		$is_new      = 'new' === $definition;
		$selected    = null;
		$remaining   = [];
		$back_url    = '' !== $context
			? $this->editor_url( $context, $is_taxonomy ? 'categories' : 'fields' )
			: $this->schema_url( 'library' );
		$back_label  = '' !== $context
			? ( isset( $post_types[ $context ] ) ? $post_types[ $context ] : __( 'Post type', 'octave-addons' ) )
			: __( 'Content schema library', 'octave-addons' );

		foreach ( $definitions as $item ) {

			$item_key = $is_taxonomy ? $item['taxonomy'] : $item['name'];

			if ( ! $is_new && $definition === $item_key ) {

				$selected = $item;

			} else {

				$remaining[] = $item;

			}

		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress adds this after a successful Settings API save.
		if ( $is_new && ! empty( $_GET['settings-updated'] ) && ! empty( $definitions ) ) {

			$selected  = array_shift( $remaining );
			$is_new    = false;
			$definition = $is_taxonomy ? $selected['taxonomy'] : $selected['name'];

		}

		?>

		<div class="oa-schema-page-head oa-schema-page-head--editor">
			<a href="<?= esc_url( $back_url ); ?>" class="oa-cpt-back-link"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?= esc_html( $back_label ); ?></a>
			<span class="oa-panel-kicker"><?= $is_taxonomy ? esc_html__( 'Reusable post category', 'octave-addons' ) : esc_html__( 'Reusable content field', 'octave-addons' ); ?></span>
			<h3><?= $is_new ? ( $is_taxonomy ? esc_html__( 'Add post category', 'octave-addons' ) : esc_html__( 'Add content field', 'octave-addons' ) ) : sprintf( esc_html__( 'Editing: %s', 'octave-addons' ), esc_html( $is_taxonomy ? $selected['name'] : $selected['label'] ) ); ?></h3>
			<p><?php esc_html_e( 'Changes to this definition apply everywhere it is assigned. Use the assignment section to add it to more content types.', 'octave-addons' ); ?></p>
		</div>

		<?php

		if ( ! $is_new && null === $selected ) {

			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'This definition could not be found. Return to the schema library and choose an available item.', 'octave-addons' ) . '</p></div>';
			$this->render_preserved_collection( $collection, $definitions );

		} elseif ( $is_taxonomy ) {

			$this->render_taxonomy_editor( null === $selected ? [] : [ $selected ], $post_types, $is_new ? $context : '', true, $is_new );
			$this->render_preserved_collection( 'custom_taxonomies', $remaining );

		} else {

			$this->render_field_editor( null === $selected ? [] : [ $selected ], $post_types, $is_new ? $context : '', true, $is_new );
			$this->render_preserved_collection( 'custom_fields', $remaining );

		}

		if ( $is_taxonomy ) {

			$this->render_preserved_collection( 'custom_fields', $fields );

		} else {

			$this->render_preserved_collection( 'custom_taxonomies', $taxonomies );

		}

	}

	/*
	RENDER POST TYPE CARD
	-- Outputs one sortable custom post type definition.
	-- Saved cards summarise how much content structure is already attached;
	-- new cards offer a first category and field so all three arrive together.
	---------------------------------------------------------- */

	protected function render_post_type_card( string $index, array $post_type, bool $saved, array $taxonomies = [], array $fields = [] ): void {

		$name                   = (string) ( $post_type['name'] ?? '' );
		$singular_name          = (string) ( $post_type['singular_name'] ?? '' );
		$key                    = (string) ( $post_type['post_type'] ?? '' );
		$menu_icon              = (string) ( $post_type['menu_icon'] ?? 'dashicons-admin-post' );
		$post_slug              = (string) ( $post_type['post_slug'] ?? '' );
		$archive_slug           = (string) ( $post_type['archive_slug'] ?? '' );
		$public                 = ! empty( $post_type['public'] );
		$has_archive            = ! empty( $post_type['has_archive'] );
		$enabled                = ! empty( $post_type['enabled'] );
		$categories_url         = $saved ? $this->editor_url( $key, 'categories' ) : '';
		$fields_url             = $saved ? $this->editor_url( $key, 'fields' ) : '';
		$category_count         = $saved ? count( $this->definitions_for_post_type( $taxonomies, $key ) ) : 0;
		$field_count            = $saved ? count( $this->definitions_for_post_type( $fields, $key ) ) : 0;
		$dashicons              = $this->dashicons();

		?>

		<article class="oa-cpt-item" draggable="false" data-saved="<?= $saved ? 'true' : 'false'; ?>"<?= $saved ? ' id="oa-cpt-' . esc_attr( $key ) . '"' : ''; ?>>
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
						<span class="dashicons <?= esc_attr( $menu_icon ); ?> oa-cpt-item-icon oa-cpt-live-icon" aria-hidden="true"></span>
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

			<?php if ( $saved ) : ?>
			<div class="oa-cpt-content-actions">
				<div>
					<strong><?php esc_html_e( 'Content setup', 'octave-addons' ); ?></strong>
					<span><?php esc_html_e( 'Choose what editors can categorize and complete.', 'octave-addons' ); ?></span>
				</div>
				<a href="<?= esc_url( $categories_url ); ?>" class="button oa-cpt-content-link"><span class="dashicons dashicons-category" aria-hidden="true"></span><?php esc_html_e( 'Categories', 'octave-addons' ); ?><span class="oa-cpt-content-count"><?= esc_html( number_format_i18n( $category_count ) ); ?></span></a>
				<a href="<?= esc_url( $fields_url ); ?>" class="button oa-cpt-content-link"><span class="dashicons dashicons-feedback" aria-hidden="true"></span><?php esc_html_e( 'Content fields', 'octave-addons' ); ?><span class="oa-cpt-content-count"><?= esc_html( number_format_i18n( $field_count ) ); ?></span></a>
			</div>
			<?php endif; ?>

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

						<div class="oa-cpt-field oa-cpt-field--full oa-cpt-icon-field">
							<span><?php esc_html_e( 'Admin menu icon', 'octave-addons' ); ?></span>
							<input type="hidden" class="oa-cpt-icon-value" name="<?= esc_attr( $this->cpt_field_name( $index, 'menu_icon' ) ); ?>" value="<?= esc_attr( $menu_icon ); ?>">
							<div class="oa-cpt-icon-picker">
								<div class="oa-cpt-icon-selection">
									<span class="oa-cpt-icon-preview" aria-hidden="true"><span class="dashicons <?= esc_attr( $menu_icon ); ?> oa-cpt-live-icon"></span></span>
									<div>
										<strong><?= esc_html( $dashicons[ $menu_icon ] ?? __( 'Posts', 'octave-addons' ) ); ?></strong>
										<code><?= esc_html( $menu_icon ); ?></code>
									</div>
									<button type="button" class="button oa-cpt-icon-toggle" aria-expanded="false"><?php esc_html_e( 'Choose icon', 'octave-addons' ); ?><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
								</div>
								<div class="oa-cpt-icon-options oa-hidden" role="listbox" aria-label="<?php esc_attr_e( 'WordPress admin menu icons', 'octave-addons' ); ?>">

									<?php

									foreach ( $dashicons as $icon => $icon_label ) :

									?>

									<button type="button" class="oa-cpt-icon-option<?= $menu_icon === $icon ? ' is-selected' : ''; ?>" data-icon="<?= esc_attr( $icon ); ?>" data-label="<?= esc_attr( $icon_label ); ?>" role="option" aria-selected="<?= $menu_icon === $icon ? 'true' : 'false'; ?>" title="<?= esc_attr( $icon_label ); ?>">
										<span class="dashicons <?= esc_attr( $icon ); ?>" aria-hidden="true"></span>
										<span><?= esc_html( $icon_label ); ?></span>
									</button>

									<?php

									endforeach;

									?>

								</div>
							</div>
							<small><?php esc_html_e( 'Uses WordPress Dashicons, so no additional icon files are loaded.', 'octave-addons' ); ?></small>
						</div>
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

				<?php

				if ( ! $saved ) {

					$this->render_starter_content( $index );

				}

				?>

			</div>
		</article>

		<?php

	}

	/*
	RENDER STARTER CONTENT
	-- Lets one save create the post type, its first category and its first field.
	-- Blank values are skipped, so the shortcut never forces extra structure.
	---------------------------------------------------------- */

	protected function render_starter_content( string $index ): void {

		?>

		<fieldset class="oa-cpt-group oa-cpt-starter">
			<legend><?php esc_html_e( 'Starter content', 'octave-addons' ); ?></legend>
			<p class="oa-cpt-group-description"><?php esc_html_e( 'Optional. Saving creates these alongside the post type and assigns them to it. Leave blank to add them later.', 'octave-addons' ); ?></p>
			<div class="oa-cpt-fields">
				<label class="oa-cpt-field">
					<span><?php esc_html_e( 'First category', 'octave-addons' ); ?></span>
					<input type="text" name="<?= esc_attr( $this->cpt_field_name( $index, 'starter_taxonomy_name' ) ); ?>" value="" placeholder="<?php esc_attr_e( 'Project Categories', 'octave-addons' ); ?>">
					<small><?php esc_html_e( 'Plural name for the category group.', 'octave-addons' ); ?></small>
				</label>

				<label class="oa-cpt-field">
					<span><?php esc_html_e( 'Category singular name', 'octave-addons' ); ?></span>
					<input type="text" name="<?= esc_attr( $this->cpt_field_name( $index, 'starter_taxonomy_singular_name' ) ); ?>" value="" placeholder="<?php esc_attr_e( 'Project Category', 'octave-addons' ); ?>">
					<small><?php esc_html_e( 'Optional. Defaults to the plural name.', 'octave-addons' ); ?></small>
				</label>

				<label class="oa-cpt-field">
					<span><?php esc_html_e( 'First content field', 'octave-addons' ); ?></span>
					<input type="text" name="<?= esc_attr( $this->cpt_field_name( $index, 'starter_field_label' ) ); ?>" value="" placeholder="<?php esc_attr_e( 'Client name', 'octave-addons' ); ?>">
					<small><?php esc_html_e( 'The label editors see on the post screen.', 'octave-addons' ); ?></small>
				</label>

				<label class="oa-cpt-field">
					<span><?php esc_html_e( 'Content field type', 'octave-addons' ); ?></span>
					<select name="<?= esc_attr( $this->cpt_field_name( $index, 'starter_field_type' ) ); ?>">

						<?php

						foreach ( $this->field_types() as $type_key => $type_label ) :

						?>

						<option value="<?= esc_attr( $type_key ); ?>"<?= selected( 'text', $type_key, false ); ?>><?= esc_html( $type_label ); ?></option>

						<?php

						endforeach;

						?>

					</select>
				</label>
			</div>
		</fieldset>

		<?php

	}

	/*
	RENDER TAXONOMY EDITOR
	-- Displays reusable category definitions and multi-post assignments.
	---------------------------------------------------------- */

	protected function render_taxonomy_editor( array $taxonomies, array $post_types, string $primary_post_type = '', bool $single = false, bool $start_new = false ): void {

		$template = [
			'enabled'      => true,
			'name'         => '',
			'singular_name' => '',
			'taxonomy'     => 'oa_category',
			'slug'         => '',
			'hierarchical' => true,
			'public'       => true,
			'post_types'   => '' !== $primary_post_type ? [ $primary_post_type ] : [],
		];

		if ( $start_new ) {

			$taxonomies = [ $template ];

		}

		?>

		<div class="oa-cpt-section oa-custom-posts-box oa-collection<?= $single ? ' oa-single-definition' : ''; ?>" data-collection="custom_taxonomies" data-new-label="<?php esc_attr_e( 'New post category', 'octave-addons' ); ?>">
			<div class="oa-cpt-section-head">
				<div>
					<span class="oa-panel-kicker"><?php esc_html_e( 'Custom Posts', 'octave-addons' ); ?></span>
					<h3><?php esc_html_e( 'Post Categories', 'octave-addons' ); ?></h3>
					<p><?php esc_html_e( 'Create reusable taxonomies, then assign each one to any combination of posts, pages, or custom post types.', 'octave-addons' ); ?></p>
				</div>
				<button type="button" class="button oa-cpt-add oa-collection-add<?= $single ? ' oa-hidden' : ''; ?>"><span class="oa-cpt-add-icon" aria-hidden="true">+</span><?php esc_html_e( 'Add post category', 'octave-addons' ); ?></button>
			</div>

			<div class="oa-cpt-list oa-collection-list" data-empty-text="<?php esc_attr_e( 'No custom post categories have been added.', 'octave-addons' ); ?>">

				<?php

				foreach ( $taxonomies as $index => $taxonomy ) {

					$this->render_taxonomy_card( (string) $index, $taxonomy, $post_types, ! $start_new, $primary_post_type, $single );

				}

				?>

			</div>

			<template class="oa-collection-template"><?php $this->render_taxonomy_card( '__INDEX__', $template, $post_types, false, $primary_post_type ); ?></template>
		</div>

		<?php

	}

	/*
	RENDER TAXONOMY CARD
	-- Outputs one taxonomy definition with friendly assignment controls.
	---------------------------------------------------------- */

	protected function render_taxonomy_card( string $index, array $taxonomy, array $post_types, bool $saved, string $primary_post_type = '', bool $expanded = false ): void {

		$name     = (string) ( $taxonomy['name'] ?? '' );
		$key      = (string) ( $taxonomy['taxonomy'] ?? '' );
		$assigned = is_array( $taxonomy['post_types'] ?? null ) ? $taxonomy['post_types'] : [];

		?>

		<article class="oa-cpt-item oa-collection-item" data-saved="<?= $saved ? 'true' : 'false'; ?>">
			<div class="oa-cpt-item-head">
				<button type="button" class="oa-cpt-expand oa-collection-expand" aria-expanded="<?= $saved && ! $expanded ? 'false' : 'true'; ?>">
					<span class="oa-cpt-expand-copy"><strong class="oa-cpt-item-title"><?= esc_html( '' !== $name ? $name : __( 'New post category', 'octave-addons' ) ); ?></strong><span class="oa-cpt-key-preview"><?= esc_html( $key ); ?></span></span>
					<span class="dashicons dashicons-arrow-down-alt2 oa-cpt-expand-icon" aria-hidden="true"></span>
				</button>
				<div class="oa-cpt-enabled-summary"><span><?php esc_html_e( 'Enabled', 'octave-addons' ); ?></span><label class="oa-switch"><input type="checkbox" data-role="enabled" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'enabled' ) ); ?>" value="1"<?= checked( ! empty( $taxonomy['enabled'] ), true, false ); ?>><span class="oa-switch-slider"></span></label></div>
				<button type="button" class="oa-cpt-remove oa-collection-remove" aria-label="<?php esc_attr_e( 'Remove this post category', 'octave-addons' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
			</div>

			<div class="oa-cpt-groups oa-collection-body<?= $saved && ! $expanded ? ' oa-hidden' : ''; ?>">
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

				<?php $this->render_assignment_group( 'custom_taxonomies', $index, $assigned, $post_types, false, $primary_post_type ); ?>
			</div>
		</article>

		<?php

	}

	/*
	RENDER FIELD EDITOR
	-- Displays ACF-style field definitions assigned to custom post types.
	---------------------------------------------------------- */

	protected function render_field_editor( array $fields, array $post_types, string $primary_post_type = '', bool $single = false, bool $start_new = false ): void {

		$template = [ 'enabled' => true, 'label' => '', 'name' => 'field_name', 'type' => 'text', 'default_value' => '', 'choices' => '', 'description' => '', 'required' => false, 'post_types' => '' !== $primary_post_type ? [ $primary_post_type ] : [], 'sub_fields' => [] ];

		if ( $start_new ) {

			$fields = [ $template ];

		}

		?>

		<div class="oa-cpt-section oa-custom-posts-box oa-collection<?= $single ? ' oa-single-definition' : ''; ?>" data-collection="custom_fields" data-new-label="<?php esc_attr_e( 'New post field', 'octave-addons' ); ?>">
			<div class="oa-cpt-section-head">
				<div>
					<span class="oa-panel-kicker"><?php esc_html_e( 'Custom Posts', 'octave-addons' ); ?></span>
					<h3><?php esc_html_e( 'Post Fields', 'octave-addons' ); ?></h3>
					<p><?php esc_html_e( 'Define typed fields for post editors. Values are stored as registered WordPress post meta and appear under Octave in Breakdance Dynamic Data.', 'octave-addons' ); ?></p>
				</div>
				<button type="button" class="button oa-cpt-add oa-collection-add<?= $single ? ' oa-hidden' : ''; ?>"><span class="oa-cpt-add-icon" aria-hidden="true">+</span><?php esc_html_e( 'Add post field', 'octave-addons' ); ?></button>
			</div>

			<div class="oa-cpt-list oa-collection-list" data-empty-text="<?php esc_attr_e( 'No custom post fields have been added.', 'octave-addons' ); ?>">

				<?php

				foreach ( $fields as $index => $field ) {

					$this->render_field_card( (string) $index, $field, $post_types, ! $start_new, $primary_post_type );

				}

				?>

			</div>

			<template class="oa-collection-template"><?php $this->render_field_card( '__INDEX__', $template, $post_types, false, $primary_post_type ); ?></template>
		</div>

		<?php

	}

	/*
	RENDER FIELD CARD
	-- Outputs one typed custom field definition.
	---------------------------------------------------------- */

	protected function render_field_card( string $index, array $field, array $post_types, bool $saved, string $primary_post_type = '' ): void {

		$label    = (string) ( $field['label'] ?? '' );
		$name     = (string) ( $field['name'] ?? '' );
		$type     = (string) ( $field['type'] ?? 'text' );
		$assigned = is_array( $field['post_types'] ?? null ) ? $field['post_types'] : [];
		$types    = $this->field_types();
		$is_container = in_array( $type, [ 'group', 'repeater' ], true );

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
						<label class="oa-cpt-field oa-field-default<?= $is_container ? ' oa-hidden' : ''; ?>"><span><?php esc_html_e( 'Default value', 'octave-addons' ); ?></span><input type="text" name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'default_value' ) ); ?>" value="<?= esc_attr( is_scalar( $field['default_value'] ?? '' ) ? (string) $field['default_value'] : '' ); ?>"><small><?php esc_html_e( 'Shown until a post has its own saved value.', 'octave-addons' ); ?></small></label>
						<label class="oa-cpt-field oa-cpt-field--full oa-field-choices<?= in_array( $type, [ 'select', 'multiselect', 'radio' ], true ) ? '' : ' oa-hidden'; ?>"><span><?php esc_html_e( 'Choices', 'octave-addons' ); ?></span><textarea name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'choices' ) ); ?>" rows="5" placeholder="featured : Featured&#10;standard : Standard"><?= esc_textarea( (string) ( $field['choices'] ?? '' ) ); ?></textarea><small><?php esc_html_e( 'One per line. Use value : Label or a simple value.', 'octave-addons' ); ?></small></label>
						<label class="oa-cpt-field oa-cpt-field--full"><span><?php esc_html_e( 'Instructions for editors', 'octave-addons' ); ?></span><textarea name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'description' ) ); ?>" rows="3"><?= esc_textarea( (string) ( $field['description'] ?? '' ) ); ?></textarea></label>
						<div class="oa-cpt-field oa-cpt-switch-field"><span><?php esc_html_e( 'Required', 'octave-addons' ); ?></span><label class="oa-switch"><input type="checkbox" name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'required' ) ); ?>" value="1"<?= checked( ! empty( $field['required'] ), true, false ); ?>><span class="oa-switch-slider"></span></label><small><?php esc_html_e( 'Prompts editors to complete the field in the post screen.', 'octave-addons' ); ?></small></div>
					</div>
				</fieldset>

				<?php $this->render_sub_field_editor( $index, $field['sub_fields'] ?? [], $is_container ); ?>

				<?php $this->render_assignment_group( 'custom_fields', $index, $assigned, $post_types, true, $primary_post_type ); ?>
			</div>
		</article>

		<?php

	}

	/*
	RENDER ASSIGNMENT GROUP
	-- Provides clear checkbox assignments for taxonomies and fields.
	---------------------------------------------------------- */

	protected function render_assignment_group( string $collection, string $index, array $assigned, array $post_types, bool $custom_only = false, string $primary_post_type = '' ): void {

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

					$is_primary = '' !== $primary_post_type && $post_type === $primary_post_type;

				?>

				<label class="oa-assignment-option<?= $is_primary ? ' is-primary' : ''; ?>">
					<input type="checkbox" name="<?= esc_attr( $this->collection_field_name( $collection, $index, 'post_types' ) ); ?>[]" value="<?= esc_attr( $post_type ); ?>"<?= checked( $is_primary || in_array( $post_type, $assigned, true ), true, false ); ?><?= $is_primary ? ' data-primary-assignment="true"' : ''; ?>>
					<span class="oa-assignment-check" aria-hidden="true"></span>
					<span class="oa-assignment-copy"><strong><?= esc_html( $label ); ?></strong><small><?= $is_primary ? esc_html__( 'Current post type', 'octave-addons' ) : esc_html( $post_type ); ?></small></span>
				</label>

				<?php

				endforeach;

				?>

			</div>
		</fieldset>

		<?php

	}

	/*
	RENDER SUB FIELD EDITOR
	-- Defines the children stored inside one group or repeater meta value.
	---------------------------------------------------------- */

	protected function render_sub_field_editor( string $field_index, array $sub_fields, bool $visible ): void {

		$template = [
			'label'         => '',
			'name'          => 'item_field',
			'type'          => 'text',
			'default_value' => '',
			'choices'       => '',
			'description'   => '',
			'required'      => false,
		];

		?>

		<fieldset class="oa-cpt-group oa-sub-field-editor<?= $visible ? '' : ' oa-hidden'; ?>">
			<legend><?php esc_html_e( 'Fields inside this item', 'octave-addons' ); ?></legend>
			<p class="oa-cpt-group-description"><?php esc_html_e( 'Each child is stored inside the single parent meta value. Repeaters let post editors add as many rows as they need.', 'octave-addons' ); ?></p>
			<div class="oa-sub-field-toolbar">
				<span><?php esc_html_e( 'Keep each item focused so it remains quick for editors to complete.', 'octave-addons' ); ?></span>
				<button type="button" class="button oa-sub-field-add"><span aria-hidden="true">+</span><?php esc_html_e( 'Add item field', 'octave-addons' ); ?></button>
			</div>
			<div class="oa-sub-field-list" data-empty-text="<?php esc_attr_e( 'No fields have been added inside this item.', 'octave-addons' ); ?>">

				<?php

				foreach ( $sub_fields as $sub_index => $sub_field ) {

					$this->render_sub_field_card( $field_index, (string) $sub_index, $sub_field, true );

				}

				?>

			</div>
			<template class="oa-sub-field-template"><?php $this->render_sub_field_card( $field_index, '__SUB_INDEX__', $template, false ); ?></template>
		</fieldset>

		<?php

	}

	/*
	RENDER SUB FIELD CARD
	-- Outputs one compact child-field definition.
	---------------------------------------------------------- */

	protected function render_sub_field_card( string $field_index, string $sub_index, array $field, bool $saved ): void {

		$label = (string) ( $field['label'] ?? '' );
		$name  = (string) ( $field['name'] ?? '' );
		$type  = (string) ( $field['type'] ?? 'text' );
		$types = $this->sub_field_types();

		?>

		<article class="oa-sub-field-item" data-saved="<?= $saved ? 'true' : 'false'; ?>">
			<div class="oa-sub-field-head">
				<span class="dashicons dashicons-editor-table" aria-hidden="true"></span>
				<strong class="oa-sub-field-title"><?= esc_html( '' !== $label ? $label : __( 'New item field', 'octave-addons' ) ); ?></strong>
				<code class="oa-sub-field-key"><?= esc_html( $name ); ?></code>
				<button type="button" class="oa-sub-field-toggle" aria-expanded="<?= $saved ? 'false' : 'true'; ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Toggle item field settings', 'octave-addons' ); ?></span></button>
				<button type="button" class="oa-sub-field-remove" aria-label="<?php esc_attr_e( 'Remove this item field', 'octave-addons' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
			</div>
			<div class="oa-sub-field-body<?= $saved ? ' oa-hidden' : ''; ?>">
				<div class="oa-cpt-fields">
					<label class="oa-cpt-field"><span><?php esc_html_e( 'Label', 'octave-addons' ); ?></span><input type="text" data-sub-role="title" name="<?= esc_attr( $this->sub_field_name( $field_index, $sub_index, 'label' ) ); ?>" value="<?= esc_attr( $label ); ?>" placeholder="Heading" required></label>
					<label class="oa-cpt-field"><span><?php esc_html_e( 'Field name', 'octave-addons' ); ?></span><input type="text" data-sub-role="key" name="<?= esc_attr( $this->sub_field_name( $field_index, $sub_index, 'name' ) ); ?>" value="<?= esc_attr( $name ); ?>" maxlength="40" pattern="[a-z0-9_]+" required<?= $saved ? ' readonly' : ''; ?>></label>
					<label class="oa-cpt-field"><span><?php esc_html_e( 'Field type', 'octave-addons' ); ?></span><select data-sub-field-type name="<?= esc_attr( $this->sub_field_name( $field_index, $sub_index, 'type' ) ); ?>"><?php foreach ( $types as $type_key => $type_label ) : ?><option value="<?= esc_attr( $type_key ); ?>"<?= selected( $type, $type_key, false ); ?>><?= esc_html( $type_label ); ?></option><?php endforeach; ?></select></label>
					<label class="oa-cpt-field"><span><?php esc_html_e( 'Default value', 'octave-addons' ); ?></span><input type="text" name="<?= esc_attr( $this->sub_field_name( $field_index, $sub_index, 'default_value' ) ); ?>" value="<?= esc_attr( (string) ( $field['default_value'] ?? '' ) ); ?>"></label>
					<label class="oa-cpt-field oa-cpt-field--full oa-sub-field-choices<?= in_array( $type, [ 'select', 'multiselect', 'radio' ], true ) ? '' : ' oa-hidden'; ?>"><span><?php esc_html_e( 'Choices', 'octave-addons' ); ?></span><textarea name="<?= esc_attr( $this->sub_field_name( $field_index, $sub_index, 'choices' ) ); ?>" rows="4" placeholder="value : Label"><?= esc_textarea( (string) ( $field['choices'] ?? '' ) ); ?></textarea></label>
					<label class="oa-cpt-field oa-cpt-field--full"><span><?php esc_html_e( 'Instructions for editors', 'octave-addons' ); ?></span><textarea name="<?= esc_attr( $this->sub_field_name( $field_index, $sub_index, 'description' ) ); ?>" rows="2"><?= esc_textarea( (string) ( $field['description'] ?? '' ) ); ?></textarea></label>
					<div class="oa-cpt-field oa-cpt-switch-field"><span><?php esc_html_e( 'Required', 'octave-addons' ); ?></span><label class="oa-switch"><input type="checkbox" name="<?= esc_attr( $this->sub_field_name( $field_index, $sub_index, 'required' ) ); ?>" value="1"<?= checked( ! empty( $field['required'] ), true, false ); ?>><span class="oa-switch-slider"></span></label></div>
				</div>
			</div>
		</article>

		<?php

	}

	/*
	SETTINGS URL
	-- Returns the stable Custom Posts settings URL.
	---------------------------------------------------------- */

	protected function settings_url(): string {

		return add_query_arg( [ 'page' => OCTAVE_ADDONS_SLUG, 'tab' => $this->get_id() ], admin_url( 'admin.php' ) );

	}

	/*
	EDITOR URL
	-- Returns a focused category or content-field editor URL.
	---------------------------------------------------------- */

	protected function editor_url( string $post_type, string $section ): string {

		return add_query_arg(
			[
				'section'   => $section,
				'post_type' => $post_type,
			],
			$this->settings_url()
		);

	}

	/*
	SCHEMA URL
	-- Returns a reusable schema library or single-definition editor URL.
	-- A context post type pre-assigns new definitions and restores the trail
	-- back to the post type the editor was opened from.
	---------------------------------------------------------- */

	protected function schema_url( string $section, string $definition = '', string $context = '' ): string {

		$args = [ 'section' => $section ];

		if ( '' !== $definition ) {

			$args['definition'] = $definition;

		}

		if ( '' !== $context ) {

			$args['context'] = $context;

		}

		return add_query_arg( $args, $this->settings_url() );

	}

	/*
	DEFINITIONS FOR POST TYPE
	-- Filters definitions assigned to the focused content type.
	---------------------------------------------------------- */

	protected function definitions_for_post_type( array $definitions, string $post_type ): array {

		return array_values(
			array_filter(
				$definitions,
				static function ( array $definition ) use ( $post_type ): bool {

					return in_array( $post_type, $definition['post_types'] ?? [], true );

				}
			)
		);

	}

	/*
	DEFINITIONS WITHOUT POST TYPE
	-- Keeps unrelated definitions in the submitted settings payload.
	---------------------------------------------------------- */

	protected function definitions_without_post_type( array $definitions, string $post_type ): array {

		return array_values(
			array_filter(
				$definitions,
				static function ( array $definition ) use ( $post_type ): bool {

					return ! in_array( $post_type, $definition['post_types'] ?? [], true );

				}
			)
		);

	}

	/*
	RENDER PRESERVED COLLECTION
	-- Keeps off-screen definitions in one compact payload below max_input_vars.
	---------------------------------------------------------- */

	protected function render_preserved_collection( string $collection, array $items ): void {

		$encoded = base64_encode( (string) wp_json_encode( array_values( $items ) ) );

		?>

		<input type="hidden" name="<?= esc_attr( $this->field_name( 'preserved_' . $collection ) ); ?>" value="<?= esc_attr( $encoded ); ?>">

		<?php

	}

	/*
	PRESERVED COLLECTION
	-- Decodes off-screen definitions before the normal sanitizer validates them.
	---------------------------------------------------------- */

	protected function preserved_collection( array $input, string $collection ): array {

		$key = 'preserved_' . $collection;

		if ( empty( $input[ $key ] ) || ! is_string( $input[ $key ] ) ) {

			return [];

		}

		$json = base64_decode( wp_unslash( $input[ $key ] ), true );

		if ( false === $json ) {

			return [];

		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : [];

	}

	/*
	RENDER HIDDEN COLLECTION
	-- Preserves the compact post type collection on focused screens.
	---------------------------------------------------------- */

	protected function render_hidden_collection( string $collection, array $items, int $offset = 0 ): void {

		foreach ( array_values( $items ) as $index => $item ) {

			$this->render_hidden_value( $this->field_name( $collection ) . '[' . ( $index + $offset ) . ']', $item );

		}

	}

	/*
	RENDER HIDDEN VALUE
	-- Recursively emits an option value without exposing it in the interface.
	---------------------------------------------------------- */

	protected function render_hidden_value( string $name, $value ): void {

		if ( is_array( $value ) ) {

			foreach ( $value as $key => $child ) {

				$this->render_hidden_value( $name . '[' . $key . ']', $child );

			}

			return;

		}

		?>

		<input type="hidden" name="<?= esc_attr( $name ); ?>" value="<?= esc_attr( (string) $value ); ?>">

		<?php

	}

	/*
	RUN
	-- Renames Posts and registers enabled content types in saved order.
	---------------------------------------------------------- */

	public function run( array $settings ): void {

		if ( ! empty( $settings['blog_labels'] ) ) {

			add_filter( 'post_type_labels_post', [ $this, 'rename_post_labels' ] );

			$post_type_object = get_post_type_object( 'post' );

			if ( $post_type_object ) {

				$post_type_object->labels = $this->rename_post_labels( $post_type_object->labels );
				$post_type_object->label  = __( 'Blogs', 'octave-addons' );

			}

		}

		if ( ! empty( $settings['page_categories'] ) ) {

			$this->register_page_categories();

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
	RENDER PAGE CATEGORY FILTER
	-- Adds the single category dropdown used to find Pages in the list toolbar.
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
				'menu_icon'           => $post_type['menu_icon'],
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
			$menu_icon    = sanitize_key( wp_unslash( (string) ( $post_type['menu_icon'] ?? '' ) ) );

			if ( ! array_key_exists( $menu_icon, $this->dashicons() ) ) {

				$menu_icon = 'dashicons-admin-post';

			}

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
				'menu_icon'              => $menu_icon,
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

			$used[ $name ] = true;
			$is_container  = in_array( $type, [ 'group', 'repeater' ], true );
			$clean[]       = [
				'enabled'       => ! empty( $field['enabled'] ),
				'label'         => $label,
				'name'          => $name,
				'meta_key'      => '_octave_' . $name,
				'type'          => $type,
				'default_value' => $is_container
					? []
					: ( 'wysiwyg' === $type
					? wp_kses_post( wp_unslash( (string) ( $field['default_value'] ?? '' ) ) )
					: sanitize_text_field( wp_unslash( (string) ( $field['default_value'] ?? '' ) ) ) ),
				'choices'       => sanitize_textarea_field( wp_unslash( (string) ( $field['choices'] ?? '' ) ) ),
				'description'   => sanitize_textarea_field( wp_unslash( (string) ( $field['description'] ?? '' ) ) ),
				'required'      => ! empty( $field['required'] ),
				'post_types'    => $assigned,
				'sub_fields'    => $is_container ? $this->normalise_sub_fields( $field['sub_fields'] ?? [] ) : [],
			];

		}

		return $clean;

	}

	/*
	NORMALISE SUB FIELDS
	-- Sanitizes one level of child definitions for groups and repeaters.
	---------------------------------------------------------- */

	protected function normalise_sub_fields( $fields ): array {

		if ( ! is_array( $fields ) ) {

			return [];

		}

		$clean = [];
		$used  = [];
		$types = array_keys( $this->sub_field_types() );

		foreach ( array_slice( $fields, 0, 20 ) as $index => $field ) {

			if ( ! is_array( $field ) ) {

				continue;

			}

			$label = sanitize_text_field( wp_unslash( (string) ( $field['label'] ?? '' ) ) );
			$name  = substr( sanitize_key( wp_unslash( (string) ( $field['name'] ?? '' ) ) ), 0, 40 );
			$type  = sanitize_key( $field['type'] ?? 'text' );

			if ( '' === $name ) {

				$name = 'item_field_' . ( $index + 1 );

			}

			if ( '' === $label || isset( $used[ $name ] ) ) {

				continue;

			}

			$type          = in_array( $type, $types, true ) ? $type : 'text';
			$used[ $name ] = true;
			$clean[]       = [
				'label'         => $label,
				'name'          => $name,
				'type'          => $type,
				'default_value' => 'wysiwyg' === $type
					? wp_kses_post( wp_unslash( (string) ( $field['default_value'] ?? '' ) ) )
					: sanitize_text_field( wp_unslash( (string) ( $field['default_value'] ?? '' ) ) ),
				'choices'       => sanitize_textarea_field( wp_unslash( (string) ( $field['choices'] ?? '' ) ) ),
				'description'   => sanitize_textarea_field( wp_unslash( (string) ( $field['description'] ?? '' ) ) ),
				'required'      => ! empty( $field['required'] ),
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
			'group'       => __( 'Group', 'octave-addons' ),
			'repeater'    => __( 'Repeater', 'octave-addons' ),
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
	DASHICONS
	-- Provides a focused set of built-in WordPress icons for admin menus.
	---------------------------------------------------------- */

	protected function dashicons(): array {

		return [
			'dashicons-admin-post'       => __( 'Posts', 'octave-addons' ),
			'dashicons-admin-page'       => __( 'Pages', 'octave-addons' ),
			'dashicons-portfolio'        => __( 'Portfolio', 'octave-addons' ),
			'dashicons-products'         => __( 'Products', 'octave-addons' ),
			'dashicons-store'            => __( 'Store', 'octave-addons' ),
			'dashicons-building'         => __( 'Building', 'octave-addons' ),
			'dashicons-businessperson'   => __( 'Person', 'octave-addons' ),
			'dashicons-groups'           => __( 'People', 'octave-addons' ),
			'dashicons-id'               => __( 'Profile', 'octave-addons' ),
			'dashicons-location'         => __( 'Location', 'octave-addons' ),
			'dashicons-calendar-alt'     => __( 'Calendar', 'octave-addons' ),
			'dashicons-tickets-alt'      => __( 'Tickets', 'octave-addons' ),
			'dashicons-megaphone'        => __( 'Announcement', 'octave-addons' ),
			'dashicons-format-aside'     => __( 'Article', 'octave-addons' ),
			'dashicons-book'             => __( 'Book', 'octave-addons' ),
			'dashicons-book-alt'         => __( 'Resource', 'octave-addons' ),
			'dashicons-welcome-learn-more' => __( 'Learning', 'octave-addons' ),
			'dashicons-awards'           => __( 'Award', 'octave-addons' ),
			'dashicons-star-filled'      => __( 'Featured', 'octave-addons' ),
			'dashicons-heart'            => __( 'Heart', 'octave-addons' ),
			'dashicons-images-alt2'      => __( 'Gallery', 'octave-addons' ),
			'dashicons-format-video'     => __( 'Video', 'octave-addons' ),
			'dashicons-format-audio'     => __( 'Audio', 'octave-addons' ),
			'dashicons-media-document'   => __( 'Document', 'octave-addons' ),
			'dashicons-download'         => __( 'Download', 'octave-addons' ),
			'dashicons-chart-bar'        => __( 'Analytics', 'octave-addons' ),
			'dashicons-lightbulb'        => __( 'Idea', 'octave-addons' ),
			'dashicons-hammer'           => __( 'Services', 'octave-addons' ),
			'dashicons-shield'           => __( 'Security', 'octave-addons' ),
			'dashicons-universal-access' => __( 'Accessibility', 'octave-addons' ),
		];

	}

	/*
	SUB FIELD TYPES
	-- Allows every standard control while preventing deeply nested containers.
	---------------------------------------------------------- */

	protected function sub_field_types(): array {

		return array_diff_key( $this->field_types(), array_flip( [ 'group', 'repeater' ] ) );

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
			'menu_icon'              => 'dashicons-portfolio',
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
	SUB FIELD NAME
	-- Produces a nested Settings API name for a container child field.
	---------------------------------------------------------- */

	protected function sub_field_name( string $field_index, string $sub_index, string $key ): string {

		return sprintf( '%s[%s][custom_fields][%s][sub_fields][%s][%s]', OCTAVE_ADDONS_OPTION_KEY, $this->get_id(), $field_index, $sub_index, $key );

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
