<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visital_Reviews {

	const OPTION_KEY = 'visital_review_criteria';
	const META_PREFIX = 'visital_rating_';
	const CACHE_PREFIX = 'visital_review_avgs_';
	const CACHE_TTL = 3600;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		remove_action( 'comment_form', 'drplus_comment_stars_form' );
		add_action( 'comment_form', [ $this, 'render_form' ] );

		add_action( 'comment_post', [ $this, 'save' ], 20, 3 );
		add_action( 'edit_comment', [ $this, 'on_edit' ], 20, 1 );
		add_action( 'transition_comment_status', [ $this, 'on_transition' ], 20, 3 );

		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public static function default_criteria() {
		return [
			'behavior'    => 'برخورد و رفتار پزشک',
			'secretary'   => 'نحوه برخورد و هماهنگی منشی',
			'cleanliness' => 'نظافت و بهداشت محیط مطب',
			'waiting'     => 'مدیریت زمان و مدت انتظار در مطب',
			'privacy'     => 'حفظ حریم خصوصی بیمار (عدم ویزیت همزمان یا گروهی)',
			'amenities'   => 'شرایط محیطی و امکانات رفاهی مطب (تهویه، دما و آرامش فضا)',
		];
	}

	public function get_criteria() {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( empty( $stored ) || ! is_array( $stored ) ) {
			$stored = self::default_criteria();
		}
		$criteria = [];
		foreach ( $stored as $key => $label ) {
			$key = sanitize_key( $key );
			if ( '' === $key ) {
				continue;
			}
			$criteria[ $key ] = sanitize_text_field( $label );
		}
		return apply_filters( 'visital/review/criteria', $criteria );
	}

	private function is_specialist_context() {
		$post_id = get_the_ID();
		return $post_id && 'specialist' === get_post_type( $post_id );
	}

	public function render_form() {
		if ( ! class_exists( '\DrPlus\Utils\UI' ) ) {
			return;
		}

		if ( ! $this->is_specialist_context() ) {
			$radio_name = is_singular( 'product' ) ? 'rating' : 'drplus_star';
			echo '<div class="drplus_comment_stars-wrap"><div class="drplus_comment_star-title">' . esc_html__( 'Your score:', 'drplus' ) . '</div>';
			\DrPlus\Utils\UI::stars( 0, 5, true, $radio_name );
			echo '</div>';
			return;
		}

		$criteria = $this->get_criteria();
		if ( empty( $criteria ) ) {
			return;
		}
		?>
		<div class="visital-review-criteria" data-visital-review>
			<div class="visital-review-criteria-title"><?php esc_html_e( 'امتیاز شما به شاخص‌های زیر:', 'visital-core' ); ?></div>
			<?php foreach ( $criteria as $key => $label ) { ?>
				<div class="visital-review-criterion">
					<span class="visital-review-criterion-label"><?php echo esc_html( $label ); ?></span>
					<?php \DrPlus\Utils\UI::stars( 0, 5, true, self::META_PREFIX . $key ); ?>
				</div>
			<?php } ?>
			<input type="hidden" name="drplus_star" class="visital-review-overall" value="">
		</div>
		<?php
	}

	public function save( $comment_id, $comment_approved, $commentdata ) {
		$post_id = isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0;
		if ( ! $post_id ) {
			return;
		}
		if ( 'specialist' === get_post_type( $post_id ) && empty( $_POST['drplus_comment_order_id'] ) ) {
			return;
		}

		$criteria = $this->get_criteria();
		foreach ( $criteria as $key => $label ) {
			$field = self::META_PREFIX . $key;
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			$value = (int) $_POST[ $field ];
			$value = max( 0, min( 5, $value ) );
			if ( $value > 0 ) {
				update_comment_meta( $comment_id, $field, $value );
			}
		}

		$this->invalidate( $post_id );
	}

	public function on_edit( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( $comment ) {
			$this->invalidate( (int) $comment->comment_post_ID );
		}
	}

	public function on_transition( $new_status, $old_status, $comment ) {
		if ( is_object( $comment ) && ! empty( $comment->comment_post_ID ) ) {
			$this->invalidate( (int) $comment->comment_post_ID );
		}
	}

	public function get_averages( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return [ 'overall' => [ 'avg' => 0, 'count' => 0 ], 'criteria' => [] ];
		}

		$cache_key = self::CACHE_PREFIX . $post_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$criteria = $this->get_criteria();
		$result   = [
			'overall'  => [ 'avg' => 0, 'count' => 0 ],
			'criteria' => [],
		];

		$comments_count = (int) get_comment_count( $post_id )['approved'];
		if ( class_exists( '\DrPlus\Utils' ) ) {
			$result['overall'] = [
				'avg'   => (float) \DrPlus\Utils::get_post_avg( $post_id, 1, $comments_count ),
				'count' => $comments_count,
			];
		}

		if ( ! empty( $criteria ) ) {
			global $wpdb;
			$meta_keys    = array_map( function ( $k ) {
				return self::META_PREFIX . $k;
			}, array_keys( $criteria ) );
			$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
			$sql          = "SELECT cm.meta_key AS mk, AVG(cm.meta_value + 0) AS av, COUNT(*) AS cnt
				FROM {$wpdb->commentmeta} cm
				INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
				WHERE c.comment_post_ID = %d AND c.comment_approved = '1' AND cm.meta_key IN ($placeholders)
				GROUP BY cm.meta_key";
			$params       = array_merge( [ $post_id ], $meta_keys );
			$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

			$by_key = [];
			if ( $rows ) {
				foreach ( $rows as $row ) {
					$by_key[ $row->mk ] = [
						'avg'   => round( (float) $row->av, 1 ),
						'count' => (int) $row->cnt,
					];
				}
			}

			foreach ( $criteria as $key => $label ) {
				$mk                        = self::META_PREFIX . $key;
				$result['criteria'][ $key ] = [
					'label' => $label,
					'avg'   => isset( $by_key[ $mk ] ) ? $by_key[ $mk ]['avg'] : 0,
					'count' => isset( $by_key[ $mk ] ) ? $by_key[ $mk ]['count'] : 0,
				];
			}
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	public function breakdown_html( $post_id ) {
		$data = $this->get_averages( $post_id );
		if ( empty( $data['overall']['count'] ) ) {
			return '';
		}

		$overall = $data['overall'];
		ob_start();
		?>
		<div class="visital-review-breakdown">
			<div class="visital-review-breakdown-overall">
				<div class="visital-review-breakdown-score"><?php echo esc_html( number_format_i18n( $overall['avg'], 1 ) ); ?></div>
				<div class="visital-review-breakdown-overall-meta">
					<?php if ( class_exists( '\DrPlus\Utils\UI' ) ) {
						\DrPlus\Utils\UI::stars( (int) round( $overall['avg'] ), 5 );
					} ?>
					<span class="visital-review-breakdown-count"><?php echo esc_html( sprintf( _n( 'از %s دیدگاه', 'از %s دیدگاه', $overall['count'], 'visital-core' ), number_format_i18n( $overall['count'] ) ) ); ?></span>
				</div>
			</div>
			<?php if ( ! empty( $data['criteria'] ) ) { ?>
				<ul class="visital-review-breakdown-list">
					<?php foreach ( $data['criteria'] as $criterion ) {
						$pct = $criterion['avg'] > 0 ? ( $criterion['avg'] / 5 ) * 100 : 0;
						?>
						<li class="visital-review-breakdown-item">
							<span class="visital-review-breakdown-label"><?php echo esc_html( $criterion['label'] ); ?></span>
							<span class="visital-review-breakdown-bar" aria-hidden="true">
								<span class="visital-review-breakdown-bar-fill" style="width:<?php echo esc_attr( round( $pct ) ); ?>%"></span>
							</span>
							<span class="visital-review-breakdown-value"><?php echo $criterion['avg'] > 0 ? esc_html( number_format_i18n( $criterion['avg'], 1 ) ) : '—'; ?></span>
						</li>
					<?php } ?>
				</ul>
			<?php } ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public function invalidate( $post_id ) {
		delete_transient( self::CACHE_PREFIX . (int) $post_id );
	}

	public function admin_menu() {
		add_options_page(
			esc_html__( 'معیارهای امتیازدهی', 'visital-core' ),
			esc_html__( 'معیارهای امتیازدهی', 'visital-core' ),
			'manage_options',
			'visital-review-criteria',
			[ $this, 'settings_page' ]
		);
	}

	public function register_settings() {
		register_setting(
			'visital_reviews_group',
			self::OPTION_KEY,
			[ $this, 'sanitize_criteria' ]
		);
	}

	public function sanitize_criteria( $input ) {
		$criteria = [];
		if ( is_string( $input ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', $input );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$parts = explode( '|', $line, 2 );
				$key   = sanitize_key( trim( $parts[0] ) );
				$label = isset( $parts[1] ) ? sanitize_text_field( trim( $parts[1] ) ) : '';
				if ( '' === $key || '' === $label ) {
					continue;
				}
				$criteria[ $key ] = $label;
			}
		}
		if ( empty( $criteria ) ) {
			$criteria = self::default_criteria();
		}
		return $criteria;
	}

	public function settings_page() {
		$criteria = $this->get_criteria();
		$lines    = [];
		foreach ( $criteria as $key => $label ) {
			$lines[] = $key . ' | ' . $label;
		}
		$value = implode( "\n", $lines );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'معیارهای امتیازدهی پزشکان', 'visital-core' ); ?></h1>
			<p><?php esc_html_e( 'هر خط یک معیار است به شکل: کلید انگلیسی | عنوان فارسی. تغییر کلیدِ یک معیار موجود، امتیازهای قبلیِ آن را از دست می‌دهد.', 'visital-core' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'visital_reviews_group' ); ?>
				<textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>" rows="10" cols="60" class="large-text code"><?php echo esc_textarea( $value ); ?></textarea>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
