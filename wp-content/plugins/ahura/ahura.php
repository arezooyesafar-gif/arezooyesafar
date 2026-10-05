<?php
/**
 * Plugin Name: Ahura Import API
 * Plugin URI:  https://visital.ir
 * Description: REST API (namespace ahura/v1) for importing scraper data into VisitAl: doctors (specialists), hospitals, specialities, insurances, offices, opening times, authentic comments, users, products, bookings and bulk operations. Key-authenticated, idempotent, batch-capable.
 * Version:     1.0.0
 * Author:      Ahura
 * Author URI:  https://visital.ir
 * Text Domain: ahura
 * Requires PHP: 7.4
 *
 * Endpoints are prefixed /wp-json/ahura/v1/...
 * Auth: header X-Ahura-Key: <key stored in option ahura_api_key>
 */

if (!defined('ABSPATH')) exit;

const AHURA_NS          = 'ahura/v1';
const AHURA_KEY_OPTION  = 'ahura_api_key';
const AHURA_LOG_TABLE   = 'Dj3i_ahura_import_log';
const AHURA_DRPLUS_SPEC = 'Dj3i_drplus_specialists';
const AHURA_REL_HOSP    = 'Dj3i_drplus_specialist_hospitals_rel';
const AHURA_REL_SPEC    = 'Dj3i_drplus_specialist_speciality_rel';
const AHURA_REL_INS     = 'Dj3i_drplus_specialist_insurances_rel';
const AHURA_TIMES       = 'Dj3i_drplus_times';
const AHURA_BOOKINGS    = 'Dj3i_drplus_booking';

/* ------------------------------------------------------------------ */
/* Activation                                                          */
/* ------------------------------------------------------------------ */

register_activation_hook(__FILE__, 'ahura_activate');

function ahura_activate() {
    global $wpdb;

    // API key: generate once, keep stable across reactivations.
    if (!get_option(AHURA_KEY_OPTION)) {
        update_option(AHURA_KEY_OPTION, 'ahura_' . wp_generate_password(40, false, false));
    }

    $charset = $wpdb->get_charset_collate();
    $log = $wpdb->prefix . 'ahura_import_log';

    // Idempotency ledger: one row per external operation key.
    $sql = "CREATE TABLE {$log} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        idem_key VARCHAR(191) NOT NULL,
        route VARCHAR(191) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT '',
        request_hash CHAR(64) NOT NULL DEFAULT '',
        response LONGTEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY idem_key (idem_key)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/* ------------------------------------------------------------------ */
/* Auth + helpers                                                      */
/* ------------------------------------------------------------------ */

add_filter('rest_pre_serve_request', 'ahura_cors_headers', 5, 4);

function ahura_cors_headers($served, $result, $request, $server) {
    if (strpos($request->get_route(), '/' . AHURA_NS) === 0) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, X-Ahura-Key, X-Idempotency-Key');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    }
    return $served;
}

function ahura_permission(WP_REST_Request $req) {
    $key = (string) get_option(AHURA_KEY_OPTION);
    if ($key === '') {
        return new WP_Error('ahura_no_key', 'API key not configured.', array('status' => 500));
    }
    $given = $req->get_header('x-ahura-key');
    if (!$given) {
        $given = $req->get_param('ahura_key'); // convenience fallback; header preferred
    }
    if (!is_string($given) || !hash_equals($key, $given)) {
        return new WP_Error('ahura_forbidden', 'Invalid or missing X-Ahura-Key.', array('status' => 403));
    }
    return true;
}

/**
 * Idempotency: if the request carries X-Idempotency-Key, atomically claim it.
 * First call executes; retries with same key + same payload get the stored response.
 * Same key + different payload -> 409.
 */
function ahura_idempotency_pre(WP_REST_Request $req) {
    $idem = $req->get_header('x-idempotency-key');
    if (!$idem) return null;

    global $wpdb;
    $log = $wpdb->prefix . 'ahura_import_log';
    $route = $req->get_route();
    $hash = hash('sha256', (string) $req->get_body());

    // atomic claim: unique key decides the winner
    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$log} (idem_key, route, status, request_hash, created_at)
         VALUES (%s, %s, 'in_progress', %s, %s)",
        $idem, $route, $hash, current_time('mysql', true)));

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT request_hash, status, response FROM {$log} WHERE idem_key = %s", $idem), ARRAY_A);
    if (!$existing) return null; // ledger unavailable; proceed unguarded

    if ((int) $inserted === 0) {
        // somebody already claimed this key
        if ($existing['request_hash'] !== $hash) {
            return new WP_Error('ahura_idem_conflict',
                'Idempotency key reused with a different payload.',
                array('status' => 409));
        }
        if ($existing['status'] === 'done' && $existing['response']) {
            return new WP_REST_Response(json_decode($existing['response'], true), 200);
        }
        return new WP_Error('ahura_idem_in_progress',
            'A request with this idempotency key is still being processed.',
            array('status' => 409));
    }

    $GLOBALS['ahura_idem_key'] = $idem;
    $GLOBALS['ahura_idem_hash'] = $hash;
    return null;
}

/** Store the final response under the claimed idempotency key. */
function ahura_idempotency_finish($resp) {
    $key = isset($GLOBALS['ahura_idem_key']) ? $GLOBALS['ahura_idem_key'] : null;
    if (!$key) return $resp;

    global $wpdb;
    $log = $wpdb->prefix . 'ahura_import_log';

    if ($resp instanceof WP_REST_Response) {
        $status = $resp->get_status();
        $body   = wp_json_encode($resp->get_data());
    } elseif (is_wp_error($resp)) {
        $d = $resp->get_error_data();
        $status = is_array($d) && isset($d['status']) ? (int) $d['status'] : 500;
        $body   = wp_json_encode(array('error' => $resp->get_error_message()));
    } else {
        $status = 200;
        $body   = wp_json_encode($resp);
    }

    $wpdb->update($log,
        array('status' => 'done', 'response' => (string) $body),
        array('idem_key' => $key, 'request_hash' => $GLOBALS['ahura_idem_hash']));
    unset($GLOBALS['ahura_idem_key'], $GLOBALS['ahura_idem_hash']);
    return $resp;
}

add_filter('rest_pre_dispatch', 'ahura_route_dispatch', 10, 3);

function ahura_route_dispatch($result, $server, $request) {
    if (strpos($request->get_route(), '/' . AHURA_NS) !== 0) return $result;
    if ($request->get_method() === 'OPTIONS') return $result;

    $blocked = ahura_idempotency_pre($request);
    if ($blocked !== null) return $blocked;

    return $result; // normal dispatch; finish() hooks rest_post_dispatch below
}

add_filter('rest_post_dispatch', 'ahura_idempotency_finish', 10, 3);

function ahura_ok($data, $status = 200) {
    return new WP_REST_Response(array('data' => $data), $status);
}

function ahura_fail($code, $message, $status, $details = null) {
    $err = array('code' => $code, 'message' => $message);
    if ($details !== null) $err['details'] = $details;
    return new WP_REST_Response(array('error' => $err), $status);
}

function ahura_error_response(WP_Error $wperr) {
    $status = 500;
    $d = $wperr->get_error_data();
    if (is_array($d) && isset($d['status'])) $status = (int) $d['status'];
    return ahura_fail($wperr->get_error_code(), $wperr->get_error_message(), $status);
}

/** Normalize Persian/Arabic digits to ASCII in a string. */
function ahura_fa_digits($s) {
    if (!is_string($s)) return $s;
    $fa = array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩');
    $en = array('0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9');
    return str_replace($fa, $en, $s);
}

/** Create (or fetch) a WP user with a unique login. Returns array(user_id, created). */
function ahura_ensure_user($login, $email, $display, $role = 'subscriber', $password = null) {
    $existing = null;
    if ($email) {
        $existing = email_exists(ahura_fa_digits($email));
    }
    if (!$existing && $login) {
        $existing = username_exists($login);
    }
    if ($existing) {
        return array((int) $existing, false);
    }
    $uid = wp_insert_user(array(
        'user_login'   => $login,
        'user_pass'    => $password ? $password : wp_generate_password(20, true, true),
        'user_email'   => $email,
        'display_name' => $display,
        'role'         => $role,
    ));
    if (is_wp_error($uid)) return $uid;
    return array((int) $uid, true);
}

/** Upsert a post of given type by ahura_external_id meta or slug. */
function ahura_upsert_post($type, $body, $default_status = 'publish') {
    $title = isset($body['title']) ? sanitize_text_field($body['title']) : '';
    if ($title === '') {
        return new WP_Error('ahura_validation', 'title is required.', array('status' => 422));
    }
    $ext = isset($body['external_id']) ? sanitize_text_field((string) $body['external_id']) : '';

    $post_id = 0;
    if ($ext !== '') {
        $found = get_posts(array(
            'post_type'      => $type,
            'meta_key'       => 'ahura_external_id',
            'meta_value'     => $ext,
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ));
        if ($found) $post_id = (int) $found[0];
    }
    if (!$post_id && !empty($body['slug'])) {
        $found = get_posts(array(
            'post_type'      => $type,
            'name'           => sanitize_title($body['slug']),
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ));
        if ($found) $post_id = (int) $found[0];
    }
    if (!$post_id && $type === 'speciality' && $title) {
        // speciality CPT has no unique slug guarantee; match by title as last resort
        $found = get_posts(array(
            'post_type'      => $type,
            'title'          => $title,
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ));
        if ($found) $post_id = (int) $found[0];
    }

    $fields = array(
        'post_type'    => $type,
        'post_title'   => $title,
        'post_status'  => isset($body['status']) ? sanitize_key($body['status']) : $default_status,
        'post_content' => isset($body['content']) ? wp_kses_post($body['content']) : '',
        'post_excerpt' => isset($body['excerpt']) ? sanitize_textarea_field($body['excerpt']) : '',
    );
    if (!empty($body['slug']))  $fields['post_name'] = sanitize_title($body['slug']);

    $created = false;
    if ($post_id) {
        $fields['ID'] = $post_id;
        $post_id = wp_update_post($fields, true);
    } else {
        $post_id = wp_insert_post($fields, true);
        $created = true;
    }
    if (is_wp_error($post_id)) return $post_id;

    if ($ext !== '') update_post_meta($post_id, 'ahura_external_id', $ext);

    // taxonomy assignment: taxonomy => array of term names
    if (!empty($body['terms']) && is_array($body['terms'])) {
        foreach ($body['terms'] as $tax => $terms) {
            $tax = sanitize_key($tax);
            if (!taxonomy_exists($tax)) continue;
            $ids = array();
            foreach ((array) $terms as $t) {
                $term = get_term_by('name', sanitize_text_field($t), $tax);
                if (!$term) {
                    $res = wp_insert_term(sanitize_text_field($t), $tax);
                    $term = is_wp_error($res) ? null : get_term($res['term_id']);
                }
                if ($term) $ids[] = (int) $term->term_id;
            }
            if ($ids) wp_set_object_terms($post_id, $ids, $tax, false);
        }
    }
    // arbitrary meta
    if (!empty($body['meta']) && is_array($body['meta'])) {
        foreach ($body['meta'] as $k => $v) {
            update_post_meta($post_id, sanitize_key($k), $v);
        }
    }
    return array('id' => $post_id, 'created' => $created);
}

/* ------------------------------------------------------------------ */
/* Routes                                                              */
/* ------------------------------------------------------------------ */

add_action('rest_api_init', 'ahura_register_routes');

function ahura_register_routes() {

    /* ---------------- system ---------------- */

    register_rest_route(AHURA_NS, '/ping', array(
        'methods'  => 'GET',
        'callback' => function () {
            return ahura_ok(array(
                'ok'        => true,
                'plugin'    => 'ahura-import-api',
                'version'   => '1.0.0',
                'site'      => home_url(),
                'time_utc'  => gmdate('c'),
                'wp'        => get_bloginfo('version'),
                'drplus'    => wp_get_theme()->get('Name'),
            ));
        },
        'permission_callback' => '__return_true',
    ));

    register_rest_route(AHURA_NS, '/stats', array(
        'methods'  => 'GET',
        'callback' => function () {
            global $wpdb;
            $q = function ($sql) use ($wpdb) { return (int) $wpdb->get_var($sql); };
            return ahura_ok(array(
                'specialists'  => $q('SELECT COUNT(*) FROM ' . AHURA_DRPLUS_SPEC),
                'hospitals'    => $q("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='hospital' AND post_status='publish'"),
                'specialities' => $q("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='speciality' AND post_status='publish'"),
                'insurances'   => $q("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy='insurance'"),
                'comments'     => $q("SELECT COUNT(*) FROM {$wpdb->comments}"),
                'users'        => $q("SELECT COUNT(*) FROM {$wpdb->users}"),
                'products'     => $q("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product'"),
                'bookings'     => $q('SELECT COUNT(*) FROM ' . AHURA_BOOKINGS),
                'times'        => $q('SELECT COUNT(*) FROM ' . AHURA_TIMES),
            ));
        },
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/import-progress', array(
        array(
            'methods' => 'GET',
            'callback' => 'ahura_import_progress_get',
            'permission_callback' => '__return_true',
        ),
        array(
            'methods' => 'POST',
            'callback' => 'ahura_import_progress_update',
            'permission_callback' => 'ahura_permission',
        ),
    ));

    register_rest_route(AHURA_NS, '/doctors/import-source-licenses', array(
        'methods' => 'POST',
        'callback' => 'ahura_import_source_licenses',
        'permission_callback' => 'ahura_permission',
    ));

    // Verified Nobat-only licence pass. Candidates are pulled directly from
    // active profiles whose saved Nobat URL exists but whose public code is
    // still blank; updates are tied to that exact specialist ID and URL.
    register_rest_route(AHURA_NS, '/doctors/nobat-license-candidates', array(
        'methods' => 'POST',
        'callback' => 'ahura_nobat_license_candidates',
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/doctors/import-verified-nobat-licenses', array(
        'methods' => 'POST',
        'callback' => 'ahura_import_verified_nobat_licenses',
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- doctors (specialists) ---------------- */

    /**
     * POST /doctors  — upsert one doctor.
     * Body: {
     *   external_id, name (required), subtitle, slug, status(active|pending|reject),
     *   offline_visit:bool, online_visit:bool, is_verified:bool,
     *   hospitals:[{external_id|id|title, ...upsert fields}] or [id...],
     *   specialities:[...same], insurances:[names or term ids],
     *   offices:[{id(guid),name,phone,province,city,address,map_url,type,visit_price,enable_booking}],
     *   times:[{office(guid or "default"), day(0-6 sat=0? sun=0 WP), from:"08:00", to:"12:00", status:1}],
     *   post:{title, content, excerpt, slug, status, terms:{tax:[...]}, meta:{...}},
     *   wp_user:{login,email,display,role}  (attach/ensure)
     * }
     */
    register_rest_route(AHURA_NS, '/doctors', array(
        'methods'  => 'POST',
        'callback' => 'ahura_doctor_upsert',
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/doctors/(?P<id>\d+)', array(
        'methods'  => 'GET',
        'callback' => function ($req) {
            global $wpdb;
            $id = (int) $req['id'];
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . AHURA_DRPLUS_SPEC . ' WHERE id = %d', $id), ARRAY_A);
            if (!$row) return ahura_fail('not_found', 'Doctor not found.', 404);
            return ahura_ok(ahura_doctor_hydrate($row));
        },
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/doctors/(?P<id>\d+)', array(
        'methods'  => 'DELETE',
        'callback' => function ($req) {
            global $wpdb;
            $id = (int) $req['id'];
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT id, user_id, post_id FROM ' . AHURA_DRPLUS_SPEC . ' WHERE id = %d', $id), ARRAY_A);
            if (!$row) return ahura_fail('not_found', 'Doctor not found.', 404);
            $wpdb->delete(AHURA_DRPLUS_SPEC, array('id' => $id));
            foreach (array(AHURA_REL_HOSP, AHURA_REL_SPEC, AHURA_REL_INS) as $t) {
                $wpdb->delete($t, array('user_id' => $row['user_id']));
            }
            $wpdb->delete(AHURA_TIMES, array('user_id' => $row['user_id']));
            if ($row['post_id']) wp_delete_post($row['post_id'], true);
            return ahura_ok(array('deleted' => $id));
        },
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- bulk ---------------- */

    /**
     * POST /doctors/bulk
     * Body: { items:[ ...same shape as POST /doctors ], continue_on_error:true }
     * -> { data: { total, created, updated, failed, errors:[{index, error}] } }
     */
    register_rest_route(AHURA_NS, '/doctors/bulk', array(
        'methods'  => 'POST',
        'callback' => 'ahura_doctor_bulk',
        'permission_callback' => 'ahura_permission',
    ));

    // Repair category links in bounded batches. Imported source pages can
    // attach a whole category cluster to one doctor; the profile subtitle is
    // the authoritative primary qualification for the directory.
    register_rest_route(AHURA_NS, '/doctors/repair-primary-speciality', array(
        'methods'  => 'POST',
        'callback' => function ( $req ) {
            global $wpdb;
            $body = $req->get_json_params();
            $cursor = max( 0, (int) ( $body['cursor'] ?? 0 ) );
            $limit = min( 5000, max( 100, (int) ( $body['limit'] ?? 1000 ) ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                'SELECT id FROM ' . AHURA_DRPLUS_SPEC . " WHERE status='active' AND id > %d ORDER BY id ASC LIMIT %d",
                $cursor,
                $limit
            ), ARRAY_A );
            if ( empty( $rows ) ) return ahura_ok( array(
                'processed' => 0, 'changed' => 0, 'next_cursor' => 0, 'complete' => true,
            ) );

            $last_id = (int) end( $rows )['id'];
            $range = $wpdb->prepare(
                's.status=%s AND s.id > %d AND s.id <= %d AND s.subtitle <> %s',
                'active', $cursor, $last_id, ''
            );
            $matched = (int) $wpdb->get_var(
                'SELECT COUNT(DISTINCT s.id) FROM ' . AHURA_DRPLUS_SPEC . ' s INNER JOIN ' . $wpdb->posts . ' sp ON sp.post_type=\'speciality\' AND sp.post_status=\'publish\' AND sp.post_title=s.subtitle WHERE ' . $range
            );
            if ( ! empty( $body['dry_run'] ) ) return ahura_ok( array(
                'processed' => count( $rows ), 'matched' => $matched, 'next_cursor' => $last_id, 'complete' => false,
            ) );

            $wpdb->query( 'START TRANSACTION' );
            try {
                // Only rows with a category exactly matching their declared
                // subtitle are touched. Profiles without such a category keep
                // their existing relation until they can be reviewed.
                $deleted = $wpdb->query(
                    'DELETE rel FROM ' . AHURA_REL_SPEC . ' rel INNER JOIN ' . AHURA_DRPLUS_SPEC . ' s ON s.user_id=rel.user_id INNER JOIN ' . $wpdb->posts . ' sp ON sp.post_type=\'speciality\' AND sp.post_status=\'publish\' AND sp.post_title=s.subtitle WHERE ' . $range
                );
                $inserted = $wpdb->query(
                    'INSERT INTO ' . AHURA_REL_SPEC . ' (user_id, speciality_id) SELECT s.user_id, MIN(sp.ID) FROM ' . AHURA_DRPLUS_SPEC . ' s INNER JOIN ' . $wpdb->posts . ' sp ON sp.post_type=\'speciality\' AND sp.post_status=\'publish\' AND sp.post_title=s.subtitle WHERE ' . $range . ' GROUP BY s.user_id'
                );
                if ( false === $deleted || false === $inserted ) throw new Exception( $wpdb->last_error ?: 'Category repair failed.' );
                $wpdb->query( 'COMMIT' );
            } catch ( Exception $error ) {
                $wpdb->query( 'ROLLBACK' );
                return ahura_fail( 'category_repair_failed', $error->getMessage(), 500 );
            }
            return ahura_ok( array(
                'processed' => count( $rows ), 'matched' => $matched,
                'deleted' => (int) $deleted, 'changed' => (int) $inserted,
                'next_cursor' => $last_id, 'complete' => false,
            ) );
        },
        'permission_callback' => 'ahura_permission',
    ));

    // Archive only high-confidence duplicate profiles: same full licence and
    // same display name. The most complete source-backed profile remains live;
    // its siblings' comments are moved to it before they are hidden.
    register_rest_route(AHURA_NS, '/doctors/archive-reliable-duplicates', array(
        'methods'  => 'POST',
        'callback' => function ( $req ) {
            global $wpdb;
            $body = $req->get_json_params();
            $rows = $wpdb->get_results(
                "SELECT s.id,s.user_id,s.post_id,s.name,s.meta,um.meta_value AS licence,p.comment_count
                 FROM " . AHURA_DRPLUS_SPEC . " s
                 JOIN {$wpdb->usermeta} um ON um.user_id=s.user_id AND um.meta_key='specialist_code' AND um.meta_value REGEXP '^[0-9]{4,}$'
                 JOIN {$wpdb->posts} p ON p.ID=s.post_id AND p.post_type='specialist' AND p.post_status='publish'
                 WHERE s.status='active'
                 ORDER BY um.meta_value,s.name,p.comment_count DESC,s.id DESC",
                ARRAY_A
            );
            $groups = array();
            foreach ( $rows as $row ) $groups[ $row['licence'] . "\x1f" . $row['name'] ][] = $row;
            $plans = array();
            foreach ( $groups as $group ) {
                if ( count( $group ) < 2 ) continue;
                usort( $group, static function( $a, $b ) {
                    $a_source = false !== strpos( (string) $a['meta'], 'profile_url' ) ? 1 : 0;
                    $b_source = false !== strpos( (string) $b['meta'], 'profile_url' ) ? 1 : 0;
                    if ( $a_source !== $b_source ) return $b_source <=> $a_source;
                    if ( (int) $a['comment_count'] !== (int) $b['comment_count'] ) return (int) $b['comment_count'] <=> (int) $a['comment_count'];
                    return (int) $b['id'] <=> (int) $a['id'];
                } );
                $plans[] = array( 'winner' => $group[0], 'losers' => array_slice( $group, 1 ) );
            }
            $planned = array_sum( array_map( static function( $plan ) { return count( $plan['losers'] ); }, $plans ) );
            if ( ! empty( $body['dry_run'] ) ) return ahura_ok( array( 'groups' => count( $plans ), 'profiles_to_archive' => $planned ) );

            $archived = 0; $moved_comments = 0;
            foreach ( $plans as $plan ) {
                $winner_id = (int) $plan['winner']['post_id'];
                foreach ( $plan['losers'] as $loser ) {
                    $loser_post_id = (int) $loser['post_id'];
                    $moved = $wpdb->query( $wpdb->prepare(
                        "UPDATE {$wpdb->comments} SET comment_post_ID=%d WHERE comment_post_ID=%d",
                        $winner_id, $loser_post_id
                    ) );
                    $moved_comments += max( 0, (int) $moved );
                    update_post_meta( $loser_post_id, 'ahura_duplicate_of', $winner_id );
                    wp_update_post( array( 'ID' => $loser_post_id, 'post_status' => 'draft' ) );
                    $wpdb->update( AHURA_DRPLUS_SPEC, array( 'status' => 'reject' ), array( 'id' => (int) $loser['id'] ) );
                    $archived++;
                }
                wp_update_comment_count( $winner_id );
            }
            return ahura_ok( array( 'groups' => count( $plans ), 'archived' => $archived, 'moved_comments' => $moved_comments ) );
        },
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- comments (authentic) ---------------- */

    /**
     * POST /comments
     * Body: {
     *   post_id (required)  — WP post to comment on (doctor post ID or any post)
     *   author_name, author_email, content (required),
     *   date:"2026-09-01 12:30:00" (optional; default now),
     *   approved:"1"|"0"|"spam"|"trash" (default "1"),
     *   user_id (optional), parent (optional comment id), rating (optional 1-5 -> comment meta),
     *   external_id (optional; dedupe), meta:{k:v}
     * }
     */
    register_rest_route(AHURA_NS, '/comments', array(
        'methods'  => 'POST',
        'callback' => 'ahura_comment_create',
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/comments/bulk', array(
        'methods'  => 'POST',
        'callback' => 'ahura_comment_bulk',
        'permission_callback' => 'ahura_permission',
    ));

    // Import public reviews from a source profile without relying on a name
    // match. This keeps comments attached to the correct imported profile.
    register_rest_route(AHURA_NS, '/comments/import-source-reviews', array(
        'methods'  => 'POST',
        'callback' => function ( $req ) {
            global $wpdb;
            $body = $req->get_json_params();
            $items = $body['items'] ?? array();
            if ( ! is_array( $items ) || empty( $items ) ) return ahura_fail( 'validation_error', 'items are required.', 422 );
            if ( count( $items ) > 500 ) return ahura_fail( 'validation_error', 'Maximum 500 reviews per request.', 422 );
            $source = sanitize_key( $body['source'] ?? 'import' );
            $resolved = array(); $created = 0; $duplicates = 0; $missing = 0; $failed = 0;
            foreach ( $items as $item ) {
                $url = esc_url_raw( $item['source_url'] ?? '' );
                $source_id = sanitize_text_field( $item['source_id'] ?? '' );
                $cache_key = $url ?: $source . ':' . $source_id;
                if ( ! array_key_exists( $cache_key, $resolved ) ) {
                    $post_id = 0;
                    if ( $url ) {
                        // Specialist meta is JSON encoded, so its forward
                        // slashes are stored as `\/`. Match that stored form
                        // rather than comparing the human-facing URL literal.
                        $stored_url = str_replace( '/', '\\/', $url );
                        $post_id = (int) $wpdb->get_var( $wpdb->prepare(
                            'SELECT post_id FROM ' . AHURA_DRPLUS_SPEC . " WHERE status='active' AND meta LIKE %s ORDER BY id DESC LIMIT 1",
                            '%' . $wpdb->esc_like( $stored_url ) . '%'
                        ) );
                    }
                    if ( ! $post_id && $source_id !== '' ) {
                        $external_id = $source . '-' . $source_id;
                        $post_id = (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id AND p.post_type='specialist' AND p.post_status='publish' WHERE pm.meta_key='ahura_external_id' AND pm.meta_value=%s LIMIT 1",
                            $external_id
                        ) );
                    }
                    $resolved[ $cache_key ] = $post_id;
                }
                $post_id = (int) $resolved[ $cache_key ];
                $content = trim( (string) ( $item['content'] ?? '' ) );
                if ( ! $post_id || $content === '' ) { $missing++; continue; }
                $result = ahura_comment_create( null, array(
                    'post_id' => $post_id,
                    'content' => $content,
                    'author_name' => sanitize_text_field( $item['author_name'] ?? 'بیمار' ),
                    'date' => sanitize_text_field( $item['date'] ?? '' ),
                    'rating' => isset( $item['rating'] ) ? (int) $item['rating'] : null,
                    'external_id' => sanitize_text_field( $item['external_id'] ?? '' ),
                    'approved' => '1',
                    'meta' => array( 'source' => $source, 'source_url' => $url ),
                ) );
                if ( ! ( $result instanceof WP_REST_Response ) || $result->get_status() >= 300 ) { $failed++; continue; }
                $data = $result->get_data();
                $data = $data['data'] ?? $data;
                if ( empty( $data['created'] ) ) $duplicates++; else $created++;
            }
            return ahura_ok( array(
                'total' => count( $items ), 'created' => $created, 'duplicates' => $duplicates,
                'missing_profile' => $missing, 'failed' => $failed,
            ) );
        },
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/comments', array(
        'methods'  => 'GET',
        'callback' => function ($req) {
            global $wpdb;
            $post_id = (int) $req->get_param('post_id');
            $page = max(1, (int) $req->get_param('page'));
            $per  = min(100, max(1, (int) ($req->get_param('per_page') ?: 20)));
            $where = $post_id ? $wpdb->prepare('WHERE comment_post_ID = %d', $post_id) : '';
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} $where");
            $off = ($page - 1) * $per;
            $rows = $wpdb->get_results(
                "SELECT * FROM {$wpdb->comments} $where ORDER BY comment_ID ASC LIMIT $per OFFSET $off", ARRAY_A);
            return ahura_ok($rows, 200);
        },
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/comments/(?P<id>\d+)', array(
        'methods'  => 'DELETE',
        'callback' => function ($req) {
            $id = (int) $req['id'];
            $c = get_comment($id);
            if (!$c) return ahura_fail('not_found', 'Comment not found.', 404);
            wp_delete_comment($id, true);
            return ahura_ok(array('deleted' => $id));
        },
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- users ---------------- */

    register_rest_route(AHURA_NS, '/users', array(
        'methods'  => 'POST',
        'callback' => function ($req) {
            $b = $req->get_json_params();
            $login = sanitize_user(ahura_fa_digits($b['login'] ?? ''), true);
            if (!$login) return ahura_fail('validation_error', 'login is required.', 422);
            $res = ahura_ensure_user(
                $login,
                isset($b['email']) ? sanitize_email(ahura_fa_digits($b['email'])) : '',
                isset($b['display']) ? sanitize_text_field($b['display']) : $login,
                isset($b['role']) ? sanitize_key($b['role']) : 'subscriber',
                $b['password'] ?? null
            );
            if (is_wp_error($res)) return ahura_error_response($res);
            return ahura_ok(array('user_id' => $res[0], 'created' => $res[1]), $res[1] ? 201 : 200);
        },
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- hospitals / specialities (CPT upserts) ---------------- */

    register_rest_route(AHURA_NS, '/hospitals', array(
        'methods'  => 'POST',
        'callback' => function ($req) {
            $r = ahura_upsert_post('hospital', $req->get_json_params());
            if (is_wp_error($r)) return ahura_error_response($r);
            return ahura_ok($r, $r['created'] ? 201 : 200);
        },
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/specialities', array(
        'methods'  => 'POST',
        'callback' => function ($req) {
            $r = ahura_upsert_post('speciality', $req->get_json_params());
            if (is_wp_error($r)) return ahura_error_response($r);
            return ahura_ok($r, $r['created'] ? 201 : 200);
        },
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- insurances (terms) ---------------- */

    register_rest_route(AHURA_NS, '/insurances', array(
        'methods'  => 'POST',
        'callback' => function ($req) {
            $b = $req->get_json_params();
            $name = sanitize_text_field($b['name'] ?? '');
            if (!$name) return ahura_fail('validation_error', 'name is required.', 422);
            $term = get_term_by('name', $name, 'insurance');
            if (!$term) {
                $res = wp_insert_term($name, 'insurance', array('slug' => $b['slug'] ?? null));
                if (is_wp_error($res) && $res->get_error_code() === 'term_exists') {
                    $term = get_term($res->get_error_data()['term_id'], 'insurance');
                } elseif (is_wp_error($res)) {
                    return ahura_error_response($res);
                } else {
                    $term = get_term($res['term_id'], 'insurance');
                }
            }
            return ahura_ok(array('term_id' => (int) $term->term_id, 'name' => $term->name), 201);
        },
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- opening times ---------------- */

    register_rest_route(AHURA_NS, '/times', array(
        'methods'  => 'POST',
        'callback' => 'ahura_times_upsert',
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/times/bulk', array(
        'methods'  => 'POST',
        'callback' => function ($req) {
            global $wpdb;
            $b = $req->get_json_params();
            $items = $b['items'] ?? array();
            $done = 0; $failed = array();
            foreach ($items as $i => $item) {
                $r = ahura_times_upsert(null, $item);
                if ($r instanceof WP_REST_Response && $r->get_status() < 300) $done++;
                else $failed[] = array('index' => $i, 'error' => $r->get_data()['error'] ?? 'unknown');
            }
            return ahura_ok(array('done' => $done, 'failed' => $failed));
        },
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- bookings ---------------- */

    register_rest_route(AHURA_NS, '/bookings', array(
        'methods'  => 'POST',
        'callback' => 'ahura_booking_create',
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- products (WooCommerce) ---------------- */

    register_rest_route(AHURA_NS, '/products', array(
        'methods'  => 'POST',
        'callback' => function ($req) {
            $b = $req->get_json_params();
            $r = ahura_upsert_post('product', $b);
            if (is_wp_error($r)) return ahura_error_response($r);
            $pid = $r['id'];
            if (!empty($b['regular_price']) || !empty($b['sale_price'])) {
                update_post_meta($pid, '_regular_price', (string) ahura_fa_digits($b['regular_price'] ?? ''));
                update_post_meta($pid, '_sale_price', (string) ahura_fa_digits($b['sale_price'] ?? ''));
                $price = ($b['sale_price'] ?? '') ?: ($b['regular_price'] ?? '');
                update_post_meta($pid, '_price', (string) ahura_fa_digits($price));
                wp_set_object_terms($pid, 'simple', 'product_type');
            }
            if (isset($b['stock_status'])) {
                update_post_meta($pid, '_stock_status', sanitize_key($b['stock_status']));
                wc_update_product_stock_status($pid, $b['stock_status'] === 'instock' ? 1 : 0);
            }
            return ahura_ok($r, $r['created'] ? 201 : 200);
        },
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- attachments / media ---------------- */

    register_rest_route(AHURA_NS, '/media', array(
        'methods'  => 'POST',
        'callback' => 'ahura_media_upload',
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- admin ---------------- */

    register_rest_route(AHURA_NS, '/key/rotate', array(
        'methods'  => 'POST',
        'callback' => function () {
            $new = 'ahura_' . wp_generate_password(40, false, false);
            update_option(AHURA_KEY_OPTION, $new);
            return ahura_ok(array('rotated' => true));
        },
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/logs', array(
        'methods'  => 'GET',
        'callback' => function ($req) {
            global $wpdb;
            $log = $wpdb->prefix . 'ahura_import_log';
            $rows = $wpdb->get_results(
                "SELECT idem_key, route, status, created_at FROM {$log} ORDER BY id DESC LIMIT 50", ARRAY_A);
            return ahura_ok($rows);
        },
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- terms / categories / taxonomies ---------------- */

    /**
     * GET  /terms?taxonomy=hospital_category  — list terms of a taxonomy
     * POST /terms  — upsert one term:
     *   {"taxonomy":"hospital_category","name":"کلینیک تخصصی","slug":"optional",
     *    "description":"","parent":0}
     * Allowed taxonomies (whitelist): hospital_category, hospital-service, location,
     * insurance, speciality_tag, product_cat, product_tag, post_tag, category, identity_type.
     */
    register_rest_route(AHURA_NS, '/terms', array(
        array(
            'methods'  => 'GET',
            'callback' => function ($req) {
                global $wpdb;
                $tax = sanitize_key((string) $req->get_param('taxonomy'));
                if ($tax === '') return ahura_fail('validation_error', 'taxonomy is required.', 422);
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT t.term_id, t.name, t.slug, tt.description, tt.parent, tt.count
                     FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                     WHERE tt.taxonomy = %s ORDER BY t.name", $tax), ARRAY_A);
                return ahura_ok($rows);
            },
            'permission_callback' => 'ahura_permission',
        ),
        array(
            'methods'  => 'POST',
            'callback' => 'ahura_term_upsert',
            'permission_callback' => 'ahura_permission',
        ),
    ));

    register_rest_route(AHURA_NS, '/terms/bulk', array(
        'methods'  => 'POST',
        'callback' => function ($req) {
            $b = $req->get_json_params();
            $items = $b['items'] ?? array();
            if (!is_array($items) || !$items) return ahura_fail('validation_error', 'items must be a non-empty array.', 422);
            $done = 0; $failed = array();
            foreach ($items as $i => $item) {
                $r = ahura_term_upsert(null, $item);
                if ($r instanceof WP_REST_Response && $r->get_status() < 300) $done++;
                else $failed[] = array('index' => $i, 'error' => $r->get_data()['error'] ?? 'unknown');
            }
            return ahura_ok(array('total' => count($items), 'done' => $done, 'failed' => $failed));
        },
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- doctor stats / scores / views ---------------- */

    /**
     * POST /doctors/{id}/stats — upsert a stat row (rating, review count, ...)
     *   {"user_id":N} required OR doctor id in URL; body: {"key":"score","value":"4.5"}
     * Multiple stats per doctor live in Dj3i_drplus_specialists_statics.
     */
    register_rest_route(AHURA_NS, '/doctors/(?P<id>\d+)/stats', array(
        array(
            'methods'  => 'POST',
            'callback' => function ($req) {
                global $wpdb;
                $spec_id = (int) $req['id'];
                $b = $req->get_json_params();
                if (!is_array($b)) return ahura_fail('invalid_json', 'Body must be JSON.', 400);
                $key = sanitize_text_field((string) ($b['key'] ?? ''));
                $value = (string) ($b['value'] ?? '');
                if ($key === '' || $value === '') {
                    return ahura_fail('validation_error', 'key and value are required.', 422);
                }
                $user_id = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT user_id FROM ' . AHURA_DRPLUS_SPEC . ' WHERE id=%d', $spec_id));
                if (!$user_id) return ahura_fail('not_found', 'Doctor not found.', 404);

                $now = current_time('mysql', true);
                $existing = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT id FROM Dj3i_drplus_specialists_statics WHERE user_id=%d AND static_name=%s',
                    $user_id, $key));
                if ($existing) {
                    $wpdb->update('Dj3i_drplus_specialists_statics',
                        array('static_value' => $value, 'updated_at' => $now),
                        array('id' => $existing));
                    return ahura_ok(array('stat_id' => $existing, 'created' => false), 200);
                }
                $wpdb->insert('Dj3i_drplus_specialists_statics', array(
                    'user_id' => $user_id, 'static_name' => $key, 'static_value' => $value,
                    'created_at' => $now, 'updated_at' => $now,
                ));
                // keep the theme's star display in sync: avg = _drplus_total_scores / approved comments
                if ($key === 'score' && is_numeric($value)) {
                    $post_id = (int) $wpdb->get_var($wpdb->prepare(
                        'SELECT post_id FROM ' . AHURA_DRPLUS_SPEC . ' WHERE id=%d', $spec_id));
                    if ($post_id) {
                        $cnt = (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID=%d AND comment_approved='1'",
                            $post_id));
                        if ($cnt) update_post_meta($post_id, '_drplus_total_scores',
                            (string) round(((float) $value) * $cnt, 1));
                    }
                }
                return ahura_ok(array('stat_id' => (int) $wpdb->insert_id, 'created' => true), 201);
            },
            'permission_callback' => 'ahura_permission',
        ),
        array(
            'methods'  => 'GET',
            'callback' => function ($req) {
                global $wpdb;
                $spec_id = (int) $req['id'];
                $user_id = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT user_id FROM ' . AHURA_DRPLUS_SPEC . ' WHERE id=%d', $spec_id));
                if (!$user_id) return ahura_fail('not_found', 'Doctor not found.', 404);
                $rows = $wpdb->get_results($wpdb->prepare(
                    'SELECT static_name, static_value FROM Dj3i_drplus_specialists_statics WHERE user_id=%d',
                    $user_id), ARRAY_A);
                return ahura_ok($rows);
            },
            'permission_callback' => 'ahura_permission',
        ),
    ));

    /** POST /doctors/{id}/views — increment or set the profile post view counter. */
    register_rest_route(AHURA_NS, '/doctors/(?P<id>\d+)/views', array(
        'methods'  => 'POST',
        'callback' => function ($req) {
            global $wpdb;
            $spec_id = (int) $req['id'];
            $post_id = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT post_id FROM ' . AHURA_DRPLUS_SPEC . ' WHERE id=%d', $spec_id));
            if (!$post_id) return ahura_fail('not_found', 'Doctor not found.', 404);
            $b = $req->get_json_params();
            $views = isset($b['views']) ? max(0, (int) ahura_fa_digits((string) $b['views'])) : null;
            if ($views === null) {
                $current = (int) get_post_meta($post_id, '_views', true);
                $views = $current + 1;
            }
            update_post_meta($post_id, '_views', $views);
            return ahura_ok(array('views' => $views), 200);
        },
        'permission_callback' => 'ahura_permission',
    ));

    /** POST /doctors/{id}/license — set the doctor's شماره نظام پزشکی (user meta specialist_code). */
    register_rest_route(AHURA_NS, '/doctors/(?P<id>\d+)/license', array(
        'methods'  => 'POST',
        'callback' => function ($req) {
            global $wpdb;
            $spec_id = (int) $req['id'];
            $b = $req->get_json_params();
            $lic = sanitize_text_field(ahura_fa_digits((string) ($b['license'] ?? '')));
            if ($lic === '') return ahura_fail('validation_error', 'license is required.', 422);
            $user_id = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT user_id FROM ' . AHURA_DRPLUS_SPEC . ' WHERE id=%d', $spec_id));
            if (!$user_id) return ahura_fail('not_found', 'Doctor not found.', 404);
            update_user_meta($user_id, 'specialist_code', $lic);
            return ahura_ok(array('specialist_id' => $spec_id, 'user_id' => $user_id, 'license' => $lic), 200);
        },
        'permission_callback' => 'ahura_permission',
    ));

    /** POST /doctors/{id}/thumbnail — attach uploaded media id as featured image. */
    register_rest_route(AHURA_NS, '/doctors/(?P<id>\d+)/thumbnail', array(
        'methods'  => 'POST',
        'callback' => function ($req) {
            global $wpdb;
            $spec_id = (int) $req['id'];
            $post_id = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT post_id FROM ' . AHURA_DRPLUS_SPEC . ' WHERE id=%d', $spec_id));
            if (!$post_id) return ahura_fail('not_found', 'Doctor not found.', 404);
            $b = $req->get_json_params();
            $att = (int) ($b['attachment_id'] ?? 0);
            if (!$att || !get_post($att)) return ahura_fail('validation_error', 'attachment_id is required.', 422);
            set_post_thumbnail($post_id, $att);
            return ahura_ok(array('thumbnail_id' => $att), 200);
        },
        'permission_callback' => 'ahura_permission',
    ));
}

function ahura_import_source_licenses(WP_REST_Request $req) {
    global $wpdb;
    $body = $req->get_json_params();
    $source = sanitize_key($body['source'] ?? '');
    $items = $body['items'] ?? array();
    if ($source === '' || !is_array($items) || !$items) return ahura_fail('validation_error', 'source and items are required.', 422);
    $items = array_slice($items, 0, 500);
    $updated = 0; $unchanged = 0; $missing = 0; $invalid = 0;
    foreach ($items as $item) {
        $license = sanitize_text_field(ahura_fa_digits((string) ($item['license'] ?? '')));
        if ($license === '' || strlen($license) < 4) { $invalid++; continue; }
        $url = esc_url_raw((string) ($item['source_url'] ?? ''));
        $stored_url = str_replace('/', '\\/', $url);
        $source_id = sanitize_text_field((string) ($item['source_id'] ?? ''));
        $row = null;
        // External IDs are indexed and avoid a slow wildcard URL scan for the
        // large Nobat import. URL matching remains as a fallback for older
        // profiles that predate the external-id metadata.
        if ($source_id !== '') {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT s.id,s.user_id FROM ' . AHURA_DRPLUS_SPEC . ' s JOIN ' . $wpdb->postmeta . ' pm ON pm.post_id=s.post_id WHERE s.status=\'active\' AND pm.meta_key=\'ahura_external_id\' AND pm.meta_value=%s LIMIT 1',
                $source . '-' . $source_id), ARRAY_A);
        }
        if (!$row && $stored_url !== '') {
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT s.id,s.user_id FROM ' . AHURA_DRPLUS_SPEC . ' s LEFT JOIN ' . $wpdb->postmeta . ' pm ON pm.post_id=s.post_id AND pm.meta_key IN (\'ahura_profile_url\',\'profile_url\') WHERE s.status=\'active\' AND (s.meta LIKE %s OR pm.meta_value=%s) LIMIT 1',
                '%' . $wpdb->esc_like($stored_url) . '%', $url), ARRAY_A);
        }
        if (!$row || empty($row['user_id'])) { $missing++; continue; }
        $existing = sanitize_text_field(ahura_fa_digits((string) get_user_meta((int) $row['user_id'], 'specialist_code', true)));
        if ($existing !== '') { $unchanged++; continue; }
        update_user_meta((int) $row['user_id'], 'specialist_code', $license);
        $updated++;
    }
    return ahura_ok(array('total' => count($items), 'updated' => $updated, 'unchanged' => $unchanged, 'missing_profile' => $missing, 'invalid' => $invalid));
}

/** Return the canonical Nobat URL stored in a specialist's source metadata. */
function ahura_nobat_profile_url_from_meta($raw_meta) {
    $meta = is_array($raw_meta) ? $raw_meta : json_decode((string) $raw_meta, true);
    if (!is_array($meta)) return '';
    foreach (array('nobat_profile_url', 'profile_url', 'source_url') as $key) {
        $url = isset($meta[$key]) ? esc_url_raw((string) $meta[$key]) : '';
        if ($url && preg_match('#^https?://(?:www\.)?nobat\.ir(?:/|$)#i', $url)) {
            return rtrim($url, '/');
        }
    }
    return '';
}

/**
 * Page through precisely the active, public profiles that have a Nobat source
 * URL and no visible specialist_code. This deliberately does not use fuzzy
 * name matching or a URL substring match.
 */
function ahura_nobat_license_candidates(WP_REST_Request $req) {
    global $wpdb;
    $body = $req->get_json_params();
    $cursor = max(0, (int) ($body['cursor'] ?? 0));
    $limit = min(100, max(10, (int) ($body['limit'] ?? 25)));
    $base_where = "s.status='active' AND p.post_type='specialist' AND p.post_status='publish' AND (um.meta_value IS NULL OR um.meta_value='') AND s.meta LIKE '%nobat.ir%'";
    $total = (int) $wpdb->get_var(
        'SELECT COUNT(*) FROM ' . AHURA_DRPLUS_SPEC . ' s JOIN ' . $wpdb->posts . ' p ON p.ID=s.post_id LEFT JOIN ' . $wpdb->usermeta . " um ON um.user_id=s.user_id AND um.meta_key='specialist_code' WHERE " . $base_where
    );
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT s.id,s.user_id,s.name,s.meta FROM ' . AHURA_DRPLUS_SPEC . ' s JOIN ' . $wpdb->posts . ' p ON p.ID=s.post_id LEFT JOIN ' . $wpdb->usermeta . " um ON um.user_id=s.user_id AND um.meta_key='specialist_code' WHERE " . $base_where . ' AND s.id > %d ORDER BY s.id ASC LIMIT %d',
        $cursor, $limit
    ), ARRAY_A);
    $items = array();
    $next_cursor = $cursor;
    foreach ($rows as $row) {
        $next_cursor = max($next_cursor, (int) $row['id']);
        $url = ahura_nobat_profile_url_from_meta($row['meta']);
        if (!$url) continue;
        $items[] = array('specialist_id' => (int) $row['id'], 'name' => (string) $row['name'], 'source_url' => $url);
    }
    return ahura_ok(array(
        'total' => $total,
        'cursor' => $cursor,
        'next_cursor' => $next_cursor,
        'complete' => count($rows) < $limit,
        'items' => $items,
    ));
}

/**
 * Store a licence only after a server-side worker fetched it directly from the
 * exact saved Nobat URL. Existing visible codes are never replaced.
 */
function ahura_import_verified_nobat_licenses(WP_REST_Request $req) {
    global $wpdb;
    $body = $req->get_json_params();
    $items = $body['items'] ?? array();
    if (!is_array($items) || !$items) return ahura_fail('validation_error', 'items are required.', 422);
    $items = array_slice($items, 0, 100);
    $updated = 0; $unchanged = 0; $source_mismatch = 0; $invalid = 0; $missing = 0;
    foreach ($items as $item) {
        $specialist_id = (int) ($item['specialist_id'] ?? 0);
        $source_url = rtrim(esc_url_raw((string) ($item['source_url'] ?? '')), '/');
        $license = preg_replace('/[^0-9]/', '', ahura_fa_digits((string) ($item['license'] ?? '')));
        if (!$specialist_id || !$source_url || !preg_match('#^https?://(?:www\.)?nobat\.ir(?:/|$)#i', $source_url) || $license === '' || strlen($license) > 10) {
            $invalid++; continue;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT s.id,s.user_id,s.meta FROM ' . AHURA_DRPLUS_SPEC . " s JOIN {$wpdb->posts} p ON p.ID=s.post_id AND p.post_type='specialist' AND p.post_status='publish' WHERE s.id=%d AND s.status='active' LIMIT 1",
            $specialist_id
        ), ARRAY_A);
        if (!$row || empty($row['user_id'])) { $missing++; continue; }
        if (ahura_nobat_profile_url_from_meta($row['meta']) !== $source_url) { $source_mismatch++; continue; }
        $existing = trim((string) get_user_meta((int) $row['user_id'], 'specialist_code', true));
        if ($existing !== '') { $unchanged++; continue; }
        update_user_meta((int) $row['user_id'], 'specialist_code', $license);
        $updated++;
    }
    return ahura_ok(array(
        'total' => count($items), 'updated' => $updated, 'unchanged' => $unchanged,
        'source_mismatch' => $source_mismatch, 'missing_profile' => $missing, 'invalid' => $invalid,
    ));
}

function ahura_import_progress_get() {
    return ahura_ok(get_option('ahura_import_progress', array(
        'updated_at' => '', 'sources' => array(), 'events' => array(),
    )));
}

function ahura_import_progress_update(WP_REST_Request $req) {
    $body = $req->get_json_params();
    $source = sanitize_key($body['source'] ?? '');
    if ($source === '') return ahura_fail('validation_error', 'source is required.', 422);
    $allowed = array('cursor', 'total', 'created', 'updated', 'duplicates', 'unchanged', 'missing_profile', 'failed', 'invalid', 'profiles_with_reviews', 'fetch_errors', 'source_found', 'source_no_license', 'source_mismatch', 'started_at');
    $state = array();
    foreach ($allowed as $key) {
        if (!array_key_exists($key, (array) ($body['state'] ?? array()))) continue;
        $state[$key] = $key === 'started_at' ? sanitize_text_field($body['state'][$key]) : (int) $body['state'][$key];
    }
    $progress = get_option('ahura_import_progress', array('sources' => array(), 'events' => array()));
    $progress['sources'][$source] = array_merge($state, array('updated_at' => current_time('mysql')));
    $event = array(
        'source' => $source,
        'at' => current_time('mysql'),
        'cursor' => (int) ($state['cursor'] ?? 0),
        'total' => (int) ($state['total'] ?? 0),
        'created' => (int) ($state['created'] ?? 0),
        'profile' => sanitize_text_field((string) ($body['profile'] ?? '')),
        'review_count' => (int) ($body['review_count'] ?? 0),
        'message' => sanitize_text_field((string) ($body['message'] ?? '')),
    );
    $progress['events'] = array_slice(array_merge(array($event), (array) ($progress['events'] ?? array())), 0, 80);
    $progress['updated_at'] = current_time('mysql');
    update_option('ahura_import_progress', $progress, false);
    return ahura_ok($progress);
}

/* ------------------------------------------------------------------ */
/* Term upsert implementation                                          */
/* ------------------------------------------------------------------ */

function ahura_term_upsert(WP_REST_Request $req = null, $body = null) {
    global $wpdb;
    $b = is_array($body) ? $body : ($req ? $req->get_json_params() : null);
    if (!is_array($b)) return ahura_fail('invalid_json', 'Body must be JSON.', 400);

    $allowed = array(
        'hospital_category', 'hospital-service', 'location', 'insurance',
        'product_cat', 'product_tag', 'post_tag', 'category', 'identity_type',
        'product-badge', 'product-service',
    );
    $tax = sanitize_key((string) ($b['taxonomy'] ?? ''));
    if (!in_array($tax, $allowed, true)) {
        return ahura_fail('validation_error', 'taxonomy must be one of: ' . implode(', ', $allowed), 422);
    }
    $name = trim((string) ($b['name'] ?? ''));
    if ($name === '') return ahura_fail('validation_error', 'name is required.', 422);

    $existing = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT t.term_id FROM {$wpdb->terms} t
         JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
         WHERE tt.taxonomy = %s AND t.name = %s LIMIT 1", $tax, $name));

    if ($existing) {
        $desc = isset($b['description']) ? sanitize_textarea_field($b['description']) : null;
        if ($desc !== null || isset($b['parent'])) {
            $tt_update = array();
            if ($desc !== null) $tt_update['description'] = $desc;
            if (isset($b['parent'])) $tt_update['parent'] = (int) $b['parent'];
            if ($tt_update) {
                $ttid = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy=%s AND term_id=%d",
                    $tax, $existing));
                if ($ttid) $wpdb->update($wpdb->term_taxonomy, $tt_update, array('term_taxonomy_id' => $ttid));
            }
        }
        return ahura_ok(array('term_id' => $existing, 'created' => false), 200);
    }

    $args = array();
    if (!empty($b['slug'])) $args['slug'] = sanitize_title($b['slug']);
    if (isset($b['description'])) $args['description'] = sanitize_textarea_field($b['description']);
    if (isset($b['parent'])) $args['parent'] = (int) $b['parent'];

    // wp_insert_term needs the taxonomy registered; use direct SQL when it is not.
    if (taxonomy_exists($tax)) {
        $res = wp_insert_term($name, $tax, $args);
        if (is_wp_error($res)) {
            if ($res->get_error_code() === 'term_exists') {
                return ahura_ok(array('term_id' => (int) $res->get_error_data()['term_id'], 'created' => false), 200);
            }
            return ahura_error_response($res);
        }
        return ahura_ok(array('term_id' => (int) $res['term_id'], 'created' => true), 201);
    }

    $wpdb->query('START TRANSACTION');
    $wpdb->insert($wpdb->terms, array(
        'name' => $name,
        'slug' => isset($args['slug']) ? $args['slug'] : sanitize_title($name),
    ));
    $term_id = (int) $wpdb->insert_id;
    $wpdb->insert($wpdb->term_taxonomy, array(
        'term_id'     => $term_id,
        'taxonomy'    => $tax,
        'description' => isset($args['description']) ? $args['description'] : '',
        'parent'      => isset($args['parent']) ? (int) $args['parent'] : 0,
        'count'       => 0,
    ));
    $wpdb->query('COMMIT');
    delete_option('{$tax}_children');
    return ahura_ok(array('term_id' => $term_id, 'created' => true), 201);
}

/* ------------------------------------------------------------------ */
/* Doctor implementation                                               */
/* ------------------------------------------------------------------ */

function ahura_doctor_hydrate($row) {
    global $wpdb;
    $uid = (int) $row['user_id'];
    $row['hospitals']   = $wpdb->get_col($wpdb->prepare('SELECT hospital_id FROM ' . AHURA_REL_HOSP . ' WHERE user_id=%d', $uid));
    $row['specialities']= $wpdb->get_col($wpdb->prepare('SELECT speciality_id FROM ' . AHURA_REL_SPEC . ' WHERE user_id=%d', $uid));
    $row['insurances']  = $wpdb->get_col($wpdb->prepare('SELECT insurance_id FROM ' . AHURA_REL_INS . ' WHERE user_id=%d', $uid));
    $row['times']       = $wpdb->get_results($wpdb->prepare(
        'SELECT id, office, day, `from`, `to`, use_default, status FROM ' . AHURA_TIMES . ' WHERE user_id=%d', $uid), ARRAY_A);
    return $row;
}

function ahura_doctor_upsert(WP_REST_Request $req = null, $body = null) {
    global $wpdb;
    $b = is_array($body) ? $body : ($req ? $req->get_json_params() : null);
    if (!is_array($b)) return ahura_fail('invalid_json', 'Body must be JSON.', 400);
    $name = trim((string) ($b['name'] ?? ''));
    if ($name === '') return ahura_fail('validation_error', 'name is required.', 422);

    $spec_id = 0;
    $ext = (string) ($b['external_id'] ?? '');

    if ($ext !== '') {
        $spec_id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . AHURA_DRPLUS_SPEC . ' WHERE slug=%s LIMIT 1', $ext));
    }
    if (!$spec_id && isset($b['id'])) $spec_id = (int) $b['id'];

    $slug = $ext !== '' ? $ext : sanitize_title($name);

    // resolve/ensure WP user for the doctor
    $user_id = 0;
    if (!empty($b['wp_user'])) {
        $wu = $b['wp_user'];
        $res = ahura_ensure_user(
            sanitize_user(ahura_fa_digits($wu['login'] ?? $slug), true),
            isset($wu['email']) ? sanitize_email(ahura_fa_digits($wu['email'])) : '',
            isset($wu['display']) ? sanitize_text_field($wu['display']) : $name,
            $wu['role'] ?? 'subscriber',
            $wu['password'] ?? null
        );
        if (is_wp_error($res)) return ahura_error_response($res);
        $user_id = $res[0];
    }

    $req_status = isset($b['status']) ? (string) $b['status'] : 'active';
    $status = in_array($req_status, array('active', 'pending', 'reject'), true) ? $req_status : 'active';

    // Enrichment imports may bring a license, source links, category data, or
    // avatar URL for a profile that is already on the site. Merge those fields
    // into the stored JSON instead of replacing the doctor's existing address,
    // phone, and other scraper data with empty values.
    $has_meta = array_key_exists('meta', $b);
    $meta_value = '';
    if ($has_meta) {
        if (is_string($b['meta'])) {
            $meta_value = $b['meta'];
        } elseif (is_array($b['meta']) && $spec_id && !empty($b['meta_merge'])) {
            $old_meta = $wpdb->get_var($wpdb->prepare(
                'SELECT meta FROM ' . AHURA_DRPLUS_SPEC . ' WHERE id=%d', $spec_id));
            $merged_meta = json_decode((string) $old_meta, true);
            if (!is_array($merged_meta)) $merged_meta = array();
            foreach ($b['meta'] as $key => $value) {
                if ($value !== null && $value !== '' && $value !== array()) {
                    $merged_meta[$key] = $value;
                }
            }
            $meta_value = json_encode($merged_meta, JSON_UNESCAPED_UNICODE);
        } else {
            $meta_value = json_encode($b['meta'], JSON_UNESCAPED_UNICODE);
        }
    }

    $now = current_time('mysql', true);
    $data = array(
        'slug'          => $slug,
        'name'          => $name,
        'subtitle'      => sanitize_text_field($b['subtitle'] ?? ''),
        'offline_visit' => !empty($b['offline_visit']) ? 1 : 0,
        'online_visit'  => !empty($b['online_visit']) ? 1 : 0,
        'is_verified'   => !empty($b['is_verified']) ? 1 : 0,
        'meta'          => $has_meta ? $meta_value : '',
        'offices'       => isset($b['offices']) ? (is_string($b['offices']) ? $b['offices'] : json_encode(array_values($b['offices']), JSON_UNESCAPED_UNICODE)) : '',
        'documents'     => isset($b['documents']) ? (is_string($b['documents']) ? $b['documents'] : json_encode($b['documents'], JSON_UNESCAPED_UNICODE)) : '',
        'status'        => $status,
        'reject'        => sanitize_text_field($b['reject'] ?? ''),
        'updated_at'    => $now,
        'creator'       => 1,
    );

    $created = false;
    if ($spec_id) {
        // partial update: never blank fields the caller didn't send
        $data = array_intersect_key($data, array_filter($b, function ($v) {
            return $v !== null && $v !== '';
        }, ARRAY_FILTER_USE_KEY));
        $data['updated_at'] = $now;
        if (!empty($data)) {
            $wpdb->update(AHURA_DRPLUS_SPEC, $data, array('id' => $spec_id));
        }
        $user_id = $user_id ?: (int) $wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM ' . AHURA_DRPLUS_SPEC . ' WHERE id=%d', $spec_id));
    } else {
        // ensure a user exists even if not supplied (drplus requires user_id)
        if (!$user_id) {
            $res = ahura_ensure_user($slug, '', $name, 'subscriber');
            if (is_wp_error($res)) return ahura_error_response($res);
            $user_id = $res[0];
        }
        $data['user_id']    = $user_id;
        $data['created_at'] = $now;
        $wpdb->insert(AHURA_DRPLUS_SPEC, $data);
        $spec_id = (int) $wpdb->insert_id;
        $created = true;
    }

    // doctor profile post (post_type 'specialist') so it shows on the site
    $post_id = (int) ($wpdb->get_var($wpdb->prepare(
        'SELECT post_id FROM ' . AHURA_DRPLUS_SPEC . ' WHERE id=%d', $spec_id)) ?: 0);
    if (!$post_id) {
        $pref = 'ahura_drpost_' . $slug;
        $found = get_posts(array('post_type' => 'specialist', 'meta_key' => '_drplus_specialist_id',
            'meta_value' => $spec_id, 'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'any'));
        if ($found) {
            $post_id = (int) $found[0];
            $wpdb->update(AHURA_DRPLUS_SPEC, array('post_id' => $post_id), array('id' => $spec_id));
        }
    }
    if (!$post_id) {
        $post_id = wp_insert_post(array(
            'post_type'    => 'specialist',
            'post_title'   => $name,
            'post_name'    => sanitize_title($name),
            'post_status'  => 'publish',
            'post_author'  => $user_id,
            'post_content' => isset($b['content']) ? wp_kses_post($b['content']) : '',
        ), true);
        if (is_wp_error($post_id)) return ahura_error_response($post_id);
        $wpdb->update(AHURA_DRPLUS_SPEC, array('post_id' => $post_id), array('id' => $spec_id));
    }
    if (isset($b['content']) && trim((string) $b['content']) !== '') {
        wp_update_post(array('ID' => $post_id, 'post_content' => wp_kses_post($b['content'])));
    }
    update_post_meta($post_id, '_drplus_specialist_id', $spec_id);
    update_post_meta($post_id, '_drplus_user_id', $user_id);
    if ($ext !== '') update_post_meta($post_id, 'ahura_external_id', $ext);

    // theme's "شماره نظام پزشکی" reads USER meta specialist_code
    $lic = '';
    if (isset($b['meta']) && is_array($b['meta']) && !empty($b['meta']['med_license'])) {
        $lic = (string) $b['meta']['med_license'];
    } elseif (isset($b['med_license'])) {
        $lic = (string) $b['med_license'];
    }
    if ($lic !== '' && $user_id) {
        update_user_meta($user_id, 'specialist_code', sanitize_text_field(ahura_fa_digits($lic)));
    }

    // doctor location taxonomy (attached to the specialist post by the theme)
    if (array_key_exists('location', $b)) {
        $loc = $b['location'];
        if (is_numeric($loc)) {
            wp_set_object_terms($post_id, array((int) $loc), 'location', false);
        } elseif (is_string($loc) && $loc !== '') {
            $t = get_term_by('name', $loc, 'location');
            if (!$t) {
                $res = wp_insert_term($loc, 'location');
                $t = is_wp_error($res) ? null : get_term($res['term_id']);
            }
            if ($t) wp_set_object_terms($post_id, array((int) $t->term_id), 'location', false);
        }
    }

    // generic post tags on the profile post
    if (array_key_exists('tags', $b)) {
        wp_set_post_tags($post_id, array_map('sanitize_text_field', (array) $b['tags']), false);
    }

    // arbitrary meta on the profile post
    if (!empty($b['post_meta']) && is_array($b['post_meta'])) {
        foreach ($b['post_meta'] as $k => $v) update_post_meta($post_id, sanitize_key($k), $v);
    }

    // relationships — accept ints or {external_id|id|title} objects
    $resolve_ref = function ($ref, $type) use ($wpdb) {
        if (is_numeric($ref)) return (int) $ref;
        if (is_array($ref)) {
            if (!empty($ref['id'])) return (int) $ref['id'];
            $ext2 = (string) ($ref['external_id'] ?? '');
            if ($ext2 !== '') {
                $pid = (int) get_posts(array('post_type' => $type, 'meta_key' => 'ahura_external_id',
                    'meta_value' => $ext2, 'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'any')[0] ?? 0);
                if ($pid) return $pid;
            }
            if (!empty($ref['title'])) {
                $r = ahura_upsert_post($type, $ref);
                if (!is_wp_error($r)) return (int) $r['id'];
            }
        }
        return 0;
    };

    foreach (array(
        'hospitals'   => array(AHURA_REL_HOSP, 'hospital_id',   'hospital'),
        'specialities'=> array(AHURA_REL_SPEC, 'speciality_id', 'speciality'),
    ) as $key => $cfg) {
        if (array_key_exists($key, $b)) {
            list($table, $col, $ptype) = $cfg;
            $wpdb->delete($table, array('user_id' => $user_id));
            foreach ((array) $b[$key] as $ref) {
                $rid = $resolve_ref($ref, $ptype);
                if ($rid) $wpdb->insert($table, array('user_id' => $user_id, $col => $rid));
            }
        }
    }
    if (array_key_exists('insurances', $b)) {
        $wpdb->delete(AHURA_REL_INS, array('user_id' => $user_id));
        foreach ((array) $b['insurances'] as $ref) {
            if (is_numeric($ref)) {
                $wpdb->insert(AHURA_REL_INS, array('user_id' => $user_id, 'insurance_id' => (int) $ref));
            } elseif (is_string($ref) && $ref !== '') {
                // resolve by name directly against the terms table — the insurance
                // taxonomy may not be registered at REST time, which makes
                // get_term_by()/wp_insert_term() unreliable.
                $term_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT t.term_id FROM {$wpdb->terms} t WHERE t.name = %s LIMIT 1", $ref));
                if (!$term_id) {
                    // term missing: create it through term machinery if available
                    $res = wp_insert_term($ref, 'insurance');
                    if (!is_wp_error($res)) {
                        $term_id = (int) $res['term_id'];
                    } elseif ($res->get_error_code() === 'term_exists') {
                        $term_id = (int) $res->get_error_data()['term_id'];
                    }
                }
                if ($term_id) $wpdb->insert(AHURA_REL_INS, array('user_id' => $user_id, 'insurance_id' => $term_id));
            }
        }
    }

    // times
    if (array_key_exists('times', $b)) {
        $wpdb->delete(AHURA_TIMES, array('user_id' => $user_id));
        foreach ((array) $b['times'] as $t) {
            $wpdb->insert(AHURA_TIMES, array(
                'user_id'    => $user_id,
                'office'     => sanitize_text_field($t['office'] ?? 'default'),
                'day'        => (int) ($t['day'] ?? 0),
                'from'       => sanitize_text_field(ahura_fa_digits($t['from'] ?? '00:00')),
                'to'         => sanitize_text_field(ahura_fa_digits($t['to'] ?? '00:00')),
                'use_default'=> !empty($t['use_default']) ? 1 : 0,
                'status'     => isset($t['status']) ? (int) $t['status'] : 1,
                'created_at' => $now,
                'updated_at' => $now,
                'creator'    => 1,
            ));
        }
    }

    return ahura_ok(array(
        'id'      => $spec_id,
        'post_id' => $post_id,
        'user_id' => $user_id,
        'created' => $created,
    ), $created ? 201 : 200);
}

function ahura_doctor_bulk(WP_REST_Request $req) {
    $b = $req->get_json_params();
    $items = $b['items'] ?? array();
    if (!is_array($items) || !$items) return ahura_fail('validation_error', 'items must be a non-empty array.', 422);

    $created = 0; $updated = 0; $failed = 0; $errors = array();
    foreach ($items as $i => $item) {
        $r = ahura_doctor_upsert(null, $item);
        if ($r instanceof WP_REST_Response && $r->get_status() < 300) {
            $d = $r->get_data();
            (!empty($d['data']['created'])) ? $created++ : $updated++;
        } else {
            $failed++;
            $errors[] = array('index' => $i, 'error' => $r->get_data()['error'] ?? 'unknown');
        }
    }
    return ahura_ok(array(
        'total' => count($items), 'created' => $created, 'updated' => $updated,
        'failed' => $failed, 'errors' => $errors,
    ));
}

/* ------------------------------------------------------------------ */
/* Comments implementation                                             */
/* ------------------------------------------------------------------ */

function ahura_comment_create(WP_REST_Request $req = null, $body = null) {
    $b = is_array($body) ? $body : ($req ? $req->get_json_params() : null);
    if (!is_array($b)) return ahura_fail('invalid_json', 'Body must be JSON.', 400);

    $post_id = (int) ($b['post_id'] ?? 0);
    $content = (string) ($b['content'] ?? '');
    if (!$post_id || trim($content) === '') {
        return ahura_fail('validation_error', 'post_id and content are required.', 422);
    }
    if (!get_post($post_id)) return ahura_fail('not_found', 'post_id does not exist.', 404);

    // Client rule (2026-09-09): scraped commenters always display as «کاربر میهمان».
    // Any author coming from a scraper source (or the default) is normalized here,
    // so imports stay consistent without per-row renaming.
    $c_author = trim((string) ($b['author_name'] ?? ''));
    $scraper_names = array('کاربر دکترتو', 'بیمار', 'کاربر مهمان', 'کاربر ميهمان', '');
    if (in_array($c_author, $scraper_names, true) || $c_author === '') {
        $c_author = 'کاربر میهمان';
    }

    // dedupe by external_id stored as comment meta (unique across the site)
    $ext = sanitize_text_field((string) ($b['external_id'] ?? ''));
    if ($ext !== '') {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key='ahura_external_id' AND meta_value=%s LIMIT 1",
            $ext));
        if ($found) {
            return ahura_ok(array('comment_id' => (int) $found, 'created' => false), 200);
        }
    }

    $approved = (string) ($b['approved'] ?? '1');
    $c_wait      = sanitize_text_field($b['wait'] ?? '');
    $c_reason    = sanitize_text_field($b['reason'] ?? '');
    $c_recommend = sanitize_text_field($b['recommend'] ?? '');
    $date = sanitize_text_field(ahura_fa_digits((string) ($b['date'] ?? '')));
    if ($date === '' || strtotime($date) === false) {
        $date_gmt = current_time('mysql', true);
        $date = get_date_from_gmt($date_gmt);
    } else {
        $date_gmt = get_gmt_from_date($date);
    }

    $comment_id = wp_insert_comment(array(
        'comment_post_ID'      => $post_id,
        'comment_author'       => sanitize_text_field($c_author ?: 'کاربر میهمان'),
        'comment_author_email' => sanitize_email(ahura_fa_digits($b['author_email'] ?? '')),
        'comment_author_url'   => esc_url_raw($b['author_url'] ?? ''),
        'comment_author_IP'    => sanitize_text_field($b['author_ip'] ?? ''),
        'comment_date'         => $date,
        'comment_date_gmt'     => $date_gmt,
        'comment_content'      => wp_kses_post($content),
        'comment_approved'     => in_array($approved, array('1', '0', 'spam', 'trash'), true) ? $approved : '1',
        'comment_agent'        => sanitize_text_field($b['agent'] ?? 'Ahura-Import/1.0'),
        'comment_type'         => sanitize_key($b['type'] ?? 'comment'),
        'comment_parent'       => (int) ($b['parent'] ?? 0),
        'user_id'              => (int) ($b['user_id'] ?? 0),
    ));
    if (!$comment_id) return ahura_fail('insert_failed', 'wp_insert_comment failed.', 500);

    if ($ext !== '') add_comment_meta($comment_id, 'ahura_external_id', $ext, true);

    // drplus theme only counts comments flagged as patient reviews
    add_comment_meta($comment_id, '_drplus_patient_review', '1', true);

    if (isset($b['rating'])) {
        $rating = (int) $b['rating'];
        if ($rating >= 1 && $rating <= 5) {
            add_comment_meta($comment_id, 'rating', $rating, true);
        }
    }
    foreach (array('wait' => $c_wait, 'reason' => $c_reason, 'recommend' => $c_recommend) as $mk => $mv) {
        if ($mv !== '') add_comment_meta($comment_id, $mk, $mv, true);
    }
    if (!empty($b['meta']) && is_array($b['meta'])) {
        foreach ($b['meta'] as $k => $v) add_comment_meta($comment_id, sanitize_key($k), $v, true);
    }

    return ahura_ok(array('comment_id' => (int) $comment_id, 'created' => true), 201);
}

function ahura_comment_bulk(WP_REST_Request $req) {
    $b = $req->get_json_params();
    $items = $b['items'] ?? array();
    if (!is_array($items) || !$items) return ahura_fail('validation_error', 'items must be a non-empty array.', 422);
    $done = 0; $failed = 0; $errors = array();
    foreach ($items as $i => $item) {
        $r = ahura_comment_create(null, $item);
        if ($r instanceof WP_REST_Response && $r->get_status() < 300) $done++;
        else { $failed++; $errors[] = array('index' => $i, 'error' => $r->get_data()['error'] ?? 'unknown'); }
    }
    return ahura_ok(array('total' => count($items), 'done' => $done, 'failed' => $failed, 'errors' => $errors));
}

/* ------------------------------------------------------------------ */
/* Times implementation                                                */
/* ------------------------------------------------------------------ */

function ahura_times_upsert(WP_REST_Request $req = null, $body = null) {
    global $wpdb;
    $b = is_array($body) ? $body : ($req ? $req->get_json_params() : null);
    if (!is_array($b)) return ahura_fail('invalid_json', 'Body must be JSON.', 400);
    $user_id = (int) ($b['user_id'] ?? 0);
    $day = (int) ($b['day'] ?? -1);
    if (!$user_id || $day < 0) return ahura_fail('validation_error', 'user_id and day are required.', 422);

    $spec = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . AHURA_DRPLUS_SPEC . ' WHERE user_id=%d LIMIT 1', $user_id));
    if (!$spec) return ahura_fail('not_found', 'No doctor (specialist) with this user_id.', 404);

    $now = current_time('mysql', true);
    $from = sanitize_text_field(ahura_fa_digits($b['from'] ?? '00:00'));
    $to   = sanitize_text_field(ahura_fa_digits($b['to'] ?? '00:00'));
    if (strlen($from) === 5) $from .= ':00';
    if (strlen($to) === 5) $to .= ':00';

    $id = $wpdb->insert(AHURA_TIMES, array(
        'user_id'     => $user_id,
        'office'      => sanitize_text_field($b['office'] ?? 'default'),
        'day'         => $day,
        'from'        => $from,
        'to'          => $to,
        'use_default' => !empty($b['use_default']) ? 1 : 0,
        'status'      => isset($b['status']) ? (int) $b['status'] : 1,
        'created_at'  => $now,
        'updated_at'  => $now,
        'creator'     => 1,
    ));
    if (!$id) return ahura_fail('insert_failed', 'Could not insert time row.', 500);
    return ahura_ok(array('time_id' => (int) $wpdb->insert_id), 201);
}

/* ------------------------------------------------------------------ */
/* Bookings implementation                                             */
/* ------------------------------------------------------------------ */

function ahura_booking_create(WP_REST_Request $req) {
    global $wpdb;
    $b = $req->get_json_params();
    if (!is_array($b)) return ahura_fail('invalid_json', 'Body must be JSON.', 400);
    $customer = (int) ($b['customer_id'] ?? 0);
    $specialist = (int) ($b['specialist_id'] ?? 0);
    $date = sanitize_text_field(ahura_fa_digits($b['date'] ?? ''));
    if (!$customer || !$specialist || $date === '') {
        return ahura_fail('validation_error', 'customer_id, specialist_id and date are required.', 422);
    }
    $now = current_time('mysql', true);
    $ok = $wpdb->insert(AHURA_BOOKINGS, array(
        'customer_id'  => $customer,
        'specialist_id'=> $specialist,
        'office_id'    => sanitize_text_field($b['office_id'] ?? 'default'),
        'date'         => $date,
        'start_time'   => sanitize_text_field(ahura_fa_digits($b['start_time'] ?? '08:00')),
        'end_time'     => sanitize_text_field(ahura_fa_digits($b['end_time'] ?? '08:30')),
        'total_price'  => (string) ($b['total_price'] ?? 0),
        'commission'   => (string) ($b['commission'] ?? 0),
        'specialist_income' => (string) ($b['specialist_income'] ?? 0),
        'order_id'     => (int) ($b['order_id'] ?? 0),
        'order_status' => sanitize_key($b['order_status'] ?? 'pending'),
        'created_at'   => $now,
        'updated_at'   => $now,
        'deleted'      => 0,
    ));
    if (!$ok) return ahura_fail('insert_failed', 'Could not insert booking.', 500);
    return ahura_ok(array('book_id' => (int) $wpdb->insert_id), 201);
}

/* ------------------------------------------------------------------ */
/* Media implementation — upload binary or base64 into the library     */
/* ------------------------------------------------------------------ */

function ahura_media_upload(WP_REST_Request $req) {
    $b = $req->get_json_params();
    $data64 = preg_replace('#^data:[^;]+;base64,#', '', (string) ($b['base64'] ?? ''));
    if (!$data64) return ahura_fail('validation_error', 'base64 is required.', 422);
    $bin = base64_decode($data64, true);
    if ($bin === false) return ahura_fail('validation_error', 'base64 is malformed.', 422);

    $filename = sanitize_file_name($b['filename'] ?? ('ahura-' . time() . '.jpg'));
    $tmp = wp_tempnam($filename);
    file_put_contents($tmp, $bin);
    $file = array('name' => $filename, 'tmp_name' => $tmp);
    $move = wp_handle_sideload($file, array('test_form' => false));
    if (!empty($move['error'])) return ahura_fail('upload_failed', $move['error'], 500);

    $type = wp_check_filetype($move['file']);
    $attach_id = wp_insert_attachment(array(
        'post_mime_type' => $type['type'],
        'post_title'     => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
        'post_status'    => 'inherit',
    ), $move['file']);
    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $move['file']));

    $out = array('attachment_id' => (int) $attach_id, 'url' => wp_get_attachment_url($attach_id));
    if (!empty($b['post_id'])) {
        $pid = (int) $b['post_id'];
        set_post_thumbnail($pid, $attach_id);
        // drplus theme avatar = USER meta 'avatar'; link it when the post is a specialist profile
        $uid = (int) get_post_meta($pid, '_drplus_user_id', true);
        if ($uid) update_user_meta($uid, 'avatar', (string) $attach_id);
    }
    return ahura_ok($out, 201);
}

// Part 2: full-site coverage endpoints (posts, users, orders, wallet, chats, sql, schema...)
include_once plugin_dir_path(__FILE__) . 'ahura-part2.php';

/* ------------------------------------------------------------------ */
/* Comment meta boxes (wait / reason / recommend) rendered separately  */
/* ------------------------------------------------------------------ */

add_filter('comment_text', 'ahura_comment_meta_boxes', 20, 3);

function ahura_comment_meta_boxes($text, $comment = null, $args = array()) {
    if (!$comment) return $text;
    if (get_post_type((int) $comment->comment_post_ID) !== 'specialist') return $text;

    $wait      = get_comment_meta($comment->comment_ID, 'wait', true);
    $reason    = get_comment_meta($comment->comment_ID, 'reason', true);
    $recommend = get_comment_meta($comment->comment_ID, 'recommend', true);
    if ($wait === '' && $reason === '' && $recommend === '') return $text;

    $box = '<div class="ahura-comment-boxes" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">';
    if ($reason !== '') {
        $box .= '<div style="background:#f2f7f7;border:1px solid #d8e6e6;border-radius:8px;padding:6px 12px;font-size:12px;">'
              . '<strong>' . esc_html__('علت مراجعه', 'ahura') . ':</strong> ' . esc_html($reason) . '</div>';
    }
    if ($wait !== '') {
        $box .= '<div style="background:#fdf6ec;border:1px solid #f0e2c8;border-radius:8px;padding:6px 12px;font-size:12px;">'
              . '<strong>' . esc_html__('زمان انتظار', 'ahura') . ':</strong> ' . esc_html($wait) . '</div>';
    }
    if ($recommend !== '') {
        $pos = (strpos($recommend, 'نمیکنم') === false);
        $color = $pos ? '#e8f6ef' : '#fdeeee';
        $border = $pos ? '#c8e6d5' : '#f2cdcD';
        $border = $pos ? '#c8e6d5' : '#f2cdcd';
        $box .= '<div style="background:' . $color . ';border:1px solid ' . $border . ';border-radius:8px;padding:6px 12px;font-size:12px;">'
              . esc_html($pos ? '✓ این پزشک را پیشنهاد می‌کنم' : '✕ این پزشک را پیشنهاد نمی‌کنم') . '</div>';
    }
    $box .= '</div>';
    return $text . $box;
}

/* ------------------------------------------------------------------ */
/* Hide per-comment star chip & visited badge; keep count query intact */
/* ------------------------------------------------------------------ */

add_action('wp_head', 'ahura_hide_review_chips', 99);

function ahura_hide_review_chips() {
    if (!is_singular('specialist')) return;
    echo '<style>.drplus-comment-patient-review,.drplus-comment-patient-score{display:none!important}.specialist_introduction{display:none!important}</style>';
}

/* ------------------------------------------------------------------ */
/* Rename theme's services box title to تخصص های دکتر                   */
/* ------------------------------------------------------------------ */

add_filter('gettext', 'ahura_rename_services_title', 10, 3);

function ahura_rename_services_title($translated, $original, $domain) {
    if ($domain !== 'drplus') return $translated;
    if ($original === 'خدمات دکتر %s' || $original === 'Specialized services of %s') {
        return 'تخصص های %s';
    }
    return $translated;
}

// Part 3: psychologists category, rating meta, top-rated search + filters
include_once plugin_dir_path(__FILE__) . 'ahura-part3.php';

// Part 4: dentists category + search sidebar tabs (روانشناس‌ها / دندانپزشک‌ها)
include_once plugin_dir_path(__FILE__) . 'ahura-part4.php';

/* Public, auto-refreshing import monitor: /?ahura_import_progress=1 */
function ahura_render_import_progress_page() {
    $api = wp_json_encode(esc_url(rest_url(AHURA_NS . '/import-progress')));
    $page = <<<'HTML'
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>گزارش زنده ورود داده‌ها | ویزیتال</title><style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#081a31;color:#ecf6ff;font-family:Tahoma,Arial,sans-serif}.wrap{max-width:980px;margin:auto;padding:32px 18px}.head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px}.head h1{font-size:24px;margin:0 0 8px}.pill{color:#062034;background:#79e5d4;padding:9px 13px;border-radius:99px;font-size:13px;white-space:nowrap}.muted{color:#9cb3ca;font-size:13px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:14px;margin:22px 0}.card,.events{background:#102949;border:1px solid #214367;border-radius:16px;padding:18px}.card h2,.events h2{font-size:17px;margin:0 0 14px}.bar{height:8px;background:#203c60;border-radius:99px;overflow:hidden;margin:12px 0 15px}.bar i{display:block;height:100%;background:linear-gradient(90deg,#70e6d3,#58a9fa);border-radius:inherit}.line{display:flex;justify-content:space-between;gap:12px;padding:7px 0;color:#bfd3e5;border-bottom:1px solid #1c3b5d}.line:last-child{border:0}.line strong{color:#fff;text-align:left}.event{padding:12px 0;border-bottom:1px solid #214367;color:#c9d9e9}.event:last-child{border:0}.note{margin:0 0 18px;padding:12px 14px;border-radius:12px;background:#0f3441;color:#bae9df;font-size:13px;line-height:1.8}@media(max-width:540px){.wrap{padding:22px 14px}.head{flex-direction:column}.head h1{font-size:21px}.pill{white-space:normal}}
</style></head><body><main class="wrap"><div class="head"><div><h1>گزارش زنده بررسی پروفایل‌ها و داده‌های سایت</h1><div class="muted">این صفحه هر ۵ ثانیه خودکار به‌روزرسانی می‌شود.</div></div><div class="pill" id="stamp">در حال اتصال…</div></div><p class="note" id="note" hidden></p><section class="grid" id="sources"></section><section class="events"><h2>آخرین رویدادها</h2><div id="events" class="muted">در حال دریافت گزارش…</div></section></main><script>
const api=__AHURA_API__;const n=x=>new Intl.NumberFormat('fa-IR').format(Number(x||0));
function title(k){return k==='nobat_reviews'?'نظرهای نوبت':k==='doctoreto_reviews'?'نظرهای دکترتو':k==='nobat_licenses'?'شماره‌های نظام پزشکی نوبت':k==='nobat_addresses'?'آدرس‌های نوبت':k==='doctoreto_profiles'?'پروفایل‌های دکترتو':k==='nobat_profiles'?'پروفایل‌های نوبت':k}
function line(label,value,ready=false){return `<div class="line"><span>${label}</span><strong>${ready?value:n(value)}</strong></div>`}
function card(k,v){const pc=v.total?Math.min(100,Math.round(v.cursor*100/v.total)):0;const licence=k==='nobat_licenses';const address=k==='nobat_addresses';let rows=line('پیشرفت',`${n(v.cursor)} از ${n(v.total)}`,true);if(licence){rows+=line('منبع شماره را تأیید کرد',v.source_found)+line('شماره‌های ثبت‌شده',v.updated)+line('قبلاً ثبت‌شده',v.unchanged)+line('منبع شماره نداشت',v.source_no_license)+line('خطای دریافت',v.fetch_errors)+line('عدم تطبیق پروفایل',Number(v.source_mismatch||0)+Number(v.missing_profile||0));}else if(address){rows+=line('آدرس‌های ثبت‌شده',v.updated)+line('قبلاً دارای آدرس',v.unchanged)+line('منبع آدرس نداشت',v.source_no_address)+line('خطای دریافت',v.fetch_errors)+line('عدم تطبیق پروفایل',Number(v.source_mismatch||0)+Number(v.missing_profile||0));}else if(k==='doctoreto_profiles'||k==='nobat_profiles'){rows+=line('پروفایل‌های بررسی‌شده',v.source_found)+line('در انتظار بررسی',Math.max(0,Number(v.total||0)-Number(v.cursor||0)))+line('خطا',v.fetch_errors||v.failed)+line('موارد نیازمند بررسی',v.invalid);}else{rows+=line('نظرهای افزوده',v.created)+line('تکراریِ ردشده',v.duplicates||v.unchanged)+line('خطا',v.fetch_errors||v.failed||v.missing_profile);}return `<article class="card"><h2>${title(k)}</h2><div class="bar"><i style="width:${pc}%"></i></div>${rows}</article>`}
function render(payload){const d=payload.data||payload,s=d.sources||{},events=d.events||[];document.querySelector('#stamp').textContent=d.updated_at?'آخرین بروزرسانی: '+d.updated_at:'در انتظار اولین گزارش';document.querySelector('#sources').innerHTML=Object.entries(s).map(([k,v])=>card(k,v)).join('')||'<div class="muted">هنوز گزارشی ثبت نشده است.</div>';const latest=events.find(x=>x.source==='nobat_licenses')||events.find(x=>x.source==='nobat_addresses');const note=document.querySelector('#note');if(latest&&latest.message){note.hidden=false;note.textContent=latest.message}else note.hidden=true;document.querySelector('#events').innerHTML=events.map(v=>`<div class="event"><strong>${title(v.source)}</strong> — ${n(v.cursor)} از ${n(v.total)}${v.profile?' · '+v.profile:''}<div class="muted">${v.at||''}</div></div>`).join('')||'هنوز رویدادی ثبت نشده است.'}
async function load(){try{const freshApi=new URL(api,window.location.href);freshApi.searchParams.set('live',Date.now());render(await (await fetch(freshApi,{cache:'no-store'})).json())}catch(e){document.querySelector('#stamp').textContent='اتصال دوباره تلاش می‌شود…'}}load();setInterval(load,5000);
</script></body></html>
HTML;
    echo str_replace('__AHURA_API__', $api, $page);
}

add_filter('query_vars', function ($vars) {
    $vars[] = 'ahura_import_progress';
    return $vars;
});

add_action('template_redirect', function () {
    if ((string) get_query_var('ahura_import_progress') !== '1') return;
    status_header(200);
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    ahura_render_import_progress_page();
    exit;
    $api = esc_url(rest_url(AHURA_NS . '/import-progress'));
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>گزارش زنده ورود داده‌ها | ویزیتال</title><style>body{margin:0;background:#081a31;color:#ecf6ff;font-family:Tahoma,Arial,sans-serif}.wrap{max-width:900px;margin:auto;padding:32px 18px}.head{display:flex;justify-content:space-between;align-items:center;gap:14px}.pill{color:#062034;background:#79e5d4;padding:8px 12px;border-radius:99px;font-size:13px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px;margin:22px 0}.card{background:#102949;border:1px solid #214367;border-radius:16px;padding:18px}.card h2{font-size:17px;margin:0 0 14px}.line{display:flex;justify-content:space-between;gap:12px;margin:8px 0;color:#bad0e5}.line strong{color:#fff}.bar{height:8px;background:#203c60;border-radius:99px;overflow:hidden;margin:14px 0}.bar i{display:block;height:100%;background:linear-gradient(90deg,#70e6d3,#58a9fa);border-radius:inherit}.events{background:#102949;border:1px solid #214367;border-radius:16px;padding:18px}.event{padding:12px 0;border-bottom:1px solid #214367;color:#bed1e5}.event:last-child{border:0}.muted{color:#8ea9c3;font-size:13px}@media(max-width:540px){.wrap{padding:22px 14px}.head{align-items:flex-start;flex-direction:column}}</style></head><body><main class="wrap"><div class="head"><div><h1>گزارش زنده ورود نظرها و شماره‌های نظام پزشکی</h1><div class="muted">این صفحه هر ۵ ثانیه خودکار به‌روزرسانی می‌شود.</div></div><div class="pill" id="stamp">در حال اتصال…</div></div><section class="grid" id="sources"></section><section class="events"><h2>آخرین رویدادها</h2><div id="events" class="muted">در حال دریافت گزارش…</div></section></main><script>const api=' . wp_json_encode($api) . ';const n=x=>new Intl.NumberFormat("fa-IR").format(Number(x||0));function sourceTitle(k){return k==="nobat_reviews"?"نظرهای نوبت":k==="doctoreto_reviews"?"نظرهای دکترتو":k==="nobat_licenses"?"شماره‌های نظام پزشکی نوبت":k}function render(p){const d=p.data||p,s=d.sources||{},e=d.events||[];document.querySelector("#stamp").textContent=d.updated_at?"آخرین بروزرسانی: "+d.updated_at:"در انتظار اولین گزارش";document.querySelector("#sources").innerHTML=Object.entries(s).map(([k,v])=>{const pc=v.total?Math.min(100,Math.round(v.cursor*100/v.total)):0;const lic=k==="nobat_licenses";return `<article class="card"><h2>${sourceTitle(k)}</h2><div class="line"><span>پیشرفت</span><strong>${n(v.cursor)} از ${n(v.total)}</strong></div><div class="bar"><i style="width:${pc}%"></i></div><div class="line"><span>${lic?"شماره‌های افزوده":"نظرهای افزوده"}</span><strong>${n(v.created||v.updated)}</strong></div><div class="line"><span>${lic?"قبلاً موجود":"تکراریِ ردشده"}</span><strong>${n(v.duplicates||v.unchanged)}</strong></div><div class="line"><span>خطا</span><strong>${n(v.fetch_errors||v.failed||v.missing_profile)}</strong></div></article>`}).join("")||"<div class=\"muted\">هنوز گزارشی ثبت نشده است.</div>";document.querySelector("#events").innerHTML=e.map(v=>`<div class="event"><strong>${sourceTitle(v.source)}</strong> — ${n(v.cursor)} از ${n(v.total)}${v.profile?" · "+v.profile:""}<div class="muted">${v.at||""}</div></div>`).join("")||"هنوز رویدادی ثبت نشده است."}async function load(){try{render(await (await fetch(api,{cache:"no-store"})).json())}catch(e){document.querySelector("#stamp").textContent="اتصال دوباره تلاش می‌شود…"}}load();setInterval(load,5000);</script></body></html>';
    exit;
});
