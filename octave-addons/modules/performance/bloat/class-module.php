<?php

/*
PERFORMANCE: WORDPRESS BLOAT
-- Removes frontend assets WordPress loads on every page whether or not the
-- page uses them: the emoji script and styles, the oEmbed host script,
-- jQuery Migrate and the block editor stylesheets
-- Frontend only. The admin, the editors and the Breakdance builder are never
-- touched, and ?oa_no_optimize puts everything back for troubleshooting
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once dirname( __DIR__ ) . '/services/bootstrap.php';

class Octave_Addons_Module_Performance_Bloat extends Octave_Addons_Module {

	use Octave_Addons_Perf_Field_Rows;

	/**
	 * Stylesheets WordPress adds for block editor content.
	 */
	public const BLOCK_STYLES = [ 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles' ];

	public function get_id(): string {

		return 'performance-bloat';

	}

	public function get_title(): string {

		return __( 'WordPress Bloat', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Stops WordPress loading emoji, embed, jQuery Migrate and block editor assets on pages that do not use them.', 'octave-addons' );

	}

	public function get_order(): int {

		return 55;

	}

	public function get_defaults(): array {

		return [
			'enabled'        => false,
			'emojis'         => true,
			'embeds'         => true,
			'jquery_migrate' => false,
			'block_styles'   => false,
		];

	}

	public function sanitize( $input ): array {

		$clean = parent::sanitize( $input );

		foreach ( [ 'emojis', 'embeds', 'jquery_migrate', 'block_styles' ] as $key ) {

			$clean[ $key ] = ! empty( $input[ $key ] );

		}

		return $clean;

	}

	/*
	RUN
	-- Waits for the query so the troubleshooting bypass and the builder check
	-- can see the request
	---------------------------------------------------------- */

	public function run( array $s ): void {

		add_action( 'wp', static function () use ( $s ): void {

			if ( is_admin() || Octave_Addons_Module::is_builder_request() || Octave_Addons_Perf_Context::bypass_requested() ) {

				return;

			}

			self::apply( $s );

		} );

	}

	public static function apply( array $s ): void {

		if ( ! empty( $s['emojis'] ) ) {

			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			add_filter( 'emoji_svg_url', '__return_false' );

		}

		if ( ! empty( $s['embeds'] ) ) {

			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );

			add_action( 'wp_enqueue_scripts', static function (): void {

				wp_deregister_script( 'wp-embed' );

			}, PHP_INT_MAX );

		}

		if ( ! empty( $s['jquery_migrate'] ) ) {

			add_action( 'wp_enqueue_scripts', static function (): void {

				self::drop_jquery_migrate( wp_scripts() );

			}, PHP_INT_MAX );

		}

		if ( ! empty( $s['block_styles'] ) && ! self::page_has_blocks() ) {

			add_action( 'wp_enqueue_scripts', static function (): void {

				foreach ( self::BLOCK_STYLES as $handle ) {

					wp_dequeue_style( $handle );

				}

			}, PHP_INT_MAX );

			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
			remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );

		}

	}

	/*
	DROP JQUERY MIGRATE
	-- Takes jquery-migrate out of the jquery bundle's dependencies, so jQuery
	-- itself still loads for everything that asks for it
	---------------------------------------------------------- */

	public static function drop_jquery_migrate( $scripts ): bool {

		$jquery = $scripts->registered['jquery'] ?? null;

		if ( ! is_object( $jquery ) || ! is_array( $jquery->deps ) || ! in_array( 'jquery-migrate', $jquery->deps, true ) ) {

			return false;

		}

		$jquery->deps = array_values( array_diff( $jquery->deps, [ 'jquery-migrate' ] ) );

		return true;

	}

	/*
	PAGE HAS BLOCKS
	-- Only a single post or page proven to hold no block markup drops the
	-- block styles. Archives, search and the home page keep them, as any post
	-- in the loop might use blocks
	---------------------------------------------------------- */

	public static function page_has_blocks(): bool {

		if ( ! is_singular() ) {

			return true;

		}

		return has_blocks( get_queried_object_id() );

	}

	public function render_settings( array $s ): void {

		?>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			$this->switch_row( 'emojis', __( 'Emoji script', 'octave-addons' ), __( 'Safe. Removes the script and styles that swap emoji for images. Every current browser shows emoji natively.', 'octave-addons' ), $s );
			$this->switch_row( 'embeds', __( 'Embed host script', 'octave-addons' ), __( 'Safe. Stops other sites embedding your posts as cards. Embedding YouTube, X and others into your pages keeps working.', 'octave-addons' ), $s );
			$this->switch_row( 'jquery_migrate', __( 'jQuery Migrate', 'octave-addons' ), __( 'Advanced. Removes the compatibility layer for jQuery code written before 2016. Check sliders, forms and older plugins afterwards; the browser console shows a JQMIGRATE warning when something needed it.', 'octave-addons' ), $s );
			$this->switch_row( 'block_styles', __( 'Block editor styles', 'octave-addons' ), __( 'Advanced. On single posts and pages with no block editor content, drops the block library and global styles stylesheets. Breakdance pages rarely need them; pages with blocks, archives and the home page keep them.', 'octave-addons' ), $s );

			?>
		</table>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Bloat();
