<?php

/*
ANIMATE ALL h1,h2,h3 ELEMENTS WITH GSAP
-- Adds legacy GSAP heading reveal animation markup in the footer
---------------------------------------------------------- */

add_action( 'wp_footer', function () {

    if ( ! is_front_page() ) {

        echo '<script src="https://unpkg.com/gsap@3.12.2/dist/ScrollTrigger.min.js" defer></script>';

    }

?>

<script defer>

    document.addEventListener( 'DOMContentLoaded', function() {

        if ( typeof( gsap ) !== "object" ) {

            return;

        }

        gsap.registerPlugin( ScrollTrigger );

        function splitTextPreserveSpans( element ) {

            const nodes = Array.from( element.childNodes );

            nodes.forEach( node => {

                if ( node.nodeType === Node.TEXT_NODE ) {

                    const words = node.textContent.split( ' ' );
                    const fragment = document.createDocumentFragment();

                    words.forEach( ( word, index ) => {

                        const newWord = word.trim();

                        if ( ! newWord ) {

                            return;

                        }

                        const mask = document.createElement( 'span' );
                        mask.className = 'word-mask';

                        const wordSpan = document.createElement( 'span' );
                        wordSpan.className = 'word';
                        wordSpan.textContent = newWord + ' ';

                        mask.appendChild( wordSpan );
                        fragment.appendChild( mask );

                    } );

                    node.replaceWith( fragment );

                }

                if ( node.nodeType === Node.ELEMENT_NODE ) {

                    splitTextPreserveSpans( node );

                }

            } );

            element.classList.add( 'oa_gsap' );

        }

        document.querySelectorAll( 'h1, h2, h3, h4' ).forEach( heading => {

            splitTextPreserveSpans( heading );

            var words = heading.querySelectorAll( '.word' );

            gsap.set( words, {

                y: '120%',
                opacity: 0

            } );

            gsap.to( words, {

                y: '0%',
                opacity: 1,
                duration: 0.5,
                ease: 'power3.out',
                stagger: 0.025,
                scrollTrigger: {

                    trigger: heading,
                    start: 'top 80%',
                    end: 'top: 40%',
                    scrub: false,
                    markers: false,
                    once: false

                }

            } );

        } );

    } );

</script>

<?php

} );
