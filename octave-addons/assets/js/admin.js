/*
ADMIN INTERACTIONS
-- Powers module settings controls, custom fields and save feedback.
---------------------------------------------------------- */

(function () {
	'use strict';

	/*
	NAV DOTS
	-- A nav item stands for one entry, which may hold several modules, so its
	-- dot is on whenever any toggle belonging to that entry is on.
	---------------------------------------------------------- */

	function syncNavDots() {

		document.querySelectorAll('.oa-nav-item[data-entry]').forEach(function (navItem) {

			var dot = navItem.querySelector('.oa-dot');

			if (!dot) {

				return;

			}

			var toggles = document.querySelectorAll('.oa-enable-toggle[data-entry="' + navItem.dataset.entry + '"]');

			// Only the open entry is in the form, so every other dot keeps the state the server rendered.
			if (!toggles.length) {

				return;

			}

			var on = Array.prototype.some.call(toggles, function (toggle) {

				return toggle.checked;

			});

			dot.classList.toggle('is-on', on);
			dot.classList.toggle('is-off', !on);

		});

	}

	/* Enable toggle → show/hide settings body + update sidebar dot */
	document.querySelectorAll( '.oa-enable-toggle' ).forEach( function ( toggle ) {

		var panelId = toggle.dataset.panel;
		var panel = panelId ? document.getElementById( panelId ) : null;

		if ( ! panel ) {

			return;

		}

		var body = panel.querySelector( '.oa-settings-body' );
		var locked = panel.querySelector( '.oa-settings-locked' );

		function sync() {

			var on = toggle.checked;

			// A disabled module keeps its values in the form, but nothing inside it
			// should be able to block the save while it is out of view.
			setFieldVisibility( body, on );

			if ( locked ) {

				locked.classList.toggle( 'oa-hidden', on );

			}

			syncNavDots();

		}

		toggle.addEventListener( 'change', sync );

		toggle.addEventListener( 'change', function () {

			oaNotify( toggle.checked ? oaAdmin.moduleEnabledText : oaAdmin.moduleDisabledText, 'info' );

		} );

		sync();

	} );

	/* Field row show/hide — control attributes accept comma-separated IDs */
	document.querySelectorAll( '[data-controls-row]' ).forEach( function ( checkbox ) {

		var ids = checkbox.dataset.controlsRow.split( ',' );
		var rows = ids.map( function ( id ) {

			return document.getElementById( id.trim() );

		} ).filter( Boolean );

		if ( ! rows.length ) {

			return;

		}

		function sync() {

			rows.forEach( function ( row ) {

				row.classList.toggle( 'oa-hidden', ! checkbox.checked );

			} );

		}

		sync();
		checkbox.addEventListener( 'change', sync );

	} );

	document.querySelectorAll( '[data-controls-row-hide]' ).forEach( function ( checkbox ) {

		var ids = checkbox.dataset.controlsRowHide.split( ',' );
		var rows = ids.map( function ( id ) {

			return document.getElementById( id.trim() );

		} ).filter( Boolean );

		if ( ! rows.length ) {

			return;

		}

		function sync() {

			rows.forEach( function ( row ) {

				row.classList.toggle( 'oa-hidden', checkbox.checked );

			} );

		}

		sync();
		checkbox.addEventListener( 'change', sync );

	} );

	/* Responsive nav dropdown */
	var navSelect = document.querySelector('.oa-nav-select');
	if (navSelect) {
		navSelect.addEventListener('change', function () {
			window.location.href = this.value;
		});
	}

	/*
	SETTINGS FEEDBACK
	-- Tracks unsaved changes and keeps active-module totals current
	---------------------------------------------------------- */

	var settingsForm = document.querySelector( '.oa-form' );
	var saveBar = document.querySelector( '.oa-save-bar' );
	var saveStateText = document.querySelector( '.oa-save-state-text' );
	var saveButton = document.querySelector( '.oa-save-button' );

	function setSaveState( state ) {

		if ( ! saveBar || ! saveStateText || ! window.oaAdmin ) {

			return;

		}

		saveBar.classList.toggle( 'has-changes', 'changed' === state );
		saveBar.classList.toggle( 'is-saving', 'saving' === state );

		if ( 'changed' === state ) {

			saveStateText.textContent = oaAdmin.unsavedText;

		} else if ( 'saving' === state ) {

			saveStateText.textContent = oaAdmin.savingText;

		} else {

			saveStateText.textContent = oaAdmin.savedText;

		}

	}

	function syncEnabledCounts() {

		// Counted per navigation entry so a grouped page adds one, not one per
		// module hidden inside it. Entries absent from this page are already
		// totalled by the server, so their count is the starting point.
		var counted = {};
		var enabledCount = window.oaAdmin && oaAdmin.enabledElsewhere ? parseInt( oaAdmin.enabledElsewhere, 10 ) : 0;

		document.querySelectorAll( '.oa-enable-toggle:checked' ).forEach( function ( toggle ) {

			var key = toggle.dataset.entry || toggle.dataset.module;

			if ( ! key || counted[ key ] ) {

				return;

			}

			counted[ key ] = true;
			enabledCount++;

		} );

		document.querySelectorAll( '.oa-enabled-count' ).forEach( function ( count ) {

			count.textContent = enabledCount;

		} );

	}

	// A save that came back with something wrong is worth landing on.
	var failureNotice = document.querySelector( '.oa-notices .notice-error, .oa-content .notice-error' );

	if ( failureNotice ) {

		failureNotice.scrollIntoView( { block: 'center' } );

	}

	if ( settingsForm ) {

		settingsForm.addEventListener( 'input', function () {

			setSaveState( 'changed' );

		} );

		settingsForm.addEventListener( 'change', function () {

			setSaveState( 'changed' );
			syncEnabledCounts();

		} );

		settingsForm.addEventListener( 'submit', function () {

			setSaveState( 'saving' );

			if ( saveButton ) {

				var spinner = document.createElement( 'span' );
				var savingLabel = document.createElement( 'span' );

				spinner.className = 'oa-button-spinner';
				spinner.setAttribute( 'aria-hidden', 'true' );
				savingLabel.className = 'screen-reader-text';
				savingLabel.textContent = oaAdmin.savingText;
				saveButton.disabled = true;
				saveButton.setAttribute( 'aria-disabled', 'true' );
				saveButton.textContent = '';
				saveButton.appendChild( spinner );
				saveButton.appendChild( savingLabel );

			}

		} );

		/*
		BLOCKED SAVE
		-- A control the browser rejects can be sitting in a closed card or an
		-- inactive tab, where the message it shows would never be seen. Each one
		-- is brought back into view, and the first is focused and named.
		---------------------------------------------------------- */

		var invalidReported = false;

		settingsForm.addEventListener( 'invalid', function ( event ) {

			revealField( event.target );

			if ( invalidReported ) {

				return;

			}

			invalidReported = true;
			oaNotify( oaAdmin.invalidFormText, 'error' );

			window.setTimeout( function () {

				event.target.focus();
				invalidReported = false;

			}, 0 );

		}, true );

	}

	/*
	REVEAL FIELD
	-- Opens whatever is hiding a control: an inactive content tab, a collapsed
	-- definition card, a disclosure, or a closed sub field.
	---------------------------------------------------------- */

	function revealField( field ) {

		var node = field;

		while ( node && node !== document.body ) {

			if ( 'DETAILS' === node.tagName && ! node.open ) {

				node.open = true;

			}

			if ( node.classList && node.classList.contains( 'oa-content-tab-panel' ) && node.classList.contains( 'oa-hidden' ) ) {

				var tab = document.querySelector( '.oa-content-tab[data-oa-tab="' + node.id + '"]' );

				if ( tab ) {

					tab.click();

				}

			}

			if ( node.classList && node.classList.contains( 'oa-hidden' ) ) {

				node.classList.remove( 'oa-hidden' );

				var card = node.parentElement;
				var opener = card ? card.querySelector( 'button[aria-expanded="false"]' ) : null;

				if ( opener ) {

					opener.setAttribute( 'aria-expanded', 'true' );

				}

			}

			node = node.parentElement;

		}

	}

	/* ---- Breakdance icon picker ---- */
	(function () {
		if (!window.oaAdmin || !oaAdmin.breakdanceActive) return;

		var modal      = null;
		var ipmSearch  = null;
		var ipmSetSel  = null;
		var ipmGrid    = null;
		var ipmMore    = null;
		var ipmLoading = null;

		var activeTarget = null;
		var setsLoaded   = false;
		var offset       = 0;
		var pageSize     = 48;
		var searchTimer  = null;

		/* Build the modal DOM once */
		function buildModal() {
			modal = document.createElement('div');
			modal.id = 'oaIconPickerModal';
			modal.setAttribute('aria-hidden', 'true');
			modal.innerHTML =
				'<div class="oa-ipm-overlay"></div>' +
				'<div class="oa-ipm-dialog" role="dialog" aria-modal="true">' +
					'<div class="oa-ipm-head">' +
						'<span class="oa-ipm-title">Choose Icon</span>' +
						'<button type="button" class="oa-ipm-close" aria-label="Close">' +
							'<svg class="oa-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg>' +
						'</button>' +
					'</div>' +
					'<div class="oa-ipm-bar">' +
						'<select class="oa-ipm-set-select"><option value="">All icon sets</option></select>' +
						'<input type="search" class="oa-ipm-search" placeholder="Search icons…">' +
					'</div>' +
					'<div class="oa-ipm-grid"></div>' +
					'<div class="oa-ipm-foot">' +
						'<span class="oa-ipm-loading oa-hidden">Loading…</span>' +
						'<button type="button" class="oa-ipm-more oa-hidden">Load more</button>' +
					'</div>' +
				'</div>';
			document.body.appendChild(modal);

			ipmSearch  = modal.querySelector('.oa-ipm-search');
			ipmSetSel  = modal.querySelector('.oa-ipm-set-select');
			ipmGrid    = modal.querySelector('.oa-ipm-grid');
			ipmMore    = modal.querySelector('.oa-ipm-more');
			ipmLoading = modal.querySelector('.oa-ipm-loading');

			enhanceSelect( ipmSetSel );

			modal.querySelector('.oa-ipm-overlay').addEventListener('click', closeModal);
			modal.querySelector('.oa-ipm-close').addEventListener('click', closeModal);

			ipmSearch.addEventListener('input', function () {
				clearTimeout(searchTimer);
				searchTimer = setTimeout(function () { offset = 0; fetchIcons(true); }, 280);
			});

			ipmSetSel.addEventListener('change', function () { offset = 0; fetchIcons(true); });
			ipmMore.addEventListener('click', function () { fetchIcons(false); });
		}

		/* Load icon sets into the dropdown (once) */
		function loadSets() {
			if (setsLoaded) return;
			setsLoaded = true;
			post({ action: 'oa_icon_sets', _ajax_nonce: oaAdmin.nonce }, function (res) {
				if (!res || !res.success) return;
				res.data.forEach(function (set) {
					var opt = document.createElement('option');
					opt.value = set.slug;
					opt.textContent = set.name;
					ipmSetSel.appendChild(opt);
				});
			});
		}

		/* Fetch icons (replace = clear grid first) */
		function fetchIcons(replace) {
			ipmLoading.classList.remove('oa-hidden');
			ipmMore.classList.add('oa-hidden');
			if (replace) ipmGrid.innerHTML = '';

			post({
				action: 'oa_icons_search',
				_ajax_nonce: oaAdmin.nonce,
				search: ipmSearch.value,
				set:    ipmSetSel.value,
				offset: offset,
			}, function (res) {
				ipmLoading.classList.add('oa-hidden');
				if (!res || !res.success) return;
				var icons = res.data;

				icons.forEach(function (icon) {
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'oa-ipm-icon-btn';
					btn.title = icon.name;
					btn.innerHTML = icon.svgCode + '<span>' + esc(icon.name) + '</span>';
					btn.addEventListener('click', function () { selectIcon(icon.svgCode); });
					ipmGrid.appendChild(btn);
				});

				offset += icons.length;
				if (icons.length === pageSize) ipmMore.classList.remove('oa-hidden');

				if (replace && icons.length === 0) {
					ipmGrid.innerHTML = '<p class="oa-ipm-empty">No icons found.</p>';
				}
			});
		}

		function selectIcon(svgCode) {
			if (!activeTarget) return;
			var input = document.getElementById(activeTarget);
			if (input) {
				input.value = svgCode;
				updatePreview(input, svgCode);
				showClearBtn(input, true);
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
			closeModal();
		}

		/* Update the preview div above the hidden input */
		function updatePreview(input, svgCode) {
			var wrap = input.parentElement;
			while (wrap && !wrap.classList.contains('oa-icon-picker-wrap')) {
				wrap = wrap.parentElement;
			}
			if (!wrap) return;
			var preview = wrap.querySelector('.oa-icon-preview');
			if (!preview) return;
			if (svgCode) {
				preview.innerHTML = svgCode;
				preview.classList.remove('is-default');
			} else {
				preview.innerHTML = '';
				preview.classList.add('is-default');
			}
		}

		/* Show or hide the "Use Default" button */
		function showClearBtn(input, show) {
			var wrap = input.parentElement;
			while (wrap && !wrap.classList.contains('oa-icon-picker-wrap')) {
				wrap = wrap.parentElement;
			}
			if (!wrap) return;
			var clearBtn = wrap.querySelector('.oa-icon-clear-btn');
			if (show && !clearBtn) {
				var pickBtn = wrap.querySelector('.oa-icon-pick-btn');
				clearBtn = document.createElement('button');
				clearBtn.type = 'button';
				clearBtn.className = 'button oa-icon-clear-btn';
				clearBtn.dataset.target = input.id;
				clearBtn.textContent = 'Use Default';
				pickBtn.parentNode.insertBefore(clearBtn, pickBtn.nextSibling);
				wireClearBtn(clearBtn);
			} else if (!show && clearBtn) {
				clearBtn.parentNode.removeChild(clearBtn);
			}
		}

		function openModal(targetId) {
			if (!modal) buildModal();
			activeTarget = targetId;
			offset = 0;
			ipmSearch.value = '';
			ipmSetSel.value = '';
			ipmSetSel.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			ipmGrid.innerHTML = '';
			modal.setAttribute('aria-hidden', 'false');
			modal.classList.add('is-open');
			loadSets();
			fetchIcons(true);
			ipmSearch.focus();
		}

		function closeModal() {
			if (!modal) return;
			modal.classList.remove('is-open');
			modal.setAttribute('aria-hidden', 'true');
			activeTarget = null;
		}

		function wireClearBtn(btn) {
			btn.addEventListener('click', function () {
				var input = document.getElementById(btn.dataset.target);
				if (!input) return;
				input.value = '';
				updatePreview(input, '');
				showClearBtn(input, false);
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			});
		}

		/* Keyboard: Escape closes */
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && modal && modal.classList.contains('is-open')) {
				closeModal();
			}
		});

		/* Wire pick buttons */
		document.querySelectorAll('.oa-icon-pick-btn').forEach(function (btn) {
			btn.addEventListener('click', function () { openModal(btn.dataset.target); });
		});

		/* Wire clear buttons already in DOM */
		document.querySelectorAll('.oa-icon-clear-btn').forEach(wireClearBtn);

		/* Tiny helpers */
		function post(data, cb) {
			var fd = new FormData();
			Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
			fetch(oaAdmin.ajaxUrl, { method: 'POST', body: fd })
				.then(function (r) { return r.json(); })
				.then(cb)
				.catch(function () { if (ipmLoading) ipmLoading.style.display = 'none'; });
		}

		function esc(str) {
			return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
		}
	}());


	/*
	CUSTOM SELECTS
	-- Keeps the native select as the submitted source of truth while providing
	-- a keyboard-friendly listbox and search for lists longer than five items.
	---------------------------------------------------------- */

	var selectIndex = 0;

	function enhanceSelect( select ) {

		if ( select.dataset.oaSelectEnhanced ) {

			return;

		}

		select.dataset.oaSelectEnhanced = 'true';
		selectIndex++;

		var wrapper = document.createElement( 'div' );
		var trigger = document.createElement( 'button' );
		var value = document.createElement( 'span' );
		var arrow = document.createElement( 'span' );
		var dropdown = document.createElement( 'div' );
		var list = document.createElement( 'div' );
		var listId = 'oa-select-list-' + selectIndex;
		var fieldLabel = select.getAttribute( 'aria-label' );
		var fieldLabelElement = select.id ? document.querySelector( 'label[for="' + select.id + '"]' ) : null;

		if ( ! fieldLabel && fieldLabelElement ) {

			fieldLabel = fieldLabelElement.textContent.trim();

		}

		wrapper.className = 'oa-custom-select' + ( select.classList.contains( 'oa-nav-select' ) ? ' oa-nav-custom-select' : '' ) + ( select.classList.contains( 'oa-ipm-set-select' ) ? ' oa-ipm-set-custom-select' : '' );
		trigger.type = 'button';
		trigger.className = 'oa-custom-select-trigger';
		trigger.setAttribute( 'aria-haspopup', 'listbox' );
		trigger.setAttribute( 'aria-expanded', 'false' );
		trigger.setAttribute( 'aria-controls', listId );

		if ( fieldLabelElement ) {

			trigger.id = select.id + '-custom';
			fieldLabelElement.htmlFor = trigger.id;

		}

		value.className = 'oa-custom-select-value';
		arrow.className = 'dashicons dashicons-arrow-down-alt2';
		arrow.setAttribute( 'aria-hidden', 'true' );
		dropdown.className = 'oa-custom-select-dropdown';
		dropdown.hidden = true;
		list.className = 'oa-custom-select-options';
		list.id = listId;
		list.setAttribute( 'role', 'listbox' );

		trigger.appendChild( value );
		trigger.appendChild( arrow );
		dropdown.appendChild( list );
		wrapper.appendChild( trigger );
		wrapper.appendChild( dropdown );
		select.classList.add( 'oa-native-select' );
		select.insertAdjacentElement( 'afterend', wrapper );

		function selectedOption() {

			return select.options[ select.selectedIndex ] || select.options[0];

		}

		function syncValue() {

			var selected = selectedOption();

			value.textContent = selected ? selected.textContent.trim() : '';
			trigger.disabled = select.disabled;

			if ( fieldLabel ) {

				trigger.setAttribute( 'aria-label', fieldLabel + ': ' + value.textContent );

			}

			list.querySelectorAll( '[role="option"]' ).forEach( function ( optionButton ) {

				var isSelected = optionButton.dataset.value === select.value;

				optionButton.classList.toggle( 'is-selected', isSelected );
				optionButton.setAttribute( 'aria-selected', isSelected ? 'true' : 'false' );

			} );

		}

		function closeSelect( restoreFocus ) {

			dropdown.hidden = true;
			trigger.setAttribute( 'aria-expanded', 'false' );
			wrapper.classList.remove( 'is-open' );

			if ( restoreFocus ) {

				trigger.focus();

			}

		}

		function focusFirstOption() {

			var selected = list.querySelector( '.is-selected:not([hidden])' );
			var first = list.querySelector( '[role="option"]:not([hidden])' );

			( selected || first || trigger ).focus();

		}

		function openSelect() {

			document.querySelectorAll( '.oa-custom-select.is-open' ).forEach( function ( openWrapper ) {

				if ( openWrapper !== wrapper ) {

					openWrapper.querySelector( '.oa-custom-select-trigger' ).click();

				}

			} );

			dropdown.hidden = false;
			trigger.setAttribute( 'aria-expanded', 'true' );
			wrapper.classList.add( 'is-open' );

			var search = dropdown.querySelector( '.oa-custom-select-search' );

			if ( search ) {

				search.value = '';
				search.dispatchEvent( new Event( 'input' ) );
				search.focus();

			} else {

				focusFirstOption();

			}

		}

		function chooseOption( option ) {

			select.value = option.value;
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			syncValue();
			closeSelect( true );

		}

		function buildOptions() {

			list.innerHTML = '';

			var oldSearch = dropdown.querySelector( '.oa-custom-select-search-wrap' );

			if ( oldSearch ) {

				oldSearch.remove();

			}

			var options = Array.prototype.slice.call( select.options );

			if ( options.length > 5 ) {

				var searchWrap = document.createElement( 'div' );
				var search = document.createElement( 'input' );

				searchWrap.className = 'oa-custom-select-search-wrap';
				search.type = 'search';
				search.className = 'oa-custom-select-search';
				search.placeholder = oaAdmin.searchOptionsText;
				search.setAttribute( 'aria-label', oaAdmin.searchOptionsText );
				searchWrap.appendChild( search );
				dropdown.insertBefore( searchWrap, list );

				search.addEventListener( 'input', function () {

					var query = search.value.trim().toLowerCase();

					list.querySelectorAll( '[role="option"]' ).forEach( function ( optionButton ) {

						optionButton.hidden = -1 === optionButton.textContent.toLowerCase().indexOf( query );

					} );

				} );

			}

			options.forEach( function ( option ) {

				var optionButton = document.createElement( 'button' );

				optionButton.type = 'button';
				optionButton.className = 'oa-custom-select-option';
				optionButton.dataset.value = option.value;
				optionButton.textContent = option.textContent.trim();
				optionButton.disabled = option.disabled;
				optionButton.setAttribute( 'role', 'option' );
				optionButton.addEventListener( 'click', function () {

					chooseOption( option );

				} );
				list.appendChild( optionButton );

			} );

			syncValue();

		}

		trigger.addEventListener( 'click', function () {

			if ( dropdown.hidden ) {

				openSelect();

			} else {

				closeSelect( false );

			}

		} );

		trigger.addEventListener( 'keydown', function ( event ) {

			if ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) {

				event.preventDefault();
				openSelect();

			}

		} );

		dropdown.addEventListener( 'keydown', function ( event ) {

			if ( 'Escape' === event.key ) {

				event.preventDefault();
				closeSelect( true );
				return;

			}

			if ( 'ArrowDown' !== event.key && 'ArrowUp' !== event.key ) {

				return;

			}

			var visibleOptions = Array.prototype.slice.call( list.querySelectorAll( '[role="option"]:not([hidden]):not(:disabled)' ) );
			var currentIndex = visibleOptions.indexOf( document.activeElement );
			var direction = 'ArrowDown' === event.key ? 1 : -1;
			var nextIndex = Math.max( 0, Math.min( visibleOptions.length - 1, currentIndex + direction ) );

			if ( visibleOptions[ nextIndex ] ) {

				event.preventDefault();
				visibleOptions[ nextIndex ].focus();

			}

		} );

		document.addEventListener( 'click', function ( event ) {

			if ( ! wrapper.contains( event.target ) ) {

				closeSelect( false );

			}

		} );

		select.addEventListener( 'change', syncValue );
		select.addEventListener( 'input', syncValue );

		new MutationObserver( buildOptions ).observe( select, { childList: true, subtree: true } );
		buildOptions();

	}

	document.querySelectorAll( '.oa-app select' ).forEach( enhanceSelect );

	new MutationObserver( function ( mutations ) {

		mutations.forEach( function ( mutation ) {

			mutation.addedNodes.forEach( function ( node ) {

				if ( Node.ELEMENT_NODE !== node.nodeType ) {

					return;

				}

				if ( node.matches( 'select' ) ) {

					enhanceSelect( node );

				}

				node.querySelectorAll( 'select' ).forEach( enhanceSelect );

			} );

		} );

	} ).observe( document.querySelector( '.oa-app' ), { childList: true, subtree: true } );

	/*
	MEDIA IMAGE FIELDS
	-- Uses the WordPress Media Library and maintains the existing URL setting.
	---------------------------------------------------------- */

	document.querySelectorAll( '.oa-media-field' ).forEach( function ( field ) {

		var input = field.querySelector( '.oa-media-url' );
		var image = field.querySelector( '.oa-media-preview img' );
		var placeholder = field.querySelector( '.oa-media-placeholder' );
		var selectButton = field.querySelector( '.oa-media-select' );
		var removeButton = field.querySelector( '.oa-media-remove' );
		var frame;

		function syncMediaField( url ) {

			input.value = url;
			field.classList.toggle( 'has-image', Boolean( url ) );
			image.hidden = ! url;
			placeholder.hidden = Boolean( url );
			removeButton.hidden = ! url;
			selectButton.textContent = url ? oaAdmin.replaceImageText : oaAdmin.selectImageText;

			if ( url ) {

				image.src = url;

			} else {

				image.removeAttribute( 'src' );

			}

			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		}

		selectButton.addEventListener( 'click', function () {

			if ( frame ) {

				frame.open();
				return;

			}

			frame = wp.media( {
				title: oaAdmin.selectImageTitle,
				button: { text: oaAdmin.useImageText },
				library: { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {

				var attachment = frame.state().get( 'selection' ).first().toJSON();

				syncMediaField( attachment.url );

			} );

			frame.open();

		} );

		removeButton.addEventListener( 'click', function () {

			syncMediaField( '' );
			selectButton.focus();

		} );

	} );

	/*
	CUSTOM COLOUR PICKERS
	-- Provides direct saturation, brightness, hue and validated hex controls.
	---------------------------------------------------------- */

	function hexToHsv( hex ) {

		var value = hex.replace( '#', '' );
		var red = parseInt( value.substring( 0, 2 ), 16 ) / 255;
		var green = parseInt( value.substring( 2, 4 ), 16 ) / 255;
		var blue = parseInt( value.substring( 4, 6 ), 16 ) / 255;
		var max = Math.max( red, green, blue );
		var min = Math.min( red, green, blue );
		var delta = max - min;
		var hue = 0;

		if ( delta ) {

			if ( max === red ) {

				hue = 60 * ( ( ( green - blue ) / delta ) % 6 );

			} else if ( max === green ) {

				hue = 60 * ( ( blue - red ) / delta + 2 );

			} else {

				hue = 60 * ( ( red - green ) / delta + 4 );

			}

		}

		return {
			h: Math.round( hue < 0 ? hue + 360 : hue ),
			s: max ? delta / max : 0,
			v: max
		};

	}

	function hsvToHex( hue, saturation, brightness ) {

		var chroma = brightness * saturation;
		var section = hue / 60;
		var x = chroma * ( 1 - Math.abs( section % 2 - 1 ) );
		var rgb = [ [ chroma, x, 0 ], [ x, chroma, 0 ], [ 0, chroma, x ], [ 0, x, chroma ], [ x, 0, chroma ], [ chroma, 0, x ] ][ Math.floor( section ) % 6 ];
		var match = brightness - chroma;

		return '#' + rgb.map( function ( channel ) {

			return Math.round( ( channel + match ) * 255 ).toString( 16 ).padStart( 2, '0' );

		} ).join( '' ).toUpperCase();

	}

	document.querySelectorAll( '.oa-color-picker-wrap' ).forEach( function ( wrap ) {

		var input = wrap.querySelector( '.oa-color-input' );
		var trigger = wrap.querySelector( '.oa-color-trigger' );
		var swatch = wrap.querySelector( '.oa-color-swatch' );
		var display = wrap.querySelector( '.oa-color-value' );
		var popover = wrap.querySelector( '.oa-color-popover' );
		var saturation = wrap.querySelector( '.oa-color-saturation' );
		var thumb = wrap.querySelector( '.oa-color-thumb' );
		var hueInput = wrap.querySelector( '.oa-color-hue' );
		var hexInput = wrap.querySelector( '.oa-color-hex' );
		var hsv = hexToHsv( input.value );
		var dragging = false;

		function renderColour( emitChange ) {

			var hex = hsvToHex( hsv.h, hsv.s, hsv.v );

			input.value = hex;
			display.textContent = hex;
			hexInput.value = hex;
			swatch.style.backgroundColor = hex;
			saturation.style.backgroundColor = 'hsl(' + hsv.h + ', 100%, 50%)';
			thumb.style.left = ( hsv.s * 100 ) + '%';
			thumb.style.top = ( ( 1 - hsv.v ) * 100 ) + '%';
			hueInput.value = Math.round( hsv.h );
			saturation.setAttribute( 'aria-valuetext', hex );

			if ( emitChange ) {

				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );

			}

		}

		function updateSaturation( event ) {

			var rect = saturation.getBoundingClientRect();

			hsv.s = Math.max( 0, Math.min( 1, ( event.clientX - rect.left ) / rect.width ) );
			hsv.v = 1 - Math.max( 0, Math.min( 1, ( event.clientY - rect.top ) / rect.height ) );
			renderColour( true );

		}

		trigger.addEventListener( 'click', function () {

			var willOpen = popover.hidden;

			document.querySelectorAll( '.oa-color-popover:not([hidden])' ).forEach( function ( openPopover ) {

				openPopover.hidden = true;
				openPopover.previousElementSibling.setAttribute( 'aria-expanded', 'false' );

			} );

			popover.hidden = ! willOpen;
			trigger.setAttribute( 'aria-expanded', willOpen ? 'true' : 'false' );

			if ( willOpen ) {

				saturation.focus();

			}

		} );

		saturation.addEventListener( 'pointerdown', function ( event ) {

			dragging = true;
			saturation.setPointerCapture( event.pointerId );
			updateSaturation( event );

		} );

		saturation.addEventListener( 'pointermove', function ( event ) {

			if ( dragging ) {

				updateSaturation( event );

			}

		} );

		saturation.addEventListener( 'pointerup', function () {

			dragging = false;

		} );

		saturation.addEventListener( 'pointercancel', function () {

			dragging = false;

		} );

		saturation.addEventListener( 'keydown', function ( event ) {

			var handled = true;

			if ( 'ArrowLeft' === event.key ) {

				hsv.s -= 0.01;

			} else if ( 'ArrowRight' === event.key ) {

				hsv.s += 0.01;

			} else if ( 'ArrowUp' === event.key ) {

				hsv.v += 0.01;

			} else if ( 'ArrowDown' === event.key ) {

				hsv.v -= 0.01;

			} else {

				handled = false;

			}

			if ( handled ) {

				event.preventDefault();
				hsv.s = Math.max( 0, Math.min( 1, hsv.s ) );
				hsv.v = Math.max( 0, Math.min( 1, hsv.v ) );
				renderColour( true );

			}

		} );

		hueInput.addEventListener( 'input', function () {

			hsv.h = Number( hueInput.value );
			renderColour( true );

		} );

		hexInput.addEventListener( 'input', function () {

			var candidate = hexInput.value.trim();

			if ( /^#?[0-9a-f]{6}$/i.test( candidate ) ) {

				hsv = hexToHsv( '#' + candidate.replace( '#', '' ) );
				renderColour( true );

			}

		} );

		hexInput.addEventListener( 'blur', function () {

			hexInput.value = input.value;

		} );

		popover.addEventListener( 'keydown', function ( event ) {

			if ( 'Escape' === event.key ) {

				popover.hidden = true;
				trigger.setAttribute( 'aria-expanded', 'false' );
				trigger.focus();

			}

		} );

		document.addEventListener( 'click', function ( event ) {

			if ( ! wrap.contains( event.target ) ) {

				popover.hidden = true;
				trigger.setAttribute( 'aria-expanded', 'false' );

			}

		} );

		renderColour( false );

	} );

	/*
	CONFIRMATION DIALOG
	-- Shared Promise-based replacement for browser confirm dialogs throughout
	-- the plugin admin. Supports safe and destructive confirmation variants.
	---------------------------------------------------------- */

	window.oaConfirm = function ( options ) {

		options = Object.assign( {
			title: oaAdmin.confirmTitleText,
			message: '',
			confirmText: oaAdmin.confirmActionText,
			cancelText: oaAdmin.cancelActionText,
			destructive: false
		}, options || {} );

		return new Promise( function ( resolve ) {

			var activeElement = document.activeElement;
			var modal = document.createElement( 'div' );
			var overlay = document.createElement( 'div' );
			var dialog = document.createElement( 'div' );
			var heading = document.createElement( 'h2' );
			var message = document.createElement( 'p' );
			var actions = document.createElement( 'div' );
			var cancelButton = document.createElement( 'button' );
			var confirmButton = document.createElement( 'button' );
			var titleId = 'oa-confirm-title-' + Date.now();
			var messageId = 'oa-confirm-message-' + Date.now();
			var isClosed = false;

			modal.className = 'oa-confirm-modal';
			overlay.className = 'oa-confirm-overlay';
			dialog.className = 'oa-confirm-dialog';
			dialog.setAttribute( 'role', 'alertdialog' );
			dialog.setAttribute( 'aria-modal', 'true' );
			dialog.setAttribute( 'aria-labelledby', titleId );
			dialog.setAttribute( 'aria-describedby', messageId );
			heading.id = titleId;
			heading.className = 'oa-confirm-title';
			heading.textContent = options.title;
			message.id = messageId;
			message.className = 'oa-confirm-message';
			message.textContent = options.message;
			actions.className = 'oa-confirm-actions';
			cancelButton.type = 'button';
			cancelButton.className = 'oa-confirm-button oa-confirm-cancel';
			cancelButton.textContent = options.cancelText;
			confirmButton.type = 'button';
			confirmButton.className = 'oa-confirm-button oa-confirm-submit' + ( options.destructive ? ' is-destructive' : '' );
			confirmButton.textContent = options.confirmText;

			actions.appendChild( cancelButton );
			actions.appendChild( confirmButton );
			dialog.appendChild( heading );
			dialog.appendChild( message );
			dialog.appendChild( actions );
			modal.appendChild( overlay );
			modal.appendChild( dialog );
			document.body.appendChild( modal );
			document.body.classList.add( 'oa-confirm-open' );

			function closeDialog( confirmed ) {

				if ( isClosed ) {

					return;

				}

				isClosed = true;
				document.removeEventListener( 'keydown', handleKeydown );
				document.body.classList.remove( 'oa-confirm-open' );
				modal.remove();

				if ( activeElement && document.contains( activeElement ) ) {

					activeElement.focus();

				}

				resolve( confirmed );

			}

			function handleKeydown( event ) {

				if ( 'Escape' === event.key ) {

					event.preventDefault();
					closeDialog( false );
					return;

				}

				if ( 'Tab' !== event.key ) {

					return;

				}

				var focusable = [ cancelButton, confirmButton ];
				var currentIndex = focusable.indexOf( document.activeElement );
				var nextIndex = event.shiftKey ? currentIndex - 1 : currentIndex + 1;

				if ( nextIndex < 0 ) {

					nextIndex = focusable.length - 1;

				} else if ( nextIndex >= focusable.length ) {

					nextIndex = 0;

				}

				event.preventDefault();
				focusable[ nextIndex ].focus();

			}

			overlay.addEventListener( 'click', function () {

				closeDialog( false );

			} );

			cancelButton.addEventListener( 'click', function () {

				closeDialog( false );

			} );

			confirmButton.addEventListener( 'click', function () {

				closeDialog( true );

			} );

			document.addEventListener( 'keydown', handleKeydown );
			cancelButton.focus();

		} );

	};

	/*
	ACTION FEEDBACK
	-- Confirms what an action did, in a message screen readers also receive.
	-- Structure changes are only pending until the settings are saved, so the
	-- wording says so rather than implying the change is already stored.
	---------------------------------------------------------- */

	var toastRegion = null;

	function oaNotify( message, type ) {

		if ( ! message ) {

			return;

		}

		if ( ! toastRegion ) {

			toastRegion = document.createElement( 'div' );
			toastRegion.className = 'oa-toasts';
			document.body.appendChild( toastRegion );

		}

		var toast = document.createElement( 'div' );
		var isError = 'error' === type;

		toast.className = 'oa-toast is-' + ( type || 'info' );
		toast.setAttribute( 'role', isError ? 'alert' : 'status' );
		toast.textContent = message;
		toastRegion.appendChild( toast );

		window.requestAnimationFrame( function () {

			toast.classList.add( 'is-visible' );

		} );

		window.setTimeout( function () {

			toast.classList.remove( 'is-visible' );

			window.setTimeout( function () {

				toast.remove();

			}, 250 );

		}, isError ? 6000 : 4000 );

	}

	window.oaNotify = oaNotify;

	/*
	SAVE REDIRECT
	-- WordPress returns to the referer the form carries once the settings are
	-- stored, so pointing that at another screen sends the save there instead.
	---------------------------------------------------------- */

	function retargetSaveRedirect( url ) {

		var referer = document.querySelector( '.oa-form [name="_wp_http_referer"]' );

		if ( ! referer || ! url ) {

			return;

		}

		referer.value = url;

	}

	/*
	KEY UNLOCK
	-- A definition key is read-only once it has been saved. The edit button
	-- reopens it after a confirmation that spells out what a rename costs, and
	-- a focused editor addressed by the old key follows the new one on save.
	---------------------------------------------------------- */

	function retargetRenamedDefinition( originalKey, newKey ) {

		var url = new URL( window.location.href );

		if ( '' === originalKey || '' === newKey || url.searchParams.get( 'definition' ) !== originalKey ) {

			return;

		}

		url.searchParams.set( 'definition', newKey );
		url.searchParams.delete( 'settings-updated' );
		retargetSaveRedirect( url.toString() );

	}

	document.querySelectorAll( '.oa-key-field' ).forEach( function ( field ) {

		var button = field.querySelector( '.oa-key-edit' );
		var input = field.querySelector( 'input[type="text"]' );
		var original = field.querySelector( '[data-role="original-key"]' );

		if ( ! button || ! input || ! original ) {

			return;

		}

		button.addEventListener( 'click', function () {

			if ( ! input.readOnly ) {

				input.focus();
				input.select();

				return;

			}

			window.oaConfirm( {
				title: oaAdmin.renameKeyTitle,
				message: button.dataset.warning,
				confirmText: oaAdmin.renameKeyAction
			} ).then( function ( confirmed ) {

				if ( ! confirmed ) {

					return;

				}

				input.readOnly = false;
				field.classList.add( 'is-unlocked' );
				input.focus();
				input.select();

			} );

		} );

		input.addEventListener( 'input', function () {

			retargetRenamedDefinition( original.value, input.value );

		} );

	} );

	/*
	CONDITIONAL FIELDS
	-- Shows or hides a dependent field. The required flag is lifted while the
	-- field is out of view, because the browser refuses to submit a form held up
	-- by a control nobody can see or reach.
	---------------------------------------------------------- */

	function setFieldVisibility( container, isVisible ) {

		if ( ! container ) {

			return;

		}

		container.classList.toggle( 'oa-hidden', ! isVisible );

		container.querySelectorAll( 'input, select, textarea' ).forEach( function ( field ) {

			if ( ! isVisible && field.required ) {

				field.dataset.oaRequired = 'true';
				field.required = false;

				return;

			}

			if ( isVisible && 'true' === field.dataset.oaRequired ) {

				field.required = true;
				delete field.dataset.oaRequired;

			}

		} );

	}

	/*
	CONTENT TYPE TABS
	-- Switches between the Post Types, Taxonomies and Fields views.
	-- The open tab is restored from the URL hash, then from the last visit so it
	-- survives the redirect WordPress performs after saving.
	---------------------------------------------------------- */

	document.querySelectorAll( '[data-oa-tabs]' ).forEach( function ( tabs ) {

		var buttons = Array.prototype.slice.call( tabs.querySelectorAll( '.oa-content-tab' ) );
		var storageKey = 'oaContentTab';

		if ( ! buttons.length ) {

			return;

		}

		function readStoredPanel() {

			try {

				return window.sessionStorage.getItem( storageKey );

			} catch ( error ) {

				return null;

			}

		}

		function storePanel( panelId ) {

			try {

				window.sessionStorage.setItem( storageKey, panelId );

			} catch ( error ) {

				return;

			}

		}

		function activate( panelId, focusTab ) {

			buttons.forEach( function ( button ) {

				var isActive = button.dataset.oaTab === panelId;
				var panel = document.getElementById( button.dataset.oaTab );

				button.classList.toggle( 'is-active', isActive );
				button.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				button.tabIndex = isActive ? 0 : -1;

				if ( panel ) {

					panel.classList.toggle( 'oa-hidden', ! isActive );

				}

				if ( isActive && focusTab ) {

					button.focus();

				}

			} );

			storePanel( panelId );

		}

		buttons.forEach( function ( button, index ) {

			button.addEventListener( 'click', function () {

				activate( button.dataset.oaTab, false );

			} );

			button.addEventListener( 'keydown', function ( event ) {

				var offset = 0;

				if ( 'ArrowRight' === event.key || 'ArrowDown' === event.key ) {

					offset = 1;

				}

				if ( 'ArrowLeft' === event.key || 'ArrowUp' === event.key ) {

					offset = -1;

				}

				if ( 0 === offset ) {

					return;

				}

				event.preventDefault();

				var next = ( index + offset + buttons.length ) % buttons.length;

				activate( buttons[ next ].dataset.oaTab, true );

			} );

		} );

		function panelFromHash() {

			var hash = window.location.hash.replace( '#', '' );

			return buttons.some( function ( button ) {

				return button.dataset.oaTab === hash;

			} ) ? hash : '';

		}

		var initial = panelFromHash() || readStoredPanel();
		var isKnown = buttons.some( function ( button ) {

			return button.dataset.oaTab === initial;

		} );

		activate( isKnown ? initial : buttons[ 0 ].dataset.oaTab, false );

		window.addEventListener( 'hashchange', function () {

			var target = panelFromHash();

			if ( target ) {

				activate( target, false );

			}

		} );

	} );

	/*
	CONTENT DIRECTORY FILTER
	-- Narrows a tab listing to the rows matching the search term, hiding any
	-- group left with nothing to show.
	---------------------------------------------------------- */

	document.querySelectorAll( '[data-oa-directory]' ).forEach( function ( directory ) {

		var search = directory.querySelector( '[data-oa-directory-search]' );
		var groups = Array.prototype.slice.call( directory.querySelectorAll( '[data-oa-directory-group]' ) );
		var noResults = directory.querySelector( '[data-oa-directory-empty]' );

		if ( ! search || ! noResults ) {

			return;

		}

		function applyFilter() {

			var term = search.value.trim().toLowerCase();
			var matches = 0;

			groups.forEach( function ( group ) {

				var rows = Array.prototype.slice.call( group.querySelectorAll( '[data-oa-directory-row]' ) );
				var visible = 0;

				rows.forEach( function ( row ) {

					var isMatch = '' === term || -1 !== row.dataset.search.indexOf( term );

					row.classList.toggle( 'oa-hidden', ! isMatch );

					if ( isMatch ) {

						visible += 1;

					}

				} );

				matches += visible;

				// An empty group still belongs on screen when nothing is being searched for.
				group.classList.toggle( 'oa-hidden', '' !== term && 0 === visible );

			} );

			noResults.classList.toggle( 'oa-hidden', '' === term || 0 !== matches );

		}

		search.addEventListener( 'input', applyFilter );

		// The listing sits inside the settings form, so Enter must not submit it.
		search.addEventListener( 'keydown', function ( event ) {

			if ( 'Enter' === event.key ) {

				event.preventDefault();

			}

		} );

	} );

	/*
	CUSTOM POST TYPE EDITOR
	-- Adds, removes and accessibly reorders the repeatable post type cards.
	---------------------------------------------------------- */

	document.querySelectorAll( '.oa-cpt-section:not(.oa-collection)' ).forEach( function ( section ) {

		var list = section.querySelector( '.oa-cpt-list' );
		var template = section.querySelector( '.oa-cpt-template' );
		var addButton = section.querySelector( '.oa-cpt-add' );
		var orderStatus = section.querySelector( '.oa-cpt-order-status' );
		var nextIndex = list.children.length;
		var draggedItem = null;

		// Trailing separators are kept while typing so a separator keypress is not swallowed before the next character arrives.
		function slugify( value, separator, keepTrailing ) {

			var slug = value.toLowerCase()
				.replace( /[^a-z0-9]+/g, separator )
				.replace( new RegExp( '^' + separator + '+' ), '' );

			if ( ! keepTrailing ) {

				slug = slug.replace( new RegExp( separator + '+$' ), '' );

			}

			return slug;

		}

		function reindexItems() {

			var items = list.querySelectorAll( '.oa-cpt-item' );

			items.forEach( function ( item, index ) {

				item.querySelectorAll( '[name]' ).forEach( function ( field ) {

					field.name = field.name.replace( /\[custom_post_types\]\[[^\]]+\]/, '[custom_post_types][' + index + ']' );

				} );

				var moveUpButton = item.querySelector( '.oa-cpt-move-up' );
				var moveDownButton = item.querySelector( '.oa-cpt-move-down' );

				if ( moveUpButton ) {

					moveUpButton.disabled = 0 === index;

				}

				if ( moveDownButton ) {

					moveDownButton.disabled = items.length - 1 === index;

				}

			} );

		}

		function announceOrderChange() {

			orderStatus.textContent = '';
			window.requestAnimationFrame( function () {

				orderStatus.textContent = oaAdmin.postTypeMovedText;

			} );
			list.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		}

		function syncConditionalFields( item ) {

			var queryableToggle = item.querySelector( '.oa-cpt-queryable-toggle' );
			var urls = item.querySelector( '.oa-cpt-urls' );
			var archiveToggle = item.querySelector( '.oa-cpt-archive-toggle' );

			setFieldVisibility( urls, queryableToggle.checked );

			item.querySelectorAll( '.oa-cpt-archive-field' ).forEach( function ( field ) {

				setFieldVisibility( field, archiveToggle.checked );

			} );

		}

		function wireItem( item ) {

			var handle = item.querySelector( '.oa-cpt-drag-handle' );
			var moveUpButton = item.querySelector( '.oa-cpt-move-up' );
			var moveDownButton = item.querySelector( '.oa-cpt-move-down' );
			var expandButton = item.querySelector( '.oa-cpt-expand' );
			var groups = item.querySelector( '.oa-cpt-groups' );
			var enabledToggle = item.querySelector( '.oa-cpt-enabled-toggle' );
			var removeButton = item.querySelector( '.oa-cpt-remove' );
			var queryableToggle = item.querySelector( '.oa-cpt-queryable-toggle' );
			var archiveToggle = item.querySelector( '.oa-cpt-archive-toggle' );
			var iconValue = item.querySelector( '.oa-cpt-icon-value' );
			var iconToggle = item.querySelector( '.oa-cpt-icon-toggle' );
			var iconOptions = item.querySelector( '.oa-cpt-icon-options' );
			var iconSearch = item.querySelector( '.oa-cpt-icon-search input' );
			var iconEmpty = item.querySelector( '.oa-cpt-icon-empty' );
			var iconButtons = iconOptions.querySelectorAll( '.oa-cpt-icon-option' );
			var iconLabel = item.querySelector( '.oa-cpt-icon-selection strong' );
			var iconCode = item.querySelector( '.oa-cpt-icon-selection code' );
			var nameInput = item.querySelector( '[data-cpt-field="name"]' );
			var singularInput = item.querySelector( '[data-cpt-field="singular_name"]' );
			var keyInput = item.querySelector( '[data-cpt-field="post_type"]' );
			var postSlugInput = item.querySelector( '[data-cpt-field="post_slug"]' );
			var archiveSlugInput = item.querySelector( '[data-cpt-field="archive_slug"]' );
			var title = item.querySelector( '.oa-cpt-item-title' );
			var keyPreview = item.querySelector( '.oa-cpt-key-preview' );
			var isNew = 'false' === item.dataset.saved;
			var keyIsAutomatic = isNew;

			function setIcon( icon, label ) {

				iconValue.value = icon;
				iconLabel.textContent = label;
				iconCode.textContent = icon;

				item.querySelectorAll( '.oa-cpt-live-icon' ).forEach( function ( preview ) {

					Array.from( preview.classList ).forEach( function ( className ) {

						if ( 0 === className.indexOf( 'dashicons-' ) ) {

							preview.classList.remove( className );

						}

					} );

					preview.classList.add( icon );

				} );

				iconOptions.querySelectorAll( '.oa-cpt-icon-option' ).forEach( function ( option ) {

					var selected = option.dataset.icon === icon;

					option.classList.toggle( 'is-selected', selected );
					option.setAttribute( 'aria-selected', selected ? 'true' : 'false' );

				} );

				iconValue.dispatchEvent( new Event( 'input', { bubbles: true } ) );

			}

			function syncEnabledState() {

				var isEnabled = enabledToggle.checked;

				item.classList.toggle( 'is-disabled', ! isEnabled );
				expandButton.disabled = ! isEnabled;

				if ( ! isEnabled ) {

					groups.classList.add( 'oa-hidden' );
					expandButton.setAttribute( 'aria-expanded', 'false' );

				}

			}

			expandButton.addEventListener( 'click', function () {

				var willOpen = groups.classList.contains( 'oa-hidden' );

				groups.classList.toggle( 'oa-hidden', ! willOpen );
				expandButton.setAttribute( 'aria-expanded', willOpen ? 'true' : 'false' );

			} );

			enabledToggle.addEventListener( 'change', syncEnabledState );

			queryableToggle.addEventListener( 'change', function () {

				syncConditionalFields( item );

			} );

			archiveToggle.addEventListener( 'change', function () {

				syncConditionalFields( item );

			} );

			iconToggle.addEventListener( 'click', function () {

				var opening = iconOptions.classList.contains( 'oa-hidden' );

				iconOptions.classList.toggle( 'oa-hidden', ! opening );
				iconToggle.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );

				if ( opening ) {

					iconSearch.focus();

				}

			} );

			iconSearch.addEventListener( 'input', function () {

				var query = iconSearch.value.trim().toLowerCase();
				var visibleCount = 0;

				iconButtons.forEach( function ( option ) {

					var searchText = ( option.dataset.label + ' ' + option.dataset.icon ).toLowerCase();
					var visible = '' === query || -1 !== searchText.indexOf( query );

					option.classList.toggle( 'oa-hidden', ! visible );

					if ( visible ) {

						visibleCount += 1;

					}

				} );

				iconEmpty.classList.toggle( 'oa-hidden', visibleCount > 0 );

			} );

			iconButtons.forEach( function ( option ) {

				option.addEventListener( 'click', function () {

					setIcon( option.dataset.icon, option.dataset.label );
					iconOptions.classList.add( 'oa-hidden' );
					iconToggle.setAttribute( 'aria-expanded', 'false' );
					iconSearch.value = '';
					iconSearch.dispatchEvent( new Event( 'input' ) );
					iconToggle.focus();

				} );

			} );

			nameInput.addEventListener( 'input', function () {

				var pluralSlug = slugify( nameInput.value, '-' );

				title.textContent = nameInput.value.trim() || oaAdmin.newPostTypeText;

				if ( isNew && keyIsAutomatic ) {

					keyInput.value = ( 'oa_' + slugify( nameInput.value, '_' ) ).substring( 0, 20 );
					keyPreview.textContent = keyInput.value;

				}

				if ( isNew && ! archiveSlugInput.dataset.edited ) {

					archiveSlugInput.value = pluralSlug;

				}

			} );

			singularInput.addEventListener( 'input', function () {

				var singularSlug = slugify( singularInput.value, '-' );

				if ( isNew && ! postSlugInput.dataset.edited ) {

					postSlugInput.value = singularSlug;

				}

			} );

			keyInput.addEventListener( 'input', function ( event ) {

				if ( event.isTrusted ) {

					keyIsAutomatic = false;

				}

				keyInput.value = slugify( keyInput.value, '_', true ).substring( 0, 20 );
				keyPreview.textContent = keyInput.value;

			} );

			keyInput.addEventListener( 'blur', function () {

				keyInput.value = slugify( keyInput.value, '_' ).substring( 0, 20 );
				keyPreview.textContent = keyInput.value;

			} );

			[ postSlugInput, archiveSlugInput ].forEach( function ( input ) {

				input.addEventListener( 'input', function ( event ) {

					if ( event.isTrusted ) {

						input.dataset.edited = 'true';

					}

				} );

			} );

			function removeItem() {

				item.remove();
				reindexItems();
				announceOrderChange();
				addButton.focus();
				oaNotify( oaAdmin.postTypeRemovedText, 'success' );

			}

			removeButton.addEventListener( 'click', function () {

				if ( 'true' !== item.dataset.saved ) {

					removeItem();
					return;

				}

				window.oaConfirm( {
					title: oaAdmin.removePostTypeTitle,
					message: oaAdmin.removePostTypeText,
					confirmText: oaAdmin.removeActionText,
					destructive: true
				} ).then( function ( confirmed ) {

					if ( confirmed ) {

						removeItem();

					}

				} );

			} );

			handle.addEventListener( 'pointerdown', function () {

				item.draggable = true;

			} );

			handle.addEventListener( 'pointerup', function () {

				item.draggable = false;

			} );

			handle.addEventListener( 'keydown', function ( event ) {

				var sibling;

				if ( 'ArrowUp' === event.key ) {

					sibling = item.previousElementSibling;

					if ( sibling ) {

						list.insertBefore( item, sibling );

					}

				} else if ( 'ArrowDown' === event.key ) {

					sibling = item.nextElementSibling;

					if ( sibling ) {

						list.insertBefore( sibling, item );

					}

				} else {

					return;

				}

				event.preventDefault();
				reindexItems();
				announceOrderChange();
				handle.focus();

			} );

			if ( moveUpButton ) {

				moveUpButton.addEventListener( 'click', function () {

					var previous = item.previousElementSibling;

					if ( previous ) {

						list.insertBefore( item, previous );
						reindexItems();
						announceOrderChange();
						moveUpButton.focus();

					}

				} );

			}

			if ( moveDownButton ) {

				moveDownButton.addEventListener( 'click', function () {

					var next = item.nextElementSibling;

					if ( next ) {

						list.insertBefore( next, item );
						reindexItems();
						announceOrderChange();
						moveDownButton.focus();

					}

				} );

			}

			item.addEventListener( 'dragstart', function ( event ) {

				draggedItem = item;
				item.classList.add( 'is-dragging' );
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData( 'text/plain', '' );

			} );

			item.addEventListener( 'dragend', function () {

				item.classList.remove( 'is-dragging' );
				item.draggable = false;
				draggedItem = null;
				reindexItems();
				announceOrderChange();

			} );

			syncConditionalFields( item );
			syncEnabledState();

		}

		list.addEventListener( 'dragover', function ( event ) {

			if ( ! draggedItem ) {

				return;

			}

			event.preventDefault();

			var target = Array.prototype.find.call( list.querySelectorAll( '.oa-cpt-item:not(.is-dragging)' ), function ( item ) {

				var box = item.getBoundingClientRect();

				return event.clientY < box.top + box.height / 2;

			} );

			list.insertBefore( draggedItem, target || null );

		} );

		addButton.addEventListener( 'click', function () {

			var html = template.innerHTML.split( '__INDEX__' ).join( 'new_' + nextIndex );
			var holder = document.createElement( 'div' );

			nextIndex++;
			holder.innerHTML = html.trim();

			var item = holder.firstElementChild;
			var keyInput = item.querySelector( '[data-cpt-field="post_type"]' );

			keyInput.value = ( 'oa_content_' + nextIndex ).substring( 0, 20 );
			item.querySelector( '.oa-cpt-key-preview' ).textContent = keyInput.value;
			list.appendChild( item );
			wireItem( item );
			item.querySelector( '.oa-cpt-groups' ).classList.remove( 'oa-hidden' );
			item.querySelector( '.oa-cpt-expand' ).setAttribute( 'aria-expanded', 'true' );
			reindexItems();
			announceOrderChange();
			item.querySelector( '[data-cpt-field="name"]' ).focus();
			oaNotify( oaAdmin.postTypeAddedText, 'success' );

		} );

		list.querySelectorAll( '.oa-cpt-item' ).forEach( wireItem );
		reindexItems();

	} );

	/*
	SCHEMA ASSIGNMENTS
	-- Reflects reusable definition assignments without opening their editors.
	---------------------------------------------------------- */

	document.querySelectorAll( '.oa-assignment-card' ).forEach( function ( card ) {

		var input = card.querySelector( '.oa-assignment-toggle input' );
		var label = card.querySelector( '.oa-assignment-toggle > span:first-child' );

		function syncAssignment() {

			card.classList.toggle( 'is-assigned', input.checked );
			label.textContent = input.checked ? card.dataset.assignedLabel : card.dataset.unassignedLabel;

		}

		input.addEventListener( 'change', syncAssignment );
		syncAssignment();

	} );

	/*
	TAXONOMY ORDER
	-- Stores a separate drag order for every custom post type.
	---------------------------------------------------------- */

	document.querySelectorAll( '.oa-taxonomy-order-list' ).forEach( function ( list ) {

		var draggedItem = null;
		var orderStatus = list.parentElement.querySelector( '.oa-taxonomy-order-status' );

		function reindexTaxonomies() {

			list.querySelectorAll( '.oa-taxonomy-order-item' ).forEach( function ( item, index ) {

				item.querySelector( '.oa-taxonomy-order-value' ).value = index;

			} );

		}

		function announceTaxonomyOrder() {

			reindexTaxonomies();
			orderStatus.textContent = '';

			window.requestAnimationFrame( function () {

				orderStatus.textContent = oaAdmin.taxonomyMovedText;

			} );

			list.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		}

		list.querySelectorAll( '.oa-taxonomy-order-item' ).forEach( function ( item ) {

			var handle = item.querySelector( '.oa-taxonomy-drag-handle' );

			handle.addEventListener( 'pointerdown', function () {

				item.draggable = true;

			} );

			handle.addEventListener( 'pointerup', function () {

				item.draggable = false;

			} );

			handle.addEventListener( 'pointercancel', function () {

				item.draggable = false;

			} );

			handle.addEventListener( 'keydown', function ( event ) {

				var sibling;

				if ( 'ArrowUp' === event.key ) {

					sibling = item.previousElementSibling;

					if ( sibling ) {

						list.insertBefore( item, sibling );

					}

				} else if ( 'ArrowDown' === event.key ) {

					sibling = item.nextElementSibling;

					if ( sibling ) {

						list.insertBefore( sibling, item );

					}

				} else {

					return;

				}

				event.preventDefault();
				announceTaxonomyOrder();
				handle.focus();

			} );

			item.addEventListener( 'dragstart', function ( event ) {

				draggedItem = item;
				item.classList.add( 'is-dragging' );
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData( 'text/plain', '' );

			} );

			item.addEventListener( 'dragend', function () {

				item.classList.remove( 'is-dragging' );
				item.draggable = false;
				draggedItem = null;
				announceTaxonomyOrder();

			} );

		} );

		list.addEventListener( 'dragover', function ( event ) {

			if ( ! draggedItem ) {

				return;

			}

			event.preventDefault();

			var target = Array.prototype.find.call( list.querySelectorAll( '.oa-taxonomy-order-item:not(.is-dragging)' ), function ( item ) {

				var box = item.getBoundingClientRect();

				return event.clientY < box.top + box.height / 2;

			} );

			list.insertBefore( draggedItem, target || null );

		} );

		reindexTaxonomies();

	} );

	/*
	CUSTOM POST COLLECTIONS
	-- Powers the independent taxonomy and custom-field definition cards.
	---------------------------------------------------------- */

	document.querySelectorAll( '.oa-collection' ).forEach( function ( collection ) {

		var list = collection.querySelector( '.oa-collection-list' );
		var defaultTemplate = collection.querySelector( '.oa-collection-template' );
		var addButtons = collection.querySelectorAll( '.oa-collection-add' );
		var collectionKey = collection.dataset.collection;
		var isTaxonomyCollection = 'custom_taxonomies' === collectionKey;
		var overviewHead = document.querySelector( '[data-oa-overview-url]' );
		var overviewUrl = overviewHead ? overviewHead.dataset.oaOverviewUrl : '';
		var nextIndex = list.querySelectorAll( '.oa-collection-item' ).length;
		var reusableFinderToggle = collection.querySelector( '.oa-reusable-field-finder-toggle' );
		var reusableFinder = collection.querySelector( '.oa-reusable-field-finder' );
		var reusableFinderClose = collection.querySelector( '.oa-reusable-field-finder-close' );
		var reusableFinderSearch = collection.querySelector( '.oa-reusable-field-search input' );
		var reusableFinderOptions = collection.querySelectorAll( '.oa-reusable-field-option' );
		var reusableFinderEmpty = collection.querySelector( '.oa-reusable-field-empty' );

		// Trailing underscores are kept while typing so an underscore keypress is not swallowed before the next character arrives.
		function slugify( value, keepTrailing ) {

			var slug = value.toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+/, '' );

			if ( ! keepTrailing ) {

				slug = slug.replace( /_+$/, '' );

			}

			return slug;

		}

		function reindex() {

			list.querySelectorAll( '.oa-collection-item' ).forEach( function ( item, index ) {

				item.querySelectorAll( '[name]' ).forEach( function ( input ) {

					var pattern = new RegExp( '\\[' + collectionKey + '\\]\\[[^\\]]+\\]' );

					input.name = input.name.replace( pattern, '[' + collectionKey + '][' + index + ']' );

				} );

			} );

		}

		function syncReusableFieldFinder() {

			if ( ! reusableFinder ) {

				return;

			}

			var query = reusableFinderSearch.value.trim().toLowerCase();
			var visibleCount = 0;

			reusableFinderOptions.forEach( function ( option ) {

				var field = document.getElementById( option.dataset.fieldTarget );
				var assignment = field ? field.querySelector( '[data-context-assignment="true"]' ) : null;
				var used = ! assignment || assignment.checked;
				var matches = '' === query || -1 !== option.dataset.search.indexOf( query );

				option.classList.toggle( 'is-used', used );
				option.classList.toggle( 'is-filtered', ! matches );

				if ( ! used && matches ) {

					visibleCount += 1;

				}

			} );

			reusableFinderEmpty.classList.toggle( 'oa-hidden', visibleCount > 0 );

		}

		function wireItem( item ) {

			var expandButton = item.querySelector( '.oa-collection-expand' );
			var body = item.querySelector( '.oa-collection-body' );
			var removeButton = item.querySelector( '.oa-collection-remove' );
			var enabled = item.querySelector( '[data-role="enabled"]' );
			var titleInput = item.querySelector( '[data-role="title"]' );
			var keyInput = item.querySelector( '[data-role="key"]' );
			var title = item.querySelector( '.oa-cpt-item-title' );
			var keyPreview = item.querySelector( '.oa-cpt-key-preview' );
			var typeInput = item.querySelector( '[data-field-type]' );
			var scopeBadge = item.querySelector( '.oa-field-scope-badge' );
			var contextAssignment = item.querySelector( '[data-context-assignment="true"]' );
			var publicToggle = item.querySelector( '.oa-tax-public-toggle' );
			var urlField = item.querySelector( '.oa-tax-url-field' );
			var keyIsAutomatic = 'false' === item.dataset.saved;

			function syncEnabled() {

				item.classList.toggle( 'is-disabled', ! enabled.checked );

			}

			function syncPublicUrls() {

				if ( ! publicToggle || ! urlField ) {

					return;

				}

				setFieldVisibility( urlField, publicToggle.checked );

			}

			function syncContextAssignment() {

				if ( ! scopeBadge || ! contextAssignment ) {

					return;

				}

				scopeBadge.textContent = contextAssignment.checked
					? scopeBadge.dataset.usedLabel
					: scopeBadge.dataset.availableLabel;

			}

			function syncFieldType() {

				if ( ! typeInput ) {

					return;

				}

				var choices = item.querySelector( '.oa-field-choices' );
				var defaultField = item.querySelector( '.oa-field-default' );
				var subFieldEditor = item.querySelector( '.oa-sub-field-editor' );
				var needsChoices = [ 'select', 'multiselect', 'radio' ].indexOf( typeInput.value ) !== -1;
				var isContainer = [ 'group', 'repeater' ].indexOf( typeInput.value ) !== -1;

				choices.classList.toggle( 'oa-hidden', ! needsChoices );

				if ( defaultField ) {

					defaultField.classList.toggle( 'oa-hidden', isContainer );

				}

				if ( subFieldEditor ) {

					subFieldEditor.classList.toggle( 'oa-hidden', ! isContainer );

				}

			}

			function wireSubFields() {

				var editor = item.querySelector( '.oa-sub-field-editor' );

				if ( ! editor ) {

					return;

				}

				var subList = editor.querySelector( '.oa-sub-field-list' );
				var subTemplate = editor.querySelector( '.oa-sub-field-template' );
				var subAdd = editor.querySelector( '.oa-sub-field-add' );
				var nextSubIndex = subList.children.length;

				function reindexSubFields() {

					subList.querySelectorAll( '.oa-sub-field-item' ).forEach( function ( subItem, index ) {

						subItem.querySelectorAll( '[name]' ).forEach( function ( input ) {

							input.name = input.name.replace( /\[sub_fields\]\[[^\]]+\]/, '[sub_fields][' + index + ']' );

						} );

					} );

				}

				function wireSubItem( subItem ) {

					var toggle = subItem.querySelector( '.oa-sub-field-toggle' );
					var remove = subItem.querySelector( '.oa-sub-field-remove' );
					var body = subItem.querySelector( '.oa-sub-field-body' );
					var titleInput = subItem.querySelector( '[data-sub-role="title"]' );
					var keyInput = subItem.querySelector( '[data-sub-role="key"]' );
					var typeSelect = subItem.querySelector( '[data-sub-field-type]' );
					var title = subItem.querySelector( '.oa-sub-field-title' );
					var key = subItem.querySelector( '.oa-sub-field-key' );
					var automaticKey = 'false' === subItem.dataset.saved;

					function syncSubType() {

						var choices = subItem.querySelector( '.oa-sub-field-choices' );
						var visible = [ 'select', 'multiselect', 'radio' ].indexOf( typeSelect.value ) !== -1;

						choices.classList.toggle( 'oa-hidden', ! visible );

					}

					toggle.addEventListener( 'click', function () {

						var opening = body.classList.contains( 'oa-hidden' );

						body.classList.toggle( 'oa-hidden', ! opening );
						toggle.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );

					} );

					titleInput.addEventListener( 'input', function () {

						title.textContent = titleInput.value.trim() || oaAdmin.newSubFieldText;

						if ( automaticKey ) {

							keyInput.value = slugify( titleInput.value ).substring( 0, 40 );
							key.textContent = keyInput.value;

						}

					} );

					keyInput.addEventListener( 'input', function ( event ) {

						if ( event.isTrusted ) {

							automaticKey = false;

						}

						keyInput.value = slugify( keyInput.value, true ).substring( 0, 40 );
						key.textContent = keyInput.value;

					} );

					keyInput.addEventListener( 'blur', function () {

						keyInput.value = slugify( keyInput.value ).substring( 0, 40 );
						key.textContent = keyInput.value;

					} );

					typeSelect.addEventListener( 'change', syncSubType );
					remove.addEventListener( 'click', function () {

						subItem.remove();
						reindexSubFields();
						subList.dispatchEvent( new Event( 'input', { bubbles: true } ) );
						oaNotify( oaAdmin.subFieldRemovedText, 'success' );

					} );

					syncSubType();

				}

				subAdd.addEventListener( 'click', function () {

					var html = subTemplate.innerHTML.split( '__SUB_INDEX__' ).join( 'new_' + nextSubIndex );
					var holder = document.createElement( 'div' );

					nextSubIndex++;
					holder.innerHTML = html.trim();

					var subItem = holder.firstElementChild;

					subList.appendChild( subItem );
					wireSubItem( subItem );
					reindexSubFields();
					subItem.querySelector( '[data-sub-role="title"]' ).focus();
					subList.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					oaNotify( oaAdmin.subFieldAddedText, 'success' );

				} );

				subList.querySelectorAll( '.oa-sub-field-item' ).forEach( wireSubItem );
				reindexSubFields();

			}

			expandButton.addEventListener( 'click', function () {

				var opening = body.classList.contains( 'oa-hidden' );

				body.classList.toggle( 'oa-hidden', ! opening );
				expandButton.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );

			} );

			enabled.addEventListener( 'change', syncEnabled );

			if ( contextAssignment ) {

				contextAssignment.addEventListener( 'change', function () {

					syncContextAssignment();
					syncReusableFieldFinder();

				} );

			}

			titleInput.addEventListener( 'input', function () {

				title.textContent = titleInput.value.trim() || collection.dataset.newLabel;

				if ( keyIsAutomatic ) {

					keyInput.value = slugify( titleInput.value ).substring( 0, 'custom_taxonomies' === collectionKey ? 29 : 40 );

					if ( 'custom_taxonomies' === collectionKey ) {

						keyInput.value = 'oa_' + keyInput.value.replace( /^oa_+/, '' );

					}

					keyPreview.textContent = keyInput.value;

				}

			} );

			keyInput.addEventListener( 'input', function ( event ) {

				if ( event.isTrusted ) {

					keyIsAutomatic = false;

				}

				keyInput.value = slugify( keyInput.value, true ).substring( 0, 'custom_taxonomies' === collectionKey ? 32 : 40 );
				keyPreview.textContent = keyInput.value;

			} );

			keyInput.addEventListener( 'blur', function () {

				keyInput.value = slugify( keyInput.value ).substring( 0, 'custom_taxonomies' === collectionKey ? 32 : 40 );
				keyPreview.textContent = keyInput.value;

			} );

			if ( typeInput ) {

				typeInput.addEventListener( 'change', syncFieldType );
				wireSubFields();

			}

			if ( publicToggle ) {

				publicToggle.addEventListener( 'change', syncPublicUrls );

			}

			syncPublicUrls();

			item.querySelectorAll( '[data-primary-assignment="true"]' ).forEach( function ( assignment ) {

				assignment.addEventListener( 'change', function () {

					assignment.checked = true;

				} );

			} );

			removeButton.addEventListener( 'click', function () {

				function remove() {

					item.remove();
					reindex();
					list.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					oaNotify( isTaxonomyCollection ? oaAdmin.categoryRemovedText : oaAdmin.fieldRemovedText, 'success' );

					// On a single definition's own editor there is nothing left to edit once
					// it is gone, so the save returns to the overview instead of a dead page.
					if ( ! list.querySelector( '.oa-collection-item' ) ) {

						retargetSaveRedirect( overviewUrl );

					}

				}

				if ( 'false' === item.dataset.saved ) {

					remove();
					return;

				}

				window.oaConfirm( {
					title: oaAdmin.removeDefinitionTitle,
					message: oaAdmin.removeDefinitionText,
					confirmText: oaAdmin.removeDefinitionAction,
					destructive: true
				} ).then( function ( confirmed ) {

					if ( confirmed ) {

						remove();

					}

				} );

			} );

			syncEnabled();
			syncFieldType();
			syncContextAssignment();

		}

		addButtons.forEach( function ( addButton ) {

			addButton.addEventListener( 'click', function () {

				var scope = addButton.dataset.fieldScope || '';
				var template = scope
					? collection.querySelector( '.oa-collection-template[data-field-scope="' + scope + '"]' )
					: defaultTemplate;
				var html = template.innerHTML.split( '__INDEX__' ).join( 'new_' + nextIndex );
				var holder = document.createElement( 'div' );

				nextIndex++;
				holder.innerHTML = html.trim();

				var item = holder.firstElementChild;

				list.appendChild( item );
				wireItem( item );
				reindex();
				item.querySelector( '[data-role="title"]' ).focus();
				list.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				oaNotify( isTaxonomyCollection ? oaAdmin.categoryAddedText : oaAdmin.fieldAddedText, 'success' );

			} );

		} );

		if ( reusableFinder ) {

			reusableFinderToggle.addEventListener( 'click', function () {

				var opening = reusableFinder.classList.contains( 'oa-hidden' );

				reusableFinder.classList.toggle( 'oa-hidden', ! opening );
				reusableFinderToggle.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );

				if ( opening ) {

					syncReusableFieldFinder();
					reusableFinderSearch.focus();

				}

			} );

			reusableFinderClose.addEventListener( 'click', function () {

				reusableFinder.classList.add( 'oa-hidden' );
				reusableFinderToggle.setAttribute( 'aria-expanded', 'false' );
				reusableFinderToggle.focus();

			} );

			reusableFinderSearch.addEventListener( 'input', syncReusableFieldFinder );

			reusableFinderOptions.forEach( function ( option ) {

				option.addEventListener( 'click', function () {

					var field = document.getElementById( option.dataset.fieldTarget );
					var assignment = field ? field.querySelector( '[data-context-assignment="true"]' ) : null;

					if ( ! assignment ) {

						return;

					}

					assignment.checked = true;
					assignment.dispatchEvent( new Event( 'change', { bubbles: true } ) );

				} );

			} );

		}

		list.querySelectorAll( '.oa-collection-item' ).forEach( wireItem );
		reindex();
		syncReusableFieldFinder();

	} );

	/* Open a field linked from the content overview directly in place */
	if ( 0 === window.location.hash.indexOf( '#oa-field-' ) ) {

		var targetedField = document.getElementById( window.location.hash.substring( 1 ) );

		if ( targetedField ) {

			var targetedFieldToggle = targetedField.querySelector( '.oa-collection-expand' );
			var targetedFieldBody = targetedField.querySelector( '.oa-collection-body' );

			targetedFieldBody.classList.remove( 'oa-hidden' );
			targetedFieldToggle.setAttribute( 'aria-expanded', 'true' );
			window.requestAnimationFrame( function () {

				targetedField.scrollIntoView( { behavior: 'smooth', block: 'center' } );

			} );

		}

	}

})();

/*
BREAKDANCE SPACING GRID
-- Switches the grid between Breakdance breakpoints, keeps token-bound rows
-- read-only, and mirrors the compiled stylesheet into the preview panel so
-- the result is visible before saving.
---------------------------------------------------------- */

(function () {

	'use strict';

	var grid = document.querySelector( '[data-oa-spacing]' );

	if ( ! grid ) {

		return;

	}

	var tabs        = Array.prototype.slice.call( grid.querySelectorAll( '.oa-spacing-tab' ) );
	var tokenRows   = Array.prototype.slice.call( grid.querySelectorAll( '.oa-spacing-row--token' ) );
	var elementRows = Array.prototype.slice.call( grid.querySelectorAll( '.oa-spacing-row[data-selector]' ) );
	var resetToggle = grid.querySelector( '.oa-switch input[type="checkbox"]' );
	var preview     = grid.querySelector( '.oa-spacing-css' );
	var NUMBER      = /^-?\d+(\.\d+)?$/;

	/*
	TOKEN VAR
	-- The variable a row is bound to, or an empty string when the row carries
	-- its own literal value.
	---------------------------------------------------------- */

	function tokenVar( row ) {

		var select = row.querySelector( '.oa-spacing-unit-select' );
		var option = select ? select.options[ select.selectedIndex ] : null;

		return option && option.dataset.var ? option.dataset.var : '';

	}

	/*
	RESOLVE VALUE
	-- Mirrors the module's PHP value resolution: a bound row emits its
	-- variable once at the base breakpoint, everything else emits a number
	-- plus its unit, and a blank field emits nothing.
	---------------------------------------------------------- */

	function resolveValue( row, breakpoint, isBase ) {

		var bound = tokenVar( row );

		if ( bound ) {

			return isBase ? 'var(' + bound + ')' : '';

		}

		var input = row.querySelector( '.oa-spacing-input[data-breakpoint="' + breakpoint + '"]' );
		var value = input ? input.value.trim() : '';

		if ( '' === value || ! NUMBER.test( value ) ) {

			return '';

		}

		var select = row.querySelector( '.oa-spacing-unit-select' );
		var unit = select ? select.value : 'px';

		return '0' === value ? '0px' : value + unit;

	}

	/*
	INDENT
	-- Pushes a block of rules one tab in, for rules nested in a media query.
	---------------------------------------------------------- */

	function indent( css ) {

		return css.split( '\n' ).map( function ( line ) {

			return '' === line.trim() ? '' : '\t' + line;

		} ).join( '\n' );

	}

	/*
	BUILD CSS
	-- Recompiles the whole stylesheet from the current field values.
	---------------------------------------------------------- */

	function buildCss() {

		var blocks = [];
		var reset = [];

		tabs.forEach( function ( tab ) {

			var breakpoint = tab.dataset.breakpoint;
			var isBase = tab.hasAttribute( 'data-base' );
			var media = tab.dataset.media || '';
			var rules = [];
			var declarations = [];

			tokenRows.forEach( function ( row ) {

				var value = resolveValue( row, breakpoint, isBase );

				if ( ! value ) {

					return;

				}

				declarations.push( '\t' + row.dataset.var + ': ' + value + ';' );

			} );

			if ( declarations.length ) {

				rules.push( ':root {\n' + declarations.join( '\n' ) + '\n}' );

			}

			elementRows.forEach( function ( row ) {

				var value = resolveValue( row, breakpoint, isBase );

				if ( ! value ) {

					return;

				}

				rules.push( '.breakdance ' + row.dataset.selector + ' {\n\tmargin-bottom: ' + value + ';\n}' );

				if ( -1 === reset.indexOf( row ) ) {

					reset.push( row );

				}

			} );

			if ( ! rules.length ) {

				return;

			}

			if ( ! media ) {

				blocks.push( rules.join( '\n\n' ) );

				return;

			}

			blocks.push( media + ' {\n\n' + indent( rules.join( '\n\n' ) ) + '\n\n}' );

		} );

		if ( resetToggle && resetToggle.checked && reset.length ) {

			var selectors = [];

			reset.forEach( function ( row ) {

				var via = row.dataset.resetVia || '';

				var covered = via && reset.some( function ( other ) {

					return other.dataset.row === via;

				} );

				var selector = '.breakdance ' + row.dataset.selector + ':last-child';

				if ( ! covered && -1 === selectors.indexOf( selector ) ) {

					selectors.push( selector );

				}

			} );

			if ( selectors.length ) {

				blocks.push( selectors.join( ',\n' ) + ' {\n\tmargin-bottom: 0;\n}' );

			}

		}

		if ( ! blocks.length ) {

			return '';

		}

		return '/* Octave Addons — Breakdance default spacing */\n\n' + blocks.join( '\n\n' ) + '\n';

	}

	/*
	SYNC PREVIEW
	-- Writes the compiled stylesheet, or the empty-state note, into the
	-- read-only preview panel.
	---------------------------------------------------------- */

	function syncPreview() {

		if ( ! preview ) {

			return;

		}

		var code = preview.querySelector( 'code' ) || preview;

		code.textContent = buildCss() || preview.dataset.empty || '';

	}

	/*
	SYNC TABS
	-- Marks any breakpoint that carries its own values, so an override on a
	-- hidden tab is never invisible.
	---------------------------------------------------------- */

	function syncTabs() {

		tabs.forEach( function ( tab ) {

			var breakpoint = tab.dataset.breakpoint;

			var hasValues = tokenRows.concat( elementRows ).some( function ( row ) {

				return '' !== resolveValue( row, breakpoint, tab.hasAttribute( 'data-base' ) );

			} );

			tab.classList.toggle( 'has-values', hasValues );

		} );

	}

	/*
	SYNC ROW
	-- Keeps a bound row's number fields read-only, since its spacing comes
	-- from the token rather than from the row itself.
	---------------------------------------------------------- */

	function syncRow( row ) {

		var bound = '' !== tokenVar( row );

		row.classList.toggle( 'is-token', bound );

		row.querySelectorAll( '.oa-spacing-input' ).forEach( function ( input ) {

			input.readOnly = bound;

		} );

	}

	/*
	SHOW BREAKPOINT
	-- Reveals one breakpoint's fields. Every field stays in the form at all
	-- times, so hidden values are still submitted.
	---------------------------------------------------------- */

	function showBreakpoint( breakpoint ) {

		tabs.forEach( function ( tab ) {

			var isActive = tab.dataset.breakpoint === breakpoint;

			tab.classList.toggle( 'is-active', isActive );
			tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );

		} );

		grid.querySelectorAll( '.oa-spacing-input' ).forEach( function ( input ) {

			input.classList.toggle( 'oa-hidden', input.dataset.breakpoint !== breakpoint );

		} );

	}

	tabs.forEach( function ( tab ) {

		tab.addEventListener( 'click', function () {

			showBreakpoint( tab.dataset.breakpoint );

		} );

	} );

	grid.addEventListener( 'change', function ( event ) {

		var row = event.target.closest ? event.target.closest( '.oa-spacing-row' ) : null;

		if ( row && event.target.matches( '.oa-spacing-unit-select' ) ) {

			syncRow( row );

		}

		syncTabs();
		syncPreview();

	} );

	grid.addEventListener( 'input', function () {

		syncTabs();
		syncPreview();

	} );

	if ( resetToggle ) {

		resetToggle.addEventListener( 'change', syncPreview );

	}

	elementRows.forEach( syncRow );
	syncTabs();
	syncPreview();

})();
