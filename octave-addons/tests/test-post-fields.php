<?php

/*
POST FIELDS TESTS
-- CPT select references: unreadable posts stay hidden and cannot be newly
-- attached, while a reference already saved on the post survives a re-save
---------------------------------------------------------- */

/*
POST AND META STUBS
-- Posts live in $GLOBALS['oa_posts'] as id => [ type, status ], meta in
-- $GLOBALS['oa_meta'] as post id => [ key => value ].
---------------------------------------------------------- */

function get_post_status( $post_id ) {

	return $GLOBALS['oa_posts'][ $post_id ][1] ?? false;

}

function get_post_type( $post_id ) {

	return $GLOBALS['oa_posts'][ $post_id ][0] ?? false;

}

function metadata_exists( $type, $post_id, $key ) {

	return isset( $GLOBALS['oa_meta'][ $post_id ][ $key ] );

}

function get_post_meta( $post_id, $key, $single = false ) {

	return $GLOBALS['oa_meta'][ $post_id ][ $key ] ?? '';

}

/*
HELPERS
---------------------------------------------------------- */

function oa_post_fields_setup( int $saving_post_id ): Closure {

	$GLOBALS['oa_posts'] = [
		10 => [ 'page', 'publish' ],
		11 => [ 'page', 'private' ],
		12 => [ 'page', 'draft' ],
	];
	$GLOBALS['oa_meta']  = [ 1 => [ 'related' => '11' ] ];
	$GLOBALS['oa_flags']['logged_in'] = true;

	$fields = ( new ReflectionClass( 'Octave_Addons_Custom_Post_Fields' ) )->newInstanceWithoutConstructor();
	$field  = [ 'type' => 'cpt_select', 'reference_source' => 'page', 'meta_key' => 'related', 'default_value' => '' ];

	return Closure::bind(
		function ( $value ) use ( $field, $saving_post_id ) {

			$this->saving_post_id = $saving_post_id;

			return $this->sanitize_value( $value, $field );

		},
		$fields,
		'Octave_Addons_Custom_Post_Fields'
	);

}

/*
SANITIZE
---------------------------------------------------------- */

function test_cpt_select_rejects_new_unreadable_reference(): void {

	$sanitize = oa_post_fields_setup( 1 );

	oa_assert_same( '10', $sanitize( '10' ), 'published post is always allowed' );
	oa_assert_same( '', $sanitize( '12' ), 'unreadable draft cannot be attached' );

}

function test_cpt_select_keeps_existing_unreadable_reference(): void {

	$sanitize = oa_post_fields_setup( 1 );

	oa_assert_same( '11', $sanitize( '11' ), 'stored private reference survives a re-save' );

	$sanitize = oa_post_fields_setup( 2 );

	oa_assert_same( '', $sanitize( '11' ), 'same private post is refused on another post' );

}

function test_cpt_select_trusts_readers_and_logged_out_saves(): void {

	$sanitize = oa_post_fields_setup( 2 );

	$GLOBALS['oa_caps'] = [ 'read_post' ];
	oa_assert_same( '12', $sanitize( '12' ), 'user who can read the draft may attach it' );

	$GLOBALS['oa_caps'] = [];
	$GLOBALS['oa_flags']['logged_in'] = false;
	oa_assert_same( '12', $sanitize( '12' ), 'imports and WP-CLI are unaffected' );

}

function test_cpt_select_still_checks_post_type(): void {

	$sanitize = oa_post_fields_setup( 1 );

	$GLOBALS['oa_posts'][13] = [ 'post', 'publish' ];

	oa_assert_same( '', $sanitize( '13' ), 'wrong post type is refused' );

}
