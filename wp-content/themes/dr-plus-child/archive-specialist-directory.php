<?php
use DrPlus\Model\Specialists;
use DrPlus\Utils;
use DrPlus\Utils\UtilsSpecialists;
if ( ! defined( 'ABSPATH' ) ) exit;
$sec = get_query_var( 'specialist_section' );
$titles = array( 'doctors' => 'متخصص‌ها', 'traditional_medicine' => 'پزشکان طب سنتی', 'dentists' => 'دندان‌پزشکان', 'psychologists' => 'روانشناسی', 'veterinary' => 'دامپزشکی', 'occupational_therapy' => 'کاردرمانی', 'search' => 'جستجوی پزشک و متخصص' );
$title = isset( $titles[ $sec ] ) ? $titles[ $sec ] : $titles['search'];
$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$speciality = isset( $_GET['subspeciality'] ) ? sanitize_text_field( wp_unslash( $_GET['subspeciality'] ) ) : ( isset( $_GET['speciality'] ) ? sanitize_text_field( wp_unslash( $_GET['speciality'] ) ) : '' );
$city = isset( $_GET['city'] ) ? sanitize_text_field( wp_unslash( $_GET['city'] ) ) : '';
$category_links = array(
    array( 'متخصص‌ها', 'پزشکی عمومی و تخصصی', '/doctors/', 'doctors', '<path d="M12 3v18M3 12h18"/>', '#2dd4bf' ),
    array( 'دندان‌پزشکان', 'لبخند سالم، از نزدیک', '/dentists/', 'dentists', '<path d="M7.5 3.8c1.6 0 2.7 1.1 4.5 1.1s2.9-1.1 4.5-1.1c2.1 0 3.5 1.8 3.5 4.3 0 3.4-2 4.8-2.7 8.8-.4 2.2-1 3.9-2.4 3.9-1.7 0-1.6-5.2-2.9-5.2s-1.2 5.2-2.9 5.2c-1.4 0-2-1.7-2.4-3.9C6 12.9 4 11.5 4 8.1c0-2.5 1.4-4.3 3.5-4.3Z"/>', '#f59e0b' ),
    array( 'روانشناسی', 'آرامش و همراهی حرفه‌ای', '/psychologists/', 'psychologists', '<path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z"/><path d="M8.5 12a3.5 3.5 0 0 0 7 0M9 8.8h.01M15 8.8h.01"/>', '#fb7185' ),
    array( 'کاردرمانی', 'توانبخشی برای زندگی بهتر', '/occupational-therapy/', 'occupational_therapy', '<path d="M8 11.5V6a2 2 0 0 1 4 0v4M12 9V5.5a2 2 0 0 1 4 0v5M16 10V8a2 2 0 0 1 4 0v5.5c0 3.8-2.8 6.5-6.5 6.5H11c-2.1 0-3.2-.8-4.4-2.2L3.7 14a2 2 0 0 1 2.8-2.8L8 12.7"/>', '#a78bfa' ),
    array( 'دامپزشکی', 'مراقبت از دوست‌های کوچک', '/veterinary/', 'veterinary', '<path d="M8.2 11.8c-1.7 2-3 3.3-3 5.3 0 1.9 1.4 3.1 3.2 3.1 1.5 0 2.4-.8 3.6-.8s2.1.8 3.6.8c1.8 0 3.2-1.2 3.2-3.1 0-2-1.3-3.3-3-5.3M8 8.5a2 2 0 1 0-2-2M16 8.5a2 2 0 1 0 2-2M11 6a2 2 0 1 0-2-2M13 6a2 2 0 1 0 2-2"/>', '#38bdf8' ),
    array( 'طب سنتی', 'درمان‌های مکمل و سنتی', '/traditional-medicine/', 'traditional_medicine', '<path d="M12 3c2.8 3.2 5.5 6.2 5.5 10.1A5.5 5.5 0 1 1 6.5 13.1C6.5 9.2 9.2 6.2 12 3Z"/><path d="M9.5 15.5c.8 1.1 1.6 1.6 2.5 1.6"/>', '#84cc16' )
);
// The city filter lists the cities that actually have published profiles, counted from the data instead of a
// fixed national list: a patient who picks a city with no doctors sees an empty page and concludes the site
// is broken. Counts are cached because this is the busiest page on the site.
$cities = array( '' => 'همه شهرها' );
global $wpdb;
$city_rows = get_transient( 'visital_directory_cities_v1' );
if ( false === $city_rows ) {
    $city_rows = $wpdb->get_results(
        "SELECT t.name, t.slug, COUNT(*) AS total
           FROM {$wpdb->term_relationships} tr
           JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tr.term_taxonomy_id AND tt.taxonomy = 'location'
           JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
           JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'specialist' AND p.post_status = 'publish'
          GROUP BY t.term_id, t.name, t.slug
          ORDER BY total DESC, t.name ASC"
    );
    set_transient( 'visital_directory_cities_v1', $city_rows, 10 * MINUTE_IN_SECONDS );
}
foreach ( (array) $city_rows as $city_row ) {
    if ( ! isset( $cities[ $city_row->slug ] ) ) { $cities[ $city_row->slug ] = $city_row->name; }
}
$city_count = count( $cities ) - 1;
$doctor_count = (int) get_transient( 'visital_directory_doctor_count_v1' );
if ( ! $doctor_count ) {
    $doctor_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'specialist' AND post_status = 'publish'" );
    set_transient( 'visital_directory_doctor_count_v1', $doctor_count, 10 * MINUTE_IN_SECONDS );
}
$fa_digits = static function ( $n ) { return strtr( (string) $n, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) ); };
$doctor_metric = $doctor_count >= 1000 ? $fa_digits( (int) floor( $doctor_count / 1000 ) ) . 'هزار+' : $fa_digits( $doctor_count );
$ids = array();
if ( have_posts() ) { while ( have_posts() ) { the_post(); $ids[] = get_the_ID(); } }
$specialists = null;
if ( $ids ) { $specialists = Specialists::query()->whereIn( 'post_id', $ids )->where( 'status', 'active' )->orderByRaw( 'FIELD(`post_id`, ' . Utils::db_placeholder( $ids, '%d' ) . ')', $ids )->get(); }
$has_filters = ( '' !== $q || '' !== $speciality || '' !== $city );
$result_count = number_format_i18n( (int) $GLOBALS['wp_query']->found_posts );
$search_action = home_url( '/search/' );
get_header();
?>
<main class="visital-directory page-width" dir="rtl">
    <section class="visital-directory-hero" aria-labelledby="visital-directory-title">
        <div class="visital-hero-glow visital-hero-glow-one" aria-hidden="true"></div><div class="visital-hero-glow visital-hero-glow-two" aria-hidden="true"></div>
        <div class="visital-hero-copy">
            <span class="visital-directory-eyebrow"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M3 12h18"/></svg> ویزیتال <i></i> دسترسی سریع به مراقبت</span>
            <h1 id="visital-directory-title"><?php echo esc_html( $title ); ?></h1>
            <p class="visital-directory-lead">متخصص مناسب را با چند کلمه پیدا کنید؛ تخصص، شهر یا نام پزشک را وارد کنید و با خیال راحت انتخاب کنید.</p>
            <div class="visital-hero-metrics" aria-label="اطلاعات ویزیتال"><div><strong><?php echo esc_html( $doctor_metric ); ?></strong><span>متخصص فعال</span></div><div><strong><?php echo esc_html( $fa_digits( count( $category_links ) ) ); ?></strong><span>دسته خدمات</span></div><div><strong><?php echo esc_html( $fa_digits( $city_count ) ); ?></strong><span>شهر پوشش داده‌شده</span></div></div>
        </div>
        <div class="visital-hero-mark" aria-hidden="true"><span></span><span></span><span></span><svg viewBox="0 0 24 24"><path d="M12 3v18M3 12h18"/></svg></div>
        <form class="visital-directory-form" action="<?php echo esc_url( $search_action ); ?>" method="get">
            <div class="visital-search-primary"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></svg><label class="screen-reader-text" for="visital-directory-q">نام پزشک یا تخصص</label><input id="visital-directory-q" name="q" value="<?php echo esc_attr( $q ); ?>" type="search" placeholder="نام پزشک، تخصص یا خدمت درمانی" autocomplete="off"></div><button class="visital-search-submit" type="submit"><span>جستجو کن</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6"/></svg></button>
            <div class="visital-quick-searches" aria-label="جستجوهای محبوب"><span>جستجوی سریع:</span><button type="button" data-visital-query="قلب و عروق">قلب و عروق</button><button type="button" data-visital-query="دندان‌پزشکان">دندان‌پزشکان</button><button type="button" data-visital-query="روانشناسی">روانشناسی</button><button type="button" data-visital-query="کاردرمانی">کاردرمانی</button><button type="button" data-visital-query="طب سنتی">طب سنتی</button></div>
            <details class="visital-filter-details" <?php echo $has_filters ? 'open' : ''; ?>><summary><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg> فیلترهای دقیق‌تر</span><svg class="visital-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary><div class="visital-filter-grid"><div class="visital-field"><label for="visital-directory-speciality">زیرتخصص یا خدمت</label><input id="visital-directory-speciality" name="subspeciality" value="<?php echo esc_attr( $speciality ); ?>" type="search" placeholder="مثلاً قلب و عروق"></div><div class="visital-field"><label for="visital-directory-city">شهر</label><select id="visital-directory-city" name="city"><?php foreach ( $cities as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $city, $slug ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></div><?php if ( $has_filters ) : ?><a class="visital-clear-filters" href="<?php echo esc_url( $search_action ); ?>">پاک کردن فیلترها</a><?php endif; ?></div></details>
        </form>
    </section>
    <section class="visital-directory-categories" aria-labelledby="visital-categories-title"><div class="visital-section-intro"><div><span class="visital-section-kicker">انتخاب مسیر</span><h2 id="visital-categories-title">از کجا شروع کنیم؟</h2></div><p>دسته مناسب را انتخاب کنید تا نتایج دقیق‌تری ببینید.</p></div><div class="visital-category-grid"><?php foreach ( $category_links as $link ) : ?><a class="visital-category-card <?php echo $sec === $link[3] ? 'is-active' : ''; ?>" href="<?php echo esc_url( home_url( $link[2] ) ); ?>" style="--card-accent:<?php echo esc_attr( $link[5] ); ?>"><span class="visital-category-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $link[4]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></svg></span><span class="visital-category-arrow"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg></span><strong><?php echo esc_html( $link[0] ); ?></strong><span><?php echo esc_html( $link[1] ); ?></span></a><?php endforeach; ?></div></section>
    <section class="visital-directory-results" aria-live="polite" aria-labelledby="visital-results-title"><div class="visital-results-heading"><div><span class="visital-section-kicker">نتایج پیدا شده</span><h2 id="visital-results-title"><?php echo esc_html( $title ); ?></h2></div><div class="visital-results-meta"><strong><?php echo esc_html( $result_count ); ?></strong><span>نتیجه</span><?php if ( $has_filters ) : ?><a href="<?php echo esc_url( $search_action ); ?>">نمایش همه</a><?php endif; ?></div></div><?php if ( ! empty( $specialists ) && ! $specialists->isEmpty() ) : ?><div class="specialists specialists-style-card list-specialists visital-directory-grid"><?php ob_start(); UtilsSpecialists::list_html( array( 'specialists' => $specialists, 'settings' => array( 'style' => 'card-1', 'verified-text' => 'تأییدشده توسط ویزیتال' ), 'remove_wrap' => true ) ); $cards_html = ob_get_clean(); $cards_html = preg_replace( '/فوق\s+(?:متخصص|تخصص)/u', '__AHURA_SUPER_SPECIALIST__', $cards_html ); $cards_html = preg_replace( '/(?<!م)تخصص(?=\s|$)/u', 'متخصص', $cards_html ); $cards_html = str_replace( '__AHURA_SUPER_SPECIALIST__', 'فوق تخصص', $cards_html ); echo $cards_html; ?></div><?php else : ?><div class="visital-empty-state"><span class="visital-empty-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5M8 11h6"/></svg></span><strong>نتیجه‌ای پیدا نشد</strong><p>عبارت جستجو را کوتاه‌تر کنید یا یکی از دسته‌بندی‌های بالا را امتحان کنید.</p><a href="<?php echo esc_url( $search_action ); ?>">شروع جستجوی تازه</a></div><?php endif; ?><?php $links = paginate_links( array( 'type' => 'list', 'mid_size' => 2, 'prev_text' => 'قبلی', 'next_text' => 'بعدی', 'total' => max( 1, (int) $GLOBALS['wp_query']->max_num_pages ), 'current' => max( 1, (int) get_query_var( 'paged' ) ), 'add_args' => array_filter( array( 'q' => $q, 'subspeciality' => $speciality, 'city' => $city ) ) ) ); if ( $links ) echo '<nav class="visital-directory-pagination" aria-label="صفحه‌بندی">' . $links . '</nav>'; ?></section>
</main>
<script>(function(){var form=document.querySelector('.visital-directory-form'),input=document.getElementById('visital-directory-q');if(!form||!input)return;form.querySelectorAll('[data-visital-query]').forEach(function(button){button.addEventListener('click',function(){input.value=button.getAttribute('data-visital-query')||'';form.submit();});});var details=form.querySelector('.visital-filter-details');if(details){details.addEventListener('toggle',function(){details.classList.toggle('is-open',details.open);});}})();</script>
<?php get_footer(); ?>

