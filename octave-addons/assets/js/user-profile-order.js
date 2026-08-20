/*
USER PROFILE FIELD ORDER
-- Moves the avatar and job title rows into the About Yourself section.
-- WordPress ships no action hook inside that table, so the rows are rendered
-- at the end of the form by show_user_profile and relocated here.
---------------------------------------------------------- */

(function () {

    'use strict';

    var table = document.querySelector( '.oa-user-profile-fields' );
    var bioRow = document.querySelector( '.user-description-wrap' );

    if ( ! table || ! bioRow || ! bioRow.parentNode ) {

        return;

    }

    var rows = table.querySelectorAll( '.oa-user-profile-row' );

    if ( ! rows.length ) {

        return;

    }

    // Static NodeList, so moving each row mid-loop keeps the rendered order.
    rows.forEach( function ( row ) {

        bioRow.parentNode.insertBefore( row, bioRow );

    } );

    table.remove();

}());
