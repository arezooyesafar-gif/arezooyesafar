<?php
/**
 * Ahura Import API — Part 2 endpoints (appended by ahura_build_part2.py).
 * Everything else: posts, users, meta, orders, wallet, chat, otp, sms,
 * wishlist, elementor, menus, attachments, options, schema, sql, site.
 * Loaded as a separate file so the main plugin file stays manageable.
 *
 * NOTE: this file is include()d from ahura.php — no plugin header here.
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', 'ahura_register_routes_part2');

function ahura_register_routes_part2() {

    /* ---------------- generic posts reader/writer ---------------- */

    register_rest_route(AHURA_NS, '/posts', array(
        array(
            'methods'  => 'GET',
            'callback' => function ($req) {
                $args = array(
                    'post_type'      => sanitize_key((string) ($req->get_param('post_type') ?: 'post')),
                    'post_status'    => (string) ($req->get_param('status') ?: 'any'),
                    's'              => sanitize_text_field((string) $req->get_param('search')),
                    'paged'          => max(1, (int) $req->get_param('page')),
                    'posts_per_page' => min(100, max(1, (int) ($req->get_param('per_page') ?: 20))),
                );
                if ($req->get_param('fields') === 'ids') $args['fields'] = 'ids';
                $q = new WP_Query($args);
                $out = array();
                foreach ($q->posts as $p) {
                    if (is_object($p) && isset($p->ID) && isset($p->post_title)) {
                        $out[] = array('id' => $p->ID, 'title' => $p->post_title, 'type' => $p->post_type,
                                       'status' => $p->post_status, 'date' => $p->post_date, 'slug' => $p->post_name);
                    } else {
                        $out[] = array('id' => (int) $p);
                    }
                }
                return ahura_ok($out);
            },
            'permission_callback' => 'ahura_permission',
        ),
        array(
            'methods'  => 'POST',
            'callback' => function ($req) {
                $b = $req->get_json_params();
                $type = sanitize_key($b['post_type'] ?? 'post');
                $r = ahura_upsert_post($type, $b, 'publish');
                if (is_wp_error($r)) return ahura_error_response($r);
                return ahura_ok($r, $r['created'] ? 201 : 200);
            },
            'permission_callback' => 'ahura_permission',
        ),
    ));

    register_rest_route(AHURA_NS, '/posts/(?P<id>\d+)', array(
        array(
            'methods'  => 'GET',
            'callback' => function ($req) {
                $p = get_post((int) $req['id']);
                if (!$p) return ahura_fail('not_found', 'Post not found.', 404);
                $meta = get_post_meta($p->ID);
                $terms = array();
                foreach (get_object_taxonomies($p->post_type) as $tax) {
                    $t = wp_get_post_terms($p->ID, $tax);
                    if ($t && !is_wp_error($t)) $terms[$tax] = wp_list_pluck($t, 'name');
                }
                return ahura_ok(array(
                    'id' => $p->ID, 'type' => $p->post_type, 'status' => $p->post_status,
                    'title' => $p->post_title, 'slug' => $p->post_name,
                    'content' => $p->post_content, 'excerpt' => $p->post_excerpt,
                    'author_id' => (int) $p->post_author, 'date' => $p->post_date,
                    'modified' => $p->post_modified, 'parent' => (int) $p->post_parent,
                    'meta' => $meta, 'terms' => $terms,
                ));
            },
            'permission_callback' => 'ahura_permission',
        ),
        array(
            'methods'  => 'DELETE',
            'callback' => function ($req) {
                $id = (int) $req['id'];
                if (!get_post($id)) return ahura_fail('not_found', 'Post not found.', 404);
                wp_delete_post($id, true);
                return ahura_ok(array('deleted' => $id));
            },
            'permission_callback' => 'ahura_permission',
        ),
    ));

    /* ---------------- postmeta ---------------- */

    register_rest_route(AHURA_NS, '/posts/(?P<id>\d+)/meta', array(
        array(
            'methods'  => 'GET',
            'callback' => function ($req) {
                return ahura_ok(get_post_meta((int) $req['id']));
            },
            'permission_callback' => 'ahura_permission',
        ),
        array(
            'methods'  => 'POST',
            'callback' => function ($req) {
                $id = (int) $req['id'];
                if (!get_post($id)) return ahura_fail('not_found', 'Post not found.', 404);
                $b = $req->get_json_params();
                if (!is_array($b)) return ahura_fail('invalid_json', 'Body must be JSON.', 400);
                foreach ($b as $k => $v) update_post_meta($id, sanitize_key($k), $v);
                return ahura_ok(array('updated' => array_keys($b)));
            },
            'permission_callback' => 'ahura_permission',
        ),
    ));

    /* ---------------- users (list/get/delete; POST handled in part 1) ---------------- */

    register_rest_route(AHURA_NS, '/users/list', array(
        'methods'  => 'GET',
        'callback' => function ($req) {
            $page = max(1, (int) $req->get_param('page'));
            $per  = min(100, max(1, (int) ($req->get_param('per_page') ?: 20)));
            $args = array('number' => $per, 'paged' => $page);
            if ($req->get_param('role')) $args['role'] = sanitize_key((string) $req->get_param('role'));
            if ($req->get_param('search')) $args['search'] = '*' . sanitize_text_field((string) $req->get_param('search')) . '*';
            $q = new WP_User_Query($args);
            $out = array();
            foreach ($q->get_results() as $u) {
                $out[] = array('id' => $u->ID, 'login' => $u->user_login,
                               'email' => $u->user_email, 'display' => $u->display_name,
                               'registered' => $u->user_registered);
            }
            return ahura_ok(array('total' => $q->get_total(), 'users' => $out));
        },
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/users/(?P<id>\d+)', array(
        array(
            'methods'  => 'GET',
            'callback' => function ($req) {
                $u = get_userdata((int) $req['id']);
                if (!$u) return ahura_fail('not_found', 'User not found.', 404);
                return ahura_ok(array(
                    'id' => $u->ID, 'login' => $u->user_login, 'email' => $u->user_email,
                    'display' => $u->display_name, 'roles' => $u->roles,
                    'registered' => $u->user_registered,
                ));
            },
            'permission_callback' => 'ahura_permission',
        ),
        array(
            'methods'  => 'DELETE',
            'callback' => function ($req) {
                $id = (int) $req['id'];
                if (!get_userdata($id)) return ahura_fail('not_found', 'User not found.', 404);
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user($id);
                return ahura_ok(array('deleted' => $id));
            },
            'permission_callback' => 'ahura_permission',
        ),
    ));

    /* ---------------- comment single read/update ---------------- */

    register_rest_route(AHURA_NS, '/comments/(?P<id>\d+)', array(
        array(
            'methods'  => 'GET',
            'callback' => function ($req) {
                $c = get_comment((int) $req['id']);
                if (!$c) return ahura_fail('not_found', 'Comment not found.', 404);
                $arr = (array) $c;
                $arr['meta'] = get_comment_meta($c->comment_ID);
                return ahura_ok($arr);
            },
            'permission_callback' => 'ahura_permission',
        ),
        array(
            'methods'  => 'POST',
            'callback' => function ($req) {
                $id = (int) $req['id'];
                if (!get_comment($id)) return ahura_fail('not_found', 'Comment not found.', 404);
                $b = $req->get_json_params();
                $patch = array('comment_ID' => $id);
                if (isset($b['content']))  $patch['comment_content'] = wp_kses_post($b['content']);
                if (isset($b['author']))   $patch['comment_author'] = sanitize_text_field($b['author']);
                if (isset($b['approved'])) $patch['comment_approved'] = in_array($b['approved'], array('1','0','spam','trash'), true) ? $b['approved'] : '1';
                if (isset($b['date']))     $patch['comment_date'] = sanitize_text_field(ahura_fa_digits($b['date']));
                wp_update_comment($patch);
                return ahura_ok(array('updated' => $id));
            },
            'permission_callback' => 'ahura_permission',
        ),
    ));

    /* ---------------- WooCommerce orders ---------------- */

    register_rest_route(AHURA_NS, '/orders', array(
        array(
            'methods'  => 'GET',
            'callback' => function ($req) {
                global $wpdb;
                $page = max(1, (int) $req->get_param('page'));
                $per  = min(100, max(1, (int) ($req->get_param('per_page') ?: 20)));
                $off = ($page - 1) * $per;
                $rows = $wpdb->get_results(
                    "SELECT id, status, currency, total_amount, customer_id, billing_email,
                            payment_method, date_created_gmt
                     FROM {$wpdb->prefix}wc_orders ORDER BY id DESC LIMIT $per OFFSET $off", ARRAY_A);
                return ahura_ok($rows);
            },
            'permission_callback' => 'ahura_permission',
        ),
        array(
            'methods'  => 'POST',
            'callback' => function ($req) {
                global $wpdb;
                $b = $req->get_json_params();
                $customer = (int) ($b['customer_id'] ?? 0);
                $total = (string) ($b['total'] ?? 0);
                if (!$customer) return ahura_fail('validation_error', 'customer_id is required.', 422);
                $now = current_time('mysql', true);
                $next_id = (int) $wpdb->get_var("SELECT COALESCE(MAX(id),0) + 1 FROM {$wpdb->prefix}wc_orders");
                $ok = $wpdb->insert($wpdb->prefix . 'wc_orders', array(
                    'id' => $next_id,
                    'status' => sanitize_key($b['status'] ?? 'completed'),
                    'currency' => sanitize_text_field($b['currency'] ?? 'IRT'),
                    'type' => 'shop_order',
                    'total_amount' => $total,
                    'customer_id' => $customer,
                    'billing_email' => sanitize_email($b['billing_email'] ?? ''),
                    'payment_method' => sanitize_text_field($b['payment_method'] ?? ''),
                    'date_created_gmt' => $now,
                    'date_updated_gmt' => $now,
                ));
                if (!$ok) return ahura_fail('insert_failed', 'Could not create order.', 500);
                $order_id = (int) $wpdb->insert_id;

                foreach ((array) ($b['items'] ?? array()) as $item) {
                    $wpdb->insert($wpdb->prefix . 'woocommerce_order_items', array(
                        'order_item_name' => sanitize_text_field($item['name'] ?? 'item'),
                        'order_item_type' => 'line_item',
                        'order_id' => $order_id,
                    ));
                    $item_id = (int) $wpdb->insert_id;
                    $pairs = array(
                        '_product_id' => (int) ($item['product_id'] ?? 0),
                        '_qty' => (int) ($item['quantity'] ?? 1),
                        '_line_total' => (string) ($item['total'] ?? 0),
                    );
                    foreach ($pairs as $k => $v) {
                        $wpdb->insert($wpdb->prefix . 'woocommerce_order_itemmeta', array(
                            'order_item_id' => $item_id, 'meta_key' => $k, 'meta_value' => $v,
                        ));
                    }
                }
                return ahura_ok(array('order_id' => $next_id), 201);
            },
            'permission_callback' => 'ahura_permission',
        ),
    ));

    register_rest_route(AHURA_NS, '/orders/(?P<id>\d+)', array(
        array(
            'methods'  => 'GET',
            'callback' => function ($req) {
                global $wpdb;
                $id = (int) $req['id'];
                $order = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wc_orders WHERE id = %d", $id), ARRAY_A);
                if (!$order) return ahura_fail('not_found', 'Order not found.', 404);
                $order['items'] = $wpdb->get_results($wpdb->prepare(
                    "SELECT oi.order_item_id, oi.order_item_name, oi.order_item_type, m.meta_key, m.meta_value
                     FROM {$wpdb->prefix}woocommerce_order_items oi
                     LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = oi.order_item_id
                     WHERE oi.order_id = %d", $id), ARRAY_A);
                $order['addresses'] = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wc_order_addresses WHERE order_id = %d", $id), ARRAY_A);
                return ahura_ok($order);
            },
            'permission_callback' => 'ahura_permission',
        ),
        array(
            'methods'  => 'DELETE',
            'callback' => function ($req) {
                global $wpdb;
                $id = (int) $req['id'];
                $wpdb->delete($wpdb->prefix . 'wc_orders', array('id' => $id));
                $wpdb->delete($wpdb->prefix . 'wc_orders_meta', array('order_id' => $id));
                $wpdb->delete($wpdb->prefix . 'wc_order_addresses', array('order_id' => $id));
                return ahura_ok(array('deleted' => $id));
            },
            'permission_callback' => 'ahura_permission',
        ),
    ));

    /* ---------------- wallet (sheyda) ---------------- */

    register_rest_route(AHURA_NS, '/wallet/(?P<user_id>\d+)', array(
        array(
            'methods'  => 'GET',
            'callback' => function ($req) {
                global $wpdb;
                $uid = (int) $req['user_id'];
                $bal = $wpdb->get_row($wpdb->prepare(
                    "SELECT balance, locked, updated_at FROM {$wpdb->prefix}sheyda_wallet_balances WHERE user_id=%d",
                    $uid), ARRAY_A);
                if (!$bal) $bal = array('balance' => '0', 'locked' => '0', 'updated_at' => null);
                $bal['ledger'] = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, type, amount, balance_after, related_id, created_at
                     FROM {$wpdb->prefix}sheyda_wallet_ledger WHERE user_id=%d ORDER BY id DESC LIMIT 100",
                    $uid), ARRAY_A);
                return ahura_ok($bal);
            },
            'permission_callback' => 'ahura_permission',
        ),
        array(
            'methods'  => 'POST',
            'callback' => function ($req) {
                global $wpdb;
                $uid = (int) $req['user_id'];
                $b = $req->get_json_params();
                $amount = (float) ($b['amount'] ?? 0);
                $type = sanitize_key($b['type'] ?? 'credit');
                if ($amount === 0.0) return ahura_fail('validation_error', 'amount is required.', 422);
                $sign = ($type === 'debit') ? -1 : 1;

                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$wpdb->prefix}sheyda_wallet_balances (user_id, balance, locked)
                     VALUES (%d, %f, 0) ON DUPLICATE KEY UPDATE balance = balance + %f",
                    $uid, $sign * $amount, $sign * $amount));
                $bal = (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT balance FROM {$wpdb->prefix}sheyda_wallet_balances WHERE user_id=%d", $uid));

                $wpdb->insert($wpdb->prefix . 'sheyda_wallet_ledger', array(
                    'user_id' => $uid, 'type' => $type, 'amount' => (string) ($sign * $amount),
                    'balance_after' => (string) $bal, 'created_by' => 1,
                    'related_id' => (int) ($b['related_id'] ?? 0),
                    'meta' => isset($b['meta']) ? json_encode($b['meta'], JSON_UNESCAPED_UNICODE) : null,
                ));
                return ahura_ok(array('balance' => $bal), 201);
            },
            'permission_callback' => 'ahura_permission',
        ),
    ));

    register_rest_route(AHURA_NS, '/wallet/withdrawals', array(
        array(
            'methods'  => 'GET',
            'callback' => function ($req) {
                global $wpdb;
                return ahura_ok($wpdb->get_results(
                    "SELECT * FROM {$wpdb->prefix}sheyda_wallet_withdrawals ORDER BY id DESC LIMIT 100", ARRAY_A));
            },
            'permission_callback' => 'ahura_permission',
        ),
        array(
            'methods'  => 'POST',
            'callback' => function ($req) {
                global $wpdb;
                $b = $req->get_json_params();
                $uid = (int) ($b['user_id'] ?? 0);
                $amount = (float) ($b['amount'] ?? 0);
                if (!$uid || $amount <= 0) return ahura_fail('validation_error', 'user_id and amount are required.', 422);
                $fee = (float) ($b['fee'] ?? 0);
                $wpdb->insert($wpdb->prefix . 'sheyda_wallet_withdrawals', array(
                    'user_id' => $uid, 'amount_requested' => (string) $amount, 'fee' => (string) $fee,
                    'amount_net' => (string) ($amount - $fee),
                    'status' => in_array($b['status'] ?? '', array('pending','approved','paid','rejected'), true) ? $b['status'] : 'pending',
                    'bank_info' => json_encode($b['bank_info'] ?? array(), JSON_UNESCAPED_UNICODE),
                    'admin_notes' => sanitize_textarea_field($b['admin_notes'] ?? ''),
                ));
                return ahura_ok(array('withdrawal_id' => (int) $wpdb->insert_id), 201);
            },
            'permission_callback' => 'ahura_permission',
        ),
    ));

    /* ---------------- chat / otp / sms / wishlist ---------------- */

    register_rest_route(AHURA_NS, '/chats', array(
        array(
            'methods'  => 'GET',
            'callback' => function ($req) {
                global $wpdb;
                return ahura_ok($wpdb->get_results(
                    "SELECT * FROM {$wpdb->prefix}drplus_chat_sessions ORDER BY id DESC LIMIT 100", ARRAY_A));
            },
            'permission_callback' => 'ahura_permission',
        ),
        array(
            'methods'  => 'POST',
            'callback' => function ($req) {
                global $wpdb;
                $b = $req->get_json_params();
                $u1 = (int) ($b['user_1_id'] ?? 0); $u2 = (int) ($b['user_2_id'] ?? 0);
                if (!$u1 || !$u2) return ahura_fail('validation_error', 'user_1_id and user_2_id are required.', 422);
                $now = current_time('mysql');
                $wpdb->insert($wpdb->prefix . 'drplus_chat_sessions', array(
                    'user_1_id' => $u1, 'user_2_id' => $u2,
                    'context_id' => (int) ($b['context_id'] ?? 0),
                    'subject' => sanitize_text_field($b['subject'] ?? ''),
                    'is_closed' => 0, 'open_at' => $now, 'closed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ));
                return ahura_ok(array('chat_id' => (int) $wpdb->insert_id), 201);
            },
            'permission_callback' => 'ahura_permission',
        ),
    ));

    register_rest_route(AHURA_NS, '/otp', array(
        array(
            'methods'  => 'GET',
            'callback' => function ($req) {
                global $wpdb;
                return ahura_ok($wpdb->get_results(
                    "SELECT id, mobile, expire FROM {$wpdb->prefix}drplus_otp ORDER BY id DESC LIMIT 100", ARRAY_A));
            },
            'permission_callback' => 'ahura_permission',
        ),
        array(
            'methods'  => 'POST',
            'callback' => function ($req) {
                global $wpdb;
                $b = $req->get_json_params();
                $mobile = sanitize_text_field(ahura_fa_digits($b['mobile'] ?? ''));
                if (!$mobile) return ahura_fail('validation_error', 'mobile is required.', 422);
                $otp = random_int(10000, 99999);
                $wpdb->insert($wpdb->prefix . 'drplus_otp', array(
                    'mobile' => $mobile, 'otp' => $otp,
                    'expire' => gmdate('Y-m-d H:i:s', time() + 120),
                ));
                return ahura_ok(array('otp_id' => (int) $wpdb->insert_id, 'otp' => $otp), 201);
            },
            'permission_callback' => 'ahura_permission',
        ),
    ));

    register_rest_route(AHURA_NS, '/sms', array(
        'methods'  => 'GET',
        'callback' => function ($req) {
            global $wpdb;
            $page = max(1, (int) $req->get_param('page'));
            $per  = min(100, max(1, (int) ($req->get_param('per_page') ?: 20)));
            $off = ($page - 1) * $per;
            return ahura_ok($wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}woocommerce_ir_sms_archive ORDER BY ID DESC LIMIT $per OFFSET $off", ARRAY_A));
        },
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/wishlist', array(
        array(
            'methods'  => 'GET',
            'callback' => function ($req) {
                global $wpdb;
                return ahura_ok($wpdb->get_results(
                    "SELECT * FROM {$wpdb->prefix}drplus_wishlist ORDER BY id DESC LIMIT 200", ARRAY_A));
            },
            'permission_callback' => 'ahura_permission',
        ),
        array(
            'methods'  => 'POST',
            'callback' => function ($req) {
                global $wpdb;
                $b = $req->get_json_params();
                $pid = (int) ($b['product_id'] ?? 0); $uid = (int) ($b['user_id'] ?? 0);
                if (!$pid || !$uid) return ahura_fail('validation_error', 'product_id and user_id are required.', 422);
                $exists = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}drplus_wishlist WHERE product_id=%d AND user_id=%d", $pid, $uid));
                if ($exists) return ahura_ok(array('wishlist_id' => $exists, 'created' => false), 200);
                $wpdb->insert($wpdb->prefix . 'drplus_wishlist', array('product_id' => $pid, 'user_id' => $uid));
                return ahura_ok(array('wishlist_id' => (int) $wpdb->insert_id, 'created' => true), 201);
            },
            'permission_callback' => 'ahura_permission',
        ),
    ));

    /* ---------------- elementor / menus / attachments ---------------- */

    register_rest_route(AHURA_NS, '/elementor', array(
        'methods'  => 'GET',
        'callback' => function ($req) {
            global $wpdb;
            $type = sanitize_key((string) ($req->get_param('type') ?: 'wp_page'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT p.ID, p.post_title, p.post_status, p.post_type
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
                 WHERE p.post_type = %s AND p.post_status IN ('publish','draft') LIMIT 50", $type), ARRAY_A);
            foreach ($rows as $i => $r) {
                $rows[$i]['elementor_json'] = get_post_meta((int) $r['ID'], '_elementor_data', true);
            }
            return ahura_ok($rows);
        },
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/menus', array(
        'methods'  => 'GET',
        'callback' => function ($req) {
            $out = array();
            foreach (wp_get_nav_menus() as $m) {
                $out[] = array('id' => $m->term_id, 'name' => $m->name,
                               'items' => wp_get_nav_menu_items($m->term_id));
            }
            return ahura_ok($out);
        },
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/attachments', array(
        'methods'  => 'GET',
        'callback' => function ($req) {
            $q = new WP_Query(array(
                'post_type' => 'attachment', 'post_status' => 'inherit',
                'posts_per_page' => min(100, max(1, (int) ($req->get_param('per_page') ?: 20))),
                'paged' => max(1, (int) $req->get_param('page')),
            ));
            $out = array();
            foreach ($q->posts as $a) {
                $out[] = array('id' => $a->ID, 'title' => $a->post_title,
                               'url' => wp_get_attachment_url($a->ID),
                               'mime' => $a->post_mime_type, 'date' => $a->post_date);
            }
            return ahura_ok($out);
        },
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- options & schema introspection ---------------- */

    register_rest_route(AHURA_NS, '/options', array(
        'methods'  => 'GET',
        'callback' => function ($req) {
            global $wpdb;
            $name = (string) $req->get_param('name');
            if ($name !== '') {
                return ahura_ok(array('name' => $name, 'value' => get_option($name)));
            }
            $like = sanitize_text_field((string) ($req->get_param('search') ?: ''));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT option_name, LENGTH(option_value) AS value_len, autoload
                 FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 200",
                '%' . $wpdb->esc_like($like) . '%'), ARRAY_A);
            return ahura_ok($rows);
        },
        'permission_callback' => 'ahura_permission',
    ));

    register_rest_route(AHURA_NS, '/schema', array(
        'methods'  => 'GET',
        'callback' => function () {
            global $wpdb;
            $tables = $wpdb->get_col('SHOW TABLES');
            $out = array();
            foreach ($tables as $t) {
                $cols = $wpdb->get_results("SHOW COLUMNS FROM `{$t}`", ARRAY_A);
                $out[$t] = array_map(function ($c) {
                    return array('field' => $c['Field'], 'type' => $c['Type'], 'null' => $c['Null'], 'key' => $c['Key']);
                }, $cols);
            }
            return ahura_ok($out);
        },
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- raw SQL (read-only guard) ---------------- */

    register_rest_route(AHURA_NS, '/sql', array(
        'methods'  => 'POST',
        'callback' => function ($req) {
            global $wpdb;
            $b = $req->get_json_params();
            $sql = trim((string) ($b['query'] ?? ''));
            if ($sql === '') return ahura_fail('validation_error', 'query is required.', 422);
            $head = strtoupper(substr(ltrim($sql), 0, 7));
            if (strpos($head, 'SELECT') !== 0 && strpos($head, 'SHOW') !== 0 && strpos($head, 'DESCRIB') !== 0) {
                return ahura_fail('forbidden_sql',
                    'Only SELECT/SHOW/DESCRIBE allowed here; use entity endpoints for writes.', 403);
            }
            $rows = $wpdb->get_results($sql, ARRAY_A);
            if ($rows === null && $wpdb->last_error) {
                return ahura_fail('sql_error', $wpdb->last_error, 400);
            }
            return ahura_ok(array('rows' => $rows, 'count' => is_array($rows) ? count($rows) : 0));
        },
        'permission_callback' => 'ahura_permission',
    ));

    /* ---------------- site info ---------------- */

    register_rest_route(AHURA_NS, '/site', array(
        'methods'  => 'GET',
        'callback' => function () {
            $theme = wp_get_theme();
            return ahura_ok(array(
                'name' => get_bloginfo('name'),
                'url' => home_url(),
                'wp' => get_bloginfo('version'),
                'php' => PHP_VERSION,
                'theme' => $theme->get('Name') . ' ' . $theme->get('Version'),
                'active_plugins' => get_option('active_plugins'),
                'timezone' => wp_timezone()->getName(),
                'language' => get_bloginfo('language'),
            ));
        },
        'permission_callback' => 'ahura_permission',
    ));
}
