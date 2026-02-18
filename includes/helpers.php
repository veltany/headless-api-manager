<?php

if (!defined('ABSPATH')) {
  exit;
}

// Add thumbnail support 
function my_plugin_add_thumbnail_support() {
    add_theme_support( 'post-thumbnails' );
}
add_action( 'after_setup_theme', 'my_plugin_add_thumbnail_support' );



// sanite rest audio shorcodes from content
add_filter('the_content', function ($content) {

    //  Remove Gutenberg <figure class="wp-block-audio"> blocks
    $content = preg_replace(
        '/<figure[^>]*class="[^"]*wp-block-audio[^"]*"[^>]*>.*?<\/figure>/is',
        '',
        $content
    );

    //  Remove all <audio> elements including nested <source> or <a>
    $content = preg_replace(
        '/<audio\b[^>]*>.*?<\/audio>/is',
        '',
        $content
    );

    //  Remove classic [audio] shortcodes
    $content = preg_replace(
        '/\[audio[^\]]*\]/i',
        '',
        $content
    );

    //  Remove empty <p> tags leftover
    $content = preg_replace('/<p>\s*<\/p>/i', '', $content);
    
    
    //  Remove empty <p> tags leftover
    $content = preg_replace('/<p>\s*<\/p>/i', '', $content);

    return $content;

}, 10);

