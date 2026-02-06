<?php

if (!defined('ABSPATH')) {
  exit;
}

// Add thumbnail support 
function my_plugin_add_thumbnail_support() {
    add_theme_support( 'post-thumbnails' );
}
add_action( 'after_setup_theme', 'my_plugin_add_thumbnail_support' );




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

 

/* ======================================================
 * REST: orderby=most_tags (tag slugs)
 * ====================================================== */
namespace Fayaz\Dev;

use WP_Query;
use WP_REST_Request;

/**
 * Register orderby=most_tags for REST
 */
add_filter( 'rest_post_collection_params', function ( $params ) {

    $params['orderby']['enum'][] = 'most_tags';

    return $params;
} );

/**
 * Translate REST request → WP_Query vars
 */
add_filter( 'rest_post_query', function ( array $args, WP_REST_Request $request ) {

    if ( $request->get_param( 'orderby' ) !== 'most_tags' ) {
        return $args;
    }

    $tags = $request->get_param( 'tags' ); // tag IDs (native REST param)

    if ( empty( $tags ) ) {
        return $args;
    }

    $args['tag__in']             = array_map( 'intval', (array) $tags );
    $args['order_by_most_tags']  = true;

    return $args;

}, 10, 2 );

/**
 * Hook into query before execution
 */
add_action( 'pre_get_posts', function ( WP_Query $query ) {

    if (
        ! defined( 'REST_REQUEST' ) ||
        ! REST_REQUEST ||
        ! $query->get( 'order_by_most_tags' ) ||
        empty( $query->get( 'tag__in' ) )
    ) {
        return;
    }

    add_filter( 'posts_clauses', __NAMESPACE__ . '\add_most_tags_sql', 10, 2 );
} );

/**
 * SQL: count DISTINCT matching tag IDs
 */
function add_most_tags_sql( array $clauses, WP_Query $query ) {
    global $wpdb;

    $tag_ids = array_map( 'intval', (array) $query->get( 'tag__in' ) );

    if ( empty( $tag_ids ) ) {
        return $clauses;
    }

    $placeholders = implode( ',', array_fill( 0, count( $tag_ids ), '%d' ) );
    $prepared_in  = $wpdb->prepare( $placeholders, ...$tag_ids );

    $clauses['join'] .= "
        LEFT JOIN {$wpdb->term_relationships} tr_mt
            ON {$wpdb->posts}.ID = tr_mt.object_id
        LEFT JOIN {$wpdb->term_taxonomy} tt_mt
            ON tr_mt.term_taxonomy_id = tt_mt.term_taxonomy_id
            AND tt_mt.taxonomy = 'post_tag'
            AND tt_mt.term_id IN ($prepared_in)
    ";

    $clauses['fields'] .= ",
        COUNT(DISTINCT tt_mt.term_id) AS most_tags_count
    ";

    // Preserve existing GROUP BY
    if ( empty( $clauses['groupby'] ) ) {
        $clauses['groupby'] = "{$wpdb->posts}.ID";
    } elseif ( strpos( $clauses['groupby'], "{$wpdb->posts}.ID" ) === false ) {
        $clauses['groupby'] .= ", {$wpdb->posts}.ID";
    }

    $clauses['orderby'] = "most_tags_count DESC, {$wpdb->posts}.post_date DESC";

    remove_filter( 'posts_clauses', __NAMESPACE__ . '\add_most_tags_sql', 10 );

    return $clauses;
}
