<?php

/*
ADMIN LOADER
-- Loads legacy admin include files from the admin subdirectories
---------------------------------------------------------- */

$dir = dirname( __FILE__ );
$files = scandir( $dir );

foreach ( $files as $filename ) {

    if ( in_array( $filename, array( '.', '..', basename( __FILE__ ) ), true ) || str_contains( $filename, '.php' ) ) {

        continue;

    }

    /*
    RUN THROUGH ALL SUB FILES IN FOLDER
    -- Includes PHP files one level below each admin directory
    ---------------------------------------------------------- */

    foreach ( ( array ) scandir( dirname( __FILE__ ) . '/' . $filename ) as $subfile ) {

        if ( in_array( $subfile, array( '.', '..' ), true ) || ! str_contains( $subfile, '.php' ) ) {

            continue;

        }

        require_once( $filename . '/' . $subfile );

    }

}


require_once( 'global-functions.php' );
