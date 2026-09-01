<?php

/*
MODULE: FEATURED IMAGE COLUMN
-- Adds a featured image thumbnail column to every admin post list table, so a
-- library of posts can be scanned by image rather than by title alone.
-- Covers every post type that declares thumbnail support, and sits directly
-- after the date column, ahead of any SEO plugin's own columns.
-- Hovering a cell reveals controls to swap the image through the media
-- library or clear it, both applied over AJAX without leaving the table.
-- Always on and hidden from the admin — there is nothing to configure.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Featured_Image_Column extends Octave_Addons_Module {

	/** Column key added to each list table. */
	const COLUMN = 'oa_featured_image';

	/** Action and nonce name for the inline image updates. */
	const ACTION = 'oa_featured_image_update';

	/** Rendered thumbnail edge, in pixels. */
	protected const SIZE = 60;

	/** Registered image size the thumbnail is loaded at. */
	protected const IMAGE_SIZE = 'thumbnail';

	/*
	SEO COLUMN PREFIXES
	-- Column keys belonging to an SEO plugin. The thumbnail is placed before
	-- the first of them, so the run of SEO columns is left intact at the end
	-- of the row wherever the plugin chose to add them.
	---------------------------------------------------------- */

	protected const SEO_PREFIXES = [ 'rank_math', 'wpseo', 'aioseo', 'seopress' ];


	public function get_id(): string {

		return 'featured-image-column';

	}

	public function get_title(): string {

		return __( 'Featured Image Column', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Shows a featured image thumbnail column in every admin post table, with hover controls to swap or clear the image in place.', 'octave-addons' );

	}

	/*
	SHOW IN ADMIN
	-- Hidden: the column is the same on every site, so there is nothing to
	-- present on the settings screen.
	---------------------------------------------------------- */

	public function show_in_admin(): bool {

		return false;

	}

	/*
	IS ALWAYS ENABLED
	-- Runs regardless of saved settings.
	---------------------------------------------------------- */

	public function is_always_enabled(): bool {

		return true;

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

			$types[] = $post_type->name;

		}

		return $types;

	}

	public function run( array $s ): void {

		if ( ! is_admin() ) {

			return;

		}

		add_action( 'admin_init', [ $this, 'register_columns' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'ajax_update_thumbnail' ] );

	}

	/*
	REGISTER COLUMNS
	-- Hooks every eligible post type. The per-post-type filters cover pages and
	-- custom post types as well as posts, so one pair of hooks is enough.
	-- A late priority gives an SEO plugin the chance to add its own columns
	-- first, so they are already present when the order is worked out.
	---------------------------------------------------------- */

	public function register_columns(): void {

		foreach ( $this->eligible_post_types() as $post_type ) {

			add_filter( "manage_{$post_type}_posts_columns", [ $this, 'add_column' ], 20 );
			add_action( "manage_{$post_type}_posts_custom_column", [ $this, 'render_column' ], 10, 2 );

		}

	}

	/*
	ADD COLUMN
	-- Places the thumbnail immediately after the date column, and before the
	-- first SEO column when a plugin has put one ahead of the date. Falls back
	-- to the end of the row for a table carrying neither.
	---------------------------------------------------------- */

	public function add_column( $columns ): array {

		$columns = is_array( $columns ) ? $columns : [];
		$label   = __( 'Image', 'octave-addons' );
		$ordered = [];
		$placed  = false;

		foreach ( $columns as $key => $value ) {

			if ( ! $placed && $this->is_seo_column( (string) $key ) ) {

				$ordered[ self::COLUMN ] = $label;
				$placed                  = true;

			}

			$ordered[ $key ] = $value;

			if ( ! $placed && 'date' === $key ) {

				$ordered[ self::COLUMN ] = $label;
				$placed                  = true;

			}

		}

		if ( ! $placed ) {

			$ordered[ self::COLUMN ] = $label;

		}

		return $ordered;

	}

	protected function is_seo_column( string $key ): bool {

		foreach ( self::SEO_PREFIXES as $prefix ) {

			if ( 0 === strpos( $key, $prefix ) ) {

				return true;

			}

		}

		return false;

	}

	public function render_column( $column, $post_id ): void {

		if ( self::COLUMN !== $column ) {

			return;

		}

		echo $this->cell_html( (int) $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in cell_html().

	}

	/*
	CELL HTML
	-- One complete cell, used both when the table is drawn and when an update
	-- comes back over AJAX, so the two can never drift apart.
	-- The hover controls are only drawn for someone who may edit the post.
	---------------------------------------------------------- */

	protected function cell_html( int $post_id ): string {

		$can_edit  = current_user_can( 'edit_post', $post_id );
		$title     = get_the_title( $post_id );
		$thumbnail = has_post_thumbnail( $post_id )
			? get_the_post_thumbnail( $post_id, self::IMAGE_SIZE, [ 'class' => 'oa-fic__thumb', 'loading' => 'lazy' ] )
			: '';

		$classes = 'oa-fic' . ( '' === $thumbnail ? ' is-empty' : '' );

		$html = sprintf(
			'<div class="%1$s" data-post-id="%2$d">',
			esc_attr( $classes ),
			$post_id
		);

		$html .= $this->image_html( $post_id, $thumbnail, $title, $can_edit );

		if ( $can_edit ) {

			$html .= $this->actions_html( $title, '' !== $thumbnail );

		}

		return $html . '</div>';

	}

	/*
	IMAGE HTML
	-- The thumbnail itself, linked to the post editor when the current user can
	-- open it, or the dashed outline standing in for a post with no image.
	---------------------------------------------------------- */

	protected function image_html( int $post_id, string $thumbnail, string $title, bool $can_edit ): string {

		if ( '' === $thumbnail ) {

			return sprintf(
				'<span class="oa-fic__empty" aria-label="%s"></span>',
				esc_attr__( 'No featured image', 'octave-addons' )
			);

		}

		$edit_link = $can_edit ? get_edit_post_link( $post_id ) : '';

		if ( ! $edit_link ) {

			return $thumbnail;

		}

		return sprintf(
			'<a href="%1$s" class="oa-fic__link" aria-label="%2$s">%3$s</a>',
			esc_url( $edit_link ),
			/* translators: %s: post title. */
			esc_attr( sprintf( __( 'Edit %s', 'octave-addons' ), $title ) ),
			$thumbnail
		);

	}

	/*
	ACTIONS HTML
	-- The hover controls. Remove is only offered when there is an image to
	-- take away, so the control set always matches the state of the cell.
	---------------------------------------------------------- */

	protected function actions_html( string $title, bool $has_image ): string {

		$html = '<div class="oa-fic__actions">';

		$html .= sprintf(
			'<button type="button" class="oa-fic__action oa-fic__action--edit" data-oa-fic-action="edit" aria-label="%1$s" title="%2$s"><span class="dashicons dashicons-edit" aria-hidden="true"></span></button>',
			esc_attr(
				$has_image
					/* translators: %s: post title. */
					? sprintf( __( 'Change the featured image for %s', 'octave-addons' ), $title )
					/* translators: %s: post title. */
					: sprintf( __( 'Set a featured image for %s', 'octave-addons' ), $title )
			),
			$has_image ? esc_attr__( 'Change image', 'octave-addons' ) : esc_attr__( 'Set image', 'octave-addons' )
		);

		if ( $has_image ) {

			$html .= sprintf(
				'<button type="button" class="oa-fic__action oa-fic__action--remove" data-oa-fic-action="remove" aria-label="%1$s" title="%2$s"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>',
				/* translators: %s: post title. */
				esc_attr( sprintf( __( 'Remove the featured image from %s', 'octave-addons' ), $title ) ),
				esc_attr__( 'Remove image', 'octave-addons' )
			);

		}

		return $html . '</div>';

	}

	/*
	ENQUEUE ASSETS
	-- Loads on the post list tables only, and only for a post type carrying the
	-- column. The media library is pulled in because the edit control opens it.
	---------------------------------------------------------- */

	public function enqueue_assets( $hook ): void {

		if ( 'edit.php' !== $hook ) {

			return;

		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->post_type, $this->eligible_post_types(), true ) ) {

			return;

		}

		$base_dir = OCTAVE_ADDONS_DIR . 'modules/featured-image-column/assets/';
		$base_url = OCTAVE_ADDONS_URL . 'modules/featured-image-column/assets/';

		$css_path = $base_dir . 'featured-image-column.css';
		$js_path  = $base_dir . 'featured-image-column.js';

		wp_enqueue_style(
			'octave-featured-image-column',
			$base_url . 'featured-image-column.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : OCTAVE_ADDONS_VERSION
		);

		wp_add_inline_style(
			'octave-featured-image-column',
			sprintf( ':root{--oa-fic-size:%dpx;}', self::SIZE )
		);

		if ( ! current_user_can( 'edit_posts' ) ) {

			return;

		}

		wp_enqueue_media();

		wp_enqueue_script(
			'octave-featured-image-column',
			$base_url . 'featured-image-column.js',
			[],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : OCTAVE_ADDONS_VERSION,
			true
		);

		wp_localize_script( 'octave-featured-image-column', 'oaFeaturedImage', [
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'action'        => self::ACTION,
			'nonce'         => wp_create_nonce( self::ACTION ),
			'frameTitle'    => __( 'Choose a featured image', 'octave-addons' ),
			'frameButton'   => __( 'Set featured image', 'octave-addons' ),
			'confirmRemove' => __( 'Remove the featured image from this entry?', 'octave-addons' ),
			'errorText'     => __( 'The featured image could not be updated. Please reload the page and try again.', 'octave-addons' ),
		] );

	}

	/*
	AJAX UPDATE THUMBNAIL
	-- Sets or clears one post's featured image and hands back the rebuilt cell,
	-- so the table shows exactly what the next page load would.
	-- An attachment id of zero is the request to clear the image.
	---------------------------------------------------------- */

	public function ajax_update_thumbnail(): void {

		check_ajax_referer( self::ACTION, 'nonce' );

		$post_id       = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$post          = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || ! in_array( $post->post_type, $this->eligible_post_types(), true ) ) {

			wp_send_json_error( [ 'message' => __( 'That entry cannot carry a featured image.', 'octave-addons' ) ], 400 );

		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {

			wp_send_json_error( [ 'message' => __( 'You are not allowed to edit this entry.', 'octave-addons' ) ], 403 );

		}

		if ( 0 === $attachment_id ) {

			delete_post_thumbnail( $post_id );

			wp_send_json_success( [ 'html' => $this->cell_html( $post_id ) ] );

		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {

			wp_send_json_error( [ 'message' => __( 'That file is not an image.', 'octave-addons' ) ], 400 );

		}

		set_post_thumbnail( $post_id, $attachment_id );

		wp_send_json_success( [ 'html' => $this->cell_html( $post_id ) ] );

	}

}

return new Octave_Addons_Module_Featured_Image_Column();
