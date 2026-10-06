/*
EDITORIAL PRESET
-- Headings reveal line by line; media trails text slightly for an
-- intentional, asymmetric rhythm
---------------------------------------------------------- */

if ( window.OctaveAnimations ) {

	window.OctaveAnimations.preset( {
		split: 'lines',
		lineStagger: 120,
		stagger: 110,
		maxStagger: 440,
		settle: 1900,
		delay: function ( element, index, fallback ) {

			return 'media' === element.getAttribute( 'data-oa-anim' ) ? fallback + 140 : fallback;

		}
	} );

}
