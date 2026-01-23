<?php

// ================================
// INTERNAL TAG VERSIONING HELPERS
// ================================

function hkvc_tag_version_key($tag) {
    return "hkvc:tagver:" . md5($tag);
}

function hkvc_get_tag_version($tag) {
    $key = hkvc_tag_version_key($tag);

    if (wp_using_ext_object_cache()) {
        $v = wp_cache_get($key, 'hkvc');
        if ($v !== false) return (int)$v;

        wp_cache_set($key, 1, 'hkvc');
        return 1;
    }

    // DB fallback
    $v = hkvc_get($key, 'hkvc_tags');
    if ($v !== null) return (int)$v;

    hkvc_set($key, 1, DAY_IN_SECONDS * 30);
    return 1;
}

function hkvc_bump_tag_version($tag) {
    $key = hkvc_tag_version_key($tag);

    if (wp_using_ext_object_cache()) {
        $v = wp_cache_get($key, 'hkvc');
        $v = ($v === false) ? 2 : $v + 1;
        wp_cache_set($key, $v, 'hkvc');
        return;
    }

    $v = hkvc_get($key, 'hkvc_tags');
    hkvc_set($key, ($v ?? 1) + 1, DAY_IN_SECONDS * 30);
}

function hkvc_build_key($key, $group) {
    return 'wp:' . get_current_blog_id() . ':' . $group . ':' . $key;
}




// Register route
add_action('rest_api_init', function () {
    register_rest_route(HRAM_API_ROUTE, '/kv/get', [
        'methods' => 'GET',
        'callback' => function ($req) {
            $key = sanitize_text_field($req->get_param('key'));
            if (!$key) return ['hit' => false];

            $value = hkvc_get($key);
            if ($value === null) return ['hit' => false];

            return [
                'hit' => true,
                'data' => $value
            ];
        },
        'permission_callback' => '__return_true',
    ]);
});

// set cache
add_action('rest_api_init', function () {
    register_rest_route(HRAM_API_ROUTE, '/kv/set', [
        'methods' => 'POST',
        'callback' => function ($req) {
            $key = sanitize_text_field($req->get_param('key'));
            $data = $req->get_param('data');
            $ttl  = intval($req->get_param('ttl') ?? 300);
            $tags = $req->get_param('tags') ?? [];

            if (!$key || $data === null) {
                return ['stored' => false];
            }

            hkvc_set($key, $data, $ttl, $tags);
            return ['stored' => true];
        },
        'permission_callback' => '__return_true',
    ]);
});




/**
 * Get cache value
 */
function hkvc_get($key, $group = 'hkvc') {

    $tag_version = hkvc_get_tag_version($group);
    $key = hkvc_build_key("v{$tag_version}:{$key}", $group);

    if (wp_using_ext_object_cache()) {
        $value = wp_cache_get($key, $group);
        return $value === false ? null : $value;
    }

    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT cache_value, expires_at FROM " . HRAM_KV_TABLE . " WHERE cache_key=%s",
            $key
        )
    );

    if (!$row) return null;

    if (time() > (int)$row->expires_at) {
        hkvc_delete($key);
        return null;
    }

    return json_decode($row->cache_value, true);
}


/**
 * Set cache value
 */
function hkvc_set($key, $value, $ttl = 300, $tags = []) {

    $group = 'hkvc';
    $tag_version = hkvc_get_tag_version($group);
    $key = hkvc_build_key("v{$tag_version}:{$key}", $group);

    if (wp_using_ext_object_cache()) {
        wp_cache_set($key, $value, $group, $ttl);
        return;
    }

    global $wpdb;

    $wpdb->query(
        $wpdb->prepare(
            "
            INSERT INTO " . HRAM_KV_TABLE . "
            (cache_key, cache_value, cache_tags, expires_at, updated_at)
            VALUES (%s, %s, %s, %d, %d)
            ON DUPLICATE KEY UPDATE
                cache_value=VALUES(cache_value),
                cache_tags=VALUES(cache_tags),
                expires_at=VALUES(expires_at),
                updated_at=VALUES(updated_at)
            ",
            $key,
            wp_json_encode($value),
            wp_json_encode($tags),
            time() + (int)$ttl,
            time()
        )
    );
}


/**
 * Delete by key
 */
function hkvc_delete($key) {
    $group = 'hkvc';
    $tag_version = hkvc_get_tag_version($group);
    $key = hkvc_build_key("v{$tag_version}:{$key}", $group);

    if (wp_using_ext_object_cache()) {
        wp_cache_delete($key, $group);
        return;
    }

    global $wpdb;
    $wpdb->delete(HRAM_KV_TABLE, ['cache_key' => $key]);
}


/**
 * Delete by tag
 */
function hkvc_delete_by_tag($tag) {
    // Object cache-safe invalidation
    hkvc_bump_tag_version($tag);

    // DB cleanup is optional but keeps table lean
    global $wpdb;
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM " . HRAM_KV_TABLE . " WHERE cache_tags LIKE %s",
            '%' . $wpdb->esc_like($tag) . '%'
        )
    );
}




// Automatic cache invalidation on content changes
add_action('save_post', function ($post_id) {
    if (wp_is_post_revision($post_id)) return;

    // Global content changed
    hkvc_delete_by_tag('global');

    // Post-specific
    hkvc_delete_by_tag('post:' . $post_id);

}, 10);

add_action('edited_terms', function () {
    hkvc_delete_by_tag('taxonomy');
});

// Clear cache when menus or logo change:
add_action('wp_update_nav_menu', function () {
  delete_transient('headless_api_menu_primary');
  delete_transient('headless_api_menu_footer');
  hkvc_delete_by_tag('menu');
});

add_action('customize_save_after', function () {
  delete_transient('headless_api_site_logo');
    hkvc_delete_by_tag('site-logo');
});


 //------------------
 add_action('hram_kv_cleanup', 'hkvc_cleanup_expired_cache');
function hkvc_cleanup_expired_cache() {
    global $wpdb;

    $now = time();

    // Delete expired cache entries
    $deleted = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM " . HRAM_KV_TABLE . " WHERE expires_at < %d",
            $now
        )
    );

    if ($deleted !== false && $deleted > 0) {
        hram_log("KV cleanup: removed {$deleted} expired cache rows.");
    }
}

//------------------
function hkvc_install_object_cache() {
    $wp_content = WP_CONTENT_DIR;
    $target     = $wp_content . '/object-cache.php';
    $backup     = $wp_content . '/object-cache.php.bak';
    $source     = plugin_dir_path(__FILE__) . 'object-cache.php';

    // Ensure source exists
    if (!file_exists($source)) {
        return;
    }

    // Backup existing object cache
    if (file_exists($target) && !file_exists($backup)) {
        rename($target, $backup);
    }

    // Copy our object cache
    copy($source, $target);

    update_option('hkvc_object_cache_installed', true);
}


function hkvc_uninstall_object_cache() {
    $wp_content = WP_CONTENT_DIR;
    $target     = $wp_content . '/object-cache.php';
    $backup     = $wp_content . '/object-cache.php.bak';

    // Remove our cache
    if (file_exists($target)) {
        unlink($target);
    }

    // Restore previous cache if exists
    if (file_exists($backup)) {
        rename($backup, $target);
    }

    delete_option('hkvc_object_cache_installed');
}


