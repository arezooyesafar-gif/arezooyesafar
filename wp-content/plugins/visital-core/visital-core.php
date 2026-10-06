<?php
/**
 * Plugin Name: VisitAl Core
 * Description: Custom business logic for the VisitAl medical booking platform (availability, claiming, reviews, filters, payments and integrations) built on top of the Dr Plus theme without editing the theme core.
 * Version: 1.0.7
 * Author: VisitAl
 * Text Domain: visital-core
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VISITAL_CORE_VERSION', '1.0.7' );
define( 'VISITAL_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'VISITAL_CORE_URI', plugin_dir_url( __FILE__ ) );

final class Visital_Core {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->includes();
		add_action( 'after_setup_theme', [ $this, 'boot' ], 20 );
	}

	private function includes() {
		require_once VISITAL_CORE_DIR . 'includes/class-visital-offices-fix.php';
		require_once VISITAL_CORE_DIR . 'includes/class-visital-availability.php';
		require_once VISITAL_CORE_DIR . 'includes/class-visital-cards.php';
		require_once VISITAL_CORE_DIR . 'includes/class-visital-profile-book.php';
		require_once VISITAL_CORE_DIR . 'includes/class-visital-calendar.php';
		require_once VISITAL_CORE_DIR . 'includes/class-visital-reviews.php';
		require_once VISITAL_CORE_DIR . 'includes/class-visital-receipt.php';
		require_once VISITAL_CORE_DIR . 'includes/class-visital-callmask.php';
		require_once VISITAL_CORE_DIR . 'includes/class-visital-sep-gateway.php';
		require_once VISITAL_CORE_DIR . 'includes/class-visital-claim.php';
		require_once VISITAL_CORE_DIR . 'includes/class-visital-assets.php';
	}

	public function boot() {
		if ( ! $this->theme_ready() ) {
			add_action( 'admin_notices', [ $this, 'theme_missing_notice' ] );
		}

		Visital_Offices_Fix::instance();
		Visital_Availability::instance();
		Visital_Cards::instance();
		Visital_Profile_Book::instance();
		Visital_Calendar::instance();
		Visital_Reviews::instance();
		Visital_Receipt::instance();
		Visital_CallMask::instance();
		Visital_Claim::instance();
		Visital_Assets::instance();
	}

	public function theme_ready() {
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme();
			if ( $theme && ( 'dr-plus' === $theme->get_template() || 'dr-plus' === $theme->get_stylesheet() ) ) {
				return true;
			}
		}
		return class_exists( '\DrPlus\Utils\Booking' ) && class_exists( '\DrPlus\Model\Specialists' );
	}

	public function theme_missing_notice() {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'VisitAl Core requires the Dr Plus theme to be active.', 'visital-core' );
		echo '</p></div>';
	}
}

Visital_Core::instance();
