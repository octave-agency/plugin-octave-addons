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

	/*
	CONSTRUCTOR
	-- Stores normalised definitions and attaches editor and builder hooks.
	---------------------------------------------------------- */

	public function __construct( array $fields, array $post_type_labels ) {

		$this->fields           = $fields;
		$this->post_type_labels = $post_type_labels;

		$this->register_meta();

		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'save_post' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'init', [ $this, 'register_breakdance_fields' ], 20 );

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

				if ( 'multiselect' === $field['type'] ) {

					$args['single']       = true;
					$args['type']         = 'array';
					$args['show_in_rest'] = [
						'schema' => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
					];

				}

				register_post_meta( $post_type, $field['meta_key'], $args );

			}

		}

	}

	/*
	ADD META BOXES
	-- Adds one consistent Octave panel to every assigned custom post type.
	---------------------------------------------------------- */

	public function add_meta_boxes(): void {

		foreach ( array_keys( $this->post_type_labels ) as $post_type ) {

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
	RENDER META BOX
	-- Presents assigned values as a clear, responsive editor form.
	---------------------------------------------------------- */

	public function render_meta_box( WP_Post $post ): void {

		$fields = $this->fields_for_post_type( $post->post_type );

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

			<div class="oa-post-fields-grid">

			<?php

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

			$raw   = $submitted[ $field['name'] ] ?? ( 'multiselect' === $field['type'] ? [] : '' );
			$value = $this->sanitize_value( $raw, $field );

			update_post_meta( $post_id, $field['meta_key'], $value );

		}

	}

	/*
	SANITIZE VALUE
	-- Applies the safest matching WordPress sanitizer for each field type.
	---------------------------------------------------------- */

	protected function sanitize_value( $value, array $field ) {

		$type = $field['type'];

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
	ENQUEUE EDITOR ASSETS
	-- Loads the purpose-built field UI only on applicable post editors.
	---------------------------------------------------------- */

	public function enqueue_editor_assets( string $hook ): void {

		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {

			return;

		}

		$screen = get_current_screen();

		if ( ! $screen || empty( $this->fields_for_post_type( $screen->post_type ) ) ) {

			return;

		}

		wp_enqueue_editor();
		wp_enqueue_media();
		wp_enqueue_style( 'octave-post-fields', OCTAVE_ADDONS_URL . 'modules/custom-post-types/assets/post-fields.css', [], OCTAVE_ADDONS_VERSION );
		wp_enqueue_script( 'octave-post-fields', OCTAVE_ADDONS_URL . 'modules/custom-post-types/assets/post-fields.js', [ 'jquery' ], OCTAVE_ADDONS_VERSION, true );

		wp_localize_script(
			'octave-post-fields',
			'octavePostFields',
			[
				'chooseImage' => __( 'Choose an image', 'octave-addons' ),
				'chooseFile'  => __( 'Choose a file', 'octave-addons' ),
				'useMedia'    => __( 'Use this media', 'octave-addons' ),
				'replace'     => __( 'Replace', 'octave-addons' ),
			]
		);

	}

	/*
	REGISTER BREAKDANCE FIELDS
	-- Creates typed Dynamic Data entries grouped under Octave.
	---------------------------------------------------------- */

	public function register_breakdance_fields(): void {

		if ( ! function_exists( '\\Breakdance\\DynamicData\\registerField' ) || ! class_exists( '\\Breakdance\\DynamicData\\StringField' ) ) {

			return;

		}

		if ( ! class_exists( 'Octave_Addons_Breakdance_String_Field', false ) ) {

			require_once __DIR__ . '/class-breakdance-fields.php';

		}

		foreach ( $this->fields as $field ) {

			if ( empty( $field['enabled'] ) ) {

				continue;

			}

			$field['subcategory'] = $this->field_subcategory( $field );

			if ( 'image' === $field['type'] && class_exists( '\\Breakdance\\DynamicData\\ImageField' ) ) {

				\Breakdance\DynamicData\registerField( new Octave_Addons_Breakdance_Image_Field( $field ) );

			} else {

				\Breakdance\DynamicData\registerField( new Octave_Addons_Breakdance_String_Field( $field ) );

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

	/*
	FIELD SUBCATEGORY
	-- Labels Dynamic Data fields with their assigned content areas.
	---------------------------------------------------------- */

	protected function field_subcategory( array $field ): string {

		$labels = [];

		foreach ( $field['post_types'] as $post_type ) {

			if ( isset( $this->post_type_labels[ $post_type ] ) ) {

				$labels[] = $this->post_type_labels[ $post_type ];

			}

		}

		return implode( ', ', $labels );

	}

}
