<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redirect frontend requests to new domain
 */
add_action('template_redirect', function () {

    // Do not redirect if:
    if (
        is_admin() ||                       // wp-admin
        wp_doing_ajax() ||                  // admin-ajax
        wp_doing_cron() ||                  // cron
        defined('REST_REQUEST') && REST_REQUEST ||  // REST API
        strpos($_SERVER['REQUEST_URI'], '/wp-json/') === 0 ||
        strpos($_SERVER['REQUEST_URI'], '/wp-login.php') === 0
    ) {
        return;
    }

    // Prevent redirect loop if already on target domain
    if ($_SERVER['HTTP_HOST'] === HRAM_FRONTEND_URL ) {
        return;
    }

    // Preserve request URI
    $request_uri = $_SERVER['REQUEST_URI'];

    $new_url = HRAM_FRONTEND_URL . $request_uri;

    wp_redirect($new_url, 301);
    exit;
});