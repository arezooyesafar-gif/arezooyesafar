<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visital_Cards {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public static function next_slot_badge( $specialist, $echo = true ) {
		$html = self::build_badge( $specialist );
		if ( $echo ) {
			echo $html;
		}
		return $html;
	}

	private static function build_badge( $specialist ) {
		if ( ! class_exists( 'Visital_Availability' ) ) {
			return '';
		}

		$service = Visital_Availability::instance();
		$slot    = $service->get_next_slot( $specialist );

		if ( empty( $slot ) ) {
			if ( ! apply_filters( 'visital/next_slot/show_empty', false, $specialist ) ) {
				return '';
			}
			return sprintf(
				'<div class="visital-next-slot visital-next-slot--none"><span class="visital-next-slot-value">%s</span></div>',
				esc_html__( 'No active appointment', 'visital-core' )
			);
		}

		$value = $service->human_label( $slot );

		$badge = sprintf(
			'<div class="visital-next-slot visital-next-slot--available"><i class="drplus-icon-clock-fill" aria-hidden="true"></i><span class="visital-next-slot-label">%s</span><span class="visital-next-slot-value">%s</span></div>',
			esc_html__( 'Next available slot', 'visital-core' ),
			esc_html( $value )
		);

		return apply_filters( 'visital/next_slot/badge_html', $badge, $slot, $specialist );
	}
}

if ( ! function_exists( 'visital_next_slot_badge' ) ) {
	function visital_next_slot_badge( $specialist, $echo = true ) {
		if ( ! class_exists( 'Visital_Cards' ) ) {
			return '';
		}
		return Visital_Cards::next_slot_badge( $specialist, $echo );
	}
}
