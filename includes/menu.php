<?php

if (!defined('ABSPATH')) {
  exit;
}


/**
 * Get menu ID from location
 */
function headless_api_get_menu_id($location) {
  $locations = get_nav_menu_locations();
  return $locations[$location] ?? null;
}


//  Register menu locations
function headless_api__register_nav_menus() {
    register_nav_menus( array(
        'primary' => __( 'Headless Primary Menu', 'primary' ),
        'footer'  => __( 'Headless Footer Menu', 'footer' ),
        'footer-note-links'  => __( 'Headless Footer Note Links', 'footer-note-links' ),
    ) );
}
add_action( 'init', 'headless_api__register_nav_menus' );



/**
 * Register REST namespace
 */
add_action('rest_api_init', function () {
  register_rest_route(HRAM_API_ROUTE, '/menu', [
    'methods'  => 'GET',
    'callback' => 'headless_api_get_menu',
    'permission_callback' => '__return_true',
  ]);

}); 


function headless_api_get_menu(WP_REST_Request $request) {
  $location = $request->get_param('location') ?: 'primary';

  $menu_id = headless_api_get_menu_id($location);
  if (!$menu_id) {
    return [];
  }

  // Cache result
  $cache_key = "headless_api_menu_{$location}";
  $cached = get_transient($cache_key);
  if ($cached !== false) {
    return $cached;
  }

  $items = wp_get_nav_menu_items($menu_id);
  if (!$items) {
    return [];
  }

  $menu = [];

  foreach ($items as $item) {
    $menu[$item->ID] = [
      'id'       => $item->ID,
      'title'    => $item->title,
      'url'      => $item->url,
      'slug'     => sanitize_title($item->title),
      'parent'   => (int) $item->menu_item_parent,
      'children' => [],
      'order'    => (int) $item->menu_order,
    ];
  }

  // Build tree
  $tree = [];
  foreach ($menu as $id => &$node) {
    if ($node['parent'] && isset($menu[$node['parent']])) {
      $menu[$node['parent']]['children'][] = &$node;
    } else {
      $tree[] = &$node;
    }
  }

  set_transient($cache_key, $tree, HOUR_IN_SECONDS);

  return $tree;
}
