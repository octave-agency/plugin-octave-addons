/*
STRUCTURED CONTENT LAUNCHER
-- Registers and maintains the Gutenberg launcher for field-only post types.
---------------------------------------------------------- */

(function () {
    'use strict';

	var blockName = 'octave/block-octave-launcher';
	var unsubscribeFromEditor;

	/*
	RESET TO LAUNCHER
	-- Makes the launcher the sole content block, matching Breakdance's model.
	---------------------------------------------------------- */

	function resetToLauncher() {

		wp.data.dispatch( 'core/block-editor' ).resetBlocks( [ wp.blocks.createBlock( blockName ) ] );

	}

	/*
	LOCK LAUNCHER CONTENT
	-- Restores the launcher if another block is inserted or it is removed.
	---------------------------------------------------------- */

	function lockLauncherContent() {

		document.body.classList.add( 'oa-structured-content-editor' );

		if ( unsubscribeFromEditor ) {

			unsubscribeFromEditor();

		}

		unsubscribeFromEditor = wp.data.subscribe( function () {

			var blocks = wp.data.select( 'core/block-editor' ).getBlocks();
			var onlyLauncher = 1 === blocks.length && blockName === blocks[0].name;

			if ( ! onlyLauncher ) {

				resetToLauncher();

			}

		} );

	}

	wp.blocks.registerBlockType( blockName, {
		apiVersion: 2,
		title: 'Octave Content Fields',
		icon: 'feedback',
		category: 'common',
		supports: {
			customClassName: false,
			html: false,
			inserter: false,
			multiple: false,
			reusable: false
		},
		edit: function ( props ) {

			wp.element.useEffect( function () {

				lockLauncherContent();

				return function () {

					if ( unsubscribeFromEditor ) {

						unsubscribeFromEditor();
						unsubscribeFromEditor = null;

					}

				};

			}, [] );

			return wp.element.createElement(
				'div',
				{ className: props.className },
				wp.element.createElement(
					'div',
					{ className: 'oa-content-launcher' },
					wp.element.createElement( 'p', { className: 'oa-content-launcher__title' }, octaveStructuredContent.title ),
					wp.element.createElement( 'p', { className: 'oa-content-launcher__description' }, octaveStructuredContent.description )
				)
			);

		},
		save: function () {

			return null;

		}
	} );

	wp.domReady( function () {

		var blocks = wp.data.select( 'core/block-editor' ).getBlocks();
		var onlyLauncher = 1 === blocks.length && blockName === blocks[0].name;

		if ( ! onlyLauncher ) {

			resetToLauncher();

		}

		lockLauncherContent();

	} );

})();
