<?php

/*
GLOBAL FUNCTIONS
-- Provides legacy frontend helpers used by older Octave sites
---------------------------------------------------------- */

/*
ADDITIONAL STYLES / SCRIPTS
-- Enqueues shared frontend dependencies
---------------------------------------------------------- */

function oa_styles_scripts() {

    global $post;

    wp_enqueue_script( 'jquery' );

}

add_action( 'wp_enqueue_scripts', 'oa_styles_scripts' );

/*
ICON
-- Returns an SVG icon from the plugin assets folder
--------------------------------------------------------- */

function icon( $name, $class = '' ) {

    $icon = file_get_contents( OCTAVE_PLUGIN_PATH . '/assets/icons/' . $name . '.svg' );
    
    if ( $class ) {

        return '<span class="' . $class . '">' . $icon . '</span>';

    }

    return $icon;

}

/*
LOADER
-- Returns the shared legacy loading indicator markup
--------------------------------------------------------- */

function loader( $extra_classes = '', $text = '' ) {

    return '<div class="loader absolute-cover ' . $extra_classes . '"><div class="inner txt-primary">' . icon( 'loader' ) . ( $text ? '<p class="text margin-gap-top bde-h6 txt-center">' . $text . '</p>' : '' ) . '</div></div>';

}
