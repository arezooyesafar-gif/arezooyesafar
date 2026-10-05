<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visital_Payments {

	const OPT_DEPOSIT_ENABLED = 'visital_deposit_enabled';
	const OPT_DEPOSIT_DEFAULT = 'visital_deposit_default';
	const OPT_COMMISSION      = 'visital_commission';
	const OPT_TAX             = 'visital_tax';

	const META_DEPOSIT_MODE   = 'visital_deposit_mode';
	const META_DEPOSIT_AMOUNT = 'visital_deposit_amount';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
	}

	public static function deposit_enabled() {
		return (bool) get_option( self::OPT_DEPOSIT_ENABLED, 1 );
	}

	public static function default_deposit() {
		return (float) get_option( self::OPT_DEPOSIT_DEFAULT, 150000 );
	}

	public static function commission() {
		return (float) get_option( self::OPT_COMMISSION, 30000 );
	}

	public static function tax() {
		return (float) get_option( self::OPT_TAX, 3000 );
	}

	public static function total_commission() {
		return self::commission() + self::tax();
	}

	public function get_doctor_deposit( $specialist ) {
		$mode   = '';
		$amount = 0;
		if ( is_object( $specialist ) && ! empty( $specialist->user_id ) ) {
			$mode   = (string) get_user_meta( $specialist->user_id, self::META_DEPOSIT_MODE, true );
			$amount = (float) get_user_meta( $specialist->user_id, self::META_DEPOSIT_AMOUNT, true );
		}
		return [
			'mode'   => $mode ?: 'default',
			'amount' => $amount,
		];
	}

	public function charge_amount( $visit_price, $specialist = null ) {
		$visit_price = max( 0, (float) $visit_price );
		$charge      = $visit_price;

		if ( self::deposit_enabled() ) {
			$doctor = $this->get_doctor_deposit( $specialist );
			if ( 'none' === $doctor['mode'] ) {
				$charge = $visit_price;
			} elseif ( 'custom' === $doctor['mode'] && $doctor['amount'] > 0 ) {
				$charge = min( $doctor['amount'], $visit_price );
			} else {
				$default = self::default_deposit();
				$charge  = $default > 0 ? min( $default, $visit_price ) : $visit_price;
			}
		}

		return (float) apply_filters( 'visital/payments/charge_amount', $charge, $visit_price, $specialist );
	}

	public function split( $charge ) {
		$charge     = max( 0, (float) $charge );
		$commission = min( self::total_commission(), $charge );
		$commission = (float) apply_filters( 'visital/payments/commission', $commission, $charge );
		$commission = max( 0, min( $commission, $charge ) );
		$income     = max( 0, $charge - $commission );
		$income     = (float) apply_filters( 'visital/payments/specialist_income', $income, $charge, $commission );

		return [
			'charge'            => $charge,
			'commission'        => $commission,
			'specialist_income' => $income,
			'tax'               => min( self::tax(), $commission ),
		];
	}

	public function admin_menu() {
		add_options_page(
			esc_html__( 'بیعانه و کارمزد', 'visital-core' ),
			esc_html__( 'بیعانه و کارمزد (VisitAl)', 'visital-core' ),
			'manage_options',
			'visital-payments',
			[ $this, 'settings_page' ]
		);
	}

	public function register_settings() {
		register_setting( 'visital_payments_group', self::OPT_DEPOSIT_ENABLED, 'absint' );
		register_setting( 'visital_payments_group', self::OPT_DEPOSIT_DEFAULT, [ $this, 'sanitize_amount' ] );
		register_setting( 'visital_payments_group', self::OPT_COMMISSION, [ $this, 'sanitize_amount' ] );
		register_setting( 'visital_payments_group', self::OPT_TAX, [ $this, 'sanitize_amount' ] );
	}

	public function sanitize_amount( $value ) {
		return max( 0, (float) preg_replace( '/[^0-9.]/', '', (string) $value ) );
	}

	public function settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'بیعانه و کارمزد پلتفرم', 'visital-core' ); ?></h1>
			<p><?php esc_html_e( 'مبالغ به تومان. بیعانه منعطف است؛ هر پزشک می‌تواند حالت خود را روی «پیش‌فرض»، «مبلغ دلخواه» یا «بدون بیعانه» قرار دهد.', 'visital-core' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'visital_payments_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'دریافت بیعانه', 'visital-core' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT_DEPOSIT_ENABLED ); ?>" value="1" <?php checked( 1, (int) get_option( self::OPT_DEPOSIT_ENABLED, 1 ) ); ?>> <?php esc_html_e( 'فعال باشد', 'visital-core' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'بیعانه پیش‌فرض (تومان)', 'visital-core' ); ?></th>
						<td><input type="number" min="0" step="1000" name="<?php echo esc_attr( self::OPT_DEPOSIT_DEFAULT ); ?>" value="<?php echo esc_attr( self::default_deposit() ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'کارمزد پلتفرم (تومان)', 'visital-core' ); ?></th>
						<td><input type="number" min="0" step="1000" name="<?php echo esc_attr( self::OPT_COMMISSION ); ?>" value="<?php echo esc_attr( self::commission() ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'مالیات (تومان)', 'visital-core' ); ?></th>
						<td><input type="number" min="0" step="500" name="<?php echo esc_attr( self::OPT_TAX ); ?>" value="<?php echo esc_attr( self::tax() ); ?>" class="regular-text"></td>
					</tr>
				</table>
				<p class="description"><?php printf( esc_html__( 'سهم پزشک از هر رزرو = مبلغ شارژ منهای کارمزد کل (%s تومان).', 'visital-core' ), esc_html( number_format_i18n( self::total_commission() ) ) ); ?></p>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
