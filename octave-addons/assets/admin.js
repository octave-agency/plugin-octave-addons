(function () {
	'use strict';

	/* Enable toggle → show/hide settings body + update sidebar dot */
	document.querySelectorAll('.oa-enable-toggle').forEach(function (toggle) {
		var panelId  = toggle.dataset.panel;
		var panel    = panelId ? document.getElementById(panelId) : null;
		if (!panel) return;

		var body     = panel.querySelector('.oa-settings-body');
		var locked   = panel.querySelector('.oa-settings-locked');
		var moduleId = toggle.dataset.module;
		var navItem  = moduleId
			? document.querySelector('.oa-nav-item[data-module="' + moduleId + '"]')
			: null;
		var dot = navItem ? navItem.querySelector('.oa-dot') : null;

		function sync() {
			var on = toggle.checked;
			if (body)   body.classList.toggle('oa-hidden', !on);
			if (locked) locked.classList.toggle('oa-hidden', on);
			if (dot) {
				dot.classList.toggle('is-on',  on);
				dot.classList.toggle('is-off', !on);
			}
		}

		toggle.addEventListener('change', sync);
	});

	/* Field row show/hide — data-controls-row accepts comma-separated IDs */
	document.querySelectorAll('[data-controls-row]').forEach(function (cb) {
		var ids  = cb.dataset.controlsRow.split(',');
		var rows = ids.map(function (id) { return document.getElementById(id.trim()); }).filter(Boolean);
		if (!rows.length) return;
		function sync() { rows.forEach(function (r) { r.classList.toggle('oa-hidden', !cb.checked); }); }
		sync();
		cb.addEventListener('change', sync);
	});

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

		var enabledCount = document.querySelectorAll( '.oa-enable-toggle:checked' ).length;

		document.querySelectorAll( '.oa-enabled-count' ).forEach( function ( count ) {

			count.textContent = enabledCount;

		} );

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

		} );

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
							'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
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

	/* Native colour pickers — keep the hex display in sync */
	document.querySelectorAll('.oa-color-input').forEach(function (input) {
		var wrap    = input.closest('.oa-color-picker-wrap');
		var display = wrap ? wrap.querySelector('.oa-color-value') : null;
		if (!display) return;
		input.addEventListener('input', function () {
			display.textContent = input.value;
		});
	});
})();
