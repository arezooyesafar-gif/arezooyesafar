<?php
/**
 * Child theme overrides and enhancements:
 * 1. Single specialist stats & comment boxes adjustments
 * 2. Top-rated deterministic ordering (no randomness)
 * 3. Dedicated specialist categories (Doctors, Traditional Medicine, Dentists, Vets, Psychologists, Occupational Therapy)
 * 4. Custom rewrite rules & query routing for the dedicated sections
 * 5. 301 Redirect from generic /specialists/ to /doctors/
 * 6. Doctor Avatar Integration (CDN WebP / Nobat / local fallbacks)
 */

// 1. Empty the stats array -> template renders nothing
add_filter( 'drplus/specialist/single/stats', '__return_empty_array', 999 );

// Specialist profiles take no reviews. The parent theme already wraps the whole section in
// comments_open() (templates/specialists/single/template-specialists-single-reviews.php), so
// closing them drops the heading, the list and the form, and rejects POSTs to wp-comments-post.php.
add_filter( 'comments_open', 'ahura_specialist_comments_closed', 10, 2 );
function ahura_specialist_comments_closed( $open, $post_id ) {
    return 'specialist' === get_post_type( $post_id ) ? false : $open;
}

// 2. Never render the wait/reason/recommend chips under comments
remove_filter( 'comment_text', 'ahura_comment_meta_boxes', 20 );

// 3. Specialist results ordering: Top-rated to least-rated (deterministic, no randomness)
// (Removed posts_clauses filter in favor of cached queries)
function ahura_specialist_top_rated_ordering( $clauses, $query ) {
    if ( is_admin() ) return $clauses;
    if ( $query->is_singular() || $query->get( 'p' ) || $query->get( 'name' ) || $query->get( 'attachment_id' ) ) {
        return $clauses;
    }

    $pt = $query->get( 'post_type' );
    $pts = is_array( $pt ) ? $pt : array( $pt );
    $is_spec = in_array( 'specialist', $pts, true ) || $query->is_post_type_archive( 'specialist' );
    if ( ! $is_spec ) return $clauses;

    global $wpdb;
    if ( strpos( $clauses['join'], 'ahura_pm_sort' ) === false ) {
        $clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} AS ahura_pm_sort ON ({$wpdb->posts}.ID = ahura_pm_sort.post_id AND ahura_pm_sort.meta_key = 'ahura_rating') ";
    }
    $clauses['orderby'] = " COALESCE(CAST(ahura_pm_sort.meta_value AS DECIMAL(4,2)), 0.00) DESC, {$wpdb->posts}.comment_count DESC, {$wpdb->posts}.ID ASC ";

    return $clauses;
}

// 4. Doctor Avatar Integration via pre_get_avatar_data filter
add_filter( 'pre_get_avatar_data', 'ahura_custom_avatar_data', 99, 2 );
function ahura_custom_avatar_data( $args, $id_or_email ) {
    $post_id = 0;
    if ( is_numeric( $id_or_email ) ) {
        $uid = (int) $id_or_email;
        if ( $uid > 1000000 ) {
            $post_id = $uid - 1000000;
        } else {
            static $uid_cache = array();
            if ( isset( $uid_cache[ $uid ] ) ) {
                $post_id = $uid_cache[ $uid ];
            } else {
                global $wpdb;
                $post_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}drplus_specialists WHERE user_id = %d LIMIT 1", $uid ) );
                $uid_cache[ $uid ] = $post_id;
            }
        }
    } elseif ( is_object( $id_or_email ) ) {
        if ( isset( $id_or_email->user_id ) ) {
            $uid = (int) $id_or_email->user_id;
            if ( $uid > 1000000 ) {
                $post_id = $uid - 1000000;
            }
        } elseif ( isset( $id_or_email->post_id ) ) {
            $post_id = (int) $id_or_email->post_id;
        } elseif ( isset( $id_or_email->ID ) && get_post_type( $id_or_email->ID ) === 'specialist' ) {
            $post_id = (int) $id_or_email->ID;
        }
    }

    if ( $post_id > 0 ) {
        $url = get_post_meta( $post_id, 'ahura_avatar_url', true );
        if ( ! empty( $url ) ) {
            $args['url'] = $url;
            return $args;
        }
    }

    return $args;
}

// 5. Rewrite Rules & Query Var for Dedicated Sections
add_filter( 'query_vars', 'ahura_register_section_query_vars' );
function ahura_register_section_query_vars( $vars ) {
    $vars[] = 'specialist_section';
    return $vars;
}

add_action( 'init', 'ahura_register_section_rewrites' );
function ahura_register_section_rewrites() {
    add_rewrite_tag( '%specialist_section%', '([^&]+)' );

    // Doctors (/doctors/ and /doctors/page/2/)
    add_rewrite_rule( '^doctors/page/([0-9]+)/?$', 'index.php?post_type=specialist&specialist_section=doctors&paged=$matches[1]', 'top' );
    add_rewrite_rule( '^doctors/?$', 'index.php?post_type=specialist&specialist_section=doctors', 'top' );

    // Veterinary (/veterinary/ and /vets/)
    add_rewrite_rule( '^veterinary/page/([0-9]+)/?$', 'index.php?post_type=specialist&specialist_section=veterinary&paged=$matches[1]', 'top' );
    add_rewrite_rule( '^veterinary/?$', 'index.php?post_type=specialist&specialist_section=veterinary', 'top' );
    add_rewrite_rule( '^vets/page/([0-9]+)/?$', 'index.php?post_type=specialist&specialist_section=veterinary&paged=$matches[1]', 'top' );
    add_rewrite_rule( '^vets/?$', 'index.php?post_type=specialist&specialist_section=veterinary', 'top' );

    // Traditional medicine (/traditional-medicine/ and /traditional/)
    add_rewrite_rule( '^traditional-medicine/page/([0-9]+)/?$', 'index.php?post_type=specialist&specialist_section=traditional_medicine&paged=$matches[1]', 'top' );
    add_rewrite_rule( '^traditional-medicine/?$', 'index.php?post_type=specialist&specialist_section=traditional_medicine', 'top' );
    add_rewrite_rule( '^traditional/page/([0-9]+)/?$', 'index.php?post_type=specialist&specialist_section=traditional_medicine&paged=$matches[1]', 'top' );
    add_rewrite_rule( '^traditional/?$', 'index.php?post_type=specialist&specialist_section=traditional_medicine', 'top' );

    // Dentists (/dentists/)
    add_rewrite_rule( '^dentists/page/([0-9]+)/?$', 'index.php?post_type=specialist&specialist_section=dentists&paged=$matches[1]', 'top' );
    add_rewrite_rule( '^dentists/?$', 'index.php?post_type=specialist&specialist_section=dentists', 'top' );

    // Psychologists (/psychologists/)
    add_rewrite_rule( '^psychologists/page/([0-9]+)/?$', 'index.php?post_type=specialist&specialist_section=psychologists&paged=$matches[1]', 'top' );
    add_rewrite_rule( '^psychologists/?$', 'index.php?post_type=specialist&specialist_section=psychologists', 'top' );

    // Occupational therapy (/occupational-therapy/)
    add_rewrite_rule( '^occupational-therapy/page/([0-9]+)/?$', 'index.php?post_type=specialist&specialist_section=occupational_therapy&paged=$matches[1]', 'top' );
    add_rewrite_rule( '^occupational-therapy/?$', 'index.php?post_type=specialist&specialist_section=occupational_therapy', 'top' );

    // Unified search (/search/ and paginated results)
    add_rewrite_rule( '^search/page/([0-9]+)/?$', 'index.php?post_type=specialist&specialist_section=search&paged=$matches[1]', 'top' );
    add_rewrite_rule( '^search/?$', 'index.php?post_type=specialist&specialist_section=search', 'top' );

    // Single specialist profiles use the public /specialist/{slug}/ URL.
    add_rewrite_rule( '^specialist/([^/]+)/?$', 'index.php?post_type=specialist&name=$matches[1]', 'top' );

}

// Keep directory and profile URLs working even when a host has stale rewrite rules.
add_filter( 'request', function( $vars ) {
    $path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
    $sections = array(
        'doctors' => 'doctors',
        'search' => 'search',
        'veterinary' => 'veterinary',
        'vets' => 'veterinary',
        'traditional-medicine' => 'traditional_medicine',
        'traditional' => 'traditional_medicine',
        'dentists' => 'dentists',
        'psychologists' => 'psychologists',
        'occupational-therapy' => 'occupational_therapy',
    );
    if ( preg_match( '#^([^/]+)(?:/page/([0-9]+))?$#', $path, $match ) && isset( $sections[ $match[1] ] ) ) {
        $vars['post_type'] = 'specialist';
        $vars['specialist_section'] = $sections[ $match[1] ];
        unset( $vars['pagename'], $vars['page_id'], $vars['name'], $vars['error'], $vars['attachment'] );
        if ( ! empty( $match[2] ) ) {
            $vars['paged'] = absint( $match[2] );
        }
    } elseif ( preg_match( '#^specialist/([^/]+)$#', $path, $match ) ) {
        $vars['post_type'] = 'specialist';
        $vars['name'] = sanitize_title( rawurldecode( $match[1] ) );
        unset( $vars['pagename'], $vars['page_id'], $vars['error'], $vars['attachment'], $vars['specialist_section'] );
    }
    return $vars;
}, 1 );

// Refresh WordPress' cached route table once after these rules change.
add_action( 'init', function() {
    $version = '2026-09-24-visital-routes-v2';
    if ( get_option( 'ahura_visital_route_rules_version' ) === $version ) {
        return;
    }
    flush_rewrite_rules( false );
    update_option( 'ahura_visital_route_rules_version', $version, false );
}, 99 );

// Keep anonymous directory responses cacheable and compressed for a snappy app-like feel.
add_action( 'template_redirect', function() {
    if ( is_admin() || ! get_query_var( 'specialist_section' ) || is_user_logged_in() || ! empty( $_COOKIE ) ) {
        return;
    }
    if ( ! headers_sent() ) {
        header( 'Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=600' );
        header( 'Vary: Accept-Encoding' );
    }
    if ( function_exists( 'ob_gzhandler' ) && 0 === ob_get_level() && ! empty( $_SERVER['HTTP_ACCEPT_ENCODING'] ) && false !== stripos( $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' ) ) {
        ob_start( 'ob_gzhandler' );
    }
}, 0 );

// Search helpers: normalize common Persian/Arabic variants and tolerate small typos.
function ahura_normalize_search_text( $text, $compact = false ) {
    $text = strtolower( trim( wp_strip_all_tags( (string) $text ) ) );
    $text = strtr( $text, array( 'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه', 'ؤ' => 'و', 'إ' => 'ا', 'أ' => 'ا', 'ئ' => 'ی', 'ـ' => '' ) );
    $text = preg_replace( '/[\x{200C}\x{200D}\x{FEFF}]/u', '', $text );
    $text = preg_replace( '/\s+/u', ' ', $text );
    return $compact ? preg_replace( '/\s+/u', '', $text ) : $text;
}

function ahura_unicode_distance( $a, $b ) {
    $a = preg_split( '//u', ahura_normalize_search_text( $a, true ), -1, PREG_SPLIT_NO_EMPTY );
    $b = preg_split( '//u', ahura_normalize_search_text( $b, true ), -1, PREG_SPLIT_NO_EMPTY );
    $prev = range( 0, count( $b ) );
    foreach ( $a as $i => $ca ) {
        $curr = array( $i + 1 );
        foreach ( $b as $j => $cb ) {
            $curr[] = min( $curr[ $j ] + 1, $prev[ $j + 1 ] + 1, $prev[ $j ] + ( $ca === $cb ? 0 : 1 ) );
        }
        $prev = $curr;
    }
    return (int) end( $prev );
}

function ahura_search_variants( $text ) {
    global $wpdb;
    $base = ahura_normalize_search_text( $text );
    if ( '' === $base ) return array();
    $variants = array( $base );
    $compact = ahura_normalize_search_text( $base, true );
    $aliases = array(
        'دندونپزشکی' => 'دندانپزشکی', 'دندون پزشکی' => 'دندانپزشکی', 'دندان پزشک' => 'دندانپزشکی',
        'روانشناس' => 'روانشناسی', 'سايكولوژی' => 'روانشناسی', 'سایکولوژی' => 'روانشناسی',
        'کار درمانی' => 'کاردرمانی', 'کاردرمانگر' => 'کاردرمانی', 'دامپزشک' => 'دامپزشکی',
        'طب سنتي' => 'طب سنتی', 'طب سنتی' => 'طب سنتی', 'قلب وعروق' => 'قلب و عروق'
    );
    foreach ( $aliases as $from => $to ) {
        if ( false !== strpos( $compact, ahura_normalize_search_text( $from, true ) ) ) {
            $variants[] = ahura_normalize_search_text( $to );
        }
    }
    // Parent chips use a friendly «متخصص …» label, while database specialty
    // titles are stored without that display prefix.
    if ( 0 === strpos( $base, 'متخصص ' ) ) {
        $variants[] = trim( substr( $base, strlen( 'متخصص ' ) ) );
    } elseif ( 0 === strpos( $base, 'فوق تخصص ' ) ) {
        $variants[] = trim( substr( $base, strlen( 'فوق تخصص ' ) ) );
    }

    // Match a one- or two-character misspelling against the small speciality table.
    static $speciality_titles = null;
    if ( null === $speciality_titles ) {
        $speciality_titles = $wpdb->get_col( "SELECT post_title FROM {$wpdb->posts} WHERE post_type = 'speciality' AND post_status = 'publish' LIMIT 1500" );
    }
    $input_len = count( preg_split( '//u', $compact, -1, PREG_SPLIT_NO_EMPTY ) );
    if ( $input_len >= 5 && $input_len <= 40 && ! empty( $speciality_titles ) ) {
        foreach ( $speciality_titles as $candidate ) {
            $candidate_normalized = ahura_normalize_search_text( $candidate );
            $candidate_compact = ahura_normalize_search_text( $candidate_normalized, true );
            if ( '' === $candidate_compact || abs( $input_len - count( preg_split( '//u', $candidate_compact, -1, PREG_SPLIT_NO_EMPTY ) ) ) > 2 ) continue;
            $distance = ahura_unicode_distance( $compact, $candidate_compact );
            if ( $distance > 0 && $distance <= ( $input_len > 8 ? 2 : 1 ) ) {
                $variants[] = $candidate_normalized;
                break;
            }
        }
    }
    return array_values( array_unique( array_filter( $variants ) ) );
}

// 6. Filter Specialist Sections in WP_Query (Using native taxonomy - zero memory limits)
add_action( 'pre_get_posts', 'ahura_filter_specialist_sections', 20 );
function ahura_filter_specialist_sections( $query ) {
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    $sec = $query->get( 'specialist_section' );
    if ( ! $sec ) {
        return;
    }

    $query->set( 'post_type', 'specialist' );
    $query->is_post_type_archive = true;
    $query->is_archive = true;
    $query->is_home = false;

    // Handle our ultra-fast query caching
    global $wpdb;
    
    $term_id = 0;
    if ( 'doctors' === $sec ) $term_id = 305;
    elseif ( 'veterinary' === $sec ) $term_id = 224;
    elseif ( 'dentists' === $sec ) $term_id = 200;
    elseif ( 'psychologists' === $sec ) $term_id = 178;
    elseif ( 'traditional_medicine' === $sec ) $term_id = 193;
    // Occupational therapists are stored in the specialist relation table;
    // hospital_category term 205 exists but currently has no relationships.
    
    if ( !$term_id && ! in_array( $sec, array( 'occupational_therapy', 'search' ), true ) ) return;

    $city = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
    if ( !$city && is_tax( 'location' ) ) {
        $term = get_queried_object();
        if ( $term && !is_wp_error( $term ) ) $city = $term->slug;
    }
    
    $specialities = [];
    if ( !empty($_GET['specialities']) ) {
        $specialities = is_array($_GET['specialities']) ? $_GET['specialities'] : [$_GET['specialities']];
        $specialities = array_map('absint', $specialities);
        sort($specialities);
    }
    
    $search_cache_text = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
    $speciality_cache_text = isset( $_GET['subspeciality'] ) ? sanitize_text_field( wp_unslash( $_GET['subspeciality'] ) ) : ( isset( $_GET['speciality'] ) ? sanitize_text_field( wp_unslash( $_GET['speciality'] ) ) : '' );
    $cache_key = 'visital_sp_ids_v11_declared_speciality_' . md5($sec . '_' . $city . '_' . implode(',', $specialities) . '_' . $search_cache_text . '_' . $speciality_cache_text);
    $cached_ids = get_transient( $cache_key );
    $time_start = microtime(true);
    if ( false !== $cached_ids ) {
        header('X-Visital-Cache: HIT');
    } else {
        header('X-Visital-Cache: MISS');
    }
    
    if ( false === $cached_ids ) {
        $join = " JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id ";
        $join .= " JOIN {$wpdb->term_taxonomy} tt ON (tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'hospital_category' AND tt.term_id = %d) ";
        $args = [ $term_id ];
        if ( 'search' === $sec ) {
            $join = '';
            $args = [];
        } elseif ( 'occupational_therapy' === $sec ) {
            $join = " JOIN {$wpdb->prefix}drplus_specialists sp ON p.ID = sp.post_id AND sp.subtitle IN ('کار درمانی','کاردرمانی') ";
            $args = [];
        } elseif ( 'doctors' === $sec ) {
            // Keep the general doctor directory free of the dedicated service sections.
            // Exclusion is applied with NOT EXISTS below so multi-category profiles
            // cannot leak back in through a non-excluded relationship row.
        }
        
        if ( $city ) {
            $city_term = get_term_by( 'slug', $city, 'location' );
            if ( $city_term ) {
                $join .= " JOIN {$wpdb->term_relationships} tr_loc ON p.ID = tr_loc.object_id ";
                $join .= " JOIN {$wpdb->term_taxonomy} tt_loc ON (tr_loc.term_taxonomy_id = tt_loc.term_taxonomy_id AND tt_loc.taxonomy = 'location' AND tt_loc.term_id = %d) ";
                $args[] = $city_term->term_id;
            }
        }
        
        if ( !empty($specialities) ) {
            $join .= " JOIN {$wpdb->prefix}drplus_specialists sp ON p.ID = sp.post_id ";
            $placeholders = implode(',', array_fill(0, count($specialities), '%d'));
            $join .= " JOIN {$wpdb->posts} declared_spec ON declared_spec.ID IN ($placeholders) AND declared_spec.post_type = 'speciality' AND declared_spec.post_title = sp.subtitle ";
            $args = array_merge($args, $specialities);
        }

        $where_extra = '';
        $search_text = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        $speciality_text = $speciality_cache_text;
        if ( ! $search_text && $speciality_text ) {
            $search_text = $speciality_text;
        }
        if ( $search_text ) {
            $join .= " LEFT JOIN {$wpdb->prefix}drplus_specialists sp_search ON p.ID = sp_search.post_id ";
            // Search only the declared specialty. Relationship rows may be
            // broad source clusters and must never turn into false matches.
            $variants = ahura_search_variants( $search_text );
            $search_groups = array();
            foreach ( $variants as $variant ) {
                $needle = '%' . $wpdb->esc_like( $variant ) . '%';
                $search_groups[] = '(p.post_title LIKE %s OR sp_search.name LIKE %s OR sp_search.subtitle LIKE %s)';
                $args = array_merge( $args, array( $needle, $needle, $needle ) );
                $compact_needle = '%' . $wpdb->esc_like( ahura_normalize_search_text( $variant, true ) ) . '%';
                $normalized_title = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(p.post_title,'ي','ی'),'ى','ی'),'ك','ک'),'‌',''),' ','')";
                $normalized_name = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(sp_search.name,'ي','ی'),'ى','ی'),'ك','ک'),'‌',''),' ','')";
                $normalized_subtitle = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(sp_search.subtitle,'ي','ی'),'ى','ی'),'ك','ک'),'‌',''),' ','')";
                $search_groups[] = "($normalized_title LIKE %s OR $normalized_name LIKE %s OR $normalized_subtitle LIKE %s)";
                $args = array_merge( $args, array( $compact_needle, $compact_needle, $compact_needle ) );
            }
            if ( $variants ) {
                $where_extra .= ' AND (' . implode( ' OR ', $search_groups ) . ')';
            }
        }
        
        // Keep profiles with a real picture first. The directory's avatar
        // resolver reads this same field, so ordering here matches what users see.
        $join .= " LEFT JOIN {$wpdb->postmeta} avatar_sort ON (p.ID = avatar_sort.post_id AND avatar_sort.meta_key = 'ahura_avatar_url' AND avatar_sort.meta_value <> '') ";
        // A medical licence is a stronger trust signal than recency. Put
        // licence-confirmed profiles first, then preserve the photo-first
        // behavior requested for the remaining ordering.
        $join .= " LEFT JOIN {$wpdb->prefix}drplus_specialists licence_sp ON licence_sp.post_id = p.ID ";
        $join .= " LEFT JOIN {$wpdb->usermeta} licence_code ON (licence_code.user_id = licence_sp.user_id AND licence_code.meta_key = 'specialist_code' AND licence_code.meta_value <> '') ";
        $sql = "
            SELECT p.ID 
            FROM {$wpdb->posts} p
            $join
            WHERE p.post_type = 'specialist' AND p.post_status = 'publish'
              " . ( 'doctors' === $sec ? "AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->term_relationships} tr_ex
                    JOIN {$wpdb->term_taxonomy} tt_ex ON tr_ex.term_taxonomy_id = tt_ex.term_taxonomy_id
                    WHERE tr_ex.object_id = p.ID AND tt_ex.taxonomy = 'hospital_category'
                      AND tt_ex.term_id IN (178,193,200,205,224)
                )" : '' ) . " $where_extra
            GROUP BY p.ID
            ORDER BY CASE WHEN COUNT(licence_code.umeta_id) > 0 THEN 0 ELSE 1 END ASC,
                     CASE WHEN COUNT(avatar_sort.meta_id) > 0 THEN 0 ELSE 1 END ASC,
                     p.comment_count DESC, p.ID ASC
        ";
        
        $sql = $wpdb->prepare( $sql, ...$args );
        
        // Use get_col to fetch just the array of IDs
        $cached_ids = $wpdb->get_col( $sql );
        if ( !is_array($cached_ids) ) $cached_ids = [];
        
        // Cache for 1 hour
        set_transient( $cache_key, $cached_ids, HOUR_IN_SECONDS );
    }
    
    $per_page = (int) get_option( 'posts_per_page', 12 );
    $query->set( 'posts_per_page', $per_page );
    $paged = (int) $query->get( 'paged' );
    if ( !$paged ) $paged = 1;
    
    $offset = ($paged - 1) * $per_page;
    $page_ids = array_slice( $cached_ids, $offset, $per_page );
    
    if ( empty($page_ids) ) {
        $page_ids = [0]; // Force no results
    }
    
    $query->set( 'post__in', $page_ids );
    $query->set( 'orderby', 'post__in' );
    
    // Completely remove tax_query and meta_query so WP doesn't do extra joins
    $query->set( 'tax_query', [] );
    $query->set( 'meta_query', [] );
    
    // Keep found_posts enabled so WordPress pagination can use our replacement
    // count. post_limits below removes the second offset after array slicing.
    $query->set( 'no_found_rows', false );
            
    
    // Pass the total count for pagination
    $query->set( 'visital_total_count', count($cached_ids) );
    header('X-Visital-Time: ' . (microtime(true) - $time_start));
}

add_filter( 'found_posts', function( $found_posts, $query ) {
    if ( $query->get( 'visital_total_count' ) !== '' && $query->get( 'visital_total_count' ) !== null ) {
        return (int) $query->get( 'visital_total_count' );
    }
    return $found_posts;
}, 10, 2 );


// 7. Route section requests to archive-specialist.php template
add_filter( 'template_include', 'ahura_section_template_loader', 99 );
function ahura_section_template_loader( $template ) {
    $sec = get_query_var( 'specialist_section' );
    if ( $sec ) {
        $archive_template = locate_template( array( 'archive-specialist-directory.php', 'archive-specialist.php' ) );
        if ( $archive_template ) {
            return $archive_template;
        }
    }
    return $template;
}

// 8. Custom Titles for Dedicated Sections (Header, Breadcrumbs, HTML Title)
function ahura_custom_section_title( $title ) {
    $sec = get_query_var( 'specialist_section' );
    if ( 'doctors' === $sec ) {
        return 'متخصص‌ها';
    } elseif ( 'veterinary' === $sec ) {
        return 'دامپزشکی';
    } elseif ( 'dentists' === $sec ) {
        return 'دندان‌پزشکان';
    } elseif ( 'psychologists' === $sec ) {
        return 'روانشناسان';
    } elseif ( 'traditional_medicine' === $sec ) {
        return 'پزشکان طب سنتی';
    } elseif ( 'occupational_therapy' === $sec ) {
        return 'کاردرمانی';
    } elseif ( 'search' === $sec ) {
        return 'جستجوی پزشک و متخصص';
    }
    return $title;
}
add_filter( 'get_the_archive_title', 'ahura_custom_section_title', 99 );
add_filter( 'drplus/page/title', 'ahura_custom_section_title', 99 );

add_filter( 'document_title_parts', function( $parts ) {
    $sec = get_query_var( 'specialist_section' );
    if ( 'doctors' === $sec ) {
        $parts['title'] = 'متخصص‌ها';
    } elseif ( 'veterinary' === $sec ) {
        $parts['title'] = 'دامپزشکی';
    } elseif ( 'dentists' === $sec ) {
        $parts['title'] = 'دندان‌پزشکان';
    } elseif ( 'psychologists' === $sec ) {
        $parts['title'] = 'روانشناسان';
    } elseif ( 'traditional_medicine' === $sec ) {
        $parts['title'] = 'پزشکان طب سنتی';
    } elseif ( 'occupational_therapy' === $sec ) {
        $parts['title'] = 'کاردرمانی';
    } elseif ( 'search' === $sec ) {
        $parts['title'] = 'جستجوی پزشک و متخصص';
    }
    return $parts;
}, 99 );

// 9. Update Sidebar Widget Title on /doctors/
add_filter( 'widget_title', function( $title, $instance = null, $id_base = null ) {
    if ( get_query_var( 'specialist_section' ) === 'doctors' ) {
        if ( false !== strpos( $title, 'متخصص' ) ) {
            return str_replace( 'متخصص', 'پزشک', $title );
        }
    }
    return $title;
}, 10, 3 );

// 10. 301 Redirect: Hide /specialists/ archive and redirect to /doctors/
add_action( 'template_redirect', 'ahura_redirect_specialists_archive', 1 );
function ahura_redirect_specialists_archive() {
    if ( is_admin() ) {
        return;
    }

    $req_path = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
    $req_path = trim( (string) $req_path, '/' );

    if ( $req_path === 'specialists' || 0 === strpos( $req_path, 'specialists/page/' ) ) {
        if ( preg_match( '#^specialists/page/([0-9]+)#', $req_path, $m ) ) {
            wp_redirect( home_url( "/doctors/page/{$m[1]}/" ), 301 );
        } else {
            wp_redirect( home_url( '/doctors/' ), 301 );
        }
        exit;
    }

    if ( is_post_type_archive( 'specialist' ) && ! get_query_var( 'specialist_section' ) ) {
        $paged = (int) get_query_var( 'paged' );
        if ( $paged > 1 ) {
            wp_redirect( home_url( "/doctors/page/{$paged}/" ), 301 );
        } else {
            wp_redirect( home_url( '/doctors/' ), 301 );
        }
        exit;
    }
}

// 11. Navigation Menu Updates & Synchronization
add_action( 'init', 'ahura_sync_nav_menus_once' );
function ahura_sync_nav_menus_once() {
    if ( ! get_option( 'ahura_menu_synced_v6' ) ) {
        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->posts} SET post_title = 'متخصص‌ها' WHERE ID IN (712, 1019)" );
        $wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value = 'https://visital.ir/doctors/' WHERE post_id IN (712, 1019) AND meta_key = '_menu_item_url'" );
        $wpdb->query( "UPDATE {$wpdb->posts} SET post_title = 'دندان‌پزشکان' WHERE ID = 1020" );
        $wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value = 'https://visital.ir/dentists/' WHERE post_id = 1020 AND meta_key = '_menu_item_url'" );
        update_option( 'ahura_menu_synced_v6', 1 );
    }
}

// Ensure all nav menu objects render correct URLs and titles on the frontend
add_filter( 'wp_nav_menu_objects', 'ahura_filter_nav_menu_objects', 10, 2 );
function ahura_filter_nav_menu_objects( $items, $args ) {
    foreach ( $items as &$item ) {
        if ( false !== strpos( $item->url, '/specialists' ) ) {
            $item->url = home_url( '/doctors/' );
            if ( in_array( $item->title, array( 'متخصص‌ها', 'پزشکان', 'بیمارستان ها', 'متخصصان', 'متخصص‌ها و پزشک‌های عمومی' ), true ) ) {
                $item->title = 'متخصص‌ها';
            }
        } elseif ( in_array( $item->title, array( 'دندانپزشکی', 'دندانپزشکان', 'دندان‌پزشکان' ), true ) ) {
            $item->title = 'دندان‌پزشکان';
        }
    }
    return $items;
}

// Add a compact, clearly separated services group to the main navigation.
// Keep it generated at render time so menu editor changes remain intact.
add_filter( 'wp_nav_menu_items', 'ahura_add_service_menu_group', 20, 2 );
function ahura_add_service_menu_group( $items, $args ) {
    if ( empty( $args->theme_location ) || 'main-menu' !== $args->theme_location ) {
        return $items;
    }
    $links = array(
        array( 'متخصص‌ها', '/doctors/' ),
        array( 'دندان‌پزشکان', '/dentists/' ),
        array( 'روانشناسی', '/psychologists/' ),
        array( 'کاردرمانی', '/occupational-therapy/' ),
        array( 'دامپزشکی', '/veterinary/' ),
        array( 'طب سنتی', '/traditional-medicine/' ),
    );
    $children = '';
    foreach ( $links as $link ) {
        $children .= sprintf(
            '<li class="menu-item visital-service-item"><a href="%s">%s</a></li>',
            esc_url( home_url( $link[1] ) ),
            esc_html( $link[0] )
        );
    }
    return $items . '<li class="menu-item menu-item-has-children visital-services-menu"><a href="' . esc_url( home_url( '/doctors/' ) ) . '">خدمات درمانی</a><ul class="sub-menu">' . $children . '</ul></li>';
}


// Fix pagination when no_found_rows is true
add_filter( 'found_posts', 'ahura_fix_pagination_count', 10, 2 );
function ahura_fix_pagination_count( $found_posts, $query ) {
    if ( $query->get( 'my_custom_found_posts' ) ) {
        return $query->get( 'my_custom_found_posts' );
    }
    return $found_posts;
}



// Fix LIMIT offset when we already sliced post__in
add_filter( 'post_limits', function( $limits, $query ) {
    if ( $query->get( 'visital_total_count' ) !== '' && $query->get( 'visital_total_count' ) !== null ) {
        $per_page = (int) $query->get( 'posts_per_page' );
        if ( $per_page > 0 ) {
            return "LIMIT 0, $per_page";
        }
    }
    return $limits;
}, 10, 2 );

// Replace the legacy Elementor homepage search behavior with the new directory search.
add_action( 'template_redirect', function() {
    $request_path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
    $legacy_specialist_search = isset( $_GET['section'] ) && 'specialist' === sanitize_key( wp_unslash( $_GET['section'] ) );
    if ( $legacy_specialist_search ) {
        $args = array();
        if ( isset( $_GET['s'] ) && '' !== trim( (string) $_GET['s'] ) ) {
            $args['q'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
        }
        if ( ! empty( $_GET['city'] ) ) {
            $args['city'] = sanitize_key( wp_unslash( $_GET['city'] ) );
        }
        wp_safe_redirect( add_query_arg( $args, home_url( '/search/' ) ), 301 );
        exit;
    }
    if ( ( is_front_page() || is_home() || '' === $request_path ) && isset( $_GET['s'] ) && '' !== trim( (string) $_GET['s'] ) ) {
        $args = array( 'q' => sanitize_text_field( wp_unslash( $_GET['s'] ) ) );
        if ( ! empty( $_GET['city'] ) ) {
            $args['city'] = sanitize_key( wp_unslash( $_GET['city'] ) );
        }
        wp_safe_redirect( add_query_arg( $args, home_url( '/search/' ) ), 302 );
        exit;
    }
}, -1 );

// Critical homepage polish is kept inline so cached theme styles cannot show
// the retired search shell or override the mobile clinic readability fixes.
add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    $transparent_logo = home_url( '/wp-content/uploads/visital-assets/visital-logo-transparent.png' );
    ?>
    <style id="visital-home-polish">
      .home img[src*="IMG_7183"]{content:url("<?php echo esc_url( $transparent_logo ); ?>")!important}
      .home .elementor-widget-drplus_search,.home .elementor-element-d212f23,.home .elementor-element-6750114{display:none!important}
      .home .elementor-element-ea8e6f4{background:transparent!important}
      .home .elementor-element-ea8e6f4 .elementor-element-1bcc369{align-items:center;gap:1rem!important}
      .home .elementor-element-ea8e6f4 .elementor-element-f94ba1d .elementor-heading-title{color:#123d63!important;font-size:clamp(1.35rem,3vw,2rem)!important;font-weight:800!important;line-height:1.5!important}
      .home .elementor-element-ea8e6f4 .clinics,.home .elementor-element-ea8e6f4 .clinic{background:transparent!important}
      .home .elementor-element-ea8e6f4 .clinic-title{display:block!important;overflow:visible!important;white-space:normal!important;text-overflow:clip!important;-webkit-line-clamp:unset!important;color:#284f6b!important;line-height:1.7!important}
      .home .elementor-element-ea8e6f4 .clinic-icon-wrap,.home .elementor-element-ea8e6f4 .clinic-icon-wrap *,.home .elementor-element-ea8e6f4 .clinic-icon{color:#234d6a!important;opacity:.9!important}
      .home .elementor-element-ea8e6f4 .clinic-separator{background:transparent!important;border:0!important;box-shadow:none!important;color:#234d6a!important;opacity:.65!important}
      .home .elementor-element-ea8e6f4 .clinic-separator-white{background:transparent!important;opacity:.5!important}
      .home .elementor-element-ea8e6f4 .clinic-separator::before,.home .elementor-element-ea8e6f4 .clinic-separator-white::before{background:transparent!important;box-shadow:none!important}
      .home .elementor-element-ea8e6f4{box-sizing:border-box;grid-template-columns:minmax(0,1.55fr) minmax(260px,.85fr)!important;gap:clamp(1rem,3vw,2.5rem)!important;align-items:center!important;overflow:hidden!important;padding:clamp(1.25rem,3vw,2.25rem)!important}
      .home .elementor-element-ea8e6f4 .elementor-element-aeb7160,.home .elementor-element-ea8e6f4 .elementor-element-1bcc369{min-width:0!important;max-width:100%!important}
      .home .elementor-element-ea8e6f4 img{max-width:100%;height:auto;object-fit:contain}
      #footer-socials-wrap{gap:14px!important;align-items:start!important}
      #footer-org-items-wrap{box-sizing:border-box;width:82px!important;min-width:82px!important;height:126px!important;min-height:126px!important;margin:0 auto 10px!important;padding:0!important;display:flex!important;align-items:center!important;justify-content:center!important;overflow:hidden;border-radius:14px;background-position:center!important;background-size:76px auto!important;background-repeat:no-repeat!important}
      #footer-orgs-logo-section{display:none!important}
      #footer-social-section{gap:8px!important;margin-bottom:18px!important}
      @media(max-width:700px){.home .elementor-element-ea8e6f4{grid-template-columns:1fr!important;gap:1.25rem!important;padding:1.25rem .9rem!important}.home .elementor-element-ea8e6f4 .elementor-element-1bcc369{gap:.75rem!important}.home .elementor-element-ea8e6f4 .elementor-element-f94ba1d .elementor-heading-title{font-size:1.45rem!important}.home .elementor-element-ea8e6f4 .clinic-title{font-size:.83rem;line-height:1.8!important}.home .elementor-element-ea8e6f4 .clinic-icon{font-size:2.1rem}}
    </style>
    <?php
}, 100 );

// Site-wide theme surfaces and the compact light/dark brand mark.
add_action( 'wp_head', function() {
    $light_logo = home_url( '/wp-content/uploads/visital-assets/visital-theme-toggle-light.png' );
    $dark_logo  = home_url( '/wp-content/uploads/visital-assets/visital-theme-toggle-dark.png' );
    ?>
    <style id="visital-global-theme">
      #header_inner,.elementor-location-header{position:relative}
      .visital-header-mark{position:absolute;left:12px;top:50%;z-index:50;display:grid;width:42px;height:42px;padding:0;place-items:center;transform:translateY(-50%);border:0;border-radius:50%;background:transparent;line-height:0;cursor:pointer;-webkit-tap-highlight-color:transparent}
      .visital-header-mark img{display:block;width:100%;height:100%;object-fit:contain}
      .elementor-location-header .elementor-widget-theme-site-logo img{display:block;width:112px!important;max-width:100%;max-height:56px;height:auto;object-fit:contain}
      body.visital-dark .elementor-location-header,body.visital-dark .elementor-location-header .elementor-element,body.visital-dark .elementor-location-header .elementor-widget-wrap,body.visital-dark .elementor-location-header .e-con-inner{color:#e8f7f3!important;background-color:#0b202c!important;border-color:#1d4050!important}
      body.visital-dark .elementor-location-header a{color:#e8f7f3!important}
      body.visital-dark .elementor-location-header .elementor-widget-theme-site-logo img{filter:none!important;opacity:1!important}
      body.visital-dark .visital-header-mark{background:#102b39!important;box-shadow:0 2px 12px rgba(0,0,0,.16)}
      body.visital-dark,body.visital-dark #page,body.visital-dark #page-main,body.visital-dark .site-content,body.visital-dark .elementor,body.visital-dark .elementor-page{color:#e8f7f3!important;background:#07151f!important}
      body.visital-dark #header-container,body.visital-dark #header_inner,body.visital-dark .header-mobile-menu-wrap,body.visital-dark .main-menu,body.visital-dark .main-menu ul{color:#e8f7f3!important;background:#0b202c!important;border-color:#1d4050!important}
      body.visital-dark .header-logo img{filter:brightness(0) invert(1);opacity:.95}
      body.visital-dark a{color:#72e0bd}
      body.visital-dark .header-menu-wrap a,body.visital-dark .main-menu a,body.visital-dark .header-mobile-menu-wrap a{color:#d8f2ed!important}
      body.visital-dark .header-action-btn,body.visital-dark .header-cart-btn,body.visital-dark .header-account{background:#102b39!important;color:#72e0bd!important;border-color:#2a5362!important}
      body.visital-dark .header-header-action-btn{background:#55d6ad!important;color:#06251f!important;border-color:#55d6ad!important}
      body.visital-dark .drplus-breadcrumbs,body.visital-dark .breadcrumb-wrap,body.visital-dark .breadcrumb-item{color:#9fc4c5!important}
      body.visital-dark .specialist_article,body.visital-dark .specialist_main_section,body.visital-dark .specialist_sidebar,body.visital-dark .specialist_section,body.visital-dark .specialist_reviews,body.visital-dark .comments-area,body.visital-dark .comment-respond,body.visital-dark .specialist_office,body.visital-dark .specialist-card{background:#0d2531!important;color:#e8f7f3!important;border-color:#2a5362!important}
      body.visital-dark .specialist_article h1,body.visital-dark .specialist_article h2,body.visital-dark .specialist_article h3,body.visital-dark .specialist_article h4,body.visital-dark .specialist_article p,body.visital-dark .specialist_article span,body.visital-dark .specialist_article div,body.visital-dark .specialist_article li{color:#e8f7f3!important}
      body.visital-dark .specialist_article a{color:#72e0bd!important}
      body.visital-dark .specialist_article .section-title-title,body.visital-dark .specialist_article .comment-author-name,body.visital-dark .specialist_article .comment-text-wrap,body.visital-dark .specialist_article .comment-meta,body.visital-dark .specialist_article .specialist_office-address,body.visital-dark .specialist_article .specialist_office-name{color:#e8f7f3!important}
      body.visital-dark .specialist_article .specialist_code-wrap,body.visital-dark .specialist_article .visital-profile-chip-main,body.visital-dark .specialist_article .visital-profile-chip-fellowship{background:#102b39!important;border-color:#2a5362!important;color:#b9eee2!important}
      body.visital-dark .specialist_article input,body.visital-dark .specialist_article textarea,body.visital-dark .specialist_article select{background:#102b39!important;color:#e8f7f3!important;border-color:#2a5362!important}
      body.visital-dark .specialist_article .button-primary,body.visital-dark .specialist_article #specialist_booking-btn,body.visital-dark .specialist_article #specialist_consultation-btn{background:#55d6ad!important;color:#06251f!important;border-color:#55d6ad!important}
      body.visital-dark .specialist_article .drplus-comment-patient-score,body.visital-dark .specialist_article .specialist_verified-icon,body.visital-dark .specialist_article .specialist-is-verified-text{color:#72e0bd!important}
      @media (max-width:767px){
        body.home .elementor-element-acc40ad{min-height:0!important;padding:28px 20px 24px!important;border-radius:24px!important}
        body.home .elementor-element-acc40ad .elementor-element-7bee747{max-width:220px!important}
        body.home .elementor-element-acc40ad .elementor-element-7bee747 img{max-width:220px!important;height:auto!important}
        body.home .elementor-element-ea8e6f4 .clinics{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:12px!important;height:auto!important}
        body.home .elementor-element-ea8e6f4 .clinic-empty{display:none!important}
        body.home .elementor-element-ea8e6f4 .clinic{display:flex!important;min-height:118px!important;width:auto!important;margin:0!important;padding:14px 10px!important;border:1px solid #e5edf1!important;border-radius:22px!important;background:#fff!important;align-items:center!important;justify-content:center!important}
        body.home .elementor-element-ea8e6f4 .clinic-inner{display:flex!important;flex-direction:column!important;align-items:center!important;justify-content:center!important;gap:8px!important}
        body.home .elementor-element-ea8e6f4 .clinic-separator{display:none!important}
        body.home .elementor-element-ea8e6f4 .clinic-title{font-size:.85rem!important;text-align:center!important;line-height:1.7!important}
        body.visital-dark .home .elementor-element-ea8e6f4 .clinic{background:#102b39!important;border-color:#2a5362!important}
        #footer{padding-top:22px!important}
        #footer_content{display:flex!important;flex-direction:column!important;gap:26px!important;width:100%!important;max-width:none!important;padding:0 16px!important;box-sizing:border-box!important}
        #footer_about_us,#footer_info,#footer-socials-wrap{width:100%!important;max-width:none!important;min-width:0!important;min-height:0!important;grid-column:auto!important;align-items:center!important;text-align:center!important}
        #footer-socials-wrap{order:-1!important;display:flex!important;flex-direction:column!important;justify-content:flex-start!important;gap:12px!important;padding:0!important}
        #footer-socials-wrap .footer-section-title,#footer-socials-wrap .footer-section-title-text{display:block!important;visibility:visible!important;opacity:1!important;color:#eaf5f3!important;text-align:center!important}
        #footer-social-section{display:flex!important;flex-wrap:wrap!important;justify-content:center!important;align-items:center!important;gap:10px!important;margin:0!important}
        #footer-social-section .footer-social-item{display:flex!important;visibility:visible!important;opacity:1!important;width:56px!important;height:56px!important;align-items:center!important;justify-content:center!important;border-radius:16px!important}
        #footer-socials-wrap .footer-social>a{display:flex!important;visibility:visible!important;opacity:1!important;justify-content:center!important;margin-top:10px!important}
      }
      body.visital-dark h1,body.visital-dark h2,body.visital-dark h3,body.visital-dark h4,body.visital-dark h5,body.visital-dark h6,body.visital-dark p,body.visital-dark label,body.visital-dark li,body.visital-dark .elementor-heading-title,body.visital-dark .elementor-widget-text-editor{color:#e8f7f3!important}
      body.visital-dark input,body.visital-dark select,body.visital-dark textarea,body.visital-dark .drplus-custom-select,body.visital-dark button{color:#e8f7f3!important;background:#102b39!important;border-color:#2a5362!important}
      body.visital-dark input::placeholder,body.visital-dark textarea::placeholder{color:#9bb9bd!important}
      body.visital-dark .button-primary,body.visital-dark .drplus-search-button,body.visital-dark .specialist-btn{color:#06251f!important;background:#55d6ad!important;border-color:#55d6ad!important}
      body.visital-dark .elementor-section,body.visital-dark .elementor-container,body.visital-dark .elementor-column,body.visital-dark .elementor-widget-wrap{background-color:transparent!important}
      body.visital-dark .home .elementor-element-ea8e6f4,body.visital-dark .home .elementor-element-10a2989,body.visital-dark .home .elementor-element-10a2989 .proicon{background:#0d2531!important;border-color:#214453!important}
      body.visital-dark .home .drplus-proicon-group .proicon{color:#e8f7f3!important;background:#102b39!important;border-color:#2a5362!important;box-shadow:0 8px 18px rgba(0,0,0,.2)}
      body.visital-dark .home .drplus-proicon-group .proicon-title,body.visital-dark .home .elementor-element-ea8e6f4 .clinic-title{color:#e8f7f3!important}
      body.visital-dark .home .drplus-proicon-group .proicon-img-wrap{background:#173c48!important}
      body.visital-dark .home .drplus-proicon-group .proicon-icon,body.visital-dark .home .drplus-proicon-group .proicon-btn,body.visital-dark .home .elementor-element-ea8e6f4 .clinic-icon{color:#72e0bd!important}
      body.visital-dark .home .elementor-element-ea8e6f4 .elementor-heading-title{color:#e8f7f3!important}
      body.visital-dark .proicon,body.visital-dark a.proicon,body.visital-dark .proicon.slider-slide{color:#e8f7f3!important;background:#102b39!important;border-color:#2a5362!important;box-shadow:0 8px 18px rgba(0,0,0,.22)!important}
      body.visital-dark .proicon .proicon-title,body.visital-dark .proicon .proicon-text,body.visital-dark .proicon span,body.visital-dark .proicon i{color:#e8f7f3!important}
      body.visital-dark .proicon .proicon-img-wrap,body.visital-dark .proicon .proicon-icon-wrap{background:#173c48!important;border-color:#2a5362!important}
      body.visital-dark .clinic-popover,body.visital-dark .clinic-card,body.visital-dark .clinic-item{color:#e8f7f3!important;background:#102b39!important;border-color:#2a5362!important}
      body.visital-dark .header-popover,body.visital-dark .account-items,body.visital-dark .header-mini-cart-wrap{color:#e8f7f3!important;background:#102b39!important;border-color:#2a5362!important}
      body.visital-dark .header-popover a,body.visital-dark .account-items a{color:#e8f7f3!important}
      body.visital-dark #footer,body.visital-dark #footer-container,body.visital-dark #footer-socials-wrap,body.visital-dark #footer-social,body.visital-dark .footer-content{color:#d9eee9!important;background:#061827!important;border-color:#17394a!important}
      body.visital-dark .footer-section-title-text,body.visital-dark .footer-contact_info,body.visital-dark .footer-info,body.visital-dark .footer-content a{color:#d9eee9!important}
      body.visital-dark .visital-directory,body.visital-dark .visital-directory-results,body.visital-dark .visital-directory-categories{background:#07151f!important;color:#e8f7f3!important}
      body.visital-dark .visital-directory-hero{background:linear-gradient(135deg,#06151f,#0b3140)!important}
      body.visital-dark .visital-directory-form,body.visital-dark .visital-filter-details,body.visital-dark .visital-category-card,body.visital-dark .visital-directory-grid .specialist{background:#102b39!important;border-color:#2a5362!important;color:#e8f7f3!important}
      body.visital-dark .visital-search-primary{background:#0a202c!important;border-color:#2a5362!important}
      body.visital-dark .visital-search-primary input,body.visital-dark .visital-field input,body.visital-dark .visital-field select{background:transparent!important;color:#e8f7f3!important}
      body.visital-dark .visital-category-card strong,body.visital-dark .visital-directory-grid .specialist-name{color:#e8f7f3!important}
      body.visital-dark .visital-directory-grid .specialist-short_bio,body.visital-dark .visital-results-meta,body.visital-dark .visital-directory-lead{color:#a8c5c7!important}
      /* Keep nested review content, menus, and forms readable on every template. */
      body.visital-dark .specialist_main_section *,body.visital-dark .specialist_reviews *,body.visital-dark .specialist_sidebar *,body.visital-dark .comments-area *,body.visital-dark .comment-respond *,body.visital-dark .comment *,body.visital-dark [id^="div-comment-"] *,body.visital-dark [class*="review"] *{color:#e8f7f3!important}
      body.visital-dark .specialist_main_section a,body.visital-dark .specialist_reviews a,body.visital-dark .specialist_sidebar a,body.visital-dark .comments-area a,body.visital-dark .comment a,body.visital-dark [id^="div-comment-"] a{color:#72e0bd!important}
      body.visital-dark .specialist_main_section [class*="star"],body.visital-dark .specialist_reviews [class*="star"],body.visital-dark [class*="rating"] [class*="star"],body.visital-dark .specialist_main_section [aria-label*="ستاره"]{color:#72e0bd!important;fill:#72e0bd!important}
      body.visital-dark .main-menu ul.sub-menu,body.visital-dark .header-menu-wrap ul.sub-menu,body.visital-dark .header-mobile-menu-wrap ul.sub-menu{background:#102b39!important;border-color:#2a5362!important;box-shadow:0 16px 36px rgba(0,0,0,.28)!important}
      body.visital-dark .main-menu ul.sub-menu a,body.visital-dark .header-menu-wrap ul.sub-menu a,body.visital-dark .header-mobile-menu-wrap ul.sub-menu a{color:#e8f7f3!important}
      body.visital-dark .woocommerce,body.visital-dark .woocommerce-page,body.visital-dark .woocommerce .shop_table,body.visital-dark .woocommerce form,body.visital-dark .booking-form,body.visital-dark .map-popup-body{background:#0d2531!important;color:#e8f7f3!important;border-color:#2a5362!important}
      body.visital-dark .woocommerce th,body.visital-dark .woocommerce td,body.visital-dark .woocommerce label,body.visital-dark .woocommerce .amount{color:#e8f7f3!important;border-color:#2a5362!important}
      body.visital-dark .visital-dark-toggle{background:#102b39!important;color:#72e0bd!important;border-color:#2a5362!important}
      /* The homepage owns the .home class on <body>; include that form for icon contrast. */
      body.visital-dark.home .drplus-proicon-group .proicon-icon,body.visital-dark.home .drplus-proicon-group .proicon-btn,body.visital-dark.home .elementor-element-ea8e6f4 .clinic-icon{color:#72e0bd!important}
      body.visital-dark.home .drplus-proicon-group .proicon-title,body.visital-dark.home .elementor-element-ea8e6f4 .clinic-title{color:#e8f7f3!important}
      body.visital-dark.home .drplus-proicon-group .proicon-img-wrap{background:#173c48!important}
      body.visital-dark.home .elementor-element-ea8e6f4 .clinic{background:#102b39!important;border-color:#2a5362!important}
      @media(max-width:700px){.visital-header-mark{left:8px;width:38px;height:38px}.elementor-location-header .elementor-widget-theme-site-logo img{width:98px!important;max-height:48px}.header-logo{margin-inline:auto}.header-actions-wrap{margin-inline-start:52px}.header-header-action-btn{transform:translateX(24px)}}
    </style>
    <script>
    (function(){var dark=false;try{dark=window.localStorage.getItem('visital-dark-mode')==='1';}catch(e){}if(!dark){var m=document.cookie.match(/(?:^|; )visital-dark-mode=([^;]*)/);dark=!!m&&decodeURIComponent(m[1])==='1';}if(dark)document.documentElement.classList.add('visital-dark-preload');})();
    </script>
    <?php
}, 101 );

add_action( 'wp_footer', function() {
    if ( ! is_front_page() && ! is_home() ) {
        return;
    }
    $search_url = home_url( '/search/' );
    $transparent_logo = home_url( '/wp-content/uploads/visital-assets/visital-logo-transparent.png' );
    ?>
    <script>
    (function(){
        var searchUrl=<?php echo wp_json_encode( $search_url ); ?>;
        var transparentLogo=<?php echo wp_json_encode( $transparent_logo ); ?>;
        document.querySelectorAll('img[src*="IMG_7183"]').forEach(function(image){
            image.src=transparentLogo;
            image.removeAttribute('srcset');
            image.setAttribute('alt','ویزیتال');
        });
        // The homepage search widget is replaced by the dedicated /search/
        // experience. Remove the old visual block entirely so it cannot leave
        // a duplicate field or dark strip behind it.
        document.querySelectorAll('.elementor-widget-drplus_search, form.drplus-search-form').forEach(function(node){
            var widget=node.closest('.elementor-widget-drplus_search');
            if(widget){widget.remove();}else if(node.parentNode){node.remove();}
        });
        // Elementor keeps the old search widget inside a rounded shell; clear
        // that now-empty shell as well so the hero has no blank white strip.
        document.querySelectorAll('.elementor-element-d212f23,.elementor-element-6750114').forEach(function(shell){
            if(shell && shell.parentNode){shell.remove();}
        });
        document.querySelectorAll('form.drplus-search-form').forEach(function(form){
            var input=form.querySelector('.drplus-search-input');
            var city=form.querySelector('.drplus-search-city');
            var button=form.querySelector('.drplus-search-button');
            if(!input||!button)return;
            form.classList.add('visital-home-search');
            form.setAttribute('action',searchUrl);
            input.name='q';
            input.classList.remove('drplus-search-with-ajax');
            input.removeAttribute('data-nonce');
            if(city){city.classList.remove('drplus-custom-select');city.style.display='block';}
            button.type='submit';
            button.setAttribute('aria-label','جستجو');
            if(!button.querySelector('.visital-home-search-label')){
                var label=document.createElement('span');label.className='visital-home-search-label';label.textContent='جستجو';button.appendChild(label);
            }
            var go=function(event){
                if(event){event.preventDefault();event.stopImmediatePropagation();}
                var params=new URLSearchParams(),value=(input.value||'').trim();
                if(value)params.set('q',value);
                if(city&&city.value)params.set('city',city.value);
                window.location.href=searchUrl+(params.toString()?'?'+params.toString():'');
            };
            button.addEventListener('click',go,true);
            form.addEventListener('submit',go,true);
        });
        document.querySelectorAll('a[href*="/specialists"]').forEach(function(link){
            link.href=link.href.replace('/specialists','/doctors');
        });
    })();
    </script>
    <?php
}, 99 );

// Expose the parent specialty and fellowship sub-specialty on each profile.
// Fellowship names are commonly stored in the specialist subtitle while the
// relation table stores the broader discipline (and sometimes a duplicate
// fellowship label), so resolve both sources into one compact data set.
function ahura_profile_category_data( $post_id ) {
    static $cache = array();
    $post_id = (int) $post_id;
    if ( isset( $cache[ $post_id ] ) ) return $cache[ $post_id ];
    if ( ! $post_id ) return array( 'main' => array(), 'fellowships' => array() );
    global $wpdb;
    $specialist = $wpdb->get_row( $wpdb->prepare(
        "SELECT user_id,subtitle FROM {$wpdb->prefix}drplus_specialists WHERE post_id = %d LIMIT 1",
        $post_id
    ) );
    if ( ! $specialist ) return $cache[ $post_id ] = array( 'main' => array(), 'fellowships' => array() );

    $normalise = static function( $label ) {
        $label = trim( preg_replace( '/\\s+/u', ' ', (string) $label ) );
        $label = str_replace( 'ممتخصص', 'متخصص', $label );
        $label = str_replace( array( 'فوق متخصص', 'فوق‌متخصص' ), 'فوق تخصص', $label );
        $label = str_replace( array( 'فوق تخصصی', 'فوق‌تخصصی' ), 'فوق تخصص', $label );
        $label = preg_replace( '/(?<!فوق\\s)تخصصی/u', 'متخصص', $label );
        $label = preg_replace( '/(?<!فوق\\s)تخصص/u', 'متخصص', $label );
        $label = str_replace( array( 'فوق متخصص', 'فوق‌متخصص' ), 'فوق تخصص', $label );
        $label = preg_replace( '/^متخصص\\s+متخصص\\s+/u', 'متخصص ', $label );
        return trim( $label );
    };
    $subtitle = trim( (string) $specialist->subtitle );
    $fellowships = array();
    if ( preg_match_all( '/فلوشیپ\\s*([^\\/،,;|]+)/u', $subtitle, $matches ) ) {
        foreach ( $matches[1] as $name ) {
            $name = trim( preg_replace( '/\\s+/u', ' ', $name ) );
            if ( $name !== '' ) $fellowships[] = 'فلوشیپ ' . $name;
        }
    }

    // Canonical parents for fellowship labels that are also present as a
    // relation title or have no parent suffix in the speciality post.
    $fellowship_parents = array(
        'صرع' => 'مغز و اعصاب', 'اپی لپسی' => 'مغز و اعصاب', 'اختلالات حرکتی' => 'مغز و اعصاب',
        'سکته مغزی' => 'مغز و اعصاب', 'استروتاکسی' => 'مغز و اعصاب', 'فانکشنال مغز و اعصاب' => 'مغز و اعصاب',
        'تصویربرداری مداخل' => 'رادیولوژی', 'نورورادیولوژی' => 'رادیولوژی', 'دمانس' => 'مغز و اعصاب',
        'آلزایمر' => 'مغز و اعصاب', 'عصبی عضلانی' => 'مغز و اعصاب', 'نوروماسکولار' => 'مغز و اعصاب',
        'اینترونشنال کاردیولوژی' => 'قلب و عروق', 'آنژیوگرافی' => 'قلب و عروق', 'آنژیوپلاستی' => 'قلب و عروق',
        'پریناتولوژی' => 'زنان و زایمان', 'طب مادر و جنین' => 'زنان و زایمان', 'نازایی' => 'زنان و زایمان',
        'آی وی اف' => 'زنان و زایمان', 'آی وی اف' => 'زنان و زایمان', 'انکولوژی زنان' => 'زنان و زایمان',
        'قرنیه' => 'چشم پزشکی', 'گلوکوم' => 'چشم پزشکی', 'استرابیسم' => 'چشم پزشکی', 'ویتره' => 'چشم پزشکی',
        'رتین' => 'چشم پزشکی', 'اکولوپلاستی' => 'چشم پزشکی', 'اندویورولوژی' => 'اورولوژی',
        'اورولوژی زنان' => 'اورولوژی', 'اوروانکولوژی' => 'اورولوژی', 'اورولوژی کودکان' => 'اورولوژی',
        'اتولوژی' => 'گوش، حلق و بینی', 'نورواتولوژی' => 'گوش، حلق و بینی', 'گوش و حلق و بینی' => 'گوش، حلق و بینی',
        'جراحی زانو' => 'ارتوپدی', 'جراحی لگن' => 'ارتوپدی', 'جراحی شانه' => 'ارتوپدی',
        'جراحی ستون فقرات' => 'ارتوپدی', 'جراحی پا' => 'ارتوپدی', 'جراحی کولورکتال' => 'جراحی عمومی',
        'جراحی عروق' => 'جراحی عمومی', 'جراحی پستان' => 'جراحی عمومی', 'لاپاراسکوپی' => 'جراحی عمومی',
        'پیوند کلیه' => 'اورولوژی', 'درد' => 'بیهوشی',
    );

    $main = array();
    // Keep the factual qualification already supplied by the profile as the
    // first parent label (for example «فوق تخصص روماتولوژی»).
    $subtitle_main = trim( preg_replace( '/^نوبت[‌\\-]?دهی\\s*/u', '', $subtitle ) );
    if ( $subtitle_main !== '' && false === mb_stripos( $subtitle_main, 'فلوشیپ' ) && preg_match( '/^(?:متخصص|فوق تخصص|پزشک)\\b/u', $subtitle_main ) ) {
        $main[] = $normalise( $subtitle_main );
    }
    $mapped_parents = array();
    foreach ( $fellowships as $fellowship ) {
        $name = trim( preg_replace( '/^فلوشیپ\\s*/u', '', $fellowship ) );
        foreach ( $fellowship_parents as $needle => $parent ) {
            if ( false !== mb_stripos( $name, $needle ) ) {
                // Prefer the canonical parent when the relation itself is a
                // duplicate of the fellowship title.
                $mapped_parents[] = 'متخصص ' . $parent;
                break;
            }
        }
    }
    if ( ! empty( $mapped_parents ) ) {
        // When a fellowship has a canonical mapping, use that factual parent
        // instead of an old or unrelated relation label.
        $main = array_values( array_unique( $mapped_parents ) );
    }
    if ( empty( $main ) && $subtitle !== '' && false === mb_stripos( $subtitle, 'فلوشیپ' ) ) {
        $fallback = $normalise( preg_replace( '/^نوبت\\-?دهی\\s*/u', '', $subtitle ) );
        if ( $fallback !== '' ) $main[] = preg_match( '/^(?:متخصص|فوق تخصص|پزشک)\\b/u', $fallback ) ? $fallback : 'متخصص ' . $fallback;
    }
    $fellowships = array_values( array_unique( array_map( $normalise, $fellowships ) ) );
    // A few legacy relations repeat the fellowship itself (for example
    // «اینترونشنال کاردیولوژی»). Keep that item in the fellowship group and
    // leave the canonical parent in the main group.
    $fellow_names = array_map( static function( $value ) {
        return trim( preg_replace( '/^فلوشیپ\\s*/u', '', (string) $value ) );
    }, $fellowships );
    $main = array_values( array_unique( array_filter( $main, static function( $value ) use ( $fellow_names ) {
        $plain = trim( preg_replace( '/^(?:متخصص|فوق تخصص|پزشک)\\s+/u', '', (string) $value ) );
        return ! in_array( $plain, $fellow_names, true );
    } ) ) );
    // Do not repeat the same parent already shown by the profile subtitle
    // (for example «پزشک طب سنتی» alongside «متخصص طب سنتی»).
    $subtitle_plain = trim( preg_replace( '/^(?:متخصص|فوق تخصص|پزشک)\\s+/u', '', $subtitle_main ) );
    if ( $subtitle_plain !== '' ) {
        $subtitle_display = $normalise( $subtitle_main );
        $main = array_values( array_filter( $main, static function( $value ) use ( $subtitle_plain, $subtitle_display ) {
            $plain = trim( preg_replace( '/^(?:متخصص|فوق تخصص|پزشک)\\s+/u', '', (string) $value ) );
            return $plain !== $subtitle_plain || (string) $value === $subtitle_display;
        } ) );
    }
    return $cache[ $post_id ] = array( 'main' => $main, 'fellowships' => $fellowships );
}

// DrPlus builds its schema from every linked category. Imported profile links
// can contain broad search relations, so publish only the declared profile
// qualification and explicit fellowship data in structured data as well.
add_filter( 'drplus/specialist/single/schema', function( $schema, $specialist ) {
    if ( ! is_singular( 'specialist' ) || ! is_array( $schema ) ) return $schema;
    $categories = ahura_profile_category_data( get_queried_object_id() );
    $labels = array_merge( $categories['main'] ?? array(), $categories['fellowships'] ?? array() );
    if ( ! empty( $labels ) ) $schema['specialty'] = implode( ', ', array_values( array_unique( $labels ) ) );
    return $schema;
}, 20, 2 );

add_action( 'wp_footer', function() {
    if ( ! is_singular( 'specialist' ) ) return;
    $data = ahura_profile_category_data( get_queried_object_id() );
    if ( empty( $data['main'] ) && empty( $data['fellowships'] ) ) return;
    $search_url = home_url( '/search/' );
    ?>
    <div id="visital-profile-categories" class="visital-profile-categories" dir="rtl" hidden>
        <?php if ( ! empty( $data['main'] ) ) : ?>
            <div class="visital-profile-category-group"><div class="visital-profile-category-chips">
                <?php foreach ( $data['main'] as $label ) : ?><a class="visital-profile-chip visital-profile-chip-main" href="<?php echo esc_url( add_query_arg( 'q', $label, $search_url ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
            </div></div>
        <?php endif; ?>
        <?php if ( ! empty( $data['fellowships'] ) ) : ?>
            <div class="visital-profile-category-group"><div class="visital-profile-category-chips">
                <?php foreach ( $data['fellowships'] as $label ) : ?><a class="visital-profile-chip visital-profile-chip-fellowship" href="<?php echo esc_url( add_query_arg( 'q', $label, $search_url ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
            </div></div>
        <?php endif; ?>
    </div>
    <script>
    (function(){var box=document.getElementById('visital-profile-categories');if(!box)return;var sub=document.querySelector('.specialist_subtitle');if(sub&&sub.parentNode){sub.parentNode.insertBefore(box,sub.nextSibling);}else{var host=document.querySelector('.specialist_sidebar,.specialist-info,.specialist_profile');if(host)host.insertBefore(box,host.firstChild);}box.hidden=false;})();
    </script>
    <?php
}, 100 );

// Imported directory profiles are informational. Keep booking controls out of
// their page header and remove their markup after the profile has rendered.
add_action( 'wp_head', function() {
    if ( ! is_singular( 'specialist' ) ) return;
    ?>
    <style id="visital-profile-booking-off">
      .specialist_article .specialist_booking,
      .specialist_article #specialist_booking-btn,
      .specialist_article #specialist_consultation-btn{display:none!important}
    </style>
    <?php
}, 101 );

add_action( 'wp_footer', function() {
    if ( ! is_singular( 'specialist' ) ) return;
    ?>
    <script id="visital-profile-booking-remove">
    (function(){
      document.querySelectorAll('.specialist_article .specialist_booking,.specialist_article #specialist_booking-btn,.specialist_article #specialist_consultation-btn').forEach(function(node){
        var wrapper=node.closest('.specialist_booking');
        (wrapper||node).remove();
      });
    })();
    </script>
    <?php
}, 102 );

// Use the brand mark as the persistent dark-mode control. The navigation menu
// stays focused on navigation and never receives a theme row.
add_action( 'wp_footer', function() {
    $light_logo     = home_url( '/wp-content/uploads/visital-assets/visital-theme-toggle-light.png' );
    $dark_logo      = home_url( '/wp-content/uploads/visital-assets/visital-theme-toggle-dark.png' );
    $light_full_logo = home_url( '/wp-content/uploads/visital-assets/visital-logo-light-wordmark.png' );
    $dark_full_logo = home_url( '/wp-content/uploads/visital-assets/visital-logo-transparent.png' );
    $home_url       = home_url( '/' );
    ?>
    <script>
    (function(){
        var key='visital-dark-mode',migrationKey='visital-theme-v2';
        var lightLogo=<?php echo wp_json_encode( $light_logo ); ?>,darkLogo=<?php echo wp_json_encode( $dark_logo ); ?>,lightFullLogo=<?php echo wp_json_encode( $light_full_logo ); ?>,darkFullLogo=<?php echo wp_json_encode( $dark_full_logo ); ?>,homeUrl=<?php echo wp_json_encode( $home_url ); ?>;
        var readMode=function(){var value='';try{value=window.localStorage.getItem(key)||'';}catch(e){}if(!value){var match=document.cookie.match(new RegExp('(?:^|; )'+key+'=([^;]*)'));if(match)value=decodeURIComponent(match[1]);}return value;};
        var writeMode=function(value){try{window.localStorage.setItem(key,value);}catch(e){}try{document.cookie=key+'='+encodeURIComponent(value)+'; path=/; max-age=31536000; SameSite=Lax';}catch(e){}};
        var hasMigration=function(){var value='';try{value=window.localStorage.getItem(migrationKey)||'';}catch(e){}if(!value){var match=document.cookie.match(new RegExp('(?:^|; )'+migrationKey+'=([^;]*)'));if(match)value=decodeURIComponent(match[1]);}return value==='1';};
        var markMigration=function(){try{window.localStorage.setItem(migrationKey,'1');}catch(e){}try{document.cookie=migrationKey+'=1; path=/; max-age=31536000; SameSite=Lax';}catch(e){}};
        if(!hasMigration()){writeMode('0');markMigration();}
        var saved=readMode();
        var ensureMark=function(on){var logo=document.querySelector('.elementor-location-header .elementor-widget-theme-site-logo');var header=logo&&logo.parentElement?logo.parentElement:(document.querySelector('.elementor-location-header')||document.querySelector('#header_inner')||document.querySelector('#header-container'));if(!header)return;if(!logo)header.classList.add('visital-header-mark-pinned');var mark=header.querySelector('[data-visital-header-mark]');if(!mark){mark=document.createElement('button');mark.type='button';mark.className='visital-header-mark';mark.setAttribute('data-visital-header-mark','1');mark.setAttribute('aria-label','تغییر حالت نمایش');mark.setAttribute('title','تغییر حالت نمایش');mark.setAttribute('aria-pressed',on?'true':'false');var image=document.createElement('img');image.alt='';image.setAttribute('aria-hidden','true');mark.appendChild(image);header.appendChild(mark);}var image=mark.querySelector('img');if(image){image.src=on?darkLogo:lightLogo;image.removeAttribute('srcset');}};
        var ensureWordmark=function(on){document.querySelectorAll('.elementor-location-header .elementor-widget-theme-site-logo,.header-logo').forEach(function(wordmark){var anchor=wordmark.matches('a')?wordmark:wordmark.querySelector('a'),image=wordmark.matches('img')?wordmark:wordmark.querySelector('img');if(anchor){anchor.href=homeUrl;anchor.removeAttribute('data-visital-theme-logo');anchor.removeAttribute('role');anchor.removeAttribute('aria-pressed');anchor.removeAttribute('title');if(anchor.getAttribute('aria-label')==='تغییر حالت نمایش')anchor.removeAttribute('aria-label');}if(image){image.src=on?darkFullLogo:lightFullLogo;image.removeAttribute('srcset');image.alt='ویزیتال';}});};
        var removeMenuControls=function(){document.querySelectorAll('.visital-dark-mode-menu-item,[data-visital-dark-toggle]').forEach(function(node){var item=node.closest&&node.closest('.visital-dark-mode-menu-item');if(item)item.remove();else if(node.parentNode)node.parentNode.removeChild(node);});};
        var bindLogoToggle=function(){document.querySelectorAll('.visital-header-mark').forEach(function(button){if(button.getAttribute('data-visital-theme-logo'))return;button.setAttribute('data-visital-theme-logo','1');button.addEventListener('click',function(){var on=!document.body.classList.contains('visital-dark');writeMode(on?'1':'0');apply(on);});});};
        var apply=function(on){document.body.classList.toggle('visital-dark',on);document.documentElement.classList.toggle('visital-dark-preload',on);ensureMark(on);ensureWordmark(on);if(window.visitalUpdateFooterBrand)window.visitalUpdateFooterBrand(on);removeMenuControls();bindLogoToggle();document.querySelectorAll('.visital-header-mark').forEach(function(logo){logo.setAttribute('aria-pressed',on?'true':'false');});};
        var init=function(){removeMenuControls();apply(saved==='1');};
        if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
    })();
    </script>
    <?php
}, 101 );




// Final homepage clinic layout: compact cards, no placeholder cells, and a clear gap after the hero.
add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <style id="visital-home-clinic-finish">
      .home .elementor-element-10a2989{margin-top:clamp(1.35rem,4vw,2.75rem)!important}
      .home .elementor-element-acc40ad .elementor-element-acbee21{display:flex!important;flex-direction:column!important;align-items:center!important;justify-content:center!important;text-align:center!important}
      .home .elementor-element-acc40ad .elementor-element-1202b3b,.home .elementor-element-acc40ad .elementor-element-60460aa{width:100%!important;max-width:none!important;text-align:center!important;align-self:stretch!important}
      .home .elementor-element-acc40ad .elementor-element-7bee747{display:flex!important;width:100%!important;max-width:none!important;align-items:center!important;justify-content:center!important;margin-inline:auto!important}
      .home .elementor-element-acc40ad .elementor-element-7bee747 img{display:block!important;width:min(58%,220px)!important;max-width:220px!important;height:auto!important;margin:0 auto!important;object-fit:contain!important;transform:none!important}
      .home .elementor-element-ea8e6f4{display:block!important;width:calc(100% - 2rem)!important;max-width:1180px!important;margin:clamp(1.5rem,4vw,3rem) auto 0!important;padding:0!important;background:transparent!important}
      .home .elementor-element-ea8e6f4 .elementor-element-1bcc369{display:flex!important;width:100%!important;min-height:0!important;height:auto!important;margin:0 auto 1.1rem!important;padding:0!important;align-items:center!important;justify-content:center!important;gap:.55rem!important}
      .home .elementor-element-ea8e6f4 .elementor-element-aeb7160,.home .elementor-element-ea8e6f4 .elementor-element-f0709f5,.home .elementor-element-ea8e6f4 .elementor-widget-drplus_clinics,.home .elementor-element-ea8e6f4 .clinics{display:block!important;width:100%!important;height:auto!important;min-height:0!important;margin:0!important;padding:0!important;background:transparent!important}
      .home .elementor-element-ea8e6f4 .clinics{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:12px!important}
      .home .elementor-element-ea8e6f4 .clinic-empty{display:none!important}
      .home .elementor-element-ea8e6f4 .clinic{display:flex!important;min-height:112px!important;height:auto!important;width:auto!important;margin:0!important;padding:1rem!important;align-items:center!important;justify-content:center!important;border:1px solid #e2ebef!important;border-radius:16px!important;background:#fff!important;box-shadow:0 8px 22px rgba(25,57,78,.06)!important}
      .home .elementor-element-ea8e6f4 .clinic-inner{display:flex!important;min-height:0!important;flex-direction:column!important;align-items:center!important;justify-content:center!important;gap:.45rem!important}
      .home .elementor-element-ea8e6f4 .clinic-icon{font-size:2rem!important;color:#315c78!important}
      .home .elementor-element-ea8e6f4 .clinic-title{font-size:.82rem!important;line-height:1.7!important;text-align:center!important;color:#31536c!important}
      .home .elementor-element-ea8e6f4 .clinic-popover{display:none!important}
      body.visital-dark.home .elementor-element-ea8e6f4 .clinic{background:#102b39!important;border-color:#2a5362!important;box-shadow:0 10px 24px rgba(0,0,0,.18)!important}
      body.visital-dark.home .elementor-element-ea8e6f4 .clinic-icon{color:#72e0bd!important}
      body.visital-dark.home .elementor-element-ea8e6f4 .clinic-title{color:#e8f7f3!important}
      @media(max-width:700px){
        .home .elementor-element-10a2989{margin-top:1.25rem!important}
        .home .elementor-element-ea8e6f4{width:calc(100% - 1.25rem)!important;margin-top:2rem!important}
        .home .elementor-element-ea8e6f4 .clinics{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:10px!important}
        .home .elementor-element-ea8e6f4 .clinic{min-height:102px!important;padding:.75rem .55rem!important;border-radius:15px!important}
        .home .elementor-element-ea8e6f4 .clinic-icon{font-size:1.8rem!important}
        .home .elementor-element-ea8e6f4 .clinic-title{font-size:.78rem!important}
      }
    </style>
    <?php
}, 120 );

add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <style id="visital-home-clinic-specificity">
      body.home .elementor-element-ea8e6f4 .clinics > .clinic.clinic-empty{display:none!important}
      .home .elementor-element-acc40ad .elementor-element-7bee747{flex:1 1 100%!important;align-self:stretch!important}
      .home .elementor-element-acc40ad .elementor-element-7bee747 img{width:220px!important;max-width:70%!important}
    </style>
    <?php
}, 121 );

// Final compact sizing for the homepage clinic cards.
add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <style id="visital-home-clinic-compact-sizing">
      body.home .elementor-element-ea8e6f4 .clinics{grid-auto-rows:minmax(0,auto)!important;align-items:start!important}
      body.home .elementor-element-ea8e6f4 .clinics > .clinic:not(.clinic-empty){box-sizing:border-box!important;min-height:96px!important;height:96px!important;padding:9px 8px!important}
      body.home .elementor-element-ea8e6f4 .clinics > .clinic:not(.clinic-empty) .clinic-inner{min-height:0!important;height:auto!important;gap:4px!important}
      body.home .elementor-element-ea8e6f4 .clinics > .clinic:not(.clinic-empty) .clinic-icon{font-size:1.65rem!important;line-height:1!important}
      body.home .elementor-element-ea8e6f4 .clinics > .clinic:not(.clinic-empty) .clinic-title{font-size:.76rem!important;line-height:1.55!important}
      @media(max-width:700px){
        body.home .elementor-element-ea8e6f4 .clinics > .clinic:not(.clinic-empty){min-height:96px!important;height:96px!important;padding:8px 7px!important}
        body.home .elementor-element-ea8e6f4 .clinics > .clinic:not(.clinic-empty) .clinic-icon{font-size:1.55rem!important}
        body.home .elementor-element-ea8e6f4 .clinics > .clinic:not(.clinic-empty) .clinic-title{font-size:.72rem!important;line-height:1.5!important}
      }
    </style>
    <?php
}, 122 );

add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <style id="visital-home-hero-logo-centering">
      body.home .elementor-element-acc40ad .elementor-element-7bee747 img{position:relative!important;left:10px!important}
    </style>
    <?php
}, 123 );

// Homepage specialty carousel: pair the two source rows into one synchronized
// two-row track so mobile swipes never split the rows or expose half-cards.
add_action( 'wp_footer', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    $search_url = home_url( '/search/' );
    ?>
    <script id="visital-specialty-paired-carousel">
    (function(){
        var searchUrl=<?php echo wp_json_encode( $search_url ); ?>;
        var storageKey='visital-recent-specialty';
        var normalize=function(value){return String(value||'').replace(/[\u200c\u200f\u202a-\u202e]/g,'').replace(/\s+/g,' ').trim();};
        var readRecent=function(){try{return normalize(window.localStorage.getItem(storageKey)||'');}catch(e){return ''}};
        var writeRecent=function(value){try{window.localStorage.setItem(storageKey,normalize(value));}catch(e){}};
        var build=function(){
            var host=document.querySelector('.elementor-element-10a2989 .elementor-element-49d606d');
            if(!host||host.querySelector('.visital-specialty-paired-track'))return;
            var rows=[].slice.call(host.querySelectorAll(':scope > .elementor-widget-drplus_pro_icon_group .drplus-slider-wrap'));
            if(rows.length<2)return;
            var lists=rows.map(function(row){return [].slice.call(row.querySelectorAll('.wrapper > .proicon'));});
            if(!lists[0].length||!lists[1].length)return;
            var total=Math.min(lists[0].length,lists[1].length),recent=readRecent(),order=[];
            for(var i=0;i<total;i++)order.push(i);
            if(recent){var found=order.findIndex(function(index){return [lists[0][index],lists[1][index]].some(function(card){return normalize(card.querySelector('.proicon-title')?.textContent)===recent;});});if(found>0)order.unshift(order.splice(found,1)[0]);}
            var track=document.createElement('div');track.className='visital-specialty-paired-track';track.setAttribute('dir','rtl');
            order.forEach(function(index){
                var pair=document.createElement('div');pair.className='visital-specialty-pair';pair.setAttribute('data-specialty-pair',String(index));
                [lists[0][index],lists[1][index]].forEach(function(card){if(card)pair.appendChild(card);});
                track.appendChild(pair);
            });
            rows.forEach(function(row){row.setAttribute('aria-hidden','true');row.style.display='none';});
            var mobileButton=host.querySelector('.elementor-element-98fe61a');if(mobileButton)host.insertBefore(track,mobileButton);else host.appendChild(track);
            track.querySelectorAll('a.proicon').forEach(function(link){
                var title=normalize(link.querySelector('.proicon-title')?.textContent);if(!title)return;
                var url=new URL(searchUrl,window.location.origin);url.searchParams.set('q',title);link.href=url.toString();link.setAttribute('data-specialty',title);
                link.addEventListener('click',function(event){event.preventDefault();event.stopImmediatePropagation();writeRecent(title);window.location.assign(link.href);},true);
            });
        };
        var run=function(){build();window.setTimeout(build,250);window.setTimeout(build,900);};
        if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run);else run();
    })();
    </script>
    <?php
}, 100 );

add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <style id="visital-specialty-icon-finish">
      .home .elementor-element-10a2989 .section-title-icon-wrap,.home .elementor-element-ea8e6f4 .elementor-element-265332b .drplus-simple-icon-wrap{position:relative!important}
      .home .elementor-element-10a2989 .section-title-icon-wrap::after,.home .elementor-element-ea8e6f4 .elementor-element-265332b .drplus-simple-icon-wrap::after{color:#fff!important;font-size:8px!important;font-weight:900!important;text-shadow:0 1px 2px rgba(7,37,62,.25)}
    </style>
    <?php
}, 124 );

// Remove the hard outlines from the homepage clinic module while retaining
// the card spacing and soft elevation that separates each clinic.
add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <style id="visital-home-clinic-no-outline">
      body.home .elementor-element-ea8e6f4,
      body.home .elementor-element-ea8e6f4 .elementor-element-aeb7160,
      body.home .elementor-element-ea8e6f4 .elementor-element-f0709f5,
      body.home .elementor-element-ea8e6f4 .elementor-widget-drplus_clinics,
      body.home .elementor-element-ea8e6f4 .clinics{border:0!important;outline:0!important}
      body.home .elementor-element-ea8e6f4 .clinics > .clinic:not(.clinic-empty){border:0!important;outline:0!important}
      body.visital-dark.home .elementor-element-ea8e6f4 .clinics > .clinic:not(.clinic-empty){border:0!important}
    </style>
    <?php
}, 125 );

add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <style id="visital-home-clinic-separators-off">
      body.home .elementor-element-ea8e6f4 .clinic-separator,
      body.home .elementor-element-ea8e6f4 .clinic-separator-white{display:none!important;border:0!important;background:transparent!important;box-shadow:none!important}
    </style>
    <?php
}, 126 );

// Final footer alignment and full specialty-name treatment.
add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <style id="visital-home-footer-and-specialties-finish">
      body.home .elementor-element-10a2989 .visital-specialty-pair .proicon-title{display:block!important;white-space:normal!important;overflow:visible!important;text-overflow:clip!important;-webkit-line-clamp:unset!important;line-clamp:unset!important;word-break:normal!important;overflow-wrap:normal!important}
      body.home #footer-org-items-wrap{display:none!important}
      body.home #footer-socials-wrap{display:flex!important;flex-direction:column!important;align-items:center!important;justify-content:flex-start!important;text-align:center!important;gap:14px!important}
      body.home #footer-socials-wrap > #footer-social{display:flex!important;width:min(100%,420px)!important;flex-direction:column!important;align-items:center!important;text-align:center!important;gap:12px!important}
      body.home #footer-social-section{display:grid!important;width:100%!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:10px!important;align-items:center!important;justify-items:center!important;margin:0!important}
      body.home #footer-social-section .footer-social-item{display:flex!important;width:56px!important;height:56px!important;max-width:56px!important;align-items:center!important;justify-content:center!important;margin:0!important}
      body.home #footer-socials-wrap .site-title{display:flex!important;width:100%!important;justify-content:center!important;align-items:center!important;margin:0!important}
      body.home #footer-socials-wrap .site-title-inner{display:flex!important;width:100%!important;justify-content:center!important;align-items:center!important}
      body.home #footer-socials-wrap #site-logo{display:block!important;width:100%!important;text-align:center!important;line-height:0!important}
      body.home #footer-socials-wrap #site-logo img{display:block!important;width:min(300px,92%)!important;max-width:300px!important;height:auto!important;margin:0 auto!important;object-fit:contain!important}
      body.home #footer_info{display:grid!important;grid-template-columns:minmax(0,1fr) minmax(0,1.2fr)!important;gap:22px!important;align-items:start!important}
      body.home #footer_info .footer-menu-wrap,body.home #footer_info #footer-contact_info{min-width:0!important;text-align:center!important}
      body.home #footer_info .footer-menu ul{display:grid!important;gap:4px!important;margin:0!important;padding:0!important;list-style:none!important}
      body.home #footer_info .footer-menu li{display:block!important;margin:0!important;padding:0!important;min-width:0!important}
      body.home #footer_info .footer-menu li a{display:flex!important;justify-content:center!important;align-items:center!important;min-height:28px!important;white-space:normal!important;text-align:center!important}
      body.home #footer-contact_info-inner{width:100%!important}
      body.home #footer-contact_info .footer-contacts-wrap{display:flex!important;flex-direction:column!important;gap:8px!important;width:100%!important}
      body.home #footer-contact_info .footer-contact-wrap{display:flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;width:100%!important;min-width:0!important}
      body.home #footer-contact_info .footer-contact-wrap > i{flex:0 0 22px!important;width:22px!important;text-align:center!important}
      body.home #footer-contact_info .footer-contact{display:block!important;max-width:100%!important;overflow-wrap:anywhere!important;text-align:center!important}
      @media(max-width:700px){
        body.home .elementor-element-10a2989 .visital-specialty-pair .proicon-title{font-size:.76rem!important;line-height:1.4!important}
        body.home #footer_content{display:flex!important;flex-direction:column!important;align-items:stretch!important;gap:26px!important}
        body.home #footer-socials-wrap{order:-1!important;width:100%!important}
        body.home #footer-socials-wrap > #footer-social{width:min(100%,420px)!important}
        body.home #footer_info{display:flex!important;flex-direction:column!important;align-items:center!important;gap:24px!important;width:100%!important}
        body.home #footer_info .footer-menu-wrap,body.home #footer_info #footer-contact_info{width:100%!important;max-width:360px!important}
        body.home #footer_info .footer-menu ul{justify-items:center!important}
        body.home #footer_info .footer-menu li a{min-height:30px!important}
      }
    </style>
    <?php
}, 127 );

add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <style id="visital-home-clinic-pseudo-lines-off">
      body.home .elementor-element-ea8e6f4 .clinics::before,
      body.home .elementor-element-ea8e6f4 .clinics::after,
      body.home .elementor-element-ea8e6f4 .clinics > .clinic::before,
      body.home .elementor-element-ea8e6f4 .clinics > .clinic::after{display:none!important;content:none!important;border:0!important;background:transparent!important;box-shadow:none!important}
    </style>
    <?php
}, 128 );

add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <style id="visital-home-clinic-shadow-off">
      body.home .elementor-element-ea8e6f4 .clinics > .clinic:not(.clinic-empty){box-shadow:none!important;background:#f8fbfc!important}
      body.visital-dark.home .elementor-element-ea8e6f4 .clinics > .clinic:not(.clinic-empty){box-shadow:none!important;background:#102b39!important}
    </style>
    <?php
}, 129 );

add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <style id="visital-home-clinic-empty-tiles-off">
      body.home .elementor-element-ea8e6f4 .clinics > .clinic.clinic-empty{
        border:0!important;
        outline:0!important;
        box-shadow:none!important;
        background:transparent!important;
      }
    </style>
    <?php
}, 130 );

add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <style id="visital-home-social-icons-size-spacing">
      body.home #footer-social-section{
        gap:14px!important;
      }
      body.home #footer-social-section .footer-social-item{
        width:48px!important;
        height:48px!important;
        max-width:48px!important;
        padding:0!important;
        box-sizing:border-box!important;
        border-radius:14px!important;
      }
      body.home #footer-social-section .footer-social-item > i{
        font-size:21px!important;
        line-height:1!important;
      }
    </style>
    <?php
}, 131 );

add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <style id="visital-home-clinic-heading-star-no-tile">
      body.home .elementor-element-ea8e6f4 .drplus-simple-icon-wrap.icon-has-bg{
        width:26px!important;
        height:26px!important;
        background:transparent!important;
        border:0!important;
        box-shadow:none!important;
      }
      body.home .elementor-element-ea8e6f4 .drplus-simple-icon-wrap.icon-has-bg::before{
        display:none!important;
        content:none!important;
        background:transparent!important;
        box-shadow:none!important;
      }
      body.home .elementor-element-ea8e6f4 .drplus-simple-icon-wrap.icon-has-bg > .drplus-simple-icon{
        color:#03306a!important;
        background:transparent!important;
      }
      body.visital-dark.home .elementor-element-ea8e6f4 .drplus-simple-icon-wrap.icon-has-bg > .drplus-simple-icon{
        color:#8ee6dc!important;
      }
    </style>
    <?php
}, 132 );

add_action( 'wp_head', function() {
    ?>
    <style id="visital-header-theme-logo-fit">
      .visital-header-mark{
        width:34px!important;
        height:34px!important;
        max-width:34px!important;
        max-height:34px!important;
        overflow:hidden!important;
      }
      .visital-header-mark img{
        width:34px!important;
        height:34px!important;
        max-width:34px!important;
        max-height:34px!important;
        object-fit:contain!important;
      }
      @media(max-width:700px){
        .visital-header-mark{
          left:4px!important;
          width:32px!important;
          height:32px!important;
          max-width:32px!important;
          max-height:32px!important;
        }
        .visital-header-mark img{
          width:32px!important;
          height:32px!important;
          max-width:32px!important;
          max-height:32px!important;
        }
      }
    </style>
    <?php
}, 133 );

add_action( 'wp_head', function() {
    ?>
    <style id="visital-header-theme-logo-placement">
      .elementor-location-header .visital-header-mark{position:relative!important;left:auto!important;right:auto!important;top:auto!important;transform:none!important;flex:0 0 auto;align-self:center;order:3;margin-inline-start:10px}
      .visital-header-mark-pinned .visital-header-mark{position:absolute!important;left:12px!important;top:50%!important;transform:translateY(-50%)!important}
      @media(max-width:700px){.visital-header-mark-pinned .visital-header-mark{left:8px!important}}
    </style>
    <?php
}, 134 );

// A deliberately small, focused search entry point at the end of the home
// hero. It uses the directory search route, rather than the retired Elementor
// form, so every query reaches the same fuzzy search and category results.
add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <style id="visital-home-search-shortcut">
      .visital-home-search-shortcut{width:calc(100% - 2rem);max-width:670px;margin:-14px auto 2rem;position:relative;z-index:4}
      .visital-home-search-shortcut__form{position:relative;display:flex;align-items:center;width:100%;min-height:58px;padding:5px 12px 5px 18px;border:1px solid rgba(23,72,106,.14);border-radius:19px;background:rgba(255,255,255,.92);box-shadow:0 13px 30px rgba(17,57,88,.12);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);box-sizing:border-box;transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}
      .visital-home-search-shortcut__form:focus-within{transform:translateY(-2px);border-color:rgba(35,119,153,.45);box-shadow:0 17px 34px rgba(17,57,88,.17)}
      .visital-home-search-shortcut__input{width:100%!important;min-width:0!important;height:46px!important;margin:0!important;padding:0 48px!important;border:0!important;outline:0!important;background:transparent!important;color:#133f60!important;font:inherit!important;font-size:1rem!important;font-weight:650!important;text-align:center!important;box-shadow:none!important}
      .visital-home-search-shortcut__input::placeholder{color:#436b82!important;opacity:1!important;text-align:center!important}
      .visital-home-search-shortcut__submit{position:absolute!important;inset-inline-start:13px!important;top:50%!important;display:grid!important;width:38px!important;height:38px!important;min-width:38px!important;padding:0!important;place-items:center!important;transform:translateY(-50%)!important;border:0!important;border-radius:13px!important;background:transparent!important;color:#1b6988!important;cursor:pointer!important;box-shadow:none!important}
      .visital-home-search-shortcut__submit:hover,.visital-home-search-shortcut__submit:focus-visible{background:#e7f5f4!important;color:#0c536f!important}
      .visital-home-search-shortcut__submit svg{width:21px;height:21px;stroke:currentColor;stroke-width:2;fill:none;stroke-linecap:round}
      body.visital-dark .visital-home-search-shortcut__form{border-color:rgba(117,220,190,.24)!important;background:rgba(11,40,53,.96)!important;box-shadow:0 14px 32px rgba(0,0,0,.28)!important}
      body.visital-dark .visital-home-search-shortcut__form:focus-within{border-color:#56d7af!important;box-shadow:0 17px 36px rgba(0,0,0,.36)!important}
      body.visital-dark .visital-home-search-shortcut__input{color:#e8f7f3!important;background:transparent!important}
      body.visital-dark .visital-home-search-shortcut__input::placeholder{color:#b5d4d1!important}
      body.visital-dark .visital-home-search-shortcut__submit{color:#78e2c2!important;background:transparent!important}
      body.visital-dark .visital-home-search-shortcut__submit:hover,body.visital-dark .visital-home-search-shortcut__submit:focus-visible{background:#173d48!important;color:#9cf0d5!important}
      @media(max-width:700px){.visital-home-search-shortcut{width:calc(100% - 1.4rem);margin:-8px auto 1.5rem}.visital-home-search-shortcut__form{min-height:54px;border-radius:17px;padding-inline:10px}.visital-home-search-shortcut__input{height:42px!important;font-size:.94rem!important}.visital-home-search-shortcut__submit{inset-inline-start:10px!important;width:35px!important;height:35px!important;min-width:35px!important;border-radius:12px!important}.visital-home-search-shortcut__submit svg{width:20px;height:20px}}
    </style>
    <?php
}, 140 );

add_action( 'wp_footer', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    $search_url = home_url( '/search/' );
    ?>
    <script id="visital-home-search-shortcut-script">
    (function(){
      var mount=function(){
        if(document.querySelector('.visital-home-search-shortcut'))return;
        var hero=document.querySelector('.elementor-element-acc40ad');
        if(!hero)return;
        var section=document.createElement('section');
        section.className='visital-home-search-shortcut';
        section.setAttribute('aria-label','جستجوی پزشک');
        section.innerHTML='<form class="visital-home-search-shortcut__form" action="<?php echo esc_url( $search_url ); ?>" method="get" role="search"><input class="visital-home-search-shortcut__input" name="q" type="search" inputmode="search" autocomplete="off" placeholder="جستجوی پزشک" aria-label="جستجوی پزشک"><button class="visital-home-search-shortcut__submit" type="submit" aria-label="جستجو"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg></button></form>';
        hero.insertAdjacentElement('afterend',section);
      };
      if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount);else mount();
    })();
    </script>
    <?php
}, 160 );

/* -------------------------------------------------------------------------- */
/* Search engine foundations: public indexing, unique metadata, and sitemaps */
/* -------------------------------------------------------------------------- */

// WordPress had search-engine visibility disabled, which emits
// "noindex, nofollow" on every public page. Restore the public setting once
// and leave account, search, cart, and other utility views out of the index.
add_action( 'init', function() {
    if ( '1' !== (string) get_option( 'blog_public' ) ) {
        update_option( 'blog_public', '1' );
    }
}, 1 );

// Publish the new titles, robots rules, and sitemap immediately instead of
// waiting for an existing full-page cache entry to expire.
add_action( 'init', function() {
    $cache_revision = '2026-09-25-dedupe-87824';
    if ( $cache_revision === get_option( 'visital_seo_cache_revision' ) ) return;
    update_option( 'visital_seo_cache_revision', $cache_revision );
    if ( class_exists( '\LiteSpeed\Purge' ) ) {
        \LiteSpeed\Purge::purge_all( 'visital theme rollback' );
    }
}, 3 );

function visital_request_path() {
    $path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
    return untrailingslashit( $path );
}

function visital_is_internal_search_view() {
    return is_search() || 'search' === get_query_var( 'specialist_section' ) || '/search' === visital_request_path();
}

add_filter( 'wp_robots', function( $robots ) {
    unset( $robots['noindex'], $robots['nofollow'] );
    if ( visital_is_internal_search_view() || is_404() ) {
        $robots['noindex'] = true;
    }
    return $robots;
}, 999 );

add_filter( 'robots_txt', function( $output ) {
    $sitemap = home_url( '/sitemap.xml' );
    // Core advertises wp-sitemap.xml even though this installation does not
    // serve it. Avoid giving crawlers a dead sitemap alongside the working one.
    $output = preg_replace( '#^\s*Sitemap:\s*https?://[^\s]+/wp-sitemap\.xml\s*$#mi', '', $output );
    if ( false === strpos( $output, $sitemap ) ) {
        $output = trim( $output ) . "\nSitemap: " . $sitemap . "\n";
    }
    return $output;
}, 99 );

function visital_seo_context() {
    $site_name = 'ویزیتال';
    $default_image = home_url( '/wp-content/uploads/2026/08/IMG_7183.png' );
    $context = array(
        'title'       => $site_name . ' | نوبت‌دهی آنلاین پزشکان و مراکز درمانی',
        'description' => 'ویزیتال، جستجو و نوبت‌دهی آنلاین پزشکان، متخصص‌ها و مراکز درمانی در ایران.',
        'url'         => home_url( '/' ),
        'type'        => 'website',
        'image'       => $default_image,
    );

    if ( is_front_page() || is_home() ) {
        return $context;
    } elseif ( is_post_type_archive( 'specialist' ) || 'doctors' === get_query_var( 'specialist_section' ) ) {
        $context['title'] = 'متخصص‌ها و پزشکان عمومی | ویزیتال';
        $context['description'] = 'پزشک، متخصص و فوق‌تخصص موردنظر خود را بر اساس تخصص و شهر پیدا کنید و برای ویزیت آنلاین یا حضوری اقدام کنید.';
        $context['url'] = home_url( '/doctors/' );
    } elseif ( visital_is_internal_search_view() ) {
        $term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        $context['title'] = $term ? 'نتایج جستجوی ' . $term . ' | ویزیتال' : 'جستجوی پزشک و متخصص | ویزیتال';
        $context['description'] = 'جستجوی پزشک و متخصص در ویزیتال.';
        $context['url'] = $term ? add_query_arg( 'q', $term, home_url( '/search/' ) ) : home_url( '/search/' );
    } elseif ( is_singular( 'specialist' ) ) {
        $name = get_the_title();
        $context['title'] = $name . ' | ویزیتال';
        $context['description'] = 'مشاهده پروفایل، تخصص‌ها و اطلاعات تماس ' . $name . ' در ویزیتال.';
        $context['url'] = get_permalink();
        $context['type'] = 'profile';
        $thumbnail = get_the_post_thumbnail_url( get_queried_object_id(), 'large' );
        if ( $thumbnail ) $context['image'] = $thumbnail;
    } elseif ( is_page() ) {
        $title = get_the_title();
        if ( $title ) $context['title'] = $title . ' | ویزیتال';
        $excerpt = wp_strip_all_tags( get_the_excerpt() );
        if ( $excerpt ) $context['description'] = wp_trim_words( $excerpt, 28, '' );
        $context['url'] = get_permalink();
    }
    return $context;
}

add_filter( 'document_title_parts', function( $parts ) {
    $context = visital_seo_context();
    $parts['title'] = $context['title'];
    unset( $parts['site'] );
    return $parts;
}, 999 );

// The theme supplies a title through its own title callback. This final hook
// ensures public pages get the concise, unique SEO title above.
add_filter( 'pre_get_document_title', function() {
    $context = visital_seo_context();
    return $context['title'];
}, 99999 );

function visital_xml_value( $value ) {
    return htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

function visital_sitemap_header() {
    status_header( 200 );
    nocache_headers();
    header( 'Content-Type: application/xml; charset=UTF-8' );
    header( 'X-Robots-Tag: index, follow' );
    header( 'Cache-Control: public, max-age=300' );
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
}

function visital_sitemap_url( $location, $modified = '' ) {
    echo "  <url>\n    <loc>" . visital_xml_value( $location ) . "</loc>\n";
    if ( $modified ) echo '    <lastmod>' . visital_xml_value( mysql_to_rfc3339( $modified ) ) . "</lastmod>\n";
    echo "  </url>\n";
}

// A compact sitemap index keeps the 136k+ doctor profiles within the sitemap
// protocol limit (50,000 URLs per file) and keeps crawls inexpensive.
add_action( 'template_redirect', function() {
    $request_path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
    $request_path = trim( $request_path, '/' );
    if ( ! preg_match( '#^sitemap(?:-(pages|doctors-([1-9][0-9]*)))?\.xml$#', $request_path, $matches ) ) return;

    $chunk_size = 5000;

    if ( 'sitemap.xml' === $request_path ) {
        $status_counts = wp_count_posts( 'specialist' );
        $doctor_count = isset( $status_counts->publish ) ? (int) $status_counts->publish : 0;
        $chunks = max( 1, (int) ceil( $doctor_count / $chunk_size ) );
        visital_sitemap_header();
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        echo '  <sitemap><loc>' . visital_xml_value( home_url( '/sitemap-pages.xml' ) ) . "</loc></sitemap>\n";
        for ( $i = 1; $i <= $chunks; $i++ ) {
            echo '  <sitemap><loc>' . visital_xml_value( home_url( '/sitemap-doctors-' . $i . '.xml' ) ) . "</loc></sitemap>\n";
        }
        echo "</sitemapindex>\n";
        exit;
    }

    if ( 'pages' === ( $matches[1] ?? '' ) ) {
        $pages = get_pages( array( 'post_status' => 'publish', 'sort_column' => 'post_modified_gmt', 'sort_order' => 'DESC' ) );
        visital_sitemap_header();
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        visital_sitemap_url( home_url( '/' ) );
        visital_sitemap_url( home_url( '/doctors/' ) );
        foreach ( $pages as $page ) {
            $url = get_permalink( $page );
            if ( $url && untrailingslashit( $url ) !== untrailingslashit( home_url( '/' ) ) ) visital_sitemap_url( $url, $page->post_modified_gmt );
        }
        echo "</urlset>\n";
        exit;
    }

    $chunk = (int) ( $matches[2] ?? 0 );
    if ( $chunk < 1 ) return;
    $doctor_query = new WP_Query( array(
        'post_type'              => 'specialist',
        'post_status'            => 'publish',
        'posts_per_page'         => $chunk_size,
        'paged'                  => $chunk,
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ) );
    $doctors = $doctor_query->posts;
    if ( empty( $doctors ) ) return;
    visital_sitemap_header();
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ( $doctors as $doctor ) {
        $url = get_permalink( $doctor );
        if ( $url ) visital_sitemap_url( $url, $doctor->post_modified_gmt );
    }
    echo "</urlset>\n";
    exit;
}, 0 );

add_action( 'wp_head', function() {
    if ( is_admin() ) return;
    $context = visital_seo_context();
    $site_url = home_url( '/' );
    $schema = array(
        '@context' => 'https://schema.org',
        '@graph' => array(
            array(
                '@type' => 'MedicalOrganization',
                '@id' => $site_url . '#organization',
                'name' => 'ویزیتال',
                'url' => $site_url,
                'logo' => home_url( '/wp-content/uploads/visital-assets/visital-logo-transparent.png' ),
                'image' => $context['image'],
                'description' => 'سامانه جستجو و نوبت‌دهی آنلاین پزشکان و مراکز درمانی ویزیتال.',
                'inLanguage' => 'fa-IR',
            ),
            array(
                '@type' => 'WebSite',
                '@id' => $site_url . '#website',
                'url' => $site_url,
                'name' => 'ویزیتال',
                'inLanguage' => 'fa-IR',
                'publisher' => array( '@id' => $site_url . '#organization' ),
                'potentialAction' => array(
                    '@type' => 'SearchAction',
                    'target' => home_url( '/search/?q={search_term_string}' ),
                    'query-input' => 'required name=search_term_string',
                ),
            ),
        ),
    );
    if ( is_singular( 'specialist' ) ) {
        $profile_categories = function_exists( 'ahura_profile_category_data' ) ? ahura_profile_category_data( get_queried_object_id() ) : array();
        $specialties = array_merge( $profile_categories['main'] ?? array(), $profile_categories['fellowships'] ?? array() );
        $specialties = array_map( function( $label ) {
            return preg_replace( '/ممتخصص/u', 'متخصص', (string) $label );
        }, $specialties );
        $specialties = array_values( array_filter( array_unique( $specialties ) ) );
        $person = array(
            '@type' => 'Physician',
            '@id' => $context['url'] . '#physician',
            'name' => get_the_title(),
            'url' => $context['url'],
            'image' => $context['image'],
            'description' => $context['description'],
            'medicalSpecialty' => $specialties,
            'inLanguage' => 'fa-IR',
        );
        $schema['@graph'][] = $person;
        $schema['@graph'][] = array(
            '@type' => 'BreadcrumbList',
            'itemListElement' => array(
                array( '@type' => 'ListItem', 'position' => 1, 'name' => 'ویزیتال', 'item' => $site_url ),
                array( '@type' => 'ListItem', 'position' => 2, 'name' => 'متخصص‌ها', 'item' => home_url( '/doctors/' ) ),
                array( '@type' => 'ListItem', 'position' => 3, 'name' => get_the_title(), 'item' => $context['url'] ),
            ),
        );
    }
    ?>
    <meta id="visital-meta-description" name="description" content="<?php echo esc_attr( $context['description'] ); ?>">
    <meta name="theme-color" content="#0d3c70">
    <meta property="og:locale" content="fa_IR">
    <meta property="og:type" content="<?php echo esc_attr( $context['type'] ); ?>">
    <meta property="og:site_name" content="ویزیتال">
    <meta property="og:title" content="<?php echo esc_attr( $context['title'] ); ?>">
    <meta property="og:description" content="<?php echo esc_attr( $context['description'] ); ?>">
    <meta property="og:url" content="<?php echo esc_url( $context['url'] ); ?>">
    <meta property="og:image" content="<?php echo esc_url( $context['image'] ); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo esc_attr( $context['title'] ); ?>">
    <meta name="twitter:description" content="<?php echo esc_attr( $context['description'] ); ?>">
    <meta name="twitter:image" content="<?php echo esc_url( $context['image'] ); ?>">
    <script type="application/ld+json" id="visital-seo-schema"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
    <?php
}, 2 );

// The active footer is Elementor template 482, so keep the agreed logo,
// contact alignment, and compact social controls attached to its real markup.
add_action( 'wp_head', function() {
    ?>
    <style id="visital-elementor-footer-recovery">
      .elementor-482.elementor-location-footer .elementor-element-6ef907e{background:#114b5f!important}
      .elementor-482.elementor-location-footer .elementor-element-ea3bdf0{display:grid!important;width:100%!important;max-width:1220px!important;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr) minmax(230px,.85fr)!important;gap:clamp(22px,3vw,42px)!important;margin-inline:auto!important;padding:46px 28px 38px!important;box-sizing:border-box!important}
      .elementor-482.elementor-location-footer .elementor-element-96ad4d5{min-width:0!important}
      .elementor-482.elementor-location-footer .elementor-element-4da8e5c{grid-template-columns:minmax(0,1fr) minmax(0,1.2fr)!important;gap:26px!important;align-items:start!important}
      .elementor-482.elementor-location-footer .elementor-element-788f13b{display:flex!important;flex-direction:column!important;align-items:center!important;justify-content:flex-start!important;gap:16px!important;min-width:0!important}
      .elementor-482.elementor-location-footer .elementor-element-960d422{order:0!important;display:flex!important;width:100%!important;min-height:0!important;align-items:center!important;justify-content:center!important;margin:0!important;padding:0!important;background:transparent!important;border:0!important;box-shadow:none!important}
      .elementor-482.elementor-location-footer .elementor-element-960d422>.e-con-inner{display:flex!important;width:100%!important;max-width:none!important;min-height:0!important;align-items:center!important;justify-content:center!important;margin:0!important;padding:0!important;background:transparent!important}
      .elementor-482.elementor-location-footer .visital-footer-wordmark{display:flex!important;width:100%!important;max-width:230px!important;align-items:center!important;justify-content:center!important;margin:0 auto!important;padding:0!important;background:transparent!important;line-height:0!important}
      .elementor-482.elementor-location-footer .visital-footer-wordmark img{display:block!important;width:100%!important;max-width:230px!important;height:auto!important;max-height:118px!important;object-fit:contain!important;margin:0 auto!important}
      .elementor-482.elementor-location-footer .elementor-element-07e12e2{order:1!important;display:flex!important;width:100%!important;max-width:360px!important;flex-direction:column!important;align-items:center!important;justify-content:flex-start!important;gap:10px!important;margin:0 auto!important;padding:10px 12px 14px!important;background:transparent!important;text-align:center!important;box-sizing:border-box!important}
      .elementor-482.elementor-location-footer .elementor-element-e8beb57,.elementor-482.elementor-location-footer .elementor-element-e8beb57 .elementor-heading-title{width:100%!important;text-align:center!important}
      .elementor-482.elementor-location-footer .elementor-element-0587cdd{display:flex!important;width:100%!important;flex-direction:row!important;flex-wrap:wrap!important;align-items:center!important;justify-content:center!important;gap:12px!important;margin:0!important;padding:0!important}
      .elementor-482.elementor-location-footer .elementor-element-0587cdd>.elementor-element{flex:0 0 44px!important;width:44px!important;max-width:44px!important;margin:0!important}
      .elementor-482.elementor-location-footer .elementor-element-0587cdd .elementor-icon{display:grid!important;width:44px!important;height:44px!important;place-items:center!important;border-radius:13px!important}
      .elementor-482.elementor-location-footer .elementor-element-0587cdd .elementor-icon i{font-size:19px!important}
      .elementor-482.elementor-location-footer .elementor-widget-icon-box .elementor-icon-box-wrapper{display:flex!important;flex-direction:row!important;align-items:center!important;gap:10px!important;text-align:right!important}
      .elementor-482.elementor-location-footer .elementor-widget-icon-box .elementor-icon-box-icon{flex:0 0 auto!important;margin:0!important}
      .elementor-482.elementor-location-footer .elementor-element-a03a946{width:100%!important;min-height:0!important;padding:26px 16px 14px!important;text-align:center!important}
      .elementor-482.elementor-location-footer .elementor-element-1ec781d,.elementor-482.elementor-location-footer .elementor-element-1ec781d .elementor-widget-container{text-align:center!important}
      body.visital-dark .elementor-482.elementor-location-footer .elementor-element-6ef907e,body.visital-dark .elementor-482.elementor-location-footer .elementor-element-6ef907e:not(.elementor-motion-effects-element-type-background){background:#061827!important;background-color:#061827!important}
      body.visital-dark .elementor-482.elementor-location-footer .elementor-element-07e12e2,body.visital-dark .elementor-482.elementor-location-footer .elementor-element-07e12e2:not(.elementor-motion-effects-element-type-background){background:#0d2531!important;background-color:#0d2531!important}
      @media(max-width:767px){
        .elementor-482.elementor-location-footer .elementor-element-5a6c88a{margin-top:42px!important}
        .elementor-482.elementor-location-footer .elementor-element-ea3bdf0{grid-template-columns:minmax(0,1fr)!important;gap:28px!important;padding:34px 18px 26px!important}
        .elementor-482.elementor-location-footer .elementor-element-6f35afd,.elementor-482.elementor-location-footer .elementor-element-96ad4d5,.elementor-482.elementor-location-footer .elementor-element-788f13b{width:100%!important;max-width:520px!important;margin-inline:auto!important;text-align:center!important}
        .elementor-482.elementor-location-footer .elementor-element-4da8e5c{grid-template-columns:minmax(0,1fr)!important;gap:22px!important}
        .elementor-482.elementor-location-footer .elementor-element-8585c4b,.elementor-482.elementor-location-footer .elementor-element-a876b08{align-items:center!important;text-align:center!important}
        .elementor-482.elementor-location-footer .elementor-element-a876b08 .elementor-widget-icon-box .elementor-icon-box-wrapper{justify-content:center!important;text-align:center!important}
        .elementor-482.elementor-location-footer .elementor-element-960d422{min-height:0!important}
        .elementor-482.elementor-location-footer .visital-footer-wordmark{max-width:210px!important}
        .elementor-482.elementor-location-footer .elementor-element-0587cdd{gap:12px!important}
      }
    </style>
    <?php
}, 135 );

add_action( 'wp_footer', function() {
    $light_full_logo = home_url( '/wp-content/uploads/visital-assets/visital-logo-light-wordmark.png' );
    $dark_full_logo  = home_url( '/wp-content/uploads/visital-assets/visital-logo-transparent.png' );
    $home_url        = home_url( '/' );
    ?>
    <script id="visital-elementor-footer-recovery-script">
    (function(){
      var lightLogo=<?php echo wp_json_encode( $light_full_logo ); ?>,darkLogo=<?php echo wp_json_encode( $dark_full_logo ); ?>,homeUrl=<?php echo wp_json_encode( $home_url ); ?>;
      window.visitalUpdateFooterBrand=function(dark){
        var footer=document.querySelector('.elementor-482.elementor-location-footer');
        if(!footer)return;
        var holder=footer.querySelector('.elementor-element-960d422');
        if(holder){
          holder.classList.add('visital-footer-wordmark-wrap');
          var link=holder.querySelector('.visital-footer-wordmark');
          if(!link){
            while(holder.firstChild)holder.removeChild(holder.firstChild);
            link=document.createElement('a');
            link.className='visital-footer-wordmark';
            link.href=homeUrl;
            link.setAttribute('aria-label','بازگشت به صفحه اصلی ویزیتال');
            var image=document.createElement('img');
            image.alt='ویزیتال';
            image.loading='lazy';
            link.appendChild(image);
            holder.appendChild(link);
          }
          var image=link.querySelector('img');
          if(image)image.src=dark?darkLogo:lightLogo;
        }
        var phone=footer.querySelector('.elementor-element-e03f2df a[href^="tel:"]');
        if(phone){
          var digits=(phone.getAttribute('href').replace(/^tel:/i,'').match(/[0-9]+/g)||[]).join('');
          if(digits){
            var shown=digits.length>3?digits.slice(0,3)+'-'+digits.slice(3):digits;
            shown=shown.replace(/[0-9]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'[Number(d)];});
            phone.textContent=shown;
            phone.setAttribute('aria-label','تماس تلفنی '+shown);
            phone.setAttribute('dir','ltr');
          }
        }
      };
      window.visitalUpdateFooterBrand(document.body.classList.contains('visital-dark'));
    })();
    </script>
    <?php
}, 105 );

// A merged duplicate keeps its published address working: the old
// /specialist/{slug}/ URL 301s to the profile that absorbed it. This only runs on
// 404 requests, so normal profile views pay nothing.
add_action( 'template_redirect', function () {
    if ( ! is_404() ) return;

    $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH );
    $path = trim( (string) $path, '/' );
    if ( 0 !== strpos( $path, 'specialist/' ) ) return;

    $slug = substr( $path, strlen( 'specialist/' ) );
    if ( '' === $slug ) return;
    $slug = rawurldecode( $slug );

    $post = get_page_by_path( $slug, ARRAY_A, 'specialist' );
    if ( ! $post ) return;

    // Follow the chain once so a survivor that was itself merged later still lands somewhere live.
    $seen = array();
    $target = (int) $post['ID'];
    while ( $target && ! isset( $seen[ $target ] ) ) {
        $seen[ $target ] = true;
        $next = (int) get_post_meta( $target, 'ahura_duplicate_of', true );
        if ( ! $next || 'publish' !== get_post_status( $next ) ) break;
        $target = $next;
    }

    if ( $target === (int) $post['ID'] || 'publish' !== get_post_status( $target ) ) return;

    $url = get_permalink( $target );
    if ( ! $url ) return;

    wp_redirect( $url, 301 );
    exit;
}, 1 );
