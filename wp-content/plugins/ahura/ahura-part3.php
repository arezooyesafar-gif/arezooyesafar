<?php
/**
 * Ahura Import API — Part 3
 * Psychologists category, rating meta, comment hooks, and REST helper routes.
 * Included from ahura.php.
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', 'ahura_register_routes_part3');
add_action('comment_post', 'ahura_rating_hook', 20, 1);
add_action('edit_comment', 'ahura_rating_hook', 20, 1);
add_action('wp_set_comment_status', 'ahura_rating_status_hook', 20, 2);
add_action('deleted_comment', 'ahura_rating_deleted_hook', 20, 2);

/* ---------------- psychology & midwife detection ---------------- */

if (!function_exists('ahura_psych_post_ids')) {
    function ahura_psych_post_ids() {
        $cached = get_transient('ahura_psych_posts_v4');
        if (is_array($cached)) return $cached;
        global $wpdb;
        $table = $wpdb->prefix . 'drplus_specialists';
        $out = array_values(array_filter(array_map('intval', (array) $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$table} WHERE subtitle REGEXP 'روانشناس|مشاوره|رواندرمان' AND post_id > 0"
        ))));
        set_transient('ahura_psych_posts_v4', $out, 12 * HOUR_IN_SECONDS);
        return $out;
    }
}

if (!function_exists('ahura_mid_post_ids')) {
    function ahura_mid_post_ids() {
        $cached = get_transient('ahura_mid_posts_v4');
        if (is_array($cached)) return $cached;
        global $wpdb;
        $table = $wpdb->prefix . 'drplus_specialists';
        $out = array_values(array_filter(array_map('intval', (array) $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$table} WHERE subtitle REGEXP 'ماما|مامایی' AND post_id > 0"
        ))));
        set_transient('ahura_mid_posts_v4', $out, 12 * HOUR_IN_SECONDS);
        return $out;
    }
}

if (!function_exists('ahura_split_post_ids')) {
    function ahura_split_post_ids() {
        $psych = function_exists('ahura_psych_post_ids') ? ahura_psych_post_ids() : array();
        $dent  = function_exists('ahura_dent_post_ids') ? ahura_dent_post_ids() : array();
        $mid   = function_exists('ahura_mid_post_ids') ? ahura_mid_post_ids() : array();
        return array_values(array_unique(array_merge($psych, $dent, $mid)));
    }
}

if (!function_exists('ahura_post_is_psychologist')) {
    function ahura_post_is_psychologist($post_id) {
        return in_array((int) $post_id, ahura_psych_post_ids(), true);
    }
}

/* ---------------- average rating meta ---------------- */

if (!function_exists('ahura_rating_hook')) {
    function ahura_rating_hook($comment_id) {
        $c = get_comment($comment_id);
        if ($c) ahura_recalc_rating_for_post((int) $c->comment_post_ID);
    }
}

if (!function_exists('ahura_rating_status_hook')) {
    function ahura_rating_status_hook($comment_id, $new_status) {
        ahura_rating_hook($comment_id);
    }
}

if (!function_exists('ahura_rating_deleted_hook')) {
    function ahura_rating_deleted_hook($comment_id, $comment = null) {
        if ($comment) ahura_recalc_rating_for_post((int) $comment->comment_post_ID);
    }
}

if (!function_exists('ahura_recalc_rating_for_post')) {
    function ahura_recalc_rating_for_post($post_id) {
        global $wpdb;
        if (!$post_id) return;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS n, SUM(COALESCE(cm.meta_value, 0)) AS s
             FROM {$wpdb->comments} c
             LEFT JOIN {$wpdb->commentmeta} cm ON cm.comment_id = c.comment_ID AND cm.meta_key = '_drplus_star'
             WHERE c.comment_post_ID = %d AND c.comment_approved = '1' AND c.comment_type = 'comment'",
            $post_id), ARRAY_A);
        $n = (int) ($row['n'] ?? 0);
        $avg = 0.0;
        if ($n > 0 && (float) $row['s'] > 0) {
            $avg = round(((float) $row['s']) / $n, 2);
        } else {
            $total = (float) get_post_meta($post_id, '_drplus_total_scores', true);
            if ($total > 0) {
                $avg = round(min(5, $total / max(1, $n)), 2);
            }
        }
        if ($avg > 0) {
            update_post_meta($post_id, 'ahura_rating', $avg);
        } else {
            delete_post_meta($post_id, 'ahura_rating');
        }
    }
}

/* ---------------- routes ---------------- */

function ahura_register_routes_part3() {
    register_rest_route(AHURA_NS, '/psychologists/count', array(
        'methods'  => 'GET',
        'callback' => function () {
            $posts = ahura_psych_post_ids();
            return ahura_ok(array('count' => count($posts)));
        },
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/dentists/count', array(
        'methods'  => 'GET',
        'callback' => function () {
            $posts = function_exists('ahura_dent_post_ids') ? ahura_dent_post_ids() : array();
            return ahura_ok(array('count' => count($posts)));
        },
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/midwives/count', array(
        'methods'  => 'GET',
        'callback' => function () {
            $posts = ahura_mid_post_ids();
            return ahura_ok(array('count' => count($posts)));
        },
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/fix-slugs', array(
        'methods'  => 'POST',
        'callback' => function ($req) {
            global $wpdb;
            $b = $req->get_json_params();
            $ids = (isset($b['post_ids']) && is_array($b['post_ids'])) ? array_map('intval', $b['post_ids']) : null;
            $mode = isset($b['mode']) ? sanitize_key($b['mode']) : 'ascii';
            if ($ids === null) {
                $ids = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='specialist' AND post_status='publish'"));
            }
            $n = 0; $done = array();
            foreach ($ids as $pid) {
                $post = get_post($pid);
                if (!$post) continue;
                $slug = ($mode === 'name') ? $post->post_title : ('doctor-' . $pid);
                $r = wp_update_post(array('ID' => $pid, 'post_name' => $slug), true);
                if (!is_wp_error($r)) { $n++; $done[] = $pid; }
            }
            return ahura_ok(array('fixed' => $n, 'sample' => array_slice($done, 0, 5)));
        },
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/ratings/recalc', array(
        'methods'  => 'POST',
        'callback' => function ($req) {
            global $wpdb;
            $b = $req->get_json_params();
            $ids = $b['post_ids'] ?? null;
            if (!is_array($ids) || !$ids) {
                $ids = $wpdb->get_col('SELECT post_id FROM ' . AHURA_DRPLUS_SPEC . ' WHERE post_id != 0');
            }
            $n = 0;
            foreach ($ids as $pid) {
                ahura_recalc_rating_for_post((int) $pid);
                $n++;
                if ($n >= 800) break;
            }
            return ahura_ok(array('recalculated' => $n));
        },
        'permission_callback' => 'ahura_permission',
    ));
}
