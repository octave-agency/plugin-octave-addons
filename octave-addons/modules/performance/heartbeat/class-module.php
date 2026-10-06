<?php

/*
PERFORMANCE: HEARTBEAT CONTROL
-- WordPress's Heartbeat polls the server while a page is open. This sets its
-- interval separately for the frontend, the general admin and the post
-- editor, or switches it off where that is safe
-- The editor can be slowed but never switched off, because Heartbeat is what
-- autosaves drafts and stops two people editing the same post at once.
-- Breakdance builder requests are never touched
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once dirname( __DIR__ ) . '/services/bootstrap.php';

class Octave_Addons_Module_Performance_Heartbeat extends Octave_Addons_Module {

	use Octave_Addons_Perf_Field_Rows;

	public function get_id(): string {

		return 'performance-heartbeat';

	}

	public function get_title(): string {

		return __( 'Heartbeat Control', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Reduces how often WordPress polls the server in the background, separately for the frontend, the admin and the editor.', 'octave-addons' );

	}

	public function get_order(): int {

		return 60;

	}

	public function get_defaults(): array {

		return [
			'enabled'  => false,
			'frontend' => 'disable',
			'admin'    => '120',
			'editor'   => '120',
		];

	}

	/*
	MODES
	-- Allowed values per context. The editor has no disable option
	---------------------------------------------------------- */

	public static function modes( string $context ): array {

		$modes = [
			'default' => __( 'WordPress default', 'octave-addons' ),
			'30'      => __( 'Every 30 seconds', 'octave-addons' ),
			'60'      => __( 'Every 60 seconds', 'octave-addons' ),
			'120'     => __( 'Every 120 seconds', 'octave-addons' ),
		];

		if ( 'editor' !== $context ) {

			$modes['disable'] = __( 'Disable', 'octave-addons' );

		}

		return $modes;

	}

	public function sanitize( $input ): array {

		$clean = parent::sanitize( $input );

		foreach ( [ 'frontend', 'admin', 'editor' ] as $context ) {

			$value             = sanitize_key( $input[ $context ] ?? '' );
			$clean[ $context ] = isset( self::modes( $context )[ $value ] ) ? $value : $this->get_defaults()[ $context ];

		}

		return $clean;

	}

	public function run( array $s ): void {

		add_filter( 'heartbeat_settings', static function ( $settings ) use ( $s ) {

			return self::filter_settings( is_array( $settings ) ? $settings : [], $s, self::context() );

		} );

		add_action( 'wp_enqueue_scripts', static function () use ( $s ): void {

			self::maybe_disable( $s, self::context() );

		}, PHP_INT_MAX );

		add_action( 'admin_enqueue_scripts', static function () use ( $s ): void {

			self::maybe_disable( $s, self::context() );

		}, PHP_INT_MAX );

	}

	/*
	CONTEXT
	-- frontend, editor (post and site editors) or admin; '' for requests that
	-- must not be touched
	---------------------------------------------------------- */

	public static function context(): string {

		if ( Octave_Addons_Module::is_builder_request() ) {

			return '';

		}

		if ( ! is_admin() ) {

			return 'frontend';

		}

		$page = $GLOBALS['pagenow'] ?? '';

		return in_array( $page, [ 'post.php', 'post-new.php', 'site-editor.php' ], true ) ? 'editor' : 'admin';

	}

	public static function filter_settings( array $settings, array $s, string $context ): array {

		$mode = $s[ $context ] ?? 'default';

		if ( '' === $context || ! ctype_digit( (string) $mode ) ) {

			return $settings;

		}

		$settings['interval'] = (int) $mode;

		return $settings;

	}

	public static function maybe_disable( array $s, string $context ): bool {

		if ( '' === $context || 'editor' === $context || 'disable' !== ( $s[ $context ] ?? '' ) ) {

			return false;

		}

		wp_deregister_script( 'heartbeat' );

		return true;

	}

	public function render_settings( array $s ): void {

		?>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			$this->select_row( 'frontend', __( 'Frontend', 'octave-addons' ), self::modes( 'frontend' ), __( 'Recommended: Disable. Heartbeat on the frontend only runs for logged-in users and few sites need it there.', 'octave-addons' ), $s );
			$this->select_row( 'admin', __( 'General admin', 'octave-addons' ), self::modes( 'admin' ), __( 'Recommended: 120 seconds. Disabling also stops the session-expiry login prompt and live dashboard updates.', 'octave-addons' ), $s );
			$this->select_row( 'editor', __( 'Post editor', 'octave-addons' ), self::modes( 'editor' ), __( 'Recommended: 120 seconds. Cannot be disabled, as it autosaves drafts and prevents two people overwriting each other.', 'octave-addons' ), $s );

			?>
		</table>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Heartbeat();
