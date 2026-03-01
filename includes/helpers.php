<?php

if (!defined('ABSPATH')) {
  exit;
}

// Add thumbnail support 
function my_plugin_add_thumbnail_support() {
    add_theme_support( 'post-thumbnails' );
}
add_action( 'after_setup_theme', 'my_plugin_add_thumbnail_support' );



// Sanitize REST audio shortcodes and HTML from content
add_filter('the_content', function ($content) {

    // 1. Remove Gutenberg <figure class="wp-block-audio"> blocks
    $content = preg_replace('/<figure[^>]*class="[^"]*wp-block-audio[^"]*"[^>]*>.*?<\/figure>/is', '', $content);

    // 2. Remove all <audio> elements including nested <source> or <a>
    $content = preg_replace('/<audio\b[^>]*>.*?<\/audio>/is', '', $content);

    // 3. Remove enclosing shortcodes and anything between them: [audio]...[/audio]
    $content = preg_replace('/\[audio[^\]]*\].*?\[\/audio\]/is', '', $content);

    // 4. Remove standalone [audio] and [/audio] tags (Normal brackets)
    $content = preg_replace('/\[\/?audio[^\]]*\]/i', '', $content);

    // 5. Remove standalone [audio] and [/audio] tags (HTML Encoded brackets)
    $content = preg_replace('/&#91;\/?audio.*?&#93;/i', '', $content);

    // 6. Hard-coded fallback for the exact literal strings
    $content = str_ireplace(['[/audio]', '&#91;/audio&#93;'], '', $content);

    // 7. Clean up empty <p> tags, including those with rogue <br> tags inside
    $content = preg_replace('/<p>(?:\s|&nbsp;|<br\s*\/?>)*<\/p>/i', '', $content);

    return trim($content);

}, 20); // <-- CHANGED TO PRIORITY 20



// handle CORS for REST
add_action( 'rest_api_init', function() {
    // 1. Strip out default WordPress CORS headers to prevent duplicates
    remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

    // 2. Intercept the request and apply dynamic headers based on the origin
    add_filter( 'rest_pre_serve_request', function( $value ) {
        
        // Define your allowed origins here
        $allowed_origins = [
            'https://soundwela.ng',
            'http://localhost:3000', // Common for Next.js / React
            'http://localhost:5173', // Common for Vite / Vue
            'http://localhost:8080',  // Common for Vue CLI / Webpack
            HRAM_FRONTEND_URL
        ];

        // Get the origin of the current request
        $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';

        // If the incoming origin is in our allowed list, dynamically set it
        if ( in_array( $origin, $allowed_origins, true ) ) {
            header( 'Access-Control-Allow-Origin: ' . $origin );
        } else {
            // Fallback for safety if the origin doesn't match
            header( 'Access-Control-Allow-Origin: https://soundwela.ng' ); 
        }

        header( 'Access-Control-Allow-Methods: OPTIONS, GET, POST, PUT, PATCH, DELETE' );
        header( 'Access-Control-Allow-Credentials: true' );
        header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Api-Key' );
        header( 'Access-Control-Max-Age: 86400' );
        
        // CRITICAL: Tell caches that the response varies based on the Origin
        header( 'Vary: Origin' ); 

        // 3. Catch the preflight OPTIONS request and exit immediately with a 200 OK
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
            status_header( 200 );
            exit();
        }

        return $value;
    });
}, 15 );
