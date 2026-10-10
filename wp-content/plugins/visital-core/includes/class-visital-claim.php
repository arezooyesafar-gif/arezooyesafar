<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Visital_Claim' ) ) {

	class Visital_Claim {

		const DONE_META     = 'visital_claim_done';
		const PENDING_META  = 'visital_claim_pending_link';
		const CODE_META     = 'specialist_code';
		const COOKIE        = 'visital_pending_code';
		const CLAIMED_META  = 'visital_claimed';
		const CLAIMANT_META = 'visital_claimed_by';

		private static $instance = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_shortcode( 'visital_doctor_register', [ $this, 'render_landing' ] );

			add_action( 'wp_ajax_nopriv_visital_claim_lookup', [ $this, 'ajax_lookup' ] );
			add_action( 'wp_ajax_visital_claim_lookup', [ $this, 'ajax_lookup' ] );
			add_action( 'wp_ajax_visital_claim_save', [ $this, 'ajax_save' ] );

			add_action( 'init', [ $this, 'capture_cookie' ] );
			add_action( 'drplus/onboard/before_form', [ $this, 'render_gate' ] );
		}

		private function specialists_table() {
			global $wpdb;
			return $wpdb->prefix . 'drplus_specialists';
		}

		private function dashboard_url() {
			if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
				$url = wc_get_account_endpoint_url( 'specialist-dashboard' );
				if ( $url ) {
					return $url;
				}
			}
			return home_url( '/my-account/specialist-dashboard/' );
		}

		private function normalize_code( $code ) {
			$code = is_string( $code ) ? trim( $code ) : '';
			if ( class_exists( '\DrPlus\Utils' ) ) {
				$code = \DrPlus\Utils::convert_chars( $code );
			}
			return sanitize_text_field( $code );
		}

		private function code_candidates( $code ) {
			$en = $this->normalize_code( $code );
			if ( '' === $en ) {
				return [];
			}
			$map_fa = [ '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ];
			$map_ar = [ '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩' ];
			$candidates = [ $en, strtr( $en, $map_fa ), strtr( $en, $map_ar ) ];
			return array_values( array_unique( array_filter( $candidates ) ) );
		}

		private function find_by_code( $code, $exclude_user = 0 ) {
			global $wpdb;
			$candidates = $this->code_candidates( $code );
			if ( empty( $candidates ) ) {
				return null;
			}

			$args = [
				'meta_query'  => [
					[
						'key'     => self::CODE_META,
						'value'   => $candidates,
						'compare' => 'IN',
					],
				],
				'fields'      => 'ID',
				'number'      => 1,
				'count_total' => false,
			];
			if ( $exclude_user ) {
				$args['exclude'] = [ (int) $exclude_user ];
			}
			$user_ids = get_users( $args );
			if ( empty( $user_ids ) ) {
				return null;
			}
			$user_id = (int) $user_ids[0];

			$table = $this->specialists_table();
			$row   = $wpdb->get_row(
				$wpdb->prepare( "SELECT id, user_id, post_id, name, status FROM {$table} WHERE user_id = %d LIMIT 1", $user_id )
			);
			if ( $row ) {
				return $row;
			}

			$user = get_userdata( $user_id );
			return (object) [
				'id'      => 0,
				'user_id' => $user_id,
				'post_id' => 0,
				'name'    => $user ? trim( $user->first_name . ' ' . $user->last_name ) : '',
				'status'  => '',
			];
		}

		private function profile_name( $row ) {
			if ( ! $row ) {
				return '';
			}
			$name = $row->name;
			if ( empty( $name ) && ! empty( $row->post_id ) ) {
				$name = get_the_title( $row->post_id );
			}
			return $name;
		}

		public function ajax_lookup() {
			check_ajax_referer( 'visital_claim', 'nonce' );
			$code = isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '';
			$code = $this->normalize_code( $code );
			if ( '' === $code ) {
				wp_send_json_error( [ 'message' => esc_html__( 'شماره نظام پزشکی را وارد کنید.', 'visital-core' ) ] );
			}
			$row = $this->find_by_code( $code );
			if ( $row ) {
				wp_send_json_success( [
					'status'  => 'found',
					'name'    => $this->profile_name( $row ),
					'message' => sprintf(
						esc_html__( 'پروفایلی با این کد نظام یافت شد: %s. پس از تکمیل ثبت نام و تایید مدارک توسط مدیر، این پروفایل به حساب شما متصل میشود.', 'visital-core' ),
						$this->profile_name( $row )
					),
				] );
			}
			wp_send_json_success( [
				'status'  => 'not_found',
				'name'    => '',
				'message' => esc_html__( 'پروفایلی با این کد یافت نشد. ثبت نام جدید انجام میشود.', 'visital-core' ),
			] );
		}

		public function ajax_save() {
			check_ajax_referer( 'visital_claim', 'nonce' );
			$user_id = get_current_user_id();
			if ( ! $user_id ) {
				wp_send_json_error( [ 'message' => 'not_logged_in' ] );
			}
			$code = isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '';
			$code = $this->normalize_code( $code );
			if ( '' === $code ) {
				wp_send_json_error( [ 'message' => esc_html__( 'شماره نظام پزشکی را وارد کنید.', 'visital-core' ) ] );
			}
			$this->store_claim( $user_id, $code );
			wp_send_json_success( [ 'reload' => true ] );
		}

		private function store_claim( $user_id, $code ) {
			$code = $this->normalize_code( $code );
			if ( '' === $code ) {
				return;
			}

			$target = $this->find_by_code( $code, $user_id );
			if ( $target && ! empty( $target->id ) && (int) $target->user_id !== (int) $user_id ) {
				$claimed = $target->post_id ? (int) get_post_meta( $target->post_id, self::CLAIMANT_META, true ) : 0;
				if ( ! $claimed ) {
					if ( $this->link_profile( $user_id, $target ) ) {
						update_user_meta( $user_id, self::PENDING_META, $code );
					}
				}
			}

			update_user_meta( $user_id, self::CODE_META, $code );
			update_user_meta( $user_id, self::DONE_META, 1 );
		}

		private function link_profile( $claimant_id, $target ) {
			global $wpdb;
			$claimant_id = (int) $claimant_id;
			if ( empty( $target->id ) || ! $claimant_id ) {
				return false;
			}
			$claimed = $target->post_id ? (int) get_post_meta( $target->post_id, self::CLAIMANT_META, true ) : 0;
			if ( $claimed && $claimed !== $claimant_id ) {
				return false;
			}

			$table = $this->specialists_table();

			$own = $wpdb->get_row( $wpdb->prepare( "SELECT id, post_id FROM {$table} WHERE user_id = %d LIMIT 1", $claimant_id ) );
			if ( $own && (int) $own->id !== (int) $target->id ) {
				if ( ! empty( $own->post_id ) ) {
					$own_post = get_post( $own->post_id );
					if ( $own_post && '' === trim( (string) $own_post->post_content ) ) {
						wp_trash_post( $own->post_id );
					}
				}
				$wpdb->delete( $table, [ 'id' => (int) $own->id ] );
			}

			$updated = $wpdb->update( $table, [ 'user_id' => $claimant_id ], [ 'id' => (int) $target->id ] );
			if ( false === $updated ) {
				return false;
			}

			if ( ! empty( $target->post_id ) ) {
				wp_update_post( [ 'ID' => (int) $target->post_id, 'post_author' => $claimant_id ] );
				update_post_meta( $target->post_id, self::CLAIMED_META, 1 );
				update_post_meta( $target->post_id, self::CLAIMANT_META, $claimant_id );
			}

			$old_user = (int) $target->user_id;
			if ( $old_user && $old_user !== $claimant_id ) {
				delete_user_meta( $old_user, self::CODE_META );
			}

			if ( class_exists( '\DrPlus\Utils\UtilsSpecialists' ) && method_exists( '\DrPlus\Utils\UtilsSpecialists', 'clear_cache' ) ) {
				\DrPlus\Utils\UtilsSpecialists::clear_cache( (int) $target->id );
			}

			return true;
		}

		public function capture_cookie() {
			if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
				return;
			}
			$user_id = get_current_user_id();
			if ( ! $user_id ) {
				return;
			}
			$this->store_claim( $user_id, wp_unslash( $_COOKIE[ self::COOKIE ] ) );
			if ( ! headers_sent() ) {
				setcookie( self::COOKIE, '', time() - HOUR_IN_SECONDS, '/' );
			}
			unset( $_COOKIE[ self::COOKIE ] );
		}

		private function already_captured( $user_id ) {
			if ( get_user_meta( $user_id, self::DONE_META, true ) ) {
				return true;
			}
			if ( get_user_meta( $user_id, self::CODE_META, true ) ) {
				return true;
			}
			return false;
		}

		public function render_landing( $atts ) {
			$atts = shortcode_atts( [
				'next'  => '',
				'title' => 'ثبت نام پزشکان',
			], $atts, 'visital_doctor_register' );

			$next = $atts['next'] ? $atts['next'] : $this->dashboard_url();

			if ( is_user_logged_in() ) {
				ob_start();
				echo $this->assets();
				?>
				<div class="visital-claim">
					<div class="visital-claim-box">
						<h2 class="visital-claim-title"><?php echo esc_html( $atts['title'] ); ?></h2>
						<p class="visital-claim-lead"><?php esc_html_e( 'شما وارد شده‌اید. برای تکمیل ثبت نام یا ورود به پنل ادامه دهید.', 'visital-core' ); ?></p>
						<a class="visital-claim-btn" href="<?php echo esc_url( $next ); ?>"><?php esc_html_e( 'ورود به پنل پزشک', 'visital-core' ); ?></a>
					</div>
				</div>
				<?php
				return ob_get_clean();
			}

			ob_start();
			echo $this->assets();
			?>
			<div class="visital-claim" data-visital-claim data-next="<?php echo esc_url( $next ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'visital_claim' ) ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
				<div class="visital-claim-box">
					<h2 class="visital-claim-title"><?php echo esc_html( $atts['title'] ); ?></h2>
					<p class="visital-claim-lead"><?php esc_html_e( 'برای شروع، شماره نظام پزشکی خود را وارد کنید.', 'visital-core' ); ?></p>

					<label class="visital-claim-label" for="visital-claim-code"><?php esc_html_e( 'شماره نظام پزشکی', 'visital-core' ); ?></label>
					<input type="text" id="visital-claim-code" class="visital-claim-input" inputmode="numeric" autocomplete="off" data-role="code">

					<div class="visital-claim-message" data-role="message" hidden></div>

					<button type="button" class="visital-claim-btn" data-action="lookup"><?php esc_html_e( 'بررسی', 'visital-core' ); ?></button>
					<button type="button" class="visital-claim-btn visital-claim-btn-next" data-action="continue" hidden><?php esc_html_e( 'ادامه و ثبت نام', 'visital-core' ); ?></button>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		public function render_gate() {
			if ( ! class_exists( '\DrPlus\Utils\Onboard' ) || ! \DrPlus\Utils\Onboard::is_onboard() ) {
				return;
			}
			$user_id = get_current_user_id();
			if ( ! $user_id || $this->already_captured( $user_id ) ) {
				return;
			}
			if ( class_exists( '\DrPlus\Utils\UtilsSpecialists' ) ) {
				$specialist = \DrPlus\Utils\UtilsSpecialists::get_by_user_id( $user_id );
				if ( $specialist && in_array( $specialist->status, [ 'active', 'pending', 'rejected' ], true ) ) {
					update_user_meta( $user_id, self::DONE_META, 1 );
					return;
				}
			}
			echo $this->assets();
			?>
			<div class="visital-claim visital-claim-gate" data-visital-claim data-mode="gate" data-nonce="<?php echo esc_attr( wp_create_nonce( 'visital_claim' ) ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
				<div class="visital-claim-box">
					<h2 class="visital-claim-title"><?php esc_html_e( 'شماره نظام پزشکی', 'visital-core' ); ?></h2>
					<p class="visital-claim-lead"><?php esc_html_e( 'برای ادامه، ابتدا شماره نظام پزشکی خود را وارد کنید.', 'visital-core' ); ?></p>

					<label class="visital-claim-label" for="visital-claim-code-gate"><?php esc_html_e( 'شماره نظام پزشکی', 'visital-core' ); ?></label>
					<input type="text" id="visital-claim-code-gate" class="visital-claim-input" inputmode="numeric" autocomplete="off" data-role="code">

					<div class="visital-claim-message" data-role="message" hidden></div>

					<button type="button" class="visital-claim-btn" data-action="lookup"><?php esc_html_e( 'بررسی', 'visital-core' ); ?></button>
					<button type="button" class="visital-claim-btn visital-claim-btn-next" data-action="continue" hidden><?php esc_html_e( 'ادامه', 'visital-core' ); ?></button>
				</div>
			</div>
			<?php
		}

		private function assets() {
			static $done = false;
			if ( $done ) {
				return '';
			}
			$done = true;
			$css = '
			.visital-claim{--visital-navy:#002f6c;--visital-royal:#0056b3;--visital-gold:#c5a059;--visital-offwhite:#f4f7fa;direction:rtl;max-width:460px;margin:24px auto;padding:0 16px;box-sizing:border-box}
			.visital-claim *{box-sizing:border-box}
			.visital-claim-box{background:#fff;border:1px solid #e3e9f2;border-radius:18px;padding:26px 22px;box-shadow:0 12px 34px rgba(0,47,108,.08)}
			.visital-claim-title{color:var(--visital-navy);font-size:20px;font-weight:800;margin:0 0 8px;text-align:center}
			.visital-claim-lead{color:var(--visital-royal);font-size:14px;font-weight:600;line-height:1.8;margin:0 0 18px;text-align:center}
			.visital-claim-label{display:block;color:var(--visital-navy);font-size:14px;font-weight:700;margin:0 0 8px}
			.visital-claim-input{width:100%;height:52px;border:1px solid #e3e9f2;border-radius:12px;padding:0 14px;font:inherit;color:var(--visital-navy);outline:none;background:var(--visital-offwhite)}
			.visital-claim-input:focus{border-color:var(--visital-royal);background:#fff}
			.visital-claim-btn{display:block;width:100%;margin-top:14px;height:52px;border:0;border-radius:12px;background:var(--visital-navy);color:#fff;font-weight:700;font-size:16px;cursor:pointer;transition:background .2s;text-align:center;line-height:52px;text-decoration:none}
			.visital-claim-btn:hover{background:var(--visital-royal);color:#fff}
			.visital-claim-btn-next{background:var(--visital-gold);color:#1b2a4a}
			.visital-claim-btn-next:hover{background:#b8934f;color:#1b2a4a}
			.visital-claim-message{margin-top:14px;padding:12px 14px;border-radius:10px;font-size:14px;line-height:1.8;background:var(--visital-offwhite);color:var(--visital-navy)}
			.visital-claim-message.is-found{background:#eaf6ef;color:#1a7a44}
			.visital-claim-message.is-notfound{background:#fff6e6;color:#8a6d1f}
			.visital-claim-message.is-error{background:#fdecec;color:#b43b3b}';
			$js = '
			(function(){
				function ready(fn){if(document.readyState!=="loading"){fn();}else{document.addEventListener("DOMContentLoaded",fn);}}
				function setCookie(v){document.cookie="' . self::COOKIE . '="+encodeURIComponent(v)+";path=/;max-age=1800";}
				ready(function(){
					var roots=document.querySelectorAll("[data-visital-claim]");
					Array.prototype.forEach.call(roots,function(root){
						var ajax=root.getAttribute("data-ajax");
						var nonce=root.getAttribute("data-nonce");
						var next=root.getAttribute("data-next");
						var mode=root.getAttribute("data-mode");
						var codeEl=root.querySelector("[data-role=\'code\']");
						var msgEl=root.querySelector("[data-role=\'message\']");
						var lookupBtn=root.querySelector("[data-action=\'lookup\']");
						var nextBtn=root.querySelector("[data-action=\'continue\']");
						function showMsg(text,cls){if(!msgEl){return;}msgEl.textContent=text;msgEl.className="visital-claim-message"+(cls?" "+cls:"");msgEl.hidden=false;}
						function post(action,data,cb){
							var body="action="+encodeURIComponent(action)+"&nonce="+encodeURIComponent(nonce);
							for(var k in data){body+="&"+encodeURIComponent(k)+"="+encodeURIComponent(data[k]);}
							var xhr=new XMLHttpRequest();
							xhr.open("POST",ajax,true);
							xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded");
							xhr.onload=function(){try{cb(JSON.parse(xhr.responseText));}catch(e){cb(null);}};
							xhr.onerror=function(){cb(null);};
							xhr.send(body);
						}
						if(lookupBtn){lookupBtn.addEventListener("click",function(){
							var code=codeEl?codeEl.value.trim():"";
							if(!code){showMsg("شماره نظام پزشکی را وارد کنید.","is-error");return;}
							lookupBtn.disabled=true;
							post("visital_claim_lookup",{code:code},function(res){
								lookupBtn.disabled=false;
								if(!res||!res.success){showMsg((res&&res.data&&res.data.message)||"بررسی ممکن نشد. دوباره تلاش کنید.","is-error");return;}
								var d=res.data;
								showMsg(d.message,d.status==="found"?"is-found":"is-notfound");
								if(nextBtn){nextBtn.hidden=false;}
							});
						});}
						if(nextBtn){nextBtn.addEventListener("click",function(){
							var code=codeEl?codeEl.value.trim():"";
							if(!code){showMsg("شماره نظام پزشکی را وارد کنید.","is-error");return;}
							if(mode==="gate"){
								nextBtn.disabled=true;
								post("visital_claim_save",{code:code},function(res){
									nextBtn.disabled=false;
									if(res&&res.success){window.location.reload();}
									else{showMsg((res&&res.data&&res.data.message)||"ثبت ممکن نشد.","is-error");}
								});
							}else{
								setCookie(code);
								window.location.href=next;
							}
						});}
						if(codeEl){codeEl.addEventListener("input",function(){if(nextBtn){nextBtn.hidden=true;}if(msgEl){msgEl.hidden=true;}});}
					});
				});
			})();';
			return '<style id="visital-claim-css">' . $css . '</style><script id="visital-claim-js">' . $js . '</script>';
		}
	}
}
