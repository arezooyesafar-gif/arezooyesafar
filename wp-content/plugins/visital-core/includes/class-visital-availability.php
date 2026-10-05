<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visital_Availability {

	const MAX_SCAN_DAYS   = 30;
	const CACHE_TTL_FOUND = 1200;
	const CACHE_TTL_EMPTY = 600;

	private static $instance = null;
	private $request_cache = [];

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'visital/booking/changed', [ $this, 'flush_specialist' ], 10, 1 );
	}

	public static function to_bool( $value ) {
		if ( class_exists( '\DrPlus\Utils' ) ) {
			return \DrPlus\Utils::to_bool( $value );
		}
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	public function get_next_slot( $specialist ) {
		$specialist = $this->normalize_specialist( $specialist );
		if ( empty( $specialist ) || empty( $specialist->id ) ) {
			return null;
		}

		$specialist_id = (int) $specialist->id;
		if ( array_key_exists( $specialist_id, $this->request_cache ) ) {
			return $this->request_cache[ $specialist_id ];
		}

		$cache_key = 'visital_next_slot_' . $specialist_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$value                               = ( 'none' === $cached ) ? null : $cached;
			$this->request_cache[ $specialist_id ] = $value;
			return $value;
		}

		$result = $this->compute_next_slot( $specialist );

		set_transient(
			$cache_key,
			null === $result ? 'none' : $result,
			null === $result ? self::CACHE_TTL_EMPTY : self::CACHE_TTL_FOUND
		);

		$this->request_cache[ $specialist_id ] = $result;
		return $result;
	}

	private function compute_next_slot( $specialist ) {
		if ( ! class_exists( '\DrPlus\Utils\Booking' ) ) {
			return null;
		}

		$offices = is_array( $specialist->offices ) ? $specialist->offices : [];
		if ( empty( $offices ) ) {
			return null;
		}

		if ( ! $this->has_active_schedule( (int) $specialist->user_id ) ) {
			return null;
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Tehran' );
		$now      = new DateTime( 'now', $timezone );
		$best     = null;

		foreach ( $offices as $office ) {
			if ( ! is_array( $office ) ) {
				$office = (array) $office;
			}
			if ( empty( $office['id'] ) ) {
				continue;
			}
			if ( ! self::to_bool( $office['enable_booking'] ?? 0 ) ) {
				continue;
			}
			if ( 'instant_chat_consultation' === $office['id'] ) {
				continue;
			}

			$chunk = isset( $office['visit_time'] ) ? (int) $office['visit_time'] : 0;
			if ( $chunk <= 0 ) {
				continue;
			}

			$max_days = self::MAX_SCAN_DAYS;
			if ( isset( $office['max_booking_days'] ) && is_numeric( $office['max_booking_days'] ) ) {
				$max_days = max( 0, min( self::MAX_SCAN_DAYS, (int) $office['max_booking_days'] ) );
			}

			$found = $this->office_first_slot( $specialist->id, $office, $chunk, $now, $max_days, $timezone );
			if ( null === $found ) {
				continue;
			}

			if ( null === $best || $found['timestamp'] < $best['timestamp'] ) {
				$best = $found;
			}
		}

		return $best;
	}

	private function office_first_slot( $specialist_id, $office, $chunk, DateTime $now, $max_days, $timezone ) {
		for ( $i = 0; $i <= $max_days; $i++ ) {
			$day      = clone $now;
			$day->modify( '+' . $i . ' day' );
			$date_str = $day->format( 'Y-m-d' );

			$slots = \DrPlus\Utils\Booking::get_available_time_slots( $date_str, (int) $specialist_id, $office['id'], $chunk );
			if ( empty( $slots ) || ! is_array( $slots ) ) {
				continue;
			}

			foreach ( $slots as $slot ) {
				if ( empty( $slot['available'] ) ) {
					continue;
				}

				$slot_dt = DateTime::createFromFormat( 'Y-m-d H:i', $date_str . ' ' . $slot['from'], $timezone );
				if ( ! $slot_dt ) {
					continue;
				}

				return [
					'timestamp' => $slot_dt->getTimestamp(),
					'date'      => $date_str,
					'time'      => $slot['from'],
					'office_id' => $office['id'],
					'type'      => $office['type'] ?? '',
				];
			}
		}

		return null;
	}

	public function get_day_capacity( $specialist, $office_id, $date_str ) {
		$specialist = $this->normalize_specialist( $specialist );
		if ( empty( $specialist ) || empty( $specialist->id ) || ! class_exists( '\DrPlus\Utils\Booking' ) ) {
			return 0;
		}

		$offices = is_array( $specialist->offices ) ? $specialist->offices : [];
		$chunk   = 0;
		foreach ( $offices as $office ) {
			if ( ! is_array( $office ) ) {
				$office = (array) $office;
			}
			if ( ( $office['id'] ?? '' ) === $office_id ) {
				$chunk = isset( $office['visit_time'] ) ? (int) $office['visit_time'] : 0;
				break;
			}
		}
		if ( $chunk <= 0 ) {
			return 0;
		}

		$slots = \DrPlus\Utils\Booking::get_available_time_slots( $date_str, (int) $specialist->id, $office_id, $chunk );
		if ( empty( $slots ) || ! is_array( $slots ) ) {
			return 0;
		}

		$remaining = 0;
		foreach ( $slots as $slot ) {
			if ( ! empty( $slot['available'] ) ) {
				$remaining++;
			}
		}
		return $remaining;
	}

	public function human_label( $slot ) {
		if ( empty( $slot['timestamp'] ) ) {
			return '';
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'Asia/Tehran' );
		$today    = ( new DateTime( 'now', $timezone ) )->format( 'Y-m-d' );
		$tomorrow = ( new DateTime( 'now', $timezone ) )->modify( '+1 day' )->format( 'Y-m-d' );

		if ( $slot['date'] === $today ) {
			$day_label = esc_html__( 'امروز', 'visital-core' );
		} elseif ( $slot['date'] === $tomorrow ) {
			$day_label = esc_html__( 'فردا', 'visital-core' );
		} elseif ( class_exists( '\DrPlus\Utils\Date' ) ) {
			$day_label = \DrPlus\Utils\Date::jdate( 'l j F', $slot['timestamp'] );
		} else {
			$day_label = $slot['date'];
		}

		$time = $slot['time'];
		if ( class_exists( '\DrPlus\Utils\Date' ) ) {
			$time = \DrPlus\Utils\Date::tr_num( $time, 'fa' );
		}

		return sprintf( '%s %s %s', $day_label, esc_html__( 'ساعت', 'visital-core' ), $time );
	}

	public function flush_specialist( $specialist_id ) {
		$specialist_id = (int) $specialist_id;
		if ( $specialist_id > 0 ) {
			delete_transient( 'visital_next_slot_' . $specialist_id );
			unset( $this->request_cache[ $specialist_id ] );
		}
	}

	private function has_active_schedule( $user_id ) {
		if ( $user_id <= 0 ) {
			return false;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'drplus_times';
		$count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 1", $user_id )
		);
		return (int) $count > 0;
	}

	private function normalize_specialist( $specialist ) {
		if ( is_object( $specialist ) && isset( $specialist->offices ) ) {
			return $specialist;
		}
		if ( is_numeric( $specialist ) && class_exists( '\DrPlus\Model\Specialists' ) ) {
			return ( new \DrPlus\Model\Specialists() )->find( (int) $specialist );
		}
		return null;
	}
}
