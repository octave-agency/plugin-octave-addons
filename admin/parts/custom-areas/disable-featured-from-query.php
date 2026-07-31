<?php

/*
DISABLE FEATURED FROM QUERY
-- Removes the featured work item from archive queries
---------------------------------------------------------- */

add_action( 'pre_get_posts', 'exclude_work_featured' );

/*
EXCLUDE WORK FEATURED
-- Excludes the configured featured work post from archive listings
---------------------------------------------------------- */

function exclude_work_featured( $query ) {

    if ( ! $query->is_main_query() ) {

        return;

    }

    $featured_id = get_option( 'oa_work_featured' );

    if ( ! $featured_id ) {

        return;

    }

    if ( is_admin() || ! $query->is_main_query() ) {

        return;

    }

    if ( $query->is_archive() ) {

        $query->set( 'post__not_in', array( ( int ) $featured_id ) );

    }

}
