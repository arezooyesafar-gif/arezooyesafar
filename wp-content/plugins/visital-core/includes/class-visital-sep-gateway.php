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

			$this->title       = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			$this->terminal_id = trim( $this->get_option( 'terminal_id' ) );

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
			];
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

			$body = wp_json_encode( [
				'Action'      => 'Token',
				'TerminalId'  => $this->terminal_id,
				'RedirectUrl' => add_query_arg( 'wc-api', $this->id, home_url( '/' ) ),
				'ResNum'      => (string) $order_id,
				'Amount'      => $this->to_rial( $order->get_total() ),
				'CellNumber'  => $order->get_billing_phone(),
			] );

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
