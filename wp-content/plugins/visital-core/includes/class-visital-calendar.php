<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visital_Calendar {

	const CACHE_TTL = 600;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_visital_day_capacity', [ $this, 'ajax_day_capacity' ] );
		add_action( 'wp_ajax_nopriv_visital_day_capacity', [ $this, 'ajax_day_capacity' ] );
	}

	public function ajax_day_capacity() {
		check_ajax_referer( 'visital_day_capacity', 'nonce' );

		$specialist_id = isset( $_POST['specialist'] ) ? absint( $_POST['specialist'] ) : 0;
		$office_id     = isset( $_POST['office'] ) ? sanitize_text_field( wp_unslash( $_POST['office'] ) ) : '';
		$raw_dates     = isset( $_POST['dates'] ) ? (array) $_POST['dates'] : [];

		if ( empty( $specialist_id ) || '' === $office_id || empty( $raw_dates ) ) {
			wp_send_json_error( [ 'message' => 'invalid_request' ] );
		}

		if ( ! class_exists( 'Visital_Availability' ) ) {
			wp_send_json_error( [ 'message' => 'service_unavailable' ] );
		}

		$dates = [];
		foreach ( array_slice( $raw_dates, 0, 45 ) as $date ) {
			$date = sanitize_text_field( wp_unslash( $date ) );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$dates[] = $date;
			}
		}
		$dates = array_values( array_unique( $dates ) );
		if ( empty( $dates ) ) {
			wp_send_json_error( [ 'message' => 'no_dates' ] );
		}

		$service  = Visital_Availability::instance();
		$response = [];

		foreach ( $dates as $date ) {
			$cache_key = 'visital_cap_' . $specialist_id . '_' . md5( $office_id . '_' . $date );
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				$response[ $date ] = (int) $cached;
				continue;
			}

			$remaining = (int) $service->get_day_capacity( $specialist_id, $office_id, $date );
			set_transient( $cache_key, $remaining, self::CACHE_TTL );
			$response[ $date ] = $remaining;
		}

		wp_send_json_success( $response );
	}
}
