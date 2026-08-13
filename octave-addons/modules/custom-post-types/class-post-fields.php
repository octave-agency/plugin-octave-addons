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

	/*
	CONSTRUCTOR
	-- Stores normalised definitions and attaches editor and builder hooks.
	---------------------------------------------------------- */

	public function __construct( array $fields, array $post_type_labels, array $post_types = [] ) {

		$this->fields                = $fields;
		$this->post_type_labels      = $post_type_labels;
		$this->structured_post_types = [];

		foreach ( $post_types as $post_type ) {

			$key = (string) ( $post_type['post_type'] ?? '' );

			if ( '' !== $key && empty( $post_type['content_editor'] ) ) {

				$this->structured_post_types[] = $key;

			}

		}

		$this->register_meta();

		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'save_post' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'init', [ $this, 'register_structured_content_block' ], 15 );
		add_action( 'init', [ $this, 'register_breakdance_fields' ], 20 );

	}

	/*
	REGISTER STRUCTURED CONTENT BLOCK
	-- Provides the Gutenberg-native launcher used when standard content is off.
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
		wp_register_script(
			'octave-post-fields',
			OCTAVE_ADDONS_URL . 'modules/custom-post-types/assets/post-fields.js',
			[ 'jquery', 'wp-blocks', 'wp-data', 'wp-dom-ready', 'wp-element', 'wp-i18n' ],
			OCTAVE_ADDONS_VERSION,
			true
		);

		register_block_type(
			'octave/structured-content-launcher',
			[
				'api_version'     => 2,
				'editor_script'   => 'octave-post-fields',
				'editor_style'    => 'octave-post-fields',
				'render_callback' => '__return_empty_string',
			]
		);

	}

	/*
	REGISTER META
	-- Gives every assigned field a REST-visible, sanitised WordPress schema.
	---------------------------------------------------------- */

	protected function register_meta(): void {

		foreach ( $this->fields as $field ) {

			if ( empty( $field['enabled'] ) ) {

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

				if ( in_array( $field['type'], [ 'multiselect', 'repeater' ], true ) ) {

					$args['single']       = true;
					$args['type']         = 'array';
					$args['show_in_rest'] = [
						'schema' => [
							'type'  => 'array',
							'items' => 'repeater' === $field['type']
								? [ 'type' => 'object', 'additionalProperties' => true ]
								: [ 'type' => 'string' ],
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
	-- Adds one consistent Octave panel to every assigned content type.
	---------------------------------------------------------- */

	public function add_meta_boxes(): void {

		foreach ( array_keys( $this->post_type_labels ) as $post_type ) {

			if ( empty( $this->fields_for_post_type( $post_type ) ) && ! in_array( $post_type, $this->structured_post_types, true ) ) {

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
	RENDER META BOX
	-- Presents assigned values as a clear, responsive editor form.
	---------------------------------------------------------- */

	public function render_meta_box( WP_Post $post ): void {

		$fields          = $this->fields_for_post_type( $post->post_type );
		$structured_only = in_array( $post->post_type, $this->structured_post_types, true );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		?>

		<div class="oa-post-fields<?= $structured_only ? ' oa-post-fields--structured' : ''; ?>">
			<div class="oa-post-fields-intro">
				<span class="dashicons <?= $structured_only ? 'dashicons-feedback' : 'dashicons-admin-generic'; ?>" aria-hidden="true"></span>
				<div>
					<strong><?= $structured_only ? esc_html__( 'This post type uses default fields', 'octave-addons' ) : esc_html__( 'Content details', 'octave-addons' ); ?></strong>
					<p><?= $structured_only ? esc_html__( 'Please populate the fields below. These values provide the content used by your templates and dynamic layouts.', 'octave-addons' ) : esc_html__( 'Complete the fields below to keep this content consistent across templates and dynamic layouts.', 'octave-addons' ); ?></p>
				</div>
			</div>

			<div class="oa-post-fields-grid">

			<?php

			if ( empty( $fields ) ) :

			?>

			<p class="oa-post-fields-empty"><?php esc_html_e( 'No content fields are assigned yet. Add fields to this post type in Octave Addons, then return here to populate them.', 'octave-addons' ); ?></p>

			<?php

			endif;

			foreach ( $fields as $field ) {

				$this->render_field( $post, $field );

			}

			?>

			</div>
		</div>

		<?php

	}

	/*
	RENDER FIELD
	-- Outputs the appropriate accessible control and media affordances.
	---------------------------------------------------------- */

	protected function render_field( WP_Post $post, array $field ): void {

		$stored      = get_post_meta( $post->ID, $field['meta_key'], true );
		$has_value   = metadata_exists( 'post', $post->ID, $field['meta_key'] );
		$value       = $has_value ? $stored : $field['default_value'];
		$name        = 'octave_post_fields[' . $field['name'] . ']';
		$id          = 'octave_post_field_' . $field['name'];
		$type        = $field['type'];
		$is_wide     = in_array( $type, [ 'textarea', 'wysiwyg' ], true );
		$choices     = $this->parse_choices( $field['choices'] );
		$description = (string) $field['description'];

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
		$is_wide = in_array( $type, [ 'textarea', 'wysiwyg' ], true );

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
			<?php elseif ( in_array( $type, [ 'image', 'file' ], true ) ) :

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

			$raw   = $submitted[ $field['name'] ] ?? ( in_array( $field['type'], [ 'multiselect', 'group', 'repeater' ], true ) ? [] : '' );
			$value = $this->sanitize_value( $raw, $field );

			if ( $this->should_store_value( $value, $field ) ) {

				update_post_meta( $post_id, $field['meta_key'], $value );

			} else {

				delete_post_meta( $post_id, $field['meta_key'] );

			}

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

		if ( in_array( $type, [ 'repeater', 'multiselect' ], true ) ) {

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

			$raw = $row[ $sub_field['name'] ] ?? ( 'multiselect' === $sub_field['type'] ? [] : '' );

			$clean[ $sub_field['name'] ] = $this->sanitize_value( $raw, $sub_field );

		}

		return $clean;

	}

	/*
	ENQUEUE EDITOR ASSETS
	-- Loads the purpose-built field UI only on applicable post editors.
	---------------------------------------------------------- */

	public function enqueue_editor_assets( string $hook ): void {

		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {

			return;

		}

		$screen = get_current_screen();

		if ( ! $screen || ( empty( $this->fields_for_post_type( $screen->post_type ) ) && ! in_array( $screen->post_type, $this->structured_post_types, true ) ) ) {

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
				'useMedia'             => __( 'Use this media', 'octave-addons' ),
				'replace'              => __( 'Replace', 'octave-addons' ),
				'itemLabel'            => __( 'Item %d', 'octave-addons' ),
				'structuredOnly'       => in_array( $screen->post_type, $this->structured_post_types, true ),
				'launcherTitle'        => __( 'This post type uses default fields.', 'octave-addons' ),
				'launcherDescription'  => __( 'Please populate the Octave content fields below.', 'octave-addons' ),
				'launcherButton'       => __( 'Go to content fields', 'octave-addons' ),
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

					return ! empty( $field['enabled'] );

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

			$sub_field['meta_key']      = $parent['meta_key'];
			$sub_field['post_types']    = $parent['post_types'];
			$sub_field['parent_type']   = $parent['type'];
			$sub_field['parent_name']   = $parent['name'];
			$sub_field['dynamic_name'] = $parent['name'] . '_' . $sub_field['name'];
			$sub_field['label']        = $parent['label'] . ' · ' . $sub_field['label'];

			if ( 'image' === $sub_field['type'] && class_exists( 'Octave_Addons_Breakdance_Image_Field', false ) ) {

				\Breakdance\DynamicData\registerField( new Octave_Addons_Breakdance_Image_Field( $sub_field ) );

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
