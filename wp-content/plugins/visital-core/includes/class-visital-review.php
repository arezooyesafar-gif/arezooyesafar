<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Visital_Review' ) ) {

	class Visital_Review {

		private static $instance = null;

		private $count_cache = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_action( 'admin_menu', [ $this, 'menu' ], 30 );
			add_action( 'admin_bar_menu', [ $this, 'admin_bar' ], 90 );
			add_action( 'wp_dashboard_setup', [ $this, 'dashboard_widget' ] );
		}

		private function table() {
			global $wpdb;
			return $wpdb->prefix . 'drplus_specialists';
		}

		private function statuses() {
			return apply_filters( 'visital/review/statuses', [ 'pending' ] );
		}

		private function count() {
			if ( null !== $this->count_cache ) {
				return $this->count_cache;
			}
			global $wpdb;
			$statuses = $this->statuses();
			if ( empty( $statuses ) ) {
				$this->count_cache = 0;
				return 0;
			}
			$table        = $this->table();
			$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$this->count_cache = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status IN ({$placeholders})", $statuses )
			);
			return $this->count_cache;
		}

		private function get_pending( $limit = 300 ) {
			global $wpdb;
			$statuses = $this->statuses();
			if ( empty( $statuses ) ) {
				return [];
			}
			$table        = $this->table();
			$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$args         = array_merge( $statuses, [ (int) $limit ] );
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT id, user_id, post_id, name, status, updated_at FROM {$table} WHERE status IN ({$placeholders}) ORDER BY updated_at DESC LIMIT %d", $args )
			);
		}

		private function review_url( $sid ) {
			return add_query_arg(
				[ 'page' => 'specialists', 'tab' => 'view', 'sid' => (int) $sid ],
				admin_url( 'admin.php' )
			);
		}

		private function page_url() {
			return add_query_arg( [ 'post_type' => 'specialist', 'page' => 'visital-review' ], admin_url( 'edit.php' ) );
		}

		private function display_name( $row ) {
			if ( ! empty( $row->name ) ) {
				return $row->name;
			}
			if ( ! empty( $row->post_id ) ) {
				$title = get_the_title( $row->post_id );
				if ( $title ) {
					return $title;
				}
			}
			$user = $row->user_id ? get_userdata( $row->user_id ) : null;
			return $user ? trim( $user->first_name . ' ' . $user->last_name ) : '—';
		}

		public function menu() {
			$count = $this->count();
			$bubble = $count ? ' <span class="awaiting-mod"><span class="pending-count">' . esc_html( number_format_i18n( $count ) ) . '</span></span>' : '';
			add_submenu_page(
				'edit.php?post_type=specialist',
				esc_html__( 'در انتظار بررسی', 'visital-core' ),
				esc_html__( 'در انتظار بررسی', 'visital-core' ) . $bubble,
				'manage_options',
				'visital-review',
				[ $this, 'render_page' ]
			);
		}

		public function admin_bar( $bar ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$count = $this->count();
			if ( ! $count ) {
				return;
			}
			$bar->add_node( [
				'id'    => 'visital-review',
				'title' => '<span class="ab-icon dashicons dashicons-id-alt" style="top:2px"></span>' . sprintf( esc_html__( '%s پزشک در انتظار بررسی', 'visital-core' ), number_format_i18n( $count ) ),
				'href'  => $this->page_url(),
				'meta'  => [ 'title' => esc_html__( 'پزشکان در انتظار بررسی مدارک', 'visital-core' ) ],
			] );
		}

		public function dashboard_widget() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			wp_add_dashboard_widget( 'visital_review_widget', esc_html__( 'پزشکان در انتظار بررسی', 'visital-core' ), [ $this, 'render_widget' ] );
		}

		public function render_widget() {
			$count = $this->count();
			if ( ! $count ) {
				echo '<p>' . esc_html__( 'در حال حاضر پزشکی در انتظار بررسی نیست.', 'visital-core' ) . '</p>';
				return;
			}
			$rows = $this->get_pending( 8 );
			echo '<p style="font-weight:700;font-size:15px">' . sprintf( esc_html__( '%s پزشک منتظر تایید مدارک هستند.', 'visital-core' ), number_format_i18n( $count ) ) . '</p>';
			echo '<ul style="margin:0">';
			foreach ( $rows as $row ) {
				$code = $row->user_id ? get_user_meta( $row->user_id, 'specialist_code', true ) : '';
				echo '<li style="padding:6px 0;border-bottom:1px solid #f0f0f1">';
				echo '<a href="' . esc_url( $this->review_url( $row->id ) ) . '"><strong>' . esc_html( $this->display_name( $row ) ) . '</strong></a>';
				if ( $code ) {
					echo ' <span style="color:#646970">(نظام: ' . esc_html( $code ) . ')</span>';
				}
				echo '</li>';
			}
			echo '</ul>';
			echo '<p style="margin-top:10px"><a class="button button-primary" href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'مشاهده همه و بررسی', 'visital-core' ) . '</a></p>';
		}

		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$this->count_cache = null;
			$rows = $this->get_pending( 300 );
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'پزشکان در انتظار بررسی', 'visital-core' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?>)</span></h1>
				<p class="description"><?php esc_html_e( 'فهرست پزشکانی که ثبت‌نام/پروفایل خود را تکمیل و مدارک ارسال کرده‌اند و منتظر تایید مدیر هستند. روی «بررسی مدارک» بزنید تا پروفایل و مدارک باز شود.', 'visital-core' ); ?></p>

				<?php if ( empty( $rows ) ) : ?>
					<div class="notice notice-success inline"><p><?php esc_html_e( 'در حال حاضر هیچ پزشکی در انتظار بررسی نیست. 🎉', 'visital-core' ); ?></p></div>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'نام پزشک', 'visital-core' ); ?></th>
								<th><?php esc_html_e( 'شماره نظام پزشکی', 'visital-core' ); ?></th>
								<th><?php esc_html_e( 'وضعیت', 'visital-core' ); ?></th>
								<th><?php esc_html_e( 'آخرین به‌روزرسانی', 'visital-core' ); ?></th>
								<th><?php esc_html_e( 'عملیات', 'visital-core' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$status_labels = [
								'pending'    => esc_html__( 'در انتظار بررسی', 'visital-core' ),
								'incomplete' => esc_html__( 'تکمیل نشده', 'visital-core' ),
								'active'     => esc_html__( 'فعال', 'visital-core' ),
								'inactive'   => esc_html__( 'غیرفعال', 'visital-core' ),
								'rejected'   => esc_html__( 'رد شده', 'visital-core' ),
							];
							foreach ( $rows as $row ) :
								$code = $row->user_id ? get_user_meta( $row->user_id, 'specialist_code', true ) : '';
								$date = $row->updated_at ? mysql2date( 'Y/m/d H:i', $row->updated_at ) : '—';
								?>
								<tr>
									<td><strong><?php echo esc_html( $this->display_name( $row ) ); ?></strong></td>
									<td class="ltr" style="direction:ltr;text-align:right"><?php echo $code ? esc_html( $code ) : '—'; ?></td>
									<td><?php echo esc_html( $status_labels[ $row->status ] ?? $row->status ); ?></td>
									<td><?php echo esc_html( $date ); ?></td>
									<td>
										<a class="button button-primary" href="<?php echo esc_url( $this->review_url( $row->id ) ); ?>"><?php esc_html_e( 'بررسی مدارک', 'visital-core' ); ?></a>
										<?php if ( $row->post_id ) : ?>
											<a class="button" href="<?php echo esc_url( get_permalink( $row->post_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'مشاهده پروفایل', 'visital-core' ); ?></a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<?php
		}
	}
}
