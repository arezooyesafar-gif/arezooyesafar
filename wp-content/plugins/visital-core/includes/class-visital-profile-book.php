<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Visital_Profile_Book' ) ) :

class Visital_Profile_Book {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_footer', [ $this, 'render' ], 30 );
	}

	public function render() {
		if ( is_admin() || ! is_singular( 'specialist' ) ) {
			return;
		}
		if ( ! class_exists( '\DrPlus\Model\Specialists' ) || ! class_exists( '\DrPlus\Utils\Booking' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		$specialist = \DrPlus\Model\Specialists::query()->where( 'post_id', $post_id )->first();
		if ( empty( $specialist ) || empty( $specialist->id ) ) {
			return;
		}

		if ( ! \DrPlus\Utils::is_wc_active() || ! \DrPlus\Utils\Booking::is_booking_active() ) {
			return;
		}

		$offline = \DrPlus\Utils::to_bool( $specialist->offline_visit );
		$online  = \DrPlus\Utils::to_bool( $specialist->online_visit );
		if ( ! $offline && ! $online ) {
			return;
		}

		if ( class_exists( '\DrPlus\Utils\SubscriptionPlans' )
			&& ! \DrPlus\Utils\SubscriptionPlans::is_specialist_plan_active( $specialist->user_id ) ) {
			return;
		}

		if ( ! apply_filters( 'visital/profile_book/enabled', true, $specialist ) ) {
			return;
		}

		$buttons = [];

		if ( $offline ) {
			$buttons[] = [
				'url'     => \DrPlus\Utils\Booking::get_booking_page_url( 'time?sid=' . $specialist->id ),
				'label'   => __( 'رزرو نوبت', 'visital-core' ),
				'variant' => 'primary',
			];
		}

		if ( $online ) {
			$buttons[] = [
				'url'     => add_query_arg(
					[ 'sid' => $specialist->id, 'consultation' => 1 ],
					\DrPlus\Utils\Booking::get_booking_page_url( 'time' )
				),
				'label'   => __( 'مشاوره آنلاین', 'visital-core' ),
				'variant' => $offline ? 'secondary' : 'primary',
			];
		}

		if ( empty( $buttons ) ) {
			return;
		}

		$price_html = $this->starting_price_html( $specialist );

		echo '<div id="visital-book-bar" class="visital-book-bar" role="complementary" aria-label="' . esc_attr__( 'رزرو نوبت', 'visital-core' ) . '">';
		echo '<div class="visital-book-bar-inner">';

		if ( $price_html ) {
			echo '<div class="visital-book-bar-price">' . $price_html . '</div>';
		}

		echo '<div class="visital-book-bar-actions">';
		foreach ( $buttons as $button ) {
			printf(
				'<a href="%s" class="visital-book-btn visital-book-btn--%s"><span>%s</span></a>',
				esc_url( $button['url'] ),
				esc_attr( $button['variant'] ),
				esc_html( $button['label'] )
			);
		}
		echo '</div>';

		echo '</div>';
		echo '</div>';

		$this->styles();
		$this->guard_script();
	}

	private function starting_price_html( $specialist ) {
		$offices = is_array( $specialist->offices ) ? $specialist->offices : [];
		$prices  = [];
		foreach ( $offices as $office ) {
			if ( ! is_array( $office ) ) {
				$office = (array) $office;
			}
			if ( ( $office['type'] ?? '' ) === 'consultation' ) {
				continue;
			}
			if ( ! \DrPlus\Utils::to_bool( $office['enable_booking'] ?? 1 ) ) {
				continue;
			}
			if ( isset( $office['visit_price'] ) && is_numeric( $office['visit_price'] ) && (int) $office['visit_price'] > 0 ) {
				$prices[] = (int) $office['visit_price'];
			}
		}
		if ( empty( $prices ) ) {
			return '';
		}

		$min = min( $prices );
		if ( class_exists( '\DrPlus\Utils\Formatters' ) ) {
			$amount = \DrPlus\Utils\Formatters::price( $min );
		} else {
			$amount = number_format_i18n( $min );
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$unit     = in_array( $currency, [ 'IRR', 'IRT', 'IRHR', 'IRHT' ], true ) ? __( 'تومان', 'visital-core' ) : '';

		return sprintf(
			'<span class="visital-book-bar-price-label">%s</span><span class="visital-book-bar-price-value">%s %s</span>',
			esc_html__( 'شروع از', 'visital-core' ),
			esc_html( $amount ),
			esc_html( $unit )
		);
	}

	private function styles() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style id="visital-book-bar-css">
			#visital-book-bar{position:fixed;inset-inline:0;bottom:0;z-index:9990;background:#fff;border-top:1px solid rgba(0,0,0,.08);box-shadow:0 -6px 24px rgba(0,0,0,.08);padding:10px 16px;padding-bottom:calc(10px + env(safe-area-inset-bottom));direction:rtl}
			#visital-book-bar .visital-book-bar-inner{max-width:1140px;margin:0 auto;display:flex;align-items:center;gap:16px;justify-content:space-between}
			#visital-book-bar .visital-book-bar-price{display:flex;flex-direction:column;line-height:1.4;white-space:nowrap}
			#visital-book-bar .visital-book-bar-price-label{font-size:12px;color:#8a8a8a}
			#visital-book-bar .visital-book-bar-price-value{font-size:15px;font-weight:700;color:#222}
			#visital-book-bar .visital-book-bar-actions{display:flex;gap:10px;flex:1;justify-content:flex-end}
			#visital-book-bar .visital-book-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 26px;border-radius:12px;font-size:16px;font-weight:700;text-decoration:none;transition:filter .15s ease, background .15s ease}
			#visital-book-bar .visital-book-btn--primary{background:#0c9989;color:#fff}
			#visital-book-bar .visital-book-btn--primary:hover{filter:brightness(1.07)}
			#visital-book-bar .visital-book-btn--secondary{background:#fff;color:#0c9989;border:1px solid #0c9989}
			#visital-book-bar .visital-book-btn--secondary:hover{background:rgba(12,153,137,.08)}
			@media(max-width:600px){
				#visital-book-bar{padding:8px 12px;padding-bottom:calc(8px + env(safe-area-inset-bottom))}
				#visital-book-bar .visital-book-bar-inner{gap:10px}
				#visital-book-bar .visital-book-btn{flex:1;padding:0 12px;min-height:46px;font-size:15px}
				#visital-book-bar .visital-book-bar-price-value{font-size:14px}
			}
			body.visital-has-book-bar{padding-bottom:88px}
		</style>
		<?php
	}

	private function guard_script() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script>
		(function(){
			var bar=document.getElementById('visital-book-bar');
			if(!bar){return;}
			var native=document.getElementById('specialist_booking-btn')||document.getElementById('specialist_consultation-btn');
			if(native){bar.parentNode.removeChild(bar);return;}
			document.body.classList.add('visital-has-book-bar');
		})();
		</script>
		<?php
	}
}

endif;
