/*
STRUCTURED CONTENT EDITOR
-- Replaces the block canvas with the post type's Octave fields whenever the
-- standard content editor is switched off for that post type.
-- Values bind straight to registered post meta, so Gutenberg's own save,
-- autosave, and revision handling apply without any custom save routine.
-- Required fields lock post saving and raise an editor notice naming them,
-- which the REST validation in class-post-fields.php backs up on the server.
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
	var lockKey       = 'octave-structured-content';
	var noticeId      = 'octave-structured-content-required';
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
	ROW VALUE
	-- Falls back to the child default only while the row has no entry for it at
	-- all, so a deliberately cleared value never reverts on the next render and
	-- validation judges exactly what the editor can see.
	---------------------------------------------------------- */

	function rowValue( row, subField ) {

		var values = row && 'object' === typeof row && ! Array.isArray( row ) ? row : {};

		return Object.prototype.hasOwnProperty.call( values, subField.name )
			? values[ subField.name ]
			: subField.default_value;

	}

	/*
	IS EMPTY VALUE
	-- Mirrors the PHP emptiness rules so the canvas and the server never
	-- disagree about which required fields are still outstanding.
	---------------------------------------------------------- */

	function isEmptyValue( field, value ) {

		if ( 'html' === field.type ) {

			return false;

		}

		if ( 'group' === field.type ) {

			return isRowEmpty( field.sub_fields, value );

		}

		if ( 'repeater' === field.type || 'multiselect' === field.type || 'gallery' === field.type ) {

			return ! ( Array.isArray( value ) && value.length );

		}

		if ( 'checkbox' === field.type ) {

			return '1' !== String( value );

		}

		if ( 'image' === field.type || 'file' === field.type ) {

			return ! parseInt( value, 10 );

		}

		return '' === String( null === value || undefined === value ? '' : value ).trim();

	}

	/*
	IS ROW EMPTY
	-- Treats a group or repeater row as empty only when every child is empty.
	---------------------------------------------------------- */

	function isRowEmpty( subFields, row ) {

		return ! ( subFields || [] ).some( function ( subField ) {

			return ! isEmptyValue( subField, rowValue( row, subField ) );

		} );

	}

	/*
	MISSING LABELS
	-- Names every unfilled required field. A container reports itself only while
	-- it is completely empty; once it holds content its own required children
	-- are reported instead, which matches how PHP discards empty rows on save.
	---------------------------------------------------------- */

	function missingLabels( field, value, prefix ) {

		if ( 'html' === field.type ) {

			return [];

		}

		var label = ( prefix || '' ) + field.label;

		if ( 'group' !== field.type && 'repeater' !== field.type ) {

			return field.required && isEmptyValue( field, value ) ? [ label ] : [];

		}

		var isGroup = 'group' === field.type;
		var rows    = isGroup ? [ value ] : ( Array.isArray( value ) ? value : [] );
		var missing = [];

		if ( isGroup ? isRowEmpty( field.sub_fields, value ) : ! rows.length ) {

			return field.required ? [ label ] : [];

		}

		rows.forEach( function ( row, index ) {

			var rowPrefix = isGroup
				? label + ' › '
				: label + ' – ' + strings.item.replace( '%d', index + 1 ) + ' › ';

			( field.sub_fields || [] ).forEach( function ( subField ) {

				missing = missing.concat( missingLabels( subField, rowValue( row, subField ), rowPrefix ) );

			} );

		} );

		return missing;

	}

	/*
	FIELD WRAPPER
	-- Gives every control the same label, required marker, error, and help text.
	---------------------------------------------------------- */

	function FieldWrapper( props ) {

		var field = props.field;

		return createElement(
			'div',
			{ className: 'oa-field oa-field--' + field.type + ( props.invalid ? ' is-invalid' : '' ) },
			createElement(
				'div',
				{ className: 'oa-field__label' },
				createElement( 'span', { className: 'oa-field__name' }, field.label ),
				field.required ? createElement( 'span', { className: 'oa-field__required' }, strings.required ) : null
			),
			createElement( 'div', { className: 'oa-field__control' }, props.children ),
			props.invalid ? createElement( 'div', { className: 'oa-field__error' }, strings.fieldRequired ) : null,
			field.description ? createElement( 'div', { className: 'oa-field__description' }, field.description ) : null
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
	GALLERY CONTROL
	-- Selects many attachments at once and stores their IDs in display order.
	-- Choosing again adds to the gallery rather than replacing it, so an order
	-- arranged by dragging survives every later trip to the Media Library.
	---------------------------------------------------------- */

	function GalleryControl( props ) {

		var ids = ( Array.isArray( props.value ) ? props.value : [] ).map( function ( id ) {

			return parseInt( id, 10 ) || 0;

		} ).filter( Boolean );

		var dragIndex = wp.element.useRef( null );
		var attachments = wp.data.useSelect( function ( select ) {

			var found = {};

			ids.forEach( function ( id ) {

				found[ id ] = select( 'core' ).getMedia( id );

			} );

			return found;

		}, [ ids.join( ',' ) ] );

		function previewUrl( id ) {

			var attachment = attachments[ id ];
			var sizes = attachment && attachment.media_details ? attachment.media_details.sizes : null;

			if ( sizes && sizes.thumbnail ) {

				return sizes.thumbnail.source_url;

			}

			return attachment && attachment.source_url ? attachment.source_url : '';

		}

		function moveImage( from, to ) {

			if ( 0 > to || to >= ids.length || from === to ) {

				return;

			}

			var next = ids.slice();
			var moved = next.splice( from, 1 )[0];

			next.splice( to, 0, moved );
			props.onChange( next );

		}

		return createElement(
			'div',
			{ className: 'oa-gallery' },
			ids.length
				? createElement( 'ul', { className: 'oa-gallery__items' }, ids.map( function ( id, index ) {

					var url = previewUrl( id );

					return createElement(
						'li',
						{
							'aria-label': strings.galleryItem.replace( '%d', index + 1 ),
							className: 'oa-gallery__item',
							draggable: true,
							key: id,
							tabIndex: 0,
							onDragStart: function () {

								dragIndex.current = index;

							},
							onDragOver: function ( event ) {

								event.preventDefault();

							},
							onDrop: function ( event ) {

								event.preventDefault();

								if ( null !== dragIndex.current ) {

									moveImage( dragIndex.current, index );
									dragIndex.current = null;

								}

							},
							onKeyDown: function ( event ) {

								if ( 'ArrowLeft' === event.key ) {

									event.preventDefault();
									moveImage( index, index - 1 );

								}

								if ( 'ArrowRight' === event.key ) {

									event.preventDefault();
									moveImage( index, index + 1 );

								}

							}
						},
						createElement( 'span', { className: 'oa-gallery__position' }, index + 1 ),
						url
							? createElement( 'img', { alt: '', className: 'oa-gallery__preview', src: url } )
							: createElement( 'span', { className: 'oa-gallery__placeholder', 'aria-hidden': 'true' } ),
						createElement( components.Button, {
							className: 'oa-gallery__remove',
							icon: 'no-alt',
							label: strings.removeImage,
							size: 'small',
							onClick: function () {

								props.onChange( ids.filter( function ( item, position ) {

									return position !== index;

								} ) );

							}
						} )
					);

				} ) )
				: createElement( 'div', { className: 'oa-gallery__empty' }, strings.noImages ),
			createElement(
				'div',
				{ className: 'oa-gallery__actions' },
				createElement( wp.blockEditor.MediaUploadCheck, null, createElement( wp.blockEditor.MediaUpload, {
					allowedTypes: [ 'image' ],
					multiple: 'add',
					value: ids,
					onSelect: function ( selection ) {

						var next = ids.slice();

						( Array.isArray( selection ) ? selection : [ selection ] ).forEach( function ( media ) {

							var id = media && media.id ? parseInt( media.id, 10 ) : 0;

							if ( id && -1 === next.indexOf( id ) ) {

								next.push( id );

							}

						} );

						props.onChange( next );

					},
					render: function ( renderProps ) {

						return createElement(
							components.Button,
							{ variant: 'secondary', size: 'compact', onClick: renderProps.open },
							strings.addImages
						);

					}
				} ) ),
				ids.length
					? createElement( components.Button, {
						isDestructive: true,
						size: 'compact',
						variant: 'tertiary',
						onClick: function () {

							props.onChange( [] );

						}
					}, strings.clearGallery )
					: null
			),
			ids.length ? createElement( 'p', { className: 'oa-gallery__hint' }, strings.galleryHint ) : null
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
	-- Renders the child controls of a group or one repeater row.
	---------------------------------------------------------- */

	function RowFields( props ) {

		var row = props.value && 'object' === typeof props.value && ! Array.isArray( props.value ) ? props.value : {};

		return createElement( 'div', { className: 'oa-row-fields' }, ( props.subFields || [] ).map( function ( subField ) {

			return createElement( FieldControl, {
				field: subField,
				key: subField.name,
				showErrors: props.showErrors,
				value: rowValue( row, subField ),
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
							createElement( 'span', { className: 'oa-repeater__number' }, strings.item.replace( '%d', index + 1 ) ),
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
							showErrors: props.showErrors,
							subFields: props.field.sub_fields,
							value: row
						} )
					);

				} ) )
				: createElement( 'div', { className: 'oa-repeater__empty' }, strings.noItems ),
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
	-- Maps every Octave field type to an editor-native Gutenberg control and
	-- marks the native input required so browsers and assistive tech agree with
	-- the save lock.
	---------------------------------------------------------- */

	function FieldControl( props ) {

		var field    = props.field;

		if ( 'html' === field.type ) {

			if ( String( field.default_value || '' ).trim() ) {

				return createElement( 'section', {
					className: 'oa-field oa-field--html',
					dangerouslySetInnerHTML: { __html: field.default_value }
				} );

			}

			return createElement(
				'section',
				{ className: 'oa-field oa-field--html' },
				createElement( 'h2', null, field.label )
			);

		}

		var value    = props.value;
		var choices  = parseChoices( field.choices );
		var isEmpty  = isEmptyValue( field, value );
		var invalid  = !! field.required && isEmpty && !! props.showErrors;
		var required = !! field.required;
		var control;

		if ( 'group' === field.type ) {

			control = createElement( RowFields, {
				onChange: props.onChange,
				showErrors: props.showErrors && ! isEmpty,
				subFields: field.sub_fields,
				value: value
			} );

		} else if ( 'repeater' === field.type ) {

			control = createElement( RepeaterControl, props );

		} else if ( 'wysiwyg' === field.type ) {

			control = createElement( wp.blockEditor.RichText, {
				'aria-invalid': invalid ? 'true' : undefined,
				'aria-required': required ? 'true' : undefined,
				className: 'oa-rich-text',
				onChange: props.onChange,
				tagName: 'div',
				value: String( value || '' )
			} );

		} else if ( 'textarea' === field.type ) {

			control = createElement( components.TextareaControl, {
				__nextHasNoMarginBottom: true,
				'aria-invalid': invalid ? 'true' : undefined,
				onChange: props.onChange,
				required: required,
				rows: 4,
				value: String( value || '' )
			} );

		} else if ( 'select' === field.type ) {

			control = createElement( components.SelectControl, {
				__nextHasNoMarginBottom: true,
				'aria-invalid': invalid ? 'true' : undefined,
				onChange: props.onChange,
				options: [ { label: strings.selectOption, value: '' } ].concat( choices ),
				required: required,
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

		} else if ( 'gallery' === field.type ) {

			control = createElement( GalleryControl, props );

		} else if ( 'image' === field.type || 'file' === field.type ) {

			control = createElement( MediaControl, props );

		} else {

			control = createElement( components.TextControl, {
				__nextHasNoMarginBottom: true,
				'aria-invalid': invalid ? 'true' : undefined,
				onChange: props.onChange,
				required: required,
				step: 'number' === field.type ? 'any' : undefined,
				type: 'datetime' === field.type ? 'datetime-local' : field.type,
				value: String( value || '' )
			} );

		}

		return createElement( FieldWrapper, { field: field, invalid: invalid }, control );

	}

	/*
	SAVE LOCK
	-- Holds the post shut while a required field is outstanding and explains the
	-- hold in the editor notice area, because a disabled Update button on its own
	-- tells the editor nothing. Clearing the fault releases both.
	---------------------------------------------------------- */

	function useSaveLock( message ) {

		wp.element.useEffect( function () {

			var editor  = wp.data.dispatch( 'core/editor' );
			var notices = wp.data.dispatch( 'core/notices' );

			if ( ! message ) {

				editor.unlockPostSaving( lockKey );
				notices.removeNotice( noticeId );

				return;

			}

			editor.lockPostSaving( lockKey );
			notices.createNotice( 'error', message, {
				id: noticeId,
				isDismissible: false
			} );

		}, [ message ] );

		wp.element.useEffect( function () {

			return function () {

				wp.data.dispatch( 'core/editor' ).unlockPostSaving( lockKey );
				wp.data.dispatch( 'core/notices' ).removeNotice( noticeId );

			};

		}, [] );

	}

	/*
	STRUCTURED CONTENT
	-- Binds the visible controls directly to registered Gutenberg post meta.
	-- REST reports unset meta as an empty value, so the field default is shown
	-- only while PHP reported no stored row and the editor has not touched it.
	-- That keeps a deliberately cleared value from reverting to its default.
	---------------------------------------------------------- */

	function StructuredContent() {

		var fields     = settings.fields || [];
		var storedKeys = settings.storedKeys || {};
		var metaState  = wp.coreData.useEntityProp( 'postType', settings.postType, 'meta' );
		var meta       = metaState[0] || {};
		var setMeta    = metaState[1];
		var touchState = wp.element.useState( {} );
		var touched    = touchState[0];
		var setTouched = touchState[1];
		var isNewPost  = wp.data.useSelect( function ( select ) {

			return 'auto-draft' === select( 'core/editor' ).getEditedPostAttribute( 'status' );

		}, [] );
		var postTitle = wp.data.useSelect( function ( select ) {

			return select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';

		}, [] );
		var editingLabel = postTitle ? strings.editingNamed.replace( '{{title}}', postTitle ) : strings.editing;

		var missing = [];
		var entries = fields.map( function ( field ) {

			if ( 'html' === field.type ) {

				return { field: field, value: field.default_value };

			}

			var isStored = touched[ field.meta_key ] || Object.prototype.hasOwnProperty.call( storedKeys, field.meta_key );
			var value    = isStored ? meta[ field.meta_key ] : field.default_value;

			missing = missing.concat( missingLabels( field, value, '' ) );

			return { field: field, value: value };

		} );

		useSaveLock( missing.length ? strings.requiredNotice.replace( '%s', missing.join( ', ' ) ) : '' );

		function onFieldChange( field, nextValue ) {

			var nextMeta = {};

			nextMeta[ field.meta_key ] = nextValue;

			setTouched( function ( current ) {

				var next = Object.assign( {}, current );

				next[ field.meta_key ] = true;

				return next;

			} );

			setMeta( Object.assign( {}, meta, nextMeta ) );

		}

		return createElement(
			Fragment,
			null,
			createElement(
				'div',
				{ className: 'oa-structured-content__header' },
				createElement(
					'div',
					{ className: 'oa-structured-content__heading' },
					createElement( 'div', { className: 'oa-structured-content__eyebrow' }, editingLabel ),
					createElement( 'div', { className: 'oa-structured-content__title' }, strings.title ),
					createElement( 'p', { className: 'oa-structured-content__intro' }, strings.intro )
				),
				missing.length
					? createElement(
						'div',
						{ className: 'oa-structured-content__status oa-structured-content__status--invalid' },
						1 === missing.length ? strings.requiredSingle : strings.requiredPlural.replace( '%d', missing.length )
					)
					: createElement( 'div', { className: 'oa-structured-content__status oa-structured-content__status--ready' }, strings.ready )
			),
			fields.length
				? createElement( 'div', { className: 'oa-fields' }, entries.map( function ( entry ) {

					return createElement( FieldControl, {
						field: entry.field,
						key: entry.field.meta_key,
						showErrors: ! isNewPost || !! touched[ entry.field.meta_key ],
						value: entry.value,
						onChange: function ( nextValue ) {

							onFieldChange( entry.field, nextValue );

						}
					} );

				} ) )
				: createElement( 'div', { className: 'oa-fields__empty' }, strings.emptyFields )
		);

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
	-- Declares the canvas block that carries the field form.
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
				createElement( StructuredContent )
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
