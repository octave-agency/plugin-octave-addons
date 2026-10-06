<?php

/*
BREAKDANCE ICON FIELD TESTS
-- The Icon post field: icons searched from Breakdance's library, stored as
-- SVG rebuilt from an allowlist, and the search endpoint kept to editors
---------------------------------------------------------- */

namespace Breakdance\Icons {

	/*
	BREAKDANCE ICON LIBRARY STUBS
	-- Two icons in two sets, filtered the way Breakdance filters them
	---------------------------------------------------------- */

	function get_icon_sets() {

		return [
			[ 'slug' => 'FontAwesome 6 Free - Solid', 'name' => 'FontAwesome 6 Free - Solid', 'custom' => false ],
			[ 'slug' => 'Brand', 'name' => 'Brand icons', 'custom' => true ],
		];

	}

	function find_icons( $options, $limit = 100 ) {

		$GLOBALS['oa_icon_queries'][] = [ $options, $limit ];

		$icons = [
			[ 'id' => 1, 'slug' => 'icon-arrow-right.', 'name' => 'arrow right', 'iconSetSlug' => 'FontAwesome 6 Free - Solid', 'svgCode' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><!--! Font Awesome --><path d="M438 278l-160 160"/></svg>' ],
			[ 'id' => 2, 'slug' => 'logo', 'name' => 'logo', 'iconSetSlug' => 'Brand', 'svgCode' => '<svg viewBox="0 0 24 24" onload="alert(1)"><circle cx="12" cy="12" r="10"/></svg>' ],
		];

		return array_values( array_filter( $icons, static function ( $icon ) use ( $options ) {

			return ( null === $options['icon_set_slug'] || $icon['iconSetSlug'] === $options['icon_set_slug'] )
				&& ( null === $options['search_term'] || false !== strpos( $icon['name'], $options['search_term'] ) );

		} ) );

	}

}

namespace {

	/*
	HELPERS
	---------------------------------------------------------- */

	function oa_icon_sanitizer(): Closure {

		$fields = ( new ReflectionClass( 'Octave_Addons_Custom_Post_Fields' ) )->newInstanceWithoutConstructor();

		return Closure::bind( function ( $value ) {

			return $this->sanitize_value( $value, [ 'type' => 'icon' ] );

		}, $fields, 'Octave_Addons_Custom_Post_Fields' );

	}

	/*
	SANITIZING
	---------------------------------------------------------- */

	function test_icon_svg_keeps_only_safe_svg(): void {

		$dirty = '<svg viewBox="0 0 24 24" onload="alert(1)" style="x"><script>alert(2)</script>'
			. '<a href="javascript:alert(3)"><path d="M0 0h24" onclick="x"/></a>'
			. '<foreignObject><div>text</div></foreignObject>'
			. '<defs><linearGradient id="g"><stop offset="0" stop-color="#000"/></linearGradient></defs>'
			. '<rect fill="url(#g)" width="4" height="4"/><rect fill="url(https://evil.test/x)" width="1" height="1"/>'
			. '<use href="#g"/></svg><p>after</p>';

		$clean = Octave_Addons_Breakdance_Icons::sanitize( $dirty );

		foreach ( [ 'onload', 'onclick', 'style', 'script', 'alert', 'href', '<a', 'foreignobject', 'text', 'evil.test', '<use', 'after' ] as $unsafe ) {

			oa_assert_not_contains( $unsafe, strtolower( $clean ), $unsafe );

		}

		oa_assert_contains( '<svg viewBox="0 0 24 24">', $clean, 'the icon itself survives' );
		oa_assert_contains( '<path d="M0 0h24"/>', $clean, 'shapes inside a stripped link survive' );
		oa_assert_contains( '<rect fill="url(#g)" width="4" height="4"/>', $clean, 'gradients inside the icon still work' );
		oa_assert_contains( '<rect width="1" height="1"/>', $clean, 'outside references dropped' );
		oa_assert_same( '</svg>', substr( $clean, -6 ), 'balanced' );
		oa_assert_contains( '<linearGradient id="g">', $clean, 'SVG names keep their case, so the value also works as XML' );

	}

	function test_anything_that_is_not_an_svg_is_refused(): void {

		foreach ( [ '', 'hello', '<div><p>no</p></div>', '<img src=x onerror=alert(1)>', str_repeat( '<svg>', 30000 ) ] as $value ) {

			oa_assert_same( '', Octave_Addons_Breakdance_Icons::sanitize( $value ) );

		}

	}

	function test_the_icon_field_saves_cleaned_svg_with_its_name_and_set(): void {

		$value    = Octave_Addons_Breakdance_Icons::value( '<svg viewBox="0 0 24 24"><path d="M1 1"/></svg>', 'arrow right', 'FontAwesome 6 Free - Solid' );
		$sanitize = oa_icon_sanitizer();

		oa_assert_same( $value, $sanitize( $value ), 'a picked icon saves unchanged' );
		oa_assert_same( 'arrow right', Octave_Addons_Breakdance_Icons::name( $sanitize( $value ) ) );
		oa_assert_same( 'FontAwesome 6 Free - Solid', Octave_Addons_Breakdance_Icons::set( $sanitize( $value ) ) );
		oa_assert_same( '', $sanitize( '<script>alert(1)</script>' ), 'a forged value is refused' );
		oa_assert_same( '', $sanitize( [ 'not', 'a', 'string' ] ) );

	}

	/*
	LIBRARY
	---------------------------------------------------------- */

	function test_icons_are_searched_from_the_breakdance_library(): void {

		oa_assert( Octave_Addons_Breakdance_Icons::available() );
		oa_assert_same( [ 'FontAwesome 6 Free - Solid' => 'FontAwesome 6 Free - Solid', 'Brand' => 'Brand icons' ], Octave_Addons_Breakdance_Icons::sets() );

		$icons = Octave_Addons_Breakdance_Icons::search( 'arrow', '', 0 );

		oa_assert_same( 1, count( $icons ) );
		oa_assert_same( 'arrow right', $icons[0]['name'] );
		oa_assert_same( 'FontAwesome 6 Free - Solid', $icons[0]['set'] );
		oa_assert_contains( '<path d="M438 278l-160 160"/>', $icons[0]['value'] );
		oa_assert_same( Octave_Addons_Breakdance_Icons::PER_PAGE, $GLOBALS['oa_icon_queries'][0][1], 'one page at a time' );

		$brand = Octave_Addons_Breakdance_Icons::search( '', 'Brand', 0 );

		oa_assert_not_contains( 'onload', $brand[0]['value'], 'library SVG is cleaned too' );

	}

	function test_icon_search_needs_an_editor_and_a_valid_nonce(): void {

		$_POST = [ 'nonce' => 'forged', 'search' => 'arrow' ];
		$GLOBALS['oa_caps'] = [ 'edit_posts' ];

		oa_assert_same( 403, oa_json_call( [ 'Octave_Addons_Breakdance_Icons', 'ajax_search' ] )->status, 'forged nonce' );

		$_POST['nonce']     = 'valid-' . Octave_Addons_Breakdance_Icons::NONCE;
		$GLOBALS['oa_caps'] = [];

		oa_assert_same( 403, oa_json_call( [ 'Octave_Addons_Breakdance_Icons', 'ajax_search' ] )->status, 'not an editor' );

		$GLOBALS['oa_caps'] = [ 'edit_posts' ];
		$_POST['set']       = 'Not a real set';

		$response = oa_json_call( [ 'Octave_Addons_Breakdance_Icons', 'ajax_search' ] );

		oa_assert( $response->success );
		oa_assert_same( 'arrow right', $response->data['icons'][0]['name'], 'unknown sets are ignored' );
		oa_assert_same( false, $response->data['more'] );

	}

	function test_icon_is_offered_as_a_field_type(): void {

		$module = oa_module( 'custom-post-types' );
		$types  = new ReflectionMethod( $module, 'field_types' );

		oa_assert_same( 'Breakdance icon', $types->invoke( $module )['icon'] ?? null );

		$sub = new ReflectionMethod( $module, 'sub_field_types' );

		oa_assert( isset( $sub->invoke( $module )['icon'] ), 'usable inside groups and repeaters' );

	}

}
