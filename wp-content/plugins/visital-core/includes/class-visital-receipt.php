<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visital_Receipt {

	const OPTION_KEY = 'visital_receipt_settings';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public static function defaults() {
		return [
			'enabled' => 0,
			'rate'    => 10,
			'label'   => 'مالیات بر ارزش افزوده',
		];
	}

	public static function get_settings() {
		$settings = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}
		return array_merge( self::defaults(), $settings );
	}

	public static function tax_split( $args ) {
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return null;
		}

		$rate = (float) $settings['rate'];
		if ( $rate <= 0 ) {
			return null;
		}

		$book_data = isset( $args['book_data'] ) && is_array( $args['book_data'] ) ? $args['book_data'] : [];
		if ( empty( $book_data ) ) {
			return null;
		}

		$calc_type = $book_data['commission_calculate_type'] ?? '';
		if ( 'add_to_customer_order' !== $calc_type ) {
			return null;
		}

		$commission = isset( $book_data['commission_value'] ) ? (int) round( $book_data['commission_value'] ) : 0;
		if ( $commission <= 0 ) {
			return null;
		}

		$base = (int) round( $commission / ( 1 + $rate / 100 ) );
		$tax  = $commission - $base;
		if ( $tax <= 0 ) {
			return null;
		}

		return [
			'base'  => $base,
			'tax'   => $tax,
			'label' => $settings['label'],
		];
	}

	public static function format_amount( $amount ) {
		return sprintf( __( '%s Toman', 'drplus' ), number_format( $amount, 0 ) );
	}

	public static function inject_tax_row( $html, $split ) {
		if ( empty( $split ) || empty( $split['tax'] ) ) {
			return $html;
		}

		$row = '<div class="drplus-booking-receipt-transaction-info-item">'
			. '<span class="drplus-booking-receipt-transaction-info-label drplus-booking-receipt-part-title">' . esc_html( $split['label'] ) . '</span>'
			. '<span class="drplus-booking-receipt-transaction-info-value">' . esc_html( self::format_amount( $split['tax'] ) ) . '</span>'
			. '</div>';

		$count = 0;
		$result = preg_replace_callback(
			'/<div class="drplus-booking-receipt-transaction-info-item">.*?<\/div>/s',
			function ( $matches ) use ( &$count, $row ) {
				$count++;
				if ( 2 === $count ) {
					return $matches[0] . $row;
				}
				return $matches[0];
			},
			$html
		);

		return null === $result ? $html : $result;
	}

	public function admin_menu() {
		add_options_page(
			esc_html__( 'رسید مالیاتی', 'visital-core' ),
			esc_html__( 'رسید مالیاتی', 'visital-core' ),
			'manage_options',
			'visital-receipt',
			[ $this, 'settings_page' ]
		);
	}

	public function register_settings() {
		register_setting(
			'visital_receipt_group',
			self::OPTION_KEY,
			[ $this, 'sanitize' ]
		);
	}

	public function sanitize( $input ) {
		$output            = self::defaults();
		$output['enabled'] = empty( $input['enabled'] ) ? 0 : 1;
		$output['rate']    = isset( $input['rate'] ) ? max( 0, min( 100, (float) $input['rate'] ) ) : 10;
		$output['label']   = isset( $input['label'] ) ? sanitize_text_field( $input['label'] ) : self::defaults()['label'];
		if ( '' === $output['label'] ) {
			$output['label'] = self::defaults()['label'];
		}
		return $output;
	}

	public function settings_page() {
		$settings = self::get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'رسید مالیاتی', 'visital-core' ); ?></h1>
			<p><?php esc_html_e( 'با فعال کردن این گزینه، مبلغ کارمزد در رسیدِ نوبت به دو خطِ جدا تقسیم می‌شود: کارمزد (بدون مالیات) و مالیات. جمعِ دو خط برابرِ همان کارمزدِ فعلی است و مبلغِ پرداختی تغییری نمی‌کند. برای اداره مالیات، مبلغِ مالیات به‌صورت جداگانه نمایش داده می‌شود.', 'visital-core' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'visital_receipt_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'تفکیک مالیات در رسید', 'visital-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( 1, (int) $settings['enabled'] ); ?> />
								<?php esc_html_e( 'فعال باشد', 'visital-core' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'درصد مالیات', 'visital-core' ); ?></th>
						<td>
							<input type="number" step="0.01" min="0" max="100" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[rate]" value="<?php echo esc_attr( $settings['rate'] ); ?>" class="small-text" /> %
							<p class="description"><?php esc_html_e( 'نرخ مالیاتی که داخلِ کارمزدِ فعلی لحاظ شده است. مثال: کارمزد ۳۳٬۰۰۰ تومان با نرخ ۱۰٪ به ۳۰٬۰۰۰ کارمزد + ۳٬۰۰۰ مالیات تقسیم می‌شود.', 'visital-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'عنوان خط مالیات', 'visital-core' ); ?></th>
						<td>
							<input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[label]" value="<?php echo esc_attr( $settings['label'] ); ?>" class="regular-text" />
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

if ( ! function_exists( 'visital_receipt_tax_split' ) ) {
	function visital_receipt_tax_split( $args ) {
		if ( ! class_exists( 'Visital_Receipt' ) ) {
			return null;
		}
		return Visital_Receipt::tax_split( $args );
	}
}

if ( ! function_exists( 'visital_receipt_inject_tax_row' ) ) {
	function visital_receipt_inject_tax_row( $html, $split ) {
		if ( ! class_exists( 'Visital_Receipt' ) ) {
			return $html;
		}
		return Visital_Receipt::inject_tax_row( $html, $split );
	}
}
