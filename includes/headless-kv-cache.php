<?php 

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
function hkvc_get($key) {
    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT cache_value, expires_at FROM " . HRAM_KV_TABLE . " WHERE cache_key=%s",
            $key
        )
    );

    if (!$row) return null;

    if (time() > intval($row->expires_at)) {
        hkvc_delete($key); // lazy cleanup
        return null;
    }

    return json_decode($row->cache_value, true);
}


/**
 * Set cache value
 */
function hkvc_set($key, $value, $ttl = 300, $tags = []) {
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
            time() + intval($ttl),
            time()
        )
    );
}


/**
 * Delete by key
 */
function hkvc_delete($key) {
    global $wpdb;
    $wpdb->delete(HRAM_KV_TABLE, ['cache_key' => $key]);
}

/**
 * Delete by tag
 */
function hkvc_delete_by_tag($tag) {
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




