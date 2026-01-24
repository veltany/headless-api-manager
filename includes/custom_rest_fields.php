<?php

add_action( 'rest_api_init', function () {
    register_rest_field( 'post', 'all_audio_urls', array(
        'get_callback' => 'get_post_audio_complete',
        'schema'       => null,
    ) );
});

function get_post_audio_complete( $post ) {
    $post_id = $post['id'];
    $content = get_post_field( 'post_content', $post_id );
    $audio_urls = [];

    /**
     * Helper to push normalized audio object
     */
    $add_audio = function ( $url ) use ( &$audio_urls ) {
        $url = esc_url_raw( $url );
        if ( ! empty( $url ) ) {
            $audio_urls[] = $url;
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
        ];
    }, $audio_urls );
}
