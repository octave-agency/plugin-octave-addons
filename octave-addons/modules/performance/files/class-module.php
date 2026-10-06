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
-- Copies are created by the cache warmer, cron or a diagnostics scan rather
-- than during a visitor's page view, which is served the original meanwhile
-- Breakdance assets, already-minified files, files with an integrity hash
-- and anything dynamic (extra query arguments) are never minified
-- Inlining moves a small stylesheet's contents into the page in place of
-- its <link>, Breakdance's per-page files included, without changing order
-- Breakdance CSS delivery replaces each unbroken run of Breakdance and
-- Octave stylesheets in the <head> (normalize, dependencies, fonts,
-- globals, presets, selectors and each post-ID.css) with one bundle of the
-- same CSS in the same order, inlined when small enough or else served as
-- one cached file, so first paint waits on one request instead of fifteen.
-- Nothing is removed or loaded late: unused-CSS deletion and asynchronous
-- loading are deliberately not done, as dynamic states and the header,
-- navigation and hero must all be styled at first paint
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

	/** Breakdance's generated CSS for one document: post-ID.css and post-ID-defaults.css. */
	protected const PAGE_SPECIFIC = '#/breakdance/css/post-\\d+(?:-defaults)?\\.css$#i';

	/** Stylesheets shared by many pages, which are never inlined. */
	protected const SHARED_PATTERNS = [ '/wp-content/plugins/', '/wp-includes/' ];

	/** Stylesheets bundled by Breakdance CSS delivery, matched on the URL path. */
	protected const BUNDLE_PATTERNS = [ '/breakdance/', '/octave-addons/' ];

	/** A bundle up to this size is inlined into the page; larger ones are linked as one file. */
	public const BUNDLE_INLINE_MAX = 96 * KB_IN_BYTES;

	/** Largest bundle built at all; past it the original links stay. */
	public const BUNDLE_MAX = 1024 * KB_IN_BYTES;

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

		return __( 'Per-file minification of local CSS and JavaScript, Breakdance CSS delivered as one ordered bundle, and deferral of scripts you choose.', 'octave-addons' );

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
			'inline_css'     => true,
			'inline_max'     => 4,
			'breakdance_css' => false,
			'defer_handles' => '',
			'exclude'       => '',
		];

	}

	public function sanitize( $input ): array {

		$clean = parent::sanitize( $input );

		foreach ( [ 'minify_css', 'minify_js', 'defer_js', 'inline_css', 'breakdance_css' ] as $key ) {

			$clean[ $key ] = ! empty( $input[ $key ] );

		}

		$max                 = absint( $input['inline_max'] ?? 4 );
		$clean['inline_max'] = in_array( $max, self::INLINE_SIZES, true ) ? $max : 4;

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

		// After the font rewrite (40) and before inlining (50), so the bundle holds the final links.
		if ( ! empty( $s['breakdance_css'] ) ) {

			Octave_Addons_Perf_Html::register( 'files-bundle', [ $this, 'bundle_styles' ], 45 );

		}

		// After the font rewrite (40), so a self-hosted fonts.css can be inlined too.
		if ( ! empty( $s['inline_css'] ) ) {

			Octave_Addons_Perf_Html::register( 'files', [ $this, 'inline_styles' ], 50 );

		}

	}

	/*
	INLINE STYLES
	-- Replaces small local stylesheet <link>s with their contents in a
	-- <style> at the same position, keeping id and media, so the cascade is
	-- unchanged while first paint waits on fewer requests
	-- Breakdance's per-page files (post-ID.css) are the reason this pays, so
	-- they get the budget first. Plugin, WordPress core and Breakdance's
	-- global files are shared by every page and stay as links the browser
	-- caches once. Links in <noscript> or comments, preloads, alternate and
	-- print-swap stylesheets, files with an integrity hash, @import, or a
	-- closing style tag, and anything over the size limit are left alone
	---------------------------------------------------------- */

	public function inline_styles( string $html ): string {

		if ( ! Octave_Addons_Perf::has_html_api() || false === stripos( $html, '<link' ) ) {

			return $html;

		}

		$limit   = max( 1, (int) ( $this->settings['inline_max'] ?? 4 ) ) * KB_IN_BYTES;
		$skipped = [];

		// Ranges where a <link> is not a live stylesheet.
		preg_match_all( '#<!--.*?-->|<noscript\b.*?</noscript>|<template\b.*?</template>#is', $html, $blocks, PREG_OFFSET_CAPTURE );

		foreach ( $blocks[0] as $block ) {

			$skipped[] = [ $block[1], $block[1] + strlen( $block[0] ) ];

		}

		$candidates = [];

		preg_match_all( '#<link\b[^>]*>#i', $html, $links, PREG_OFFSET_CAPTURE );

		foreach ( $links[0] as [ $tag, $offset ] ) {

			foreach ( $skipped as [ $start, $end ] ) {

				if ( $offset >= $start && $offset < $end ) {

					continue 2;

				}

			}

			$candidate = $this->inline_candidate( $tag, $limit );

			if ( ! empty( $candidate ) ) {

				$candidates[ $offset ] = $candidate + [ 'tag' => $tag ];

			}

		}

		$chosen = self::allot_budget( $candidates, self::INLINE_BUDGET );

		if ( empty( $chosen ) ) {

			return $html;

		}

		$output = '';
		$cursor = 0;
		$total  = 0;

		foreach ( $chosen as $offset => $candidate ) {

			$output .= substr( $html, $cursor, $offset - $cursor ) . $this->inline_markup( $candidate );
			$cursor  = $offset + strlen( $candidate['tag'] );
			$total  += $candidate['size'];

			Octave_Addons_Perf_Log::note( 'files', [ 'stylesheet' => $candidate['href'], 'action' => 'inlined', 'bytes' => $candidate['size'] ] );

		}

		Octave_Addons_Perf_Log::summary( 'inlined_css_bytes', $total );

		return $output . substr( $html, $cursor );

	}

	/*
	BUNDLE STYLES
	-- Replaces each unbroken run of bundleable stylesheets in the <head>
	-- with one bundle. A run only continues across whitespace, <meta> tags
	-- and non-stylesheet <link>s such as preloads, which are kept and moved
	-- ahead of the bundle; inline <style> blocks inside a run join the
	-- bundle at their own position, so the cascade is exactly as before.
	-- Anything else ends the run. Runs of fewer than two files are left
	---------------------------------------------------------- */

	public function bundle_styles( string $html ): string {

		$end = stripos( $html, '</head>' );

		if ( ! Octave_Addons_Perf::has_html_api() || false === $end ) {

			return $html;

		}

		$head  = substr( $html, 0, $end );
		$items = $this->head_items( $head );
		$runs  = [];
		$run   = [];
		$last  = null;

		foreach ( $items as $item ) {

			$gap = null === $last ? '' : substr( $head, $last, $item['offset'] - $last );

			if ( null !== $last && '' !== trim( (string) preg_replace( '#<meta\b[^>]*>#i', '', $gap ) ) || 'barrier' === $item['kind'] ) {

				$runs[] = $run;
				$run    = [];

			}

			if ( 'barrier' !== $item['kind'] && ( ! empty( $run ) || 'file' === $item['kind'] ) ) {

				$run[] = $item;

			}

			$last = $item['offset'] + strlen( $item['tag'] );

		}

		$runs[] = $run;
		$output = $head;
		$shift  = 0;
		$number = 0;

		foreach ( $runs as $run ) {

			// Trailing non-files add nothing to a bundle.
			while ( ! empty( $run ) && 'file' !== end( $run )['kind'] ) {

				array_pop( $run );

			}

			if ( count( array_filter( $run, static function ( array $item ): bool {

				return 'file' === $item['kind'];

			} ) ) < 2 ) {

				continue;

			}

			$markup = $this->bundle_markup( $run, ++$number );

			if ( '' === $markup ) {

				continue;

			}

			$start  = $run[0]['offset'];
			$stop   = end( $run )['offset'] + strlen( end( $run )['tag'] );
			$kept   = '';

			foreach ( $run as $item ) {

				$kept .= 'keep' === $item['kind'] ? $item['tag'] . "\n" : '';

			}

			preg_match_all( '#<meta\b[^>]*>#i', substr( $head, $start, $stop - $start ), $metas );

			$replacement = implode( "\n", $metas[0] ) . ( empty( $metas[0] ) ? '' : "\n" ) . $kept . $markup;
			$output      = substr( $output, 0, $start + $shift ) . $replacement . substr( $output, $stop + $shift );
			$shift      += strlen( $replacement ) - ( $stop - $start );

		}

		return $output . substr( $html, $end );

	}

	/*
	HEAD ITEMS
	-- Every <link> and <style> in the <head>, outside comments, <noscript>
	-- and <template>, classed as a bundleable 'file' or 'style', a 'keep'
	-- link that is not a stylesheet, or a 'barrier' that ends a run.
	-- <script>, <noscript>, <title> and comments end a run too, as they
	-- appear in the gap between items
	---------------------------------------------------------- */

	protected function head_items( string $head ): array {

		$skipped = [];

		preg_match_all( '#<!--.*?-->|<noscript\b.*?</noscript>|<template\b.*?</template>|<script\b.*?</script>#is', $head, $blocks, PREG_OFFSET_CAPTURE );

		foreach ( $blocks[0] as $block ) {

			$skipped[] = [ $block[1], $block[1] + strlen( $block[0] ) ];

		}

		preg_match_all( '#<link\b[^>]*>|<style\b[^>]*>.*?</style>#is', $head, $matches, PREG_OFFSET_CAPTURE );

		$items = [];

		foreach ( $matches[0] as [ $tag, $offset ] ) {

			foreach ( $skipped as [ $start, $stop ] ) {

				if ( $offset >= $start && $offset < $stop ) {

					continue 2;

				}

			}

			$items[] = array_merge( [ 'tag' => $tag, 'offset' => $offset ], $this->bundle_item( $tag ) );

		}

		return $items;

	}

	/*
	BUNDLE ITEM
	-- What one <link> or <style> contributes: kind, and for a file its
	-- href, local path, media and CSS
	---------------------------------------------------------- */

	protected function bundle_item( string $tag ): array {

		$barrier = [ 'kind' => 'barrier' ];
		$tags    = new WP_HTML_Tag_Processor( $tag );

		if ( ! $tags->next_tag() ) {

			return $barrier;

		}

		$media = trim( (string) $tags->get_attribute( 'media' ) );

		// A print stylesheet never blocks rendering, so it stays a link of its own.
		if ( preg_match( '/[<>"\'{};]/', $media ) || false !== stripos( $media, 'print' ) ) {

			return $barrier;

		}

		foreach ( [ 'integrity', 'disabled', 'onload', 'title', 'data-oa-no-inline', 'data-oa-no-bundle', 'nonce' ] as $attribute ) {

			if ( null !== $tags->get_attribute( $attribute ) ) {

				return $barrier;

			}

		}

		if ( 'STYLE' === $tags->get_tag() ) {

			$css = (string) preg_replace( '#^<style\b[^>]*>|</style>$#i', '', $tag );
			$type = strtolower( trim( (string) $tags->get_attribute( 'type' ) ) );

			if ( ( '' !== $type && 'text/css' !== $type ) || false !== stripos( $css, '@import' ) ) {

				return $barrier;

			}

			return [ 'kind' => 'style', 'css' => $css, 'media' => $media, 'source' => md5( $css ) ];

		}

		$rel = preg_split( '/\s+/', strtolower( trim( (string) $tags->get_attribute( 'rel' ) ) ) );

		if ( ! in_array( 'stylesheet', (array) $rel, true ) ) {

			return [ 'kind' => 'keep' ];

		}

		$href = html_entity_decode( trim( (string) $tags->get_attribute( 'href' ) ) );
		$path = (string) wp_parse_url( $href, PHP_URL_PATH );

		/**
		 * Filters the URL path fragments of stylesheets Breakdance CSS delivery bundles.
		 *
		 * @param string[] $patterns Case-insensitive substrings.
		 */
		$patterns = (array) apply_filters( 'octave_addons_perf_bundle_patterns', self::BUNDLE_PATTERNS );

		if ( [ 'stylesheet' ] !== $rel || ! Octave_Addons_Perf::is_same_origin( $href ) || '' === Octave_Addons_Perf::matches_any( $path, $patterns ) || '' !== $this->bundle_exclusion( (string) $tags->get_attribute( 'id' ), $href ) ) {

			return $barrier;

		}

		$file = Octave_Addons_Perf_Admin::local_path( $href );

		if ( '' === $file || 'css' !== strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) || filesize( $file ) > self::MAX_BYTES ) {

			return $barrier;

		}

		return [
			'kind'   => 'file',
			'href'   => $href,
			'file'   => $file,
			'media'  => $media,
			'source' => $file . '|' . filemtime( $file ) . '|' . filesize( $file ),
		];

	}

	/*
	BUNDLE EXCLUSION
	-- The administrator's never-minify-or-inline list applies here too
	---------------------------------------------------------- */

	public function bundle_exclusion( string $id, string $url ): string {

		/**
		 * Filters the id and URL patterns whose stylesheets Breakdance CSS delivery leaves as links.
		 *
		 * @param string[] $patterns Case-insensitive substrings.
		 */
		$patterns = (array) apply_filters( 'octave_addons_perf_bundle_exclusions', Octave_Addons_Perf::lines( $this->settings['exclude'] ?? '' ) );

		return Octave_Addons_Perf::matches_any( $id . ' ' . $url, $patterns );

	}

	/*
	BUNDLE MARKUP
	-- The tag replacing one run: an inline <style> or one <link> to the
	-- cached bundle file, or '' to keep the original tags. The bundle is
	-- built by the cache warmer, cron or a scan; a visitor meanwhile gets
	-- the original links, and that page is kept out of Octave's page cache
	---------------------------------------------------------- */

	protected function bundle_markup( array $run, int $number ): string {

		$sources = array_column( $run, 'source' );
		$key     = substr( md5( wp_json_encode( [ $sources, array_column( $run, 'media' ), Octave_Addons_Perf_Cache::generation(), OCTAVE_ADDONS_VERSION ] ) ), 0, 16 );
		$dir     = Octave_Addons_Perf_Store::dir( Octave_Addons_Perf_Cache::MIN_DIR );
		$name    = 'bundle-' . $key . '.css';
		$files   = array_values( array_filter( array_column( $run, 'href' ) ) );

		if ( ! file_exists( $dir . $name ) ) {

			if ( get_transient( 'oa_perf_min_fail_' . $key ) ) {

				return '';

			}

			if ( $this->defer_minify( $key, 'bundle' ) ) {

				Octave_Addons_Perf_Disk_Cache::skip( 'incomplete' );
				Octave_Addons_Perf_Log::summary( 'css_bundle', [ 'mode' => 'queued', 'bytes' => 0, 'files' => $files ] );

				return '';

			}

			$css = self::build_bundle( $run );

			if ( null === $css ) {

				set_transient( 'oa_perf_min_fail_' . $key, 1, DAY_IN_SECONDS );
				Octave_Addons_Perf_Log::error( 'files', __( 'Breakdance CSS could not be bundled safely, so the original stylesheets are served.', 'octave-addons' ), implode( ', ', $files ) );

				return '';

			}

			if ( ! Octave_Addons_Perf_Store::write( $dir . $name, $css ) ) {

				set_transient( 'oa_perf_min_fail_' . $key, 1, HOUR_IN_SECONDS );

				return '';

			}

		}

		$size = (int) filesize( $dir . $name );

		/**
		 * Filters the largest Breakdance CSS bundle inlined into the page, in bytes.
		 *
		 * @param int $bytes Larger bundles are linked as one cached file instead.
		 */
		$inline = $size <= (int) apply_filters( 'octave_addons_perf_bundle_inline_max', self::BUNDLE_INLINE_MAX );

		Octave_Addons_Perf_Log::summary( 'css_bundle', [ 'mode' => $inline ? 'inline' : 'file', 'bytes' => $size, 'files' => $files ] );

		if ( $inline ) {

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Octave's own cache file.
			return '<style id="oa-css-bundle-' . $number . '" data-oa-bundle="' . count( $files ) . '">' . (string) file_get_contents( $dir . $name ) . '</style>';

		}

		return '<link rel="stylesheet" id="oa-css-bundle-' . $number . '-css" href="' . esc_url( Octave_Addons_Perf_Store::url( Octave_Addons_Perf_Cache::MIN_DIR ) . $name ) . '" data-oa-bundle="' . count( $files ) . '">';

	}

	/*
	BUILD BUNDLE
	-- The run's CSS in order: relative URLs made absolute against each
	-- file, @charset dropped, and each part under its own media condition.
	-- null when any part is unsafe or the whole is too large
	---------------------------------------------------------- */

	public static function build_bundle( array $run ): ?string {

		$parts = [];
		$total = 0;

		foreach ( $run as $item ) {

			if ( 'keep' === $item['kind'] ) {

				continue;

			}

			if ( 'file' === $item['kind'] ) {

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
				$css  = (string) file_get_contents( $item['file'] );
				$base = 0 === strpos( $item['href'], '/' ) && 0 !== strpos( $item['href'], '//' ) ? home_url( $item['href'] ) : $item['href'];

				if ( false !== stripos( $css, '@import' ) ) {

					return null;

				}

				$css = Octave_Addons_Perf_Minifier::absolutize_css_urls( $css, $base );

			} else {

				$css = (string) $item['css'];

			}

			$css = trim( (string) preg_replace( '/^\s*@charset\s+[^;]+;/i', '', $css ) );

			if ( false !== stripos( $css, '</style' ) || null === Octave_Addons_Perf_Css::rules( $css ) ) {

				return null;

			}

			$media   = (string) ( $item['media'] ?? '' );
			$parts[] = '' !== $media && 'all' !== strtolower( $media ) ? '@media ' . $media . '{' . $css . '}' : $css;
			$total  += strlen( $css );

			if ( $total > self::BUNDLE_MAX ) {

				return null;

			}

		}

		return implode( "\n", $parts );

	}

	/*
	ALLOT BUDGET
	-- Page-specific files are served first, then the rest in page order,
	-- until the per-page budget runs out. Returns the chosen candidates in
	-- page order, keyed by offset
	---------------------------------------------------------- */

	public static function allot_budget( array $candidates, int $budget ): array {

		$order = array_keys( $candidates );

		usort( $order, static function ( int $a, int $b ) use ( $candidates ): int {

			return [ $candidates[ $b ]['page'], $a ] <=> [ $candidates[ $a ]['page'], $b ];

		} );

		$chosen = [];

		foreach ( $order as $offset ) {

			if ( $candidates[ $offset ]['size'] <= $budget ) {

				$budget           -= $candidates[ $offset ]['size'];
				$chosen[ $offset ] = $candidates[ $offset ];

			}

		}

		ksort( $chosen );

		return $chosen;

	}

	/*
	INLINE CANDIDATE
	-- What one <link> would inline, or [] to keep it as a link
	---------------------------------------------------------- */

	protected function inline_candidate( string $tag, int $limit ): array {

		$tags = new WP_HTML_Tag_Processor( $tag );

		if ( ! $tags->next_tag( 'LINK' ) ) {

			return [];

		}

		$rel  = preg_split( '/\s+/', strtolower( trim( (string) $tags->get_attribute( 'rel' ) ) ) );
		$href = html_entity_decode( trim( (string) $tags->get_attribute( 'href' ) ) );

		if ( ! in_array( 'stylesheet', (array) $rel, true ) || in_array( 'alternate', (array) $rel, true ) || '' === $href ) {

			return [];

		}

		foreach ( [ 'integrity', 'disabled', 'onload', 'data-oa-no-inline' ] as $attribute ) {

			if ( null !== $tags->get_attribute( $attribute ) ) {

				return [];

			}

		}

		if ( ! Octave_Addons_Perf::is_same_origin( $href ) || '' !== $this->inline_exclusion( (string) $tags->get_attribute( 'id' ), $href ) || '' !== self::shared_pattern( $href ) ) {

			return [];

		}

		$file = self::inline_file( $href );

		if ( empty( $file ) || $file['size'] > $limit ) {

			return [];

		}

		return $file + [
			'href'  => $href,
			'id'    => (string) $tags->get_attribute( 'id' ),
			'media' => trim( (string) $tags->get_attribute( 'media' ) ),
			'page'  => (bool) preg_match( self::PAGE_SPECIFIC, (string) wp_parse_url( $href, PHP_URL_PATH ) ),
		];

	}

	/*
	SHARED PATTERN
	-- Stylesheets loaded by many pages: inlining them would send the same
	-- bytes with every page instead of once
	---------------------------------------------------------- */

	public static function shared_pattern( string $href ): string {

		$path = (string) wp_parse_url( $href, PHP_URL_PATH );

		if ( false !== strpos( $path, '/breakdance/css/' ) && ! preg_match( self::PAGE_SPECIFIC, $path ) ) {

			return '/breakdance/css/';

		}

		/**
		 * Filters the URL patterns of stylesheets shared by many pages, which are never inlined.
		 *
		 * @param string[] $patterns Case-insensitive substrings.
		 */
		return Octave_Addons_Perf::matches_any( $path, (array) apply_filters( 'octave_addons_perf_inline_shared_patterns', self::SHARED_PATTERNS ) );

	}

	/*
	INLINE FILE
	-- Size and inline-ready CSS of a local stylesheet, or [] when it cannot
	-- be inlined. Cached against the file's modification time, size and the
	-- cache generation, so an edited file or a purge is always seen
	---------------------------------------------------------- */

	public static function inline_file( string $href ): array {

		$path = Octave_Addons_Perf_Admin::local_path( $href );

		if ( '' === $path || 'css' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {

			return [];

		}

		$size = (int) filesize( $path );
		$key  = md5( $path . '|' . filemtime( $path ) . '|' . $size . '|' . Octave_Addons_Perf_Cache::generation() . '|' . $href );

		$cached = wp_cache_get( $key, 'octave_addons_perf_inline' );

		if ( is_array( $cached ) ) {

			return $cached;

		}

		$file = [];

		if ( $size <= self::INLINE_SIZES[ count( self::INLINE_SIZES ) - 1 ] * KB_IN_BYTES ) {

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			$css = (string) file_get_contents( $path );

			if ( '' !== trim( $css ) && false === stripos( $css, '</style' ) && false === stripos( $css, '@import' ) ) {

				// Relative url()s were relative to the file; @charset means nothing inline.
				$css  = Octave_Addons_Perf_Minifier::absolutize_css_urls( $css, 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ? home_url( $href ) : $href );
				$css  = (string) preg_replace( '/^\s*@charset\s+[^;]+;/i', '', $css );
				$file = [ 'size' => $size, 'css' => trim( $css ) ];

			}

		}

		wp_cache_set( $key, $file, 'octave_addons_perf_inline', DAY_IN_SECONDS );

		return $file;

	}

	/*
	INLINE MARKUP
	-- The <style> replacing one chosen <link>
	---------------------------------------------------------- */

	protected function inline_markup( array $candidate ): string {

		return sprintf(
			'<style%s%s data-oa-inlined="%s">%s</style>',
			'' !== $candidate['id'] ? ' id="' . esc_attr( $candidate['id'] ) . '"' : '',
			'' !== $candidate['media'] && 'all' !== strtolower( $candidate['media'] ) ? ' media="' . esc_attr( $candidate['media'] ) . '"' : '',
			esc_attr( $candidate['href'] ),
			$candidate['css']
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

		if ( $this->defer_minify( $key ) ) {

			Octave_Addons_Perf_Disk_Cache::skip( 'incomplete' );

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
	DEFER MINIFY
	-- Minifying takes time, so a visitor is served the original while the
	-- cache warmer requests the page and creates the copy. If no warmer has
	-- run ten minutes later, cron is not working and the visitor's request
	-- does the work after all
	---------------------------------------------------------- */

	protected function defer_minify( string $key, string $reason = 'minify' ): bool {

		if ( Octave_Addons_Perf_Page_Cache::is_warm_request() ) {

			return false;

		}

		$wanted = (int) get_transient( 'oa_perf_min_wanted_' . $key );

		if ( $wanted && $wanted < time() - 10 * MINUTE_IN_SECONDS ) {

			return false;

		}

		if ( ! $wanted ) {

			set_transient( 'oa_perf_min_wanted_' . $key, time(), HOUR_IN_SECONDS );

			$path   = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only the path is used, and normalised below.
			$origin = (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', home_url() );

			Octave_Addons_Perf_Page_Cache::queue_warm( $reason, [ $origin . ( '' !== $path ? $path : '/' ) ] );

		}

		return true;

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

		<p class="oa-help oa-help--intro"><?php esc_html_e( 'Compatibility first: each file is minified on its own and keeps its place in the cascade. Breakdance CSS delivery builds the first-render CSS from exactly the stylesheets Breakdance links for each page, in their original order. Removing "unused" CSS and loading layout CSS late are deliberately not offered, as they are the most common cause of broken layouts, menus and interactive states.', 'octave-addons' ); ?></p>

		<table class="form-table oa-form-table" role="presentation">
			<?php

			Octave_Addons_Fields::section( [ 'label' => __( 'Minification', 'octave-addons' ), 'first' => true ] );

			$this->switch_row( 'minify_css', __( 'Minify local CSS', 'octave-addons' ), __( 'Recommended when files are unminified. Removes comments and whitespace from each local stylesheet; relative image and font URLs are rewritten so they keep working.', 'octave-addons' ), $s );
			$this->switch_row( 'minify_js', __( 'Minify local JavaScript', 'octave-addons' ), __( 'Advanced. Removes comments and indentation only, keeping every line break so behaviour cannot change. Savings are modest; test interactive features after enabling.', 'octave-addons' ), $s );

			Octave_Addons_Fields::section( [ 'label' => __( 'Breakdance CSS delivery', 'octave-addons' ) ] );

			$this->switch_row( 'breakdance_css', __( 'Deliver Breakdance CSS as one bundle', 'octave-addons' ), __( 'Recommended for Breakdance sites. A Breakdance page links many small stylesheets (normalize, dependencies, fonts, global settings, presets, selectors and one per page, header, footer and template), and the browser shows nothing until every one has downloaded. Each unbroken run of Breakdance and Octave stylesheets becomes one bundle with the same CSS in the same order and media: placed in the page when under 96 KB, otherwise served as one cached file. Bundles are prepared by the cache warmer, so visitors are never kept waiting while one is built; until then the original links are served.', 'octave-addons' ), $s );

			Octave_Addons_Fields::section( [ 'label' => __( 'Small stylesheets', 'octave-addons' ) ] );

			$this->switch_row( 'inline_css', __( 'Inline small stylesheets', 'octave-addons' ), __( 'Recommended. Every stylesheet in the page has to download before anything is shown. Small local ones, such as the per-page CSS Breakdance writes, are placed straight into the page instead, in the same order, so the first paint waits on fewer requests. Large files stay as links, so they can still be cached between pages.', 'octave-addons' ), $s );

			$this->select_row( 'inline_max', __( 'Inline files up to', 'octave-addons' ), [
				4  => __( '4 KB (recommended)', 'octave-addons' ),
				8  => '8 KB',
				16 => '16 KB',
			], __( 'Larger limits remove more requests but make every page heavier, as inlined CSS is downloaded again with each page instead of being cached. Breakdance\'s per-page files are inlined first; plugin, WordPress and Breakdance global stylesheets are shared by every page, so they always stay as cached links.', 'octave-addons' ), $s );

			Octave_Addons_Fields::section( [ 'label' => __( 'Deferral', 'octave-addons' ) ] );

			$this->switch_row( 'defer_js', __( 'Defer selected local JavaScript', 'octave-addons' ), __( 'Advanced. Lets the scripts listed below download without blocking the page (WordPress 6.3+). WordPress skips any script whose dependents or inline code need it to run immediately. jQuery and Breakdance are never deferred.', 'octave-addons' ), $s );
			$this->textarea_row( 'defer_handles', __( 'Script handles to defer', 'octave-addons' ), __( 'One registered script handle per line, for example contact-form-7.', 'octave-addons' ), $s );

			Octave_Addons_Fields::section( [ 'label' => __( 'Exclusions', 'octave-addons' ) ] );

			$this->textarea_row( 'exclude', __( 'Never minify, inline or bundle', 'octave-addons' ), __( 'One handle, stylesheet id or URL pattern per line. Breakdance assets, WordPress core and files already ending in .min.css or .min.js are never minified. A stylesheet can also opt out of inlining and bundling with the data-oa-no-inline attribute, or of bundling alone with data-oa-no-bundle.', 'octave-addons' ), $s );

			?>
		</table>

		<p class="oa-help"><?php esc_html_e( 'To delay third-party scripts such as analytics, use Script Delay above.', 'octave-addons' ); ?></p>

		<?php

	}

}

return new Octave_Addons_Module_Performance_Files();
