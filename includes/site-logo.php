<?php

if (!defined('ABSPATH')) {
  exit;
}

 
/**
 * Register REST namespace
 */
add_action('rest_api_init', function () {
  register_rest_route(HRAM_API_ROUTE, '/site-logo', [
    'methods'  => 'GET',
    'callback' => 'headless_api_get_site_logo',
    'permission_callback' => '__return_true',
  ]);
}); 




function headless_api_get_site_logo() {
  $cache_key = 'headless_api_site_logo';
  $cached = get_transient($cache_key);

  if ($cached !== false) {
    return $cached;
  }

  $logo_id = get_theme_mod('custom_logo');
  if (!$logo_id) {
    return null;
  }

  $logo = wp_get_attachment_image_src($logo_id, 'full');
  $url = $logo ? $logo[0] : null;

  set_transient($cache_key, $url, DAY_IN_SECONDS);

  return $url;
}
