<?php

/*
AJAX ASSETS
-- Enqueues the legacy Octave AJAX helper script
---------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', 'octave_enqueue_scripts' );

function octave_enqueue_scripts() {

    wp_enqueue_script(
        'octave-ajax',
        OCTAVE_PLUGIN_URL . 'assets/js/ajax.js',
        array( 'jquery' ),
        '1.0',
        true
    );

    wp_localize_script(
        'octave-ajax',
        'octaveData',
        array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'octave_nonce' ),
        )
    );

}
