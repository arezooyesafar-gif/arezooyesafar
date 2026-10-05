(function ($) {
	if (typeof $ === 'undefined' || !$.fn || typeof $.fn.mjpersianDatepicker !== 'function') {
		return;
	}

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
})(jQuery);
