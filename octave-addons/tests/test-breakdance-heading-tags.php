<?php

/*
BREAKDANCE HEADING TAG TESTS
-- The native Heading element gains p and span choices with global heading
-- typography without changing other Breakdance element controls
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

function test_breakdance_heading_gains_paragraph_and_span_tags_once(): void {

	$module = oa_module( 'breakdance-heading-tags' );
	$module->run( [] );

	$controls = apply_filters( 'breakdance_element_controls', oa_heading_tag_controls(), new OA_Breakdance_Heading_Element() );
	$controls = apply_filters( 'breakdance_element_controls', $controls, new OA_Breakdance_Heading_Element() );
	$items    = $controls['contentSections'][0]['children'][0]['options']['items'];
	$values   = array_column( $items, 'value' );

	oa_assert_same( [ 'h1', 'h2', 'p', 'span' ], $values );

}

function test_breakdance_heading_tags_leave_other_elements_unchanged(): void {

	$module = oa_module( 'breakdance-heading-tags' );
	$module->run( [] );

	$controls = oa_heading_tag_controls();
	$filtered = apply_filters( 'breakdance_element_controls', $controls, new OA_Breakdance_Text_Element() );

	oa_assert_same( $controls, $filtered );

}

function test_breakdance_paragraph_and_span_headings_receive_global_typography(): void {

	$module = oa_module( 'breakdance-heading-tags' );
	$module->run( [] );

	$template = apply_filters( 'breakdance_global_settings_css_twig_template_append', 'existing rules' );
	$template = apply_filters( 'breakdance_global_settings_css_twig_template_append', $template );

	oa_assert_contains( 'existing rules', $template );
	oa_assert_contains( '{{ builderPrefix }} p.bde-heading', $template );
	oa_assert_contains( '{{ builderPrefix }} span.bde-heading', $template );
	oa_assert_contains( 'font-family: var(--bde-heading-font-family)', $template );
	oa_assert_contains( 'color: var(--bde-headings-color)', $template );
	oa_assert_contains( 'macros.typography(settings.typography.advanced.headings.all_headings, settings)', $template );
	oa_assert_same( 1, substr_count( $template, 'OCTAVE BREAKDANCE NON-SEMANTIC HEADINGS' ) );

}
