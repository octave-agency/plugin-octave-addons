<?php

/*
OA READING TIME — SERVER RENDER
-- Counts words in the post being rendered and converts them to minutes.
-- Shortcodes and HTML are stripped first so markup never inflates the count.
---------------------------------------------------------- */

/**
 * @var array $propertiesData
 */

$oa_reading = $propertiesData['content']['reading'] ?? [];

$oa_wpm          = max( 1, (int) ( $oa_reading['wpm'] ?? 220 ) );
$oa_display      = (string) ( $oa_reading['display'] ?? 'time' );
$oa_minimum      = max( 0, (int) ( $oa_reading['minimum'] ?? 1 ) );
$oa_prefix       = trim( (string) ( $oa_reading['prefix'] ?? '' ) );
$oa_suffix       = trim( (string) ( $oa_reading['suffix'] ?? 'min read' ) );
$oa_words_suffix = trim( (string) ( $oa_reading['words_suffix'] ?? 'words' ) );

$oa_post = get_post();

$oa_content = $oa_post ? (string) $oa_post->post_content : '';
$oa_content = strip_shortcodes( $oa_content );
$oa_content = wp_strip_all_tags( $oa_content );

$oa_words = $oa_content ? preg_match_all( '/\S+/u', $oa_content ) : 0;
$oa_words = is_int( $oa_words ) ? $oa_words : 0;

$oa_minutes = (int) ceil( $oa_words / $oa_wpm );

if ( $oa_minutes < $oa_minimum ) {

	$oa_minutes = $oa_minimum;

}

$oa_parts = [];

if ( 'words' !== $oa_display ) {

	$oa_parts[] = trim( $oa_minutes . ( '' !== $oa_suffix ? ' ' . $oa_suffix : '' ) );

}

if ( 'time' !== $oa_display ) {

	$oa_parts[] = trim( number_format_i18n( $oa_words ) . ( '' !== $oa_words_suffix ? ' ' . $oa_words_suffix : '' ) );

}

$oa_output = implode( ' · ', $oa_parts );

if ( '' !== $oa_prefix ) {

	$oa_output = $oa_prefix . ' ' . $oa_output;

}

echo esc_html( $oa_output );
