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
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_reviews' ], 100 );
	}

	public function enqueue_styles() {
		wp_enqueue_style(
			'visital-core',
			VISITAL_CORE_URI . 'assets/css/visital-core.css',
			[],
			VISITAL_CORE_VERSION
		);
	}

	public function enqueue_reviews() {
		if ( is_admin() || ! is_singular( 'specialist' ) ) {
			return;
		}
		wp_enqueue_script(
			'visital-reviews',
			VISITAL_CORE_URI . 'assets/js/visital-reviews.js',
			[ 'jquery' ],
			VISITAL_CORE_VERSION,
			true
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
			wp_add_inline_script( 'drplus-pdp', $this->leap_fix_js(), 'after' );
		}
		$deps[] = 'drplus-booking';

		wp_enqueue_script(
			'visital-calendar',
			VISITAL_CORE_URI . 'assets/js/visital-calendar.js',
			$deps,
			VISITAL_CORE_VERSION,
			true
		);

		wp_localize_script(
			'visital-calendar',
			'visitalCalendar',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'visital_day_capacity' ),
				'rtl'     => is_rtl() ? 1 : 0,
				'labels'  => [
					'remaining' => esc_html__( 'خالی', 'visital-core' ),
					'full'      => esc_html__( 'تکمیل', 'visital-core' ),
				],
			]
		);
	}

	private function leap_fix_js() {
		return '(function($){if(!$||!$.fn||typeof $.fn.mjpersianDatepicker!=="function"){return;}var o=$.fn.mjpersianDatepicker;$.fn.mjpersianDatepicker=function(opt){if(opt&&typeof opt==="object"){opt.calendar=opt.calendar||{};opt.calendar.persian=opt.calendar.persian||{};if(!opt.calendar.persian.leapYearMode||opt.calendar.persian.leapYearMode==="astronomical"){opt.calendar.persian.leapYearMode="algorithmic";}}return o.apply(this,arguments);};})(jQuery);';
	}
}
