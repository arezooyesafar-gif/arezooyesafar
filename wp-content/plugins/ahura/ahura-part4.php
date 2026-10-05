<?php
/**
 * Ahura Import API - Part 4
 *   * Dentist, Veterinary, and Medical Doctor detection helpers
 *   * City scoping helper
 * Included from ahura.php.
 */

if (!defined('ABSPATH')) exit;

/* ---------------- dentist detection ---------------- */

if (!function_exists('ahura_dent_post_ids')) {
    function ahura_dent_post_ids() {
        $cached = get_transient('ahura_dent_posts_v5');
        if (is_array($cached)) return $cached;
        global $wpdb;
        $table = $wpdb->prefix . 'drplus_specialists';
        $out = array_values(array_filter(array_map('intval', (array) $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$table} WHERE subtitle REGEXP 'دندان|پریودنت|ارتودنس|اندودانت|پدودانت|ایمپلنت|دندانساز' AND post_id > 0"
        ))));
        set_transient('ahura_dent_posts_v5', $out, 12 * HOUR_IN_SECONDS);
        return $out;
    }
}

if (!function_exists('ahura_post_is_dentist')) {
    function ahura_post_is_dentist($post_id) {
        return in_array((int) $post_id, ahura_dent_post_ids(), true);
    }
}

/* ---------------- veterinary detection ---------------- */

if (!function_exists('ahura_vet_post_ids')) {
    function ahura_vet_post_ids() {
        $cached = get_transient('ahura_vet_posts_v5');
        if (is_array($cached)) return $cached;
        global $wpdb;
        $table = $wpdb->prefix . 'drplus_specialists';
        $out = array_values(array_filter(array_map('intval', (array) $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$table} WHERE subtitle REGEXP 'دامپزشک|دامپزشکی|حیوانات' AND post_id > 0"
        ))));
        set_transient('ahura_vet_posts_v5', $out, 12 * HOUR_IN_SECONDS);
        return $out;
    }
}

/* ---------------- medical doctor detection ---------------- */

if (!function_exists('ahura_medical_doctor_post_ids')) {
    function ahura_medical_doctor_post_ids() {
        $cached = get_transient('ahura_doctor_posts_v5');
        if (is_array($cached)) return $cached;
        global $wpdb;
        $table = $wpdb->prefix . 'drplus_specialists';
        $patterns = 'روانشناس|مشاوره|رواندرمان|دندان|پریودنت|ارتودنس|اندودانت|پدودانت|ایمپلنت|دندانساز|ماما|مامایی|تغذیه|رژیم|فیزیوتراپ|گفتاردرمان|کار درمانی|کاردرمان|بینایی|اپتومتری|شنوایی|دامپزشک|دامپزشکی|حیوانات|ارتوپدی فنی|کایروپراکتیک|بیولوژی تولید مثل';
        $out = array_values(array_filter(array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$table} WHERE subtitle NOT REGEXP %s AND post_id > 0",
            $patterns
        )))));
        set_transient('ahura_doctor_posts_v5', $out, 12 * HOUR_IN_SECONDS);
        return $out;
    }
}

/* ---------------- city scoping helper ---------------- */

if (!function_exists('ahura_city_scope_ids')) {
    function ahura_city_scope_ids($ids) {
        $slug = '';
        if (!empty($_GET['city'])) $slug = sanitize_title_with_dashes(wp_unslash($_GET['city']));
        if (!$slug) return $ids;
        $term = get_term_by('slug', $slug, 'location');
        if (!$term || is_wp_error($term)) return $ids;
        $q = new WP_Query(array(
            'post_type'      => 'specialist',
            'post_status'    => 'publish',
            'post__in'       => !empty($ids) ? array_map('intval', $ids) : array(0),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => array(array('taxonomy' => 'location', 'field' => 'term_id', 'terms' => (int) $term->term_id)),
        ));
        return array_map('intval', $q->posts);
    }
}

if (!defined('AHURA_MERGE_GROUP'))   define('AHURA_MERGE_GROUP', 'Dj3i_ahura_merge_group');
if (!defined('AHURA_MERGE_COMMENT')) define('AHURA_MERGE_COMMENT', 'Dj3i_ahura_merge_comment');

add_action('rest_api_init', function () {

    /**
     * Merge a legacy doc-* profile into the doctoreto profile the importer already
     * name-matched it to. Identity is never guessed here: the caller supplies the
     * pairs and this refuses unless the two display names are the same person once
     * the doctor prefix and spacing are folded.
     *
     * POST /doctors/merge-name-matches   {pairs:[[loser_spec,winner_spec],...], dry_run:false}
     * POST /doctors/merge-name-matches/rollback {limit:100}
     */

    if (!function_exists('ahura_merge_tables')) {
        function ahura_merge_tables() {
            global $wpdb;
            $charset = $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE " . AHURA_MERGE_GROUP . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(64) NOT NULL,
                winner_post BIGINT UNSIGNED NOT NULL,
                loser_post BIGINT UNSIGNED NOT NULL,
                winner_spec BIGINT UNSIGNED NOT NULL,
                loser_spec BIGINT UNSIGNED NOT NULL,
                loser_post_status VARCHAR(32) NOT NULL,
                loser_spec_status VARCHAR(50) NOT NULL,
                winner_offices_before LONGTEXT NULL,
                winner_meta_before LONGTEXT NULL,
                comments_moved BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY loser_post (loser_post),
                KEY code (code)
            ) {$charset};");
            dbDelta("CREATE TABLE " . AHURA_MERGE_COMMENT . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                comment_id BIGINT UNSIGNED NOT NULL,
                old_post BIGINT UNSIGNED NOT NULL,
                new_post BIGINT UNSIGNED NOT NULL,
                action VARCHAR(8) NOT NULL DEFAULT 'move',
                row_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY comment_id (comment_id),
                KEY old_post (old_post)
            ) {$charset};");
        }
    }

    if (!function_exists('ahura_review_sig_sql')) {
        /**
         * Signature for "this exact review already exists". Imported reviews share an empty email and
         * a source timestamp down to the hour, so text plus date alone fuses different reviewers.
         */
        function ahura_review_sig_sql() {
            return 'CONCAT_WS(0x7c, comment_author, comment_author_email, comment_date_gmt, comment_type, user_id, MD5(comment_content))';
        }
    }

    if (!function_exists('ahura_review_sigs')) {
        /** Identical review signature (author, timestamp, text) for one post. */
        function ahura_review_sigs($post_id) {
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT comment_ID AS id, ' . ahura_review_sig_sql() . ' AS sig FROM ' . $wpdb->comments . ' WHERE comment_post_ID = %d',
                (int) $post_id), ARRAY_A);
            $out = array();
            foreach ($rows as $r) { $out[(string) $r['sig']][] = (int) $r['id']; }
            return $out;
        }
    }

    if (!function_exists('ahura_comment_snapshot')) {
        /** Whole row plus its meta so a removed review can be put back. */
        function ahura_comment_snapshot($comment_id) {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->comments . ' WHERE comment_ID = %d', (int) $comment_id), ARRAY_A);
            if (!$row) return null;
            $meta = $wpdb->get_results($wpdb->prepare('SELECT meta_key, meta_value FROM ' . $wpdb->commentmeta . ' WHERE comment_id = %d', (int) $comment_id), ARRAY_A);
            return array('comment' => $row, 'meta' => (array) $meta);
        }
    }

    if (!function_exists('ahura_remove_comment')) {
        /** Drop a review, keeping enough in the audit table to put it back. */
        function ahura_remove_comment($comment_id, $post_id, $action = 'del') {
            global $wpdb;
            $snap = ahura_comment_snapshot($comment_id);
            if (!$snap) return false;
            wp_delete_comment((int) $comment_id, true);
            $wpdb->insert(AHURA_MERGE_COMMENT, array(
                'comment_id' => (int) $comment_id, 'old_post' => (int) $post_id, 'new_post' => (int) $post_id,
                'action' => $action, 'row_json' => wp_json_encode($snap, JSON_UNESCAPED_UNICODE),
                'created_at' => current_time('mysql'),
            ));
            return true;
        }
    }

    if (!function_exists('ahura_restore_comment')) {
        /** Re-insert a snapshotted review. Content and meta return; the id may differ. */
        function ahura_restore_comment($row_json) {
            global $wpdb;
            $snap = json_decode((string) $row_json, true);
            if (empty($snap['comment'])) return false;
            $cols = $wpdb->get_col('SHOW COLUMNS FROM ' . $wpdb->comments);
            $data = array();
            foreach ($snap['comment'] as $k => $v) {
                if ('comment_ID' === $k || !in_array($k, $cols, true)) continue;
                $data[$k] = $v;
            }
            if (!$data || false === $wpdb->insert($wpdb->comments, $data)) return false;
            $new_id = (int) $wpdb->insert_id;
            foreach ((array) ($snap['meta'] ?? array()) as $m) {
                $wpdb->insert($wpdb->commentmeta, array(
                    'comment_id' => $new_id, 'meta_key' => $m['meta_key'], 'meta_value' => $m['meta_value']));
            }
            wp_update_comment_count_now((int) ($snap['comment']['comment_post_ID'] ?? 0));
            return true;
        }
    }

    if (!function_exists('ahura_fold_person')) {
        function ahura_fold_person($name) {
            $name = trim((string) $name);
            $name = str_replace(array('ي', 'ك', 'ة'), array('ی', 'ک', 'ه'), $name);
            $name = preg_replace('/^(دکتر|دكتور|دكتر|doctor)\s+/u', '', $name);
            $name = preg_replace('/[\x{200c}-\x{200f}\s]+/u', '', $name);
            return $name;
        }
    }

    if (!function_exists('ahura_loose_name')) {
        /** Folded name kept readable: prefix removed, letter variants unified, spaces single. */
        function ahura_loose_name($name) {
            $n = trim((string) $name);
            $n = str_replace(array('ي', 'ك', 'ة', 'ى'), array('ی', 'ک', 'ه', 'ی'), $n);
            $n = preg_replace('/^(دکتر|دكتور|دكتر|doctor|prof\.?)\s+/u', '', $n);
            $n = preg_replace('/[\x{200b}-\x{200f}]+/u', '', $n);
            return preg_replace('/\s+/u', ' ', $n);
        }
    }

    if (!function_exists('ahura_names_related')) {
        /** True when two names are plausibly one person: containment, or given name plus a shared token. */
        function ahura_names_related($a, $b) {
            $x = ahura_fold_person($a); $y = ahura_fold_person($b);
            if ($x === $y) return true;
            if ('' === $x || '' === $y) return false;
            if (0 === strpos($x, $y) || 0 === strpos($y, $x)) return true;
            $ta = preg_split('/\s+/u', ahura_loose_name($a));
            $tb = preg_split('/\s+/u', ahura_loose_name($b));
            if (!$ta || !$tb || $ta[0] !== $tb[0]) return false;
            $ra = array_slice($ta, 1); $rb = array_slice($tb, 1);
            return count(array_intersect($ra, $rb)) >= 1;
        }
    }

    if (!function_exists('ahura_contact_of')) {
        /** Mobile numbers and full addresses carried by a specialist row, normalised. */
        function ahura_contact_of($row) {
            $meta = ahura_json($row['meta'] ?? '');
            $offices = ahura_json($row['offices'] ?? '');
            $phones = array(); $addrs = array();
            $push_phone = function ($v) use (&$phones) {
                $d = preg_replace('/\D/', '', (string) $v);
                if (0 === strpos($d, '0098')) $d = '0' . substr($d, 4);
                if (preg_match('/^09\d{9}$/', $d)) $phones[$d] = true;
            };
            foreach ((array) ($meta['phones'] ?? array()) as $p) $push_phone($p);
            $push_phone($meta['phone'] ?? '');
            foreach ((array) $offices as $o) if (is_array($o)) { $push_phone($o['phone'] ?? ''); }
            $push_addr = function ($v) use (&$addrs) {
                $s = str_replace(array('ي', 'ك', 'ة', 'ى'), array('ی', 'ک', 'ه', 'ی'), (string) $v);
                $s = preg_replace('/[\s\x{200b}-\x{200f}،,.;:()\-]+/u', '', $s);
                $s = mb_strtolower($s, 'UTF-8');
                if (mb_strlen($s, 'UTF-8') >= 18) $addrs[$s] = true;
            };
            $push_addr($meta['address'] ?? '');
            foreach ((array) $offices as $o) if (is_array($o)) $push_addr($o['address'] ?? '');
            return array(array_keys($phones), array_keys($addrs));
        }
    }

    if (!function_exists('ahura_city_of')) {
        function ahura_city_of($row) {
            $meta = ahura_json($row['meta'] ?? '');
            if (!empty($meta['city'])) return strtolower((string) $meta['city']);
            $map = array('تهران' => 'tehran', 'مشهد' => 'mashhad', 'اصفهان' => 'isfahan', 'شیراز' => 'shiraz',
                'تبریز' => 'tabriz', 'کرج' => 'karaj', 'قم' => 'qom', 'ارومیه' => 'urmia', 'زنجان' => 'zanjan',
                'رشت' => 'rasht', 'اهواز' => 'ahvaz', 'اردبیل' => 'ardabil');
            $addr = preg_replace('/\s+/u', '', (string) ($meta['address'] ?? ''));
            foreach ($map as $fa => $slug) if (mb_strpos($addr, $fa, 0, 'UTF-8') === 0) return $slug;
            return '';
        }
    }

    if (!function_exists('ahura_json')) {
        function ahura_json($value) {
            if (is_array($value)) return $value;
            if (null === $value || '' === $value) return array();
            $decoded = json_decode((string) $value, true);
            return is_array($decoded) ? $decoded : array();
        }
    }

    if (!function_exists('ahura_office_key')) {
        function ahura_office_key($office) {
            $address = preg_replace('/\s+/u', ' ', str_replace(array('،', ',', '.', ';', ':'), ' ', (string) ($office['address'] ?? '')));
            $phone = preg_replace('/\D/', '', (string) ($office['phone'] ?? ''));
            return mb_strtolower(trim($address), 'UTF-8') . '|' . $phone;
        }
    }

    if (!function_exists('ahura_merge_one')) {
        /** Merge one loser spec row into one winner spec row. Returns stats or a skip reason. */
        function ahura_merge_one($loser_spec_id, $winner_spec_id, $dry, $mode = 'exact') {
            global $wpdb;
            $loser_spec_id = (int) $loser_spec_id; $winner_spec_id = (int) $winner_spec_id;
            $loser  = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . AHURA_DRPLUS_SPEC . ' WHERE id = %d', $loser_spec_id), ARRAY_A);
            $winner = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . AHURA_DRPLUS_SPEC . ' WHERE id = %d', $winner_spec_id), ARRAY_A);
            if (!$loser || !$winner) return array('skip' => 'missing');
            if ((int) $loser['post_id'] === (int) $winner['post_id']) return array('skip' => 'same_post');
            if (ahura_fold_person($loser['name']) !== ahura_fold_person($winner['name'])) {
                // Different spellings need either contact mode, where the server itself has to see a
                // shared mobile or an identical full address, or force mode, where a human already
                // compared the two profiles in the review queue.
                if ('contact' !== $mode && 'force' !== $mode) return array('skip' => 'name');
                if ('contact' === $mode) {
                    if (!ahura_names_related($loser['name'], $winner['name'])) return array('skip' => 'unrelated_name');
                    list($lphones, $laddrs) = ahura_contact_of($loser);
                    list($wphones, $waddrs) = ahura_contact_of($winner);
                    if (!array_intersect($lphones, $wphones) && !array_intersect($laddrs, $waddrs)) return array('skip' => 'no_contact_proof');
                    $lc = ahura_city_of($loser); $wc = ahura_city_of($winner);
                    if ($lc && $wc && $lc !== $wc) return array('skip' => 'city_conflict');
                }
            }
            if ('publish' !== get_post_status((int) $loser['post_id'])) return array('skip' => 'loser_not_live');
            if ('publish' !== get_post_status((int) $winner['post_id'])) return array('skip' => 'winner_not_live');
            if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . AHURA_MERGE_GROUP . ' WHERE loser_post = %d', (int) $loser['post_id']))) return array('skip' => 'already');

            $lmeta = ahura_json($loser['meta']);
            $wmeta = ahura_json($winner['meta']);
            $woffices = ahura_json($winner['offices']);
            $by_key = array();
            foreach ($woffices as $i => $o) { if (is_array($o)) $by_key[ahura_office_key($o)] = $i; }
            $offices_added = 0;
            foreach (ahura_json($loser['offices']) as $o) {
                if (!is_array($o)) continue;
                $key = ahura_office_key($o);
                if (isset($by_key[$key])) {
                    $cur = $woffices[$by_key[$key]];
                    foreach (array('visit_price', 'map_url', 'name', 'type') as $f) {
                        if (empty($cur[$f]) && !empty($o[$f])) $cur[$f] = $o[$f];
                    }
                    if (empty($cur['enable_booking']) && !empty($o['enable_booking'])) $cur['enable_booking'] = $o['enable_booking'];
                    $woffices[$by_key[$key]] = $cur;
                } else {
                    $by_key[$key] = count($woffices);
                    $woffices[] = $o;
                    $offices_added++;
                }
            }
            foreach (array('phones', 'cities', 'all_specialties') as $k) {
                $m = array_values(array_unique(array_merge((array) ($wmeta[$k] ?? array()), (array) ($lmeta[$k] ?? array()))));
                if ($m) $wmeta[$k] = $m;
            }
            if (empty($wmeta['address']) && !empty($lmeta['address'])) $wmeta['address'] = $lmeta['address'];
            if (empty($wmeta['specialty']) && !empty($lmeta['specialty'])) $wmeta['specialty'] = $lmeta['specialty'];
            if (empty($wmeta['nobat_profile_url']) && !empty($lmeta['nobat_profile_url'])) $wmeta['nobat_profile_url'] = $lmeta['nobat_profile_url'];
            $wmeta['sources'] = array_values(array_unique(array_merge((array) ($wmeta['sources'] ?? array()), (array) ($lmeta['sources'] ?? array()), array('merged-import'))));
            $code = (string) $lmeta['name_matched_code'] ?? '';
            if (!$code) { $code = basename((string) ($lmeta['name_matched_profile'] ?? '')); }
            $wmeta['merged_from'] = array_values(array_unique(array_merge((array) ($wmeta['merged_from'] ?? array()), array($code))));

            // Some pairs are the same doctor imported twice with the same reviews. Moving those
            // onto the survivor would show every review twice, so identical ones are dropped.
            $loser_rows = $wpdb->get_results($wpdb->prepare(
                'SELECT comment_ID AS id, ' . ahura_review_sig_sql() . ' AS sig FROM ' . $wpdb->comments . ' WHERE comment_post_ID = %d',
                (int) $loser['post_id']), ARRAY_A);
            $winner_sigs = ahura_review_sigs((int) $winner['post_id']);
            $move_ids = array(); $twin_ids = array();
            foreach ($loser_rows as $r) {
                if (isset($winner_sigs[(string) $r['sig']])) $twin_ids[] = (int) $r['id'];
                else $move_ids[] = (int) $r['id'];
            }
            $out = array('merged' => 1, 'comments' => count($move_ids), 'dupes_dropped' => count($twin_ids),
                         'offices_added' => $offices_added,
                         'loser_post' => (int) $loser['post_id'], 'winner_post' => (int) $winner['post_id']);
            if ($dry) return $out;

            $now = current_time('mysql');
            $wpdb->update(AHURA_DRPLUS_SPEC, array(
                'meta' => wp_json_encode($wmeta, JSON_UNESCAPED_UNICODE),
                'offices' => wp_json_encode(array_values($woffices), JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ), array('id' => (int) $winner['id']));

            $wpdb->query($wpdb->prepare(
                'INSERT INTO ' . AHURA_MERGE_GROUP . '
                 (code, winner_post, loser_post, winner_spec, loser_spec, loser_post_status, loser_spec_status,
                  winner_offices_before, winner_meta_before, comments_moved, created_at)
                 VALUES (%s,%d,%d,%d,%d,%s,%s,%s,%s,%d,%s)',
                $code, (int) $winner['post_id'], (int) $loser['post_id'], (int) $winner['id'], (int) $loser['id'],
                (string) get_post_status((int) $loser['post_id']), (string) $loser['status'],
                $winner['offices'], $winner['meta'], count($move_ids), $now));

            foreach ($twin_ids as $cid) { ahura_remove_comment($cid, (int) $loser['post_id'], 'del'); }
            foreach ($move_ids as $cid) {
                $wpdb->insert(AHURA_MERGE_COMMENT, array(
                    'comment_id' => (int) $cid, 'old_post' => (int) $loser['post_id'],
                    'new_post' => (int) $winner['post_id'], 'action' => 'move', 'created_at' => $now));
            }
            if ($move_ids) {
                $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->comments . ' SET comment_post_ID = %d WHERE comment_ID IN ('
                    . implode(',', array_map('intval', $move_ids)) . ')', (int) $winner['post_id']));
            }
            foreach (array(AHURA_REL_SPEC => 'speciality_id', AHURA_REL_HOSP => 'hospital_id', AHURA_REL_INS => 'insurance_id') as $rel => $col) {
                $have = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT {$col} FROM {$rel} WHERE user_id = %d", (int) $winner['user_id'])));
                $theirs = $wpdb->get_results($wpdb->prepare("SELECT {$col} FROM {$rel} WHERE user_id = %d", (int) $loser['user_id']), ARRAY_A);
                foreach ($theirs as $link) {
                    $value = (int) $link[$col];
                    if ($value && !in_array($value, $have, true)) {
                        $wpdb->insert($rel, array('user_id' => (int) $winner['user_id'], $col => $value));
                        $have[] = $value;
                    }
                }
            }
            update_post_meta((int) $loser['post_id'], 'ahura_duplicate_of', (int) $winner['post_id']);
            wp_update_post(array('ID' => (int) $loser['post_id'], 'post_status' => 'draft'));
            $wpdb->update(AHURA_DRPLUS_SPEC, array('status' => 'reject'), array('id' => (int) $loser['id']));
            wp_update_comment_count((int) $winner['post_id']);
            return $out;
        }
    }

    register_rest_route(AHURA_NS, '/doctors/merge-name-matches', array(
        'methods' => 'POST', 'permission_callback' => 'ahura_permission',
        'callback' => function ( $req ) {
            global $wpdb;
            @set_time_limit(300);
            ahura_merge_tables();
            $body = $req->get_json_params();
            $body = is_array($body) ? $body : array();
            $dry = !empty($body['dry_run']);
            $mode = in_array((string) ($body['mode'] ?? 'exact'), array('exact', 'contact'), true) ? (string) $body['mode'] : 'exact';
            $pairs = isset($body['pairs']) && is_array($body['pairs']) ? array_slice($body['pairs'], 0, 500) : array();
            if (!$pairs) return ahura_fail('validation_error', 'pairs are required as [[loser_spec,winner_spec],...].', 422);

            $done = 0; $comments = 0; $offices = 0; $skips = array();
            foreach ($pairs as $pair) {
                $a = is_array($pair) ? array((int) ($pair[0] ?? 0), (int) ($pair[1] ?? 0)) : array(0, 0);
                $r = ahura_merge_one($a[0], $a[1], $dry, $mode);
                if (!empty($r['skip'])) { $skips[$r['skip']] = (int) ($skips[$r['skip']] ?? 0) + 1; continue; }
                $done++; $comments += (int) $r['comments']; $offices += (int) $r['offices_added'];
            }
            return ahura_ok(array(
                'dry_run' => $dry, 'merged' => $done, 'comments_moved' => $comments,
                'offices_added' => $offices, 'skipped' => $skips, 'requested' => count($pairs),
                'logged_total' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AHURA_MERGE_GROUP),
            ));
        },
    ));

    register_rest_route(AHURA_NS, '/doctors/merge-name-matches/rollback', array(
        'methods' => 'POST', 'permission_callback' => 'ahura_permission',
        'callback' => function ( $req ) {
            global $wpdb;
            @set_time_limit(300);
            ahura_merge_tables();
            $body = $req->get_json_params();
            $limit = max(1, min(500, (int) ((is_array($body) ? $body : array())['limit'] ?? 100)));
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . AHURA_MERGE_GROUP . ' ORDER BY id DESC LIMIT %d', $limit), ARRAY_A);
            $restored = 0;
            foreach ($rows as $r) {
                $tracked = $wpdb->get_results($wpdb->prepare('SELECT comment_id, action, row_json FROM ' . AHURA_MERGE_COMMENT . ' WHERE old_post = %d', (int) $r['loser_post']), ARRAY_A);
                $move_ids = array();
                foreach ($tracked as $c) {
                    if ('del' === $c['action']) { ahura_restore_comment($c['row_json']); continue; }
                    $move_ids[] = (int) $c['comment_id'];
                }
                if ($move_ids) {
                    $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->comments . ' SET comment_post_ID = %d WHERE comment_ID IN ('
                        . implode(',', array_map('intval', $move_ids)) . ')', (int) $r['loser_post']));
                }
                $wpdb->query($wpdb->prepare('DELETE FROM ' . AHURA_MERGE_COMMENT . ' WHERE old_post = %d', (int) $r['loser_post']));
                $wpdb->update(AHURA_DRPLUS_SPEC, array('meta' => $r['winner_meta_before'], 'offices' => $r['winner_offices_before']), array('id' => (int) $r['winner_spec']));
                $wpdb->update(AHURA_DRPLUS_SPEC, array('status' => $r['loser_spec_status']), array('id' => (int) $r['loser_spec']));
                wp_update_post(array('ID' => (int) $r['loser_post'], 'post_status' => $r['loser_post_status']));
                delete_post_meta((int) $r['loser_post'], 'ahura_duplicate_of');
                $wpdb->delete(AHURA_MERGE_GROUP, array('id' => (int) $r['id']));
                wp_update_comment_count((int) $r['winner_post']);
                wp_update_comment_count((int) $r['loser_post']);
                $restored++;
            }
            return ahura_ok(array('restored' => $restored, 'groups_left' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AHURA_MERGE_GROUP)));
        },
    ));

    /**
     * Remove reviews that were imported twice onto one profile: same author, same timestamp, same text.
     * The copy a merge brought in is the one dropped, so the survivor keeps its own row.
     *
     * POST /doctors/duplicate-reviews/dedupe  {dry_run:true, limit:50}
     */
    register_rest_route(AHURA_NS, '/doctors/duplicate-reviews/dedupe', array(
        'methods' => 'POST', 'permission_callback' => 'ahura_permission',
        'callback' => function ( $req ) {
            global $wpdb;
            @set_time_limit(300);
            ahura_merge_tables();
            $body = $req->get_json_params();
            $body = is_array($body) ? $body : array();
            $dry = !empty($body['dry_run']);
            $limit = max(1, min(200, (int) ($body['limit'] ?? 50)));
            $after = max(0, (int) ($body['after'] ?? 0));

            if (!empty($body['restore'])) {
                $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . AHURA_MERGE_COMMENT . " WHERE action = 'dup' ORDER BY id DESC LIMIT %d", $limit));
                $back = 0;
                foreach ($ids as $tid) {
                    $c = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . AHURA_MERGE_COMMENT . ' WHERE id = %d', (int) $tid), ARRAY_A);
                    if ($c && ahura_restore_comment($c['row_json'])) $back++;
                    $wpdb->delete(AHURA_MERGE_COMMENT, array('id' => (int) $tid));
                }
                if ($back && class_exists('\LiteSpeed\Purge')) { \LiteSpeed\Purge::purge_all('restored duplicate reviews'); }
                return ahura_ok(array('restored' => $back, 'left' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AHURA_MERGE_COMMENT . " WHERE action = 'dup'")));
            }

            $sig = ahura_review_sig_sql();
            // Only copies a merge moved in are eligible. Two identical anonymous reviews that were
            // both on the same profile from the start cannot be told apart, so they are left alone
            // unless the caller widens the rule deliberately.
            $only_moved = empty($body['include_native']);
            $from = $only_moved ? ' WHERE c.comment_post_ID IN (SELECT winner_post FROM ' . AHURA_MERGE_GROUP . ')' : '';
            $posts = $wpdb->get_col($wpdb->prepare(
                'SELECT pid FROM (SELECT comment_post_ID AS pid, ' . $sig . ' AS s, COUNT(*) AS c FROM ' . $wpdb->comments . ' c' . $from . ' GROUP BY pid, s HAVING c > 1) t GROUP BY pid ORDER BY pid LIMIT %d OFFSET %d',
                $limit, $after));
            $moved = 'SELECT comment_id FROM ' . AHURA_MERGE_COMMENT . " WHERE action = 'move'";
            $dropped = 0; $skipped_groups = 0; $detail = array();
            foreach ($posts as $pid) {
                $groups = $wpdb->get_results($wpdb->prepare(
                    'SELECT GROUP_CONCAT(comment_ID ORDER BY comment_ID) AS ids,
                            GROUP_CONCAT(comment_ID IN (' . $moved . ') ORDER BY comment_ID) AS came FROM ' . $wpdb->comments . "
                     WHERE comment_post_ID = %d
                     GROUP BY " . $sig . ' HAVING COUNT(*) > 1',
                    (int) $pid), ARRAY_A);
                $before = count($groups);
                foreach ($groups as $g) {
                    $ids = array_map('intval', explode(',', (string) $g['ids']));
                    $came = array_map('intval', explode(',', (string) $g['came']));
                    $keep = null;
                    foreach ($ids as $i => $id) { if (empty($came[$i])) { $keep = $id; break; } }
                    if (null === $keep) $keep = $ids[0];
                    foreach ($ids as $i => $id) {
                        if ($id === $keep) continue;
                        if ($only_moved && empty($came[$i])) { $skipped_groups++; continue; }
                        $dropped++;
                        if (!$dry) ahura_remove_comment($id, (int) $pid, 'dup');
                    }
                }
                if ($before) {
                    if (!$dry) wp_update_comment_count_now((int) $pid);
                    $detail[] = array('post' => (int) $pid, 'twin_groups' => $before,
                        'left' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->comments . ' WHERE comment_post_ID = %d', (int) $pid)));
                }
            }
            if (!$dry && $dropped && class_exists('\LiteSpeed\Purge')) { \LiteSpeed\Purge::purge_all('duplicate reviews removed'); }
            return ahura_ok(array('dry_run' => $dry, 'posts' => count($posts), 'after' => $after,
                'only_moved_copies' => $only_moved, 'native_pairs_left_alone' => $skipped_groups,
                'duplicates_dropped' => $dropped, 'detail' => array_slice($detail, 0, 20)));
        },
    ));
});

if (!defined('AHURA_REVIEW_TABLE')) define('AHURA_REVIEW_TABLE', 'Dj3i_ahura_dupe_review');

// Helpers live at file scope: the page handler runs on init, long before rest_api_init fires.
    if (!function_exists('ahura_merge_rows')) {
        /** Merge a claimed set of queue rows. Every write goes through the reversible merge path. */
        function ahura_merge_rows($rows, $dry = false) {
            global $wpdb;
            $merged = 0; $moved = 0; $dropped = 0; $offices = 0; $skips = array();
            $now = current_time('mysql');
            foreach ($rows as $r) {
                $res = ahura_merge_one((int) $r['loser_spec'], (int) $r['winner_spec'], $dry, 'force');
                if (!empty($res['skip'])) {
                    $sk = (string) $res['skip'];
                    $skips[$sk] = (int) ($skips[$sk] ?? 0) + 1;
                    if (!$dry) $wpdb->update(AHURA_REVIEW_TABLE,
                        array('status' => 'skipped', 'note' => $sk, 'reviewed_at' => $now), array('id' => (int) $r['id']));
                    continue;
                }
                $merged++;
                $moved += (int) $res['comments']; $dropped += (int) $res['dupes_dropped']; $offices += (int) $res['offices_added'];
                if (!$dry) $wpdb->update(AHURA_REVIEW_TABLE, array('status' => 'merged', 'reviewed_at' => $now), array('id' => (int) $r['id']));
            }
            return array('merged' => $merged, 'comments_moved' => $moved, 'dupes_dropped' => $dropped,
                          'offices_added' => $offices, 'skipped' => $skips);
        }
    }

    if (!function_exists('ahura_review_table')) {
        function ahura_review_table() {
            global $wpdb;
            $charset = $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta('CREATE TABLE ' . AHURA_REVIEW_TABLE . " (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                loser_spec BIGINT UNSIGNED NOT NULL,
                winner_spec BIGINT UNSIGNED NOT NULL,
                reason VARCHAR(191) NOT NULL DEFAULT '',
                score INT NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                note VARCHAR(191) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                reviewed_at DATETIME NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY pair (loser_spec, winner_spec),
                KEY status (status),
                KEY score (score)
            ) {$charset};");
        }
    }

    if (!function_exists('ahura_review_row')) {
        /** Public view of one candidate with both profiles resolved. */
        function ahura_review_row($row) {
            global $wpdb;
            $out = array('id' => (int) $row['id'], 'reason' => $row['reason'], 'score' => (int) $row['score'],
                         'status' => $row['status'], 'note' => $row['note']);
            foreach (array('loser' => (int) $row['loser_spec'], 'winner' => (int) $row['winner_spec']) as $side => $spec_id) {
                $s = $wpdb->get_row($wpdb->prepare('SELECT id, post_id, user_id, name, status, meta, offices FROM ' . AHURA_DRPLUS_SPEC . ' WHERE id = %d', $spec_id), ARRAY_A);
                if (!$s) { $out[$side] = null; continue; }
                $meta = ahura_json($s['meta']);
                $out[$side] = array(
                    'spec' => (int) $s['id'], 'post' => (int) $s['post_id'], 'user' => (int) $s['user_id'],
                    'name' => $s['name'], 'row_status' => $s['status'],
                    'url' => get_permalink((int) $s['post_id']),
                    'status' => get_post_status((int) $s['post_id']),
                    'city' => $meta['city'] ?? '', 'specialty' => $meta['specialty'] ?? '',
                    'phones' => (array) ($meta['phones'] ?? array()), 'address' => $meta['address'] ?? '',
                    'licence' => (string) get_user_meta((int) $s['user_id'], 'specialist_code', true),
                    'reviews' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d", (int) $s['post_id'])),
                    'offices' => array_map(function ($o) { return array('address' => $o['address'] ?? '', 'phone' => $o['phone'] ?? ''); },
                        array_slice((array) ahura_json($s['offices']), 0, 3)),
                    'sources' => array_values(array_filter(array($meta['doctoreto_profile_url'] ?? '', $meta['nobat_profile_url'] ?? '', $meta['profile_url'] ?? ''))),
                );
            }
            return $out;
        }
    }

add_action('rest_api_init', function () {

    /**
     * Duplicate review queue. Candidates are loaded by an offline audit and stored here so a
     * human can merge or dismiss each one. Merging reuses the same reversible path as the bulk
     * batches, only with the identity gate lifted because a person already looked at the pair.
     *
     * POST /doctors/duplicate-review  {action:'add'|'list'|'merge'|'dismiss'|'stats', ...}
     * GET  /?visital_dupe_review=1&key=<ahura key>   human page
     */

    register_rest_route(AHURA_NS, '/doctors/duplicate-review', array(
        'methods' => 'POST', 'permission_callback' => 'ahura_permission',
        'callback' => function ( $req ) {
            global $wpdb;
            @set_time_limit(300);
            ahura_review_table();
            $body = $req->get_json_params();
            $body = is_array($body) ? $body : array();
            $action = (string) ($body['action'] ?? 'list');

            if ('add' === $action) {
                $items = is_array($body['items'] ?? null) ? array_slice($body['items'], 0, 2000) : array();
                $now = current_time('mysql'); $added = 0; $known = 0;
                foreach ($items as $it) {
                    $loser = (int) ($it['loser'] ?? 0); $winner = (int) ($it['winner'] ?? 0);
                    if (!$loser || !$winner || $loser === $winner) continue;
                    if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . AHURA_MERGE_GROUP . ' WHERE loser_spec = %d', $loser))) { $known++; continue; }
                    $exists = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . AHURA_REVIEW_TABLE . ' WHERE loser_spec = %d AND winner_spec = %d', $loser, $winner));
                    if ($exists) { $known++; continue; }
                    $wpdb->insert(AHURA_REVIEW_TABLE, array(
                        'loser_spec' => $loser, 'winner_spec' => $winner,
                        'reason' => mb_substr((string) ($it['reason'] ?? ''), 0, 190, 'UTF-8'),
                        'score' => (int) ($it['score'] ?? 0), 'status' => 'pending', 'created_at' => $now,
                    ));
                    $added++;
                }
                return ahura_ok(array('added' => $added, 'already_known' => $known,
                    'pending' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AHURA_REVIEW_TABLE . " WHERE status='pending'")));
            }

            if ('list' === $action) {
                $limit = max(1, min(200, (int) ($body['limit'] ?? 40)));
                $offset = max(0, (int) ($body['offset'] ?? 0));
                $only = 'pending';
                if (!empty($body['all'])) $only = '';
                $where = $only ? $wpdb->prepare("WHERE status = %s", $only) : '';
                $ids = $wpdb->get_col('SELECT id FROM ' . AHURA_REVIEW_TABLE . " {$where} ORDER BY score DESC, id ASC LIMIT {$limit} OFFSET {$offset}");
                $rows = array();
                foreach ($ids as $id) {
                    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . AHURA_REVIEW_TABLE . ' WHERE id = %d', (int) $id), ARRAY_A);
                    if ($r) $rows[] = ahura_review_row($r);
                }
                return ahura_ok(array('rows' => $rows, 'limit' => $limit, 'offset' => $offset,
                    'pending' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AHURA_REVIEW_TABLE . " WHERE status='pending'"),
                    'merged' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AHURA_REVIEW_TABLE . " WHERE status='merged'"),
                    'skipped' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AHURA_REVIEW_TABLE . " WHERE status='skipped'"),
                    'dismissed' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AHURA_REVIEW_TABLE . " WHERE status='dismissed'")));
            }

            if ('merge' === $action) {
                $id = (int) ($body['id'] ?? 0);
                $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . AHURA_REVIEW_TABLE . ' WHERE id = %d', $id), ARRAY_A);
                if (!$r) return ahura_fail('not_found', 'Review row not found.', 404);
                if ('pending' !== $r['status']) return ahura_fail('already_handled', 'This pair is already ' . $r['status'] . '.', 409);
                // A human decided these are one person, so the automatic identity gate is lifted.
                $res = ahura_merge_one((int) $r['loser_spec'], (int) $r['winner_spec'], false, 'force');
                if (!empty($res['skip'])) return ahura_fail('merge_refused', 'Merge refused: ' . $res['skip'], 409);
                $wpdb->update(AHURA_REVIEW_TABLE, array('status' => 'merged', 'reviewed_at' => current_time('mysql')), array('id' => $id));
                if (class_exists('\LiteSpeed\Purge')) {
                    $urls = array_filter(array(get_permalink((int) $res['loser_post']), get_permalink((int) $res['winner_post'])));
                    if (method_exists('\LiteSpeed\Purge', 'purge_url')) {
                        foreach ($urls as $u) { \LiteSpeed\Purge::purge_url($u); }
                    } else {
                        \LiteSpeed\Purge::purge_all('duplicate merged in review queue');
                    }
                }
                return ahura_ok(array('merged' => true, 'detail' => $res));
            }

            if ('merge-all' === $action || 'merge-claim' === $action) {
                // Operator decided the whole queue is duplicates: merge every pending row through the
                // same reversible path the per-row button uses. Refusals are stamped on the row instead
                // of silently dropped, so a second pass can be explained.
                $batch = max(1, min(200, (int) ($body['batch'] ?? 25)));
                $dry = !empty($body['dry_run']);
                $claim = 'merge-claim' === $action && !$dry;
                $token = '';
                if ($claim) {
                    // Claim atomically so several workers never touch the same pair.
                    $token = substr(md5(microtime(true) . wp_generate_password(8, false, false)), 0, 12);
                    $wpdb->query($wpdb->prepare('UPDATE ' . AHURA_REVIEW_TABLE .
                        " SET status = 'processing', note = %s WHERE status = 'pending' ORDER BY score DESC, id ASC LIMIT %d",
                        $token, $batch));
                    $rows = $wpdb->get_results($wpdb->prepare('SELECT id, loser_spec, winner_spec FROM ' . AHURA_REVIEW_TABLE .
                        " WHERE status = 'processing' AND note = %s", $token), ARRAY_A);
                } else {
                    $rows = $wpdb->get_results(
                        'SELECT id, loser_spec, winner_spec FROM ' . AHURA_REVIEW_TABLE .
                        " WHERE status = 'pending' ORDER BY score DESC, id ASC LIMIT " . $batch, ARRAY_A);
                }
                $stats = ahura_merge_rows($rows, $dry);
                if (!$dry && $stats['merged'] && class_exists('\LiteSpeed\Purge') && method_exists('\LiteSpeed\Purge', 'purge_all')) {
                    \LiteSpeed\Purge::purge_all('review queue merged');
                }
                return ahura_ok(array_merge($stats, array(
                    'dry_run' => $dry, 'attempted' => count($rows), 'token' => $token,
                    'pending_left' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AHURA_REVIEW_TABLE . " WHERE status='pending'"),
                    'processing_left' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AHURA_REVIEW_TABLE . " WHERE status='processing'"),
                )));
            }

            if ('retry-stale' === $action) {
                // Rows left as processing by an interrupted worker go back into the queue.
                $n = (int) $wpdb->query('UPDATE ' . AHURA_REVIEW_TABLE . " SET status = 'pending', note = '' WHERE status = 'processing'");
                return ahura_ok(array('requeued' => $n,
                    'pending' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AHURA_REVIEW_TABLE . " WHERE status='pending'")));
            }

            if ('finish-queue' === $action) {
                // Chains: a survivor that was itself merged later leaves drafted posts pointing at a
                // draft. Point every drafted post straight at its final published profile, and requeue
                // skipped rows whose winner has since moved on.
                $links = $wpdb->get_results('SELECT loser_post, winner_post FROM ' . AHURA_MERGE_GROUP, ARRAY_A);
                $map = array();
                foreach ($links as $l) { $map[(int) $l['loser_post']] = (int) $l['winner_post']; }
                $memo = array();
                $resolve = function ($id) use (&$resolve, &$map, &$memo) {
                    $id = (int) $id;
                    if (isset($memo[$id])) return $memo[$id];
                    $memo[$id] = 0; // guards against a cycle
                    if ('publish' === get_post_status($id)) { $memo[$id] = $id; return $id; }
                    $next = isset($map[$id]) ? $resolve($map[$id]) : 0;
                    $memo[$id] = $next;
                    return $next;
                };

                $pointers = $wpdb->get_results("SELECT post_id, meta_value AS target FROM {$wpdb->postmeta} WHERE meta_key = 'ahura_duplicate_of'", ARRAY_A);
                $repointed = 0; $orphan = 0;
                foreach ($pointers as $p) {
                    $pid = (int) $p['post_id'];
                    if ('publish' === get_post_status($pid)) continue;
                    $final = $resolve((int) $p['target']);
                    if (!$final) { $orphan++; continue; }
                    if ($final !== (int) $p['target']) { update_post_meta($pid, 'ahura_duplicate_of', $final); $repointed++; }
                }

                $requeued = 0;
                $stuck = $wpdb->get_results("SELECT id, loser_spec, winner_spec FROM " . AHURA_REVIEW_TABLE .
                    " WHERE status = 'skipped' AND note = 'winner_not_live'", ARRAY_A);
                foreach ($stuck as $s) {
                    $w = $wpdb->get_row($wpdb->prepare('SELECT post_id FROM ' . AHURA_DRPLUS_SPEC . ' WHERE id = %d', (int) $s['winner_spec']), ARRAY_A);
                    if (!$w) continue;
                    $final = $resolve((int) $w['post_id']);
                    if (!$final) continue;
                    $spec = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . AHURA_DRPLUS_SPEC . ' WHERE post_id = %d LIMIT 1', $final));
                    if (!$spec || (int) $spec === (int) $s['loser_spec']) continue;
                    $wpdb->update(AHURA_REVIEW_TABLE, array('winner_spec' => (int) $spec, 'status' => 'pending', 'note' => 'winner moved', 'reviewed_at' => null),
                        array('id' => (int) $s['id']));
                    $requeued++;
                }
                if (($repointed || $requeued) && class_exists('\LiteSpeed\Purge')) { \LiteSpeed\Purge::purge_all('duplicate chains compacted'); }
                return ahura_ok(array('pointers_checked' => count($pointers), 'repointed' => $repointed,
                    'orphans' => $orphan, 'skipped_rows' => count($stuck), 'requeued' => $requeued));
            }

            if ('dismiss' === $action) {
                $id = (int) ($body['id'] ?? 0);
                $note = mb_substr((string) ($body['note'] ?? 'dismissed by review'), 0, 190, 'UTF-8');
                $n = $wpdb->update(AHURA_REVIEW_TABLE, array('status' => 'dismissed', 'note' => $note, 'reviewed_at' => current_time('mysql')), array('id' => $id, 'status' => 'pending'));
                if (!$n) return ahura_fail('not_found', 'Nothing to dismiss.', 404);
                return ahura_ok(array('dismissed' => true, 'id' => $id));
            }

            return ahura_fail('validation_error', 'Unknown action.', 422);
        },
    ));
});

// Human-facing queue page. Same key as the API, nothing renders without it.
add_action('init', function () {
    if (!isset($_GET['visital_dupe_review'])) return;
    // Beat LiteSpeed's own init hook so the keyed page is never written to the page cache.
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}, 1);

add_action('init', function () {
    if (!isset($_GET['visital_dupe_review'])) return;
    $key = (string) get_option(AHURA_KEY_OPTION, '');
    if (!$key || !hash_equals($key, (string) ($_GET['key'] ?? ''))) { status_header(404); exit; }
    // A broken queue page must fail here, not take the site with it.
    try {
    global $wpdb;
    ahura_review_table();
    $api = esc_url(home_url('/wp-json/' . AHURA_NS));
    $pending = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AHURA_REVIEW_TABLE . " WHERE status='pending'");
    $merged = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AHURA_REVIEW_TABLE . " WHERE status='merged'");
    $skipped = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AHURA_REVIEW_TABLE . " WHERE status='skipped'");
    $dismissed = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . AHURA_REVIEW_TABLE . " WHERE status='dismissed'");
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex, nofollow', true);
    $js = 'const ENT=[[String.fromCharCode(38),"&amp;"],[String.fromCharCode(60),"&lt;"],[String.fromCharCode(62),"&gt;"],[String.fromCharCode(34),"&quot;"]];
const H=s=>{let v=String(s??"");for(const e of ENT){v=v.split(e[0]).join(e[1]);}return v;};
let off=0,size=10,rows=[];
async function post(a,b={}){const r=await fetch(API+"/doctors/duplicate-review",{method:"POST",headers:{"Content-Type":"application/json","X-Ahura-Key":KEY},body:JSON.stringify(Object.assign({action:a},b))});return await r.json();}
function side(x){
 if(!x)return "<side>پیدا نشد</side>";
 const ph=(x.phones||[]).join(" , ")||"-";
 const of=(x.offices||[]).map(o=>H(o.address)+" / "+H(o.phone)).join("<br>")||"-";
 const src=(x.sources||[]).slice(0,2).map(s=>" | <a target=\"_blank\" href=\""+H(s)+"\">منبع</a>").join("");
 return "<side><h3>"+H(x.name)+"</h3>"
  +"<div><span class=k>منبع:</span> "+H(x.row_status)+" / "+H(x.status)+"</div>"
  +"<div><span class=k>شهر:</span> "+(H(x.city)||"-")+" <span class=k>تخصص:</span> "+(H(x.specialty)||"-")+"</div>"
  +"<div><span class=k>نظام پزشکی:</span> "+(H(x.licence)||"-")+" <span class=k>دیدگاه:</span> "+x.reviews+"</div>"
  +"<div><span class=k>تلفن:</span> "+H(ph)+"</div>"
  +"<div><span class=k>آدرس:</span> "+(H(x.address)||"-")+"</div>"
  +"<div><span class=k>مطب:</span> "+of+"</div>"
  +"<div><span class=k>لینک:</span> <a target=\"_blank\" href=\""+H(x.url||"")+"\">پروفایل</a>"+src+"</div>"
  +"</side>";
}
function render(){
 const L=document.getElementById("list");
 if(!rows.length){L.innerHTML="<div class=card>موردی باقی نمانده است.</div>";return;}
 L.innerHTML=rows.map(r=>{
  const head="<h2>"+H(r.reason)+" <span class=k>امتیاز "+r.score+"</span></h2>";
  const why="<div class=why>نوار چپ حذف و در نوار راست ادغام می‌شود. دیدگاه‌ها: "+(r.loser?r.loser.reviews:0)+" در برابر "+(r.winner?r.winner.reviews:0)+"</div>";
  const cols="<p2>"+side(r.loser)+side(r.winner)+"</p2>";
  const btns=r.status==="pending"
   ?"<div style=\"margin-top:10px\"><button class=ok onclick=\"act("+r.id+",1)\">ادغام</button><button class=no onclick=\"act("+r.id+",0)\">رد</button></div>"
   :"<div>وضعیت: "+H(r.status)+" "+H(r.note)+"</div>";
  return "<div class=\"card"+(r.status==="pending"?"":" done")+"\">"+head+why+cols+btns+"</div>";
 }).join("");
}
async function load(){
 const d=await post("list",{limit:size,offset:off});
 const t=(d&&d.data)||{};
 rows=t.rows||[];render();
 document.getElementById("msg").textContent=(t.pending||0)+" در انتظار  |  "+(t.merged||0)+" ادغام شده  |  "+(t.skipped||0)+" معاف  |  "+(t.dismissed||0)+" رد شده";
}
async function act(id,doMerge){
 const d=doMerge?await post("merge",{id}):await post("dismiss",{id,note:"dismissed in review page"});
 if(d&&d.error){document.getElementById("msg").textContent="خطا: "+((d.error&&d.error.message)||JSON.stringify(d.error));return;}
 if(doMerge&&d&&d.data&&d.data.detail){const x=d.data.detail;document.getElementById("msg").textContent="ادغام شد: "+(x.comments||0)+" دیدگاه منتقل شد، "+(x.dupes_dropped||0)+" تکراری حذف شد، "+(x.offices_added||0)+" مطب اضافه شد";}
 await load();
}
document.getElementById("next").onclick=()=>{off+=size;load();};
document.getElementById("prev").onclick=()=>{off=Math.max(0,off-size);load();};
load();'
    ;
    echo '<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>بازبینی تکراری‌ها</title><style>'
        . 'body{font-family:Tahoma,sans-serif;background:#0f1b26;color:#e6f0f5;margin:0;padding:18px}'
        . 'h1{font-size:19px;margin:0 0 6px}.tallies{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}'
        . 'b{display:block;font-size:20px}.t{background:#16283a;border:1px solid #23405a;border-radius:10px;padding:8px 14px;font-size:13px}'
        . '.card{display:block;background:#16283a;border:1px solid #23405a;border-radius:12px;padding:14px;margin-bottom:12px}'
        . 'h2{font-size:15px;margin:0 0 8px}.why{background:#23324a;border-radius:8px;padding:6px 10px;font-size:12px;margin-bottom:10px}'
        . 'p2{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:820px){p2{grid-template-columns:1fr}}'
        . 'side{display:block;background:#111f2c;border:1px solid #23405a;border-radius:10px;padding:10px;font-size:12.5px;line-height:2}'
        . 'side h3{font-size:14px;margin:0 0 6px}.k{color:#8fb2c8}a{color:#6fe0c0}'
        . 'button{border:0;border-radius:8px;padding:9px 18px;font:inherit;cursor:pointer;margin-inline-end:8px}'
        . 'ok{background:#55d6ad;color:#06251f}.no{background:#2d4358;color:#cfe3ee}.done{opacity:.45}'
        . '.msg,.msg{font-size:12.5px;color:#8fb2c8;margin-bottom:12px}nav{margin-top:14px;display:flex;gap:10px}'
        . '</style></head><body><h1>بازبینی پزشکان تکراری</h1>'
        . '<div class="tallies"><div class="t">در انتظار<b>' . $pending . '</b></div>'
        . '<div class="t">ادغام‌شده<b>' . $merged . '</b></div>'
        . '<div class="t">ردشده<b>' . $dismissed . '</b></div>'
        . '<div class="t">معاف<b>' . $skipped . '</b></div></div>'
        . '<div class="msg" id="msg"></div><div id="list">در حال بارگذاری…</div>'
        . '<nav><button class="no" id="prev">قبلی</button><button class="no" id="next">بعدی</button></nav>'
        . '<script>const API=' . wp_json_encode($api) . ',KEY=' . wp_json_encode($key) . ';' . $js . '</script></body></html>';
        exit;
    } catch (\Throwable $e) {
        error_log('duplicate review page: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        status_header(500);
        header('Content-Type: text/html; charset=UTF-8');
        wp_die('صفحه بازبینی خطا داد. جزئیات در error_log ثبت شد.', 'خطای صفحه بازبینی', array('response' => 500));
    }
});
