/*
DYNAMIC DATA FIELDS
-- Filters the unified Octave Fields category by assigned post type.
---------------------------------------------------------- */

( function () {

    'use strict';

    const config = window.octaveDynamicDataFields;
    let selectedPostType = '';
    let refreshScheduled = false;

    if ( ! config || ! Array.isArray( config.postTypes ) ) {

        return;

    }

    /*
    FILTER CATEGORY
    -- Keeps every scoped copy visible for All Post Types and otherwise shows
    -- only the subcategory belonging to the selected post type.
    ---------------------------------------------------------- */

    function filterCategory( category ) {

        const selected = config.postTypes.find( postType => {

            return postType.value === selectedPostType;

        } );

        category.classList.toggle( 'octave-dynamic-data-post-type-filtered', Boolean( selected ) );

        category.querySelectorAll( '.dynamic-data-fields-subcategory' ).forEach( subcategory => {

            const heading = subcategory.querySelector( '.dynamic-data-fields-subcategory__title' );
            const matches = ! selected || ( heading && heading.textContent.trim() === selected.subcategory );

            subcategory.hidden = ! matches;

        } );

    }

    /*
    CREATE FILTER
    -- Inserts one native select into each rendered Octave Fields category.
    ---------------------------------------------------------- */

    function createFilter( category ) {

        const wrapper = document.createElement( 'label' );
        const select  = document.createElement( 'select' );

        wrapper.className = 'octave-dynamic-data-post-type-filter';
        select.setAttribute( 'aria-label', config.allPostTypes );

        select.add( new Option( config.allPostTypes, '' ) );

        config.postTypes.forEach( postType => {

            select.add( new Option( postType.label, postType.value ) );

        } );

        select.value = selectedPostType;
        select.addEventListener( 'change', event => {

            selectedPostType = event.currentTarget.value;

            document.querySelectorAll( '.dynamic-data-fields-category' ).forEach( renderedCategory => {

                const heading = renderedCategory.querySelector( '.dynamic-data-fields-category__title' );

                if ( heading && heading.textContent.trim() === config.category ) {

                    const renderedSelect = renderedCategory.querySelector( '.octave-dynamic-data-post-type-filter select' );

                    if ( renderedSelect ) {

                        renderedSelect.value = selectedPostType;

                    }

                    filterCategory( renderedCategory );

                }

            } );

        } );

        wrapper.append( select );
        category.querySelector( '.dynamic-data-fields-column' ).prepend( wrapper );

    }

    /*
    REFRESH FILTERS
    -- Reapplies the selector when Breakdance rerenders search results or opens
    -- another Dynamic Data chooser.
    ---------------------------------------------------------- */

    function refreshFilters() {

        document.querySelectorAll( '.dynamic-data-fields-category' ).forEach( category => {

            const heading = category.querySelector( '.dynamic-data-fields-category__title' );

            if ( ! heading || heading.textContent.trim() !== config.category ) {

                return;

            }

            if ( ! category.querySelector( '.octave-dynamic-data-post-type-filter' ) ) {

                createFilter( category );

            }

            filterCategory( category );

        } );

    }

    /*
    SCHEDULE REFRESH
    -- Coalesces Breakdance DOM updates into one filter pass per animation frame.
    ---------------------------------------------------------- */

    function scheduleRefresh() {

        if ( refreshScheduled ) {

            return;

        }

        refreshScheduled = true;

        window.requestAnimationFrame( () => {

            refreshScheduled = false;
            refreshFilters();

        } );

    }

    const observer = new MutationObserver( scheduleRefresh );

    observer.observe( document.documentElement, {
        childList: true,
        subtree: true,
    } );

    refreshFilters();

}() );
