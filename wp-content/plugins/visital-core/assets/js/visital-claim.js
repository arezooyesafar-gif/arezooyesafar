(function ($) {
	var cfg = window.visitalClaim || null;
	if (!cfg || !cfg.ajaxUrl) {
		return;
	}

	$(function () {
		var $gate = $('[data-visital-claim-gate]');
		if (!$gate.length) {
			return;
		}

		var code = '';

		function message($el, text, type) {
			$el.text(text || '').attr('data-type', type || '');
		}

		function showStep(step) {
			$gate.find('.visital-claim-step').attr('hidden', true);
			$gate.find('[data-step="' + step + '"]').removeAttr('hidden');
		}

		$gate.on('click', '[data-action="lookup"]', function () {
			code = $.trim($('#visital-claim-code').val());
			var $m = $gate.find('[data-role="code-message"]');
			if (!code) {
				message($m, cfg.i18n.emptyCode, 'error');
				return;
			}
			message($m, '', '');
			$.ajax({ url: cfg.ajaxUrl, type: 'POST', data: { action: 'visital_claim_lookup', nonce: cfg.nonce, code: code } })
				.done(function (res) {
					if (res && res.success) {
						var st = res.data.status;
						var text = ( res.data.messages && res.data.messages[st] ) ? res.data.messages[st] : '';
						if (st === 'already_claimed') {
							message($m, text, 'error');
							return;
						}
						$gate.find('[data-role="match"]').text(text);
						showStep('mobile');
					} else {
						message($m, cfg.i18n.lookupError, 'error');
					}
				})
				.fail(function () {
					message($m, cfg.i18n.lookupError, 'error');
				});
		});

		$gate.on('click', '[data-action="send-otp"]', function () {
			var mobile = $.trim($('#visital-claim-mobile').val());
			var $m = $gate.find('[data-role="mobile-message"]');
			if (!mobile) {
				message($m, cfg.i18n.emptyMobile, 'error');
				return;
			}
			message($m, '', '');
			$.ajax({ url: cfg.ajaxUrl, type: 'POST', data: { action: 'visital_claim_send_otp', nonce: cfg.nonce, mobile: mobile } })
				.done(function (res) {
					if (res && res.success) {
						showStep('otp');
					} else {
						message($m, ( res && res.data && res.data.message ) ? res.data.message : cfg.i18n.otpError, 'error');
					}
				})
				.fail(function () {
					message($m, cfg.i18n.otpError, 'error');
				});
		});

		$gate.on('click', '[data-action="verify"]', function () {
			var mobile = $.trim($('#visital-claim-mobile').val());
			var otp = $.trim($('#visital-claim-otp').val());
			var $m = $gate.find('[data-role="otp-message"]');
			if (!otp) {
				message($m, cfg.i18n.emptyOtp, 'error');
				return;
			}
			message($m, '', '');
			$.ajax({ url: cfg.ajaxUrl, type: 'POST', data: { action: 'visital_claim_verify', nonce: cfg.nonce, mobile: mobile, otp: otp, code: code } })
				.done(function (res) {
					if (res && res.success) {
						window.location.reload();
					} else {
						message($m, ( res && res.data && res.data.message ) ? res.data.message : cfg.i18n.verifyError, 'error');
					}
				})
				.fail(function () {
					message($m, cfg.i18n.verifyError, 'error');
				});
		});
	});
})(jQuery);
