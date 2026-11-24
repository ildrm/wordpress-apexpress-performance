(function () {
	'use strict';

	document.addEventListener('submit', function (event) {
		const form = event.target;
		if (!(form instanceof HTMLFormElement) || !form.closest('.apx-wrap') || event.defaultPrevented) return;

		const message = form.getAttribute('data-apx-confirm');
		if (message && !window.confirm(message)) {
			event.preventDefault();
			return;
		}
		const button = event.submitter;
		if (!(button instanceof HTMLButtonElement)) return;
		if (button.getAttribute('aria-busy') === 'true') {
			event.preventDefault();
			return;
		}

		const label = button.textContent;
		button.setAttribute('aria-busy', 'true');
		button.textContent = window.ApexPressAdmin?.working || 'Working…';
		window.setTimeout(function () {
			button.removeAttribute('aria-busy');
			button.textContent = label;
		}, 15000);
	});
})();
