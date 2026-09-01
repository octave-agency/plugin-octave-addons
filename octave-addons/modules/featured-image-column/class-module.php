<?php

/*
MODULE: FEATURED IMAGE COLUMN
-- Adds a featured image thumbnail column to the admin post list tables, so a
-- library of posts can be scanned by image rather than by title alone.
-- Only post types that declare thumbnail support are offered or hooked.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Featured_Image_Column extends Octave_Addons_Module {

	/** Column key added to each list table. */
	const COLUMN = 'oa_featured_image';

	/** Where the column may sit relative to the title column. */
	const POSITIONS = [ 'before_title', 'after_title', 'end' ];

	/** Settings the current request runs with, shared with the column callbacks. */
	protected array $settings = [];

	public function get_id(): string {

		return 'featured-image-column';

	}

	public function get_title(): string {

		return __( 'Featured Image Column', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Shows a featured image thumbnail column in the admin post tables, so entries can be scanned by image and missing images are obvious.', 'octave-addons' );

	}

	public function get_defaults(): array {

		return [
			'enabled'          => false,
			'post_types'       => [],             // empty = every post type supporting thumbnails
			'position'         => 'before_title', // before_title | after_title | end
			'label'            => '',             // empty = "Image"
			'size'             => 'thumbnail',    // registered image size used for the <img>
			'width'            => 60,             // rendered column width in pixels
			'link_to_edit'     => true,           // wrap the thumbnail in the post edit link
			'show_placeholder' => true,           // draw an outline when a post has no image
		];

	}

	/*
	SANITIZE
	-- Keeps the stored post types and image size to values the site actually
	-- registers, so a renamed post type or removed size cannot persist.
	---------------------------------------------------------- */

	public function sanitize( $input ): array {

		$clean = $this->get_defaults();
		$input = is_array( $input ) ? $input : [];

		$clean['enabled']          = ! empty( $input['enabled'] );
		$clean['link_to_edit']     = ! empty( $input['link_to_edit'] );
		$clean['show_placeholder'] = ! empty( $input['show_placeholder'] );

		$eligible          = array_keys( $this->eligible_post_types() );
		$submitted         = is_array( $input['post_types'] ?? null ) ? $input['post_types'] : [];
		$clean['post_types'] = array_values( array_intersect( $eligible, array_map( 'sanitize_key', $submitted ) ) );

		$clean['position'] = in_array( $input['position'] ?? '', self::POSITIONS, true )
			? $input['position'] : 'before_title';

		$size           = sanitize_key( $input['size'] ?? '' );
		$clean['size']  = in_array( $size, array_keys( $this->image_sizes() ), true ) ? $size : 'thumbnail';

		$clean['label'] = sanitize_text_field( $input['label'] ?? '' );
		$clean['width'] = min( 200, max( 30, absint( $input['width'] ?? 60 ) ) );

		return $clean;

	}

	/*
	ELIGIBLE POST TYPES
	-- The post types a thumbnail column can be added to: anything with an admin
	-- UI and thumbnail support, minus attachments, which have their own table.
	---------------------------------------------------------- */

	protected function eligible_post_types(): array {

		$types = [];

		foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $post_type ) {

			if ( 'attachment' === $post_type->name || ! post_type_supports( $post_type->name, 'thumbnail' ) ) {

				continue;

			}

			$types[ $post_type->name ] = $post_type->labels->name ?? $post_type->name;

		}

		return $types;

	}

	/*
	ACTIVE POST TYPES
	-- Resolves the saved selection, treating an empty selection as every
	-- eligible post type so the module is useful the moment it is switched on.
	---------------------------------------------------------- */

	protected function active_post_types( array $settings ): array {

		$eligible = array_keys( $this->eligible_post_types() );
		$saved    = is_array( $settings['post_types'] ?? null ) ? $settings['post_types'] : [];

		if ( empty( $saved ) ) {

			return $eligible;

		}

		return array_values( array_intersect( $eligible, $saved ) );

	}

	/*
	IMAGE SIZES
	-- Registered sizes the thumbnail can be rendered at, labelled with their
	-- dimensions so a size that is far too large to sit in a table is obvious.
	---------------------------------------------------------- */

	protected function image_sizes(): array {

		$sizes = [];

		foreach ( get_intermediate_image_sizes() as $size ) {

			$sizes[ $size ] = $size;

		}

		if ( ! isset( $sizes['thumbnail'] ) ) {

			$sizes = array_merge( [ 'thumbnail' => 'thumbnail' ], $sizes );

		}

		ksort( $sizes );

		return $sizes;

	}

	/*
	COLUMN LABEL
	-- Falls back to a neutral heading when no custom label is stored.
	---------------------------------------------------------- */

	protected function column_label( array $settings ): string {

		$label = trim( (string) ( $settings['label'] ?? '' ) );

		return '' !== $label ? $label : __( 'Image', 'octave-addons' );

	}

	public function render_settings( array $s ): void {

		$eligible = $this->eligible_post_types();
		$active   = is_array( $s['post_types'] ?? null ) ? $s['post_types'] : [];
		$sizes    = $this->image_sizes();

		$positions = [
			'before_title' => __( 'Before the title', 'octave-addons' ),
			'after_title'  => __( 'After the title', 'octave-addons' ),
			'end'          => __( 'At the end of the row', 'octave-addons' ),
		];

		?>

		<table class="form-table oa-form-table" role="presentation">

			<?php

			Octave_Addons_Fields::row( [
				'label' => __( 'Post types', 'octave-addons' ),
				'field' => function () use ( $eligible, $active ) {

					?>

					<fieldset>
						<div class="oa-assignment-grid">

							<?php

							if ( empty( $eligible ) ) :

							?>

							<p class="oa-assignment-empty"><?php esc_html_e( 'No post type on this site supports featured images.', 'octave-addons' ); ?></p>

							<?php

							endif;

							foreach ( $eligible as $post_type => $label ) :

							?>

							<label class="oa-assignment-option">
								<input type="checkbox" name="<?= esc_attr( $this->field_name( 'post_types' ) ); ?>[]" value="<?= esc_attr( $post_type ); ?>"<?= checked( in_array( $post_type, $active, true ), true, false ); ?>>
								<span class="oa-assignment-check" aria-hidden="true"></span>
								<span class="oa-assignment-copy"><strong><?= esc_html( $label ); ?></strong><small><?= esc_html( $post_type ); ?></small></span>
							</label>

							<?php

							endforeach;

							?>

						</div>
					</fieldset>
					<span class="oa-help"><?php esc_html_e( 'Only post types that support featured images are listed. Leave every box clear to cover all of them.', 'octave-addons' ); ?></span>

					<?php

				},
			] );

			Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'position' ),
				'label' => __( 'Column position', 'octave-addons' ),
				'field' => function () use ( $s, $positions ) {

					?>

					<select id="<?= esc_attr( $this->field_id( 'position' ) ); ?>" name="<?= esc_attr( $this->field_name( 'position' ) ); ?>">

						<?php

						foreach ( $positions as $value => $label ) :

						?>

						<option value="<?= esc_attr( $value ); ?>"<?php selected( $s['position'], $value ); ?>><?= esc_html( $label ); ?></option>

						<?php

						endforeach;

						?>

					</select>
					<span class="oa-help"><?php esc_html_e( 'Where the thumbnail sits in the row. Before the title keeps it next to the checkbox.', 'octave-addons' ); ?></span>

					<?php

				},
			] );

			Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'label' ),
				'label' => __( 'Column heading', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::text( [
						'id'          => $this->field_id( 'label' ),
						'name'        => $this->field_name( 'label' ),
						'value'       => $s['label'],
						'placeholder' => __( 'Image', 'octave-addons' ),
						'class'       => 'regular-text',
						'help'        => __( 'Heading shown above the column. Leave empty for "Image".', 'octave-addons' ),
					] );

				},
			] );

			Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'size' ),
				'label' => __( 'Image size', 'octave-addons' ),
				'field' => function () use ( $s, $sizes ) {

					?>

					<select id="<?= esc_attr( $this->field_id( 'size' ) ); ?>" name="<?= esc_attr( $this->field_name( 'size' ) ); ?>">

						<?php

						foreach ( $sizes as $value => $label ) :

						?>

						<option value="<?= esc_attr( $value ); ?>"<?php selected( $s['size'], $value ); ?>><?= esc_html( $label ); ?></option>

						<?php

						endforeach;

						?>

					</select>
					<span class="oa-help"><?php esc_html_e( 'Registered size the thumbnail is loaded at. Thumbnail keeps the table light.', 'octave-addons' ); ?></span>

					<?php

				},
			] );

			Octave_Addons_Fields::row( [
				'for'   => $this->field_id( 'width' ),
				'label' => __( 'Column width', 'octave-addons' ),
				'field' => function () use ( $s ) {

					?>

					<input type="number" id="<?= esc_attr( $this->field_id( 'width' ) ); ?>" name="<?= esc_attr( $this->field_name( 'width' ) ); ?>" value="<?= esc_attr( (string) $s['width'] ); ?>" min="30" max="200" step="1" class="small-text">
					<span class="oa-help"><?php esc_html_e( 'Width of the rendered thumbnail in pixels, between 30 and 200.', 'octave-addons' ); ?></span>

					<?php

				},
			] );

			Octave_Addons_Fields::row( [
				'label' => __( 'Link to editor', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'name'    => $this->field_name( 'link_to_edit' ),
						'checked' => ! empty( $s['link_to_edit'] ),
						'help'    => __( 'Make the thumbnail open the post for editing, the same as clicking its title.', 'octave-addons' ),
					] );

				},
			] );

			Octave_Addons_Fields::row( [
				'label' => __( 'Missing images', 'octave-addons' ),
				'field' => function () use ( $s ) {

					Octave_Addons_Fields::switch_field( [
						'name'    => $this->field_name( 'show_placeholder' ),
						'checked' => ! empty( $s['show_placeholder'] ),
						'help'    => __( 'Draw an empty outline for posts with no featured image, rather than leaving the cell blank.', 'octave-addons' ),
					] );

				},
			] );

			?>

		</table>

		<?php

	}

	public function run( array $s ): void {

		if ( ! is_admin() ) {

			return;

		}

		$this->settings = $s;

		add_action( 'admin_init', [ $this, 'register_columns' ] );
		add_action( 'admin_head-edit.php', [ $this, 'print_styles' ] );

	}

	/*
	REGISTER COLUMNS
	-- Hooks each selected post type. The per-post-type filters cover pages and
	-- custom post types as well as posts, so one pair of hooks is enough.
	---------------------------------------------------------- */

	public function register_columns(): void {

		foreach ( $this->active_post_types( $this->settings ) as $post_type ) {

			add_filter( "manage_{$post_type}_posts_columns", [ $this, 'add_column' ] );
			add_action( "manage_{$post_type}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );

		}

	}

	/*
	ADD COLUMN
	-- Rebuilds the column map so the thumbnail can be placed around the title
	-- column, which array_merge alone cannot do without losing the order.
	---------------------------------------------------------- */

	public function add_column( $columns ): array {

		$columns  = is_array( $columns ) ? $columns : [];
		$position = $this->settings['position'] ?? 'before_title';
		$label    = $this->column_label( $this->settings );

		if ( 'end' === $position || ! isset( $columns['title'] ) ) {

			$columns[ self::COLUMN ] = $label;

			return $columns;

		}

		$ordered = [];

		foreach ( $columns as $key => $value ) {

			if ( 'title' === $key && 'before_title' === $position ) {

				$ordered[ self::COLUMN ] = $label;

			}

			$ordered[ $key ] = $value;

			if ( 'title' === $key && 'after_title' === $position ) {

				$ordered[ self::COLUMN ] = $label;

			}

		}

		return $ordered;

	}

	/*
	RENDER COLUMN
	-- Prints one cell. The thumbnail is sized by CSS rather than by the
	-- requested size so a large registered size still fits the table.
	---------------------------------------------------------- */

	public function render_column( $column, $post_id ): void {

		if ( self::COLUMN !== $column ) {

			return;

		}

		$post_id   = (int) $post_id;
		$size      = $this->settings['size'] ?? 'thumbnail';
		$thumbnail = has_post_thumbnail( $post_id )
			? get_the_post_thumbnail( $post_id, $size, [ 'class' => 'oa-featured-image-thumb', 'loading' => 'lazy' ] )
			: '';

		if ( '' === $thumbnail ) {

			if ( ! empty( $this->settings['show_placeholder'] ) ) {

				printf(
					'<span class="oa-featured-image-empty" aria-label="%s"></span>',
					esc_attr__( 'No featured image', 'octave-addons' )
				);

			} else {

				echo '<span aria-hidden="true">&mdash;</span>';

			}

			return;

		}

		$edit_link = ! empty( $this->settings['link_to_edit'] ) ? get_edit_post_link( $post_id ) : '';

		if ( $edit_link ) {

			printf(
				'<a href="%1$s" class="oa-featured-image-link" aria-label="%2$s">%3$s</a>',
				esc_url( $edit_link ),
				/* translators: %s: post title. */
				esc_attr( sprintf( __( 'Edit %s', 'octave-addons' ), get_the_title( $post_id ) ) ),
				$thumbnail
			);

			return;

		}

		echo $thumbnail;

	}

	/*
	PRINT STYLES
	-- Sizes the column on the list tables only. The width is a saved number, so
	-- the rule is built from it directly rather than enqueuing a stylesheet.
	---------------------------------------------------------- */

	public function print_styles(): void {

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->post_type, $this->active_post_types( $this->settings ), true ) ) {

			return;

		}

		$width  = (int) ( $this->settings['width'] ?? 60 );
		$column = self::COLUMN;

		?>

		<style id="oa-featured-image-column">
			.wp-list-table th.column-<?= esc_html( $column ); ?> { width: <?= (int) ( $width + 16 ); ?>px; }
			.wp-list-table td.column-<?= esc_html( $column ); ?> { width: <?= (int) ( $width + 16 ); ?>px; }
			.oa-featured-image-thumb {
				display: block;
				width: <?= $width; ?>px;
				height: <?= $width; ?>px;
				object-fit: cover;
				border-radius: 4px;
				background: rgba(0, 0, 0, 0.04);
			}
			.oa-featured-image-link { display: inline-block; line-height: 0; }
			.oa-featured-image-empty {
				display: block;
				width: <?= $width; ?>px;
				height: <?= $width; ?>px;
				border: 1px dashed rgba(0, 0, 0, 0.18);
				border-radius: 4px;
			}
			@media screen and (max-width: 782px) {
				.wp-list-table th.column-<?= esc_html( $column ); ?>,
				.wp-list-table td.column-<?= esc_html( $column ); ?> { width: auto; }
			}
		</style>

		<?php

	}

}

return new Octave_Addons_Module_Featured_Image_Column();
