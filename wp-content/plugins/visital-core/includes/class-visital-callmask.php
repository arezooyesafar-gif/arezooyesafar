<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visital_CallMask {

	const OPTION_ENABLED = 'visital_call_mask_enabled';
	const OPTION_SECRET  = 'visital_call_mask_secret';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
	}

	public static function is_enabled() {
		return (bool) get_option( self::OPTION_ENABLED, 0 );
	}

	public static function display_number( $raw_number, $context = [] ) {
		if ( ! self::is_enabled() ) {
			return $raw_number;
		}
		return apply_filters( 'visital/call_mask/number', $raw_number, $context );
	}

	public static function create_session( $caller_number, $callee_number, $context = [] ) {
		$session = apply_filters(
			'visital/call_mask/create_session',
			null,
			$caller_number,
			$callee_number,
			$context
		);
		do_action( 'visital/call_mask/session_created', $session, $caller_number, $callee_number, $context );
		return $session;
	}

	public function register_routes() {
		register_rest_route(
			'visital/v1',
			'/call-mask/callback',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_callback' ],
				'permission_callback' => [ $this, 'verify_callback' ],
			]
		);
	}

	public function verify_callback( $request ) {
		$secret = get_option( self::OPTION_SECRET, '' );
		if ( empty( $secret ) ) {
			return false;
		}
		$provided = $request->get_header( 'x_visital_signature' );
		if ( empty( $provided ) ) {
			return false;
		}
		return hash_equals( (string) $secret, (string) $provided );
	}

	public function handle_callback( $request ) {
		do_action( 'visital/call_mask/callback', $request );
		return rest_ensure_response( [ 'received' => true ] );
	}

	public function admin_menu() {
		add_options_page(
			esc_html__( 'تماس امن', 'visital-core' ),
			esc_html__( 'تماس امن (VisitAl)', 'visital-core' ),
			'manage_options',
			'visital-call-mask',
			[ $this, 'settings_page' ]
		);
	}

	public function register_settings() {
		register_setting( 'visital_call_mask_group', self::OPTION_ENABLED, 'absint' );
		register_setting( 'visital_call_mask_group', self::OPTION_SECRET, 'sanitize_text_field' );
	}

	public function settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'تماس امن (Call Masking)', 'visital-core' ); ?></h1>
			<p><?php esc_html_e( 'این بخش هوک‌های اتصال سرویس تماس دوطرفه بدون نمایش شماره را فراهم می‌کند. پس از خرید و اتصال سرویس، افزونه‌ی واسط می‌تواند روی فیلترهای visital/call_mask قلاب شود.', 'visital-core' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'visital_call_mask_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'فعال‌سازی تماس امن', 'visital-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_ENABLED ); ?>" value="1" <?php checked( 1, (int) get_option( self::OPTION_ENABLED, 0 ) ); ?>>
								<?php esc_html_e( 'نمایش شماره‌ها از طریق شماره واسط (در صورت اتصال سرویس)', 'visital-core' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'کلید امضای کال‌بک', 'visital-core' ); ?></th>
						<td>
							<input type="text" class="regular-text code" name="<?php echo esc_attr( self::OPTION_SECRET ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_SECRET, '' ) ); ?>">
							<p class="description"><?php printf( esc_html__( 'آدرس کال‌بک: %s', 'visital-core' ), esc_url( rest_url( 'visital/v1/call-mask/callback' ) ) ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
