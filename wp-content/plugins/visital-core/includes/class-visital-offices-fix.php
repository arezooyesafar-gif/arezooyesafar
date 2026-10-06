<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visital_Offices_Fix {

	private static $instance = null;
	private static $checked  = [];

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_drplus_get_available_times', [ $this, 'fix_from_request' ], 1 );
		add_action( 'wp_ajax_nopriv_drplus_get_available_times', [ $this, 'fix_from_request' ], 1 );
	}

	public function fix_from_request() {
		$sid = 0;
		if ( isset( $_REQUEST['specialist'] ) ) {
			$sid = absint( preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_REQUEST['specialist'] ) ) );
		}
		if ( $sid ) {
			self::fix( $sid );
		}
	}

	public static function fix( $specialist_id ) {
		$specialist_id = (int) $specialist_id;
		if ( $specialist_id <= 0 || isset( self::$checked[ $specialist_id ] ) ) {
			return;
		}
		self::$checked[ $specialist_id ] = true;

		if ( ! class_exists( '\DrPlus\Model\Specialists' ) ) {
			return;
		}

		$specialist = \DrPlus\Model\Specialists::query()->where( 'id', $specialist_id )->first();
		if ( empty( $specialist ) || empty( $specialist->offices ) || ! is_array( $specialist->offices ) ) {
			return;
		}

		$offices  = $specialist->offices;
		$rekeyed  = [];
		$changed  = false;

		foreach ( $offices as $key => $office ) {
			if ( is_array( $office ) && ! empty( $office['id'] ) ) {
				$rekeyed[ $office['id'] ] = $office;
				if ( (string) $key !== (string) $office['id'] ) {
					$changed = true;
				}
			} else {
				$rekeyed[ $key ] = $office;
			}
		}

		if ( ! $changed ) {
			return;
		}

		$specialist->offices = $rekeyed;
		$specialist->save();

		do_action( 'visital/booking/changed', $specialist_id );
	}
}
