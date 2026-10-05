<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visital_Assets {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ], 30 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_calendar' ], 100 );
	}

	public function enqueue_styles() {
		wp_enqueue_style(
			'visital-core',
			VISITAL_CORE_URI . 'assets/css/visital-core.css',
			[],
			VISITAL_CORE_VERSION
		);
	}

	public function enqueue_calendar() {
		if ( is_admin() ) {
			return;
		}
		if ( ! wp_script_is( 'drplus-booking', 'enqueued' ) ) {
			return;
		}

		$deps = [ 'jquery' ];
		if ( wp_script_is( 'drplus-pdp', 'registered' ) || wp_script_is( 'drplus-pdp', 'enqueued' ) ) {
			$deps[] = 'drplus-pdp';
		}
		$deps[] = 'drplus-booking';

		wp_enqueue_script(
			'visital-calendar',
			VISITAL_CORE_URI . 'assets/js/visital-calendar.js',
			$deps,
			VISITAL_CORE_VERSION,
			true
		);
	}
}
