<?php

/*
FILE OPTIMIZATION TESTS
-- Minifier correctness, safe fallback and tag rewriting
---------------------------------------------------------- */

function oa_theme_file( string $name, string $contents ): string {

	$path = WP_CONTENT_DIR . '/themes/site/' . $name;

	@mkdir( dirname( $path ), 0777, true );
	file_put_contents( $path, $contents );

	return 'https://example.com/wp-content/themes/site/' . $name;

}

function oa_files( array $values = [] ): Octave_Addons_Module_Performance_Files {

	$module = oa_module( 'performance-files' );

	$module->run( oa_set_settings( 'performance-files', array_merge( [ 'enabled' => true, 'minify_css' => true, 'minify_js' => true ], $values ) ) );

	return $module;

}

function test_css_minifier_keeps_meaningful_whitespace(): void {

	$css = "/*! licence */\n/* note */\n.a > .b ,\n.c {\n  width : calc( 100% - 2px );\n  content: \"a  /* b */ c\";\n  margin: 0 auto ;\n}\n@media screen and (min-width: 600px) { .d { color: red; } }";

	oa_assert_same(
		"/*! licence */\n.a>.b,.c{width : calc( 100% - 2px );content: \"a  /* b */ c\";margin: 0 auto}@media screen and (min-width: 600px){.d{color: red}}",
		Octave_Addons_Perf_Minifier::css( $css )
	);

	oa_assert_same( null, Octave_Addons_Perf_Minifier::css( 'a { color: red; /* never closed' ), 'unterminated comment' );

}

function test_js_minifier_output_behaves_identically_in_node(): void {

	if ( '' === trim( (string) shell_exec( 'command -v node' ) ) ) {

		oa_assert( true );

		return;

	}

	$js = <<<'JS'
/* header comment */
var out = [];
// a line comment with 'quotes' and `ticks`
var url = "http://example.com/a//b"; // trailing comment
var pattern = /\/\/+[a-z]*/g, other = /[/*]+/;
var ratio = 10 / 2 / 5;
var label = 'it\'s /* not */ a comment';
var nested = `outer ${ [ 1, 2 ].map( function ( n ) { return `inner ${ n } // still text`; } ).join( '|' ) } end`;
var a = 1
var b = a
;( function () { out.push( 'iife' ) } )()
function size( x ) {
	return /*! keep */ x.length
}
out.push( url, url.match( pattern ).join( ',' ), other.test( '/*' ), ratio, label, nested, b, size( 'abc' ) );
if ( a ) /x/.test( 'x' ) && out.push( 'regex after paren' );
console.log( JSON.stringify( out ) );
JS;

	$minified = Octave_Addons_Perf_Minifier::js( $js );

	oa_assert( is_string( $minified ) && strlen( $minified ) < strlen( $js ), 'minified' );
	oa_assert_not_contains( 'header comment', $minified );
	oa_assert_contains( '/*! keep */', $minified );

	$run = static function ( string $code ): string {

		$file = tempnam( sys_get_temp_dir(), 'oa-js' );

		file_put_contents( $file, $code );

		$output = (string) shell_exec( 'node ' . escapeshellarg( $file ) . ' 2>&1' );

		unlink( $file );

		return trim( $output );

	};

	$expected = $run( $js );

	oa_assert( 0 === strpos( $expected, '["iife","http://example.com/a//b"' ), 'sample runs: ' . $expected );
	oa_assert_same( $expected, $run( $minified ), 'same behaviour after minification' );

}

function test_js_minifier_refuses_what_it_cannot_scan(): void {

	oa_assert_same( null, Octave_Addons_Perf_Minifier::js( 'var a = "unterminated;' ) );
	oa_assert_same( null, Octave_Addons_Perf_Minifier::js( 'var a = `open ${ b ' ) );
	oa_assert_same( null, Octave_Addons_Perf_Minifier::js( '/* open comment' ) );

}

function test_css_urls_are_rewritten_against_the_source(): void {

	$css = '.a{background:url(img/a.png)}.b{background:url("../fonts/f.woff2")}.c{background:url(data:image/png;base64,AA)}.d{background:url(/abs.png)}@import "other.css";';

	oa_assert_same(
		'.a{background:url(https://example.com/wp-content/themes/site/css/img/a.png)}.b{background:url("https://example.com/wp-content/themes/site/css/../fonts/f.woff2")}.c{background:url(data:image/png;base64,AA)}.d{background:url(/abs.png)}@import "https://example.com/wp-content/themes/site/css/other.css";',
		Octave_Addons_Perf_Minifier::absolutize_css_urls( $css, 'https://example.com/wp-content/themes/site/css/style.css?ver=1' )
	);

}

function test_minified_copy_is_cached_and_tag_keeps_its_attributes(): void {

	$url    = oa_theme_file( 'style.css', ".a {\n  color: red;\n}\n" );
	$module = oa_files();
	$tag    = "<link rel='stylesheet' id='site-css' href='" . $url . "?ver=1.2' media='print' />\n";
	$out    = $module->filter_style_tag( $tag, 'site', $url . '?ver=1.2', 'print' );

	oa_assert_contains( "media='print'", $out, 'media attribute kept' );
	oa_assert_contains( 'id=\'site-css\'', $out );
	oa_assert_contains( '/cache/octave-addons/min/site-', $out );

	preg_match( '#/min/(site-[a-f0-9]{12}\.min\.css)#', $out, $match );

	oa_assert_same( '.a{color: red}', file_get_contents( Octave_Addons_Perf_Store::dir( 'min' ) . $match[1] ) );
	oa_assert_same( $out, $module->filter_style_tag( $tag, 'site', $url . '?ver=1.2', 'print' ), 'cached copy reused' );

}

function test_minification_falls_back_to_original_and_logs(): void {

	$url    = oa_theme_file( 'broken.js', "var a = 1; /* never closed\n" );
	$module = oa_files();
	$tag    = '<script src="' . $url . '" id="broken-js"></script>';

	oa_assert_same( $tag, $module->filter_script_tag( $tag, 'broken', $url ) );
	oa_assert_same( 'files', Octave_Addons_Perf_Log::entries()[0]['feature'] );
	oa_assert_same( $tag, $module->filter_script_tag( $tag, 'broken', $url ), 'still original on the next request' );

}

function test_ineligible_files_are_never_minified(): void {

	$module = oa_files( [ 'exclude' => 'skip-me' ] );

	$cases = [
		[ 'breakdance-css', oa_theme_file( 'breakdance/x.css', 'a { b: c; }' ) ],
		[ 'already', oa_theme_file( 'already.min.css', 'a { b: c; }' ) ],
		[ 'skip-me', oa_theme_file( 'mine.css', 'a { b: c; }' ) ],
		[ 'dynamic', oa_theme_file( 'dynamic.css', 'a { b: c; }' ) . '?color=red' ],
		[ 'remote', 'https://cdn.example.net/x.css' ],
	];

	foreach ( $cases as [ $handle, $url ] ) {

		oa_assert_same( '', $module->minified_url( $handle, $url, 'css' ), $handle );

	}

	$url = oa_theme_file( 'sri.css', 'a { b: c; }' );
	$tag = '<link rel="stylesheet" href="' . $url . '" integrity="sha384-x" crossorigin="anonymous">';

	oa_assert_same( $tag, $module->filter_style_tag( $tag, 'sri', $url ), 'integrity-protected file untouched' );

}

function test_minify_switches_are_independent(): void {

	oa_files( [ 'minify_css' => false, 'minify_js' => true ] );

	oa_assert( ! has_filter( 'style_loader_tag' ) && has_filter( 'script_loader_tag' ) );

}

/*
INLINE SMALL STYLESHEETS
---------------------------------------------------------- */

function test_small_stylesheets_are_inlined_in_place(): void {

	$small = oa_theme_file( 'small.css', "@charset \"UTF-8\";\n.a{background:url(img/a.png)}" );
	$large = oa_theme_file( 'large.css', '.b{color:red}' . str_repeat( ' ', 9 * 1024 ) );
	$page  = oa_page(
		'<link rel="stylesheet" id="small-css" href="' . $small . '?v=abc" media="screen">'
		. '<link rel="stylesheet" href="' . $large . '">'
		. '<noscript><link rel="stylesheet" href="' . $small . '"></noscript>'
		. '<link rel="stylesheet" href="' . $small . '" integrity="sha384-x">'
		. '<link rel="preload" as="style" href="' . $small . '">'
		. '<link rel="stylesheet" href="https://cdn.example.org/x.css">'
	);

	$html = oa_files( [ 'minify_css' => false, 'minify_js' => false ] )->inline_styles( $page );

	oa_assert_contains( '<style id="small-css" media="screen" data-oa-inlined="' . $small . '?v=abc">.a{background:url(https://example.com/wp-content/themes/site/img/a.png)}</style>', $html );
	oa_assert_not_contains( '@charset', $html );
	oa_assert_contains( '<link rel="stylesheet" href="' . $large . '">', $html, 'over the limit' );
	oa_assert_contains( '<noscript><link rel="stylesheet" href="' . $small . '"></noscript>', $html, 'noscript kept' );
	oa_assert_contains( 'integrity="sha384-x"', $html );
	oa_assert_contains( '<link rel="preload" as="style"', $html );
	oa_assert_contains( 'https://cdn.example.org/x.css', $html, 'remote kept' );

}

function test_inlining_respects_exclusions_and_imports(): void {

	$plain  = oa_theme_file( 'plain.css', '.a{color:red}' );
	$import = oa_theme_file( 'import.css', '@import url(other.css);.a{color:red}' );
	$page   = oa_page( '<link rel="stylesheet" href="' . $plain . '"><link rel="stylesheet" href="' . $import . '">' );

	$html = oa_files( [ 'exclude' => 'plain.css' ] )->inline_styles( $page );

	oa_assert_same( $page, $html, 'excluded and @import files stay as links' );

}
