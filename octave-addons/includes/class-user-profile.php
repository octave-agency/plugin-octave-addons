<?php

/*
USER PROFILE EXPERIENCE
-- Adds job titles and Media Library avatars to WordPress user profiles.
-- Custom avatar IDs take priority while native Gravatar and fallback handling
-- remain untouched whenever no valid custom image is assigned.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

class Octave_Addons_User_Profile {

	protected const AVATAR_META_KEY    = 'oa_custom_avatar_id';
	protected const JOB_TITLE_META_KEY = 'oa_job_title';

	public function __construct() {

		add_action( 'admin_init',              [ $this, 'remove_colour_scheme_picker' ] );
		add_action( 'show_user_profile',        [ $this, 'render_fields' ] );
		add_action( 'edit_user_profile',        [ $this, 'render_fields' ] );
		add_action( 'personal_options_update',  [ $this, 'save_fields' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save_fields' ] );
		add_action( 'admin_enqueue_scripts',    [ $this, 'enqueue_assets' ] );
		add_filter( 'pre_get_avatar_data',      [ $this, 'filter_avatar_data' ], 10, 2 );

	}

	/*
	REMOVE COLOUR SCHEME PICKER
	-- The custom light and dark control replaces WordPress admin colour schemes.
	---------------------------------------------------------- */

	public function remove_colour_scheme_picker(): void {

		remove_action( 'admin_color_scheme_picker', 'admin_color_scheme_picker' );

	}

	/*
	ENQUEUE PROFILE ASSETS
	-- Loads the native Media Library selector only on user editing screens.
	---------------------------------------------------------- */

	public function enqueue_assets( string $hook ): void {

		if ( ! in_array( $hook, [ 'profile.php', 'user-edit.php' ], true ) ) {

			return;

		}

		$css_path = OCTAVE_ADDONS_DIR . 'assets/css/user-profile.css';
		$js_path  = OCTAVE_ADDONS_DIR . 'assets/js/user-profile.js';

		wp_enqueue_style(
			'octave-addons-user-profile',
			OCTAVE_ADDONS_URL . 'assets/css/user-profile.css',
			[],
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : OCTAVE_ADDONS_VERSION
		);

		if ( ! current_user_can( 'upload_files' ) ) {

			return;

		}

		wp_enqueue_media();

		wp_enqueue_script(
			'octave-addons-user-profile',
			OCTAVE_ADDONS_URL . 'assets/js/user-profile.js',
			[ 'media-editor' ],
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : OCTAVE_ADDONS_VERSION,
			true
		);

	}

	/*
	RENDER PROFILE FIELDS
	-- Shows the job title and attachment-backed avatar controls.
	---------------------------------------------------------- */

	public function render_fields( WP_User $user ): void {

		$avatar_id          = absint( get_user_meta( $user->ID, self::AVATAR_META_KEY, true ) );
		$job_title          = (string) get_user_meta( $user->ID, self::JOB_TITLE_META_KEY, true );
		$custom_avatar_url  = $avatar_id ? wp_get_attachment_image_url( $avatar_id, [ 192, 192 ] ) : false;
		$default_avatar_url = $this->get_native_avatar_url( $user->ID, 192 );
		$preview_url        = $custom_avatar_url ?: $default_avatar_url;
		$can_upload         = current_user_can( 'upload_files' );

		wp_nonce_field( 'oa_save_user_profile', 'oa_user_profile_nonce' );

		?>

		<table class="form-table oa-user-profile-fields" role="presentation">
			<tr>
				<th><label for="oa_job_title"><?= esc_html__( 'Job Title', 'octave-addons' ); ?></label></th>
				<td>
					<input type="text" name="oa_job_title" id="oa_job_title" value="<?= esc_attr( $job_title ); ?>" class="regular-text" autocomplete="organization-title">
				</td>
			</tr>
			<tr>
				<th><span><?= esc_html__( 'Profile Picture', 'octave-addons' ); ?></span></th>
				<td>
					<div
						class="oa-avatar-field"
						data-default-avatar="<?= esc_url( $default_avatar_url ); ?>"
						data-dialog-title="<?= esc_attr__( 'Choose a profile picture', 'octave-addons' ); ?>"
						data-dialog-button="<?= esc_attr__( 'Use as profile picture', 'octave-addons' ); ?>"
						data-select-label="<?= esc_attr__( 'Select image', 'octave-addons' ); ?>"
						data-replace-label="<?= esc_attr__( 'Replace image', 'octave-addons' ); ?>"
					>
						<div class="oa-avatar-preview" aria-live="polite">
							<img src="<?= esc_url( $preview_url ); ?>" alt="<?= esc_attr( sprintf( __( '%s profile picture', 'octave-addons' ), $user->display_name ) ); ?>">
						</div>

						<?php

						if ( $can_upload ) :

						?>

						<input type="hidden" name="oa_custom_avatar_id" value="<?= esc_attr( $avatar_id ); ?>" class="oa-avatar-id">

						<div class="oa-avatar-actions">
							<button type="button" class="button oa-avatar-select"><?= esc_html( $avatar_id ? __( 'Replace image', 'octave-addons' ) : __( 'Select image', 'octave-addons' ) ); ?></button>
							<button type="button" class="button-link-delete oa-avatar-remove<?= $avatar_id ? '' : ' hidden'; ?>"><?= esc_html__( 'Remove custom image', 'octave-addons' ); ?></button>
						</div>

						<?php

						endif;

						?>

					</div>
				</td>
			</tr>
		</table>

		<?php

	}

	/*
	SAVE PROFILE FIELDS
	-- Validates permissions, the nonce and the selected image attachment.
	---------------------------------------------------------- */

	public function save_fields( int $user_id ): void {

		if ( ! current_user_can( 'edit_user', $user_id ) ) {

			return;

		}

		if (
			! isset( $_POST['oa_user_profile_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['oa_user_profile_nonce'] ) ), 'oa_save_user_profile' )
		) {

			return;

		}

		$job_title = isset( $_POST['oa_job_title'] )
			? sanitize_text_field( wp_unslash( $_POST['oa_job_title'] ) )
			: '';

		update_user_meta( $user_id, self::JOB_TITLE_META_KEY, $job_title );

		if ( ! current_user_can( 'upload_files' ) || ! isset( $_POST['oa_custom_avatar_id'] ) ) {

			return;

		}

		$avatar_id = absint( wp_unslash( $_POST['oa_custom_avatar_id'] ) );

		if ( 0 === $avatar_id ) {

			delete_user_meta( $user_id, self::AVATAR_META_KEY );

			return;

		}

		if ( 'attachment' === get_post_type( $avatar_id ) && wp_attachment_is_image( $avatar_id ) ) {

			update_user_meta( $user_id, self::AVATAR_META_KEY, $avatar_id );

		}

	}

	/*
	FILTER AVATAR DATA
	-- Short-circuits the native lookup only for a valid custom attachment.
	---------------------------------------------------------- */

	public function filter_avatar_data( array $args, $id_or_email ): array {

		$user = $this->resolve_user( $id_or_email );

		if ( ! $user ) {

			return $args;

		}

		$avatar_id = absint( get_user_meta( $user->ID, self::AVATAR_META_KEY, true ) );

		if ( ! $avatar_id || 'attachment' !== get_post_type( $avatar_id ) || ! wp_attachment_is_image( $avatar_id ) ) {

			return $args;

		}

		$width  = max( 1, absint( $args['width'] ?? $args['size'] ?? 96 ) );
		$height = max( 1, absint( $args['height'] ?? $args['size'] ?? 96 ) );
		$url    = wp_get_attachment_image_url( $avatar_id, [ $width, $height ] );

		if ( ! $url ) {

			return $args;

		}

		$args['url']          = $url;
		$args['found_avatar'] = true;

		return $args;

	}

	/*
	GET NATIVE AVATAR URL
	-- Gets the Gravatar or fallback URL without applying the custom image.
	---------------------------------------------------------- */

	protected function get_native_avatar_url( int $user_id, int $size ): string {

		remove_filter( 'pre_get_avatar_data', [ $this, 'filter_avatar_data' ], 10 );

		$url = get_avatar_url( $user_id, [
			'size' => $size,
		] );

		add_filter( 'pre_get_avatar_data', [ $this, 'filter_avatar_data' ], 10, 2 );

		return (string) $url;

	}

	/*
	RESOLVE AVATAR USER
	-- Maps the identifiers accepted by WordPress avatar functions to a user.
	---------------------------------------------------------- */

	protected function resolve_user( $id_or_email ): ?WP_User {

		if ( $id_or_email instanceof WP_User ) {

			return $id_or_email;

		}

		if ( $id_or_email instanceof WP_Post ) {

			$user = get_userdata( (int) $id_or_email->post_author );

			return $user instanceof WP_User ? $user : null;

		}

		if ( $id_or_email instanceof WP_Comment ) {

			if ( $id_or_email->user_id ) {

				$user = get_userdata( (int) $id_or_email->user_id );

				return $user instanceof WP_User ? $user : null;

			}

			$id_or_email = $id_or_email->comment_author_email;

		}

		if ( is_numeric( $id_or_email ) ) {

			$user = get_userdata( absint( $id_or_email ) );

			return $user instanceof WP_User ? $user : null;

		}

		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {

			$user = get_user_by( 'email', $id_or_email );

			return $user instanceof WP_User ? $user : null;

		}

		return null;

	}

}
