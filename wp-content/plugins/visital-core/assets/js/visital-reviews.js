(function ($) {
	function recompute($root) {
		var sum = 0;
		var n = 0;
		$root.find('.visital-review-criterion').each(function () {
			var v = parseInt($(this).find('input.drplus_star-input:checked').val(), 10);
			if (!isNaN(v)) {
				sum += v;
				n += 1;
			}
		});
		var overall = n ? Math.round(sum / n) : '';
		$root.find('.visital-review-overall').val(overall);
	}

	$(function () {
		$(document).on('click', '[data-visital-review] .drplus_star', function () {
			var $root = $(this).closest('[data-visital-review]');
			setTimeout(function () {
				recompute($root);
			}, 0);
		});
	});
})(jQuery);
