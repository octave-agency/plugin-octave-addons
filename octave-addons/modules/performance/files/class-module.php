<?php

/*
PERFORMANCE: FILE OPTIMIZATION
-- Independent switches: minify local CSS, minify local JavaScript, inline
-- small local stylesheets, and defer selected local scripts. Files are
-- never combined, so every
-- stylesheet and script keeps its own tag, position, dependencies, media
-- and loading attributes — only the URL changes to a minified copy
-- Minified copies are cached under a key built from the source path, its
-- modification time and size, the settings and the cache generation, so an
-- edited file or a purge produces a new copy automatically. A file that
-- cannot be minified safely is logged once a day and served as it is
-- Breakdance assets, already-minified files, files with an integrity hash
-- and anything dynamic (extra query arguments) are never minified
-- Inlining moves a small stylesheet's contents into the page in place of
-- its <link>, Breakdance's per-page files included, without changing order
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

require_once dirname( __DIR__ ) . '/services/bootstrap.php';

class Octave_Addons_Module_Performance_Files extends Octave_Addons_Module {

	use Octave_Addons_Perf_Field_Rows;

	/** Largest source file that is minified. */
	protected const MAX_BYTES = 400 * KB_IN_BYTES;

	/** URL fragments that are never minified or deferred. */
	protected const ALWAYS_EXCLUDED = [ 'breakdance', '/cache/octave-addons/', '.min.', '-min.', '/wp-includes/', '/wp-admin/' ];

	/** Inline size limits offered, in KB; 0 turns inlining off. */
	public const INLINE_SIZES = [ 4, 8, 16 ];

	/** Most CSS inlined into one page, so the HTML itself never grows too heavy. */
	protected const INLINE_BUDGET = 80 * KB_IN_BYTES;

	/** Handles that are never deferred. */
	protected const PROTECTED_HANDLES = [ 'jquery', 'jquery-core', 'jquery-migrate', 'wp-hooks', 'wp-i18n', 'wp-polyfill' ];

	protected array $settings = [];

	public function get_id(): string {

		return 'performance-files';

	}

	public function get_title(): string {

		return __( 'File Optimization', 'octave-addons' );

	}

	public function get_description(): string {

		return __( 'Per-file minification of local CSS and JavaScript, and deferral of scripts you choose. Files are never combined.', 'octave-addons' );

	}

	public function get_order(): int {

		return 30;

	}

	public function get_defaults(): array {

		return [
			'enabled'       => false,
			'minify_css'    => false,
			'minify_js'     => false,
			'defer_js'      => false,
			'inline_css'    => true,
			'inline_max'    => 8,
			'defer_handles' => '',
			'exclude'       => '',
		];

	}

	public function sanitize( $input ): array {

		$clean = parent::sanitize( $input );

		foreach ( [ 'minify_css', 'minify_js', 'defer_js', 'inline_css' ] as $key ) {

			$clean[ $key ] = ! empty( $input[ $key ] );

		}

		$max                 = absint( $input['inline_max'] ?? 8 );
		$clean['inline_max'] = in_array( $max, self::INLINE_SIZES, true ) ? $max : 8;

		$clean['defer_handles'] = Octave_Addons_Perf::sanitize_lines( $input['defer_handles'] ?? '' );
		$clean['exclude']       = Octave_Addons_Perf::sanitize_lines( $input['exclude'] ?? '' );

		return $clean;

	}

	/*
	RUN
	-- Works on the tags WordPress prints, so order, dependencies and inline
	-- before/after scripts stay exactly as WordPress arranged them
	---------------------------------------------------------- */

	public function run( array $s ): void {

		$this->settings = $s;

		if ( ! empty( $s['minify_css'] ) ) {

			add_filter( 'style_loader_tag', [ $this, 'filter_style_tag' ], 999, 4 );

		}

		if ( ! empty( $s['minify_js'] ) ) {

			add_filter( 'script_loader_tag', [ $this, 'filter_script_tag' ], 999, 3 );

		}

		if ( ! empty( $s['defer_js'] ) && '' !== trim( (string) $s['defer_handles'] ) ) {

			add_action( 'wp_print_scripts', [ $this, 'apply_defer' ], 1 );

		}

		// After the font rewrite (40), so a self-hosted fonts.css can be inlined too.
		if ( ! empty( $s['inline_css'] ) ) {

			Octave_Addons_Perf_Html::register( 'files', [ $this, 'inline_styles' ], 50 );

		}

	}

	/*
	INLINE STYLES
	-- Replaces each small local stylesheet <link> with its contents in a
	-- <style> at the same position, keeping its id and media, so the order
	-- of the cascade is unchanged while the browser no longer waits for a
	-- separate request per file before it can paint. Builders such as
	-- Breakdance print many small per-page files, which is where this pays.
	-- Links in <noscript> or comments, preloads, alternate and print-swap
	-- stylesheets, files with an integrity hash, @import, or a closing style
	-- tag, and anything over the size limit are left as links
	---------------------------------------------------------- */

	public function inline_styles( string $html ): string {

		if ( ! Octave_Addons_Perf::has_html_api() || false === stripos( $html, '<link' ) ) {

			return $html;

		}

		$limit   = max( 1, (int) ( $this->settings['inline_max'] ?? 8 ) ) * KB_IN_BYTES;
		$budget  = self::INLINE_BUDGET;
		$skipped = [];

		// Ranges where a <link> is not a live stylesheet.
		preg_match_all( '#<!--.*?-->|<noscript\b.*?</noscript>|<template\b.*?</template>#is', $html, $blocks, PREG_OFFSET_CAPTURE );

		foreach ( $blocks[0] as $block ) {

			$skipped[] = [ $block[1], $block[1] + strlen( $block[0] ) ];

		}

		$output = '';
		$cursor = 0;

		preg_match_all( '#<link\b[^>]*>#i', $html, $links, PREG_OFFSET_CAPTURE );

		foreach ( $links[0] as [ $tag, $offset ] ) {

			foreach ( $skipped as [ $start, $end ] ) {

				if ( $offset >= $start && $offset < $end ) {

					continue 2;

				}

			}

			$style = $this->inline_style( $tag, $limit, $budget );

			if ( '' === $style ) {

				continue;

			}

			$output .= substr( $html, $cursor, $offset - $cursor ) . $style;
			$cursor  = $offset + strlen( $tag );

		}

		return 0 === $cursor ? $html : $output . substr( $html, $cursor );

	}

	/*
	INLINE STYLE
	-- The <style> replacing one <link> tag, or '' to keep the link
	---------------------------------------------------------- */

	protected function inline_style( string $tag, int $limit, int &$budget ): string {

		$tags = new WP_HTML_Tag_Processor( $tag );

		if ( ! $tags->next_tag( 'LINK' ) ) {

			return '';

		}

		$rel   = preg_split( '/\s+/', strtolower( trim( (string) $tags->get_attribute( 'rel' ) ) ) );
		$href  = html_entity_decode( trim( (string) $tags->get_attribute( 'href' ) ) );
		$media = trim( (string) $tags->get_attribute( 'media' ) );

		if ( ! in_array( 'stylesheet', (array) $rel, true ) || in_array( 'alternate', (array) $rel, true ) || '' === $href ) {

			return '';

		}

		foreach ( [ 'integrity', 'disabled', 'onload', 'data-oa-no-inline' ] as $attribute ) {

			if ( null !== $tags->get_attribute( $attribute ) ) {

				return '';

			}

		}

		if ( ! Octave_Addons_Perf::is_same_origin( $href ) || '' !== $this->inline_exclusion( (string) $tags->get_attribute( 'id' ), $href ) ) {

			return '';

		}

		$path = Octave_Addons_Perf_Admin::local_path( $href );

		if ( '' === $path || 'css' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {

			return '';

		}

		$size = (int) filesize( $path );

		if ( $size > $limit || $size > $budget ) {

			return '';

		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$css = (string) file_get_contents( $path );

		if ( '' === trim( $css ) || false !== stripos( $css, '</style' ) || false !== stripos( $css, '@import' ) ) {

			return '';

		}

		$budget -= $size;

		// Relative url()s were relative to the file; @charset means nothing inline.
		$css = Octave_Addons_Perf_Minifier::absolutize_css_urls( $css, 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ? home_url( $href ) : $href );
		$css = (string) preg_replace( '/^\s*@charset\s+[^;]+;/i', '', $css );

		Octave_Addons_Perf_Log::note( 'files', [ 'stylesheet' => $href, 'action' => 'inlined', 'bytes' => $size ] );

		$id = (string) $tags->get_attribute( 'id' );

		return sprintf(
			'<style%s%s data-oa-inlined="%s">%s</style>',
			'' !== $id ? ' id="' . esc_attr( $id ) . '"' : '',
			'' !== $media && 'all' !== strtolower( $media ) ? ' media="' . esc_attr( $media ) . '"' : '',
			esc_attr( $href ),
			trim( $css )
		);

	}

	/*
	INLINE EXCLUSION
	-- Only the administrator's exclusions apply: the built-in ones exist to
	-- protect minification, which inlining does not do
	---------------------------------------------------------- */

	public function inline_exclusion( string $id, string $url ): string {

		/**
		 * Filters the id and URL patterns whose stylesheets are never inlined.
		 *
		 * @param string[] $patterns Case-insensitive substrings.
		 */
		$patterns = (array) apply_filters( 'octave_addons_perf_inline_exclusions', Octave_Addons_Perf::lines( $this->settings['exclude'] ?? '' ) );

		return Octave_Addons_Perf::matches_any( $id . ' ' . $url, $patterns );

	}

	public function filter_style_tag( $tag, $handle, $href = '', $media = '' ) {

		return $this->swap_url( (string) $tag, (string) $handle, (string) $href, 'css' );

	}

	public function filter_script_tag( $tag, $handle, $src = '' ) {

		return $this->swap_url( (string) $tag, (string) $handle, (string) $src, 'js' );

	}

	/*
	SWAP URL
	-- Points the one tag WordPress printed for this handle at the minified
	-- copy. Inline scripts sharing the tag string are left untouched
	---------------------------------------------------------- */

	protected function swap_url( string $tag, string $handle, string $url, string $type ): string {

		if ( '' === $url || ! Octave_Addons_Perf::has_html_api() || ! Octave_Addons_Perf_Context::can_optimize( 'files' ) ) {

			return $tag;

		}

		if ( '' !== Octave_Addons_Perf::handled_elsewhere( 'css' === $type ? 'minify_css' : 'minify_js' ) ) {

			return $tag;

		}

		$minified = $this->minified_url( $handle, $url, $type );

		if ( '' === $minified ) {

			return $tag;

		}

		$tags      = new WP_HTML_Tag_Processor( $tag );
		$element   = 'css' === $type ? 'LINK' : 'SCRIPT';
		$attribute = 'css' === $type ? 'href' : 'src';

		while ( $tags->next_tag( $element ) ) {

			if ( html_entity_decode( (string) $tags->get_attribute( $attribute ) ) !== html_entity_decode( $url ) ) {

				continue;

			}

			// A subresource integrity hash belongs to the original bytes.
			if ( null !== $tags->get_attribute( 'integrity' ) ) {

				return $tag;

			}

			$tags->set_attribute( $attribute, $minified );

			return $tags->get_updated_html();

		}

		return $tag;

	}

	/*
	MINIFIED URL
	-- URL of the cached minified copy, creating it on first use, or '' when
	-- the original should be served
	---------------------------------------------------------- */

	public function minified_url( string $handle, string $url, string $type ): string {

		$path = $this->eligible_path( $handle, $url, $type );

		if ( '' === $path ) {

			return '';

		}

		$key  = substr( md5( implode( '|', [ $path, filemtime( $path ), filesize( $path ), wp_json_encode( $this->settings ), Octave_Addons_Perf_Cache::generation(), OCTAVE_ADDONS_VERSION ] ) ), 0, 12 );
		$slug = sanitize_file_name( strtolower( $handle ) ) ?: 'file';
		$name = $slug . '-' . $key . '.min.' . $type;
		$dir  = Octave_Addons_Perf_Store::dir( Octave_Addons_Perf_Cache::MIN_DIR );

		if ( file_exists( $dir . $name ) ) {

			return Octave_Addons_Perf_Store::url( Octave_Addons_Perf_Cache::MIN_DIR ) . $name;

		}

		if ( get_transient( 'oa_perf_min_fail_' . $key ) ) {

			return '';

		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$source = (string) file_get_contents( $path );
		$output = 'css' === $type ? Octave_Addons_Perf_Minifier::css( $source ) : Octave_Addons_Perf_Minifier::js( $source );

		if ( null === $output || '' === trim( $output ) && '' !== trim( $source ) ) {

			set_transient( 'oa_perf_min_fail_' . $key, 1, DAY_IN_SECONDS );
			Octave_Addons_Perf_Log::error( 'files', __( 'File could not be minified safely, so the original is served.', 'octave-addons' ), $url );

			return '';

		}

		if ( 'css' === $type ) {

			$output = Octave_Addons_Perf_Minifier::absolutize_css_urls( $output, $url );

		}

		if ( ! Octave_Addons_Perf_Store::write( $dir . $name, $output ) ) {

			set_transient( 'oa_perf_min_fail_' . $key, 1, HOUR_IN_SECONDS );
			Octave_Addons_Perf_Log::error( 'files', __( 'The cache folder is not writable, so original files are served.', 'octave-addons' ), $dir );

			return '';

		}

		// Older copies of this handle are retired once the new one is in place.
		foreach ( (array) glob( $dir . $slug . '-*.min.' . $type ) as $old ) {

			if ( basename( (string) $old ) !== $name && preg_match( '/^' . preg_quote( $slug, '/' ) . '-[a-f0-9]{12}\.min\.' . $type . '$/', basename( (string) $old ) ) ) {

				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Octave's own cache file.
				@unlink( (string) $old );

			}

		}

		return Octave_Addons_Perf_Store::url( Octave_Addons_Perf_Cache::MIN_DIR ) . $name;

	}

	/*
	ELIGIBLE PATH
	-- The local file behind a URL when it may be minified, otherwise ''
	---------------------------------------------------------- */

	public function eligible_path( string $handle, string $url, string $type ): string {

		$url = html_entity_decode( $url );

		if ( ! Octave_Addons_Perf::is_same_origin( $url ) || '' !== $this->exclusion( $handle, $url ) ) {

			return '';

		}

		// Only a cache-busting ver argument is allowed; anything else may be dynamic.
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		parse_str( $query, $args );

		unset( $args['ver'] );

		if ( ! empty( $args ) ) {

			return '';

		}

		$path = Octave_Addons_Perf_Admin::local_path( $url );

		if ( '' === $path || strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) !== $type || filesize( $path ) > self::MAX_BYTES ) {

			return '';

		}

		return $path;

	}

	/*
	EXCLUSION
	-- Built-in and administrator exclusions, matched on handle or URL
	---------------------------------------------------------- */

	public function exclusion( string $handle, string $url ): string {

		$patterns = array_merge( self::ALWAYS_EXCLUDED, Octave_Addons_Perf::lines( $this->settings['exclude'] ?? '' ) );

		/**
		 * Filters the handle and URL patterns File Optimization leaves alone.
		 *
		 * @param string[] $patterns Case-insensitive substrings.
		 */
		$patterns = (array) apply_filters( 'octave_addons_perf_file_exclusions', $patterns );

		return Octave_Addons_Perf::matches_any( $handle . ' ' . $url, $patterns );

	}

	/*
	APPLY DEFER
	-- Uses WordPress's own loading strategy (6.3+), which only defers a script
	-- when its dependents and inline scripts allow it, so order is kept
	---------------------------------------------------------- */

	public function apply_defer(): void {

		if ( ! Octave_Addons_Perf_Context::can_optimize( 'files' ) || ! function_exists( 'wp_scripts' ) || ! method_exists( 'WP_Scripts', 'get_data' ) || version_compare( get_bloginfo( 'version' ), '6.3', '<' ) ) {

			return;

		}

		$scripts = wp_scripts();

		foreach ( Octave_Addons_Perf::lines( $this->settings['defer_handles'] ) as $handle ) {

			$handle = sanitize_key( $handle );

			if ( in_array( $handle, self::PROTECTED_HANDLES, true ) || 0 === strpos( $handle, 'breakdance' ) || ! isset( $scripts->registered[ $handle ] ) ) {

				continue;

			}

			$src = (string) $scripts->registered[ $handle ]->src;

			if ( '' === $src || ( 0 !== strpos( $src, '/' ) && ! Octave_Addons_Perf::is_same_origin( $src ) ) ) {

				continue;

			}

			if ( ! $scripts->get_data( $handle, 'strategy' ) ) {

				$scripts->add_data( $handle, 'strategy', 'defer' );

			}

		}

	}

	/*
	RENDER SETTINGS
	---------------------------------------------------------- */

	public function render_settings( array $s ): void {

		?>

		<p class="oa-help oa-help--intro"><?php esc_html_e( 'Compatibility first: each file is minified on its own and stays in its original position. Combining files, removing unused CSS and generating critical CSS are deliberately not offered, as they are the most common cause of broken layouts and scripts.', 'octave-addons' ); ?></p>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			Octave_Addons_Fields::section( [ 'label' => __( 'Minification', 'octave-addons' ), 'first' => true ] );

			$this->switch_row( 'minify_css', __( 'Minify local CSS', 'octave-addons' ), __( 'Recommended when files are unminified. Removes comments and whitespace from each local stylesheet; relative image and font URLs are rewritten so they keep working.', 'octave-addons' ), $s );
			$this->switch_row( 'minify_js', __( 'Minify local JavaScript', 'octave-addons' ), __( 'Advanced. Removes comments and indentation only, keeping every line break so behaviour cannot change. Savings are modest; test interactive features after enabling.', 'octave-addons' ), $s );

			Octave_Addons_Fields::section( [ 'label' => __( 'Small stylesheets', 'octave-addons' ) ] );

			$this->switch_row( 'inline_css', __( 'Inline small stylesheets', 'octave-addons' ), __( 'Recommended. Every stylesheet in the page has to download before anything is shown. Small local ones, such as the per-page CSS Breakdance writes, are placed straight into the page instead, in the same order, so the first paint waits on fewer requests. Large files stay as links, so they can still be cached between pages.', 'octave-addons' ), $s );

			$this->select_row( 'inline_max', __( 'Inline files up to', 'octave-addons' ), [
				4  => '4 KB',
				8  => __( '8 KB (recommended)', 'octave-addons' ),
				16 => '16 KB',
			], __( 'Larger limits remove more requests but make every page heavier, as inlined CSS is downloaded again with each page instead of being cached.', 'octave-addons' ), $s );

			Octave_Addons_Fields::section( [ 'label' => __( 'Deferral', 'octave-addons' ) ] );

			$this->switch_row( 'defer_js', __( 'Defer selected local JavaScript', 'octave-addons' ), __( 'Advanced. Lets the scripts listed below download without blocking the page (WordPress 6.3+). WordPress skips any script whose dependents or inline code need it to run immediately. jQuery and Breakdance are never deferred.', 'octave-addons' ), $s );
			$this->textarea_row( 'defer_handles', __( 'Script handles to defer', 'octave-addons' ), __( 'One registered script handle per line, for example contact-form-7.', 'octave-addons' ), $s );

			Octave_Addons_Fields::section( [ 'label' => __( 'Exclusions', 'octave-addons' ) ] );

			$this->textarea_row( 'exclude', __( 'Never minify or inline', 'octave-addons' ), __( 'One handle, stylesheet id or URL pattern per line. Breakdance assets, WordPress core and files already ending in .min.css or .min.js are never minified. A stylesheet can also opt out of inlining with the data-oa-no-inline attribute.', 'octave-addons' ), $s );

			?>
		</table>

		<p class="oa-help"><?php esc_html_e( 'To delay third-party scripts such as analytics, use Third-Party Script Delay above.', 'octave-addons' ); ?></p>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Files();
