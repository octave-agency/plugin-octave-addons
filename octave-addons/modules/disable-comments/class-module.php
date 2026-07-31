<?php

/*
MODULE: DISABLE COMMENTS
-- Thoroughly disables the WordPress comments system — support on every
-- post type, the comment UI, the REST endpoints, the admin menus, the
-- admin bar, and any existing comments can optionally be force-closed
-- on every post at query time.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_Module_Disable_Comments extends Octave_Addons_Module {


	public function get_id(): string {

		return 'disable-comments';

	}

	public function get_title(): string {

		return __( 'Disable Comments', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Comprehensively disables comments across the site — comment forms, REST endpoints, admin menus and the admin bar counter.', 'octave-addons' );

	}

	public function get_defaults(): array {
		return [
			'enabled'           => false,
			'disable_everywhere'=> true,   // drop support on every post type
			'disable_rest'      => true,   // block /wp-json/wp/v2/comments
			'hide_admin_menu'   => true,   // remove "Comments" admin menu entry
			'hide_admin_bar'    => true,   // remove comments icon from the admin bar
			'close_existing'    => true,   // filter comments_open / pings_open → false
		];

	}

	public function sanitize( $input ): array {

		$clean = $this->get_defaults();
		foreach ( [ 'enabled', 'disable_everywhere', 'disable_rest', 'hide_admin_menu', 'hide_admin_bar', 'close_existing' ] as $key ) {

			$clean[ $key ] = ! empty( $input[ $key ] );

		}
		return $clean;

	}

	public function render_settings( array $s ): void {

		$rows = [
			'disable_everywhere' => __( 'Remove comment support from all post types (posts, pages, media, CPTs).', 'octave-addons' ),
			'disable_rest'       => __( 'Block the comments REST API endpoint.', 'octave-addons' ),
			'hide_admin_menu'    => __( 'Hide the "Comments" entry in the WordPress admin menu.', 'octave-addons' ),
			'hide_admin_bar'     => __( 'Hide the comment count icon in the admin bar.', 'octave-addons' ),
			'close_existing'     => __( 'Force comments and pingbacks closed on every post at query time.', 'octave-addons' ),
		];
		?>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			foreach ( $rows as $key => $label ) :
				Octave_Addons_Fields::row( [
					'label' => self::label_for( $key ),
					'field' => function () use ( $key, $label, $s ) {

						Octave_Addons_Fields::switch_field( [
							'name'    => $this->field_name( $key ),
							'checked' => ! empty( $s[ $key ] ),
							'help'    => $label,
						] );

					},
				] );
			endforeach;

			?>

		</table>
		<?php

	}

	protected static function label_for( string $key ): string {

		$labels = [
			'disable_everywhere' => __( 'All post types', 'octave-addons' ),
			'disable_rest'       => __( 'REST API', 'octave-addons' ),
			'hide_admin_menu'    => __( 'Admin menu', 'octave-addons' ),
			'hide_admin_bar'     => __( 'Admin bar', 'octave-addons' ),
			'close_existing'     => __( 'Existing posts', 'octave-addons' ),
		];
		return $labels[ $key ] ?? $key;

	}

	public function run( array $s ): void {


		// Remove comment support from post types.
		if ( $s['disable_everywhere'] ) {

			add_action( 'init', function () {

				foreach ( get_post_types() as $pt ) {

					if ( post_type_supports( $pt, 'comments' ) ) {

						remove_post_type_support( $pt, 'comments' );
						remove_post_type_support( $pt, 'trackbacks' );

					}

				}
			}, 100 );

		}

		// Force comments/pings closed on every post.
		if ( $s['close_existing'] ) {

			add_filter( 'comments_open', '__return_false', 20, 2 );
			add_filter( 'pings_open',    '__return_false', 20, 2 );
			// Hide existing comments from templates.
			add_filter( 'comments_array', '__return_empty_array', 10, 2 );

		}

		// Kill the comments REST endpoint.
		if ( $s['disable_rest'] ) {

			add_filter( 'rest_endpoints', function ( array $endpoints ) {

				foreach ( array_keys( $endpoints ) as $route ) {

					if ( 0 === strpos( $route, '/wp/v2/comments' ) ) {

						unset( $endpoints[ $route ] );

					}

				}
				return $endpoints;
			} );

		}

		// Hide "Comments" from the admin sidebar.
		if ( $s['hide_admin_menu'] ) {

			add_action( 'admin_menu', function () {

				remove_menu_page( 'edit-comments.php' );
			} );

			// Also redirect anyone who hits /wp-admin/edit-comments.php directly.
			add_action( 'admin_init', function () {

				global $pagenow;
				if ( 'edit-comments.php' === $pagenow ) {

					wp_safe_redirect( admin_url() );
					exit;

				}

				// Remove the dashboard "Recent Comments" widget.
				remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
			} );

		}

		// Remove the admin bar icon.
		if ( $s['hide_admin_bar'] ) {

			add_action( 'wp_before_admin_bar_render', function () {

				global $wp_admin_bar;
				if ( $wp_admin_bar ) {

					$wp_admin_bar->remove_menu( 'comments' );

				}
			} );

		}

	}

}

return new Octave_Addons_Module_Disable_Comments();
