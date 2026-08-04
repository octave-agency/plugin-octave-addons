<?php

/*
OA COPYRIGHT — SERVER RENDER
-- Built in PHP so the year uses the site's timezone via wp_date() and the
-- name can fall back to the site title.
---------------------------------------------------------- */

/**
 * @var array $propertiesData
 */

$oa_copyright = $propertiesData['content']['copyright'] ?? [];

$oa_prefix     = trim( (string) ( $oa_copyright['prefix'] ?? '' ) );
$oa_suffix     = trim( (string) ( $oa_copyright['suffix'] ?? '' ) );
$oa_name       = trim( (string) ( $oa_copyright['name'] ?? '' ) );
$oa_start_year = trim( (string) ( $oa_copyright['start_year'] ?? '' ) );
$oa_symbol     = ! empty( $oa_copyright['symbol'] );
$oa_link       = $oa_copyright['link'] ?? [];

if ( '' === $oa_name ) {

	$oa_name = (string) get_bloginfo( 'name' );

}

$oa_current_year = function_exists( 'wp_date' ) ? wp_date( 'Y' ) : gmdate( 'Y' );
$oa_year         = $oa_current_year;

if ( '' !== $oa_start_year && ctype_digit( $oa_start_year ) && $oa_start_year < $oa_current_year ) {

	$oa_year = $oa_start_year . ' &ndash; ' . $oa_current_year;

}

$oa_name_html = esc_html( $oa_name );

if ( '' !== $oa_name && ! empty( $oa_link['url'] ) ) {

	$oa_rel = ! empty( $oa_link['nofollow'] ) ? ' rel="nofollow"' : '';
	$oa_tgt = ! empty( $oa_link['target'] ) ? ' target="' . esc_attr( $oa_link['target'] ) . '"' : '';

	$oa_name_html = '<a class="oa_copyright-link" href="' . esc_url( $oa_link['url'] ) . '"' . $oa_tgt . $oa_rel . '>' . esc_html( $oa_name ) . '</a>';

}

$oa_parts = [];

if ( '' !== $oa_prefix ) {

	$oa_parts[] = esc_html( $oa_prefix );

}

if ( $oa_symbol ) {

	$oa_parts[] = '&copy;';

}

$oa_parts[] = $oa_year;

if ( '' !== $oa_name ) {

	$oa_parts[] = $oa_name_html;

}

$oa_output = implode( ' ', $oa_parts );

if ( '' !== $oa_suffix ) {

	$oa_output .= '. ' . esc_html( $oa_suffix );

}

echo $oa_output;
