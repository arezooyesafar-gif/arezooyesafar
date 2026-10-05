(function ($) {
	if (typeof $ !== 'undefined' && $.fn && typeof $.fn.mjpersianDatepicker === 'function') {
		var original = $.fn.mjpersianDatepicker;
		$.fn.mjpersianDatepicker = function (options) {
			if (options && typeof options === 'object') {
				options.calendar = options.calendar || {};
				options.calendar.persian = options.calendar.persian || {};
				if (!options.calendar.persian.leapYearMode || options.calendar.persian.leapYearMode === 'astronomical') {
					options.calendar.persian.leapYearMode = 'algorithmic';
				}
			}
			return original.apply(this, arguments);
		};
	}

	$(function () {
		var cfg = window.visitalCalendar || null;
		if (!cfg || !cfg.ajaxUrl || !cfg.nonce) {
			return;
		}
		if (typeof window.drplusBooking === 'undefined') {
			return;
		}

		var $wrap = $('.booking-calendar-wrap');
		if (!$wrap.length) {
			return;
		}

		var specialistID = window.drplusBooking.specialistID || 0;
		var capacityCache = {};
		var pending = false;
		var timer = null;

		function currentOffice() {
			var checked = $('.booking-specialist-office-radio:checked').val();
			if (checked) {
				return checked;
			}
			if (window.drplusBooking.selectedOffice) {
				return window.drplusBooking.selectedOffice;
			}
			return '';
		}

		function unixToDate(unix) {
			var d = new Date(parseInt(unix, 10));
			if (isNaN(d.getTime())) {
				return '';
			}
			var y = d.getFullYear();
			var m = ('0' + (d.getMonth() + 1)).slice(-2);
			var day = ('0' + d.getDate()).slice(-2);
			return y + '-' + m + '-' + day;
		}

		function localizeNum(num) {
			if (!cfg.rtl) {
				return String(num);
			}
			var fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
			return String(num).replace(/\d/g, function (d) {
				return fa[parseInt(d, 10)];
			});
		}

		function badgeHtml(remaining) {
			if (remaining > 0) {
				return '<span class="visital-day-capacity visital-day-capacity--open">' + localizeNum(remaining) + ' ' + cfg.labels.remaining + '</span>';
			}
			return '<span class="visital-day-capacity visital-day-capacity--full">' + cfg.labels.full + '</span>';
		}

		function eligibleCells() {
			var cells = [];
			$wrap.find('.table-days td[data-unix]').each(function () {
				var $cell = $(this);
				var cls = ' ' + ($cell.attr('class') || '') + ' ';
				if (cls.indexOf('otherMonth') > -1 || cls.indexOf('other-month') > -1 || cls.indexOf('disabled') > -1 || cls.indexOf('empty') > -1) {
					return;
				}
				var date = unixToDate($cell.attr('data-unix'));
				if (date) {
					cells.push({ el: $cell, date: date });
				}
			});
			return cells;
		}

		function render(office, cells) {
			cells.forEach(function (item) {
				var key = office + '|' + item.date;
				if (!(key in capacityCache)) {
					return;
				}
				item.el.find('.visital-day-capacity').remove();
				item.el.append(badgeHtml(capacityCache[key]));
			});
		}

		function fetchAndRender() {
			var office = currentOffice();
			if (!office || office === 'instant_chat_consultation') {
				$wrap.find('.visital-day-capacity').remove();
				return;
			}

			var cells = eligibleCells();
			if (!cells.length) {
				return;
			}

			render(office, cells);

			var missing = [];
			cells.forEach(function (item) {
				var key = office + '|' + item.date;
				if (!(key in capacityCache) && missing.indexOf(item.date) === -1) {
					missing.push(item.date);
				}
			});

			if (!missing.length || pending) {
				return;
			}

			pending = true;
			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'visital_day_capacity',
					nonce: cfg.nonce,
					specialist: specialistID,
					office: office,
					dates: missing
				}
			}).done(function (res) {
				if (res && res.success && res.data) {
					Object.keys(res.data).forEach(function (date) {
						capacityCache[office + '|' + date] = parseInt(res.data[date], 10) || 0;
					});
					render(office, eligibleCells());
				}
			}).always(function () {
				pending = false;
			});
		}

		function schedule() {
			if (timer) {
				clearTimeout(timer);
			}
			timer = setTimeout(fetchAndRender, 250);
		}

		var target = $wrap.get(0);
		if (window.MutationObserver && target) {
			var observer = new MutationObserver(function () {
				schedule();
			});
			observer.observe(target, { childList: true, subtree: true });
		}

		$(document).on('change', '.booking-specialist-office-radio', function () {
			schedule();
		});

		schedule();
	});
})(jQuery);
