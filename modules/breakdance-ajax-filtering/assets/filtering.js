/*
BREAKDANCE AJAX FILTERING
-- Takes over the Breakdance Filter Bar and backs it with server-rendered results
-- Adds result counts, skeleton loading, and Load More or numbered pagination
---------------------------------------------------------- */

( () => {

    'use strict';

    /*
    INITIALISE CONTROLS
    -- Connects one configuration element to its Breakdance loop
    ---------------------------------------------------------- */

    function initialiseControls( controls ) {

        const isAutomatic = 'true' === controls.dataset.auto;
        const updateUrl = 'true' === controls.dataset.updateUrl;
        const scrollToResults = 'true' === controls.dataset.scrollToResults;
        const showResultCount = 'true' === controls.dataset.showResultCount;
        const navigationMode = controls.dataset.navigationMode;
        const loadMoreLabel = controls.dataset.loadMoreLabel;
        const baseUrl = controls.dataset.baseUrl;
        const postsPerPage = Number.parseInt( controls.dataset.postsPerPage, 10 ) || 9;
        const form = controls.querySelector( '.oa-breakdance-ajax-controls__form' );
        const feedback = controls.querySelector( '.oa-breakdance-ajax-feedback' );
        let selectedTerm = getSelectedTerm();
        const termData = getTermData();
        const termLookup = getTermLookup();
        let currentPage = getRequestedPage( window.location.href );
        let filterBarTemplate = null;
        let activeRequest = null;

        if ( ! form || ! feedback ) {

            controls.remove();

            return;

        }

        if ( isAutomatic && document.querySelector( '.oa-breakdance-ajax-controls[data-auto="false"]' ) ) {

            controls.remove();

            return;

        }

        // The module enhances the Breakdance Filter Bar; without a usable one it
        // leaves the loop exactly as Breakdance rendered it.
        if ( ! findTarget() || ! canUseNativeFilters() ) {

            controls.remove();

            return;

        }

        /*
        FIND TARGET
        -- Resolves the first Breakdance Post List that carries a Filter Bar
        ---------------------------------------------------------- */

        function findTarget( root = document ) {

            return Array.from( root.querySelectorAll( '.bde-post-list, [class*="bde-post-loop-builder-"]' ) ).find( candidate => {

                return candidate.querySelector( '.bde-isotope-filter-bar' );

            } ) || null;

        }

        /*
        GET TERM DATA
        -- Builds labels and published counts from the server-rendered controls
        ---------------------------------------------------------- */

        function getTermData() {

            const data = new Map();

            controls.querySelectorAll( '.oa-breakdance-ajax-filter__button' ).forEach( button => {

                const term = Number.parseInt( button.dataset.term, 10 ) || 0;
                const count = Number.parseInt( button.dataset.count, 10 ) || 0;
                const label = button.dataset.label || button.textContent.trim();

                data.set( term, {
                    count,
                    label,
                } );

            } );

            if ( ! data.has( 0 ) ) {

                data.set( 0, {
                    count: Number.parseInt( controls.dataset.totalCount, 10 ) || 0,
                    label: '',
                } );

            }

            const currentCount = Number.parseInt( controls.dataset.currentCount, 10 );

            if ( ! Number.isInteger( currentCount ) ) {

                return data;

            }

            const selectedData = data.get( selectedTerm );

            if ( selectedData ) {

                selectedData.count = currentCount;

            } else {

                data.set( selectedTerm, {
                    count: currentCount,
                    label: '',
                } );

            }

            return data;

        }

        /*
        NORMALIZE FILTER VALUE
        -- Reduces a Breakdance filter value or label to a comparable key
        ---------------------------------------------------------- */

        function normalizeFilterValue( value ) {

            return String( value || '' )
                .trim()
                .replace( /^[.#]/, '' )
                .replace( /\s+/g, ' ' )
                .toLowerCase();

        }

        /*
        GET TERM LOOKUP
        -- Maps slugs, labels, and prefixed CSS values onto real term IDs
        -- Breakdance's Filter Bar exposes slugs or Isotope classes in data-value,
        -- never term IDs, so every native value has to be translated first
        ---------------------------------------------------------- */

        function getTermLookup() {

            const lookup = new Map();

            controls.querySelectorAll( '.oa-breakdance-ajax-filter__button' ).forEach( button => {

                const term = Number.parseInt( button.dataset.term, 10 ) || 0;
                const slug = normalizeFilterValue( button.dataset.slug );
                const label = normalizeFilterValue( button.dataset.label || button.textContent );
                const taxonomy = normalizeFilterValue( button.dataset.taxonomy );

                if ( slug ) {

                    lookup.set( slug, term );

                    if ( taxonomy ) {

                        lookup.set( `${ taxonomy }-${ slug }`, term );

                    }

                }

                if ( label ) {

                    lookup.set( label, term );

                }

                if ( term > 0 ) {

                    lookup.set( String( term ), term );
                    lookup.set( `term-${ term }`, term );

                }

            } );

            lookup.set( 'all', 0 );
            lookup.set( '*', 0 );
            lookup.set( '', 0 );

            return lookup;

        }

        /*
        GET BUTTON TERM
        -- Resolves any filter button to a term ID, or null when unrecognised
        -- Breakdance writes a term ID into data-value on a Post List Filter Bar,
        -- but a slug or Isotope class elsewhere, so both are accepted
        ---------------------------------------------------------- */

        function getButtonTerm( button ) {

            if ( button.dataset.term ) {

                return Number.parseInt( button.dataset.term, 10 ) || 0;

            }

            const rawValue = normalizeFilterValue( button.dataset.value );

            if ( termLookup.has( rawValue ) ) {

                return termLookup.get( rawValue );

            }

            // A bare number is a term ID; the server validates it before querying.
            if ( /^\d+$/.test( rawValue ) ) {

                return Number.parseInt( rawValue, 10 );

            }

            const trailingSlug = rawValue.split( /[\s.]+/ ).filter( Boolean ).pop() || '';

            if ( termLookup.has( trailingSlug ) ) {

                return termLookup.get( trailingSlug );

            }

            const labelValue = normalizeFilterValue( button.textContent );

            if ( termLookup.has( labelValue ) ) {

                return termLookup.get( labelValue );

            }

            return null;

        }

        /*
        GET NATIVE FILTER BAR
        -- Returns the Breakdance Filter Bar belonging to this loop
        ---------------------------------------------------------- */

        function getNativeFilterBar( target = findTarget() ) {

            return target ? target.querySelector( '.bde-isotope-filter-bar' ) : null;

        }

        /*
        CAN USE NATIVE FILTERS
        -- Confirms the Breakdance bar exists and its buttons map onto real terms
        -- Prevents every native click from silently resolving to "All"
        ---------------------------------------------------------- */

        function canUseNativeFilters() {

            const nativeBar = getNativeFilterBar();

            if ( ! nativeBar ) {

                return false;

            }

            const buttons = Array.from( nativeBar.querySelectorAll( '[data-value]' ) );

            if ( ! buttons.length ) {

                return false;

            }

            return buttons.some( button => {

                const term = getButtonTerm( button );

                return Number.isInteger( term ) && term > 0;

            } );

        }

        /*
        UPDATE TERM COUNT
        -- Reads the accurate selected count from each fetched page configuration
        ---------------------------------------------------------- */

        function updateTermCount( parsedDocument ) {

            const incomingControls = parsedDocument.querySelector( '.oa-breakdance-ajax-controls' );
            const selectedData = termData.get( selectedTerm );
            const currentCount = incomingControls ? Number.parseInt( incomingControls.dataset.currentCount, 10 ) : Number.NaN;

            if ( ! selectedData ) {

                termData.set( selectedTerm, {
                    count: Number.isInteger( currentCount ) ? currentCount : 0,
                    label: '',
                } );

                return;

            }

            if ( Number.isInteger( currentCount ) ) {

                selectedData.count = currentCount;

            }

        }

        /*
        GET SELECTED TERM
        -- Reads an explicit URL filter, otherwise starts unfiltered
        ---------------------------------------------------------- */

        function getSelectedTerm() {

            const urlValue = new URL( window.location.href ).searchParams.get( 'baa_term' );
            const parsedValue = Number.parseInt( urlValue, 10 );

            return Number.isInteger( parsedValue ) && parsedValue >= 0 ? parsedValue : 0;

        }

        /*
        GET REQUESTED PAGE
        -- Reads the private offset parameter used by the Breakdance query bridge
        ---------------------------------------------------------- */

        function getRequestedPage( href ) {

            const value = Number.parseInt( new URL( href, window.location.href ).searchParams.get( 'baa_page' ), 10 );

            return Number.isInteger( value ) && value > 0 ? value : 1;

        }

        /*
        GET SEARCH
        -- Keeps any search term already present in the URL across filter changes
        ---------------------------------------------------------- */

        function getSearch() {

            return ( new URL( window.location.href ).searchParams.get( 'baa_search' ) || '' ).trim();

        }

        /*
        GET FILTER BUTTONS
        -- Returns the Breakdance Filter Bar tabs driving this loop
        ---------------------------------------------------------- */

        function getFilterButtons() {

            const nativeBar = getNativeFilterBar();

            return nativeBar ? Array.from( nativeBar.querySelectorAll( '[data-value]' ) ) : [];

        }

        /*
        SET FILTER STATE
        -- Keeps native tab styling and accessible selection state in sync
        ---------------------------------------------------------- */

        function setFilterState() {

            getFilterButtons().forEach( button => {

                const isActive = getButtonTerm( button ) === selectedTerm;

                button.classList.toggle( 'is-active', isActive );
                button.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
                button.tabIndex = isActive ? 0 : -1;

            } );

        }

        /*
        BUILD URL
        -- Creates a URL for the active filter, search, and result page
        ---------------------------------------------------------- */

        function buildUrl( page = 1 ) {

            const url = new URL( baseUrl, window.location.origin );
            const search = getSearch();

            url.searchParams.set( 'baa_term', String( selectedTerm ) );

            if ( search ) {

                url.searchParams.set( 'baa_search', search );

            } else {

                url.searchParams.delete( 'baa_search' );

            }

            if ( page > 1 ) {

                url.searchParams.set( 'baa_page', String( page ) );

            } else {

                url.searchParams.delete( 'baa_page' );

            }

            url.searchParams.delete( 'paged' );

            return url;

        }

        /*
        GET RESULT TOTAL
        -- Returns the selected term's published count
        ---------------------------------------------------------- */

        function getResultTotal() {

            const selectedData = termData.get( selectedTerm );

            return selectedData ? selectedData.count : 0;

        }

        /*
        GET RESULT LABEL
        -- Returns the selected filter label for the count message
        ---------------------------------------------------------- */

        function getResultLabel() {

            const selectedData = termData.get( selectedTerm );

            return selectedData ? selectedData.label : '';

        }

        /*
        GET LOADED POSTS
        -- Counts the real cards currently in the grid, ignoring placeholders
        ---------------------------------------------------------- */

        function getLoadedPosts( target = findTarget() ) {

            if ( ! target ) {

                return 0;

            }

            return target.querySelectorAll( '.ee-posts > .ee-post:not(.oa-breakdance-ajax-skeleton)' ).length;

        }

        /*
        PREPARE TARGET
        -- Disables Isotope filtering while retaining Breakdance's grid and tab design
        ---------------------------------------------------------- */

        function prepareTarget() {

            const target = findTarget();

            if ( ! target ) {

                return;

            }

            target.classList.add( 'oa-breakdance-ajax-target' );

            const loop = target.querySelector( '.ee-posts' );
            const nativeBar = getNativeFilterBar( target );

            if ( loop ) {

                loop.classList.remove( 'bde-loop-isotope', 'ee-posts-isotope' );
                loop.removeAttribute( 'style' );

                loop.querySelectorAll( '.ee-post' ).forEach( post => {

                    post.hidden = false;
                    post.removeAttribute( 'style' );

                } );

            }

            if ( nativeBar ) {

                nativeBar.classList.add( 'oa-breakdance-ajax-native-filter' );

            }

            if ( ! filterBarTemplate && nativeBar ) {

                filterBarTemplate = nativeBar.cloneNode( true );

            }

            renderInterface();
            setFilterState();

        }

        /*
        RESTORE FILTER BAR
        -- Puts the complete filter bar back after a replacement
        -- A filtered response only contains the terms still present in the results,
        -- so the incoming bar would otherwise lose most of its buttons
        ---------------------------------------------------------- */

        function restoreFilterBar( target ) {

            if ( ! filterBarTemplate || ! target ) {

                return;

            }

            const incomingBar = getNativeFilterBar( target );
            const restoredBar = filterBarTemplate.cloneNode( true );

            if ( incomingBar ) {

                incomingBar.replaceWith( restoredBar );

                return;

            }

            target.prepend( restoredBar );

        }

        /*
        RENDER INTERFACE
        -- Adds the count, loading state, and selected navigation mode
        ---------------------------------------------------------- */

        function renderInterface() {

            const target = findTarget();

            if ( ! target ) {

                return;

            }

            target.querySelectorAll( '.oa-breakdance-ajax-status, .oa-breakdance-ajax-navigation, .oa-breakdance-ajax-empty' ).forEach( element => {

                element.remove();

            } );

            const filterBar = getNativeFilterBar( target );
            const loop = target.querySelector( '.ee-posts' );
            const status = document.createElement( 'div' );
            const count = document.createElement( 'p' );
            const loader = document.createElement( 'div' );
            const spinner = document.createElement( 'span' );
            const loadingText = document.createElement( 'span' );
            const errorMessage = document.createElement( 'p' );

            status.className = 'oa-breakdance-ajax-status';
            count.className = 'oa-breakdance-ajax-count';
            loader.className = 'oa-breakdance-ajax-loader';
            loader.setAttribute( 'role', 'status' );
            loader.setAttribute( 'aria-live', 'polite' );
            spinner.className = 'oa-breakdance-ajax-spinner';
            spinner.setAttribute( 'aria-hidden', 'true' );
            loadingText.textContent = OctaveBreakdanceAjax.loadingMessage;
            errorMessage.className = 'oa-breakdance-ajax-error';
            errorMessage.hidden = true;

            loader.append( spinner, loadingText );
            status.append( count, loader, errorMessage );

            if ( filterBar ) {

                filterBar.after( status );

            } else if ( loop ) {

                loop.before( status );

            } else {

                target.prepend( status );

            }

            updateCount( count );

            if ( ! loop ) {

                return;

            }

            if ( 0 === getLoadedPosts( target ) ) {

                const empty = document.createElement( 'p' );

                empty.className = 'oa-breakdance-ajax-empty';
                empty.textContent = OctaveBreakdanceAjax.noResults;
                loop.before( empty );

            }

            loop.after( buildNavigation() );

        }

        /*
        UPDATE COUNT
        -- Writes messages such as "12 Lifestyle posts"
        ---------------------------------------------------------- */

        function updateCount( count ) {

            if ( ! showResultCount ) {

                count.hidden = true;

                return;

            }

            const total = getResultTotal();
            const label = 0 === selectedTerm ? '' : getResultLabel();
            const noun = 1 === total ? OctaveBreakdanceAjax.postSingular : OctaveBreakdanceAjax.postPlural;
            const parts = [ total.toLocaleString(), label, noun ].filter( Boolean );

            count.hidden = false;
            count.textContent = parts.join( ' ' );

        }

        /*
        BUILD NAVIGATION
        -- Creates Load More or numbered pagination from the result total
        ---------------------------------------------------------- */

        function buildNavigation() {

            const navigation = document.createElement( 'nav' );
            const totalPages = Math.max( 1, Math.ceil( getResultTotal() / postsPerPage ) );

            navigation.className = 'oa-breakdance-ajax-navigation';
            navigation.setAttribute( 'aria-label', OctaveBreakdanceAjax.paginationLabel );

            if ( 'pagination' === navigationMode ) {

                buildPagination( navigation, totalPages );

            } else {

                buildLoadMore( navigation );

            }

            return navigation;

        }

        /*
        BUILD LOAD MORE
        -- Adds an offset button while additional matching posts remain
        ---------------------------------------------------------- */

        function buildLoadMore( navigation ) {

            const loadedPosts = getLoadedPosts();

            if ( 0 === loadedPosts || loadedPosts >= getResultTotal() ) {

                navigation.hidden = true;

                return;

            }

            const button = document.createElement( 'button' );
            const label = document.createElement( 'span' );

            label.className = 'oa-breakdance-ajax-load-more__label';
            label.textContent = loadMoreLabel;

            button.className = 'oa-breakdance-ajax-load-more';
            button.type = 'button';
            button.dataset.page = String( currentPage + 1 );
            button.append( label );

            navigation.append( button );

        }

        /*
        BUILD PAGINATION
        -- Adds compact numbered page controls with previous and next actions
        ---------------------------------------------------------- */

        function buildPagination( navigation, totalPages ) {

            if ( totalPages <= 1 ) {

                navigation.hidden = true;

                return;

            }

            const pages = getVisiblePages( totalPages );

            if ( currentPage > 1 ) {

                navigation.append( createPageButton( currentPage - 1, OctaveBreakdanceAjax.previousLabel, 'previous' ) );

            }

            pages.forEach( ( page, index ) => {

                const previousPage = pages[ index - 1 ];

                if ( previousPage && page - previousPage > 1 ) {

                    const separator = document.createElement( 'span' );

                    separator.className = 'oa-breakdance-ajax-pagination__ellipsis';
                    separator.textContent = '…';
                    navigation.append( separator );

                }

                navigation.append( createPageButton( page, String( page ) ) );

            } );

            if ( currentPage < totalPages ) {

                navigation.append( createPageButton( currentPage + 1, OctaveBreakdanceAjax.nextLabel, 'next' ) );

            }

        }

        /*
        GET VISIBLE PAGES
        -- Keeps numbered pagination compact around the active page
        ---------------------------------------------------------- */

        function getVisiblePages( totalPages ) {

            const pages = new Set( [ 1, totalPages ] );

            for ( let page = currentPage - 2; page <= currentPage + 2; page += 1 ) {

                if ( page > 1 && page < totalPages ) {

                    pages.add( page );

                }

            }

            return Array.from( pages ).sort( ( first, second ) => {

                return first - second;

            } );

        }

        /*
        CREATE PAGE BUTTON
        -- Builds one accessible pagination button
        ---------------------------------------------------------- */

        function createPageButton( page, label, relation = '' ) {

            const button = document.createElement( 'button' );

            button.className = 'oa-breakdance-ajax-page';
            button.type = 'button';
            button.dataset.page = String( page );
            button.textContent = label;

            if ( page === currentPage ) {

                button.classList.add( 'is-active' );
                button.setAttribute( 'aria-current', 'page' );
                button.disabled = true;

            }

            if ( relation ) {

                button.classList.add( `oa-breakdance-ajax-page--${ relation }` );

            }

            return button;

        }

        /*
        SET ERROR
        -- Shows request failures beside the result count and to assistive technology
        ---------------------------------------------------------- */

        function setError( message ) {

            const target = findTarget();
            const errorMessage = target ? target.querySelector( '.oa-breakdance-ajax-error' ) : null;

            feedback.textContent = message;

            if ( errorMessage ) {

                errorMessage.hidden = ! message;
                errorMessage.textContent = message;

            }

        }

        /*
        CREATE SKELETON
        -- Builds one placeholder card that inherits the Breakdance grid sizing
        ---------------------------------------------------------- */

        function createSkeleton() {

            const skeleton = document.createElement( 'div' );
            const media = document.createElement( 'div' );
            const body = document.createElement( 'div' );

            skeleton.className = 'bde-loop-item ee-post oa-breakdance-ajax-skeleton';
            skeleton.setAttribute( 'aria-hidden', 'true' );
            media.className = 'oa-breakdance-ajax-skeleton__media';
            body.className = 'oa-breakdance-ajax-skeleton__body';

            for ( let line = 0; line < 3; line += 1 ) {

                const bar = document.createElement( 'span' );

                bar.className = 'oa-breakdance-ajax-skeleton__line';
                body.append( bar );

            }

            skeleton.append( media, body );

            return skeleton;

        }

        /*
        SHOW SKELETONS
        -- Replaces or extends the grid with placeholder cards while fetching
        -- Keeps the layout height stable instead of dimming the old results
        ---------------------------------------------------------- */

        function showSkeletons( append ) {

            const target = findTarget();
            const loop = target ? target.querySelector( '.ee-posts' ) : null;

            if ( ! loop ) {

                return;

            }

            const remaining = Math.max( 0, getResultTotal() - getLoadedPosts( target ) );
            const total = append ? Math.min( postsPerPage, remaining || postsPerPage ) : Math.min( postsPerPage, Math.max( getLoadedPosts( target ), 1 ) );
            const insertionPoint = loop.querySelector( '.ee-post-gutter, .ee-post-sizer' );

            if ( ! append ) {

                loop.classList.add( 'oa-breakdance-ajax-is-replacing' );

            }

            for ( let index = 0; index < total; index += 1 ) {

                loop.insertBefore( createSkeleton(), insertionPoint );

            }

        }

        /*
        CLEAR SKELETONS
        -- Removes every placeholder card and restores the real results
        ---------------------------------------------------------- */

        function clearSkeletons() {

            const target = findTarget();

            if ( ! target ) {

                return;

            }

            target.querySelectorAll( '.oa-breakdance-ajax-skeleton' ).forEach( skeleton => {

                skeleton.remove();

            } );

            target.querySelectorAll( '.oa-breakdance-ajax-is-replacing' ).forEach( loop => {

                loop.classList.remove( 'oa-breakdance-ajax-is-replacing' );

            } );

        }

        /*
        SET LOADING
        -- Applies a visible progress state without collapsing the layout
        ---------------------------------------------------------- */

        function setLoading( isLoading, options = {} ) {

            const target = findTarget();
            const trigger = options.trigger;

            controls.classList.toggle( 'is-loading', isLoading );
            controls.setAttribute( 'aria-busy', isLoading ? 'true' : 'false' );

            if ( isLoading ) {

                feedback.textContent = OctaveBreakdanceAjax.loadingMessage;
                showSkeletons( Boolean( options.append ) );

            } else {

                clearSkeletons();

            }

            if ( trigger && trigger.isConnected ) {

                const label = trigger.querySelector( '.oa-breakdance-ajax-load-more__label' );

                trigger.classList.toggle( 'is-loading', isLoading );

                if ( label ) {

                    label.textContent = isLoading ? OctaveBreakdanceAjax.loadingMore : loadMoreLabel;

                }

            }

            if ( ! target ) {

                return;

            }

            target.classList.toggle( 'oa-breakdance-ajax-is-loading', isLoading );
            target.setAttribute( 'aria-busy', isLoading ? 'true' : 'false' );

            const navigationButtons = Array.from( target.querySelectorAll( '.oa-breakdance-ajax-navigation button' ) );

            getFilterButtons().concat( navigationButtons ).forEach( button => {

                button.disabled = isLoading || button.matches( '.oa-breakdance-ajax-page.is-active' );

            } );

        }

        /*
        APPEND POSTS
        -- Adds the next server-rendered Breakdance cards to the existing grid
        ---------------------------------------------------------- */

        function appendPosts( currentTarget, incomingTarget ) {

            const currentLoop = currentTarget.querySelector( '.ee-posts' );
            const incomingLoop = incomingTarget.querySelector( '.ee-posts' );

            if ( ! currentLoop || ! incomingLoop ) {

                return 0;

            }

            const insertionPoint = currentLoop.querySelector( '.ee-post-gutter, .ee-post-sizer' );
            const incomingPosts = Array.from( incomingLoop.children ).filter( child => {

                return child.classList.contains( 'ee-post' );

            } );

            incomingPosts.forEach( post => {

                const importedPost = document.importNode( post, true );

                importedPost.hidden = false;
                importedPost.removeAttribute( 'style' );
                currentLoop.insertBefore( importedPost, insertionPoint );

            } );

            return incomingPosts.length;

        }

        /*
        DISPATCH LOADED EVENTS
        -- Notifies integrations after Breakdance markup changes
        ---------------------------------------------------------- */

        function dispatchLoadedEvents( target, url, appended ) {

            const detail = {
                appended,
                target,
                url: url.toString(),
            };

            document.dispatchEvent( new CustomEvent( 'octave_breakdance_ajax_loaded', { detail } ) );
            document.dispatchEvent( new CustomEvent( 'breakdance_ajax_archive_loaded', { detail } ) );

        }

        /*
        LOAD URL
        -- Fetches a server-rendered page and replaces or appends only loop markup
        ---------------------------------------------------------- */

        async function loadUrl( url, options = {} ) {

            const append = Boolean( options.append );
            const changeHistory = false !== options.changeHistory;
            const shouldScroll = false !== options.scroll;
            const requestedPage = getRequestedPage( url );

            if ( activeRequest ) {

                activeRequest.abort();

            }

            const request = new AbortController();

            activeRequest = request;
            setError( '' );
            setLoading( true, {
                append,
                trigger: options.trigger,
            } );

            try {

                const response = await fetch( url, {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: request.signal,
                } );

                if ( ! response.ok ) {

                    throw new Error( OctaveBreakdanceAjax.errorMessage );

                }

                const html = await response.text();
                const parsedDocument = new DOMParser().parseFromString( html, 'text/html' );
                const incomingTarget = findTarget( parsedDocument );

                if ( ! incomingTarget ) {

                    throw new Error( OctaveBreakdanceAjax.errorMessage );

                }

                clearSkeletons();

                const currentTarget = findTarget();

                if ( ! currentTarget ) {

                    throw new Error( OctaveBreakdanceAjax.errorMessage );

                }

                updateTermCount( parsedDocument );

                if ( append ) {

                    const addedPosts = appendPosts( currentTarget, incomingTarget );

                    if ( addedPosts > 0 ) {

                        currentPage = requestedPage;

                    } else {

                        // Nothing left to add: pin the total so the button stops offering more.
                        const selectedData = termData.get( selectedTerm );

                        if ( selectedData ) {

                            selectedData.count = getLoadedPosts( currentTarget );

                        }

                    }

                } else {

                    const replacement = document.importNode( incomingTarget, true );

                    currentTarget.replaceWith( replacement );
                    restoreFilterBar( replacement );
                    currentPage = requestedPage;

                }

                prepareTarget();

                if ( updateUrl && changeHistory ) {

                    const historyUrl = new URL( url );

                    if ( append ) {

                        historyUrl.searchParams.delete( 'baa_page' );
                        window.history.replaceState( {}, '', historyUrl );

                    } else {

                        window.history.pushState( {}, '', historyUrl );

                    }

                }

                const loadedTarget = findTarget();

                feedback.textContent = OctaveBreakdanceAjax.loadedMessage;
                dispatchLoadedEvents( loadedTarget, url, append );

                if ( loadedTarget && scrollToResults && shouldScroll && ! append ) {

                    loadedTarget.scrollIntoView( {
                        behavior: 'smooth',
                        block: 'start',
                    } );

                }

            } catch ( error ) {

                if ( 'AbortError' !== error.name ) {

                    setError( error.message || OctaveBreakdanceAjax.errorMessage );

                }

            } finally {

                if ( activeRequest === request ) {

                    activeRequest = null;
                    setLoading( false, {
                        append,
                        trigger: options.trigger,
                    } );

                }

            }

        }

        document.addEventListener( 'click', event => {

            const button = event.target.closest( '.bde-isotope-filter-bar [data-value]' );
            const target = findTarget();

            if ( ! button || ! target || ! target.contains( button ) ) {

                return;

            }

            const term = getButtonTerm( button );

            if ( ! Number.isInteger( term ) ) {

                return;

            }

            event.preventDefault();
            event.stopImmediatePropagation();

            if ( term === selectedTerm && 1 === currentPage ) {

                return;

            }

            selectedTerm = term;
            currentPage = 1;

            setFilterState();
            loadUrl( buildUrl(), {
                append: false,
                scroll: true,
            } );

        }, true );

        document.addEventListener( 'click', event => {

            const loadMoreButton = event.target.closest( '.oa-breakdance-ajax-load-more' );
            const pageButton = event.target.closest( '.oa-breakdance-ajax-page' );
            const breakdancePage = event.target.closest( 'a.page-numbers, .bde-post-pagination a, .bde-pagination a' );
            const target = findTarget();

            if ( ! target ) {

                return;

            }

            if ( loadMoreButton && target.contains( loadMoreButton ) ) {

                event.preventDefault();

                if ( activeRequest ) {

                    return;

                }

                loadUrl( buildUrl( currentPage + 1 ), {
                    append: true,
                    scroll: false,
                    trigger: loadMoreButton,
                } );

                return;

            }

            if ( pageButton && target.contains( pageButton ) ) {

                event.preventDefault();
                loadUrl( buildUrl( Number.parseInt( pageButton.dataset.page, 10 ) || 1 ) );

                return;

            }

            if ( breakdancePage && target.contains( breakdancePage ) ) {

                event.preventDefault();
                loadUrl( new URL( breakdancePage.href, window.location.href ) );

            }

        } );

        if ( updateUrl ) {

            window.addEventListener( 'popstate', () => {

                const url = new URL( window.location.href );

                selectedTerm = getSelectedTerm();
                currentPage = getRequestedPage( url );

                setFilterState();
                loadUrl( url, {
                    changeHistory: false,
                } );

            } );

        }

        prepareTarget();

    }

    /*
    INITIALISE ALL
    -- Starts every manual control group and automatic native bridge
    ---------------------------------------------------------- */

    function initialiseAll() {

        document.querySelectorAll( '.oa-breakdance-ajax-controls' ).forEach( controls => {

            initialiseControls( controls );

        } );

    }

    if ( 'loading' === document.readyState ) {

        document.addEventListener( 'DOMContentLoaded', initialiseAll );

    } else {

        initialiseAll();

    }

} )();
