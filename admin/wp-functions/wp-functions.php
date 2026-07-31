<?php

/*
WP FUNCTIONS LOADER
-- Loads legacy WordPress function include files
---------------------------------------------------------- */

$dir_for_wp = dirname( __FILE__ );
$files_for_wp = scandir( $dir_for_wp );

foreach ( $files_for_wp as $filename ) {

    if ( in_array( $filename, array( '.', '..', basename( __FILE__ ) ), true ) ) {

        continue;

    }

    /*
    RUN THROUGH ALL SUB PARTS FILES IN FOLDER
    -- Includes each PHP file below the WP functions directory
    ---------------------------------------------------------- */

    foreach ( scandir( dirname( __FILE__ ) . '/' . $filename ) as $subfile ) {

        if ( in_array( $subfile, array( '.', '..' ), true ) ) {

            continue;

        }

        require_once( $filename . '/' . $subfile );

    }

}
