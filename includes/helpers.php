<?php

if (!defined('ABSPATH')) {
  exit;
}

// Add thumbnail support 
function my_plugin_add_thumbnail_support() {
    add_theme_support( 'post-thumbnails' );
}
add_action( 'after_setup_theme', 'my_plugin_add_thumbnail_support' );




 //add_filter('rest_prepare_post', 'headless_api_rest_replace_audio_shortcode', 10, 3);

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

 



add_filter('rest_prepare_post', function ($response, $post, $request) {

    $content = $post->post_content;

    // 1️⃣ Remove Gutenberg audio blocks wrapped in <figure> (if any)
    $content = preg_replace(
        '/<figure[^>]*class="[^"]*wp-block-audio[^"]*"[^>]*>.*?<\/figure>/is',
        '',
        $content
    );

    // 2️⃣ Remove all <audio> tags, including nested <source> and fallback <a>
    $content = preg_replace(
        '/<audio\b[^>]*>.*?<\/audio>/is',
        '',
        $content
    );

    // 3️⃣ Remove classic [audio] shortcodes
    $content = preg_replace(
        '/\[audio[^\]]*\]/i',
        '',
        $content
    );

    // Optional: remove leftover <source> tags outside audio (rare)
    $content = preg_replace(
        '/<source[^>]*>/i',
        '',
        $content
    );

    // Optional: remove any empty <p> that might be left after audio removal
    $content = preg_replace('/<p>\s*<\/p>/i', '', $content);

    // Expose sanitized content in REST API
    $response->data['sanitized_content'] = $content;

    return $response;

}, 10, 3);
