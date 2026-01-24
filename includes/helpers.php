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


// logging
//Temporary logging
function headless_log($message)
{
$pluginlog = plugin_dir_path(__FILE__).'debug.log';
$message.= "\n";
error_log($message, 3, $pluginlog);
}



/**
 * Get menu ID from location
 */
function headless_api_get_menu_id($location) {
  $locations = get_nav_menu_locations();
  return $locations[$location] ?? null;
}





// Add CORS support
// KEEP THIS - This is the correct way for Headless WP
// add_action('rest_api_init', function () {
//     remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
//     add_filter('rest_pre_serve_request', 'headless_api_send_cors_headers', 15, 4);
// });

function headless_api_send_cors_headers($served, $result, $request, $server) {
    $allowed_origins = [
        defined('HRAM_FRONTEND_URL') ? HRAM_FRONTEND_URL : '',
        'http://localhost:3000',
        "https://gospeljuice.net",
        "https://gospeljuice.name.ng",
        "http://gospeljuice.name.ng"
    ];

    $origin = get_http_origin(); 

    if (in_array($origin, $allowed_origins)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Requested-With');
        header('Vary: Origin'); 
    }

    if ('OPTIONS' === $_SERVER['REQUEST_METHOD']) {
        status_header(200);
        exit;
    }

    return $served;
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


