<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Visital_Home' ) ) {

	class Visital_Home {

		private static $instance = null;

		private $districts_cache = null;

		private $cities_cache = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_shortcode( 'visital_hero', [ $this, 'render_hero' ] );
			add_shortcode( 'visital_specialties', [ $this, 'render_specialties' ] );
		}

		private function cities() {
			if ( null !== $this->cities_cache ) {
				return $this->cities_cache;
			}
			$this->cities_cache = [];
			if ( ! taxonomy_exists( 'location' ) ) {
				return $this->cities_cache;
			}
			$terms = get_terms( [
				'taxonomy'   => 'location',
				'parent'     => 0,
				'hide_empty' => false,
			] );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$this->cities_cache = $terms;
			}
			return $this->cities_cache;
		}

		private function districts_map() {
			if ( null !== $this->districts_cache ) {
				return $this->districts_cache;
			}
			$this->districts_cache = [];
			foreach ( $this->cities() as $city ) {
				$children = get_terms( [
					'taxonomy'   => 'location',
					'parent'     => $city->term_id,
					'hide_empty' => false,
				] );
				$list = [];
				if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
					foreach ( $children as $child ) {
						$list[] = [
							'slug' => $child->slug,
							'name' => $child->name,
						];
					}
				}
				$this->districts_cache[ $city->slug ] = $list;
			}
			return $this->districts_cache;
		}

		private function default_city_slug() {
			$default = apply_filters( 'visital/home/default_city', 'tabriz' );
			$cities  = $this->cities();
			foreach ( $cities as $city ) {
				if ( $city->slug === $default ) {
					return $city->slug;
				}
			}
			foreach ( $cities as $city ) {
				if ( ( function_exists( 'mb_strpos' ) && false !== mb_strpos( $city->name, 'تبریز' ) ) || false !== stripos( $city->slug, 'tabriz' ) ) {
					return $city->slug;
				}
			}
			return ! empty( $cities ) ? $cities[0]->slug : '';
		}

		public function render_hero( $atts ) {
			$atts = shortcode_atts( [
				'title'       => 'ویزیتال؛ سامانه نوبت‌دهی اینترنتی و مشاوره پزشکی',
				'subtitle'    => 'ساده و سریع؛ فقط با چند کلیک از پزشکان معتبر نوبت بگیر',
				'placeholder' => 'نام پزشک، تخصص، مرکز درمانی، بیماری',
				'visit_url'   => '',
				'video_url'   => '',
				'phone_url'   => '',
				'text_url'    => '',
			], $atts, 'visital_hero' );

			$cities        = $this->cities();
			$default_city  = $this->default_city_slug();
			$districts_map = $this->districts_map();
			$districts     = isset( $districts_map[ $default_city ] ) ? $districts_map[ $default_city ] : [];

			$archive = get_post_type_archive_link( 'specialist' );
			if ( ! $archive ) {
				$archive = home_url( '/' );
			}
			$visit_url = $atts['visit_url'] ? $atts['visit_url'] : $archive;
			$video_url = $atts['video_url'] ? $atts['video_url'] : $archive;
			$phone_url = $atts['phone_url'] ? $atts['phone_url'] : $archive;
			$text_url  = $atts['text_url'] ? $atts['text_url'] : $archive;

			$icons = $this->icons();

			ob_start();
			echo $this->style_once();
			?>
			<section class="visital-hero">
				<h1 class="visital-hero-title"><?php echo esc_html( $atts['title'] ); ?></h1>
				<p class="visital-hero-subtitle"><?php echo esc_html( $atts['subtitle'] ); ?></p>

				<form method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" class="visital-hero-search" role="search">
					<div class="visital-hero-search-main">
						<span class="visital-hero-ico"><?php echo $icons['search']; ?></span>
						<input type="search" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" class="visital-hero-input" placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>" autocomplete="off">
					</div>

					<div class="visital-hero-search-row">
						<label class="visital-hero-field">
							<span class="visital-hero-ico"><?php echo $icons['pin']; ?></span>
							<select class="visital-hero-select" data-role="city" aria-label="شهر">
								<?php foreach ( $cities as $city ) : ?>
									<option value="<?php echo esc_attr( $city->slug ); ?>" <?php selected( $city->slug, $default_city ); ?>><?php echo esc_html( $city->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>

						<label class="visital-hero-field">
							<span class="visital-hero-ico"><?php echo $icons['map']; ?></span>
							<select class="visital-hero-select" data-role="district" aria-label="منطقه">
								<option value="">همه مناطق</option>
								<?php foreach ( $districts as $district ) : ?>
									<option value="<?php echo esc_attr( $district['slug'] ); ?>"><?php echo esc_html( $district['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>

					<input type="hidden" name="city" value="<?php echo esc_attr( $default_city ); ?>" class="visital-hero-city-value">

					<button type="submit" class="visital-hero-btn"><?php echo $icons['search']; ?><span>جستجو</span></button>
				</form>

				<div class="visital-hero-consult">
					<a href="<?php echo esc_url( $visit_url ); ?>" class="visital-hero-consult-item"><?php echo $icons['hospital']; ?><span>نوبت‌دهی اینترنتی مراکز درمانی</span></a>
					<a href="<?php echo esc_url( $video_url ); ?>" class="visital-hero-consult-item"><?php echo $icons['video']; ?><span>مشاوره ویدیویی</span></a>
					<a href="<?php echo esc_url( $phone_url ); ?>" class="visital-hero-consult-item"><?php echo $icons['phone']; ?><span>مشاوره تلفنی</span></a>
					<a href="<?php echo esc_url( $text_url ); ?>" class="visital-hero-consult-item"><?php echo $icons['chat']; ?><span>مشاوره متنی</span></a>
				</div>
			</section>
			<?php
			echo $this->script_once( $districts_map );
			return ob_get_clean();
		}

		public function render_specialties( $atts ) {
			$atts = shortcode_atts( [
				'title'    => 'رشته‌های پربازدید ویزیتال',
				'count'    => 8,
				'include'  => '',
				'all_text' => 'مشاهده همه رشته‌ها',
			], $atts, 'visital_specialties' );

			if ( ! post_type_exists( 'speciality' ) ) {
				return '';
			}

			$posts = [];

			if ( ! empty( $atts['include'] ) ) {
				$entries = array_filter( array_map( 'trim', explode( ',', $atts['include'] ) ) );
				foreach ( $entries as $entry ) {
					$found = get_page_by_path( sanitize_title( $entry ), OBJECT, 'speciality' );
					if ( ! $found ) {
						$title_query = new WP_Query( [
							'post_type'           => 'speciality',
							'post_status'         => 'publish',
							'title'               => $entry,
							'posts_per_page'      => 1,
							'ignore_sticky_posts' => true,
							'no_found_rows'       => true,
						] );
						if ( ! empty( $title_query->posts ) ) {
							$found = $title_query->posts[0];
						}
						wp_reset_postdata();
					}
					if ( $found && 'publish' === $found->post_status && ! in_array( $found, $posts, true ) ) {
						$posts[] = $found;
					}
				}
			} else {
				$query = new WP_Query( [
					'post_type'           => 'speciality',
					'post_status'         => 'publish',
					'orderby'             => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
					'posts_per_page'      => max( 1, intval( $atts['count'] ) ),
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
				] );
				$posts = $query->posts;
				wp_reset_postdata();
			}

			if ( empty( $posts ) ) {
				return '';
			}

			$archive  = get_post_type_archive_link( 'speciality' );
			$fallback = $this->icons()['plus'];

			ob_start();
			echo $this->style_once();
			?>
			<section class="visital-specs">
				<h2 class="visital-specs-title"><?php echo esc_html( $atts['title'] ); ?></h2>
				<div class="visital-specs-grid">
					<?php foreach ( $posts as $post ) : ?>
						<a href="<?php echo esc_url( get_permalink( $post ) ); ?>" class="visital-spec-card">
							<span class="visital-spec-ico">
								<?php
								if ( has_post_thumbnail( $post ) ) {
									echo get_the_post_thumbnail( $post, 'thumbnail', [ 'class' => 'visital-spec-img', 'loading' => 'lazy', 'alt' => esc_attr( get_the_title( $post ) ) ] );
								} else {
									echo $fallback;
								}
								?>
							</span>
							<span class="visital-spec-name"><?php echo esc_html( get_the_title( $post ) ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
				<?php if ( $archive ) : ?>
					<div class="visital-specs-all">
						<a href="<?php echo esc_url( $archive ); ?>" class="visital-specs-all-btn"><?php echo esc_html( $atts['all_text'] ); ?></a>
					</div>
				<?php endif; ?>
			</section>
			<?php
			return ob_get_clean();
		}

		private function icons() {
			$open = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
			return [
				'search'   => $open . '<circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>',
				'pin'      => $open . '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>',
				'map'      => $open . '<polygon points="1 6 8 3 16 6 23 3 23 18 16 21 8 18 1 21 1 6"></polygon><line x1="8" y1="3" x2="8" y2="18"></line><line x1="16" y1="6" x2="16" y2="21"></line></svg>',
				'hospital' => $open . '<path d="M3 21h18"></path><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"></path><path d="M12 7v6"></path><path d="M9 10h6"></path></svg>',
				'video'    => $open . '<polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>',
				'phone'    => $open . '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>',
				'chat'     => $open . '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>',
				'plus'     => $open . '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>',
			];
		}

		private function style_once() {
			static $done = false;
			if ( $done ) {
				return '';
			}
			$done = true;
			return '<style id="visital-home-css">' . $this->css() . '</style>';
		}

		private function script_once( $districts_map ) {
			static $done = false;
			if ( $done ) {
				return '';
			}
			$done = true;
			$json = wp_json_encode( [ 'districts' => $districts_map ] );
			return '<script id="visital-home-js">window.VisitalHomeData=' . $json . ';' . $this->js() . '</script>';
		}

		private function css() {
			return '
			.visital-hero,.visital-specs{--visital-navy:#002f6c;--visital-royal:#0056b3;--visital-gold:#c5a059;--visital-offwhite:#f4f7fa}
			.visital-hero{background:#fff;padding:46px 16px 34px;text-align:center;direction:rtl}
			.visital-hero *,.visital-specs *{box-sizing:border-box}
			.visital-hero-title{color:var(--visital-navy);font-size:30px;font-weight:800;line-height:1.55;margin:0 0 12px}
			.visital-hero-subtitle{color:var(--visital-royal);font-size:17px;font-weight:600;line-height:1.7;margin:0 0 26px}
			.visital-hero-search{max-width:820px;margin:0 auto;background:var(--visital-offwhite);border-radius:18px;padding:16px;box-shadow:0 12px 34px rgba(0,47,108,.08);display:flex;flex-direction:column;gap:12px}
			.visital-hero-search-main,.visital-hero-field{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #e3e9f2;border-radius:12px;padding:0 14px;margin:0}
			.visital-hero-search-row{display:flex;gap:12px}
			.visital-hero-search-row .visital-hero-field{flex:1;min-width:0}
			.visital-hero-input,.visital-hero-select{border:0;background:transparent;width:100%;height:52px;font:inherit;color:var(--visital-navy);outline:none;cursor:pointer}
			.visital-hero-input{cursor:text}
			.visital-hero-select{-webkit-appearance:none;-moz-appearance:none;appearance:none;padding-inline-end:6px}
			.visital-hero-ico{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;width:20px;height:20px;color:var(--visital-royal)}
			.visital-hero-ico svg{width:20px;height:20px;display:block}
			.visital-hero-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:52px;border:0;border-radius:12px;background:var(--visital-navy);color:#fff;font-weight:700;font-size:16px;cursor:pointer;transition:background .2s}
			.visital-hero-btn:hover{background:var(--visital-royal)}
			.visital-hero-btn svg{width:20px;height:20px}
			.visital-hero-btn span{color:#fff}
			.visital-hero-consult{display:flex;flex-wrap:wrap;justify-content:center;gap:12px;margin:24px auto 0;max-width:820px}
			.visital-hero-consult-item{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1px solid #e3e9f2;border-radius:30px;padding:10px 16px;color:var(--visital-navy);font-size:14px;font-weight:600;text-decoration:none;transition:border-color .2s,color .2s}
			.visital-hero-consult-item:hover{border-color:var(--visital-gold);color:var(--visital-royal)}
			.visital-hero-consult-item svg{width:18px;height:18px;flex:0 0 auto;color:var(--visital-gold)}
			.visital-specs{background:#fff;padding:14px 16px 44px;text-align:center;direction:rtl}
			.visital-specs-title{color:var(--visital-navy);font-size:23px;font-weight:800;margin:0 0 24px}
			.visital-specs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px;max-width:1040px;margin:0 auto}
			.visital-spec-card{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;background:var(--visital-offwhite);border:1px solid #e3e9f2;border-radius:16px;padding:22px 12px;text-decoration:none;transition:border-color .2s,box-shadow .2s,transform .2s}
			.visital-spec-card:hover{border-color:var(--visital-gold);box-shadow:0 10px 26px rgba(0,47,108,.1);transform:translateY(-2px)}
			.visital-spec-ico{display:inline-flex;align-items:center;justify-content:center;width:54px;height:54px;border-radius:50%;background:#fff;color:var(--visital-royal);box-shadow:0 4px 12px rgba(0,47,108,.06)}
			.visital-spec-ico svg{width:28px;height:28px}
			.visital-spec-img{width:34px;height:34px;object-fit:contain}
			.visital-spec-name{color:var(--visital-navy);font-size:15px;font-weight:700;line-height:1.5}
			.visital-specs-all{margin-top:26px}
			.visital-specs-all-btn{display:inline-flex;align-items:center;justify-content:center;padding:12px 28px;border-radius:40px;background:var(--visital-navy);color:#fff;font-weight:700;font-size:15px;text-decoration:none;transition:background .2s}
			.visital-specs-all-btn:hover{background:var(--visital-royal);color:#fff}
			@media (max-width:600px){
				.visital-hero{padding:32px 14px 26px}
				.visital-hero-title{font-size:21px}
				.visital-hero-subtitle{font-size:14px;margin-bottom:20px}
				.visital-hero-search-row{flex-direction:column}
				.visital-hero-consult-item{font-size:13px;padding:9px 14px}
				.visital-specs-title{font-size:19px}
				.visital-specs-grid{grid-template-columns:repeat(2,1fr);gap:10px}
				.visital-spec-card{padding:18px 8px}
			}';
		}

		private function js() {
			return '
			(function(){
				function ready(fn){if(document.readyState!=="loading"){fn();}else{document.addEventListener("DOMContentLoaded",fn);}}
				ready(function(){
					var data=(window.VisitalHomeData&&window.VisitalHomeData.districts)||{};
					var forms=document.querySelectorAll(".visital-hero-search");
					Array.prototype.forEach.call(forms,function(form){
						var city=form.querySelector("[data-role=\'city\']");
						var district=form.querySelector("[data-role=\'district\']");
						var hidden=form.querySelector(".visital-hero-city-value");
						function fillDistricts(){
							if(!district){return;}
							var list=data[city?city.value:""]||[];
							district.innerHTML="<option value=\'\'>همه مناطق</option>";
							list.forEach(function(d){
								var o=document.createElement("option");
								o.value=d.slug;o.textContent=d.name;
								district.appendChild(o);
							});
						}
						function sync(){
							if(!hidden){return;}
							hidden.value=(district&&district.value)?district.value:(city?city.value:"");
						}
						if(city){city.addEventListener("change",function(){fillDistricts();sync();});}
						if(district){district.addEventListener("change",sync);}
						form.addEventListener("submit",sync);
						sync();
					});
				});
			})();';
		}
	}
}
