/*
STRUCTURED CONTENT EDITOR
-- Renders Octave schema fields as the locked Gutenberg content experience.
---------------------------------------------------------- */

(function () {
    'use strict';

	if ( ! window.octaveStructuredContent || ! octaveStructuredContent.enabled ) {

		return;

	}

	var blockName = 'octave/block-octave-launcher';
	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var unsubscribeFromEditor;

	/*
	IS TARGET EDITOR
	-- Ensures cached or combined assets cannot affect another post type.
	---------------------------------------------------------- */

	function isTargetEditor() {

		var editor = wp.data.select( 'core/editor' );

		return editor && octaveStructuredContent.postType === editor.getCurrentPostType();

	}

	/*
	PARSE CHOICES
	-- Converts the saved line-based choice format into Gutenberg options.
	---------------------------------------------------------- */

	function parseChoices( choices ) {

		return String( choices || '' ).split( /\r?\n/ ).map( function ( line ) {

			var parts = line.split( ':' );
			var value = parts.shift().trim();
			var label = parts.join( ':' ).trim() || value;

			return value ? { label: label, value: value } : null;

		} ).filter( Boolean );

	}

	/*
	MEDIA CONTROL
	-- Uses the WordPress Media Library while storing only the attachment ID.
	---------------------------------------------------------- */

	function MediaControl( props ) {

		var attachmentId = parseInt( props.value, 10 ) || 0;
		var attachment = wp.data.useSelect( function ( select ) {

			return attachmentId ? select( 'core' ).getMedia( attachmentId ) : null;

		}, [ attachmentId ] );
		var mediaLabel = attachment && attachment.title && attachment.title.rendered ? attachment.title.rendered : ( attachmentId ? '#' + attachmentId : octaveStructuredContent.chooseMedia );

		function chooseMedia() {

			var frame = wp.media( {
				title: octaveStructuredContent.chooseMedia,
				button: { text: octaveStructuredContent.chooseMedia },
				library: 'image' === props.field.type ? { type: 'image' } : {},
				multiple: false
			} );

			frame.on( 'select', function () {

				var attachment = frame.state().get( 'selection' ).first().toJSON();

				props.onChange( String( attachment.id ) );

			} );

			frame.open();

		}

		return createElement(
			'div',
			{ className: 'oa-gutenberg-media' },
			'image' === props.field.type && attachment && attachment.source_url ? createElement( 'img', { alt: '', className: 'oa-gutenberg-media__preview', src: attachment.source_url } ) : null,
			createElement( 'span', { className: 'oa-gutenberg-media__value' }, mediaLabel ),
			createElement( wp.components.Button, { variant: 'secondary', onClick: chooseMedia }, attachmentId ? octaveStructuredContent.replaceMedia : octaveStructuredContent.chooseMedia ),
			attachmentId ? createElement( wp.components.Button, { isDestructive: true, onClick: function () {

				props.onChange( '' );

			} }, octaveStructuredContent.removeMedia ) : null
		);

	}

	/*
	FIELD CONTROL
	-- Maps every Octave schema type to an editor-native Gutenberg control.
	---------------------------------------------------------- */

	function FieldControl( props ) {

		var field = props.field;
		var value = props.value;
		var choices = parseChoices( field.choices );
		var inputType = 'datetime' === field.type ? 'datetime-local' : field.type;
		var label = createElement(
			'label',
			{ className: 'oa-gutenberg-field__label' },
			field.label,
			field.required ? createElement( 'span', { className: 'oa-post-field-required' }, 'Required' ) : null
		);
		var control;

		if ( 'group' === field.type ) {

			var groupValue = value && 'object' === typeof value && ! Array.isArray( value ) ? value : {};

			control = createElement( 'div', { className: 'oa-gutenberg-nested-fields' }, ( field.sub_fields || [] ).map( function ( subField ) {

				var subValue = Object.prototype.hasOwnProperty.call( groupValue, subField.name ) ? groupValue[ subField.name ] : subField.default_value;

				return createElement( FieldControl, {
					field: subField,
					key: subField.name,
					value: subValue,
					onChange: function ( nextValue ) {

						props.onChange( Object.assign( {}, groupValue, { [ subField.name ]: nextValue } ) );

					}
				} );

			} ) );

		} else if ( 'repeater' === field.type ) {

			var rows = Array.isArray( value ) ? value : [];

			control = createElement(
				Fragment,
				null,
				createElement( 'div', { className: 'oa-gutenberg-repeater' }, rows.map( function ( row, rowIndex ) {

					return createElement(
						'div',
						{ className: 'oa-gutenberg-repeater__row', key: rowIndex },
						createElement(
							'div',
							{ className: 'oa-gutenberg-repeater__head' },
							createElement( 'strong', null, 'Item ' + ( rowIndex + 1 ) ),
							createElement( wp.components.Button, { isDestructive: true, onClick: function () {

								props.onChange( rows.filter( function ( item, index ) {

									return index !== rowIndex;

								} ) );

							} }, octaveStructuredContent.removeItem )
						),
						createElement( 'div', { className: 'oa-gutenberg-nested-fields' }, ( field.sub_fields || [] ).map( function ( subField ) {

							var rowValue = row && 'object' === typeof row ? row : {};
							var subValue = Object.prototype.hasOwnProperty.call( rowValue, subField.name ) ? rowValue[ subField.name ] : subField.default_value;

							return createElement( FieldControl, {
								field: subField,
								key: subField.name,
								value: subValue,
								onChange: function ( nextValue ) {

									props.onChange( rows.map( function ( item, index ) {

										return index === rowIndex ? Object.assign( {}, rowValue, { [ subField.name ]: nextValue } ) : item;

									} ) );

								}
							} );

						} ) )
					);

				} ) ),
				createElement( wp.components.Button, { variant: 'secondary', onClick: function () {

					props.onChange( rows.concat( [ {} ] ) );

				} }, octaveStructuredContent.addItem )
			);

		} else if ( 'wysiwyg' === field.type ) {

			control = createElement( wp.blockEditor.RichText, {
				className: 'oa-gutenberg-rich-text',
				onChange: props.onChange,
				placeholder: field.placeholder || '',
				tagName: 'div',
				value: String( value || '' )
			} );

		} else if ( 'textarea' === field.type ) {

			control = createElement( wp.components.TextareaControl, { onChange: props.onChange, value: String( value || '' ) } );

		} else if ( 'select' === field.type ) {

			control = createElement( wp.components.SelectControl, { onChange: props.onChange, options: [ { label: 'Select an option', value: '' } ].concat( choices ), value: String( value || '' ) } );

		} else if ( 'multiselect' === field.type ) {

			control = createElement( 'select', { multiple: true, onChange: function ( event ) {

				props.onChange( Array.from( event.target.selectedOptions ).map( function ( option ) {

					return option.value;

				} ) );

			}, value: Array.isArray( value ) ? value : [] }, choices.map( function ( choice ) {

				return createElement( 'option', { key: choice.value, value: choice.value }, choice.label );

			} ) );

		} else if ( 'radio' === field.type ) {

			control = createElement( wp.components.RadioControl, { onChange: props.onChange, options: choices, selected: String( value || '' ) } );

		} else if ( 'checkbox' === field.type ) {

			control = createElement( wp.components.CheckboxControl, { checked: '1' === String( value ), label: 'Yes', onChange: function ( checked ) {

				props.onChange( checked ? '1' : '0' );

			} } );

		} else if ( 'image' === field.type || 'file' === field.type ) {

			control = createElement( MediaControl, props );

		} else {

			control = createElement( 'input', {
				className: 'components-text-control__input',
				onChange: function ( event ) {

					props.onChange( event.target.value );

				},
				step: 'number' === field.type || 'range' === field.type ? 'any' : undefined,
				type: inputType,
				value: String( value || '' )
			} );

		}

		return createElement(
			'div',
			{ className: 'oa-gutenberg-field oa-gutenberg-field--' + field.type },
			label,
			control,
			field.description ? createElement( 'p', { className: 'oa-gutenberg-field__description' }, field.description ) : null
		);

	}

	/*
	SCHEMA EDITOR
	-- Connects the visible controls directly to registered Gutenberg post meta.
	---------------------------------------------------------- */

	function SchemaEditor() {

		var meta = wp.data.useSelect( function ( select ) {

			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};

		}, [] );
		var editor = wp.data.useDispatch( 'core/editor' );
		var fields = octaveStructuredContent.fields || [];
		var storedValues = octaveStructuredContent.storedValues || {};
		var touchedState = wp.element.useState( {} );
		var touched = touchedState[0];
		var setTouched = touchedState[1];

		function updateField( field, value ) {

			var nextMeta = Object.assign( {}, meta );

			nextMeta[ field.meta_key ] = value;
			setTouched( function ( current ) {

				return Object.assign( {}, current, { [ field.meta_key ]: true } );

			} );
			editor.editPost( { meta: nextMeta } );

		}

		if ( ! fields.length ) {

			return createElement( 'p', { className: 'oa-gutenberg-fields__empty' }, octaveStructuredContent.emptyFields );

		}

		return createElement( 'div', { className: 'oa-gutenberg-fields' }, fields.map( function ( field ) {

			var hasStoredValue = Object.prototype.hasOwnProperty.call( storedValues, field.meta_key );
			var hasEditedValue = Object.prototype.hasOwnProperty.call( meta, field.meta_key );
			var value = touched[ field.meta_key ] || hasStoredValue
				? ( hasEditedValue ? meta[ field.meta_key ] : storedValues[ field.meta_key ] )
				: field.default_value;

			return createElement( FieldControl, {
				field: field,
				key: field.meta_key,
				onChange: function ( nextValue ) {

					updateField( field, nextValue );

				},
				value: value
			} );

		} ) );

	}

	/*
	LOCK CONTENT
	-- Keeps the schema editor as the sole block for field-only Octave CPTs.
	---------------------------------------------------------- */

	function resetToLauncher() {

		wp.data.dispatch( 'core/block-editor' ).resetBlocks( [ wp.blocks.createBlock( blockName ) ] );

	}

	function lockLauncherContent() {

		if ( ! isTargetEditor() ) {

			return;

		}

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
		edit: function () {

			wp.element.useEffect( function () {

				lockLauncherContent();

				return function () {

					if ( unsubscribeFromEditor ) {

						unsubscribeFromEditor();
						unsubscribeFromEditor = null;

					}

				};

			}, [] );

			return createElement(
				'div',
				wp.blockEditor.useBlockProps(),
				createElement(
					'div',
					{ className: 'oa-content-launcher' },
					createElement(
						'div',
						{ className: 'oa-content-launcher__notice' },
						createElement( 'p', { className: 'oa-content-launcher__title' }, octaveStructuredContent.title ),
						createElement( 'p', { className: 'oa-content-launcher__description' }, octaveStructuredContent.description )
					),
					createElement( SchemaEditor )
				)
			);

		},
		save: function () {

			return null;

		}
	} );

	wp.domReady( function () {

		if ( ! isTargetEditor() ) {

			return;

		}

		var blocks = wp.data.select( 'core/block-editor' ).getBlocks();
		var onlyLauncher = 1 === blocks.length && blockName === blocks[0].name;

		if ( ! onlyLauncher ) {

			resetToLauncher();

		}

		lockLauncherContent();

	} );

})();
