<?php

if (!defined('ABSPATH')) {
  exit;
}


add_filter('rest_pre_dispatch', function($result, $server, $request) {
    // Define your secret key (or pull from a constant/env)
    $secret_key = hram_get_option('hram_api_key', '');
    
    // Get the key from the request header
    $provided_key = $request->get_header('X-API-KEY');

    // If key doesn't match, return an error before WP processes anything else
    if ($provided_key !== $secret_key) {
        return new WP_Error(
            'rest_forbidden', 
            'Invalid or missing API Key.', 
            ['status' => 403]
        );
    }

    // Key is valid, let WordPress continue as normal
    return $result;
}, 10, 3);
