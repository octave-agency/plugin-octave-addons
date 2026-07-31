<?php

/*
WORK TAXONOMIES
-- Registers legacy taxonomies for the Our Work post type
---------------------------------------------------------- */

register_taxonomy(
    'our_work_category',
    'our_work',
    array(
        'hierarchical'     => true,
        'label'            => 'Work Category',
        'public'           => true,
        'public_queryable' => false,
        'rewrite'          => array(
            'slug'         => 'work-category',
            'with_front'   => false,
            'hierarchical' => true 
        ),

        'show_admin_column' => true,
        'show_in_nadr_menus' => true,
    )
);

/*
WORK INDUSTRY TAXONOMY
---------------------------------------------------------- */

register_taxonomy(
    'our_work_industry',
    'our_work',
    array(
        'hierarchical'     => true,
        'label'            => 'Work Industry',
        'public'           => true,
        'public_queryable' => false,
        'rewrite'          => array(
            'slug'         => 'work-industry',
            'with_front'   => false,
            'hierarchical' => true 
        ),

        'show_admin_column' => true,
        'show_in_nadr_menus' => true,
    )
);
