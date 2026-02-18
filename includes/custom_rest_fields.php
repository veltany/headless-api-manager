<?php
 
 
add_action( 'rest_api_init', function () {
    register_rest_field( 'post', 'all_audio_urls', array(
        'get_callback' => 'get_post_audio_complete',
        'schema'       => null,
    ) );

     //  featured media sources field
    register_rest_field( 'post', 'featured_media_src', array(
        'get_callback' => 'get_featured_media_src',
        'schema'       => null,
    ) );

});

function get_post_audio_complete( $post ) {
    $post_id = $post['id'];
   $content = get_post_field( 'post_content', $post_id );
    
    $audio_urls = [];

    $normalize_url = function ( $url ) {
    $url = trim( $url );
     
   


    // Already absolute
    if ( preg_match( '#^https?://#i', $url ) ) {
        return esc_url_raw( $url );
    }

    // Root-relative: /wp-content/...
    if ( str_starts_with( $url, '/' ) ) {
        return esc_url_raw( home_url($url) );
    }

    return '';
};


    /**
     * Helper to push normalized audio object
     */
   $add_audio = function ( $url ) use ( &$audio_urls, $normalize_url ) {
    $normalized = $normalize_url( $url );

    if ( ! empty( $normalized ) ) {
        $audio_urls[] = $normalized;
    }
};


    // 1⃣ Gutenberg core/audio blocks
    if ( has_blocks( $content ) ) {
        $blocks = parse_blocks( $content );
        foreach ( $blocks as $block ) {
            if (
                $block['blockName'] === 'core/audio' &&
                ! empty( $block['attrs']['src'] )
            ) {
                $add_audio( $block['attrs']['src'] );
            }
        }
    }

    // 2  Shortcodes: [audio src|mp3|m4a|ogg|wav|wma]
    preg_match_all(
        '/\[audio\s+[^\]]*(?:src|mp3|m4a|ogg|wav|wma)=["\']([^"\']+)["\']/i',
        $content,
        $sc_matches
    );

    if ( ! empty( $sc_matches[1] ) ) {
        foreach ( $sc_matches[1] as $url ) {
            $add_audio( $url );
        }
    }

    // 3 HTML5 <audio> or <source> tags
    preg_match_all(
        '/<(?:audio|source)\s+[^>]*src=["\']([^"\']+)["\']/i',
        $content,
        $html_matches
    );

    if ( ! empty( $html_matches[1] ) ) {
        foreach ( $html_matches[1] as $url ) {
            $add_audio( $url );
        }
    }

    // 4 Naked audio URLs (WordPress auto-embed)
preg_match_all(
    '#(?<!["\'>])\bhttps?://[^\s<]+?\.(mp3|m4a|ogg|wav|wma)(\?[^\s<]*)?#i',
    $content,
    $url_matches
);

if ( ! empty( $url_matches[0] ) ) {
    foreach ( $url_matches[0] as $url ) {
        $add_audio( $url );
    }
}

   

    /**
     * Normalize & deduplicate
     */
    $audio_urls = array_values( array_unique( array_filter( $audio_urls ) ) );

    /**
     * Final normalized shape
     */
    return array_map( function ( $url ) {
        return [
            'src' => $url,
            "media_id" => attachment_url_to_postid( $url ),
            "media_details" => wp_get_attachment_metadata( attachment_url_to_postid( $url ) ),
        ];
    }, $audio_urls );
}

 

 
/**
 * Return structured featured media image data for REST API.
 *
 * @param array $post REST post object.
 * @return array|null
 */
function get_featured_media_src( $post ) {

    if ( empty( $post['id'] ) ) {
        return null;
    }

    $post_id = (int) $post['id'];

    if ( ! has_post_thumbnail( $post_id ) ) {
        return null;
    }

    $attachment_id = get_post_thumbnail_id( $post_id );

    if ( ! $attachment_id ) {
        return null;
    }

    // Ensure attachment exists
    $attachment = get_post( $attachment_id );
    if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
        return null;
    }

    $sizes = [];

    $registered_sizes = get_intermediate_image_sizes();
    $registered_sizes[] = 'full';

    foreach ( $registered_sizes as $size ) {

        $image = wp_get_attachment_image_src( $attachment_id, $size );

        if ( ! $image ) {
            continue;
        }

        $sizes[ $size ] = [
            'src'    => esc_url_raw( $image[0] ),
            'width'  => (int) $image[1],
            'height' => (int) $image[2],
        ];
    }

    if ( empty( $sizes ) ) {
        return null;
    }

    return [
        'media_id'   => $attachment_id,
        'alt_text'   => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
        'mime_type'  => get_post_mime_type( $attachment_id ),
        'sizes'      => $sizes,
        //'media_details' => wp_get_attachment_metadata( $attachment_id ),
    ];
}
