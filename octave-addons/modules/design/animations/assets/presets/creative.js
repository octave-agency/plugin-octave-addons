/*
CREATIVE PRESET
-- Cards and media enter from alternating sides based on their position
-- among their siblings, with an expressive but bounded stagger
---------------------------------------------------------- */

if ( window.OctaveAnimations ) {

	var oaCreativeEntrances = [
		'translate3d(-28px, 0, 0) rotate(-2deg)',
		'perspective(900px) translate3d(0, 32px, 0) rotateX(4deg)',
		'translate3d(28px, 0, 0) rotate(2deg)',
		'translate3d(0, 28px, 0) rotate(-1.5deg)'
	];

	window.OctaveAnimations.preset( {
		split: 'words',
		wordStagger: 35,
		stagger: 100,
		maxStagger: 520,
		settle: 1800,
		prepare: function ( element ) {

			var kind = element.getAttribute( 'data-oa-anim' );

			if ( 'item' !== kind && 'media' !== kind ) {

				return;

			}

			var position = Array.prototype.indexOf.call( element.parentNode.children, element );

			element.style.setProperty( '--oa-from', oaCreativeEntrances[ position % oaCreativeEntrances.length ] );

		},
		delay: function ( element, index, fallback ) {

			return fallback + ( index % 3 ) * 40;

		}
	} );

}
