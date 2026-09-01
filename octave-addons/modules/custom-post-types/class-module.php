<?php

/*
MODULE: CUSTOM POSTS
-- Manages custom post types, reusable taxonomies, and typed post fields.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once __DIR__ . '/class-post-fields.php';

class Octave_Addons_Module_Custom_Post_Types extends Octave_Addons_Module {

	protected const PAGE_TAXONOMY = 'oa_page_category';

	protected static ?array $dashicons = null;

	protected array $admin_taxonomies = [];

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

		return __( 'Post Types', 'octave-addons' );

	}

	/*
	GET DESCRIPTION
	-- Describes the managed WordPress content areas.
	---------------------------------------------------------- */

	public function get_description(): string {

		return __( 'Create post types, taxonomies, and structured fields in one place.', 'octave-addons' );

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
	-- Normalises saved repeatable post type, taxonomy, and field definitions.
	---------------------------------------------------------- */

	public function get_settings( array $saved ): array {

		$settings = wp_parse_args( $saved, $this->get_defaults() );

		if ( ! array_key_exists( 'blog_labels', $saved ) && ! empty( $saved['enabled'] ) ) {

			$settings['blog_labels'] = true;

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

		$post_type_rows = $this->submitted_collection( $input, 'custom_post_types' );
		$post_types     = $this->normalise_post_types( $post_type_rows );

		$taxonomies = array_merge(
			$this->submitted_collection( $input, 'custom_taxonomies' ),
			$this->preserved_collection( $input, 'custom_taxonomies' )
		);
		$fields     = array_merge(
			$this->submitted_collection( $input, 'custom_fields' ),
			$this->preserved_collection( $input, 'custom_fields' )
		);

		// A renamed post type key would otherwise read as an unknown one, so the
		// assignments pointing at the old key are moved across before validation.
		$renamed    = $this->renamed_post_types( $post_type_rows );
		$taxonomies = $this->apply_post_type_renames( $taxonomies, $renamed );
		$fields     = $this->apply_post_type_renames( $fields, $renamed );

		$submitted_post_types = $this->submitted_row_count( $post_type_rows );
		$submitted_taxonomies = $this->submitted_row_count( $taxonomies );
		$submitted_fields     = $this->submitted_row_count( $fields );

		$taxonomies = $this->normalise_taxonomies( $taxonomies, $post_types );
		$fields     = $this->normalise_fields( $fields, $post_types );

		// Rows are dropped silently by the normalisers, so say so rather than let them vanish.
		$this->report_dropped_rows( 'post_types', $submitted_post_types, count( $post_types ) );
		$this->report_dropped_rows( 'taxonomies', $submitted_taxonomies, count( $taxonomies ) );
		$this->report_dropped_rows( 'fields', $submitted_fields, count( $fields ) );

		$has_starter_content = $this->apply_starter_content( $post_type_rows, $post_types, $taxonomies, $fields );

		if ( $has_starter_content ) {

			$taxonomies = $this->normalise_taxonomies( $taxonomies, $post_types );
			$fields     = $this->normalise_fields( $fields, $post_types );

		}

		return [
			'enabled'           => ! empty( $input['enabled'] ),
			'blog_labels'       => ! empty( $input['blog_labels'] ),
			'page_categories'   => ! empty( $input['page_categories'] ),
			'custom_post_types' => $post_types,
			'custom_taxonomies' => $taxonomies,
			'custom_fields'     => $fields,
		];

	}

	/*
	SUBMITTED COLLECTION
	-- Prefers the compact browser payload and falls back to ordinary nested
	-- fields when JavaScript is unavailable.
	---------------------------------------------------------- */

	protected function submitted_collection( array $input, string $collection ): array {

		$packed = $this->packed_collection( $input, $collection );

		if ( null !== $packed ) {

			return $packed;

		}

		return is_array( $input[ $collection ] ?? null ) ? $input[ $collection ] : [];

	}

	/*
	PACKED COLLECTION
	-- Decodes a collection sent as one value so large field schemas do not hit
	-- PHP max_input_vars or request-variable limits imposed by hosting layers.
	---------------------------------------------------------- */

	protected function packed_collection( array $input, string $collection ): ?array {

		$key = 'packed_' . $collection;

		if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) ) {

			return null;

		}

		$json = base64_decode( wp_unslash( $input[ $key ] ), true );

		if ( false === $json ) {

			return null;

		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : null;

	}

	/*
	RENAMED POST TYPES
	-- Maps the key each unlocked post type was saved under to the key it now
	-- carries, so a rename is visible to everything that references it.
	---------------------------------------------------------- */

	protected function renamed_post_types( $post_types ): array {

		if ( ! is_array( $post_types ) ) {

			return [];

		}

		$renamed = [];

		foreach ( $post_types as $index => $post_type ) {

			if ( ! is_array( $post_type ) || ! isset( $post_type['original_post_type'] ) ) {

				continue;

			}

			$original = sanitize_key( wp_unslash( (string) $post_type['original_post_type'] ) );
			$key      = $this->sanitize_post_type_key( $post_type['post_type'] ?? '', (int) $index );

			if ( '' === $original || $original === $key ) {

				continue;

			}

			$renamed[ $original ] = $key;

		}

		return $renamed;

	}

	/*
	APPLY POST TYPE RENAMES
	-- Rewrites taxonomy and field assignments that still name a renamed post
	-- type, which the normalisers would otherwise drop as unavailable.
	---------------------------------------------------------- */

	protected function apply_post_type_renames( array $rows, array $renamed ): array {

		if ( empty( $renamed ) ) {

			return $rows;

		}

		foreach ( $rows as $index => $row ) {

			if ( ! is_array( $row ) ) {

				continue;

			}

			if ( isset( $row['post_types'] ) && is_array( $row['post_types'] ) ) {

				foreach ( $row['post_types'] as $slot => $assigned ) {

					$assigned = sanitize_key( wp_unslash( (string) $assigned ) );

					if ( isset( $renamed[ $assigned ] ) ) {

						$rows[ $index ]['post_types'][ $slot ] = $renamed[ $assigned ];

					}

				}

			}

			if ( isset( $row['post_type_order'] ) && is_array( $row['post_type_order'] ) ) {

				foreach ( $row['post_type_order'] as $ordered_post_type => $position ) {

					$ordered_post_type = sanitize_key( wp_unslash( (string) $ordered_post_type ) );

					if ( isset( $renamed[ $ordered_post_type ] ) ) {

						unset( $rows[ $index ]['post_type_order'][ $ordered_post_type ] );
						$rows[ $index ]['post_type_order'][ $renamed[ $ordered_post_type ] ] = $position;

					}

				}

			}

			$owner = sanitize_key( wp_unslash( (string) ( $row['owner_post_type'] ?? '' ) ) );

			if ( '' !== $owner && isset( $renamed[ $owner ] ) ) {

				$rows[ $index ]['owner_post_type'] = $renamed[ $owner ];

			}

		}

		return $rows;

	}

	/*
	SUBMITTED ROW COUNT
	-- Counts the definition rows a submission actually carried.
	---------------------------------------------------------- */

	protected function submitted_row_count( $rows ): int {

		if ( ! is_array( $rows ) ) {

			return 0;

		}

		return count( array_filter( $rows, 'is_array' ) );

	}

	/*
	REPORT DROPPED ROWS
	-- Raises a settings notice for definitions the normalisers refused, so an
	-- incomplete or duplicated row is never dropped without explanation.
	---------------------------------------------------------- */

	protected function report_dropped_rows( string $type, int $submitted, int $saved ): void {

		$dropped = $submitted - $saved;

		if ( $dropped < 1 || ! function_exists( 'add_settings_error' ) ) {

			return;

		}

		if ( 'post_types' === $type ) {

			$message = sprintf(
				/* translators: %s: number of post types that were not saved. */
				_n(
					'%s post type was not saved. Each one needs a plural name, a singular name, and a key no other post type uses. A maximum of 20 can be stored.',
					'%s post types were not saved. Each one needs a plural name, a singular name, and a key no other post type uses. A maximum of 20 can be stored.',
					$dropped,
					'octave-addons'
				),
				number_format_i18n( $dropped )
			);

		} elseif ( 'taxonomies' === $type ) {

			$message = sprintf(
				/* translators: %s: number of taxonomies that were not saved. */
				_n(
					'%s taxonomy was not saved. Each one needs a plural name, a singular name, and a key no other taxonomy uses. A maximum of 30 can be stored.',
					'%s taxonomies were not saved. Each one needs a plural name, a singular name, and a key no other taxonomy uses. A maximum of 30 can be stored.',
					$dropped,
					'octave-addons'
				),
				number_format_i18n( $dropped )
			);

		} else {

			$message = sprintf(
				/* translators: %s: number of content fields that were not saved. */
				_n(
					'%s content field was not saved. Each one needs a label and a name no other field uses. A maximum of 50 can be stored.',
					'%s content fields were not saved. Each one needs a label and a name no other field uses. A maximum of 50 can be stored.',
					$dropped,
					'octave-addons'
				),
				number_format_i18n( $dropped )
			);

		}

		add_settings_error( OCTAVE_ADDONS_OPTION_KEY, 'oa-dropped-' . $type, $message, 'error' );

	}

	/*
	APPLY STARTER CONTENT
	-- Turns the optional first category and field on a brand new post type card
	-- into definitions already assigned to that post type. Starter fields remain
	-- specific to the post type unless an editor creates them in the library.
	---------------------------------------------------------- */

	protected function apply_starter_content( $submitted, array $post_types, array &$taxonomies, array &$fields ): bool {

		if ( ! is_array( $submitted ) ) {

			return false;

		}

		$keys    = array_column( $post_types, 'post_type' );
		$changed = false;

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
				$changed = true;

			}

			if ( '' !== $field_label ) {

				$fields[] = [
					'enabled'         => true,
					'label'           => $field_label,
					'name'            => $this->unique_definition_key( $field_label, 'field', array_column( $fields, 'name' ), 40 ),
					'type'            => sanitize_key( $post_type['starter_field_type'] ?? 'text' ),
					'scope'           => 'specific',
					'owner_post_type' => $key,
					'post_types'      => [ $key ],
				];
				$changed = true;

			}

		}

		return $changed;

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
	RENDER ACTION NOTICE
	-- Confirms an action that finished on another screen, such as a definition
	-- deleted from its own editor before returning here.
	---------------------------------------------------------- */

	protected function render_action_notice(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only settings navigation.
		$notice = isset( $_GET['oa-notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['oa-notice'] ) ) : '';

		$messages = [
			'category-deleted' => __( 'Taxonomy deleted.', 'octave-addons' ),
			'field-deleted'    => __( 'Content field deleted.', 'octave-addons' ),
		];

		if ( ! isset( $messages[ $notice ] ) ) {

			return;

		}

		?>

		<div class="notice notice-success inline oa-inline-notice">
			<p><?= esc_html( $messages[ $notice ] ); ?></p>
		</div>

		<?php

	}

	/*
	RENDER SETTINGS
	-- Displays built-in controls and focused Post Types, Taxonomies, and Fields views.
	---------------------------------------------------------- */

	public function render_settings( array $settings ): void {

		$this->render_action_notice();

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
			'publicly_queryable'     => true,
			'content_editor'         => true,
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

		<details class="oa-builtin-content">
			<summary class="oa-builtin-content-head">
				<div>
					<span class="oa-panel-kicker"><?php esc_html_e( 'WordPress content', 'octave-addons' ); ?></span>
					<h3><?php esc_html_e( 'WordPress defaults', 'octave-addons' ); ?></h3>
					<p><?php esc_html_e( 'Optional changes for the built-in Posts and Pages.', 'octave-addons' ); ?></p>
				</div>
				<span class="oa-builtin-content-summary-action"><span><?php esc_html_e( '2 settings', 'octave-addons' ); ?></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></span>
			</summary>

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
		</details>

		<?php

		$tabs = [
			[
				'panel' => 'oa-post-types',
				'icon'  => 'dashicons-screenoptions',
				'label' => __( 'Post Types', 'octave-addons' ),
				'count' => count( $custom_types ),
			],
			[
				'panel' => 'oa-categories',
				'icon'  => 'dashicons-category',
				'label' => __( 'Taxonomies', 'octave-addons' ),
				'count' => count( $custom_taxonomies ),
			],
			[
				'panel' => 'oa-content-management',
				'icon'  => 'dashicons-feedback',
				'label' => __( 'Fields', 'octave-addons' ),
				'count' => count( $custom_fields ),
			],
		];

		?>

		<div class="oa-content-tabs" data-oa-tabs>

			<nav class="oa-content-tabs-nav" role="tablist" aria-label="<?php esc_attr_e( 'Custom content types', 'octave-addons' ); ?>">

				<?php

				foreach ( $tabs as $position => $tab ) :

					$is_active = 0 === $position;

				?>

				<button type="button" role="tab" class="oa-content-tab<?= $is_active ? ' is-active' : ''; ?>" id="oa-content-tab-<?= esc_attr( $tab['panel'] ); ?>" data-oa-tab="<?= esc_attr( $tab['panel'] ); ?>" aria-controls="<?= esc_attr( $tab['panel'] ); ?>" aria-selected="<?= $is_active ? 'true' : 'false'; ?>" tabindex="<?= $is_active ? '0' : '-1'; ?>">
					<span class="dashicons <?= esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span>
					<span class="oa-content-tab-label"><?= esc_html( $tab['label'] ); ?></span>
					<span class="oa-content-tab-count"><?= esc_html( number_format_i18n( $tab['count'] ) ); ?></span>
				</button>

				<?php

				endforeach;

				?>

			</nav>

			<div class="oa-content-tab-panel" id="oa-post-types" role="tabpanel" aria-labelledby="oa-content-tab-oa-post-types" tabindex="0">

				<div class="oa-cpt-section oa-custom-posts-box">
					<div class="oa-cpt-section-head">
						<div>
							<h3><?php esc_html_e( 'Post Types', 'octave-addons' ); ?></h3>
							<p><?php esc_html_e( 'Add content areas, configure how they behave, and drag them into WordPress admin menu order.', 'octave-addons' ); ?></p>
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

			</div>

			<div class="oa-content-tab-panel oa-hidden" id="oa-categories" role="tabpanel" aria-labelledby="oa-content-tab-oa-categories" tabindex="0">

				<?php $this->render_content_directory( $this->categories_directory( $custom_taxonomies, $post_type_options ) ); ?>

			</div>

			<div class="oa-content-tab-panel oa-hidden" id="oa-content-management" role="tabpanel" aria-labelledby="oa-content-tab-oa-content-management" tabindex="0">

				<?php $this->render_content_directory( $this->content_directory( $custom_types, $custom_fields ) ); ?>

			</div>

		</div>

		<?php

		$this->render_preserved_collection( 'custom_taxonomies', $custom_taxonomies );
		$this->render_preserved_collection( 'custom_fields', $custom_fields );

	}

	/*
	CATEGORIES DIRECTORY
	-- Builds the full taxonomy listing shown in the Taxonomies tab.
	---------------------------------------------------------- */

	protected function categories_directory( array $taxonomies, array $post_type_options ): array {

		$rows = [];

		foreach ( $taxonomies as $taxonomy ) {

			$assigned = [];

			foreach ( $taxonomy['post_types'] as $assigned_key ) {

				$assigned[] = $post_type_options[ $assigned_key ] ?? $assigned_key;

			}

			$tags = [
				[
					'label' => empty( $taxonomy['hierarchical'] ) ? __( 'Tag', 'octave-addons' ) : __( 'Category', 'octave-addons' ),
				],
				[
					'label' => empty( $taxonomy['public'] ) ? __( 'Admin only', 'octave-addons' ) : __( 'Public', 'octave-addons' ),
					'quiet' => true,
				],
			];

			if ( empty( $taxonomy['enabled'] ) ) {

				$tags[] = [
					'label' => __( 'Disabled', 'octave-addons' ),
					'muted' => true,
				];

			}

			$rows[] = [
				'label' => $taxonomy['name'],
				'code'  => $taxonomy['taxonomy'],
				'meta'  => empty( $assigned )
					? __( 'Not assigned to a post type', 'octave-addons' )
					: sprintf(
						/* translators: %s: comma separated list of post type names. */
						__( 'Used by %s', 'octave-addons' ),
						implode( ', ', $assigned )
					),
				'tags'  => $tags,
				'url'   => $this->schema_url( 'taxonomy', $taxonomy['taxonomy'] ),
			];

		}

		return [
			'icon'        => 'dashicons-category',
			'title'       => __( 'Taxonomies', 'octave-addons' ),
			'summary'     => __( 'Classification systems shared by one or more post types.', 'octave-addons' ),
			'search'      => __( 'Search taxonomies', 'octave-addons' ),
			'no_results'  => __( 'No taxonomies match that search.', 'octave-addons' ),
			'actions'     => [
				[
					'label' => __( 'New taxonomy', 'octave-addons' ),
					'url'   => $this->schema_url( 'taxonomy', 'new' ),
				],
			],
			'groups'      => [
				[
					'label' => __( 'All taxonomies', 'octave-addons' ),
					'empty' => __( 'No taxonomies yet. Add one and assign it to a post type.', 'octave-addons' ),
					'rows'  => $rows,
				],
			],
		];

	}

	/*
	CONTENT DIRECTORY
	-- Builds the field listing shown in the Fields tab, grouped by
	-- the reusable library and then by each post type.
	---------------------------------------------------------- */

	protected function content_directory( array $post_types, array $fields ): array {

		$library_url = $this->schema_url( 'library' );
		$types       = $this->field_types();
		$groups      = [
			[
				'label'  => __( 'Reusable Fields', 'octave-addons' ),
				'action' => [
					'label' => __( 'Manage', 'octave-addons' ),
					'url'   => $library_url . '#oa-reusable-content-fields',
				],
				'empty'  => __( 'No reusable fields yet. Create one to share it across post types.', 'octave-addons' ),
				'rows'   => $this->content_directory_rows( $this->reusable_fields( $fields ), $types, $library_url . '#oa-reusable-content-fields' ),
			],
		];

		foreach ( $post_types as $post_type ) {

			$key         = (string) $post_type['post_type'];
			$editor_url  = $this->editor_url( $key, 'fields' );

			$groups[] = [
				'label'  => sprintf(
					/* translators: %s: post type name. */
					__( '%s Fields', 'octave-addons' ),
					$post_type['name']
				),
				'action' => [
					'label' => __( 'Manage', 'octave-addons' ),
					'url'   => $editor_url,
				],
				'empty'  => __( 'No fields yet for this post type.', 'octave-addons' ),
				'rows'   => $this->content_directory_rows( $this->definitions_for_post_type( $fields, $key ), $types, $editor_url ),
			];

		}

		$populated_groups = array_values( array_filter( $groups, function ( array $group ): bool {

			return ! empty( $group['rows'] );

		} ) );

		if ( empty( $populated_groups ) ) {

			$populated_groups = [ $groups[0] ];

		}

		return [
			'icon'       => 'dashicons-feedback',
			'title'      => __( 'Fields', 'octave-addons' ),
			'summary'    => __( 'Structured fields editors complete when adding content.', 'octave-addons' ),
			'search'     => __( 'Search fields', 'octave-addons' ),
			'no_results' => __( 'No fields match that search.', 'octave-addons' ),
			'actions'    => [
				[
					'label' => __( 'New field', 'octave-addons' ),
					'url'   => add_query_arg( 'add', 'field', $library_url ),
				],
				[
					'label' => __( 'Reusable fields', 'octave-addons' ),
					'url'   => $library_url . '#oa-reusable-content-fields',
					'quiet' => true,
				],
			],
			'groups'     => $populated_groups,
		];

	}

	/*
	CONTENT DIRECTORY ROWS
	-- Turns field definitions into directory rows with their type and state.
	---------------------------------------------------------- */

	protected function content_directory_rows( array $fields, array $types, string $url ): array {

		$rows = [];

		foreach ( $fields as $field ) {

			$tags = [
				[
					'label' => $types[ $field['type'] ] ?? $field['type'],
				],
			];

			if ( ! empty( $field['required'] ) ) {

				$tags[] = [
					'label' => __( 'Required', 'octave-addons' ),
					'quiet' => true,
				];

			}

			if ( ! empty( $field['sub_fields'] ) ) {

				$count = count( $field['sub_fields'] );

				$tags[] = [
					'label' => sprintf(
						/* translators: %s: number of sub fields. */
						_n( '%s sub field', '%s sub fields', $count, 'octave-addons' ),
						number_format_i18n( $count )
					),
					'quiet' => true,
				];

			}

			if ( empty( $field['enabled'] ) ) {

				$tags[] = [
					'label' => __( 'Disabled', 'octave-addons' ),
					'muted' => true,
				];

			}

			$rows[] = [
				'label' => $field['label'],
				'code'  => $field['name'],
				'meta'  => $field['description'],
				'tags'  => $tags,
				'url'   => $url,
			];

		}

		return $rows;

	}

	/*
	RENDER CONTENT DIRECTORY
	-- Outputs one expanded, filterable listing for a content tab.
	---------------------------------------------------------- */

	protected function render_content_directory( array $directory ): void {

		$total = 0;

		foreach ( $directory['groups'] as $group ) {

			$total += count( $group['rows'] );

		}

		?>

		<section class="oa-content-directory" data-oa-directory>

			<header class="oa-content-directory-head">
				<span class="oa-content-directory-icon" aria-hidden="true"><span class="dashicons <?= esc_attr( $directory['icon'] ); ?>"></span></span>
				<div class="oa-content-directory-copy">
					<h3><?= esc_html( $directory['title'] ); ?></h3>
					<p><?= esc_html( $directory['summary'] ); ?></p>
				</div>
				<div class="oa-content-directory-actions">

					<?php

					foreach ( $directory['actions'] as $action ) :

						$classes = 'oa-overview-action' . ( empty( $action['quiet'] ) ? '' : ' is-quiet' );

					?>

					<a href="<?= esc_url( $action['url'] ); ?>" class="<?= esc_attr( $classes ); ?>"><?= empty( $action['quiet'] ) ? '<span aria-hidden="true">+</span>' : ''; ?><?= esc_html( $action['label'] ); ?></a>

					<?php

					endforeach;

					?>

				</div>
			</header>

			<div class="oa-content-directory-tools">
				<label class="oa-content-directory-search">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<input type="search" data-oa-directory-search placeholder="<?= esc_attr( $directory['search'] ); ?>" aria-label="<?= esc_attr( $directory['search'] ); ?>">
				</label>
				<span class="oa-content-directory-total">

					<?php

					printf(
						/* translators: %s: total number of listed items. */
						esc_html( _n( '%s item', '%s items', $total, 'octave-addons' ) ),
						esc_html( number_format_i18n( $total ) )
					);

					?>

				</span>
			</div>

			<div class="oa-content-directory-groups">

				<?php

				foreach ( $directory['groups'] as $group ) :

				?>

				<section class="oa-content-directory-group" data-oa-directory-group>

					<div class="oa-content-directory-group-head">
						<h4><?= esc_html( $group['label'] ); ?></h4>
						<span class="oa-content-directory-group-count"><?= esc_html( number_format_i18n( count( $group['rows'] ) ) ); ?></span>

						<?php

						if ( ! empty( $group['action'] ) ) :

						?>

						<a href="<?= esc_url( $group['action']['url'] ); ?>" class="oa-content-directory-group-link"><?= esc_html( $group['action']['label'] ); ?></a>

						<?php

						endif;

						?>

					</div>

					<?php

					if ( empty( $group['rows'] ) ) :

					?>

					<p class="oa-content-directory-empty"><?= esc_html( $group['empty'] ); ?></p>

					<?php

					else :

					?>

					<ul class="oa-content-directory-rows">

						<?php

						foreach ( $group['rows'] as $row ) :

							$haystack = strtolower( $row['label'] . ' ' . $row['code'] . ' ' . $row['meta'] );

						?>

						<li class="oa-content-directory-row" data-oa-directory-row data-search="<?= esc_attr( $haystack ); ?>">
							<a href="<?= esc_url( $row['url'] ); ?>" class="oa-content-directory-row-link">
								<span class="oa-content-directory-row-main">
									<strong><?= esc_html( $row['label'] ); ?></strong>
									<code><?= esc_html( $row['code'] ); ?></code>

									<?php

									if ( '' !== $row['meta'] ) :

									?>

									<span class="oa-content-directory-row-meta"><?= esc_html( $row['meta'] ); ?></span>

									<?php

									endif;

									?>

								</span>
								<span class="oa-content-directory-row-tags">

									<?php

									foreach ( $row['tags'] as $tag ) :

										$tag_classes = 'oa-content-directory-tag';
										$tag_classes .= empty( $tag['quiet'] ) ? '' : ' is-quiet';
										$tag_classes .= empty( $tag['muted'] ) ? '' : ' is-muted';

									?>

									<span class="<?= esc_attr( $tag_classes ); ?>"><?= esc_html( $tag['label'] ); ?></span>

									<?php

									endforeach;

									?>

								</span>
								<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
							</a>
						</li>

						<?php

						endforeach;

						?>

					</ul>

					<?php

					endif;

					?>

				</section>

				<?php

				endforeach;

				?>

			</div>

			<p class="oa-content-directory-no-results oa-hidden" data-oa-directory-empty><?= esc_html( $directory['no_results'] ); ?></p>

		</section>

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
			$this->render_preserved_collection( 'custom_fields', $this->specific_fields_outside_post_type( $fields, '' ) );

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
				<span class="oa-cpt-editor-current-section"><?= 'categories' === $section ? esc_html__( 'Editing taxonomies', 'octave-addons' ) : esc_html__( 'Editing fields', 'octave-addons' ); ?></span>
			</div>
			<div class="oa-cpt-editor-title">
				<h4><?= 'categories' === $section ? esc_html__( 'Taxonomies', 'octave-addons' ) : esc_html__( 'Fields', 'octave-addons' ); ?></h4>
				<p><?= 'categories' === $section ? esc_html__( 'Choose how editors classify this content type and set the order shown here.', 'octave-addons' ) : esc_html__( 'Build the structured information editors complete for this content type.', 'octave-addons' ); ?></p>
			</div>
			<nav class="oa-cpt-editor-tabs" aria-label="<?php esc_attr_e( 'Post type content settings', 'octave-addons' ); ?>">
				<a href="<?= esc_url( $this->editor_url( $key, 'categories' ) ); ?>" class="<?= 'categories' === $section ? 'is-active' : ''; ?>"><?php esc_html_e( 'Taxonomies', 'octave-addons' ); ?></a>
				<a href="<?= esc_url( $this->editor_url( $key, 'fields' ) ); ?>" class="<?= 'fields' === $section ? 'is-active' : ''; ?>"><?php esc_html_e( 'Fields', 'octave-addons' ); ?></a>
			</nav>
		</div>

		<?php

		if ( 'categories' === $section ) {

			$this->render_assignment_manager( 'custom_taxonomies', $taxonomies, $key, $post_type_options );
			$this->render_preserved_collection( 'custom_fields', $fields );

			return;

		}

		$this->render_field_editor( $this->fields_for_post_type_editor( $fields, $key ), $post_type_options, $key );
		$this->render_preserved_collection( 'custom_fields', $this->specific_fields_outside_post_type( $fields, $key ) );
		$this->render_preserved_collection( 'custom_taxonomies', $taxonomies );

	}

	/*
	RENDER SCHEMA LIBRARY
	-- Lists reusable categories and exposes reusable fields for inline editing.
	---------------------------------------------------------- */

	protected function render_schema_library( array $taxonomies, array $fields, array $post_types ): void {

		?>

		<div class="oa-schema-page-head">
			<a href="<?= esc_url( $this->settings_url() ); ?>" class="oa-cpt-back-link"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?php esc_html_e( 'All post types', 'octave-addons' ); ?></a>
			<span class="oa-panel-kicker"><?php esc_html_e( 'Reusable content definitions', 'octave-addons' ); ?></span>
			<h3><?php esc_html_e( 'Content schema library', 'octave-addons' ); ?></h3>
			<p><?php esc_html_e( 'Define reusable taxonomies and fields once, then attach them to every content type that needs them. Fields can be created and edited directly below.', 'octave-addons' ); ?></p>
		</div>

		<?php

		$this->render_schema_collection( 'custom_taxonomies', $taxonomies, $post_types );
		$this->render_field_editor( $this->reusable_fields( $fields ), $post_types, '', false, $this->should_start_new_library_field() );

	}

	/*
	RENDER SCHEMA COLLECTION
	-- Outputs compact taxonomy or field summary cards.
	---------------------------------------------------------- */

	protected function render_schema_collection( string $collection, array $definitions, array $post_types ): void {

		$is_taxonomy = 'custom_taxonomies' === $collection;
		$title       = $is_taxonomy ? __( 'Taxonomies', 'octave-addons' ) : __( 'Fields', 'octave-addons' );
		$description = $is_taxonomy
			? __( 'Reusable classification structures shared between content types.', 'octave-addons' )
			: __( 'Reusable typed values, including groups and repeaters, shared between post editors.', 'octave-addons' );
		$add_url     = $this->schema_url( $is_taxonomy ? 'taxonomy' : 'field', 'new' );

		?>

		<section class="oa-schema-collection">
			<div class="oa-schema-collection-head">
				<div>
					<h4><?= esc_html( $title ); ?></h4>
					<p><?= esc_html( $description ); ?></p>
				</div>
				<a href="<?= esc_url( $add_url ); ?>" class="button oa-schema-add"><span aria-hidden="true">+</span><?= $is_taxonomy ? esc_html__( 'Add taxonomy', 'octave-addons' ) : esc_html__( 'Add field', 'octave-addons' ); ?></a>
			</div>

			<div class="oa-schema-card-grid">

				<?php

				if ( empty( $definitions ) ) :

				?>

				<div class="oa-schema-empty"><?= $is_taxonomy ? esc_html__( 'No reusable taxonomies have been created.', 'octave-addons' ) : esc_html__( 'No reusable fields have been created.', 'octave-addons' ); ?></div>

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
		$title       = $is_taxonomy ? __( 'Available taxonomies', 'octave-addons' ) : __( 'Available fields', 'octave-addons' );
		$add_url     = $this->schema_url( $is_taxonomy ? 'taxonomy' : 'field', 'new', $post_type );

		if ( $is_taxonomy ) {

			$definitions = $this->sort_taxonomies_for_post_type( $definitions, $post_type );

		}

		?>

		<section class="oa-assignment-manager">
			<div class="oa-assignment-manager-head">
				<div>
					<h3><?= esc_html( $title ); ?></h3>
					<p><?= $is_taxonomy ? esc_html__( 'Assign taxonomies to this post type, then drag them into the order you want. Each taxonomy remains shared everywhere it is assigned.', 'octave-addons' ) : esc_html__( 'Assign fields to this post type. Reusable fields remain shared everywhere they are assigned.', 'octave-addons' ); ?></p>
				</div>
				<div class="oa-assignment-manager-actions">
					<a href="<?= esc_url( $this->schema_url( 'library' ) ); ?>" class="button oa-schema-library-link"><?php esc_html_e( 'Open schema library', 'octave-addons' ); ?></a>
					<a href="<?= esc_url( $add_url ); ?>" class="button oa-schema-add"><span aria-hidden="true">+</span><?= $is_taxonomy ? esc_html__( 'New taxonomy', 'octave-addons' ) : esc_html__( 'New field', 'octave-addons' ); ?></a>
				</div>
			</div>

			<div class="oa-assignment-manager-list<?= $is_taxonomy ? ' oa-taxonomy-order-list' : ''; ?>"<?= $is_taxonomy ? ' data-post-type="' . esc_attr( $post_type ) . '"' : ''; ?>>

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

			<?php

			if ( $is_taxonomy ) :

			?>

			<div class="oa-taxonomy-order-status screen-reader-text" role="status" aria-live="polite"></div>

			<?php

			endif;

			?>
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

			if ( 'post_types' !== $definition_key && 'post_type_order' !== $definition_key ) {

				$this->render_hidden_value( $this->collection_field_name( $collection, $index, $definition_key ), $value );

			}

		}

		if ( $is_taxonomy ) {

			$order = is_array( $definition['post_type_order'] ?? null ) ? $definition['post_type_order'] : [];

			foreach ( $order as $order_post_type => $position ) {

				if ( $post_type !== $order_post_type ) {

					$this->render_hidden_value( $this->collection_field_name( $collection, $index, 'post_type_order' ) . '[' . $order_post_type . ']', $position );

				}

			}

		}

		foreach ( $assigned as $assigned_post_type ) {

			if ( $post_type !== $assigned_post_type ) {

				$this->render_hidden_value( $this->collection_field_name( $collection, $index, 'post_types' ) . '[]', $assigned_post_type );

			}

		}

		?>

		<article class="oa-assignment-card<?= in_array( $post_type, $assigned, true ) ? ' is-assigned' : ''; ?><?= $is_taxonomy ? ' oa-taxonomy-order-item' : ''; ?>" draggable="false" data-assigned-label="<?php esc_attr_e( 'Used here', 'octave-addons' ); ?>" data-unassigned-label="<?php esc_attr_e( 'Add here', 'octave-addons' ); ?>">

			<?php

			if ( $is_taxonomy ) :

			?>

			<button type="button" class="oa-taxonomy-drag-handle" aria-label="<?php esc_attr_e( 'Drag to reorder this taxonomy, or use the arrow keys', 'octave-addons' ); ?>"><span class="dashicons dashicons-menu" aria-hidden="true"></span></button>
			<input type="hidden" class="oa-taxonomy-order-value" name="<?= esc_attr( $this->collection_field_name( $collection, $index, 'post_type_order' ) . '[' . $post_type . ']' ); ?>" value="<?= esc_attr( (string) ( $order[ $post_type ] ?? 0 ) ); ?>">

			<?php

			endif;

			?>

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
	SORT TAXONOMIES FOR POST TYPE
	-- Applies the saved order for one post type without changing how the shared
	-- taxonomy library is arranged for every other content area.
	---------------------------------------------------------- */

	protected function sort_taxonomies_for_post_type( array $taxonomies, string $post_type ): array {

		$indexed = [];

		foreach ( array_values( $taxonomies ) as $index => $taxonomy ) {

			$order     = is_array( $taxonomy['post_type_order'] ?? null ) ? $taxonomy['post_type_order'] : [];
			$indexed[] = [
				'definition' => $taxonomy,
				'fallback'   => $index,
				'position'   => isset( $order[ $post_type ] ) ? (int) $order[ $post_type ] : $index,
			];

		}

		usort( $indexed, function ( array $first, array $second ): int {

			if ( $first['position'] === $second['position'] ) {

				return $first['fallback'] <=> $second['fallback'];

			}

			return $first['position'] <=> $second['position'];

		} );

		return array_column( $indexed, 'definition' );

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

		<div class="oa-schema-page-head oa-schema-page-head--editor"<?= $is_new ? '' : ' data-oa-overview-url="' . esc_url( add_query_arg( 'oa-notice', $is_taxonomy ? 'category-deleted' : 'field-deleted', $back_url ) ) . '"'; ?>>
			<a href="<?= esc_url( $back_url ); ?>" class="oa-cpt-back-link"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?= esc_html( $back_label ); ?></a>
			<span class="oa-panel-kicker"><?= $is_taxonomy ? esc_html__( 'Reusable taxonomy', 'octave-addons' ) : esc_html__( 'Reusable field', 'octave-addons' ); ?></span>
			<h3><?= $is_new ? ( $is_taxonomy ? esc_html__( 'Add taxonomy', 'octave-addons' ) : esc_html__( 'Add field', 'octave-addons' ) ) : sprintf( esc_html__( 'Editing: %s', 'octave-addons' ), esc_html( $is_taxonomy ? $selected['name'] : $selected['label'] ) ); ?></h3>
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
		$publicly_queryable     = ! array_key_exists( 'publicly_queryable', $post_type ) ? $public : ! empty( $post_type['publicly_queryable'] );
		$content_editor         = ! array_key_exists( 'content_editor', $post_type ) || ! empty( $post_type['content_editor'] );
		$has_archive            = ! empty( $post_type['has_archive'] );
		$enabled                = ! empty( $post_type['enabled'] );
		$taxonomies_url         = $saved ? $this->editor_url( $key, 'categories' ) : '';
		$fields_url             = $saved ? $this->editor_url( $key, 'fields' ) : '';
		$category_count         = $saved ? count( $this->definitions_for_post_type( $taxonomies, $key ) ) : 0;
		$field_count            = $saved ? count( $this->definitions_for_post_type( $fields, $key ) ) : 0;
		$dashicons              = $this->dashicons();

		?>

		<article class="oa-cpt-item oa-post-type-item" draggable="false" data-saved="<?= $saved ? 'true' : 'false'; ?>"<?= $saved ? ' id="oa-cpt-' . esc_attr( $key ) . '"' : ''; ?>>
			<div class="oa-cpt-item-head">
				<button type="button" class="oa-cpt-drag-handle" aria-label="<?php esc_attr_e( 'Drag to reorder this post type, or use the arrow keys', 'octave-addons' ); ?>">
					<span class="dashicons dashicons-menu" aria-hidden="true"></span>
				</button>
				<div class="oa-cpt-expand-copy">
					<span class="dashicons <?= esc_attr( $menu_icon ); ?> oa-cpt-item-icon oa-cpt-live-icon" aria-hidden="true"></span>
					<strong class="oa-cpt-item-title"><?= esc_html( '' !== $name ? $name : __( 'New post type', 'octave-addons' ) ); ?></strong>
					<span class="oa-cpt-key-preview"><?= esc_html( $key ); ?></span>
				</div>

				<?php

				if ( $saved ) :

				?>

				<nav class="oa-cpt-row-links" aria-label="<?= esc_attr( sprintf( __( '%s content structure', 'octave-addons' ), $name ) ); ?>">
					<a href="<?= esc_url( $taxonomies_url ); ?>"><span class="dashicons dashicons-category" aria-hidden="true"></span><?php esc_html_e( 'Taxonomies', 'octave-addons' ); ?><span><?= esc_html( number_format_i18n( $category_count ) ); ?></span></a>
					<a href="<?= esc_url( $fields_url ); ?>"><span class="dashicons dashicons-feedback" aria-hidden="true"></span><?php esc_html_e( 'Fields', 'octave-addons' ); ?><span><?= esc_html( number_format_i18n( $field_count ) ); ?></span></a>
				</nav>

				<?php

				endif;

				?>

				<button type="button" class="oa-cpt-expand" aria-expanded="false">
					<span class="oa-cpt-expand-label"><?php esc_html_e( 'Edit settings', 'octave-addons' ); ?></span>
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

						<label class="oa-cpt-field oa-cpt-field--full<?= $saved ? ' oa-key-field' : ''; ?>">
							<span><?php esc_html_e( 'Post type key', 'octave-addons' ); ?></span>
							<span class="oa-key-control">
								<input type="text" data-cpt-field="post_type" name="<?= esc_attr( $this->cpt_field_name( $index, 'post_type' ) ); ?>" value="<?= esc_attr( $key ); ?>" maxlength="20" pattern="[a-z0-9_]+" required<?= $saved ? ' readonly' : ''; ?>>

								<?php

								if ( $saved ) {

									$this->render_key_unlock(
										$this->cpt_field_name( $index, 'original_post_type' ),
										$key,
										__( 'Edit post type key', 'octave-addons' ),
										__( 'Renaming the key registers a new post type. Posts already saved under the old key stay in the database but are hidden until that key is registered again. Category and field assignments follow the rename.', 'octave-addons' )
									);

								}

								?>

							</span>
							<small><?= $saved ? esc_html__( 'Locked after saving to protect existing content. Use the edit button to rename it.', 'octave-addons' ) : esc_html__( 'Use an oa_ prefix; maximum 20 characters.', 'octave-addons' ); ?></small>
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
								<div class="oa-cpt-icon-options oa-hidden">
									<label class="oa-cpt-icon-search">
										<span class="screen-reader-text"><?php esc_html_e( 'Search WordPress admin menu icons', 'octave-addons' ); ?></span>
										<input type="search" placeholder="<?php esc_attr_e( 'Search Dashicons…', 'octave-addons' ); ?>" autocomplete="off">
									</label>
									<div class="oa-cpt-icon-grid" role="listbox" aria-label="<?php esc_attr_e( 'WordPress admin menu icons', 'octave-addons' ); ?>">

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
									<p class="oa-cpt-icon-empty oa-hidden"><?php esc_html_e( 'No Dashicons match your search.', 'octave-addons' ); ?></p>
								</div>
							</div>
							<small><?php esc_html_e( 'Uses WordPress Dashicons, so no additional icon files are loaded.', 'octave-addons' ); ?></small>
						</div>
					</div>
				</fieldset>

				<details class="oa-cpt-advanced">
					<summary class="oa-cpt-advanced-summary">
						<span>
							<strong><?php esc_html_e( 'Advanced settings', 'octave-addons' ); ?></strong>
							<small><?php esc_html_e( 'Visibility, URLs and editor behaviour', 'octave-addons' ); ?></small>
						</span>
						<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
					</summary>
					<div class="oa-cpt-advanced-body">
						<fieldset class="oa-cpt-group">
					<legend><?php esc_html_e( 'Visibility', 'octave-addons' ); ?></legend>
					<p class="oa-cpt-group-description"><?php esc_html_e( 'Control how the type is registered and whether it answers frontend URLs.', 'octave-addons' ); ?></p>
					<div class="oa-cpt-fields oa-cpt-fields--switches">
						<div class="oa-cpt-field oa-cpt-switch-field">
							<span><?php esc_html_e( 'Public', 'octave-addons' ); ?></span>
							<label class="oa-switch">
								<input type="checkbox" class="oa-cpt-public-toggle" name="<?= esc_attr( $this->cpt_field_name( $index, 'public' ) ); ?>" value="1"<?= checked( $public, true, false ); ?>>
								<span class="oa-switch-slider"></span>
							</label>
							<small><?php esc_html_e( 'Register the type as public content. Builders such as Breakdance only list public post types in their query and template pickers.', 'octave-addons' ); ?></small>
						</div>

						<div class="oa-cpt-field oa-cpt-switch-field">
							<span><?php esc_html_e( 'Publicly queryable', 'octave-addons' ); ?></span>
							<label class="oa-switch">
								<input type="hidden" name="<?= esc_attr( $this->cpt_field_name( $index, 'publicly_queryable' ) ); ?>" value="0">
								<input type="checkbox" class="oa-cpt-queryable-toggle" name="<?= esc_attr( $this->cpt_field_name( $index, 'publicly_queryable' ) ); ?>" value="1"<?= checked( $publicly_queryable, true, false ); ?>>
								<span class="oa-switch-slider"></span>
							</label>
							<small><?php esc_html_e( 'Answer frontend URLs, archives and search results. Turn this off to keep entries available to builders and queries while their own URLs stay unavailable.', 'octave-addons' ); ?></small>
						</div>
					</div>
						</fieldset>

						<fieldset class="oa-cpt-group oa-cpt-urls<?= $publicly_queryable ? '' : ' oa-hidden'; ?>">
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

						<fieldset class="oa-cpt-group">
					<legend><?php esc_html_e( 'Editing', 'octave-addons' ); ?></legend>
					<p class="oa-cpt-group-description"><?php esc_html_e( 'Choose whether this post type needs the standard WordPress content area as well as its Octave content fields.', 'octave-addons' ); ?></p>
					<div class="oa-cpt-fields oa-cpt-fields--switches">
						<div class="oa-cpt-field oa-cpt-switch-field">
							<span><?php esc_html_e( 'Content editor', 'octave-addons' ); ?></span>
							<label class="oa-switch">
								<input type="hidden" name="<?= esc_attr( $this->cpt_field_name( $index, 'content_editor' ) ); ?>" value="0">
								<input type="checkbox" name="<?= esc_attr( $this->cpt_field_name( $index, 'content_editor' ) ); ?>" value="1"<?= checked( $content_editor, true, false ); ?>>
								<span class="oa-switch-slider"></span>
							</label>
							<small><?php esc_html_e( 'Turn off when entries are built entirely from Octave content fields, such as testimonials. Gutenberg remains available, with the Octave fields replacing its content canvas.', 'octave-addons' ); ?></small>
						</div>
					</div>
						</fieldset>
					</div>
				</details>

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
					<span><?php esc_html_e( 'First taxonomy', 'octave-addons' ); ?></span>
					<input type="text" name="<?= esc_attr( $this->cpt_field_name( $index, 'starter_taxonomy_name' ) ); ?>" value="" placeholder="<?php esc_attr_e( 'Project Categories', 'octave-addons' ); ?>">
					<small><?php esc_html_e( 'Plural name for the taxonomy.', 'octave-addons' ); ?></small>
				</label>

				<label class="oa-cpt-field">
					<span><?php esc_html_e( 'Taxonomy singular name', 'octave-addons' ); ?></span>
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
			'enabled'           => true,
			'name'              => '',
			'singular_name'     => '',
			'taxonomy'          => 'oa_category',
			'slug'              => '',
			'hierarchical'      => true,
			'public'            => true,
			'show_admin_column' => true,
			'show_admin_filter' => false,
			'post_types'        => '' !== $primary_post_type ? [ $primary_post_type ] : [],
			'post_type_order'   => [],
		];

		if ( $start_new ) {

			$taxonomies = [ $template ];

		}

		?>

		<div class="oa-cpt-section oa-custom-posts-box oa-collection<?= $single ? ' oa-single-definition' : ''; ?>" data-collection="custom_taxonomies" data-new-label="<?php esc_attr_e( 'New taxonomy', 'octave-addons' ); ?>">
			<div class="oa-cpt-section-head">
				<div>
					<span class="oa-panel-kicker"><?php esc_html_e( 'Post Types', 'octave-addons' ); ?></span>
					<h3><?php esc_html_e( 'Taxonomies', 'octave-addons' ); ?></h3>
					<p><?php esc_html_e( 'Create reusable taxonomies, then assign each one to any combination of posts, pages, or custom post types.', 'octave-addons' ); ?></p>
				</div>
				<button type="button" class="button oa-cpt-add oa-collection-add<?= $single ? ' oa-hidden' : ''; ?>"><span class="oa-cpt-add-icon" aria-hidden="true">+</span><?php esc_html_e( 'Add taxonomy', 'octave-addons' ); ?></button>
			</div>

			<div class="oa-cpt-list oa-collection-list" data-empty-text="<?php esc_attr_e( 'No custom taxonomies have been added.', 'octave-addons' ); ?>">

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
	RENDER KEY UNLOCK
	-- Prints the edit control that reopens a key locked by the first save, and
	-- stores the key it was locked with so a rename can be traced on save.
	---------------------------------------------------------- */

	protected function render_key_unlock( string $original_name, string $key, string $label, string $warning ): void {

		?>

		<input type="hidden" data-role="original-key" name="<?= esc_attr( $original_name ); ?>" value="<?= esc_attr( $key ); ?>">
		<button type="button" class="oa-key-edit" data-warning="<?= esc_attr( $warning ); ?>" aria-label="<?= esc_attr( $label ); ?>" title="<?= esc_attr( $label ); ?>">
			<span class="dashicons dashicons-edit" aria-hidden="true"></span>
		</button>

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

			<?php

			$this->render_hidden_value( $this->collection_field_name( 'custom_taxonomies', $index, 'post_type_order' ), is_array( $taxonomy['post_type_order'] ?? null ) ? $taxonomy['post_type_order'] : [] );

			?>

			<div class="oa-cpt-item-head">
				<button type="button" class="oa-cpt-expand oa-collection-expand" aria-expanded="<?= $saved && ! $expanded ? 'false' : 'true'; ?>">
					<span class="oa-cpt-expand-copy"><strong class="oa-cpt-item-title"><?= esc_html( '' !== $name ? $name : __( 'New taxonomy', 'octave-addons' ) ); ?></strong><span class="oa-cpt-key-preview"><?= esc_html( $key ); ?></span></span>
					<span class="dashicons dashicons-arrow-down-alt2 oa-cpt-expand-icon" aria-hidden="true"></span>
				</button>
				<div class="oa-cpt-enabled-summary"><span><?php esc_html_e( 'Enabled', 'octave-addons' ); ?></span><label class="oa-switch"><input type="checkbox" data-role="enabled" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'enabled' ) ); ?>" value="1"<?= checked( ! empty( $taxonomy['enabled'] ), true, false ); ?>><span class="oa-switch-slider"></span></label></div>
				<button type="button" class="oa-cpt-remove oa-collection-remove" aria-label="<?php esc_attr_e( 'Remove this taxonomy', 'octave-addons' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
			</div>

			<div class="oa-cpt-groups oa-collection-body<?= $saved && ! $expanded ? ' oa-hidden' : ''; ?>">
				<fieldset class="oa-cpt-group">
					<legend><?php esc_html_e( 'Identity and URLs', 'octave-addons' ); ?></legend>
					<p class="oa-cpt-group-description"><?php esc_html_e( 'The key becomes permanent after the first save. Categories behave hierarchically; tags do not.', 'octave-addons' ); ?></p>
					<div class="oa-cpt-fields">
						<label class="oa-cpt-field"><span><?php esc_html_e( 'Plural name', 'octave-addons' ); ?></span><input type="text" data-role="title" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'name' ) ); ?>" value="<?= esc_attr( $name ); ?>" placeholder="Project Categories" required></label>
						<label class="oa-cpt-field"><span><?php esc_html_e( 'Singular name', 'octave-addons' ); ?></span><input type="text" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'singular_name' ) ); ?>" value="<?= esc_attr( (string) ( $taxonomy['singular_name'] ?? '' ) ); ?>" placeholder="Project Category" required></label>
						<label class="oa-cpt-field<?= $saved ? ' oa-key-field' : ''; ?>">
							<span><?php esc_html_e( 'Taxonomy key', 'octave-addons' ); ?></span>
							<span class="oa-key-control">
								<input type="text" data-role="key" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'taxonomy' ) ); ?>" value="<?= esc_attr( $key ); ?>" maxlength="32" pattern="[a-z0-9_]+" required<?= $saved ? ' readonly' : ''; ?>>

								<?php

								if ( $saved ) {

									$this->render_key_unlock(
										$this->collection_field_name( 'custom_taxonomies', $index, 'original_taxonomy' ),
										$key,
										__( 'Edit taxonomy key', 'octave-addons' ),
										__( 'Renaming the key registers a new taxonomy. Terms already saved under the old key stay in the database but are hidden until that key is registered again.', 'octave-addons' )
									);

								}

								?>

							</span>

							<?php

							if ( $saved ) :

							?>

							<small><?php esc_html_e( 'Locked after saving to protect existing terms. Use the edit button to rename it.', 'octave-addons' ); ?></small>

							<?php

							endif;

							?>

						</label>
						<div class="oa-cpt-field oa-cpt-switch-field"><span><?php esc_html_e( 'Hierarchical', 'octave-addons' ); ?></span><label class="oa-switch"><input type="checkbox" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'hierarchical' ) ); ?>" value="1"<?= checked( ! empty( $taxonomy['hierarchical'] ), true, false ); ?>><span class="oa-switch-slider"></span></label><small><?php esc_html_e( 'Enable parent and child terms like Categories.', 'octave-addons' ); ?></small></div>
						<div class="oa-cpt-field oa-cpt-switch-field"><span><?php esc_html_e( 'Public archives', 'octave-addons' ); ?></span><label class="oa-switch"><input type="checkbox" class="oa-tax-public-toggle" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'public' ) ); ?>" value="1"<?= checked( ! empty( $taxonomy['public'] ), true, false ); ?>><span class="oa-switch-slider"></span></label><small><?php esc_html_e( 'Expose term archive URLs and navigation options.', 'octave-addons' ); ?></small></div>
						<div class="oa-cpt-field oa-cpt-switch-field"><span><?php esc_html_e( 'Show in admin column', 'octave-addons' ); ?></span><input type="hidden" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'show_admin_column' ) ); ?>" value="0"><label class="oa-switch"><input type="checkbox" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'show_admin_column' ) ); ?>" value="1"<?= checked( ! array_key_exists( 'show_admin_column', $taxonomy ) || ! empty( $taxonomy['show_admin_column'] ), true, false ); ?>><span class="oa-switch-slider"></span></label><small><?php esc_html_e( 'Show a sortable taxonomy column in assigned post type tables.', 'octave-addons' ); ?></small></div>
						<div class="oa-cpt-field oa-cpt-switch-field"><span><?php esc_html_e( 'Show in admin filter', 'octave-addons' ); ?></span><input type="hidden" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'show_admin_filter' ) ); ?>" value="0"><label class="oa-switch"><input type="checkbox" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'show_admin_filter' ) ); ?>" value="1"<?= checked( ! empty( $taxonomy['show_admin_filter'] ), true, false ); ?>><span class="oa-switch-slider"></span></label><small><?php esc_html_e( 'Add a term dropdown above assigned post type tables.', 'octave-addons' ); ?></small></div>
						<label class="oa-cpt-field oa-cpt-field--full oa-tax-url-field<?= empty( $taxonomy['public'] ) ? ' oa-hidden' : ''; ?>"><span><?php esc_html_e( 'URL slug', 'octave-addons' ); ?></span><input type="text" name="<?= esc_attr( $this->collection_field_name( 'custom_taxonomies', $index, 'slug' ) ); ?>" value="<?= esc_attr( (string) ( $taxonomy['slug'] ?? '' ) ); ?>" placeholder="project-category" required><small><?php esc_html_e( 'The term archive URL path. Falls back to the singular name when left empty.', 'octave-addons' ); ?></small></label>
					</div>
				</fieldset>

				<?php $this->render_assignment_group( 'custom_taxonomies', $index, $assigned, $post_types, false, $primary_post_type ); ?>
			</div>
		</article>

		<?php

	}

	/*
	RENDER FIELD EDITOR
	-- Displays reusable and post-type-specific field definitions inline.
	---------------------------------------------------------- */

	protected function render_field_editor( array $fields, array $post_types, string $primary_post_type = '', bool $single = false, bool $start_new = false ): void {

		$context_label     = $post_types[ $primary_post_type ] ?? '';
		$field_types       = $this->field_types();
		$reusable_template = [
			'enabled'         => true,
			'label'           => '',
			'name'            => 'field_name',
			'type'            => 'text',
			'default_value'   => '',
			'choices'         => '',
			'description'     => '',
			'required'        => false,
			'scope'           => 'reusable',
			'owner_post_type' => '',
			'post_types'      => '' !== $primary_post_type ? [ $primary_post_type ] : [],
			'sub_fields'      => [],
		];
		$specific_template = array_merge(
			$reusable_template,
			[
				'scope'           => 'specific',
				'owner_post_type' => $primary_post_type,
				'post_types'      => '' !== $primary_post_type ? [ $primary_post_type ] : [],
			]
		);
		$new_field_index   = null;
		$section_id        = '' === $primary_post_type ? 'oa-reusable-content-fields' : 'oa-' . $primary_post_type . '-fields';

		if ( $start_new ) {

			$new_field_index = $single ? 0 : count( $fields );
			$new_field       = '' !== $primary_post_type ? $specific_template : $reusable_template;
			$fields          = $single ? [ $new_field ] : array_merge( $fields, [ $new_field ] );

		}

		?>

		<div id="<?= esc_attr( $section_id ); ?>" class="oa-cpt-section oa-custom-posts-box oa-collection<?= $single ? ' oa-single-definition' : ''; ?>" data-collection="custom_fields" data-new-label="<?php esc_attr_e( 'New post field', 'octave-addons' ); ?>">
			<div class="oa-cpt-section-head">
				<div>
					<span class="oa-panel-kicker"><?php esc_html_e( 'Post Types', 'octave-addons' ); ?></span>
					<h3><?= '' !== $context_label ? sprintf( esc_html__( 'Fields for %s', 'octave-addons' ), esc_html( $context_label ) ) : esc_html__( 'Reusable content fields', 'octave-addons' ); ?></h3>
					<p><?= '' !== $context_label ? esc_html__( 'Create, group, and drag fields into the order editors should complete them. Reusable fields can be assigned here too.', 'octave-addons' ) : esc_html__( 'Create fields once, drag them into order, then assign them to Posts, Pages, or custom post types.', 'octave-addons' ); ?></p>
				</div>
				<div class="oa-cpt-section-actions<?= $single ? ' oa-hidden' : ''; ?>">

					<?php

					if ( '' !== $primary_post_type ) :

					?>

					<button type="button" class="button oa-cpt-add oa-collection-add" data-field-scope="specific"><span class="oa-cpt-add-icon" aria-hidden="true">+</span><?= sprintf( esc_html__( 'Add field for %s', 'octave-addons' ), esc_html( $context_label ) ); ?></button>

					<?php

					endif;

					?>

					<?php

					if ( '' !== $primary_post_type ) :

					?>

					<button type="button" class="button oa-cpt-add oa-reusable-field-finder-toggle" aria-expanded="false" aria-controls="oa-reusable-field-finder-<?= esc_attr( $primary_post_type ); ?>"><span class="oa-cpt-add-icon" aria-hidden="true">+</span><?php esc_html_e( 'Add reusable field', 'octave-addons' ); ?></button>

					<?php

					else :

					?>

					<button type="button" class="button oa-cpt-add oa-collection-add" data-field-scope="reusable"><span class="oa-cpt-add-icon" aria-hidden="true">+</span><?php esc_html_e( 'Add reusable field', 'octave-addons' ); ?></button>

					<?php

					endif;

					?>
				</div>
			</div>

			<div class="oa-field-scope-guide">
				<span><strong><?php esc_html_e( 'Reusable', 'octave-addons' ); ?></strong><span><?php esc_html_e( 'Can be assigned to multiple content types.', 'octave-addons' ); ?></span></span>

				<?php

				if ( '' !== $primary_post_type ) :

				?>

				<span><strong><?php esc_html_e( 'Specific', 'octave-addons' ); ?></strong><span><?= sprintf( esc_html__( 'Belongs only to %s.', 'octave-addons' ), esc_html( $context_label ) ); ?></span></span>

				<?php

				endif;

				?>

			</div>

			<?php

			if ( '' !== $primary_post_type && ! $single ) :

			?>

			<div class="oa-reusable-field-finder oa-hidden" id="oa-reusable-field-finder-<?= esc_attr( $primary_post_type ); ?>">
				<div class="oa-reusable-field-finder-head">
					<div>
						<strong><?php esc_html_e( 'Add from reusable fields', 'octave-addons' ); ?></strong>
						<span><?php esc_html_e( 'Choose a field already defined in Reusable Content Fields.', 'octave-addons' ); ?></span>
					</div>
					<button type="button" class="oa-reusable-field-finder-close" aria-label="<?php esc_attr_e( 'Close reusable field finder', 'octave-addons' ); ?>">&times;</button>
				</div>
				<label class="oa-reusable-field-search">
					<span class="screen-reader-text"><?php esc_html_e( 'Search reusable fields', 'octave-addons' ); ?></span>
					<input type="search" placeholder="<?php esc_attr_e( 'Search reusable fields…', 'octave-addons' ); ?>" autocomplete="off">
				</label>
				<div class="oa-reusable-field-options">

					<?php

					foreach ( $this->reusable_fields( $fields ) as $reusable_field ) :

						$field_name  = (string) $reusable_field['name'];
						$field_label = (string) $reusable_field['label'];
						$type_label  = $field_types[ $reusable_field['type'] ] ?? __( 'Field', 'octave-addons' );
						$is_used     = in_array( $primary_post_type, $reusable_field['post_types'] ?? [], true );

					?>

					<button type="button" class="oa-reusable-field-option<?= $is_used ? ' is-used' : ''; ?>" data-field-target="oa-field-<?= esc_attr( $field_name ); ?>" data-search="<?= esc_attr( strtolower( $field_label . ' ' . $field_name . ' ' . $type_label ) ); ?>">
						<span><strong><?= esc_html( $field_label ); ?></strong><code><?= esc_html( $field_name ); ?></code></span>
						<span><?= esc_html( $type_label ); ?></span>
						<span><?php esc_html_e( 'Add', 'octave-addons' ); ?></span>
					</button>

					<?php

					endforeach;

					?>

				</div>
				<p class="oa-reusable-field-empty oa-hidden"><?php esc_html_e( 'No reusable fields are available to add. Existing fields may already be used here, or a new definition needs to be created in Reusable Content Fields.', 'octave-addons' ); ?></p>
			</div>

			<?php

			endif;

			?>

			<div class="oa-cpt-list oa-collection-list" data-empty-text="<?php esc_attr_e( 'No content fields have been added.', 'octave-addons' ); ?>">

				<?php

				foreach ( $fields as $index => $field ) {

					$this->render_field_card( (string) $index, $field, $post_types, $index !== $new_field_index, $primary_post_type );

				}

				?>

			</div>

			<?php

			if ( '' === $primary_post_type ) :

			?>

			<template class="oa-collection-template" data-field-scope="reusable"><?php $this->render_field_card( '__INDEX__', $reusable_template, $post_types, false, $primary_post_type ); ?></template>

			<?php

			endif;

			?>

			<?php

			if ( '' !== $primary_post_type ) :

			?>

			<template class="oa-collection-template" data-field-scope="specific"><?php $this->render_field_card( '__INDEX__', $specific_template, $post_types, false, $primary_post_type ); ?></template>

			<?php

			endif;

			?>

		</div>

		<?php

	}

	/*
	RENDER FIELD CARD
	-- Outputs one typed custom field definition.
	---------------------------------------------------------- */

	protected function render_field_card( string $index, array $field, array $post_types, bool $saved, string $primary_post_type = '' ): void {

		$label          = (string) ( $field['label'] ?? '' );
		$name           = (string) ( $field['name'] ?? '' );
		$type           = (string) ( $field['type'] ?? 'text' );
		$scope          = 'specific' === ( $field['scope'] ?? 'reusable' ) ? 'specific' : 'reusable';
		$owner          = 'specific' === $scope ? (string) ( $field['owner_post_type'] ?? $primary_post_type ) : '';
		$owner_label    = $post_types[ $owner ] ?? $owner;
		$assigned       = is_array( $field['post_types'] ?? null ) ? $field['post_types'] : [];
		$types          = $this->field_types();
		$is_container   = in_array( $type, [ 'group', 'repeater' ], true );
		$is_html        = 'html' === $type;
		$is_tab         = 'tab' === $type;
		$hides_default  = $is_container || $is_tab || 'gallery' === $type;
		$hides_required = $is_html || $is_tab;
		$scope_label    = 'specific' === $scope
			? sprintf( __( 'Specific to %s', 'octave-addons' ), $owner_label )
			: __( 'Reusable', 'octave-addons' );

		if ( 'reusable' === $scope && '' !== $primary_post_type ) {

			$scope_label = in_array( $primary_post_type, $assigned, true )
				? __( 'Reusable · Used here', 'octave-addons' )
				: __( 'Reusable · Available', 'octave-addons' );

		}

		?>

		<article class="oa-cpt-item oa-collection-item oa-field-scope--<?= esc_attr( $scope ); ?>"<?= '' !== $name ? ' id="oa-field-' . esc_attr( $name ) . '"' : ''; ?> data-saved="<?= $saved ? 'true' : 'false'; ?>">
			<input type="hidden" data-field-scope-value name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'scope' ) ); ?>" value="<?= esc_attr( $scope ); ?>">
			<input type="hidden" data-field-owner-value name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'owner_post_type' ) ); ?>" value="<?= esc_attr( $owner ); ?>">
			<div class="oa-cpt-item-head">
				<button type="button" class="oa-field-drag-handle" aria-label="<?php esc_attr_e( 'Drag to reorder this field, or use the arrow keys', 'octave-addons' ); ?>"><span class="dashicons dashicons-menu" aria-hidden="true"></span></button>
				<button type="button" class="oa-cpt-expand oa-collection-expand" aria-expanded="<?= $saved ? 'false' : 'true'; ?>"><span class="oa-cpt-expand-copy"><strong class="oa-cpt-item-title"><?= esc_html( '' !== $label ? $label : __( 'New post field', 'octave-addons' ) ); ?></strong><span class="oa-cpt-key-preview"><?= esc_html( $name ); ?></span><span class="oa-field-scope-badge" data-used-label="<?php esc_attr_e( 'Reusable · Used here', 'octave-addons' ); ?>" data-available-label="<?php esc_attr_e( 'Reusable · Available', 'octave-addons' ); ?>"><?= esc_html( $scope_label ); ?></span></span><span class="dashicons dashicons-arrow-down-alt2 oa-cpt-expand-icon" aria-hidden="true"></span></button>
				<div class="oa-cpt-enabled-summary"><span><?php esc_html_e( 'Enabled', 'octave-addons' ); ?></span><label class="oa-switch"><input type="checkbox" data-role="enabled" name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'enabled' ) ); ?>" value="1"<?= checked( ! empty( $field['enabled'] ), true, false ); ?>><span class="oa-switch-slider"></span></label></div>
				<button type="button" class="oa-cpt-remove oa-collection-remove" aria-label="<?php esc_attr_e( 'Remove this post field', 'octave-addons' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
			</div>

			<div class="oa-cpt-groups oa-collection-body<?= $saved ? ' oa-hidden' : ''; ?>">
				<fieldset class="oa-cpt-group">
					<legend><?php esc_html_e( 'Field settings', 'octave-addons' ); ?></legend>
					<p class="oa-cpt-group-description"><?php esc_html_e( 'The field name becomes the permanent post-meta key after saving. Values saved under the older _octave_ prefixed key are still read.', 'octave-addons' ); ?></p>
					<div class="oa-cpt-fields">
						<label class="oa-cpt-field"><span><?php esc_html_e( 'Label', 'octave-addons' ); ?></span><input type="text" data-role="title" name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'label' ) ); ?>" value="<?= esc_attr( $label ); ?>" placeholder="Client name" required></label>
						<label class="oa-cpt-field"><span><?php esc_html_e( 'Field name', 'octave-addons' ); ?></span><input type="text" data-role="key" name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'name' ) ); ?>" value="<?= esc_attr( $name ); ?>" maxlength="40" pattern="[a-z0-9_]+" required<?= $saved ? ' readonly' : ''; ?>></label>
						<label class="oa-cpt-field"><span><?php esc_html_e( 'Field type', 'octave-addons' ); ?></span><select data-field-type name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'type' ) ); ?>"><?php foreach ( $types as $type_key => $type_label ) : ?><option value="<?= esc_attr( $type_key ); ?>"<?= selected( $type, $type_key, false ); ?>><?= esc_html( $type_label ); ?></option><?php endforeach; ?></select></label>
						<label class="oa-cpt-field oa-cpt-field--full oa-field-default<?= $hides_default ? ' oa-hidden' : ''; ?>" data-default-label="<?php esc_attr_e( 'Default value', 'octave-addons' ); ?>" data-html-label="<?php esc_attr_e( 'HTML content', 'octave-addons' ); ?>" data-default-help="<?php esc_attr_e( 'Shown until a post has its own saved value.', 'octave-addons' ); ?>" data-html-help="<?php esc_attr_e( 'Presentation-only markup shown between fields. It is sanitised and never saved as post meta.', 'octave-addons' ); ?>"><span><?= $is_html ? esc_html__( 'HTML content', 'octave-addons' ) : esc_html__( 'Default value', 'octave-addons' ); ?></span><textarea data-field-default-control name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'default_value' ) ); ?>" rows="<?= $is_html ? '6' : '2'; ?>"><?= esc_textarea( is_scalar( $field['default_value'] ?? '' ) ? (string) $field['default_value'] : '' ); ?></textarea><small><?= $is_html ? esc_html__( 'Presentation-only markup shown between fields. It is sanitised and never saved as post meta.', 'octave-addons' ) : esc_html__( 'Shown until a post has its own saved value.', 'octave-addons' ); ?></small></label>
						<label class="oa-cpt-field oa-cpt-field--full oa-field-choices<?= in_array( $type, [ 'select', 'multiselect', 'radio' ], true ) ? '' : ' oa-hidden'; ?>"><span><?php esc_html_e( 'Choices', 'octave-addons' ); ?></span><textarea name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'choices' ) ); ?>" rows="5" placeholder="featured : Featured&#10;standard : Standard"><?= esc_textarea( (string) ( $field['choices'] ?? '' ) ); ?></textarea><small><?php esc_html_e( 'One per line. Use value : Label or a simple value.', 'octave-addons' ); ?></small></label>
						<label class="oa-cpt-field oa-cpt-field--full"><span><?php esc_html_e( 'Instructions for editors', 'octave-addons' ); ?></span><textarea name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'description' ) ); ?>" rows="3"><?= esc_textarea( (string) ( $field['description'] ?? '' ) ); ?></textarea></label>
						<div class="oa-cpt-field oa-cpt-switch-field oa-field-required<?= $hides_required ? ' oa-hidden' : ''; ?>"><span><?php esc_html_e( 'Required', 'octave-addons' ); ?></span><label class="oa-switch"><input type="checkbox" name="<?= esc_attr( $this->collection_field_name( 'custom_fields', $index, 'required' ) ); ?>" value="1"<?= checked( ! empty( $field['required'] ), true, false ); ?>><span class="oa-switch-slider"></span></label><small><?php esc_html_e( 'Prompts editors to complete the field in the post screen.', 'octave-addons' ); ?></small></div>
					</div>
				</fieldset>

				<?php $this->render_sub_field_editor( $index, $field['sub_fields'] ?? [], $is_container ); ?>

				<?php

				if ( 'reusable' === $scope ) {

					$this->render_assignment_group( 'custom_fields', $index, $assigned, $post_types, false, $primary_post_type, false );

				}

				?>
			</div>
		</article>

		<?php

	}

	/*
	RENDER ASSIGNMENT GROUP
	-- Provides clear checkbox assignments for taxonomies and fields.
	---------------------------------------------------------- */

	protected function render_assignment_group( string $collection, string $index, array $assigned, array $post_types, bool $custom_only = false, string $primary_post_type = '', bool $lock_primary = true ): void {

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
					<input type="checkbox" name="<?= esc_attr( $this->collection_field_name( $collection, $index, 'post_types' ) ); ?>[]" value="<?= esc_attr( $post_type ); ?>"<?= checked( in_array( $post_type, $assigned, true ), true, false ); ?><?= $is_primary ? ' data-context-assignment="true"' : ''; ?><?= $is_primary && $lock_primary ? ' data-primary-assignment="true"' : ''; ?>>
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
			<div class="oa-existing-field-picker oa-hidden">
				<label><span><?php esc_html_e( 'Move an existing field into this group', 'octave-addons' ); ?></span><select data-existing-field><option value=""><?php esc_html_e( 'Choose a field', 'octave-addons' ); ?></option></select></label>
				<button type="button" class="button oa-existing-field-add" data-confirm-title="<?php esc_attr_e( 'Move field into this group?', 'octave-addons' ); ?>" data-confirm-message="<?php esc_attr_e( 'Future values will be stored inside the group. Existing values saved under the field’s current standalone meta key will remain in the database but will not appear inside the group automatically.', 'octave-addons' ); ?>" data-confirm-action="<?php esc_attr_e( 'Move field', 'octave-addons' ); ?>"><?php esc_html_e( 'Move into group', 'octave-addons' ); ?></button>
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
				<button type="button" class="oa-sub-field-drag-handle" aria-label="<?php esc_attr_e( 'Drag to reorder this item field, or use the arrow keys', 'octave-addons' ); ?>"><span class="dashicons dashicons-menu" aria-hidden="true"></span></button>
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
					<label class="oa-cpt-field oa-sub-field-default<?= 'gallery' === $type ? ' oa-hidden' : ''; ?>"><span><?php esc_html_e( 'Default value', 'octave-addons' ); ?></span><input type="text" name="<?= esc_attr( $this->sub_field_name( $field_index, $sub_index, 'default_value' ) ); ?>" value="<?= esc_attr( is_scalar( $field['default_value'] ?? '' ) ? (string) $field['default_value'] : '' ); ?>"></label>
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
	-- Returns the stable Post Types settings URL.
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
	REUSABLE FIELDS
	-- Returns global fields, including definitions created before field scopes
	-- were introduced.
	---------------------------------------------------------- */

	protected function reusable_fields( array $fields ): array {

		return array_values(
			array_filter(
				$fields,
				static function ( array $field ): bool {

					return 'specific' !== ( $field['scope'] ?? 'reusable' );

				}
			)
		);

	}

	/*
	FIELDS FOR POST TYPE EDITOR
	-- Combines fields owned by the current post type with every reusable field
	-- so both can be edited without leaving the page.
	---------------------------------------------------------- */

	protected function fields_for_post_type_editor( array $fields, string $post_type ): array {

		return array_values(
			array_filter(
				$fields,
				static function ( array $field ) use ( $post_type ): bool {

					return 'specific' !== ( $field['scope'] ?? 'reusable' )
						|| $post_type === ( $field['owner_post_type'] ?? '' );

				}
			)
		);

	}

	/*
	SPECIFIC FIELDS OUTSIDE POST TYPE
	-- Preserves post-type-owned fields that are intentionally off screen.
	---------------------------------------------------------- */

	protected function specific_fields_outside_post_type( array $fields, string $post_type ): array {

		return array_values(
			array_filter(
				$fields,
				static function ( array $field ) use ( $post_type ): bool {

					return 'specific' === ( $field['scope'] ?? 'reusable' )
						&& $post_type !== ( $field['owner_post_type'] ?? '' );

				}
			)
		);

	}

	/*
	SHOULD START NEW LIBRARY FIELD
	-- Lets overview links open a fresh reusable field directly on the library.
	---------------------------------------------------------- */

	protected function should_start_new_library_field(): bool {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress adds this after a successful Settings API save.
		if ( ! empty( $_GET['settings-updated'] ) ) {

			return false;

		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only settings navigation.
		$add = isset( $_GET['add'] ) ? sanitize_key( wp_unslash( (string) $_GET['add'] ) ) : '';

		return 'field' === $add;

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

		$post_types         = $this->normalise_post_types( $settings['custom_post_types'] ?? [] );
		$runtime_post_types = [];

		foreach ( $post_types as $index => $post_type ) {

			if ( empty( $post_type['enabled'] ) ) {

				continue;

			}

			if ( $this->register_custom_post_type( $post_type, $this->menu_position_for_index( (int) $index ) ) ) {

				$runtime_post_types[] = $post_type;

			}

		}

		foreach ( $this->normalise_taxonomies( $settings['custom_taxonomies'] ?? [], $post_types ) as $taxonomy ) {

			if ( ! empty( $taxonomy['enabled'] ) ) {

				$this->register_custom_taxonomy( $taxonomy, $post_types );
				$this->admin_taxonomies[ $taxonomy['taxonomy'] ] = $taxonomy;

			}

		}

		if ( is_admin() && ! empty( $this->admin_taxonomies ) ) {

			add_action( 'restrict_manage_posts', [ $this, 'render_custom_taxonomy_filters' ], 10, 2 );
			add_action( 'pre_get_posts', [ $this, 'prepare_taxonomy_admin_query' ] );
			add_filter( 'posts_clauses', [ $this, 'order_posts_by_taxonomy' ], 10, 2 );

			$admin_post_types = [];

			foreach ( $this->admin_taxonomies as $taxonomy ) {

				foreach ( $taxonomy['post_types'] as $post_type ) {

					$admin_post_types[ $post_type ] = true;

				}

			}

			foreach ( array_keys( $admin_post_types ) as $post_type ) {

				add_filter( "manage_edit-{$post_type}_sortable_columns", [ $this, 'make_taxonomy_columns_sortable' ] );

			}

		}

		$fields = $this->normalise_fields( $settings['custom_fields'] ?? [], $post_types );

		new Octave_Addons_Custom_Post_Fields( $fields, $this->post_type_options( $post_types ), $runtime_post_types );

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
	RENDER CUSTOM TAXONOMY FILTERS
	-- Adds enabled taxonomy dropdowns to the top of assigned post type tables.
	---------------------------------------------------------- */

	public function render_custom_taxonomy_filters( $post_type, $which = 'top' ): void {

		if ( 'top' !== $which ) {

			return;

		}

		foreach ( $this->admin_taxonomies as $taxonomy => $definition ) {

			if ( empty( $definition['show_admin_filter'] ) || ! in_array( $post_type, $definition['post_types'], true ) ) {

				continue;

			}

			?>

			<label class="screen-reader-text" for="<?= esc_attr( $taxonomy ); ?>">
				<?= esc_html( sprintf( __( 'Filter by %s', 'octave-addons' ), $definition['singular_name'] ) ); ?>
			</label>

			<?php

			wp_dropdown_categories(
				[
					'show_option_all' => sprintf( __( 'All %s', 'octave-addons' ), $definition['name'] ),
					'taxonomy'        => $taxonomy,
					'name'            => $taxonomy,
					'id'              => $taxonomy,
					'value_field'     => 'slug',
					'selected'        => $this->get_current_taxonomy_filter( $taxonomy ),
					'hierarchical'    => ! empty( $definition['hierarchical'] ),
					'depth'           => ! empty( $definition['hierarchical'] ) ? 3 : 0,
					'orderby'         => 'name',
					'show_count'      => true,
					'hide_empty'      => false,
					'hide_if_empty'   => false,
				]
			);

		}

	}

	/*
	MAKE TAXONOMY COLUMNS SORTABLE
	-- Maps each visible managed taxonomy column to its admin query order key.
	---------------------------------------------------------- */

	public function make_taxonomy_columns_sortable( array $columns ): array {

		foreach ( $this->admin_taxonomies as $taxonomy => $definition ) {

			if ( ! empty( $definition['show_admin_column'] ) ) {

				$columns[ 'taxonomy-' . $taxonomy ] = [
					'oa_taxonomy__' . $taxonomy,
					false,
					$definition['name'],
					sprintf( __( 'Table ordered by %s.', 'octave-addons' ), $definition['name'] ),
				];

			}

		}

		return $columns;

	}

	/*
	PREPARE TAXONOMY ADMIN QUERY
	-- Applies selected toolbar terms and records sortable taxonomy requests.
	---------------------------------------------------------- */

	public function prepare_taxonomy_admin_query( $query ): void {

		if ( ! $query->is_main_query() ) {

			return;

		}

		$post_type     = sanitize_key( (string) $query->get( 'post_type' ) );
		$raw_tax_query = $query->get( 'tax_query' );
		$tax_query     = is_array( $raw_tax_query ) ? $raw_tax_query : [];

		if ( '' === $post_type ) {

			global $typenow;

			$post_type = sanitize_key( (string) $typenow );

		}

		foreach ( $this->admin_taxonomies as $taxonomy => $definition ) {

			if ( empty( $definition['show_admin_filter'] ) || ! in_array( $post_type, $definition['post_types'], true ) ) {

				continue;

			}

			$term = $this->get_current_taxonomy_filter( $taxonomy );

			if ( '' !== $term ) {

				$tax_query[] = [
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $term,
				];

			}

		}

		if ( ! empty( $tax_query ) ) {

			$query->set( 'tax_query', $tax_query );

		}

		$orderby = (string) $query->get( 'orderby' );
		$prefix  = 'oa_taxonomy__';

		if ( 0 !== strpos( $orderby, $prefix ) ) {

			return;

		}

		$taxonomy = sanitize_key( substr( $orderby, strlen( $prefix ) ) );

		if (
			isset( $this->admin_taxonomies[ $taxonomy ] )
			&& ! empty( $this->admin_taxonomies[ $taxonomy ]['show_admin_column'] )
			&& in_array( $post_type, $this->admin_taxonomies[ $taxonomy ]['post_types'], true )
		) {

			$query->set( 'oa_orderby_taxonomy', $taxonomy );

		}

	}

	/*
	ORDER POSTS BY TAXONOMY
	-- Sorts the main admin list query by the first alphabetical assigned term.
	---------------------------------------------------------- */

	public function order_posts_by_taxonomy( array $clauses, $query ): array {

		$taxonomy = sanitize_key( (string) $query->get( 'oa_orderby_taxonomy' ) );

		if ( '' === $taxonomy || ! isset( $this->admin_taxonomies[ $taxonomy ] ) ) {

			return $clauses;

		}

		global $wpdb;

		$suffix              = substr( md5( $taxonomy ), 0, 8 );
		$relationships_alias = 'oa_tr_' . $suffix;
		$taxonomy_alias      = 'oa_tt_' . $suffix;
		$terms_alias         = 'oa_t_' . $suffix;
		$order               = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';

		$clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} AS {$relationships_alias} ON ({$wpdb->posts}.ID = {$relationships_alias}.object_id)";
		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->term_taxonomy} AS {$taxonomy_alias} ON ({$relationships_alias}.term_taxonomy_id = {$taxonomy_alias}.term_taxonomy_id AND {$taxonomy_alias}.taxonomy = %s)",
			$taxonomy
		);
		$clauses['join'] .= " LEFT JOIN {$wpdb->terms} AS {$terms_alias} ON ({$taxonomy_alias}.term_id = {$terms_alias}.term_id)";

		$clauses['groupby'] = '' === $clauses['groupby']
			? "{$wpdb->posts}.ID"
			: $clauses['groupby'] . ", {$wpdb->posts}.ID";
		$clauses['orderby'] = "MIN({$terms_alias}.name) {$order}, {$wpdb->posts}.post_title ASC";

		return $clauses;

	}

	/*
	GET CURRENT TAXONOMY FILTER
	-- Returns a safe term slug from a read-only admin list request.
	---------------------------------------------------------- */

	protected function get_current_taxonomy_filter( string $taxonomy ): string {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list table filter.
		if ( empty( $_GET[ $taxonomy ] ) ) {

			return '';

		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list table filter.
		return sanitize_title( wp_unslash( (string) $_GET[ $taxonomy ] ) );

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

	protected function register_custom_post_type( array $post_type, int $menu_position ): bool {

		$key          = $post_type['post_type'];
		$name         = $post_type['name'];
		$singular     = $post_type['singular_name'];
		$post_slug    = $post_type['post_slug'];
		$archive_slug = $post_type['archive_slug'];
		$is_public    = ! empty( $post_type['public'] );
		$is_queryable = ! array_key_exists( 'publicly_queryable', $post_type )
			? $is_public
			: ! empty( $post_type['publicly_queryable'] );
		$supports     = [
			'title',
			'editor',
			'author',
			'thumbnail',
			'excerpt',
			'revisions',
			'custom-fields',
		];

		if ( post_type_exists( $key ) ) {

			return false;

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

		$registration_args = [
			'labels'              => $labels,
			'public'              => $is_public,
			'hierarchical'        => false,
			'exclude_from_search' => ! $is_queryable,
			'publicly_queryable'  => $is_queryable,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => $is_public && $is_queryable,
			'show_in_rest'        => true,
			'menu_position'       => $menu_position,
			'menu_icon'           => $post_type['menu_icon'],
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'query_var'           => $is_queryable,
			'rewrite'             => $is_queryable
				? [
					'slug'       => $post_slug,
					'with_front' => false,
				]
				: false,
			'has_archive'         => $is_queryable && ! empty( $post_type['has_archive'] ) ? $archive_slug : false,
			'supports'            => $supports,
		];

		if ( empty( $post_type['content_editor'] ) ) {

			$registration_args['template']      = [ [ 'octave/block-octave-launcher' ] ];
			$registration_args['template_lock'] = 'all';

		}

		$registered = register_post_type( $key, $registration_args );

		return ! is_wp_error( $registered );

	}

	/*
	TAXONOMY MENU LABEL
	-- Names the submenu entry under each post type. A taxonomy is normally named
	-- after the type that owns it, so that leading post type name is dropped and
	-- only the distinct part is left on the menu.
	---------------------------------------------------------- */

	protected function taxonomy_menu_label( array $definition, array $post_types ): string {

		$label    = trim( (string) $definition['name'] );
		$assigned = is_array( $definition['post_types'] ?? null ) ? $definition['post_types'] : [];
		$owners   = [];

		foreach ( $post_types as $post_type ) {

			if ( ! in_array( $post_type['post_type'], $assigned, true ) ) {

				continue;

			}

			$owners[] = trim( (string) $post_type['name'] );
			$owners[] = trim( (string) $post_type['singular_name'] );

		}

		// Longest first, so a plural name is preferred over the singular inside it.
		usort(
			$owners,
			static function ( string $a, string $b ): int {

				return strlen( $b ) <=> strlen( $a );

			}
		);

		foreach ( $owners as $owner ) {

			if ( '' === $owner || 0 !== stripos( $label, $owner . ' ' ) ) {

				continue;

			}

			$trimmed = trim( substr( $label, strlen( $owner ) ) );

			if ( '' !== $trimmed ) {

				return $trimmed;

			}

		}

		return '' !== $label ? $label : __( 'Categories', 'octave-addons' );

	}

	/*
	REGISTER CUSTOM TAXONOMY
	-- Registers one reusable taxonomy against all selected post types.
	---------------------------------------------------------- */

	protected function register_custom_taxonomy( array $definition, array $post_types = [] ): void {

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
			'menu_name'         => $this->taxonomy_menu_label( $definition, $post_types ),
		];

		register_taxonomy(
			$taxonomy,
			$definition['post_types'],
			[
				'labels'             => $labels,
				'description'        => sprintf( __( 'Octave-managed %s.', 'octave-addons' ), $name ),
				'public'             => ! empty( $definition['public'] ),
				'hierarchical'       => ! empty( $definition['hierarchical'] ),
				'show_ui'            => true,
				'show_admin_column'  => ! empty( $definition['show_admin_column'] ),
				'show_in_quick_edit' => true,
				'show_in_nav_menus'  => ! empty( $definition['public'] ),
				'show_tagcloud'      => false,
				'show_in_rest'       => true,
				'query_var'          => ! empty( $definition['public'] ),
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

			$taxonomy                = substr( $key . '_category', 0, 32 );
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
				'publicly_queryable'     => ! array_key_exists( 'publicly_queryable', $post_type )
					? ! empty( $post_type['public'] )
					: ! empty( $post_type['publicly_queryable'] ),
				'content_editor'         => ! array_key_exists( 'content_editor', $post_type ) || ! empty( $post_type['content_editor'] ),
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

			$key = 'oa_' . ltrim( preg_replace( '/^oa_+/', '', $key ), '_' );

			if ( 'oa_' === $key ) {

				$key = 'oa_category_' . ( $index + 1 );

			}

			if ( '' === $name || '' === $singular || isset( $used[ $key ] ) ) {

				continue;

			}

			$assigned = isset( $taxonomy['post_types'] ) && is_array( $taxonomy['post_types'] )
				? array_values( array_intersect( array_map( 'sanitize_key', $taxonomy['post_types'] ), $available ) )
				: [];
			$order    = [];

			if ( isset( $taxonomy['post_type_order'] ) && is_array( $taxonomy['post_type_order'] ) ) {

				foreach ( $taxonomy['post_type_order'] as $order_post_type => $position ) {

					$order_post_type = sanitize_key( wp_unslash( (string) $order_post_type ) );

					if ( in_array( $order_post_type, $available, true ) ) {

						$order[ $order_post_type ] = min( 29, max( 0, absint( $position ) ) );

					}

				}

			}

			$used[ $key ] = true;
			$clean[]      = [
				'enabled'           => ! empty( $taxonomy['enabled'] ),
				'name'              => $name,
				'singular_name'     => $singular,
				'taxonomy'          => $key,
				'slug'              => self::sanitize_rewrite_path( $taxonomy['slug'] ?? '', sanitize_title( $singular ) ),
				'hierarchical'      => ! empty( $taxonomy['hierarchical'] ),
				'public'            => ! empty( $taxonomy['public'] ),
				'show_admin_column' => ! array_key_exists( 'show_admin_column', $taxonomy ) || ! empty( $taxonomy['show_admin_column'] ),
				'show_admin_filter' => ! empty( $taxonomy['show_admin_filter'] ),
				'post_types'        => $assigned,
				'post_type_order'   => $order,
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

		$clean              = [];
		$used               = [];
		$available          = array_keys( $this->post_type_options( $post_types ) );
		$specific_available = array_column( $post_types, 'post_type' );
		$types              = array_keys( $this->field_types() );

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

			$type  = in_array( $type, $types, true ) ? $type : 'text';
			$scope = 'specific' === sanitize_key( $field['scope'] ?? 'reusable' ) ? 'specific' : 'reusable';
			$owner = sanitize_key( $field['owner_post_type'] ?? '' );

			if ( 'specific' === $scope && ! in_array( $owner, $specific_available, true ) ) {

				$scope = 'reusable';
				$owner = '';

			}

			$assigned = isset( $field['post_types'] ) && is_array( $field['post_types'] )
				? array_values( array_intersect( array_map( 'sanitize_key', $field['post_types'] ), $available ) )
				: [];

			if ( 'specific' === $scope ) {

				$assigned = [ $owner ];

			}

			$used[ $name ] = true;
			$is_container  = in_array( $type, [ 'group', 'repeater' ], true );
			$is_list       = $is_container || 'gallery' === $type;
			$clean[]       = [
				'enabled'         => ! empty( $field['enabled'] ),
				'label'           => $label,
				'name'            => $name,
				'meta_key'        => $name,
				'legacy_meta_key' => '_octave_' . $name,
				'type'            => $type,
				'default_value'   => $is_list
					? []
					: ( in_array( $type, [ 'wysiwyg', 'html' ], true )
					? wp_kses_post( wp_unslash( (string) ( $field['default_value'] ?? '' ) ) )
					: sanitize_text_field( wp_unslash( (string) ( $field['default_value'] ?? '' ) ) ) ),
				'choices'         => sanitize_textarea_field( wp_unslash( (string) ( $field['choices'] ?? '' ) ) ),
				'description'     => sanitize_textarea_field( wp_unslash( (string) ( $field['description'] ?? '' ) ) ),
				'required'        => ! in_array( $type, [ 'html', 'tab' ], true ) && ! empty( $field['required'] ),
				'scope'           => $scope,
				'owner_post_type' => $owner,
				'post_types'      => $assigned,
				'sub_fields'      => $is_container ? $this->normalise_sub_fields( $field['sub_fields'] ?? [] ) : [],
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
				'default_value' => 'gallery' === $type
					? []
					: ( 'wysiwyg' === $type
					? wp_kses_post( wp_unslash( (string) ( $field['default_value'] ?? '' ) ) )
					: sanitize_text_field( wp_unslash( (string) ( $field['default_value'] ?? '' ) ) ) ),
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
				'enabled'           => true,
				'name'              => $post_type['taxonomy_name'],
				'singular_name'     => $post_type['taxonomy_singular_name'],
				'taxonomy'          => $post_type['taxonomy'],
				'slug'              => $post_type['taxonomy_slug'],
				'hierarchical'      => true,
				'public'            => ! empty( $post_type['public'] ),
				'show_admin_column' => true,
				'show_admin_filter' => false,
				'post_types'        => [ $post_type['post_type'] ],
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
			'html'        => __( 'HTML / section heading', 'octave-addons' ),
			'tab'         => __( 'Tab', 'octave-addons' ),
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
			'gallery'     => __( 'Gallery', 'octave-addons' ),
			'file'        => __( 'File', 'octave-addons' ),
		];

	}

	/*
	DASHICONS
	-- Provides every icon shipped by the installed WordPress version, with the
	-- most useful admin menu choices kept first.
	---------------------------------------------------------- */

	protected function dashicons(): array {

		if ( null !== self::$dashicons ) {

			return self::$dashicons;

		}

		$dashicons = [
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
		$stylesheet = ABSPATH . WPINC . '/css/dashicons.css';
		$css        = is_readable( $stylesheet ) ? file_get_contents( $stylesheet ) : '';

		if ( is_string( $css ) && preg_match_all( '/\.(dashicons-[a-z0-9-]+):before/', $css, $matches ) ) {

			$icon_classes = array_values( array_unique( $matches[1] ) );
			sort( $icon_classes, SORT_NATURAL );

			foreach ( $icon_classes as $icon_class ) {

				if ( isset( $dashicons[ $icon_class ] ) ) {

					continue;

				}

				$label = str_replace( 'dashicons-', '', $icon_class );
				$label = ucwords( str_replace( '-', ' ', $label ) );

				$dashicons[ $icon_class ] = $label;

			}

		}

		self::$dashicons = $dashicons;

		return self::$dashicons;

	}

	/*
	SUB FIELD TYPES
	-- Allows every standard control while preventing deeply nested containers.
	---------------------------------------------------------- */

	protected function sub_field_types(): array {

		return array_diff_key( $this->field_types(), array_flip( [ 'group', 'repeater', 'html', 'tab' ] ) );

	}

	/*
	SANITIZE POST TYPE KEY
	-- Prefixes custom post type keys with oa_.
	---------------------------------------------------------- */

	protected function sanitize_post_type_key( $value, int $index ): string {

		$key = substr( sanitize_key( wp_unslash( (string) $value ) ), 0, 20 );

		$key = preg_replace( '/^oa_+/', '', $key );
		$key = 'oa_' . trim( (string) $key, '_' );

		if ( 'oa_' === $key ) {

			$key = 'oa_content_' . ( $index + 1 );

		}

		return substr( $key, 0, 20 );

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
