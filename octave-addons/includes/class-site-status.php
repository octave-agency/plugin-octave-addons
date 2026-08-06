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

	private const DROP_IN_MARKER = 'OCTAVE ADDONS MANAGED STATUS PAGE';
	private const MAX_LOGO_BYTES = 524288;

	private array $drop_in_errors = [];

	public function __construct() {

		add_action( 'admin_init', [ $this, 'sync_status_drop_ins' ] );
		add_action( 'admin_notices', [ $this, 'render_drop_in_notice' ] );
		add_action( 'update_option_' . OCTAVE_ADDONS_OPTION_KEY, [ $this, 'sync_status_drop_ins_after_settings_update' ], 10, 0 );

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

		$this->refresh_status_drop_ins();

	}

	public function sync_status_drop_ins_after_settings_update(): void {

		$this->refresh_status_drop_ins();

	}

	private function refresh_status_drop_ins(): void {

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

	private static function build_drop_in( string $type ) {

		$template_path = OCTAVE_ADDONS_DIR . 'templates/site-status.php';
		$template      = @file_get_contents( $template_path );
		$appearance    = self::appearance();

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
		$variables .= '$octave_status_logo = ' . var_export( $appearance['logo'], true ) . ";\n";
		$variables .= '$octave_status_background = ' . var_export( $appearance['background'], true ) . ";\n";
		$variables .= '$octave_status_surface = ' . var_export( $appearance['surface'], true ) . ";\n";
		$variables .= '$octave_status_border = ' . var_export( $appearance['border'], true ) . ";\n";
		$variables .= '$octave_status_primary = ' . var_export( $appearance['primary'], true ) . ";\n";
		$variables .= '$octave_status_primary_rgb = ' . var_export( $appearance['primary_rgb'], true ) . ";\n";
		$variables .= '$octave_status_on_primary = ' . var_export( $appearance['on_primary'], true ) . ";\n";
		$variables .= '$octave_status_text = ' . var_export( $appearance['text'], true ) . ";\n";
		$variables .= '$octave_status_muted = ' . var_export( $appearance['muted'], true ) . ";\n";
		$variables .= '$octave_status_shadow = ' . var_export( $appearance['shadow'], true ) . ";\n";
		$variables .= '$octave_status_color_scheme = ' . var_export( $appearance['color_scheme'], true ) . ";\n\n?>\n\n";

		return $variables . $template;

	}

	private static function site_name(): string {

		$name = trim( (string) get_bloginfo( 'name' ) );

		return '' !== $name ? $name : __( 'Our website', 'octave-addons' );

	}

	/*
	STATUS APPEARANCE
	-- Uses the enabled Custom Login module as the site-branding source.
	-- Disabled or incomplete settings resolve to a neutral generic scheme.
	---------------------------------------------------------- */

	private static function appearance(): array {

		$all_settings = get_option( OCTAVE_ADDONS_OPTION_KEY, [] );
		$login        = is_array( $all_settings ) && isset( $all_settings['custom-login'] ) && is_array( $all_settings['custom-login'] )
			? $all_settings['custom-login']
			: [];
		$is_enabled   = ! empty( $login['enabled'] );

		$background = $is_enabled && ! empty( $login['bg_color'] )
			? sanitize_hex_color( $login['bg_color'] )
			: '#f0f2f5';
		$primary    = $is_enabled && ! empty( $login['primary_color'] )
			? sanitize_hex_color( $login['primary_color'] )
			: '#4f8ef7';

		$background = $background ?: '#f0f2f5';
		$primary    = $primary ?: '#4f8ef7';
		$is_dark    = self::is_dark_color( $background );
		$logo_url   = $is_enabled && ! empty( $login['custom_logo_url'] )
			? esc_url_raw( $login['custom_logo_url'] )
			: '';

		return [
			'background'   => $background,
			'surface'      => $is_dark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(255, 255, 255, 0.94)',
			'border'       => $is_dark ? 'rgba(255, 255, 255, 0.14)' : 'rgba(17, 24, 39, 0.10)',
			'primary'      => $primary,
			'primary_rgb'  => self::hex_to_rgb( $primary ),
			'on_primary'   => self::is_dark_color( $primary ) ? '#ffffff' : '#111827',
			'text'         => $is_dark ? '#f8fafc' : '#111827',
			'muted'        => $is_dark ? '#c2c8ce' : '#5f6673',
			'shadow'       => $is_dark ? '0 28px 80px rgba(0, 0, 0, 0.34)' : '0 28px 80px rgba(17, 24, 39, 0.14)',
			'color_scheme' => $is_dark ? 'dark' : 'light',
			'logo'         => self::logo_source( $logo_url ),
		];

	}

	/*
	CUSTOM LOGIN LOGO SOURCE
	-- Embeds Media Library logos when small enough and otherwise retains the
	-- configured URL. No Octave or theme logo is substituted.
	---------------------------------------------------------- */

	private static function logo_source( string $logo_url ): string {

		if ( '' === $logo_url ) {

			return '';

		}

		$logo_id   = attachment_url_to_postid( $logo_url );
		$logo_path = $logo_id ? get_attached_file( $logo_id ) : '';
		$logo_size = is_string( $logo_path ) && is_readable( $logo_path ) ? @filesize( $logo_path ) : false;

		if ( false === $logo_size || $logo_size > self::MAX_LOGO_BYTES ) {

			return $logo_url;

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

			return $logo_url;

		}

		$image = @file_get_contents( $logo_path );

		return false !== $image ? 'data:' . $mime_type . ';base64,' . base64_encode( $image ) : $logo_url;

	}

	private static function is_dark_color( string $hex ): bool {

		$rgb = self::hex_to_rgb_values( $hex );

		return ( ( 0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2] ) / 255 ) < 0.5;

	}

	private static function hex_to_rgb( string $hex ): string {

		return implode( ', ', self::hex_to_rgb_values( $hex ) );

	}

	private static function hex_to_rgb_values( string $hex ): array {

		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {

			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];

		}

		return [
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		];

	}

}
