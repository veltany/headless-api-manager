<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redirect frontend requests to new domain
 */
function headless_api_frontend_redirect() {

    // Only redirect requests for the old domain
    if (
        empty($_SERVER['HTTP_HOST']) ||
        $_SERVER['HTTP_HOST'] !== 'gospeljuice.name.ng'
    ) {
        return;
    }

    $request_uri = $_SERVER['REQUEST_URI'] ?? '/';

    // ---- HARD EXCLUSIONS (must come early) ----

    // Allow REST API (ALL wp-json routes)
    if (strpos($request_uri, '/wp-json') === 0) {
        return;
    }

    // Allow admin dashboard
    if (is_admin()) {
        return;
    }

    // Allow login
    if (strpos($request_uri, '/wp-login.php') === 0) {
        return;
    }

    // Allow AJAX & cron
    if (wp_doing_ajax() || wp_doing_cron()) {
        return;
    }
    
    // Allow static content (uploads, plugins, themes)
    if (strpos($request_uri, '/wp-content/') === 0) {
        return;
    }


    // ---- REDIRECT ----

    $target_domain = 'https://gospeljuice.net';

    wp_redirect($target_domain . $request_uri, 301);
    exit;
}
