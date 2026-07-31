<?php

/*
PARTS LOADER
-- Loads legacy custom area include files from the parts subdirectories
---------------------------------------------------------- */

$dir = dirname( __FILE__ );
$files = scandir( $dir );

foreach ( $files as $filename ) {

    if ( in_array( $filename, array( '.', '..', basename( __FILE__ ) ), true ) ) {

        continue;

    }

    /*
    RUN THROUGH ALL SUB PARTS FILES IN FOLDER
    -- Includes each file below the parts directory
    ---------------------------------------------------------- */

    foreach ( scandir( dirname( __FILE__ ) . '/' . $filename ) as $subfile ) {

        if ( in_array( $subfile, array( '.', '..' ), true ) ) {

            continue;

        }

        require_once( $filename . '/' . $subfile );

    }

}
