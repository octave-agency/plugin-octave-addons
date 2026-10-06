<?php

/*
BREAKDANCE HEADING TAG TESTS
-- The native Heading element gains p and div choices without changing other
-- Breakdance element controls
---------------------------------------------------------- */

class OA_Breakdance_Heading_Element {

	public static function slug(): string {

		return 'EssentialElements\\Heading';

	}

}

class OA_Breakdance_Text_Element {

	public static function slug(): string {

		return 'EssentialElements\\Text';

	}

}

function oa_heading_tag_controls(): array {

	return [
		'contentSections' => [
			[
				'slug'     => 'content',
				'children' => [
					[
						'slug'    => 'tags',
						'options' => [
							'type'  => 'dropdown',
							'items' => [
								[ 'text' => 'h1', 'value' => 'h1' ],
								[ 'text' => 'h2', 'value' => 'h2' ],
							],
						],
					],
				],
			],
		],
	];

}

function test_breakdance_heading_tags_module_is_always_enabled_and_hidden(): void {

	$module = oa_module( 'breakdance-heading-tags' );

	oa_assert( $module->is_always_enabled() );
	oa_assert( ! $module->show_in_admin() );

}

function test_breakdance_heading_gains_paragraph_and_div_tags_once(): void {

	$module = oa_module( 'breakdance-heading-tags' );
	$module->run( [] );

	$controls = apply_filters( 'breakdance_element_controls', oa_heading_tag_controls(), new OA_Breakdance_Heading_Element() );
	$controls = apply_filters( 'breakdance_element_controls', $controls, new OA_Breakdance_Heading_Element() );
	$items    = $controls['contentSections'][0]['children'][0]['options']['items'];
	$values   = array_column( $items, 'value' );

	oa_assert_same( [ 'h1', 'h2', 'p', 'div' ], $values );

}

function test_breakdance_heading_tags_leave_other_elements_unchanged(): void {

	$module = oa_module( 'breakdance-heading-tags' );
	$module->run( [] );

	$controls = oa_heading_tag_controls();
	$filtered = apply_filters( 'breakdance_element_controls', $controls, new OA_Breakdance_Text_Element() );

	oa_assert_same( $controls, $filtered );

}
