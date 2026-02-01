<?php

if (!defined('ABSPATH')) {
  exit;
}

// Add thumbnail support 
function my_plugin_add_thumbnail_support() {
    add_theme_support( 'post-thumbnails' );
}
add_action( 'after_setup_theme', 'my_plugin_add_thumbnail_support' );


//  Register menu locations
function headless_api__register_nav_menus() {
    register_nav_menus( array(
        'primary' => __( 'Headless Primary Menu', 'primary' ),
        'footer'  => __( 'Headless Footer Menu', 'footer' ),
    ) );
}
add_action( 'init', 'headless_api__register_nav_menus' );


/**
 * Get menu ID from location
 */
function headless_api_get_menu_id($location) {
  $locations = get_nav_menu_locations();
  return $locations[$location] ?? null;
}


add_filter('rest_prepare_post', function ($response) {
     
    if (empty($response->data['content']['rendered'])) {
        return $response;
    }

    $html = $response->data['content']['rendered'];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//figure[contains(@class,"wp-block-audio")]') as $node) {
        $node->parentNode->removeChild($node);
    }

    $body = $dom->getElementsByTagName('body')->item(0);
    $clean = '';
    foreach ($body->childNodes as $child) {
        $clean .= $dom->saveHTML($child);
    }

    $response->data['content']['rendered'] = $clean;

    return $response;
}, 10);


 add_filter('rest_prepare_post', 'headless_api_rest_replace_audio_shortcode', 10, 3);

function headless_api_rest_replace_audio_shortcode($response, $post, $request) {

    if (!defined('REST_REQUEST') || !REST_REQUEST) {
        return $response;
    }

    if (empty($response->data['content']['rendered'])) {
        return $response;
    }

    $audios = [];

    /* -----------------------------
       1. Extract audio from blocks
    ----------------------------- */
    $blocks = parse_blocks($post->post_content);

    extract_audio_blocks($blocks, $audios);

    /* -----------------------------
       2. Remove audio from HTML safely
    ----------------------------- */
    $html = $response->data['content']['rendered'];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//figure[contains(@class,"wp-block-audio")]') as $node) {
        $node->parentNode->removeChild($node);
    }

    $body = $dom->getElementsByTagName('body')->item(0);
    $newHtml = '';
    foreach ($body->childNodes as $child) {
        $newHtml .= $dom->saveHTML($child);
    }

    $response->data['content']['rendered'] = $newHtml;
    $response->data['audio'] = $audios;

    return $response;
}

/* -----------------------------------
   Recursive audio block extractor
----------------------------------- */
function extract_audio_blocks(array $blocks, array &$audios) {

    foreach ($blocks as $block) {

        if ($block['blockName'] === 'core/audio') {

            $src = $block['attrs']['src'] ?? null;
            $id  = $block['attrs']['id'] ?? null;

            if (!$src && $id) {
                $src = wp_get_attachment_url($id);
            }

            if (!$src) {
                continue;
            }

            $duration = null;
            $size     = null;

            if ($id) {
                $meta = wp_get_attachment_metadata($id);
                $duration = $meta['length_formatted'] ?? null;

                $file = get_attached_file($id);
                if ($file && file_exists($file)) {
                    $size = filesize($file);
                }
            }

            $audios[] = [
                'type'     => 'audio',
                'src'      => esc_url_raw($src),
                'duration' => $duration,
                'size'     => $size
            ];
        }

        //  RECURSION — THIS IS THE KEY
        if (!empty($block['innerBlocks'])) {
            extract_audio_blocks($block['innerBlocks'], $audios);
        }
    }
}



// Modify audio player link to be external parked domain 

function headless_modify_wp_audio_shortcode( $html, $atts, $audio, $post_id, $library ) {
    
 $host = HRAM_FRONTEND_URL;
 $site_url = HRAM_FRONTEND_URL;
 
 $link = $host;
 $path = parse_url($atts['mp3']);
 if(empty($path['path']))
 { $path = parse_url($atts['src']);} 
 
 $getpath = $path["path"];
 
 
 $mp3link = 'https://gospeljuice.name.ng/'.$getpath; 
 
 
 $html ='<div id="AUDIO_DOWNLOAD_SECTION"> <audio class="wp-audio-shortcode"  preload="none" style="width: 100%;" controls controlsList="nodownload" src="'.$mp3link.'" ><source type="audio/mpeg" src="'.$mp3link.'" /><a href="'.$mp3link.'">'.$mp3link.'</a></audio> </div>';
  
  
 return $html ;

}
add_filter( 'wp_audio_shortcode','headless_modify_wp_audio_shortcode', 1, 5);


// redirect frontent properly
add_action( 'template_redirect', function () {


    // Skip admin, login, REST, AJAX, cron
    if (
        is_admin() ||
        wp_doing_ajax() ||
        wp_doing_cron() ||
        ( defined( 'REST_REQUEST' ) && REST_REQUEST )
    ) {
        return;
    }

    $site_url =  wp_parse_url( site_url(), PHP_URL_HOST );
    $old_domains = [
        $site_url,
        "www.$site_url",
    ];

    $new_domain = defined('HRAM_FRONTEND_URL') ? HRAM_FRONTEND_URL : '';

    $current_host = $_SERVER['HTTP_HOST'] ?? '';

    if ( ! in_array( $current_host, $old_domains, true ) ) {
        return;
    }

    $request_uri = $_SERVER['REQUEST_URI'] ?? '';

    // Exclude WordPress core & content files
    $excluded_paths = [
        '/wp-content/',
        '/wp-includes/',
        '/wp-admin/',
        "/wp-json/"
    ];

    foreach ( $excluded_paths as $path ) {
        if ( str_starts_with( $request_uri, $path ) ) {
            return;
        }
    }

    // Exclude direct file requests (images, css, js, fonts, etc.)
    if ( preg_match( '/\.(jpg|jpeg|png|gif|webp|svg|ico|css|js|map|woff|woff2|ttf|eot|otf|pdf|zip|rar|mp4|mp3|webm)$/i', $request_uri ) ) {
        return;
    }

    // Preserve scheme, path, and query
    $scheme = is_ssl() ? 'https://' : 'http://';
    $redirect_url = $scheme . $new_domain . $request_uri;

    wp_redirect( $redirect_url, 301 );
    exit;
});


