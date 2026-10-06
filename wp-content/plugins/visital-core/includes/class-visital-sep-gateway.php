<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'plugins_loaded', 'visital_sep_init_gateway', 11 );

function visital_sep_init_gateway() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) || class_exists( 'WC_Gateway_Visital_SEP' ) ) {
		return;
	}

	class WC_Gateway_Visital_SEP extends WC_Payment_Gateway {

		const TOKEN_URL  = 'https://sep.shaparak.ir/OnlinePG/OnlinePG';
		const VERIFY_URL = 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTranscation';

		public function __construct() {
			$this->id                 = 'visital_sep';
			$this->method_title       = 'درگاه پرداخت سپ (سامان)';
			$this->method_description = 'اتصال به درگاه اینترنتی سپ / بانک سامان';
			$this->has_fields         = false;
			$this->icon               = apply_filters( 'visital/sep/icon', '' );

			$this->init_form_fields();
			$this->init_settings();

			$this->title               = $this->get_option( 'title' );
			$this->description         = $this->get_option( 'description' );
			$this->terminal_id         = trim( $this->get_option( 'terminal_id' ) );
			$this->multisettle_enabled = 'yes' === $this->get_option( 'multisettle_enabled' );
			$this->platform_iban       = self::normalize_iban( $this->get_option( 'platform_iban' ) );
			$this->purchase_id         = trim( (string) $this->get_option( 'purchase_id' ) );
			if ( '' === $this->purchase_id ) {
				$this->purchase_id = '0';
			}

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
			add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'receipt_page' ] );
			add_action( 'woocommerce_api_' . $this->id, [ $this, 'handle_callback' ] );
		}

		public function init_form_fields() {
			$this->form_fields = [
				'enabled'     => [
					'title'   => 'فعال‌سازی',
					'type'    => 'checkbox',
					'label'   => 'فعال‌سازی درگاه پرداخت سپ (سامان)',
					'default' => 'no',
				],
				'title'       => [
					'title'   => 'عنوان',
					'type'    => 'text',
					'default' => 'پرداخت اینترنتی سپ (بانک سامان)',
				],
				'description' => [
					'title'   => 'توضیحات',
					'type'    => 'textarea',
					'default' => 'پرداخت امن از طریق درگاه سپ / بانک سامان',
				],
				'terminal_id' => [
					'title'       => 'شماره ترمینال',
					'type'        => 'text',
					'description' => 'شماره ترمینال دریافتی از بانک سامان',
					'default'     => '',
				],
				'multisettle_enabled' => [
					'title'       => 'تسهیم (تسویه به چند حساب)',
					'type'        => 'checkbox',
					'label'       => 'سهم پزشک لحظهٔ پرداخت مستقیم به حسابش واریز شود',
					'default'     => 'no',
					'description' => 'فقط وقتی فعال کن که شبای پزشکان و پلتفرم نزد سپ ثبت و تایید شده باشد. اگر اطلاعات کامل نباشد، پرداخت به‌صورت عادی انجام می‌شود.',
				],
				'platform_iban' => [
					'title'       => 'شبای پلتفرم (برای کارمزد)',
					'type'        => 'text',
					'description' => 'شمارهٔ شبای حساب پلتفرم که کارمزد به آن واریز می‌شود (با یا بدون IR).',
					'default'     => '',
				],
				'purchase_id' => [
					'title'       => 'شناسهٔ تسهیم (PurchaseId)',
					'type'        => 'text',
					'description' => 'برای تراکنش‌های عادی معمولاً 0 است. اگر سپ مقدار دیگری خواست، همان را وارد کن.',
					'default'     => '0',
				],
			];
		}

		private static function normalize_iban( $iban ) {
			$iban = strtoupper( preg_replace( '/\s+/', '', (string) $iban ) );
			$iban = preg_replace( '/[^0-9A-Z]/', '', $iban );
			if ( preg_match( '/^\d{24}$/', $iban ) ) {
				$iban = 'IR' . $iban;
			}
			return $iban;
		}

		private function doctor_iban( $user_id ) {
			$user_id = (int) $user_id;
			$iban    = apply_filters( 'visital/sep/doctor_iban', '', $user_id );
			if ( empty( $iban ) ) {
				$iban = get_user_meta( $user_id, 'visital_sep_iban', true );
			}
			if ( empty( $iban ) ) {
				$accounts = get_user_meta( $user_id, '_sheyda_wallet_financial_accounts', true );
				if ( is_array( $accounts ) ) {
					foreach ( $accounts as $account ) {
						$values = is_array( $account ) ? $account : [ $account ];
						foreach ( $values as $value ) {
							$candidate = self::normalize_iban( $value );
							if ( preg_match( '/^IR\d{24}$/', $candidate ) ) {
								$iban = $candidate;
								break 2;
							}
						}
					}
				}
			}
			return self::normalize_iban( $iban );
		}

		private function build_settlement( $order, $total_rial ) {
			if ( ! $this->multisettle_enabled ) {
				return null;
			}

			$book_data = $order->get_meta( '_booking_data' );
			if ( empty( $book_data ) || ! is_array( $book_data ) ) {
				return null;
			}

			$specialist_income = isset( $book_data['specialist_income'] ) ? (int) round( (float) $book_data['specialist_income'] ) : 0;
			$commission        = isset( $book_data['commission_value'] ) ? (int) round( (float) $book_data['commission_value'] ) : 0;
			$calc_type         = $book_data['commission_calculate_type'] ?? '';

			$doctor_rial   = $this->to_rial( $specialist_income );
			$platform_rial = 'add_to_customer_order' === $calc_type ? $this->to_rial( $commission ) : 0;

			$specialist_id = isset( $book_data['specialist_id'] ) ? (int) $book_data['specialist_id'] : 0;
			$user_id       = 0;
			if ( $specialist_id && class_exists( '\DrPlus\Model\Specialists' ) ) {
				$sp = \DrPlus\Model\Specialists::query()->select( 'user_id' )->where( 'id', $specialist_id )->first();
				if ( $sp && ! empty( $sp->user_id ) ) {
					$user_id = (int) $sp->user_id;
				}
			}
			$doctor_iban = $user_id ? $this->doctor_iban( $user_id ) : '';

			$lines = [];
			if ( $doctor_rial > 0 ) {
				if ( ! preg_match( '/^IR\d{24}$/', $doctor_iban ) ) {
					$order->add_order_note( 'تسهیم انجام نشد: شبای معتبر برای پزشک یافت نشد. پرداخت عادی انجام شد.' );
					return null;
				}
				$lines[] = [
					'IBAN'       => $doctor_iban,
					'Amount'     => (string) $doctor_rial,
					'PurchaseId' => $this->purchase_id,
				];
			}
			if ( $platform_rial > 0 ) {
				if ( ! preg_match( '/^IR\d{24}$/', $this->platform_iban ) ) {
					$order->add_order_note( 'تسهیم انجام نشد: شبای پلتفرم تنظیم نشده است. پرداخت عادی انجام شد.' );
					return null;
				}
				$lines[] = [
					'IBAN'       => $this->platform_iban,
					'Amount'     => (string) $platform_rial,
					'PurchaseId' => $this->purchase_id,
				];
			}

			if ( empty( $lines ) || count( $lines ) > 9 ) {
				return null;
			}
			if ( ( $doctor_rial + $platform_rial ) !== (int) $total_rial ) {
				$order->add_order_note( 'تسهیم انجام نشد: جمع سهم‌ها با مبلغ سفارش برابر نیست. پرداخت عادی انجام شد.' );
				return null;
			}

			return $lines;
		}

		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			return [
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( true ),
			];
		}

		private function to_rial( $amount ) {
			$rial = (int) round( (float) $amount );
			if ( 'IRR' !== get_woocommerce_currency() ) {
				$rial *= 10;
			}
			return (int) apply_filters( 'visital/sep/amount_rial', $rial, $amount );
		}

		private function back_button( $order ) {
			return '<p><a class="button" href="' . esc_url( $order->get_checkout_payment_url() ) . '">بازگشت و تلاش مجدد</a></p>';
		}

		public function receipt_page( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}

			if ( empty( $this->terminal_id ) ) {
				echo '<p>شماره ترمینال درگاه تنظیم نشده است.</p>';
				return;
			}

			$amount  = $this->to_rial( $order->get_total() );
			$payload = [
				'Action'      => 'Token',
				'TerminalId'  => $this->terminal_id,
				'RedirectUrl' => WC()->api_request_url( $this->id ),
				'ResNum'      => (string) $order_id,
				'Amount'      => $amount,
				'CellNumber'  => $order->get_billing_phone(),
			];

			$settlement = $this->build_settlement( $order, $amount );
			if ( ! empty( $settlement ) ) {
				$payload['SettlementIbanInfo'] = $settlement;
			}

			$body = wp_json_encode( $payload );

			$response = wp_remote_post( self::TOKEN_URL, [
				'timeout' => 30,
				'headers' => [ 'content-type' => 'application/json' ],
				'body'    => $body,
			] );

			if ( is_wp_error( $response ) ) {
				echo '<p>خطا در اتصال به درگاه: ' . esc_html( $response->get_error_message() ) . '</p>';
				echo $this->back_button( $order );
				return;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $data->token ) && ( ! isset( $data->status ) || 1 === (int) $data->status ) ) {
				$order->update_meta_data( '_visital_sep_token', sanitize_text_field( $data->token ) );
				$order->save();

				echo '<p>در حال انتقال به درگاه پرداخت...</p>';
				echo '<form action="' . esc_url( self::TOKEN_URL ) . '" method="post" id="visital-sep-form">';
				echo '<input type="hidden" name="Token" value="' . esc_attr( $data->token ) . '" />';
				echo '<noscript><input type="submit" value="ادامه پرداخت" /></noscript>';
				echo '</form>';
				echo '<script type="text/javascript">document.getElementById("visital-sep-form").submit();</script>';
				return;
			}

			$message = '';
			if ( isset( $data->errorDesc ) && '' !== $data->errorDesc ) {
				$message = $data->errorDesc;
			} elseif ( isset( $data->errorCode ) ) {
				$message = 'کد خطای درگاه: ' . $data->errorCode;
			} else {
				$message = 'دریافت توکن از درگاه ناموفق بود.';
			}

			$order->add_order_note( 'دریافت توکن سپ ناموفق: ' . $message );
			echo '<p>' . esc_html( $message ) . '</p>';
			echo $this->back_button( $order );
		}

		public function handle_callback() {
			$resnum = isset( $_POST['ResNum'] ) ? sanitize_text_field( wp_unslash( $_POST['ResNum'] ) ) : '';
			$refnum = isset( $_POST['RefNum'] ) ? sanitize_text_field( wp_unslash( $_POST['RefNum'] ) ) : '';
			$state  = isset( $_POST['State'] ) ? sanitize_text_field( wp_unslash( $_POST['State'] ) ) : '';

			$order = $resnum ? wc_get_order( (int) $resnum ) : false;
			if ( ! $order ) {
				wp_die( 'سفارش یافت نشد.', 'سپ', [ 'response' => 200 ] );
			}

			if ( $order->is_paid() ) {
				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;
			}

			if ( 'OK' !== strtoupper( $state ) || '' === $refnum ) {
				$order->update_status( 'failed', 'پرداخت ناموفق یا لغو شده توسط کاربر. وضعیت: ' . $state );
				wc_add_notice( 'پرداخت انجام نشد یا توسط شما لغو شد.', 'error' );
				wp_safe_redirect( $order->get_checkout_payment_url() );
				exit;
			}

			$verify = wp_remote_post( self::VERIFY_URL, [
				'timeout' => 30,
				'headers' => [ 'content-type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'TerminalNumber' => $this->terminal_id,
					'RefNum'         => $refnum,
				] ),
			] );

			if ( is_wp_error( $verify ) ) {
				$order->update_status( 'failed', 'خطا در تایید تراکنش سپ: ' . $verify->get_error_message() );
				wc_add_notice( 'خطا در تایید تراکنش. اگر مبلغ کسر شده، طی ۷۲ ساعت بازمی‌گردد.', 'error' );
				wp_safe_redirect( $order->get_checkout_payment_url() );
				exit;
			}

			$result = json_decode( wp_remote_retrieve_body( $verify ) );
			$ok     = is_object( $result ) && isset( $result->ResultCode ) && 0 === (int) $result->ResultCode;

			$paid_amount = null;
			if ( is_object( $result ) && isset( $result->TransactionDetail ) ) {
				$detail = $result->TransactionDetail;
				if ( isset( $detail->AffectiveAmount ) ) {
					$paid_amount = (int) $detail->AffectiveAmount;
				} elseif ( isset( $detail->OrignalAmount ) ) {
					$paid_amount = (int) $detail->OrignalAmount;
				}
			}

			$expected = $this->to_rial( $order->get_total() );

			if ( $ok && ( null === $paid_amount || $paid_amount === $expected ) ) {
				$order->payment_complete( $refnum );
				$rrn = ( isset( $result->TransactionDetail->RRN ) ) ? $result->TransactionDetail->RRN : '';
				$order->add_order_note( 'پرداخت سپ موفق بود. شماره مرجع: ' . $refnum . ( $rrn ? ' | RRN: ' . $rrn : '' ) );
				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;
			}

			if ( $ok && null !== $paid_amount && $paid_amount !== $expected ) {
				$order->update_status( 'on-hold', 'مبلغ تاییدشده با مبلغ سفارش مغایرت دارد. پرداختی: ' . $paid_amount . ' ریال، مورد انتظار: ' . $expected . ' ریال.' );
				wc_add_notice( 'مبلغ پرداخت با سفارش هم‌خوانی ندارد. با پشتیبانی تماس بگیرید.', 'error' );
				wp_safe_redirect( $order->get_checkout_payment_url() );
				exit;
			}

			$desc = is_object( $result ) && isset( $result->ResultDescription ) ? $result->ResultDescription : 'نامشخص';
			$order->update_status( 'failed', 'تایید تراکنش سپ ناموفق بود: ' . $desc );
			wc_add_notice( 'تایید تراکنش ناموفق بود. اگر مبلغ کسر شده، طی ۷۲ ساعت بازمی‌گردد.', 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}
	}

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'WC_Gateway_Visital_SEP';
		return $gateways;
	} );
}
