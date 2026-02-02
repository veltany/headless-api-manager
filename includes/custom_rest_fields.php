<?php

add_action( 'rest_api_init', function () {
    register_rest_field( 'post', 'all_audio_urls', array(
        'get_callback' => 'get_post_audio_complete',
        'schema'       => null,
    ) );
});

function get_post_audio_complete( $post ) {
    $post_id = $post['id'];
   $raw_content = get_post_field( 'post_content', $post_id );
   $content     = apply_filters( 'the_content', $raw_content );

    $audio_urls = [];

    $normalize_url = function ( $url ) {
    $url = trim( $url );

    // Already absolute
    if ( preg_match( '#^https?://#i', $url ) ) {
        return esc_url_raw( $url );
    }

    // Root-relative: /wp-content/...
    if ( str_starts_with( $url, '/' ) ) {
        return esc_url_raw( home_url( $url ) );
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
