(function () {
	'use strict';

	function normalizeLines(value) {
		var items = (value || '')
			.split(/\r?\n/)
			.map(function (line) { return line.trim(); })
			.filter(function (line) { return /^\d{4}-\d{2}-\d{2}$/.test(line); });

		items = items.filter(function (item, index) {
			return items.indexOf(item) === index;
		});

		items.sort();
		return items;
	}

	function initDisabledDatesAdder() {
		var button = document.getElementById('cf7-ecr-add-disabled-date');
		var input = document.getElementById('cf7-ecr-disabled-date-new');
		var textarea = document.getElementById('cf7-ecr-disabled-dates');

		if (!button || !input || !textarea) {
			return;
		}

		button.addEventListener('click', function () {
			var date = input.value;
			if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
				return;
			}

			var items = normalizeLines(textarea.value);
			if (items.indexOf(date) === -1) {
				items.push(date);
			}
			items.sort();
			textarea.value = items.join('\n');
			input.value = '';
			textarea.focus();
		});
	}

	function initLoadDayButtons() {
		var form = document.querySelector('.cf7-ecr-edit-day-form');
		if (!form) {
			return;
		}

		Array.prototype.forEach.call(document.querySelectorAll('.cf7-ecr-load-day'), function (button) {
			button.addEventListener('click', function () {
				var dateInput = form.querySelector('[name="date_key"]');
				var reservedInput = form.querySelector('[name="reserved_places"]');
				var exclusiveInput = form.querySelector('[name="is_exclusive"]');
				var disabledInput = form.querySelector('[name="is_disabled"]');

				if (dateInput) {
					dateInput.value = button.getAttribute('data-date') || '';
				}
				if (reservedInput) {
					reservedInput.value = button.getAttribute('data-reserved') || '0';
				}
				if (exclusiveInput) {
					exclusiveInput.checked = button.getAttribute('data-exclusive') === '1';
				}
				if (disabledInput) {
					disabledInput.checked = button.getAttribute('data-disabled') === '1';
				}

				form.scrollIntoView({ behavior: 'smooth', block: 'start' });
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		initDisabledDatesAdder();
		initLoadDayButtons();
	});
})();
