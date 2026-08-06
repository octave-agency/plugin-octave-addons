<?php

/*
SITE STATUS PAGES
-- Brands WordPress maintenance and critical-error screens.
-- Keeps both drop-ins self-contained so they work before plugins load.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

final class Octave_Addons_Site_Status {

	private const PREVIEW_PARAMETER = 'octave-status-preview';
	private const DROP_IN_MARKER    = 'OCTAVE ADDONS MANAGED STATUS PAGE';
	private const MAX_LOGO_BYTES    = 524288;

	private array $drop_in_errors = [];

	public function __construct() {

		add_action( 'template_redirect', [ $this, 'maybe_render_preview' ], 0 );
		add_action( 'admin_init', [ $this, 'sync_status_drop_ins' ] );
		add_action( 'admin_notices', [ $this, 'render_drop_in_notice' ] );

	}

	/*
	INSTALL STATUS DROP-INS
	-- Creates WordPress's early-loading status files without replacing a
	-- drop-in owned elsewhere.
	---------------------------------------------------------- */

	public static function install_maintenance_drop_in() {

		return self::install_drop_in( 'maintenance.php', 'maintenance' );

	}

	public static function install_php_error_drop_in() {

		return self::install_drop_in( 'php-error.php', 'critical-error' );

	}

	private static function install_drop_in( string $filename, string $type ) {

		$target  = WP_CONTENT_DIR . '/' . $filename;
		$content = self::build_drop_in( $type );

		if ( is_wp_error( $content ) ) {

			return $content;

		}

		if ( file_exists( $target ) ) {

			$current = @file_get_contents( $target );

			if ( false === $current || false === strpos( $current, self::DROP_IN_MARKER ) ) {

				return new WP_Error(
					'octave_addons_status_conflict',
					sprintf(
						/* translators: %s: WordPress drop-in filename. */
						__( 'Octave Addons did not replace the existing wp-content/%s file because it is managed elsewhere.', 'octave-addons' ),
						$filename
					)
				);

			}

			if ( hash_equals( hash( 'sha256', $current ), hash( 'sha256', $content ) ) ) {

				return true;

			}

		}

		$temporary = $target . '.octave-addons.tmp';
		$written   = @file_put_contents( $temporary, $content, LOCK_EX );

		if ( false === $written || strlen( $content ) !== $written || ! @rename( $temporary, $target ) ) {

			if ( file_exists( $temporary ) ) {

				@unlink( $temporary );

			}

			return new WP_Error(
				'octave_addons_status_write_failed',
				sprintf(
					/* translators: %s: WordPress drop-in filename. */
					__( 'Octave Addons could not create wp-content/%s. Check that the wp-content directory is writable.', 'octave-addons' ),
					$filename
				)
			);

		}

		return true;

	}

	/*
	REMOVE STATUS DROP-INS
	-- Removes only files carrying the Octave ownership marker.
	---------------------------------------------------------- */

	public static function remove_status_drop_ins(): void {

		self::remove_drop_in( 'maintenance.php' );
		self::remove_drop_in( 'php-error.php' );

	}

	private static function remove_drop_in( string $filename ): void {

		$target = WP_CONTENT_DIR . '/' . $filename;

		if ( ! file_exists( $target ) ) {

			return;

		}

		$content = @file_get_contents( $target );

		if ( false !== $content && false !== strpos( $content, self::DROP_IN_MARKER ) ) {

			@unlink( $target );

		}

	}

	/*
	SYNC STATUS DROP-INS
	-- Refreshes embedded site branding after a logo or site-name change.
	---------------------------------------------------------- */

	public function sync_status_drop_ins(): void {

		if ( ! current_user_can( 'manage_options' ) ) {

			return;

		}

		$results = [
			self::install_maintenance_drop_in(),
			self::install_php_error_drop_in(),
		];

		$this->drop_in_errors = array_values( array_filter( $results, 'is_wp_error' ) );

	}

	public function render_drop_in_notice(): void {

		if ( empty( $this->drop_in_errors ) ) {

			return;

		}

		foreach ( $this->drop_in_errors as $drop_in_error ) :

		?>

		<div class="notice notice-warning">
			<p><?= esc_html( $drop_in_error->get_error_message() ); ?></p>
		</div>

		<?php

		endforeach;

	}

	/*
	STATUS PREVIEW
	-- Shows either page to administrators without changing site state.
	-- Example: ?octave-status-preview=maintenance
	---------------------------------------------------------- */

	public function maybe_render_preview(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only administrator preview.
		$preview = isset( $_GET[ self::PREVIEW_PARAMETER ] )
			? sanitize_key( wp_unslash( $_GET[ self::PREVIEW_PARAMETER ] ) )
			: '';

		if ( ! in_array( $preview, [ 'maintenance', 'critical-error' ], true ) || ! current_user_can( 'manage_options' ) ) {

			return;

		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$this->render_status_page( $preview, true );
		exit;

	}

	private function render_status_page( string $type, bool $is_preview = false ): void {

		$octave_status_type      = $type;
		$octave_status_site_name = self::site_name();
		$octave_status_home_url  = home_url( '/' );
		$octave_status_logo      = self::logo_data_uri();
		$octave_status_preview   = $is_preview;

		include OCTAVE_ADDONS_DIR . 'templates/site-status.php';

	}

	private static function build_drop_in( string $type ) {

		$template_path = OCTAVE_ADDONS_DIR . 'templates/site-status.php';
		$template      = @file_get_contents( $template_path );

		if ( false === $template ) {

			return new WP_Error(
				'octave_addons_status_template_missing',
				__( 'The Octave site-status template could not be read.', 'octave-addons' )
			);

		}

		$variables  = "<?php\n\n/* " . self::DROP_IN_MARKER . " */\n\n";
		$variables .= '$octave_status_type = ' . var_export( $type, true ) . ";\n";
		$variables .= '$octave_status_site_name = ' . var_export( self::site_name(), true ) . ";\n";
		$variables .= '$octave_status_home_url = ' . var_export( home_url( '/' ), true ) . ";\n";
		$variables .= '$octave_status_logo = ' . var_export( self::logo_data_uri(), true ) . ";\n";
		$variables .= '$octave_status_preview = false;' . "\n\n?>\n\n";

		return $variables . $template;

	}

	private static function site_name(): string {

		$name = trim( (string) get_bloginfo( 'name' ) );

		return '' !== $name ? $name : __( 'Our website', 'octave-addons' );

	}

	/*
	LOGO DATA URI
	-- Embeds a reasonably sized custom logo so it remains available while the
	-- plugin directory is temporarily moved during an update.
	---------------------------------------------------------- */

	private static function logo_data_uri(): string {

		$logo_path = '';
		$logo_id   = (int) get_theme_mod( 'custom_logo', 0 );

		if ( $logo_id ) {

			$attached_file = get_attached_file( $logo_id );

			if ( is_string( $attached_file ) ) {

				$logo_path = $attached_file;

			}

		}

		$logo_size = '' !== $logo_path && is_readable( $logo_path ) ? @filesize( $logo_path ) : false;

		if ( false === $logo_size || $logo_size > self::MAX_LOGO_BYTES ) {

			$logo_path = OCTAVE_ADDONS_DIR . 'assets/admin-icon.png';

		}

		$mime_types = [
			'avif' => 'image/avif',
			'gif'  => 'image/gif',
			'jpeg' => 'image/jpeg',
			'jpg'  => 'image/jpeg',
			'png'  => 'image/png',
			'svg'  => 'image/svg+xml',
			'webp' => 'image/webp',
		];
		$extension  = strtolower( pathinfo( $logo_path, PATHINFO_EXTENSION ) );
		$mime_type  = $mime_types[ $extension ] ?? '';

		if ( '' === $mime_type ) {

			$logo_path = OCTAVE_ADDONS_DIR . 'assets/admin-icon.png';
			$mime_type = 'image/png';

		}

		$image = @file_get_contents( $logo_path );

		return false !== $image ? 'data:' . $mime_type . ';base64,' . base64_encode( $image ) : '';

	}

}
