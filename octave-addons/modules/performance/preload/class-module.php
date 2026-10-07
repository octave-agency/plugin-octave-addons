<?php

/*
PERFORMANCE: LINK PRELOADING
-- Prefetches a same-origin page when a visitor shows intent to open it: a
-- hover held for about 100ms, or a touch. The next page is then often ready
-- by the time the click lands
-- Only plain same-origin GET links qualify. Admin, login and logout links,
-- cart and checkout actions, downloads, files, nonce URLs and anything an
-- administrator excludes are never requested. Visitors with Save-Data on
-- or on a very slow connection are left alone
-- WordPress 6.8+ ships its own speculative loading; it is switched off while
-- this module runs so the same link is never requested twice
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once dirname( __DIR__ ) . '/services/bootstrap.php';

class Octave_Addons_Module_Performance_Preload extends Octave_Addons_Module {

	use Octave_Addons_Perf_Field_Rows;

	protected const HANDLE = 'octave-addons-link-preload';

	public function get_id(): string {

		return 'performance-preload';

	}

	public function get_title(): string {

		return __( 'Link Preloading', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Starts loading the next page as soon as a visitor points at or touches its link, so it opens almost instantly when clicked.', 'octave-addons' );

	}

	public function get_order(): int {

		return 40;

	}

	public function get_defaults(): array {

		return [
			'enabled' => false,
			'exclude' => '',
		];

	}

	public function sanitize( $input ): array {

		$clean = parent::sanitize( $input );

		$clean['exclude'] = Octave_Addons_Perf::sanitize_lines( $input['exclude'] ?? '' );

		return $clean;

	}

	public function run( array $s ): void {

		add_action( 'wp_enqueue_scripts', function () use ( $s ): void {

			$this->enqueue( $s );

		} );

	}

	/*
	ENQUEUE
	---------------------------------------------------------- */

	protected function enqueue( array $s ): void {

		if ( ! Octave_Addons_Perf_Context::can_optimize( 'preload' ) ) {

			return;

		}

		add_filter( 'wp_speculation_rules_configuration', '__return_null' );

		wp_enqueue_script( self::HANDLE, Octave_Addons_Perf::asset_url( 'preload.js' ), [], Octave_Addons_Perf::asset_version( 'preload.js' ), true );

		if ( function_exists( 'wp_script_add_data' ) ) {

			wp_script_add_data( self::HANDLE, 'strategy', 'defer' );

		}

		wp_localize_script( self::HANDLE, 'oaPreload', [
			'delay'   => 100,
			'exclude' => self::exclusions( $s ),
		] );

	}

	/*
	EXCLUSIONS
	-- Substrings that rule a URL out, on top of the script's own checks for
	-- origin, hash-only links, file extensions and nonce parameters
	---------------------------------------------------------- */

	public static function exclusions( array $s ): array {

		$patterns = [
			'/wp-admin', '/wp-login.php', 'wp-json', 'xmlrpc.php', '/feed', 'action=logout',
			'add-to-cart=', 'add_to_cart', 'remove_item=', 'undo_item=', 'apply_coupon', 'removed_item', 'empty-cart', 'customer-logout',
			'_wpnonce=', 'nonce=', 'preview=true', 'preview_id=', 'breakdance=', 'oa_no_optimize',
		];

		foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {

			if ( function_exists( 'wc_get_page_permalink' ) ) {

				$link = (string) wp_parse_url( wc_get_page_permalink( $page ), PHP_URL_PATH );

				if ( '' !== $link && '/' !== $link ) {

					$patterns[] = $link;

				}

			}

		}

		$patterns = array_merge( $patterns, Octave_Addons_Perf::lines( $s['exclude'] ?? '' ) );

		/**
		 * Filters the URL patterns that are never preloaded on hover.
		 *
		 * @param string[] $patterns Case-insensitive substrings.
		 */
		return array_values( array_unique( (array) apply_filters( 'octave_addons_perf_preload_exclusions', $patterns ) ) );

	}

	public function render_settings( array $s ): void {

		?>

		<p class="oa-help oa-help--intro"><?php esc_html_e( 'Safe to turn on. A page is only fetched once, after the pointer rests on its link, and only a few at a time. It does not run for people who are logged in.', 'octave-addons' ); ?></p>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			$this->textarea_row( 'exclude', __( 'Never load early', 'octave-addons' ), __( 'Links whose address contains one of these words are never loaded early. One per line. Admin, login, logout, cart, checkout, account, add-to-cart and download links are always skipped.', 'octave-addons' ), $s );

			?>
		</table>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Preload();
