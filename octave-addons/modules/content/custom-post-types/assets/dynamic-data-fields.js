/*
DYNAMIC DATA FIELDS
-- Filters the flat Octave Fields list by assigned post type.
---------------------------------------------------------- */

( function () {

    'use strict';

    const config = window.octaveDynamicDataFields;
    let selectedPostType = '';
    let refreshScheduled = false;

    if ( ! config || ! Array.isArray( config.postTypes ) || ! Array.isArray( config.fields ) ) {

        return;

    }

    /*
    FIELD LABEL
    -- Reads only the button title; Octave fields are not Pro-only and therefore
    -- carry no badge text that could alter the registered label.
    ---------------------------------------------------------- */

    function fieldLabel( field ) {

        const title = field.querySelector( '.dynamic-data-fields-field__title' );

        return title ? title.textContent.trim() : '';

    }

    /*
    MAP RENDERED FIELDS
    -- Matches Breakdance's rendered order back to the unique Octave meta-key
    -- entries. Label queues also preserve order when two keys share a title.
    ---------------------------------------------------------- */

    function mapRenderedFields( category ) {

        const entriesByLabel = new Map();

        config.fields.forEach( entry => {

            const entries = entriesByLabel.get( entry.label ) || [];

            entries.push( entry );
            entriesByLabel.set( entry.label, entries );

        } );

        category.querySelectorAll( '.dynamic-data-fields-field' ).forEach( field => {

            const entries = entriesByLabel.get( fieldLabel( field ) ) || [];
            const entry   = entries.shift();

            field.octaveDynamicField = entry || null;

        } );

    }

    /*
    FILTER CATEGORY
    -- All Post Types leaves the complete unique meta-key list visible. A CPT
    -- selection hides only keys that are not assigned to that post type.
    ---------------------------------------------------------- */

    function filterCategory( category ) {

        mapRenderedFields( category );

        category.querySelectorAll( '.dynamic-data-fields-field' ).forEach( field => {

            const entry = field.octaveDynamicField;
            const show  = ! selectedPostType
                || ( entry && entry.postTypes.includes( selectedPostType ) );

            field.hidden = ! show;

        } );

    }

    /*
    CREATE FILTER
    -- Builds a Breakdance-style dropdown above the single Octave field grid.
    ---------------------------------------------------------- */

    function createFilter( category ) {

        const wrapper = document.createElement( 'label' );
        const select  = document.createElement( 'select' );
        const chevron = document.createElement( 'span' );

        wrapper.className = 'octave-dynamic-data-post-type-filter breakdance-dropdown-input d-flex';
        select.className = 'octave-dynamic-data-post-type-filter__select';
        select.setAttribute( 'aria-label', config.allPostTypes );

        chevron.className = 'octave-dynamic-data-post-type-filter__chevron';
        chevron.setAttribute( 'aria-hidden', 'true' );

        select.add( new Option( config.allPostTypes, '' ) );

        config.postTypes.forEach( postType => {

            select.add( new Option( postType.label, postType.value ) );

        } );

        select.value = selectedPostType;
        select.addEventListener( 'change', event => {

            selectedPostType = event.currentTarget.value;

            document.querySelectorAll( '.octave-dynamic-data-fields-category' ).forEach( renderedCategory => {

                const renderedSelect = renderedCategory.querySelector( '.octave-dynamic-data-post-type-filter__select' );

                if ( renderedSelect ) {

                    renderedSelect.value = selectedPostType;

                }

                filterCategory( renderedCategory );

            } );

        } );

        wrapper.append( select, chevron );
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

            category.classList.add( 'octave-dynamic-data-fields-category' );

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
