(function () {
	'use strict';

	if (!window.CF7ECR) {
		return;
	}

	var config = window.CF7ECR;
	var texts = config.texts || {};
	var monthNames = [
		'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec',
		'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'
	];
	var weekdays = ['Pn', 'Wt', 'Śr', 'Cz', 'Pt', 'So', 'Nd'];
	var availabilityCache = {};
	var calendars = [];
	var disabledDates = Array.isArray(config.disabledDates) ? config.disabledDates : [];

	function pad(number) {
		return number < 10 ? '0' + number : String(number);
	}

	function formatDate(date) {
		return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
	}

	function isDateDisabledBySettings(dateValue) {
		return disabledDates.indexOf(dateValue) !== -1;
	}

	function parseDate(value) {
		var match = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(value || '');
		if (!match) {
			return null;
		}

		var year = parseInt(match[1], 10);
		var month = parseInt(match[2], 10) - 1;
		var day = parseInt(match[3], 10);
		var date = new Date(year, month, day);

		if (date.getFullYear() !== year || date.getMonth() !== month || date.getDate() !== day) {
			return null;
		}

		return date;
	}

	function peopleLabel(number) {
		number = parseInt(number, 10) || 0;
		if (number === 1) {
			return '1 osoba';
		}

		var last = number % 10;
		var lastTwo = number % 100;
		if (last >= 2 && last <= 4 && !(lastTwo >= 12 && lastTwo <= 14)) {
			return number + ' osoby';
		}

		return number + ' osób';
	}

	function placesLabel(number) {
		number = parseInt(number, 10) || 0;
		if (number === 1) {
			return '1 miejsce';
		}

		var last = number % 10;
		var lastTwo = number % 100;
		if (last >= 2 && last <= 4 && !(lastTwo >= 12 && lastTwo <= 14)) {
			return number + ' miejsca';
		}

		return number + ' miejsc';
	}

	function getForm(element) {
		return element.closest('form') || document;
	}

	function getBookingState(form) {
		var booking = form.querySelector('.cf7-ecr-booking');
		if (!booking) {
			return {
				exclusive: false,
				people: 1,
				capacity: parseInt(config.capacity, 10) || 1
			};
		}

		var capacity = parseInt(booking.getAttribute('data-capacity'), 10) || parseInt(config.capacity, 10) || 1;
		var exclusiveInput = booking.querySelector('.cf7-ecr-exclusive');
		var peopleInput = booking.querySelector('.cf7-ecr-people');
		var exclusive = !!(exclusiveInput && exclusiveInput.checked);
		var people = peopleInput ? parseInt(peopleInput.value, 10) : 1;

		if (!people || people < 1) {
			people = 1;
		}

		if (people > capacity) {
			people = capacity;
		}

		return {
			exclusive: exclusive,
			people: people,
			capacity: capacity
		};
	}

	function updateBooking(booking) {
		var capacity = parseInt(booking.getAttribute('data-capacity'), 10) || parseInt(config.capacity, 10) || 1;
		var valueInput = booking.querySelector('.cf7-ecr-booking-value');
		var peopleInput = booking.querySelector('.cf7-ecr-people');
		var exclusiveInput = booking.querySelector('.cf7-ecr-exclusive');
		var fixedPeople = booking.querySelector('.cf7-ecr-fixed-people');
		var fixedExclusive = booking.querySelector('.cf7-ecr-fixed-exclusive');
		var exclusive = !!(exclusiveInput && exclusiveInput.checked);
		var people = peopleInput ? parseInt(peopleInput.value, 10) : 1;

		if (!people || people < 1) {
			people = 1;
		}

		if (people > capacity) {
			people = capacity;
		}

		if (peopleInput) {
			peopleInput.value = people;
			peopleInput.disabled = exclusive;
		}

		booking.classList.toggle('is-exclusive', exclusive);

		if (valueInput) {
			valueInput.value = exclusive ? (texts.summaryExclusive || 'Na wyłączność') : peopleLabel(people);
		}

		if (fixedPeople) {
			fixedPeople.value = exclusive ? capacity : people;
		}

		if (fixedExclusive) {
			fixedExclusive.value = exclusive ? '1' : '0';
		}
	}

	function setExclusiveBlockedForForm(form, blocked) {
		var booking = form.querySelector('.cf7-ecr-booking');

		if (!booking) {
			return;
		}

		var exclusiveInput = booking.querySelector('.cf7-ecr-exclusive');
		var exclusiveLabel = booking.querySelector('.cf7-ecr-exclusive-label');

		if (!exclusiveInput) {
			return;
		}

		if (blocked && exclusiveInput.checked) {
			exclusiveInput.checked = false;
			updateBooking(booking);
		}

		exclusiveInput.disabled = blocked;
		booking.classList.toggle('is-exclusive-blocked', blocked);

		if (exclusiveLabel) {
			exclusiveLabel.classList.toggle('is-disabled', blocked);
		}

		if (blocked) {
			exclusiveInput.setAttribute('title', 'Na ten dzień są już rezerwacje. Rezerwacja na wyłączność nie jest dostępna.');
		} else {
			exclusiveInput.removeAttribute('title');
		}
	}

	function refreshFormCalendars(form) {
		calendars.forEach(function (calendar) {
			if (calendar.form === form) {
				calendar.checkCurrentSelection();
				calendar.render();
			}
		});
	}


	function clearAvailabilityCache() {
		Object.keys(availabilityCache).forEach(function (key) {
			delete availabilityCache[key];
		});
	}

	function monthKey(year, month) {
		return year + '-' + pad(month + 1);
	}

	function monthRange(year, month) {
		var start = new Date(year, month, 1);
		var end = new Date(year, month + 1, 0);
		return {
			start: formatDate(start),
			end: formatDate(end)
		};
	}

	function fetchAvailability(year, month) {
		var key = monthKey(year, month);
		if (availabilityCache[key]) {
			return availabilityCache[key];
		}

		var range = monthRange(year, month);
		var params = new URLSearchParams();
		params.append('action', 'cf7_ecr_availability');
		params.append('nonce', config.nonce || '');
		params.append('start', range.start);
		params.append('end', range.end);

		availabilityCache[key] = fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: params.toString()
		}).then(function (response) {
			return response.json();
		}).then(function (json) {
			if (!json || !json.success || !json.data || !json.data.dates) {
				return {};
			}

			return json.data.dates;
		}).catch(function () {
			delete availabilityCache[key];
			return {};
		});

		return availabilityCache[key];
	}

	function Calendar(root) {
		this.root = root;
		this.form = getForm(root);
		this.valueInput = root.querySelector('.cf7-ecr-date-value');
		this.fixedInput = root.querySelector('.cf7-ecr-fixed-date');
		this.toggle = root.querySelector('.cf7-ecr-date-toggle');
		this.selected = root.querySelector('.cf7-ecr-date-selected');
		this.panel = root.querySelector('.cf7-ecr-calendar');
		this.today = parseDate(config.today) || new Date();

		var minDate = parseDate(config.minDate || '');
		if (minDate && formatDate(this.today) < config.minDate) {
			this.monthDate = new Date(minDate.getFullYear(), minDate.getMonth(), 1);
		} else {
			this.monthDate = new Date(this.today.getFullYear(), this.today.getMonth(), 1);
		}
		this.renderId = 0;
		this.bind();
	}

	Calendar.prototype.bind = function () {
		if (!this.panel) {
			return;
		}

		this.panel.hidden = false;
		this.render();
	};

	Calendar.prototype.open = function () {
		this.panel.hidden = false;
		if (this.toggle) {
			this.toggle.setAttribute('aria-expanded', 'true');
		}
		this.render();
	};

	Calendar.prototype.close = function () {
		this.panel.hidden = true;
		if (this.toggle) {
			this.toggle.setAttribute('aria-expanded', 'false');
		}
	};

	Calendar.prototype.clear = function () {
		if (this.valueInput) {
			this.valueInput.value = '';
		}
		if (this.fixedInput) {
			this.fixedInput.value = '';
		}
		if (this.selected) {
			this.selected.textContent = texts.noDate || 'Nie wybrano daty.';
		}

		setExclusiveBlockedForForm(this.form, false);
	};

	Calendar.prototype.select = function (dateValue) {
		if (this.valueInput) {
			this.valueInput.value = dateValue;
		}
		if (this.fixedInput) {
			this.fixedInput.value = dateValue;
		}
		if (this.selected) {
			this.selected.textContent = (texts.selectedDate || 'Wybrana data:') + ' ' + dateValue;
		}

		this.updateExclusiveOption(dateValue);
		this.render();
	};

	Calendar.prototype.getStatus = function (dateValue, statuses) {
		var minDate = config.minDate || '';
		var maxDate = config.maxDate || '';

		var status = statuses[dateValue] || {
			date: dateValue,
			capacity: parseInt(config.capacity, 10) || 1,
			reserved_places: 0,
			available_places: parseInt(config.capacity, 10) || 1,
			is_disabled: false,
			is_exclusive: false,
			is_past: dateValue < (config.today || ''),
			is_before_min: minDate ? dateValue < minDate : false,
			is_after_max: maxDate ? dateValue > maxDate : false,
			available: dateValue >= (config.today || '') && (!minDate || dateValue >= minDate) && (!maxDate || dateValue <= maxDate)
		};

		if (isDateDisabledBySettings(dateValue)) {
			status.is_disabled = true;
			status.available = false;
		}

		if (minDate && dateValue < minDate) {
			status.is_before_min = true;
			status.available = false;
		}

		if (maxDate && dateValue > maxDate) {
			status.is_after_max = true;
			status.available = false;
		}

		return status;
	};

	Calendar.prototype.isSelectable = function (dateValue, status) {
		var booking = getBookingState(this.form);

		if (!status || status.is_past || status.is_before_min || status.is_after_max || status.is_disabled || status.is_exclusive) {
			return false;
		}

		if (booking.exclusive) {
			return parseInt(status.reserved_places, 10) === 0;
		}

		return parseInt(status.available_places, 10) >= booking.people;
	};

	Calendar.prototype.getDisabledReason = function (status) {
		var booking = getBookingState(this.form);

		if (status.is_past) {
			return 'Miniony dzień.';
		}
		if (status.is_before_min) {
			return 'Rezerwacje są dostępne tylko od początku czerwca.';
		}
		if (status.is_after_max) {
			return 'Rezerwacje są dostępne tylko do końca sierpnia.';
		}
		if (status.is_disabled) {
			return texts.disabledDate || 'Ten dzień jest niedostępny.';
		}
		if (status.is_exclusive) {
			return 'Zarezerwowane na wyłączność.';
		}
		if (booking.exclusive && parseInt(status.reserved_places, 10) > 0) {
			return 'Ten dzień ma już rezerwacje.';
		}
		if (parseInt(status.available_places, 10) <= 0) {
			return texts.noPlaces || 'Brak wolnych miejsc.';
		}
		if (!booking.exclusive && parseInt(status.available_places, 10) < booking.people) {
			return 'Za mało wolnych miejsc.';
		}

		return '';
	};

	Calendar.prototype.updateExclusiveOption = function (dateValue) {
		var self = this;
		var date = parseDate(dateValue);

		if (!date) {
			setExclusiveBlockedForForm(this.form, false);
			return;
		}

		fetchAvailability(date.getFullYear(), date.getMonth()).then(function (statuses) {
			var status = self.getStatus(dateValue, statuses);
			var reservedPlaces = parseInt(status.reserved_places, 10) || 0;

			setExclusiveBlockedForForm(self.form, reservedPlaces > 0);
		});
	};

	Calendar.prototype.checkCurrentSelection = function () {
		var self = this;
		var value = this.valueInput ? this.valueInput.value : '';
		var date = parseDate(value);

		if (!date) {
			setExclusiveBlockedForForm(this.form, false);
			return;
		}

		fetchAvailability(date.getFullYear(), date.getMonth()).then(function (statuses) {
			var status = self.getStatus(value, statuses);

			if (!self.isSelectable(value, status)) {
				self.clear();
				return;
			}

			var reservedPlaces = parseInt(status.reserved_places, 10) || 0;
			setExclusiveBlockedForForm(self.form, reservedPlaces > 0);
		});
	};

	Calendar.prototype.render = function () {
		var self = this;
		var renderId = ++this.renderId;
		var year = this.monthDate.getFullYear();
		var month = this.monthDate.getMonth();

		this.panel.innerHTML = '<div class="cf7-ecr-calendar-loading">' + (texts.loading || 'Ładowanie dostępności...') + '</div>';

		fetchAvailability(year, month).then(function (statuses) {
			if (renderId !== self.renderId) {
				return;
			}
			self.draw(year, month, statuses);
		});
	};

	Calendar.prototype.draw = function (year, month, statuses) {
		var first = new Date(year, month, 1);
		var daysInMonth = new Date(year, month + 1, 0).getDate();
		var startOffset = (first.getDay() + 6) % 7;
		var selectedValue = this.valueInput ? this.valueInput.value : '';
		var html = '';
		var day = 1;
		var row;
		var col;
		var minDate = parseDate(config.minDate || '');
		var maxDate = parseDate(config.maxDate || '');
		var currentMonthDate = new Date(year, month, 1);
		var prevMonthDate = new Date(year, month - 1, 1);
		var nextMonthDate = new Date(year, month + 1, 1);

		var prevDisabled = minDate && prevMonthDate < new Date(minDate.getFullYear(), minDate.getMonth(), 1);
		var nextDisabled = maxDate && nextMonthDate > new Date(maxDate.getFullYear(), maxDate.getMonth(), 1);

		html += '<div class="cf7-ecr-calendar-header">';
		html += '<button type="button" class="cf7-ecr-prev" aria-label="' + escapeHtml(texts.prevMonth || 'Poprzedni miesiąc') + '"' + (prevDisabled ? ' disabled' : '') + '>‹</button>';
		html += '<strong>' + monthNames[month] + ' ' + year + '</strong>';
		html += '<button type="button" class="cf7-ecr-next" aria-label="' + escapeHtml(texts.nextMonth || 'Następny miesiąc') + '"' + (nextDisabled ? ' disabled' : '') + '>›</button>';
		html += '</div>';
		html += '<table class="cf7-ecr-calendar-table"><thead><tr>';

		weekdays.forEach(function (name) {
			html += '<th scope="col">' + name + '</th>';
		});

		html += '</tr></thead><tbody>';

		for (row = 0; row < 6; row++) {
			html += '<tr>';
			for (col = 0; col < 7; col++) {
				if ((row === 0 && col < startOffset) || day > daysInMonth) {
					html += '<td class="is-empty"></td>';
					continue;
				}

				var dateValue = year + '-' + pad(month + 1) + '-' + pad(day);
				var status = this.getStatus(dateValue, statuses);
				var selectable = this.isSelectable(dateValue, status);
				var reason = selectable ? placesLabel(status.available_places) + ' wolne' : this.getDisabledReason(status);
				var classes = ['cf7-ecr-day'];

				if (!selectable) {
					classes.push('is-disabled');
				}
				if (dateValue === selectedValue) {
					classes.push('is-selected');
				}

				html += '<td>';
				html += '<button type="button" class="' + classes.join(' ') + '" data-date="' + dateValue + '" title="' + escapeHtml(reason) + '"' + (selectable ? '' : ' disabled') + '>';
				html += '<span class="cf7-ecr-day-number">' + day + '</span>';
				html += '<span class="cf7-ecr-day-meta">' + escapeHtml(selectable ? placesLabel(status.available_places) : '') + '</span>';
				html += '</button>';
				html += '</td>';

				day++;
			}
			html += '</tr>';
			if (day > daysInMonth) {
				break;
			}
		}

		html += '</tbody></table>';
		this.panel.innerHTML = html;
		this.bindPanelButtons();
	};

	Calendar.prototype.bindPanelButtons = function () {
		var self = this;
		var previous = this.panel.querySelector('.cf7-ecr-prev');
		var next = this.panel.querySelector('.cf7-ecr-next');

		if (previous) {
			previous.addEventListener('click', function () {
				if (previous.disabled) {
					return;
				}

				self.monthDate = new Date(self.monthDate.getFullYear(), self.monthDate.getMonth() - 1, 1);
				self.render();
			});
		}

		if (next) {
			next.addEventListener('click', function () {
				if (next.disabled) {
					return;
				}

				self.monthDate = new Date(self.monthDate.getFullYear(), self.monthDate.getMonth() + 1, 1);
				self.render();
			});
		}
		Array.prototype.forEach.call(this.panel.querySelectorAll('.cf7-ecr-day:not([disabled])'), function (button) {
			button.addEventListener('click', function () {
				self.select(button.getAttribute('data-date'));
			});
		});
	};

	function escapeHtml(value) {
		return String(value || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function initBookings() {
		Array.prototype.forEach.call(document.querySelectorAll('.cf7-ecr-booking'), function (booking) {
			var form = getForm(booking);
			var peopleInput = booking.querySelector('.cf7-ecr-people');
			var exclusiveInput = booking.querySelector('.cf7-ecr-exclusive');

			updateBooking(booking);

			if (peopleInput) {
				peopleInput.addEventListener('input', function () {
					updateBooking(booking);
					refreshFormCalendars(form);
				});
			}

			if (exclusiveInput) {
				exclusiveInput.addEventListener('change', function () {
					updateBooking(booking);
					refreshFormCalendars(form);
				});
			}
		});
	}

	function initCalendars() {
		Array.prototype.forEach.call(document.querySelectorAll('.cf7-ecr-date'), function (root) {
			calendars.push(new Calendar(root));
		});
	}

	function resetForm(form) {
		Array.prototype.forEach.call(form.querySelectorAll('.cf7-ecr-booking'), function (booking) {
			var peopleInput = booking.querySelector('.cf7-ecr-people');
			var exclusiveInput = booking.querySelector('.cf7-ecr-exclusive');
			if (peopleInput) {
				peopleInput.value = '1';
			}
			if (exclusiveInput) {
				exclusiveInput.checked = false;
			}
			updateBooking(booking);
		});

		calendars.forEach(function (calendar) {
			if (calendar.form === form) {
				calendar.clear();
				calendar.panel.hidden = false;
				calendar.render();
			}
		});
	}

	function init() {
		initBookings();
		initCalendars();
	}

	document.addEventListener('DOMContentLoaded', init);
	document.addEventListener('wpcf7submit', function () {
		clearAvailabilityCache();
	});

	document.addEventListener('wpcf7mailsent', function (event) {
		clearAvailabilityCache();
		if (event && event.target) {
			resetForm(event.target);
		}
	});
})();
