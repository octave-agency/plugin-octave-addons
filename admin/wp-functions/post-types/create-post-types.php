<?php

/*
OUR WORK POST TYPE
-- Registers the legacy Our Work custom post type
---------------------------------------------------------- */

remove_filter( 'the_excerpt', 'wpautop' );

/*
WORK LABELS
---------------------------------------------------------- */

$labels = array(
    'name'                  => _x( 'Our Work', 'octave' ),
    'singular_name'         => _x( 'Work', 'octave' ),
    'menu_name'             => _x( 'Our Work', 'octave' ),
    'name_admin_bar'        => _x( 'Work', 'octave' ),
    'add_new'               => __( 'Add New', 'octave' ),
    'add_new_item'          => __( 'Add New Work', 'octave' ),
    'new_item'              => __( 'New Work', 'octave' ),
    'edit_item'             => __( 'Edit Work', 'octave' ),
    'view_item'             => __( 'View Work', 'octave' ),
    'all_items'             => __( 'All Work', 'octave' ),
);

$args = array(
    'labels'             => $labels,
    'public'             => true,
    'publicly_queryable' => true,
    'event_ui'           => true,
    'query_var'          => true,
    'menu_icon'          => 'dashicons-layout',
    'has_archive'        => 'our-work',
    'rewrite'            => array(
        'slug'       => 'our-work',
        'with_front' => false,
    ),
    'supports'           => array(
        'title',
        'editor',
        'thumbnail',
        'excerpt',
    ),
);

register_post_type( 'our_work', $args );
