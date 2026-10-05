<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visital_Claim {

	const DONE_META       = 'visital_claim_done';
	const PENDING_META    = 'visital_claim_pending_link';
	const CLAIMED_META    = 'visital_claimed';
	const CLAIMANT_META   = 'visital_claimed_by';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'drplus/onboard/styles', [ $this, 'onboard_styles' ] );
		add_filter( 'drplus/onboard/scripts', [ $this, 'onboard_scripts' ] );
		add_action( 'drplus/onboard/before_form', [ $this, 'render_gate' ] );

		add_action( 'wp_ajax_visital_claim_lookup', [ $this, 'ajax_lookup' ] );
		add_action( 'wp_ajax_visital_claim_send_otp', [ $this, 'ajax_send_otp' ] );
		add_action( 'wp_ajax_visital_claim_verify', [ $this, 'ajax_verify' ] );
	}

	private function specialists_table() {
		global $wpdb;
		return $wpdb->prefix . 'drplus_specialists';
	}

	private function current_step() {
		if ( ! empty( $_GET['step'] ) ) {
			return sanitize_key( wp_unslash( $_GET['step'] ) );
		}
		if ( class_exists( '\DrPlus\Utils\Onboard' ) ) {
			return \DrPlus\Utils\Onboard::get_user_step();
		}
		return 'personal';
	}

	public function should_gate() {
		if ( ! class_exists( '\DrPlus\Utils\Onboard' ) || ! \DrPlus\Utils\Onboard::is_onboard() ) {
			return false;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( get_user_meta( $user_id, self::DONE_META, true ) ) {
			return false;
		}
		if ( 'personal' !== $this->current_step() ) {
			return false;
		}
		if ( class_exists( '\DrPlus\Utils\UtilsSpecialists' ) ) {
			$specialist = \DrPlus\Utils\UtilsSpecialists::get_by_user_id( $user_id );
			if ( $specialist && in_array( $specialist->status, [ 'active', 'pending', 'rejected' ], true ) ) {
				update_user_meta( $user_id, self::DONE_META, 1 );
				return false;
			}
		}
		return true;
	}

	public function onboard_styles( $styles ) {
		if ( ! $this->should_gate() ) {
			return $styles;
		}
		wp_register_style( 'visital-core', VISITAL_CORE_URI . 'assets/css/visital-core.css', [], VISITAL_CORE_VERSION );
		$styles[] = 'visital-core';
		return $styles;
	}

	public function onboard_scripts( $scripts ) {
		if ( ! $this->should_gate() ) {
			return $scripts;
		}
		wp_register_script( 'visital-claim', VISITAL_CORE_URI . 'assets/js/visital-claim.js', [ 'jquery' ], VISITAL_CORE_VERSION, true );
		wp_localize_script( 'visital-claim', 'visitalClaim', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'visital_claim' ),
			'i18n'    => [
				'lookupError'   => esc_html__( 'بررسی کد نظام ممکن نشد. دوباره تلاش کنید.', 'visital-core' ),
				'otpError'      => esc_html__( 'ارسال کد تایید ممکن نشد. دوباره تلاش کنید.', 'visital-core' ),
				'verifyError'   => esc_html__( 'کد تایید نادرست است.', 'visital-core' ),
				'emptyCode'     => esc_html__( 'شماره نظام پزشکی را وارد کنید.', 'visital-core' ),
				'emptyMobile'   => esc_html__( 'شماره موبایل را وارد کنید.', 'visital-core' ),
				'emptyOtp'      => esc_html__( 'کد تایید را وارد کنید.', 'visital-core' ),
			],
		] );
		$scripts[] = 'visital-claim';
		return $scripts;
	}

	public function render_gate() {
		if ( ! $this->should_gate() ) {
			return;
		}
		?>
		<div class="visital-claim-gate" data-visital-claim-gate>
			<div class="visital-claim-box">
				<h2 class="visital-claim-title"><?php esc_html_e( 'احراز هویت پزشک', 'visital-core' ); ?></h2>
				<p class="visital-claim-lead"><?php esc_html_e( 'برای شروع، شماره نظام پزشکی خود را وارد کنید.', 'visital-core' ); ?></p>

				<div class="visital-claim-step" data-step="code">
					<label class="visital-claim-label" for="visital-claim-code"><?php esc_html_e( 'شماره نظام پزشکی', 'visital-core' ); ?></label>
					<input type="text" id="visital-claim-code" class="visital-claim-input" inputmode="numeric" autocomplete="off">
					<button type="button" class="visital-claim-btn" data-action="lookup"><?php esc_html_e( 'بررسی', 'visital-core' ); ?></button>
					<div class="visital-claim-message" data-role="code-message"></div>
				</div>

				<div class="visital-claim-step" data-step="mobile" hidden>
					<div class="visital-claim-match" data-role="match"></div>
					<label class="visital-claim-label" for="visital-claim-mobile"><?php esc_html_e( 'شماره موبایل', 'visital-core' ); ?></label>
					<input type="text" id="visital-claim-mobile" class="visital-claim-input" inputmode="tel" placeholder="09..." autocomplete="tel">
					<button type="button" class="visital-claim-btn" data-action="send-otp"><?php esc_html_e( 'ارسال کد تایید', 'visital-core' ); ?></button>
					<div class="visital-claim-message" data-role="mobile-message"></div>
				</div>

				<div class="visital-claim-step" data-step="otp" hidden>
					<label class="visital-claim-label" for="visital-claim-otp"><?php esc_html_e( 'کد تایید پیامک‌شده', 'visital-core' ); ?></label>
					<input type="text" id="visital-claim-otp" class="visital-claim-input" inputmode="numeric" autocomplete="one-time-code">
					<button type="button" class="visital-claim-btn" data-action="verify"><?php esc_html_e( 'تایید و ادامه', 'visital-core' ); ?></button>
					<div class="visital-claim-message" data-role="otp-message"></div>
				</div>
			</div>
		</div>
		<?php
	}

	private function find_by_code( $code ) {
		global $wpdb;
		$code = trim( $code );
		if ( '' === $code ) {
			return null;
		}
		$table = $this->specialists_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, user_id, post_id, name FROM {$table} WHERE specialist_code = %s LIMIT 1", $code )
		);
		return $row ?: null;
	}

	private function lookup_status( $code, $user_id ) {
		$row = $this->find_by_code( $code );
		if ( ! $row ) {
			return [ 'status' => 'not_found', 'name' => '' ];
		}

		$name = $row->name;
		if ( empty( $name ) && ! empty( $row->post_id ) ) {
			$name = get_the_title( $row->post_id );
		}

		if ( (int) $row->user_id === (int) $user_id ) {
			return [ 'status' => 'owned', 'name' => $name ];
		}

		$claimed_by = $row->post_id ? (int) get_post_meta( $row->post_id, self::CLAIMANT_META, true ) : 0;
		if ( $claimed_by && $claimed_by !== (int) $user_id ) {
			return [ 'status' => 'already_claimed', 'name' => $name ];
		}

		return [ 'status' => 'claimable', 'name' => $name ];
	}

	public function ajax_lookup() {
		check_ajax_referer( 'visital_claim', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( [ 'message' => 'not_logged_in' ] );
		}
		$code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		if ( class_exists( '\DrPlus\Utils' ) ) {
			$code = \DrPlus\Utils::convert_chars( $code );
		}
		$result = $this->lookup_status( $code, $user_id );
		$result['messages'] = [
			'claimable'       => sprintf( esc_html__( 'پروفایلی با این کد یافت شد: %s. اگر متعلق به شماست، ادامه دهید.', 'visital-core' ), $result['name'] ),
			'owned'           => esc_html__( 'این پروفایل از قبل به حساب شما متصل است.', 'visital-core' ),
			'already_claimed' => esc_html__( 'این کد قبلاً توسط کاربر دیگری ثبت شده است. با پشتیبانی تماس بگیرید.', 'visital-core' ),
			'not_found'       => esc_html__( 'پروفایلی با این کد یافت نشد؛ می‌توانید پروفایل جدید بسازید.', 'visital-core' ),
		];
		wp_send_json_success( $result );
	}

	public function ajax_send_otp() {
		check_ajax_referer( 'visital_claim', 'nonce' );
		if ( ! get_current_user_id() ) {
			wp_send_json_error( [ 'message' => 'not_logged_in' ] );
		}
		if ( ! class_exists( '\DrPlus\SMS\SMS' ) || ! class_exists( '\DrPlus\Utils\Sanitizers' ) ) {
			wp_send_json_error( [ 'message' => 'sms_unavailable' ] );
		}
		$mobile = \DrPlus\Utils\Sanitizers::phone( isset( $_POST['mobile'] ) ? wp_unslash( $_POST['mobile'] ) : '' );
		if ( empty( $mobile ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'شماره موبایل نامعتبر است.', 'visital-core' ) ] );
		}
		$send = \DrPlus\SMS\SMS::send( $mobile, 'auth.login' );
		if ( is_wp_error( $send ) ) {
			wp_send_json_error( [ 'message' => $send->get_error_message() ] );
		}
		if ( empty( $send ) && ! ( defined( 'DRPLUS_IS_LOCAL' ) && DRPLUS_IS_LOCAL ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'ارسال پیامک ناموفق بود.', 'visital-core' ) ] );
		}
		wp_send_json_success( [ 'sent' => true ] );
	}

	public function ajax_verify() {
		check_ajax_referer( 'visital_claim', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( [ 'message' => 'not_logged_in' ] );
		}
		if ( ! class_exists( '\DrPlus\Model\OTP' ) || ! class_exists( '\DrPlus\Utils\Sanitizers' ) ) {
			wp_send_json_error( [ 'message' => 'otp_unavailable' ] );
		}

		$mobile = \DrPlus\Utils\Sanitizers::phone( isset( $_POST['mobile'] ) ? wp_unslash( $_POST['mobile'] ) : '' );
		$otp    = \DrPlus\Utils\Sanitizers::otp( isset( $_POST['otp'] ) ? wp_unslash( $_POST['otp'] ) : '' );
		$code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		if ( class_exists( '\DrPlus\Utils' ) ) {
			$code = \DrPlus\Utils::convert_chars( $code );
		}

		$now = class_exists( '\DrPlus\Utils\Date' )
			? new \DateTime( \DrPlus\Utils\Date::maybe_j2g( wp_date( 'Y-m-d H:i:s' ) ) )
			: new \DateTime( 'now' );

		$find_otp = \DrPlus\Model\OTP::query()->where( [
			[ 'mobile', $mobile ],
			[ 'otp', $otp ],
			[ 'expire', '>', $now ],
		] )->first();

		if ( ! $find_otp ) {
			wp_send_json_error( [ 'message' => esc_html__( 'کد تایید نادرست یا منقضی است.', 'visital-core' ) ] );
		}

		$status = $this->lookup_status( $code, $user_id );
		if ( 'already_claimed' === $status['status'] ) {
			wp_send_json_error( [ 'message' => esc_html__( 'این کد قبلاً ثبت شده است.', 'visital-core' ) ] );
		}

		if ( 'claimable' === $status['status'] ) {
			$linked = $this->link_profile( $user_id, $code );
			if ( is_wp_error( $linked ) ) {
				update_user_meta( $user_id, self::PENDING_META, $code );
			}
		}

		if ( ! empty( $code ) ) {
			update_user_meta( $user_id, 'specialist_code', $code );
		}
		update_user_meta( $user_id, 'visital_claim_mobile', $mobile );
		update_user_meta( $user_id, self::DONE_META, 1 );
		$find_otp->delete();

		wp_send_json_success( [ 'reload' => true ] );
	}

	private function link_profile( $user_id, $code ) {
		global $wpdb;
		$target = $this->find_by_code( $code );
		if ( ! $target ) {
			return new WP_Error( 'no_target', 'target not found' );
		}
		if ( (int) $target->user_id === (int) $user_id ) {
			return true;
		}
		$claimed_by = $target->post_id ? (int) get_post_meta( $target->post_id, self::CLAIMANT_META, true ) : 0;
		if ( $claimed_by ) {
			return new WP_Error( 'already_claimed', 'already claimed' );
		}

		$table = $this->specialists_table();
		$own   = $wpdb->get_row( $wpdb->prepare( "SELECT id, post_id, status FROM {$table} WHERE user_id = %d LIMIT 1", $user_id ) );

		if ( $own && (int) $own->id !== (int) $target->id ) {
			if ( ! $this->is_row_empty( $own ) ) {
				return new WP_Error( 'existing_profile', 'current user already has a profile' );
			}
			if ( ! empty( $own->post_id ) ) {
				$own_post = get_post( $own->post_id );
				if ( $own_post && '' === trim( (string) $own_post->post_content ) ) {
					wp_trash_post( $own->post_id );
				}
			}
			$wpdb->delete( $table, [ 'id' => (int) $own->id ] );
		}

		$updated = $wpdb->update( $table, [ 'user_id' => (int) $user_id ], [ 'id' => (int) $target->id ] );
		if ( false === $updated ) {
			return new WP_Error( 'link_failed', 'could not re-point profile' );
		}

		if ( ! empty( $target->post_id ) ) {
			wp_update_post( [ 'ID' => (int) $target->post_id, 'post_author' => (int) $user_id ] );
			update_post_meta( $target->post_id, self::CLAIMED_META, 1 );
			update_post_meta( $target->post_id, self::CLAIMANT_META, (int) $user_id );
		}

		if ( class_exists( '\DrPlus\Utils\UtilsSpecialists' ) && method_exists( '\DrPlus\Utils\UtilsSpecialists', 'clear_cache' ) ) {
			\DrPlus\Utils\UtilsSpecialists::clear_cache( $target->id );
		}

		return true;
	}

	private function is_row_empty( $row ) {
		global $wpdb;
		$status = isset( $row->status ) ? $row->status : '';
		if ( ! in_array( $status, [ '', 'incomplete', 'pending', 'draft' ], true ) ) {
			return false;
		}
		$booking_table = $wpdb->prefix . 'drplus_booking';
		$bookings = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$booking_table} WHERE specialist_id = %d", (int) $row->id ) );
		return 0 === $bookings;
	}
}
