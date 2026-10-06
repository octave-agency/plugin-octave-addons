(function () {
	'use strict';

	function init() {

		var trigger = document.getElementById('oaContactPopupTrigger');
		var wrap    = document.getElementById('oaContactPopupWrap');
		var overlay = document.getElementById('oaContactPopupOverlay');
		var popup   = document.getElementById('oaContactPopup');

		if (!trigger || !wrap || !popup) return;

		var closeBtn = popup.querySelector('.oa-contact-popup-close');

		function openPopup() {
			console.log( 'openPopup' );
			wrap.classList.add('is-open');
			wrap.setAttribute('aria-hidden', 'false');
			trigger.setAttribute('aria-expanded', 'true');
			document.body.style.overflow = 'hidden';
			if (closeBtn) closeBtn.focus();
		}

		function closePopup() {
			wrap.classList.remove('is-open');
			wrap.setAttribute('aria-hidden', 'true');
			trigger.setAttribute('aria-expanded', 'false');
			document.body.style.overflow = '';
			trigger.focus();
		}

		trigger.addEventListener('click', openPopup);

		if (closeBtn) {
			closeBtn.addEventListener('click', closePopup);
		}

		if (overlay) {
			overlay.addEventListener('click', closePopup);
		}

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && wrap.classList.contains('is-open')) {
				closePopup();
			}
		});

		/* Trap focus inside the popup while it is open */
		popup.addEventListener('keydown', function (e) {
			if (e.key !== 'Tab') return;
			var focusable = popup.querySelectorAll(
				'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
			);
			if (!focusable.length) return;
			var first = focusable[0];
			var last  = focusable[focusable.length - 1];
			if (e.shiftKey) {
				if (document.activeElement === first) { e.preventDefault(); last.focus(); }
			} else {
				if (document.activeElement === last)  { e.preventDefault(); first.focus(); }
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

})();
