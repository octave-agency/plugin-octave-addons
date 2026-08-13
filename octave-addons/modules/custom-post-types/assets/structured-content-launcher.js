/*
STRUCTURED CONTENT EDITOR
-- Replaces the block canvas with a notice and the post type's Octave fields
-- whenever the standard content editor is switched off for that post type.
-- Values bind straight to registered post meta, so Gutenberg's own save,
-- autosave, and revision handling apply without any custom save routine.
---------------------------------------------------------- */

( function () {

	'use strict';

	if ( ! window.octaveStructuredContent || ! window.octaveStructuredContent.enabled ) {

		return;

	}

	if ( ! window.wp || ! wp.blocks || ! wp.data || ! wp.element || ! wp.blockEditor ) {

		return;

	}

	if ( ! wp.coreData || ! wp.coreData.useEntityProp ) {

		return;

	}

	var settings      = window.octaveStructuredContent;
	var strings       = settings.strings || {};
	var blockName     = 'octave/block-octave-launcher';
	var createElement = wp.element.createElement;
	var Fragment      = wp.element.Fragment;
	var components    = wp.components;
	var unsubscribe   = null;
	var isResetting   = false;

	/*
	IS TARGET EDITOR
	-- Ensures cached or combined assets cannot affect another post type.
	---------------------------------------------------------- */

	function isTargetEditor() {

		var editor = wp.data.select( 'core/editor' );

		return !! editor && settings.postType === editor.getCurrentPostType();

	}

	/*
	PARSE CHOICES
	-- Converts the saved line-based choice format into control options.
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
	DEFAULT ROW
	-- Seeds a new group or repeater row with its configured child defaults.
	---------------------------------------------------------- */

	function defaultRow( subFields ) {

		var row = {};

		( subFields || [] ).forEach( function ( subField ) {

			row[ subField.name ] = subField.default_value;

		} );

		return row;

	}

	/*
	FIELD WRAPPER
	-- Gives every control the same label, required marker, and help text.
	---------------------------------------------------------- */

	function FieldWrapper( props ) {

		return createElement(
			'div',
			{ className: 'oa-field oa-field--' + props.field.type },
			createElement(
				'div',
				{ className: 'oa-field__label' },
				createElement( 'span', null, props.field.label ),
				props.field.required ? createElement( 'span', { className: 'oa-field__required' }, strings.required ) : null
			),
			createElement( 'div', { className: 'oa-field__control' }, props.children ),
			props.field.description ? createElement( 'p', { className: 'oa-field__description' }, props.field.description ) : null
		);

	}

	/*
	MEDIA CONTROL
	-- Opens the Media Library from inside the canvas and stores only the ID.
	---------------------------------------------------------- */

	function MediaControl( props ) {

		var attachmentId = parseInt( props.value, 10 ) || 0;
		var attachment   = wp.data.useSelect( function ( select ) {

			return attachmentId ? select( 'core' ).getMedia( attachmentId ) : null;

		}, [ attachmentId ] );

		var isImage    = 'image' === props.field.type;
		var previewUrl = attachment && attachment.source_url ? attachment.source_url : '';
		var mediaLabel = attachment && attachment.title && attachment.title.rendered
			? attachment.title.rendered
			: ( attachmentId ? '#' + attachmentId : strings.noMedia );

		return createElement(
			'div',
			{ className: 'oa-media' },
			isImage && previewUrl
				? createElement( 'img', { alt: '', className: 'oa-media__preview', src: previewUrl } )
				: createElement( 'span', { className: 'oa-media__placeholder', 'aria-hidden': 'true' } ),
			createElement(
				'div',
				{ className: 'oa-media__body' },
				createElement( 'span', { className: 'oa-media__name' }, mediaLabel ),
				createElement(
					'div',
					{ className: 'oa-media__actions' },
					createElement( wp.blockEditor.MediaUploadCheck, null, createElement( wp.blockEditor.MediaUpload, {
						allowedTypes: isImage ? [ 'image' ] : undefined,
						multiple: false,
						value: attachmentId,
						onSelect: function ( media ) {

							props.onChange( media && media.id ? String( media.id ) : '' );

						},
						render: function ( renderProps ) {

							return createElement(
								components.Button,
								{ variant: 'secondary', size: 'compact', onClick: renderProps.open },
								attachmentId ? strings.replaceMedia : strings.chooseMedia
							);

						}
					} ) ),
					attachmentId
						? createElement( components.Button, {
							isDestructive: true,
							size: 'compact',
							variant: 'tertiary',
							onClick: function () {

								props.onChange( '' );

							}
						}, strings.removeMedia )
						: null
				)
			)
		);

	}

	/*
	CHECKBOX GROUP CONTROL
	-- Presents multi-select choices as tick boxes instead of a native list.
	---------------------------------------------------------- */

	function CheckboxGroupControl( props ) {

		var selected = Array.isArray( props.value ) ? props.value : [];

		return createElement( 'div', { className: 'oa-checkbox-group' }, props.options.map( function ( option ) {

			return createElement( components.CheckboxControl, {
				__nextHasNoMarginBottom: true,
				checked: -1 !== selected.indexOf( option.value ),
				key: option.value,
				label: option.label,
				onChange: function ( checked ) {

					if ( checked ) {

						props.onChange( selected.concat( [ option.value ] ) );

						return;

					}

					props.onChange( selected.filter( function ( item ) {

						return item !== option.value;

					} ) );

				}
			} );

		} ) );

	}

	/*
	ROW FIELDS
	-- Renders the child controls of a group or one repeater row. A child only
	-- falls back to its default while the row has no entry for it at all, so a
	-- deliberately cleared value never reverts on the next render.
	---------------------------------------------------------- */

	function RowFields( props ) {

		var row = props.value && 'object' === typeof props.value && ! Array.isArray( props.value ) ? props.value : {};

		return createElement( 'div', { className: 'oa-row-fields' }, ( props.subFields || [] ).map( function ( subField ) {

			var value = Object.prototype.hasOwnProperty.call( row, subField.name )
				? row[ subField.name ]
				: subField.default_value;

			return createElement( FieldControl, {
				field: subField,
				key: subField.name,
				value: value,
				onChange: function ( nextValue ) {

					var nextRow = Object.assign( {}, row );

					nextRow[ subField.name ] = nextValue;
					props.onChange( nextRow );

				}
			} );

		} ) );

	}

	/*
	REPEATER CONTROL
	-- Adds, reorders, and removes rows while keeping row order stable.
	---------------------------------------------------------- */

	function RepeaterControl( props ) {

		var rows = Array.isArray( props.value ) ? props.value : [];

		function replaceRow( index, nextRow ) {

			props.onChange( rows.map( function ( row, position ) {

				return position === index ? nextRow : row;

			} ) );

		}

		function moveRow( index, offset ) {

			var target = index + offset;

			if ( 0 > target || target >= rows.length ) {

				return;

			}

			var nextRows = rows.slice();
			var moved    = nextRows.splice( index, 1 )[0];

			nextRows.splice( target, 0, moved );
			props.onChange( nextRows );

		}

		return createElement(
			Fragment,
			null,
			rows.length
				? createElement( 'div', { className: 'oa-repeater' }, rows.map( function ( row, index ) {

					return createElement(
						'div',
						{ className: 'oa-repeater__row', key: index },
						createElement(
							'div',
							{ className: 'oa-repeater__head' },
							createElement( 'strong', null, strings.item.replace( '%d', index + 1 ) ),
							createElement(
								'div',
								{ className: 'oa-repeater__actions' },
								createElement( components.Button, {
									disabled: 0 === index,
									icon: 'arrow-up-alt2',
									label: strings.moveUp,
									size: 'small',
									onClick: function () {

										moveRow( index, -1 );

									}
								} ),
								createElement( components.Button, {
									disabled: index === rows.length - 1,
									icon: 'arrow-down-alt2',
									label: strings.moveDown,
									size: 'small',
									onClick: function () {

										moveRow( index, 1 );

									}
								} ),
								createElement( components.Button, {
									icon: 'trash',
									isDestructive: true,
									label: strings.removeItem,
									size: 'small',
									onClick: function () {

										props.onChange( rows.filter( function ( item, position ) {

											return position !== index;

										} ) );

									}
								} )
							)
						),
						createElement( RowFields, {
							onChange: function ( nextRow ) {

								replaceRow( index, nextRow );

							},
							subFields: props.field.sub_fields,
							value: row
						} )
					);

				} ) )
				: createElement( 'p', { className: 'oa-repeater__empty' }, strings.noItems ),
			createElement( components.Button, {
				icon: 'plus-alt2',
				variant: 'secondary',
				onClick: function () {

					props.onChange( rows.concat( [ defaultRow( props.field.sub_fields ) ] ) );

				}
			}, strings.addItem )
		);

	}

	/*
	FIELD CONTROL
	-- Maps every Octave field type to an editor-native Gutenberg control.
	---------------------------------------------------------- */

	function FieldControl( props ) {

		var field   = props.field;
		var value   = props.value;
		var choices = parseChoices( field.choices );
		var control;

		if ( 'group' === field.type ) {

			control = createElement( RowFields, {
				onChange: props.onChange,
				subFields: field.sub_fields,
				value: value
			} );

		} else if ( 'repeater' === field.type ) {

			control = createElement( RepeaterControl, props );

		} else if ( 'wysiwyg' === field.type ) {

			control = createElement( wp.blockEditor.RichText, {
				className: 'oa-rich-text',
				onChange: props.onChange,
				tagName: 'div',
				value: String( value || '' )
			} );

		} else if ( 'textarea' === field.type ) {

			control = createElement( components.TextareaControl, {
				__nextHasNoMarginBottom: true,
				onChange: props.onChange,
				rows: 4,
				value: String( value || '' )
			} );

		} else if ( 'select' === field.type ) {

			control = createElement( components.SelectControl, {
				__nextHasNoMarginBottom: true,
				onChange: props.onChange,
				options: [ { label: strings.selectOption, value: '' } ].concat( choices ),
				value: String( value || '' )
			} );

		} else if ( 'multiselect' === field.type ) {

			control = createElement( CheckboxGroupControl, {
				onChange: props.onChange,
				options: choices,
				value: value
			} );

		} else if ( 'radio' === field.type ) {

			control = createElement( components.RadioControl, {
				onChange: props.onChange,
				options: choices,
				selected: String( value || '' )
			} );

		} else if ( 'checkbox' === field.type ) {

			control = createElement( components.ToggleControl, {
				__nextHasNoMarginBottom: true,
				checked: '1' === String( value ),
				label: strings.yes,
				onChange: function ( checked ) {

					props.onChange( checked ? '1' : '0' );

				}
			} );

		} else if ( 'range' === field.type ) {

			control = createElement( components.RangeControl, {
				__nextHasNoMarginBottom: true,
				onChange: function ( next ) {

					props.onChange( undefined === next || null === next ? '' : String( next ) );

				},
				value: '' === String( value || '' ) ? undefined : Number( value )
			} );

		} else if ( 'image' === field.type || 'file' === field.type ) {

			control = createElement( MediaControl, props );

		} else {

			control = createElement( components.TextControl, {
				__nextHasNoMarginBottom: true,
				onChange: props.onChange,
				step: 'number' === field.type ? 'any' : undefined,
				type: 'datetime' === field.type ? 'datetime-local' : field.type,
				value: String( value || '' )
			} );

		}

		return createElement( FieldWrapper, { field: field }, control );

	}

	/*
	SCHEMA EDITOR
	-- Binds the visible controls directly to registered Gutenberg post meta.
	-- REST reports unset meta as an empty value, so the field default is shown
	-- only while PHP reported no stored row and the editor has not touched it.
	-- That keeps a deliberately cleared value from reverting to its default.
	---------------------------------------------------------- */

	function SchemaEditor() {

		var fields     = settings.fields || [];
		var storedKeys = settings.storedKeys || {};
		var metaState  = wp.coreData.useEntityProp( 'postType', settings.postType, 'meta' );
		var meta       = metaState[0] || {};
		var setMeta    = metaState[1];
		var touchState = wp.element.useState( {} );
		var touched    = touchState[0];
		var setTouched = touchState[1];

		if ( ! fields.length ) {

			return createElement( 'p', { className: 'oa-fields__empty' }, strings.emptyFields );

		}

		return createElement( 'div', { className: 'oa-fields' }, fields.map( function ( field ) {

			var isStored = touched[ field.meta_key ] || Object.prototype.hasOwnProperty.call( storedKeys, field.meta_key );
			var value    = isStored ? meta[ field.meta_key ] : field.default_value;

			return createElement( FieldControl, {
				field: field,
				key: field.meta_key,
				onChange: function ( nextValue ) {

					var nextMeta = {};

					nextMeta[ field.meta_key ] = nextValue;

					setTouched( function ( current ) {

						var next = Object.assign( {}, current );

						next[ field.meta_key ] = true;

						return next;

					} );

					setMeta( Object.assign( {}, meta, nextMeta ) );

				},
				value: value
			} );

		} ) );

	}

	/*
	LOCK CANVAS
	-- Keeps the notice block as the only block, mirroring how Breakdance
	-- protects a page it owns, without looping on its own reset dispatch.
	---------------------------------------------------------- */

	function resetToLauncher() {

		if ( isResetting ) {

			return;

		}

		isResetting = true;

		wp.data.dispatch( 'core/block-editor' ).resetBlocks( [ wp.blocks.createBlock( blockName ) ] );

		window.setTimeout( function () {

			isResetting = false;

		}, 0 );

	}

	function needsLauncher() {

		var blocks = wp.data.select( 'core/block-editor' ).getBlocks();

		return ! ( 1 === blocks.length && blockName === blocks[0].name );

	}

	function lockCanvas() {

		document.body.classList.add( 'oa-structured-content-editor' );

		if ( unsubscribe ) {

			return;

		}

		unsubscribe = wp.data.subscribe( function () {

			if ( isResetting || ! needsLauncher() ) {

				return;

			}

			resetToLauncher();

		} );

	}

	/*
	REGISTER BLOCK
	-- Declares the canvas block that carries the notice and the field form.
	---------------------------------------------------------- */

	wp.blocks.registerBlockType( blockName, {
		apiVersion: 2,
		title: strings.blockTitle,
		icon: 'feedback',
		category: 'common',
		supports: {
			customClassName: false,
			html: false,
			inserter: false,
			lock: false,
			multiple: false,
			reusable: false
		},
		edit: function () {

			wp.element.useEffect( function () {

				lockCanvas();

			}, [] );

			return createElement(
				'div',
				wp.blockEditor.useBlockProps( { className: 'oa-structured-content' } ),
				createElement(
					'div',
					{ className: 'oa-structured-content__notice' },
					createElement( 'span', { className: 'oa-structured-content__icon', 'aria-hidden': 'true' } ),
					createElement( 'p', { className: 'oa-structured-content__title' }, strings.title ),
					createElement( 'p', { className: 'oa-structured-content__description' }, strings.description )
				),
				createElement( SchemaEditor )
			);

		},
		save: function () {

			return null;

		}
	} );

	/*
	BOOTSTRAP
	-- domReady can run before Gutenberg has set the post up, so this waits for
	-- the editor store to report a post status first. WordPress only applies a
	-- post type template to brand new posts, so an existing post that predates
	-- the setting still needs the canvas replaced here.
	---------------------------------------------------------- */

	function isEditorReady() {

		var editor = wp.data.select( 'core/editor' );

		return !! editor && !! editor.getEditedPostAttribute( 'status' );

	}

	function bootstrap() {

		if ( ! isTargetEditor() ) {

			return;

		}

		if ( needsLauncher() ) {

			resetToLauncher();

		}

		lockCanvas();

	}

	wp.domReady( function () {

		if ( isEditorReady() ) {

			window.setTimeout( bootstrap, 0 );

			return;

		}

		var unsubscribeFromBoot = wp.data.subscribe( function () {

			if ( ! isEditorReady() ) {

				return;

			}

			if ( unsubscribeFromBoot ) {

				unsubscribeFromBoot();

			}

			window.setTimeout( bootstrap, 0 );

		} );

	} );

} )();
