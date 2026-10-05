<?php
/**
 * Plugin Name: Visital Overrides
 * Description: Custom overrides for visital.ir (bridging external IDs, optimizing queries)
 * Version: 1.1
 * Author: Ahura
 */

// [Previous code for comments]
add_filter( 'drplus/specialist/single/comments_count', 'visital_fix_comment_count', 10, 2 );
function visital_fix_comment_count( $count, $specialist_id ) {
    global $wpdb;
    $post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}drplus_specialists WHERE id = %d LIMIT 1", $specialist_id ) );
    if ( $post_id ) {
        $real_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_approved = '1'", $post_id ) );
        return (int) $real_count;
    }
    return $count;
}

add_filter( 'get_post_metadata', 'visital_bridge_specialist_code', 10, 4 );
function visital_bridge_specialist_code( $value, $object_id, $meta_key, $single ) {
    if ( 'ahura_specialist_code' === $meta_key ) {
        global $wpdb;
        $code = $wpdb->get_var( $wpdb->prepare( "SELECT specialist_code FROM {$wpdb->prefix}drplus_specialists WHERE post_id = %d LIMIT 1", $object_id ) );
        if ( ! empty( $code ) ) {
            return $single ? $code : [ $code ];
        }
    }
    return $value;
}

// -----------------------------------------------------------------------------
// NEW: Optimized Specialities Widget
// -----------------------------------------------------------------------------

add_action( 'widgets_init', function() {
    // Unregister the slow theme widget
    unregister_widget( '\DrPlus\Widgets\Specialities' );
    // Register our highly optimized one
    register_widget( 'Visital_Optimized_Specialities_Widget' );
}, 99 );

class Visital_Optimized_Specialities_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'drplus_specialities',
            esc_html__( 'Doctor Plus - Specialities (Optimized)', 'drplus' ),
            [ 'description' => __( 'Optimized list of specialities.', 'drplus' ) ]
        );
    }

    public function form( $instance ) {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id( 'title' )); ?>"><?php esc_html_e( 'Title', 'drplus' ); ?>:</label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id( 'title' )); ?>" name="<?php echo esc_attr($this->get_field_name( 'title' )); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" >
        </p>
        <p><em>Note: Count display, hide empty, and multiple selection are enforced for performance.</em></p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        $instance = [];
        $instance['title'] = ( ! empty( $new_instance['title'] ) ) ? sanitize_text_field( $new_instance['title'] ) : '';
        return $instance;
    }

    public function widget( $args, $instance ) {
        $title = apply_filters( 'widget_title', empty($instance['title']) ? '' : $instance['title'], $instance, $this->id_base );

        // Determine current section and location
        $sec = get_query_var( 'specialist_section' );
        $city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
        
        if ( !$city && is_tax( 'location' ) ) {
            $term = get_queried_object();
            if ( $term && !is_wp_error( $term ) ) {
                $city = $term->slug;
            }
        }

        // We'll cache the HTML of the form
        $cache_key = 'visital_widget_specs_' . md5($sec . '_' . $city);
        $cached_html = get_transient( $cache_key );
        
        $active_specialities = [];
        if ( !empty($_GET['specialities']) ) {
            $active_specialities = is_array($_GET['specialities']) ? $_GET['specialities'] : [$_GET['specialities']];
            $active_specialities = array_map('absint', $active_specialities);
        }

        echo $args['before_widget'];
        if ( ! empty( $title ) ) {
            echo $args['before_title'] . $title . $args['after_title'];
        }

        // We can't cache the active state directly in the HTML easily, so we cache the DATA (array of specialities)
        $data_cache_key = 'visital_widget_data_' . md5($sec . '_' . $city);
        $specialities = get_transient( $data_cache_key );

        if ( false === $specialities ) {
            global $wpdb;
            $term_id = 0;
            if ( 'doctors' === $sec ) $term_id = 305;
            elseif ( 'dentists' === $sec ) $term_id = 200;
            elseif ( 'veterinary' === $sec ) $term_id = 224;
            elseif ( 'psychologists' === $sec ) $term_id = 178;

            $join = "";
            $where = "sp.status = 'active'";
            
            if ( $term_id > 0 ) {
                $join .= " JOIN {$wpdb->term_relationships} tr ON sp.post_id = tr.object_id ";
                $join .= " JOIN {$wpdb->term_taxonomy} tt ON (tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'hospital_category' AND tt.term_id = %d) ";
            }
            
            if ( $city ) {
                $city_term = get_term_by( 'slug', $city, 'location' );
                if ( $city_term ) {
                    $join .= " JOIN {$wpdb->term_relationships} tr_loc ON sp.post_id = tr_loc.object_id ";
                    $join .= " JOIN {$wpdb->term_taxonomy} tt_loc ON (tr_loc.term_taxonomy_id = tt_loc.term_taxonomy_id AND tt_loc.taxonomy = 'location' AND tt_loc.term_id = %d) ";
                }
            }

            $sql = "
                SELECT rel.speciality_id as id, p.post_title as name, COUNT(rel.id) as count
                FROM {$wpdb->prefix}drplus_specialist_speciality_rel rel
                JOIN {$wpdb->prefix}drplus_specialists sp ON rel.user_id = sp.user_id
                JOIN {$wpdb->posts} p ON rel.speciality_id = p.ID
                $join
                WHERE $where
                GROUP BY rel.speciality_id
                ORDER BY count DESC
            ";
            
            $prepare_args = [];
            if ( $term_id > 0 ) $prepare_args[] = $term_id;
            if ( $city && $city_term ) $prepare_args[] = $city_term->term_id;
            
            if ( !empty($prepare_args) ) {
                $sql = $wpdb->prepare( $sql, ...$prepare_args );
            }
            
            $specialities = $wpdb->get_results( $sql, ARRAY_A );
            if ( !is_array($specialities) ) $specialities = [];
            
            set_transient( $data_cache_key, $specialities, 12 * HOUR_IN_SECONDS );
        }

        ?>
        <form method="get" action="" class="drplus-specialities-filter">
            <?php if( $city ) { ?>
                <input type="hidden" name="city" value="<?php echo esc_attr( $city ); ?>">
            <?php } ?>
            <?php
            foreach( $specialities as $speciality ) {
                if ( !$speciality['count'] ) continue;
                $active = in_array( $speciality['id'], $active_specialities );
                ?>
                <label class="drplus-specialities-filter-item<?php echo $active ? ' drplus-specialities-filter-item-active' : '' ?>">
                    <input type="checkbox" name="specialities[]" class="drplus-specialities-filter-item-checkbox" value="<?php echo esc_attr( $speciality['id'] ) ?>"<?php checked( true, $active ) ?>>
                    <div class="drplus-specialities-filter-item-text"><?php echo esc_html( $speciality['name'] ) ?></div>
                    <div class="drplus-specialities-filter-item-count"><?php echo esc_html( number_format_i18n( $speciality['count'], 0 ) ) ?></div>
                </label>
            <?php } 
            
            if ( class_exists('\DrPlus\Utils') ) {
                \DrPlus\Utils::query_string_form_fields( null, ['specialities', 'city'] );
            }
            ?>
        </form>
        <?php
        
        echo $args['after_widget'];
    }
}


// Fix Redux iconly.min.css HTTP request bottleneck
add_filter( 'pre_http_request', function( $preempt, $parsed_args, $url ) {
    if ( strpos( $url, 'iconly.min.css' ) !== false ) {
        // Just return empty CSS to satisfy Redux parser without needing to load anything
        // Redux icon_select ONLY parses this file to extract class names for the admin panel dropdown.
        // On the frontend, it doesn't need to parse anything. It just wastes 25 HTTP requests.
        // The actual CSS is enqueued elsewhere.
        return array(
            'headers'  => array(),
            'body'     => '.iconly-broken-user:before { content: "e900"; }', // Dummy class to prevent empty array warnings
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'cookies'  => array(),
            'filename' => null,
        );
    }
    return $preempt;
}, 10, 3 );

