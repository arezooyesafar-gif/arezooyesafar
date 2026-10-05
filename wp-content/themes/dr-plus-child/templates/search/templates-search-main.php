<?php
/**
 * Child theme override of templates/search/templates-search-main.php
 * Renders categorized search overview with separate specialist sections:
 *   - doctor (پزشک‌ها)
 *   - dentist (دندانپزشکان)
 *   - veterinary (دامپزشکی)
 *   - psychologist (روانشناسی)
 * Strictly omits generic 'specialist' (متخصص‌ها).
 */

use DrPlus\Components\ProIcon;
use DrPlus\Utils;
use DrPlus\Utils\Elementor;
use DrPlus\Utils\Product;
use DrPlus\Utils\Search;
use DrPlus\Utils\UtilsSpecialists;

$categorized_results = function_exists( 'ahura_get_categorized_search_results' ) ? ahura_get_categorized_search_results() : Search::get_categorized_results();

if ( ! empty( $categorized_results ) ) {
    $display_settings = [
        'desktop_slider'        => true,
        'desktop_slides_type'   => 'count',
        'desktop_slides'        => 3,
        'desktop_slides_space'  => 16,
        'desktop_cols'          => 3,
        'desktop_gap'           => 16,

        'tablet_slider'         => true,
        'tablet_slides_type'    => 'auto',
        'tablet_slides'         => 2,
        'tablet_slides_space'   => 16,
        'tablet_cols'           => 2,
        'tablet_gap'            => 16,

        'mobile_slider'         => true,
        'mobile_slides_type'    => 'auto',
        'mobile_slides'         => 1,
        'mobile_slides_space'   => 16,
        'mobile_cols'           => 1,
        'mobile_gap'            => 16,
    ];
    $display_args = Elementor::get_display_attributes( $display_settings, true );
    $specialist_types = [ 'doctor', 'dentist', 'veterinary', 'psychologist', 'specialist' ];

    foreach ( $categorized_results as $post_type => $results ) {
        $div_attrs = [
            'class' => array_merge( [
                'drplus-slider-wrap',
                "drplus-{$post_type}-slider",
                "{$post_type}-wrap",
                'search-section',
            ], $display_args['wrap_classes'] ),
            'data-settings' => $display_args['args'],
            'style'         => $display_args['style'],
        ];

        if ( in_array( $post_type, $specialist_types, true ) ) {
            $div_attrs['class'][] = 'specialists';
        } else if ( $post_type == 'speciality' ) {
            $div_attrs['class'][] = 'specialities';
        } else if ( $post_type == 'hospital' ) {
            $div_attrs['class'][] = 'hospitals';
        } else if ( $post_type == 'product' ) {
            $props = Product::get_loop_props();
            $props = Utils::check_default( $display_settings, $props );
            $props['remove_swiper_div'] = true;
            wc_set_loop_prop( 'drplus_loop_props', $props );

            $div_attrs['class'][] = 'drplus-products-slider';
            $div_attrs['class'][] = 'columns-' . wc_get_loop_prop( 'columns' );
            $div_attrs['class'][] = "products-{$props['style']}";
        } else {
            $div_attrs['class'][] = 'list-posts';
            $div_attrs['class'][] = "posts-{$args['options']['archive_posts_style']}";
        }
        $div_attrs['class'] = apply_filters( 'drplus/search/main/section_classes', $div_attrs['class'], $post_type, $results );

        echo "<div " . Utils::get_html_attributes( $div_attrs ) . ">";
            get_template_part( "templates/search/templates-search-section-head", null, [
                'show_more' => true,
                'icon'      => $results['icon'],
                'label'     => $results['label'],
                'count'     => $results['count'],
                'post_type' => $post_type,
            ] );

            $wrap_attrs = [
                'class' => array_merge( [
                    'wrapper',
                ], $display_args['classes'] ),
            ];

            if ( $post_type == 'product' ) {
                $props = Product::get_loop_props();
                $props = Utils::check_default( $display_settings, $props );
                $props['remove_swiper_div'] = true;
                
                wc_set_loop_prop( 'drplus_loop_props', $props );
                woocommerce_product_loop_start();
            } else {
                echo '<div ' . Utils::get_html_attributes( $wrap_attrs ) . '>';
            }

            if ( in_array( $post_type, $specialist_types, true ) ) {
                UtilsSpecialists::list_html( [
                    'specialists' => $results['results'],
                    'remove_wrap' => true,
                ] );
            } else {
                foreach ( $results['results'] as $post ) {
                    if ( $post_type == 'product' ) {
                        setup_postdata( $GLOBALS['post'] =& $post );
                        $GLOBALS['product'] = wc_get_product();
                        do_action( 'woocommerce_shop_loop' );
                        wc_get_template_part( 'content', 'product' );
                    } else {
                        setup_postdata( $post );
                        $custom_rendered = apply_filters( "drplus/search/main/{$post_type}", false, $post );
                        if ( ! $custom_rendered ) {
                            get_template_part( "templates/archives/template-archives-post", $args['options']['archive_posts_style'], [
                                'title_tag'         => $args['options']['search_post_title_tag'],
                                'time_type'         => $args['options']['search_post_time_type'],
                                'show_read_more'    => $args['options']['search_post_show_read_more'],
                                'read_more_text'    => $args['options']['search_post_read_more_text'],
                                'read_more_icon'    => $args['options']['search_post_read_more_icon'],
                            ] );
                        }
                    }
                }
            }

            if ( $post_type == 'product' ) {
                woocommerce_product_loop_end();
            } else {
                echo "</div>";
            }

            if ( $post_type != 'product' && ! in_array( $post_type, $specialist_types, true ) ) {
                wp_reset_postdata();
            }
        echo "</div>";
    }
} else {
    get_template_part( "templates/search/templates-search-empty" );
}
