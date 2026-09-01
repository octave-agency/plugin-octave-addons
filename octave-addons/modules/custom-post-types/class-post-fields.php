<?php

/*
CUSTOM POST FIELDS RUNTIME
-- Registers Octave field values as post meta, renders the editor meta box,
-- saves typed values, and exposes fields to Breakdance Dynamic Data.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Custom_Post_Fields {

	protected const NONCE_ACTION = 'octave_addons_save_post_fields';
	protected const NONCE_NAME   = 'octave_addons_post_fields_nonce';

	protected array $fields;
	protected array $post_type_labels;
	protected array $structured_post_types;
	protected array $fields_by_meta_key;

	/*
	CONSTRUCTOR
	-- Stores normalised definitions and attaches editor and builder hooks.
	---------------------------------------------------------- */

	public function __construct( array $fields, array $post_type_labels, array $post_types = [] ) {

		$this->fields                = $fields;
		$this->post_type_labels      = $post_type_labels;
		$this->structured_post_types = [];
		$this->fields_by_meta_key    = [];

		foreach ( $fields as $field ) {

			if ( empty( $field['enabled'] ) || self::is_presentational( $field ) ) {

				continue;

			}

			$this->fields_by_meta_key[ (string) $field['meta_key'] ] = $field;

		}

		foreach ( $post_types as $post_type ) {

			$key = (string) ( $post_type['post_type'] ?? '' );

			if ( '' !== $key && ! empty( $post_type['enabled'] ) && empty( $post_type['content_editor'] ) ) {

				$this->structured_post_types[] = $key;

			}

		}

		foreach ( $this->structured_post_types as $post_type ) {

			add_action( 'rest_after_insert_' . $post_type, [ $this, 'clean_structured_meta' ], 10, 3 );
			add_filter(
				'rest_pre_insert_' . $post_type,
				function ( $prepared, $request ) use ( $post_type ) {

					return $this->validate_required_meta( $prepared, $request, $post_type );

				},
				10,
				2
			);

		}

		$this->register_meta();

		add_filter( 'default_post_metadata', [ $this, 'read_legacy_meta' ], 10, 4 );
		add_filter( 'is_protected_meta', [ $this, 'protect_field_meta' ], 10, 3 );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'save_post' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'enqueue_block_assets', [ $this, 'enqueue_structured_content_styles' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_structured_content_launcher' ] );
		add_action( 'current_screen', [ $this, 'add_structured_content_template' ] );
		add_action( 'init', [ $this, 'register_structured_content_block' ], 15 );
		add_action( 'init', [ $this, 'register_breakdance_fields' ], 20 );

	}

	/*
	IS PRESENTATIONAL
	-- Marks the field types that only shape the editing screen. They never
	-- register post meta, never validate, never save, and never reach Dynamic
	-- Data, so every runtime loop skips them through this one check.
	---------------------------------------------------------- */

	protected static function is_presentational( array $field ): bool {

		return in_array( (string) ( $field['type'] ?? '' ), [ 'html', 'tab' ], true );

	}

	/*
	META KEYS
	-- Lists every key a field's value may live under, canonical first. Values
	-- written before the key dropped its _octave_ prefix are still in place on
	-- existing sites, so the legacy key stays readable until the next save
	-- rewrites the value under the canonical one.
	---------------------------------------------------------- */

	protected static function meta_keys( array $field ): array {

		$keys   = [ (string) $field['meta_key'] ];
		$legacy = (string) ( $field['legacy_meta_key'] ?? '' );

		if ( '' !== $legacy && ! in_array( $legacy, $keys, true ) ) {

			$keys[] = $legacy;

		}

		return $keys;

	}

	/*
	STORED META KEY
	-- Names the key this post actually holds a row for, or an empty string when
	-- the field has never been saved. metadata_exists() is deliberate: an empty
	-- string is a legitimate saved value and must not read as unsaved.
	---------------------------------------------------------- */

	protected static function stored_meta_key( int $post_id, array $field ): string {

		if ( ! $post_id ) {

			return '';

		}

		foreach ( self::meta_keys( $field ) as $key ) {

			if ( metadata_exists( 'post', $post_id, $key ) ) {

				return $key;

			}

		}

		return '';

	}

	/*
	FIELD VALUE
	-- Returns the stored value, falling back to the field default only while
	-- nothing at all is stored under either key.
	---------------------------------------------------------- */

	protected static function field_value( int $post_id, array $field ) {

		$key = self::stored_meta_key( $post_id, $field );

		return '' === $key ? $field['default_value'] : get_post_meta( $post_id, $key, true );

	}

	/*
	READ LEGACY META
	-- Serves the legacy value whenever the canonical key holds no row of its
	-- own. WordPress fires this only after the real lookup misses, so one hook
	-- covers get_post_meta(), the REST meta object the block editor binds to,
	-- and anything else reading the field, without touching stored rows.
	---------------------------------------------------------- */

	public function read_legacy_meta( $value, $object_id, $meta_key, $single ) {

		$field = $this->fields_by_meta_key[ (string) $meta_key ] ?? null;

		if ( ! $field ) {

			return $value;

		}

		if ( ! in_array( (string) get_post_type( $object_id ), $field['post_types'], true ) ) {

			return $value;

		}

		$legacy = (string) ( $field['legacy_meta_key'] ?? '' );

		if ( '' === $legacy || $legacy === (string) $meta_key || ! metadata_exists( 'post', $object_id, $legacy ) ) {

			return $value;

		}

		$stored = get_post_meta( $object_id, $legacy, true );

		return $single ? $stored : [ $stored ];

	}

	/*
	PROTECT FIELD META
	-- Field keys are no longer underscore prefixed, so this keeps them out of
	-- the built-in Custom Fields panel. register_post_meta() supplies its own
	-- auth callback for each key, which is what REST and the block editor check
	-- when they write, so protecting the key here costs them nothing.
	---------------------------------------------------------- */

	public function protect_field_meta( $protected, $meta_key, $meta_type ) {

		if ( 'post' !== $meta_type ) {

			return $protected;

		}

		return isset( $this->fields_by_meta_key[ (string) $meta_key ] ) ? true : $protected;

	}

	/*
	REGISTER STRUCTURED CONTENT BLOCK
	-- Provides the Gutenberg-native canvas used when standard content is off.
	-- The canvas stylesheet is declared as the block's editor style, which is
	-- how WordPress collects assets for the block editor iframe. Enqueuing it
	-- any other way either misses the iframe or triggers Gutenberg's
	-- "added to the iframe incorrectly" warning.
	---------------------------------------------------------- */

	public function register_structured_content_block(): void {

		if ( ! function_exists( 'register_block_type' ) ) {

			return;

		}

		wp_register_style(
			'octave-post-fields',
			OCTAVE_ADDONS_URL . 'modules/custom-post-types/assets/post-fields.css',
			[],
			OCTAVE_ADDONS_VERSION
		);
		wp_register_style(
			'octave-structured-content',
			OCTAVE_ADDONS_URL . 'modules/custom-post-types/assets/structured-content.css',
			[],
			OCTAVE_ADDONS_VERSION
		);
		wp_register_script(
			'octave-post-fields',
			OCTAVE_ADDONS_URL . 'modules/custom-post-types/assets/post-fields.js',
			[ 'jquery' ],
			OCTAVE_ADDONS_VERSION,
			true
		);
		wp_register_script(
			'octave-structured-content-launcher',
			OCTAVE_ADDONS_URL . 'modules/custom-post-types/assets/structured-content-launcher.js',
			[ 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-core-data', 'wp-data', 'wp-dom-ready', 'wp-element', 'wp-i18n' ],
			OCTAVE_ADDONS_VERSION,
			false
		);

		register_block_type(
			'octave/block-octave-launcher',
			[
				'api_version'     => 2,
				'editor_style'    => 'octave-structured-content',
				'render_callback' => '__return_empty_string',
			]
		);

	}

	/*
	ENQUEUE STRUCTURED CONTENT STYLES
	-- The block's editor style covers the iframe. This adds the same sheet to
	-- the editor page itself, which the iframe never sees, so the rules that
	-- hide the inserter and appender apply on field-only post types even when
	-- the site loads block assets separately.
	---------------------------------------------------------- */

	public function enqueue_structured_content_styles(): void {

		if ( ! is_admin() ) {

			return;

		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, $this->structured_post_types, true ) ) {

			return;

		}

		wp_enqueue_style( 'octave-structured-content' );

	}

	/*
	ADD STRUCTURED CONTENT TEMPLATE
	-- Matches Breakdance's current-screen template injection so the launcher is
	-- part of Gutenberg content for both new and existing structured-only CPTs.
	---------------------------------------------------------- */

	public function add_structured_content_template(): void {

		global $pagenow;

		if ( ! in_array( $pagenow, [ 'post.php', 'post-new.php' ], true ) ) {

			return;

		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, $this->structured_post_types, true ) ) {

			return;

		}

		$post_type = get_post_type_object( $screen->post_type );

		if ( ! $post_type ) {

			return;

		}

		$post_type->template      = [ [ 'octave/block-octave-launcher' ] ];
		$post_type->template_lock = 'all';

	}

	/*
	ENQUEUE STRUCTURED CONTENT LAUNCHER
	-- Loads the block registration before Gutenberg initializes its content.
	---------------------------------------------------------- */

	public function enqueue_structured_content_launcher(): void {

		global $post;

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, $this->structured_post_types, true ) ) {

			return;

		}

		wp_enqueue_script( 'octave-structured-content-launcher' );

		$post_type   = get_post_type_object( $screen->post_type );
		$singular    = $post_type ? $post_type->labels->singular_name : __( 'post', 'octave-addons' );
		$fields      = $this->fields_for_post_type( $screen->post_type );
		$post_id     = $post instanceof WP_Post ? $post->ID : 0;
		$stored_keys = [];

		foreach ( $fields as $field ) {

			if ( self::is_presentational( $field ) ) {

				continue;

			}

			if ( '' !== self::stored_meta_key( $post_id, $field ) ) {

				$stored_keys[ $field['meta_key'] ] = true;

			}

		}

		wp_localize_script(
			'octave-structured-content-launcher',
			'octaveStructuredContent',
			[
				'enabled'    => true,
				'postType'   => $screen->post_type,
				'fields'     => $fields,
				'storedKeys' => (object) $stored_keys,
				'strings'    => [
					'blockTitle'      => __( 'Octave Content Fields', 'octave-addons' ),
					/* translators: %s: singular post type name. */
					'title'           => sprintf( __( '%s details', 'octave-addons' ), $singular ),
					/* translators: %s: lowercase singular post type name. */
					'intro'           => sprintf( __( 'Update the structured information below. These changes are saved when you update this %s.', 'octave-addons' ), strtolower( $singular ) ),
					'emptyFields'     => __( 'No content fields are assigned to this post type yet. Add fields in Octave Addons, then return here to populate them.', 'octave-addons' ),
					'tabsLabel'       => __( 'Field sections', 'octave-addons' ),
					'required'        => __( 'Required', 'octave-addons' ),
					'fieldRequired'   => __( 'This field is required.', 'octave-addons' ),
					/* translators: %s: comma separated list of field names. */
					'requiredNotice'  => __( 'Saving is paused until these required fields are filled in: %s', 'octave-addons' ),
					'selectOption'    => __( 'Select an option', 'octave-addons' ),
					'yes'             => __( 'Yes', 'octave-addons' ),
					/* translators: %d: item number. */
					'item'            => __( 'Item %d', 'octave-addons' ),
					'noItems'         => __( 'No items yet. Use “Add item” to begin.', 'octave-addons' ),
					'addItem'         => __( 'Add item', 'octave-addons' ),
					'removeItem'      => __( 'Remove item', 'octave-addons' ),
					'moveUp'          => __( 'Move item up', 'octave-addons' ),
					'moveDown'        => __( 'Move item down', 'octave-addons' ),
					'noMedia'         => __( 'Nothing selected', 'octave-addons' ),
					'chooseMedia'     => __( 'Choose', 'octave-addons' ),
					'replaceMedia'    => __( 'Replace', 'octave-addons' ),
					'removeMedia'     => __( 'Remove', 'octave-addons' ),
					'addImages'       => __( 'Add images', 'octave-addons' ),
					'clearGallery'    => __( 'Remove all', 'octave-addons' ),
					'noImages'        => __( 'No images selected yet.', 'octave-addons' ),
					'galleryHint'     => __( 'Drag a thumbnail to reorder, or focus one and use the left and right arrow keys.', 'octave-addons' ),
					/* translators: %d: image number. */
					'galleryItem'     => __( 'Image %d', 'octave-addons' ),
					'removeImage'     => __( 'Remove image', 'octave-addons' ),
				],
			]
		);

	}

	/*
	REGISTER META
	-- Gives every assigned field a REST-visible, sanitised WordPress schema.
	---------------------------------------------------------- */

	protected function register_meta(): void {

		foreach ( $this->fields as $field ) {

			if ( empty( $field['enabled'] ) || self::is_presentational( $field ) ) {

				continue;

			}

			foreach ( $field['post_types'] as $post_type ) {

				$args = [
					'single'            => true,
					'type'              => 'string',
					'show_in_rest'      => true,
					'sanitize_callback' => function ( $value ) use ( $field ) {

						return $this->sanitize_value( $value, $field );

					},
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ): bool {

						return current_user_can( 'edit_post', $post_id );

					},
				];

				if ( in_array( $field['type'], [ 'multiselect', 'repeater', 'gallery' ], true ) ) {

					if ( 'repeater' === $field['type'] ) {

						$items = [ 'type' => 'object', 'additionalProperties' => true ];

					} elseif ( 'gallery' === $field['type'] ) {

						$items = [ 'type' => 'integer' ];

					} else {

						$items = [ 'type' => 'string' ];

					}

					$args['single']       = true;
					$args['type']         = 'array';
					$args['show_in_rest'] = [
						'schema' => [
							'type'  => 'array',
							'items' => $items,
						],
					];

				} elseif ( 'group' === $field['type'] ) {

					$args['type']         = 'object';
					$args['show_in_rest'] = [
						'schema' => [
							'type'                 => 'object',
							'additionalProperties' => true,
						],
					];

				}

				register_post_meta( $post_type, $field['meta_key'], $args );

			}

		}

	}

	/*
	ADD META BOXES
	-- Adds a panel only where the standard content editor remains enabled.
	---------------------------------------------------------- */

	public function add_meta_boxes(): void {

		foreach ( array_keys( $this->post_type_labels ) as $post_type ) {

			if ( in_array( $post_type, $this->structured_post_types, true ) ) {

				continue;

			}

			if ( empty( $this->fields_for_post_type( $post_type ) ) ) {

				continue;

			}

			add_meta_box(
				'octave-custom-post-fields',
				__( 'Octave Post Fields', 'octave-addons' ),
				[ $this, 'render_meta_box' ],
				$post_type,
				'normal',
				'high'
			);

		}

	}

	/*
	VALIDATE REQUIRED META
	-- Refuses a REST save that would leave a required field empty, so the block
	-- editor's save lock is backed by the server rather than trusted on its own.
	-- Only saves that place the post in a visible status are checked, which lets
	-- an editor keep a half-finished draft while still blocking publication.
	---------------------------------------------------------- */

	public function validate_required_meta( $prepared, WP_REST_Request $request, string $post_type ) {

		if ( is_wp_error( $prepared ) || ! is_object( $prepared ) ) {

			return $prepared;

		}

		$post_id = (int) ( $prepared->ID ?? 0 );
		$status  = (string) ( $prepared->post_status ?? '' );

		if ( '' === $status && $post_id ) {

			$status = (string) get_post_status( $post_id );

		}

		if ( ! in_array( $status, [ 'publish', 'future', 'private', 'pending' ], true ) ) {

			return $prepared;

		}

		$submitted = $request->get_param( 'meta' );
		$submitted = is_array( $submitted ) ? $submitted : [];
		$missing   = [];

		foreach ( $this->fields_for_post_type( $post_type ) as $field ) {

			if ( self::is_presentational( $field ) ) {

				continue;

			}

			if ( array_key_exists( $field['meta_key'], $submitted ) ) {

				$value = $this->sanitize_value( $submitted[ $field['meta_key'] ], $field );

			} elseif ( '' !== self::stored_meta_key( $post_id, $field ) ) {

				$value = self::field_value( $post_id, $field );

			} else {

				$value = $this->sanitize_value( $field['default_value'] ?? '', $field );

			}

			$missing = array_merge( $missing, $this->missing_required_labels( $field, $value ) );

		}

		if ( empty( $missing ) ) {

			return $prepared;

		}

		return new WP_Error(
			'octave_addons_required_fields',
			sprintf(
				/* translators: %s: comma separated list of field names. */
				__( 'This content cannot be saved yet. Fill in these required fields first: %s', 'octave-addons' ),
				implode( ', ', $missing )
			),
			[ 'status' => 400 ]
		);

	}

	/*
	MISSING REQUIRED LABELS
	-- Names every unfilled required field, including children of a group or
	-- repeater. A container reports itself only while it is completely empty,
	-- because save discards empty rows before they ever reach storage.
	---------------------------------------------------------- */

	protected function missing_required_labels( array $field, $value, string $prefix = '' ): array {

		$label = $prefix . $field['label'];
		$type  = $field['type'] ?? 'text';

		if ( ! in_array( $type, [ 'group', 'repeater' ], true ) ) {

			return ! empty( $field['required'] ) && $this->is_field_value_empty( $value, $field ) ? [ $label ] : [];

		}

		$sub_fields = $field['sub_fields'] ?? [];
		$rows       = 'group' === $type
			? [ is_array( $value ) ? $value : [] ]
			: array_values( array_filter( is_array( $value ) ? $value : [], 'is_array' ) );

		if ( $this->is_field_value_empty( $value, $field ) ) {

			return ! empty( $field['required'] ) ? [ $label ] : [];

		}

		$missing = [];

		foreach ( $rows as $index => $row ) {

			$row_prefix = 'group' === $type
				? $label . ' › '
				/* translators: 1: field label, 2: row number. */
				: sprintf( __( '%1$s – Item %2$d › ', 'octave-addons' ), $label, $index + 1 );

			foreach ( $sub_fields as $sub_field ) {

				$sub_value = array_key_exists( $sub_field['name'], $row ) ? $row[ $sub_field['name'] ] : ( $sub_field['default_value'] ?? '' );
				$missing   = array_merge( $missing, $this->missing_required_labels( $sub_field, $sub_value, $row_prefix ) );

			}

		}

		return $missing;

	}

	/*
	CLEAN STRUCTURED META
	-- Applies sparse storage after Gutenberg saves registered meta through REST.
	---------------------------------------------------------- */

	public function clean_structured_meta( WP_Post $post, WP_REST_Request $request, bool $creating ): void {

		$submitted = $request->get_param( 'meta' );

		if ( ! is_array( $submitted ) ) {

			return;

		}

		foreach ( $this->fields_for_post_type( $post->post_type ) as $field ) {

			if ( self::is_presentational( $field ) ) {

				continue;

			}

			if ( ! array_key_exists( $field['meta_key'], $submitted ) ) {

				continue;

			}

			$value = $this->sanitize_value( $submitted[ $field['meta_key'] ], $field );

			$this->store_value( $post->ID, $field, $value );

		}

	}

	/*
	RENDER META BOX
	-- Presents assigned values as a clear, responsive editor form.
	---------------------------------------------------------- */

	public function render_meta_box( WP_Post $post ): void {

		$fields = $this->fields_for_post_type( $post->post_type );
		$layout = $this->split_field_tabs( $fields );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		?>

		<div class="oa-post-fields">

			<div class="oa-post-fields-intro">
				<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
				<div>
					<strong><?php esc_html_e( 'Content details', 'octave-addons' ); ?></strong>
					<p><?php esc_html_e( 'Complete the fields below to keep this content consistent across templates and dynamic layouts.', 'octave-addons' ); ?></p>
				</div>
			</div>

			<?php

			if ( empty( $fields ) ) :

			?>

			<div class="oa-post-fields-grid">
				<p class="oa-post-fields-empty"><?php esc_html_e( 'No content fields are assigned yet. Add fields to this post type in Octave Addons, then return here to populate them.', 'octave-addons' ); ?></p>
			</div>

			<?php

			endif;

			if ( ! empty( $layout['lead'] ) ) :

			?>

			<div class="oa-post-fields-grid">

				<?php

				foreach ( $layout['lead'] as $field ) {

					$this->render_field( $post, $field );

				}

				?>

			</div>

			<?php

			endif;

			if ( ! empty( $layout['panels'] ) ) :

			?>

			<div class="oa-post-fields-tabs" data-oa-field-tabs>

				<div class="oa-post-fields-tablist" role="tablist" aria-label="<?php esc_attr_e( 'Field sections', 'octave-addons' ); ?>">

					<?php

					foreach ( $layout['panels'] as $index => $panel ) :

						$is_first = 0 === $index;

					?>

					<button type="button" role="tab" class="oa-post-fields-tab<?= $is_first ? ' is-active' : ''; ?>" id="oa-post-tab-<?= esc_attr( $panel['name'] ); ?>" aria-controls="oa-post-tab-panel-<?= esc_attr( $panel['name'] ); ?>" aria-selected="<?= $is_first ? 'true' : 'false'; ?>" tabindex="<?= $is_first ? '0' : '-1'; ?>"><?= esc_html( $panel['label'] ); ?></button>

					<?php

					endforeach;

					?>

				</div>

				<?php

				foreach ( $layout['panels'] as $index => $panel ) :

				?>

				<div class="oa-post-fields-grid" role="tabpanel" id="oa-post-tab-panel-<?= esc_attr( $panel['name'] ); ?>" aria-labelledby="oa-post-tab-<?= esc_attr( $panel['name'] ); ?>"<?= 0 === $index ? '' : ' hidden'; ?>>

					<?php

					foreach ( $panel['fields'] as $field ) {

						$this->render_field( $post, $field );

					}

					?>

				</div>

				<?php

				endforeach;

				?>

			</div>

			<?php

			endif;

			?>

		</div>

		<?php

	}

	/*
	SPLIT FIELD TABS
	-- Groups the ordered field list on every tab marker. Fields placed before
	-- the first tab have no panel to belong to, so they stay above the strip
	-- and remain visible whichever tab is open.
	---------------------------------------------------------- */

	protected function split_field_tabs( array $fields ): array {

		$lead   = [];
		$panels = [];

		foreach ( $fields as $field ) {

			if ( 'tab' === $field['type'] ) {

				$panels[] = [
					'fields' => [],
					'label'  => (string) $field['label'],
					'name'   => (string) $field['name'],
				];

				continue;

			}

			if ( empty( $panels ) ) {

				$lead[] = $field;

				continue;

			}

			$panels[ count( $panels ) - 1 ]['fields'][] = $field;

		}

		return [ 'lead' => $lead, 'panels' => $panels ];

	}

	/*
	RENDER FIELD
	-- Outputs the appropriate accessible control and media affordances.
	---------------------------------------------------------- */

	protected function render_field( WP_Post $post, array $field ): void {

		$value       = self::field_value( $post->ID, $field );
		$name        = 'octave_post_fields[' . $field['name'] . ']';
		$id          = 'octave_post_field_' . $field['name'];
		$type        = $field['type'];
		$is_wide     = in_array( $type, [ 'textarea', 'wysiwyg', 'gallery' ], true );
		$choices     = $this->parse_choices( $field['choices'] );
		$description = (string) $field['description'];

		if ( 'tab' === $type ) {

			return;

		}

		if ( 'html' === $type ) {

			$content = trim( (string) $field['default_value'] );

			?>

			<section class="oa-post-field oa-post-field--wide oa-post-field-html">
				<?= '' !== $content ? wp_kses_post( $content ) : '<h3>' . esc_html( $field['label'] ) . '</h3>'; ?>
			</section>

			<?php

			return;

		}

		if ( in_array( $type, [ 'group', 'repeater' ], true ) ) {

			$this->render_container_field( $field, is_array( $value ) ? $value : [] );

			return;

		}

		?>

		<div class="oa-post-field<?= $is_wide ? ' oa-post-field--wide' : ''; ?>">
			<label class="oa-post-field-label" for="<?= esc_attr( $id ); ?>">
				<?= esc_html( $field['label'] ); ?>
				<?php if ( ! empty( $field['required'] ) ) : ?>
				<span class="oa-post-field-required"><?php esc_html_e( 'Required', 'octave-addons' ); ?></span>
				<?php endif; ?>
			</label>

			<?php

			if ( 'textarea' === $type ) {

				?>

				<textarea id="<?= esc_attr( $id ); ?>" name="<?= esc_attr( $name ); ?>" rows="5"<?= ! empty( $field['required'] ) ? ' required' : ''; ?>><?= esc_textarea( (string) $value ); ?></textarea>

				<?php

			} elseif ( 'wysiwyg' === $type ) {

				wp_editor(
					(string) $value,
					$id,
					[
						'textarea_name' => $name,
						'textarea_rows' => 8,
						'media_buttons' => true,
						'teeny'         => false,
					]
				);

			} elseif ( in_array( $type, [ 'select', 'multiselect' ], true ) ) {

				$selected_values = is_array( $value ) ? $value : [ (string) $value ];

				?>

				<select id="<?= esc_attr( $id ); ?>" name="<?= esc_attr( $name ); ?><?= 'multiselect' === $type ? '[]' : ''; ?>"<?= 'multiselect' === $type ? ' multiple size="5"' : ''; ?><?= ! empty( $field['required'] ) ? ' required' : ''; ?>>
					<?php if ( 'select' === $type ) : ?>
					<option value=""><?php esc_html_e( 'Select an option', 'octave-addons' ); ?></option>
					<?php endif; ?>
					<?php foreach ( $choices as $choice_value => $choice_label ) : ?>
					<option value="<?= esc_attr( $choice_value ); ?>"<?= selected( in_array( $choice_value, $selected_values, true ), true, false ); ?>><?= esc_html( $choice_label ); ?></option>
					<?php endforeach; ?>
				</select>

				<?php

			} elseif ( 'radio' === $type ) {

				?>

				<div class="oa-post-field-options" id="<?= esc_attr( $id ); ?>">
					<?php foreach ( $choices as $choice_value => $choice_label ) : ?>
					<label><input type="radio" name="<?= esc_attr( $name ); ?>" value="<?= esc_attr( $choice_value ); ?>"<?= checked( (string) $value, $choice_value, false ); ?>> <span><?= esc_html( $choice_label ); ?></span></label>
					<?php endforeach; ?>
				</div>

				<?php

			} elseif ( 'checkbox' === $type ) {

				?>

				<label class="oa-post-field-checkbox" for="<?= esc_attr( $id ); ?>">
					<input type="checkbox" id="<?= esc_attr( $id ); ?>" name="<?= esc_attr( $name ); ?>" value="1"<?= checked( ! empty( $value ), true, false ); ?>>
					<span><?php esc_html_e( 'Yes', 'octave-addons' ); ?></span>
				</label>

				<?php

			} elseif ( 'gallery' === $type ) {

				$this->render_gallery_control( is_array( $value ) ? $value : [], $name, $id );

			} elseif ( in_array( $type, [ 'image', 'file' ], true ) ) {

				$attachment_id = absint( $value );
				$file_name     = $attachment_id ? basename( (string) get_attached_file( $attachment_id ) ) : '';
				$image_url     = 'image' === $type && $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';

				?>

				<div class="oa-post-field-media" data-media-type="<?= esc_attr( $type ); ?>">
					<input type="hidden" id="<?= esc_attr( $id ); ?>" name="<?= esc_attr( $name ); ?>" value="<?= esc_attr( (string) $attachment_id ); ?>">
					<div class="oa-post-field-media-preview<?= $attachment_id ? ' has-value' : ''; ?>">
						<?php if ( $image_url ) : ?>
						<img src="<?= esc_url( $image_url ); ?>" alt="">
						<?php else : ?>
						<span class="dashicons <?= 'image' === $type ? 'dashicons-format-image' : 'dashicons-media-default'; ?>" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="oa-post-field-media-name"><?= esc_html( $file_name ); ?></span>
					</div>
					<div class="oa-post-field-media-actions">
						<button type="button" class="button oa-post-field-media-select"><?= $attachment_id ? esc_html__( 'Replace', 'octave-addons' ) : esc_html__( 'Choose from Media Library', 'octave-addons' ); ?></button>
						<button type="button" class="button-link-delete oa-post-field-media-remove<?= $attachment_id ? '' : ' hidden'; ?>"><?php esc_html_e( 'Remove', 'octave-addons' ); ?></button>
					</div>
				</div>

				<?php

			} else {

				$html_type = 'datetime' === $type ? 'datetime-local' : $type;
				$step      = 'number' === $type ? 'any' : '';

				?>

				<input type="<?= esc_attr( $html_type ); ?>" id="<?= esc_attr( $id ); ?>" name="<?= esc_attr( $name ); ?>" value="<?= esc_attr( (string) $value ); ?>"<?= '' !== $step ? ' step="any"' : ''; ?><?= ! empty( $field['required'] ) ? ' required' : ''; ?>>

				<?php

			}

			if ( '' !== $description ) :

			?>

			<p class="description"><?= esc_html( $description ); ?></p>

			<?php

			endif;

			?>

		</div>

		<?php

	}

	/*
	RENDER GALLERY CONTROL
	-- Shows the chosen attachments as ordered thumbnails backed by one hidden
	-- input holding the ID order, which keeps the control safe to duplicate and
	-- reindex inside a repeater row where names change after every move.
	---------------------------------------------------------- */

	protected function render_gallery_control( array $attachment_ids, string $name, string $id ): void {

		$attachment_ids = array_values(
			array_filter(
				array_map( 'absint', $attachment_ids )
			)
		);

		?>

		<div class="oa-post-field-gallery<?= empty( $attachment_ids ) ? '' : ' has-items'; ?>" data-gallery>
			<input type="hidden" id="<?= esc_attr( $id ); ?>" name="<?= esc_attr( $name ); ?>" value="<?= esc_attr( implode( ',', $attachment_ids ) ); ?>">

			<ul class="oa-gallery-items">

				<?php

				foreach ( $attachment_ids as $position => $attachment_id ) {

					$this->render_gallery_item( (int) $attachment_id, (int) $position + 1 );

				}

				?>

			</ul>

			<p class="oa-gallery-empty"><?php esc_html_e( 'No images selected yet. Use “Add images” to choose from the Media Library.', 'octave-addons' ); ?></p>

			<div class="oa-post-field-media-actions">
				<button type="button" class="button oa-gallery-select"><?php esc_html_e( 'Add images', 'octave-addons' ); ?></button>
				<button type="button" class="button-link-delete oa-gallery-clear"><?php esc_html_e( 'Remove all', 'octave-addons' ); ?></button>
			</div>

			<p class="description oa-gallery-hint"><?php esc_html_e( 'Drag a thumbnail to reorder, or focus one and use the left and right arrow keys.', 'octave-addons' ); ?></p>
		</div>

		<?php

	}

	/*
	RENDER GALLERY ITEM
	-- Outputs one thumbnail tile. A missing attachment still renders so the
	-- editor can see and clear the broken entry instead of losing it silently.
	---------------------------------------------------------- */

	protected function render_gallery_item( int $attachment_id, int $position ): void {

		$thumbnail = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

		?>

		<li class="oa-gallery-item" draggable="true" tabindex="0" data-id="<?= esc_attr( (string) $attachment_id ); ?>" aria-label="<?= esc_attr( sprintf( __( 'Image %d', 'octave-addons' ), $position ) ); ?>">
			<span class="oa-gallery-item-position"><?= esc_html( (string) $position ); ?></span>

			<?php

			if ( $thumbnail ) :

			?>

			<img src="<?= esc_url( $thumbnail ); ?>" alt="">

			<?php

			else :

			?>

			<span class="dashicons dashicons-format-image" aria-hidden="true"></span>

			<?php

			endif;

			?>

			<button type="button" class="oa-gallery-remove" aria-label="<?php esc_attr_e( 'Remove image', 'octave-addons' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
		</li>

		<?php

	}

	/*
	RENDER CONTAINER FIELD
	-- Displays a group once or a repeater with editor-controlled rows.
	---------------------------------------------------------- */

	protected function render_container_field( array $field, array $value ): void {

		$is_repeater = 'repeater' === $field['type'];
		$rows        = $is_repeater ? array_values( array_filter( $value, 'is_array' ) ) : [ $value ];

		?>

		<section class="oa-post-field oa-post-field--wide oa-post-field-container<?= $is_repeater ? ' oa-post-field-repeater' : ' oa-post-field-group'; ?>" data-field-name="<?= esc_attr( $field['name'] ); ?>">
			<div class="oa-post-field-container-head">
				<div>
					<strong><?= esc_html( $field['label'] ); ?></strong>
					<?php if ( ! empty( $field['required'] ) ) : ?>
					<span class="oa-post-field-required"><?php esc_html_e( 'Required', 'octave-addons' ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $field['description'] ) : ?>
					<p><?= esc_html( $field['description'] ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( $is_repeater ) : ?>
				<button type="button" class="button button-secondary oa-repeater-add"><span aria-hidden="true">+</span><?php esc_html_e( 'Add item', 'octave-addons' ); ?></button>
				<?php endif; ?>
			</div>

			<div class="oa-repeater-rows" data-empty-text="<?php esc_attr_e( 'No items yet. Use “Add item” to begin.', 'octave-addons' ); ?>">

				<?php

				foreach ( $rows as $row_index => $row ) {

					$this->render_container_row( $field, $row, $is_repeater ? (string) $row_index : '' );

				}

				?>

			</div>

			<?php if ( $is_repeater ) : ?>
			<template class="oa-repeater-template"><?php $this->render_container_row( $field, [], '__ROW__' ); ?></template>
			<?php endif; ?>
		</section>

		<?php

	}

	/*
	RENDER CONTAINER ROW
	-- Outputs one group or repeatable item with all configured child controls.
	---------------------------------------------------------- */

	protected function render_container_row( array $field, array $row, string $row_index ): void {

		$is_repeater = 'repeater' === $field['type'];
		$row_label   = $is_repeater && '__ROW__' !== $row_index ? sprintf( __( 'Item %d', 'octave-addons' ), (int) $row_index + 1 ) : __( 'Item', 'octave-addons' );

		?>

		<div class="oa-repeater-row">
			<?php if ( $is_repeater ) : ?>
			<div class="oa-repeater-row-head">
				<span class="oa-repeater-row-number"><?= esc_html( $row_label ); ?></span>
				<div class="oa-repeater-row-actions">
					<button type="button" class="oa-repeater-move-up" aria-label="<?php esc_attr_e( 'Move item up', 'octave-addons' ); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
					<button type="button" class="oa-repeater-move-down" aria-label="<?php esc_attr_e( 'Move item down', 'octave-addons' ); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
					<button type="button" class="oa-repeater-remove" aria-label="<?php esc_attr_e( 'Remove item', 'octave-addons' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
				</div>
			</div>
			<?php endif; ?>

			<div class="oa-repeater-row-fields">

				<?php

				foreach ( $field['sub_fields'] as $sub_field ) {

					$name = 'octave_post_fields[' . $field['name'] . ']';

					if ( $is_repeater ) {

						$name .= '[' . $row_index . ']';

					}

					$name .= '[' . $sub_field['name'] . ']';
					$id    = 'octave_post_field_' . $field['name'] . '_' . ( '' !== $row_index ? $row_index . '_' : '' ) . $sub_field['name'];
					$value = array_key_exists( $sub_field['name'], $row ) ? $row[ $sub_field['name'] ] : $sub_field['default_value'];

					$this->render_sub_field_control( $sub_field, $value, $name, $id );

				}

				?>

			</div>
		</div>

		<?php

	}

	/*
	RENDER SUB FIELD CONTROL
	-- Renders a typed child control without creating another meta value.
	---------------------------------------------------------- */

	protected function render_sub_field_control( array $field, $value, string $name, string $id ): void {

		$type    = $field['type'];
		$choices = $this->parse_choices( $field['choices'] );
		$is_wide = in_array( $type, [ 'textarea', 'wysiwyg', 'gallery' ], true );

		?>

		<div class="oa-post-field<?= $is_wide ? ' oa-post-field--wide' : ''; ?>">
			<label class="oa-post-field-label" for="<?= esc_attr( $id ); ?>"><?= esc_html( $field['label'] ); ?><?php if ( ! empty( $field['required'] ) ) : ?><span class="oa-post-field-required"><?php esc_html_e( 'Required', 'octave-addons' ); ?></span><?php endif; ?></label>

			<?php if ( 'textarea' === $type ) : ?>
			<textarea id="<?= esc_attr( $id ); ?>" name="<?= esc_attr( $name ); ?>" rows="4"<?= ! empty( $field['required'] ) ? ' required' : ''; ?>><?= esc_textarea( (string) $value ); ?></textarea>
			<?php elseif ( 'wysiwyg' === $type ) : ?>
			<textarea class="oa-nested-wysiwyg" id="<?= esc_attr( $id ); ?>" name="<?= esc_attr( $name ); ?>" rows="6"><?= esc_textarea( (string) $value ); ?></textarea>
			<?php elseif ( in_array( $type, [ 'select', 'multiselect' ], true ) ) :

				$selected_values = is_array( $value ) ? $value : [ (string) $value ];

			?>
			<select id="<?= esc_attr( $id ); ?>" name="<?= esc_attr( $name ); ?><?= 'multiselect' === $type ? '[]' : ''; ?>"<?= 'multiselect' === $type ? ' multiple size="5"' : ''; ?><?= ! empty( $field['required'] ) ? ' required' : ''; ?>>
				<?php if ( 'select' === $type ) : ?><option value=""><?php esc_html_e( 'Select an option', 'octave-addons' ); ?></option><?php endif; ?>
				<?php foreach ( $choices as $choice_value => $choice_label ) : ?><option value="<?= esc_attr( $choice_value ); ?>"<?= selected( in_array( $choice_value, $selected_values, true ), true, false ); ?>><?= esc_html( $choice_label ); ?></option><?php endforeach; ?>
			</select>
			<?php elseif ( 'radio' === $type ) : ?>
			<div class="oa-post-field-options" id="<?= esc_attr( $id ); ?>"><?php foreach ( $choices as $choice_value => $choice_label ) : ?><label><input type="radio" name="<?= esc_attr( $name ); ?>" value="<?= esc_attr( $choice_value ); ?>"<?= checked( (string) $value, $choice_value, false ); ?>> <span><?= esc_html( $choice_label ); ?></span></label><?php endforeach; ?></div>
			<?php elseif ( 'checkbox' === $type ) : ?>
			<label class="oa-post-field-checkbox" for="<?= esc_attr( $id ); ?>"><input type="checkbox" id="<?= esc_attr( $id ); ?>" name="<?= esc_attr( $name ); ?>" value="1"<?= checked( ! empty( $value ), true, false ); ?>><span><?php esc_html_e( 'Yes', 'octave-addons' ); ?></span></label>
			<?php elseif ( 'gallery' === $type ) :

				$this->render_gallery_control( is_array( $value ) ? $value : [], $name, $id );

			elseif ( in_array( $type, [ 'image', 'file' ], true ) ) :

				$attachment_id = absint( $value );
				$file_name     = $attachment_id ? basename( (string) get_attached_file( $attachment_id ) ) : '';
				$image_url     = 'image' === $type && $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';

			?>
			<div class="oa-post-field-media" data-media-type="<?= esc_attr( $type ); ?>">
				<input type="hidden" id="<?= esc_attr( $id ); ?>" name="<?= esc_attr( $name ); ?>" value="<?= esc_attr( (string) $attachment_id ); ?>">
				<div class="oa-post-field-media-preview<?= $attachment_id ? ' has-value' : ''; ?>"><?php if ( $image_url ) : ?><img src="<?= esc_url( $image_url ); ?>" alt=""><?php else : ?><span class="dashicons <?= 'image' === $type ? 'dashicons-format-image' : 'dashicons-media-default'; ?>" aria-hidden="true"></span><?php endif; ?><span class="oa-post-field-media-name"><?= esc_html( $file_name ); ?></span></div>
				<div class="oa-post-field-media-actions"><button type="button" class="button oa-post-field-media-select"><?= $attachment_id ? esc_html__( 'Replace', 'octave-addons' ) : esc_html__( 'Choose from Media Library', 'octave-addons' ); ?></button><button type="button" class="button-link-delete oa-post-field-media-remove<?= $attachment_id ? '' : ' hidden'; ?>"><?php esc_html_e( 'Remove', 'octave-addons' ); ?></button></div>
			</div>
			<?php else :

				$html_type = 'datetime' === $type ? 'datetime-local' : $type;

			?>
			<input type="<?= esc_attr( $html_type ); ?>" id="<?= esc_attr( $id ); ?>" name="<?= esc_attr( $name ); ?>" value="<?= esc_attr( (string) $value ); ?>"<?= in_array( $type, [ 'number', 'range' ], true ) ? ' step="any"' : ''; ?><?= ! empty( $field['required'] ) ? ' required' : ''; ?>>
			<?php endif; ?>

			<?php if ( '' !== $field['description'] ) : ?><p class="description"><?= esc_html( $field['description'] ); ?></p><?php endif; ?>
		</div>

		<?php

	}

	/*
	STORE VALUE
	-- Writes one field to its canonical key and clears any legacy row left over
	-- from the prefixed key, so a post carries exactly one copy of the value
	-- once it has been saved through the editor.
	---------------------------------------------------------- */

	protected function store_value( int $post_id, array $field, $value ): void {

		if ( $this->should_store_value( $value, $field ) ) {

			update_post_meta( $post_id, $field['meta_key'], $value );

		} else {

			delete_post_meta( $post_id, $field['meta_key'] );

		}

		foreach ( array_slice( self::meta_keys( $field ), 1 ) as $legacy ) {

			delete_post_meta( $post_id, $legacy );

		}

	}

	/*
	SAVE POST
	-- Validates the editor request and updates only fields assigned to the post.
	---------------------------------------------------------- */

	public function save_post( int $post_id, WP_Post $post ): void {

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {

			return;

		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {

			return;

		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {

			return;

		}

		$submitted = isset( $_POST['octave_post_fields'] ) && is_array( $_POST['octave_post_fields'] )
			? wp_unslash( $_POST['octave_post_fields'] )
			: [];

		foreach ( $this->fields_for_post_type( $post->post_type ) as $field ) {

			if ( self::is_presentational( $field ) ) {

				continue;

			}

			$raw   = $submitted[ $field['name'] ] ?? ( in_array( $field['type'], [ 'multiselect', 'group', 'repeater', 'gallery' ], true ) ? [] : '' );
			$value = $this->sanitize_value( $raw, $field );

			$this->store_value( $post_id, $field, $value );

		}

	}

	/*
	SANITIZE VALUE
	-- Applies the safest matching WordPress sanitizer for each field type.
	---------------------------------------------------------- */

	protected function sanitize_value( $value, array $field ) {

		$type = $field['type'];

		if ( 'group' === $type ) {

			return $this->sanitize_container_row( is_array( $value ) ? $value : [], $field['sub_fields'] );

		}

		if ( 'repeater' === $type ) {

			$rows = [];

			foreach ( array_slice( is_array( $value ) ? $value : [], 0, 100 ) as $row ) {

				if ( is_array( $row ) ) {

					$clean_row = $this->sanitize_container_row( $row, $field['sub_fields'] );

					if ( ! $this->is_container_row_empty( $clean_row, $field['sub_fields'] ) ) {

						$rows[] = $clean_row;

					}

				}

			}

			return $rows;

		}

		if ( 'multiselect' === $type ) {

			$allowed = array_keys( $this->parse_choices( $field['choices'] ) );
			$values  = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : [];

			return array_values( array_intersect( $values, $allowed ) );

		}

		if ( 'wysiwyg' === $type ) {

			return wp_kses_post( (string) $value );

		}

		if ( 'textarea' === $type ) {

			return sanitize_textarea_field( (string) $value );

		}

		if ( 'email' === $type ) {

			return sanitize_email( (string) $value );

		}

		if ( 'url' === $type ) {

			return esc_url_raw( (string) $value );

		}

		if ( in_array( $type, [ 'number', 'range' ], true ) ) {

			$value = trim( (string) $value );

			return '' !== $value && is_numeric( $value ) ? $value : '';

		}

		if ( 'color' === $type ) {

			return (string) sanitize_hex_color( (string) $value );

		}

		$patterns = [
			'date'     => '/^\d{4}-\d{2}-\d{2}$/',
			'time'     => '/^\d{2}:\d{2}(?::\d{2})?$/',
			'datetime' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/',
			'month'    => '/^\d{4}-\d{2}$/',
			'week'     => '/^\d{4}-W\d{2}$/',
		];

		if ( isset( $patterns[ $type ] ) ) {

			$value = sanitize_text_field( (string) $value );

			return preg_match( $patterns[ $type ], $value ) ? $value : '';

		}

		if ( 'gallery' === $type ) {

			$submitted = is_array( $value ) ? $value : preg_split( '/[^0-9]+/', (string) $value );
			$ids       = [];

			foreach ( (array) $submitted as $submitted_id ) {

				$submitted_id = absint( $submitted_id );

				if ( $submitted_id && ! in_array( $submitted_id, $ids, true ) ) {

					$ids[] = $submitted_id;

				}

			}

			return array_slice( $ids, 0, 200 );

		}

		if ( in_array( $type, [ 'image', 'file' ], true ) ) {

			return (string) absint( $value );

		}

		if ( 'checkbox' === $type ) {

			return ! empty( $value ) ? '1' : '0';

		}

		if ( in_array( $type, [ 'select', 'radio' ], true ) ) {

			$value   = sanitize_text_field( (string) $value );
			$allowed = array_keys( $this->parse_choices( $field['choices'] ) );

			return in_array( $value, $allowed, true ) ? $value : '';

		}

		return sanitize_text_field( (string) $value );

	}

	/*
	SHOULD STORE VALUE
	-- Keeps postmeta sparse by omitting values that resolve to the field default.
	-- An intentional empty override is retained when the configured default is
	-- non-empty, so clearing a field never makes its default reappear.
	---------------------------------------------------------- */

	protected function should_store_value( $value, array $field ): bool {

		$default = $this->sanitize_value( $field['default_value'] ?? '', $field );

		if ( $value === $default ) {

			return false;

		}

		return ! ( $this->is_field_value_empty( $value, $field ) && $this->is_field_value_empty( $default, $field ) );

	}

	/*
	IS FIELD VALUE EMPTY
	-- Applies type-aware emptiness so meaningful numeric zero values survive,
	-- while unchecked toggles and unselected media do not create meta rows.
	---------------------------------------------------------- */

	protected function is_field_value_empty( $value, array $field ): bool {

		$type = $field['type'] ?? 'text';

		if ( 'group' === $type ) {

			return $this->is_container_row_empty( is_array( $value ) ? $value : [], $field['sub_fields'] ?? [] );

		}

		if ( in_array( $type, [ 'repeater', 'multiselect', 'gallery' ], true ) ) {

			return empty( $value );

		}

		if ( in_array( $type, [ 'checkbox', 'image', 'file' ], true ) ) {

			return empty( $value );

		}

		return '' === (string) $value;

	}

	/*
	IS CONTAINER ROW EMPTY
	-- Treats a group or repeater row as empty only when every known child is
	-- empty according to its own field type.
	---------------------------------------------------------- */

	protected function is_container_row_empty( array $row, array $sub_fields ): bool {

		foreach ( $sub_fields as $sub_field ) {

			$value = $row[ $sub_field['name'] ] ?? '';

			if ( ! $this->is_field_value_empty( $value, $sub_field ) ) {

				return false;

			}

		}

		return true;

	}

	/*
	SANITIZE CONTAINER ROW
	-- Applies each child definition while discarding unknown submitted keys.
	---------------------------------------------------------- */

	protected function sanitize_container_row( array $row, array $sub_fields ): array {

		$clean = [];

		foreach ( $sub_fields as $sub_field ) {

			$raw = $row[ $sub_field['name'] ] ?? ( in_array( $sub_field['type'], [ 'multiselect', 'gallery' ], true ) ? [] : '' );

			$clean[ $sub_field['name'] ] = $this->sanitize_value( $raw, $sub_field );

		}

		return $clean;

	}

	/*
	ENQUEUE EDITOR ASSETS
	-- Loads the meta box field UI only on applicable post editors.
	-- Field-only post types are skipped entirely; their canvas assets go
	-- through the block asset hooks so Gutenberg can serve them to the iframe.
	---------------------------------------------------------- */

	public function enqueue_editor_assets( string $hook ): void {

		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {

			return;

		}

		$screen = get_current_screen();

		if ( ! $screen || in_array( $screen->post_type, $this->structured_post_types, true ) ) {

			return;

		}

		if ( empty( $this->fields_for_post_type( $screen->post_type ) ) ) {

			return;

		}

		wp_enqueue_editor();
		wp_enqueue_media();
		wp_enqueue_style( 'octave-post-fields' );
		wp_enqueue_script( 'octave-post-fields' );

		wp_localize_script(
			'octave-post-fields',
			'octavePostFields',
			[
				'chooseImage'          => __( 'Choose an image', 'octave-addons' ),
				'chooseFile'           => __( 'Choose a file', 'octave-addons' ),
				'chooseImages'         => __( 'Add images to the gallery', 'octave-addons' ),
				'useMedia'             => __( 'Use this media', 'octave-addons' ),
				'useImages'            => __( 'Add to gallery', 'octave-addons' ),
				'replace'              => __( 'Replace', 'octave-addons' ),
				'removeImage'          => __( 'Remove image', 'octave-addons' ),
				'galleryItemLabel'     => __( 'Image %d', 'octave-addons' ),
				'itemLabel'            => __( 'Item %d', 'octave-addons' ),
			]
		);

	}

	/*
	REGISTER BREAKDANCE FIELDS
	-- Creates typed Dynamic Data entries grouped under Octave.
	---------------------------------------------------------- */

	public function register_breakdance_fields(): void {

		$enabled_fields = array_values(
			array_filter(
				$this->fields,
				static function ( array $field ): bool {

					return ! empty( $field['enabled'] ) && ! self::is_presentational( $field );

				}
			)
		);

		if ( empty( $enabled_fields ) || ! function_exists( '\\Breakdance\\DynamicData\\registerField' ) ) {

			return;

		}

		if ( ! class_exists( 'Octave_Addons_Breakdance_String_Field', false ) ) {

			require_once __DIR__ . '/class-breakdance-fields.php';

		}

		$controller = \Breakdance\DynamicData\DynamicDataController::getInstance();
		$category   = __( 'Octave', 'octave-addons' );

		if ( ! in_array( $category, $controller->order, true ) ) {

			$post_position = array_search( __( 'Post', 'breakdance' ), $controller->order, true );

			if ( 0 === $post_position ) {

				array_unshift( $controller->order, '__octave_dynamic_data_order_start__' );
				$post_position = 1;

			}

			$position = false === $post_position ? 0 : (int) $post_position + 1;

			array_splice( $controller->order, $position, 0, [ $category ] );

		}

		usort(
			$enabled_fields,
			static function ( array $first, array $second ): int {

				return strcasecmp( (string) $first['label'], (string) $second['label'] );

			}
		);

		foreach ( $enabled_fields as $field ) {

			if ( 'group' === $field['type'] ) {

				$this->register_breakdance_sub_fields( $field );

			} elseif ( 'repeater' === $field['type'] ) {

				if ( class_exists( 'Octave_Addons_Breakdance_Repeater_Field', false ) ) {

					\Breakdance\DynamicData\registerField( new Octave_Addons_Breakdance_Repeater_Field( $field ) );
					$this->register_breakdance_sub_fields( $field );

				}

			} elseif ( 'image' === $field['type'] && class_exists( 'Octave_Addons_Breakdance_Image_Field', false ) ) {

				\Breakdance\DynamicData\registerField( new Octave_Addons_Breakdance_Image_Field( $field ) );

			} elseif ( 'gallery' === $field['type'] && class_exists( 'Octave_Addons_Breakdance_Gallery_Field', false ) ) {

				\Breakdance\DynamicData\registerField( new Octave_Addons_Breakdance_Gallery_Field( $field ) );

			} elseif ( class_exists( 'Octave_Addons_Breakdance_String_Field', false ) ) {

				\Breakdance\DynamicData\registerField( new Octave_Addons_Breakdance_String_Field( $field ) );

			}

		}

	}

	/*
	REGISTER BREAKDANCE SUB FIELDS
	-- Exposes structured children beneath their group or repeater context.
	---------------------------------------------------------- */

	protected function register_breakdance_sub_fields( array $parent ): void {

		foreach ( $parent['sub_fields'] as $sub_field ) {

			$sub_field['meta_key']        = $parent['meta_key'];
			$sub_field['legacy_meta_key'] = $parent['legacy_meta_key'] ?? '';
			$sub_field['post_types']      = $parent['post_types'];
			$sub_field['parent_type']     = $parent['type'];
			$sub_field['parent_name']     = $parent['name'];
			$sub_field['dynamic_name']    = $parent['name'] . '_' . $sub_field['name'];
			$sub_field['label']           = $parent['label'] . ' · ' . $sub_field['label'];

			if ( 'image' === $sub_field['type'] && class_exists( 'Octave_Addons_Breakdance_Image_Field', false ) ) {

				\Breakdance\DynamicData\registerField( new Octave_Addons_Breakdance_Image_Field( $sub_field ) );

			} elseif ( 'gallery' === $sub_field['type'] && class_exists( 'Octave_Addons_Breakdance_Gallery_Field', false ) ) {

				\Breakdance\DynamicData\registerField( new Octave_Addons_Breakdance_Gallery_Field( $sub_field ) );

			} elseif ( class_exists( 'Octave_Addons_Breakdance_String_Field', false ) ) {

				\Breakdance\DynamicData\registerField( new Octave_Addons_Breakdance_String_Field( $sub_field ) );

			}

		}

	}

	/*
	FIELDS FOR POST TYPE
	-- Returns enabled definitions assigned to one editor.
	---------------------------------------------------------- */

	protected function fields_for_post_type( string $post_type ): array {

		return array_values(
			array_filter(
				$this->fields,
				static function ( array $field ) use ( $post_type ): bool {

					return ! empty( $field['enabled'] ) && in_array( $post_type, $field['post_types'], true );

				}
			)
		);

	}

	/*
	PARSE CHOICES
	-- Supports either value : Label pairs or simple one-value-per-line lists.
	---------------------------------------------------------- */

	protected function parse_choices( string $choices ): array {

		$parsed = [];

		foreach ( preg_split( '/\r\n|\r|\n/', $choices ) as $line ) {

			$line = trim( $line );

			if ( '' === $line ) {

				continue;

			}

			$parts = array_map( 'trim', explode( ':', $line, 2 ) );
			$value = sanitize_text_field( $parts[0] );
			$label = sanitize_text_field( $parts[1] ?? $parts[0] );

			if ( '' !== $value ) {

				$parsed[ $value ] = $label;

			}

		}

		return $parsed;

	}

}
