/*
ANIMATION.JS
-- Handles Octave scroll-triggered reveal animations
---------------------------------------------------------- */

const visibleClass = 'visible';
const sectionSelector = '.bde-section';

/*
SECTION-SCOPED LOOKUPS
---------------------------------------------------------- */

const getSectionRoots = () => {

    return Array.from( document.querySelectorAll( sectionSelector ) );

};

const getScopedMatches = ( selector ) => {

    const matches = [];

    getSectionRoots().forEach( section => {

        if ( section.matches( selector ) ) {

            matches.push( section );

        }

        matches.push( ...section.querySelectorAll( selector ) );

    } );

    return [ ...new Set( matches ) ];

};

/*
VISIBILITY HELPERS
---------------------------------------------------------- */

const isElementInViewport = ( element, threshold = 0.15 ) => {

    const rect = element.getBoundingClientRect();
    const viewportHeight = window.innerHeight || document.documentElement.clientHeight;

    if ( rect.height <= 0 ) {

        return false;

    }

    const visibleHeight = Math.min( rect.bottom, viewportHeight ) - Math.max( rect.top, 0 );

    return visibleHeight >= rect.height * threshold;

};

const createFallbackObserver = ( elements, options = {}, onVisible ) => {

    const pendingElements = new Set( elements );
    const threshold = options.threshold ?? 0.15;

    const checkVisibility = () => {

        pendingElements.forEach( element => {

            if ( ! isElementInViewport( element, threshold ) ) {

                return;

            }

            onVisible( element );
            pendingElements.delete( element );

        } );

        if ( ! pendingElements.size ) {

            window.removeEventListener( 'scroll', checkVisibility );
            window.removeEventListener( 'resize', checkVisibility );
            window.removeEventListener( 'orientationchange', checkVisibility );
            window.removeEventListener( 'load', checkVisibility );

        }

    };

    window.addEventListener( 'scroll', checkVisibility, { passive: true } );
    window.addEventListener( 'resize', checkVisibility );
    window.addEventListener( 'orientationchange', checkVisibility );
    window.addEventListener( 'load', checkVisibility );

    checkVisibility();

};

/*
UNIVERSAL INTERSECTION OBSERVER
---------------------------------------------------------- */

const observe = ( elements, options = {}, onVisible ) => {

    if ( ! elements.length ) {

        return;

    }

    const handleVisible = onVisible || ( element => {

        element.classList.add( visibleClass );

    } );

    createFallbackObserver( elements, options, handleVisible );

    if ( 'function' !== typeof IntersectionObserver ) {

        return;

    }

    const io = new IntersectionObserver( entries => {

        entries.forEach( entry => {

            if ( ! entry.isIntersecting ) {

                return;

            }

            handleVisible( entry.target );
            io.unobserve( entry.target );

        } );

    }, {
        threshold: options.threshold ?? 0.15,
        rootMargin: options.rootMargin ?? '0px 0px -8% 0px'
    } );

    elements.forEach( element => {

        io.observe( element );

    } );

};

/*
WORD SPLIT FOR .BDE-HEADING
---------------------------------------------------------- */

const headings = '.bde-heading';

getScopedMatches( headings ).forEach( heading => {

    // Preserve inline child elements instead of flattening them into text.
    const childNodes = Array.from( heading.childNodes );
    heading.innerHTML = '';

    let wordIndex = 0;

    childNodes.forEach( node => {

        if ( node.nodeType === Node.TEXT_NODE ) {

            node.textContent.split( /\s+/ ).filter( word => word.length > 0 ).forEach( word => {

                const wordEl = document.createElement( 'span' );
                wordEl.className = 'word';

                const span = document.createElement( 'span' );
                span.textContent = word;
                span.style.transitionDelay = `${ wordIndex * 0.06 }s`;

                wordEl.appendChild( span );
                heading.appendChild( wordEl );
                heading.appendChild( document.createTextNode( ' ' ) );
                wordIndex++;

            } );

            return;

        }

        if ( node.nodeType !== Node.ELEMENT_NODE ) {

            return;

        }

        if ( 'BR' === node.tagName ) {

            heading.appendChild( document.createElement( 'br' ) );

            return;

        }

        const wordEl = document.createElement( 'span' );
        wordEl.className = `word word--inline ${ node.className }`.trim();

        wordEl.style.setProperty( '--word-delay', `${ wordIndex * 0.06 }s` );
        node.removeAttribute( 'class' );
        wordEl.appendChild( node );
        heading.appendChild( wordEl );
        heading.appendChild( document.createTextNode( ' ' ) );
        wordIndex++;

    } );

} );

observe( getScopedMatches( headings ), { threshold: 0.4 } );

/*
SINGLE ANIMATED ITEMS
---------------------------------------------------------- */

const elements = '.bde-image, .bde-button, .bde-text, .bde-rich-text, .swiper';
const elementItems = getScopedMatches( elements );

elementItems.forEach( element => {

    element.classList.add( 'oa_anim' );

} );

observe( elementItems, { threshold: 0.2 } );

/*
LOOP ITEMS
---------------------------------------------------------- */

const loopItems = getScopedMatches( '.bde-loop:not(.ee-posts-isotope) > .bde-loop-item' );

observe( loopItems, { threshold: 0.12 } );

/*
STAGGER SYSTEM
---------------------------------------------------------- */

const staggerGroups = [
    {
        parent: '.bde-frequently-asked-questions',
        children: '.bde-faq__item',
        delay: 120,
        threshold: 0.2
    },
    {
        parent: '.bde-grid',
        children: ':scope > div',
        delay: 240,
        threshold: 0.12
    },
    {
        parent: '.bde-section, .bde-container',
        children: '.bde-column',
        delay: 100,
        threshold: 0.12
    },
    {
        parent: 'ul, ol',
        children: ':scope > li',
        delay: 100,
        threshold: 0.12
    },
    {
        parent: '.swiper',
        children: '.swiper-slide',
        delay: 120,
        threshold: 0.12
    }
];

staggerGroups.forEach( group => {

    getScopedMatches( group.parent ).forEach( parentEl => {

        const items = Array.from( parentEl.querySelectorAll( group.children ) );

        if ( ! items.length ) {

            return;

        }

        let hasAnimated = false;

        observe( [ parentEl ], { threshold: group.threshold }, () => {

            if ( hasAnimated ) {

                return;

            }

            hasAnimated = true;
            parentEl.classList.add( visibleClass );

            items.forEach( ( item, index ) => {

                window.setTimeout( () => {

                    item.classList.add( visibleClass );

                }, index * group.delay );

            } );

        } );

    } );

} );
