<?php
/*	Song lyrics
 *
 *	Add music lyrics to song posted on the site with:
 *   
 *  Meta Form at editor page
*/

add_action("add_meta_boxes", "hram_lyrics_add_post_meta_box");
function hram_lyrics_add_post_meta_box()
{
    add_meta_box("lyrics-custom-meta-fields", "Song Lyrics And MetaData", "lyrics_post_box_markup", "post", "normal", "high", null);
}

function lyrics_post_box_markup( $post )
{
    wp_nonce_field( basename(__FILE__), "lyrics-post-additional-fields-nonce" );
    // Load the editor page form
    include( get_stylesheet_directory().'/lyrics/lyrics-markup.php' );
}



add_action("save_post", "hram_lyrics_save_post_custom_meta_fields", 10, 3);
function hram_lyrics_save_post_custom_meta_fields( $postID, $post, $update )
{
    if ( !isset($_POST["lyrics-post-additional-fields-nonce"] ) 
        || !wp_verify_nonce( $_POST["lyrics-post-additional-fields-nonce"], basename(__FILE__) ) ){
            return $postID;
    }

    if( !current_user_can( "edit_post", $postID ) ){
        return $postID;
    }

    if( defined("DOING_AUTOSAVE") && DOING_AUTOSAVE ){
        return $postID;
    }

    $thelyrics = '';
    if( isset( $_POST['lyrics-extra-field'] ) ){
        $thelyrics = $_POST['lyrics-extra-field'];
    }
    update_post_meta( $postID, 'lyrics-extra-field', $thelyrics );
    
    $thesongtitle = '';
    if( isset( $_POST['the-song-title'] ) ){
        $thesongtitle = $_POST['the-song-title'];
    }
    update_post_meta( $postID, 'the-song-title', $thesongtitle );
    
    $theartist = '';
    if( isset( $_POST['the-song-artist'] ) ){
        $theartist = $_POST['the-song-artist'];
    }
    update_post_meta( $postID, 'the-song-artist', $theartist );
    
     $thealbum = '';
    if( isset( $_POST['the-song-album'] ) ){
        $thealbum = $_POST['the-song-album'];
    }
    update_post_meta( $postID, 'the-song-album', $thealbum );
    
    $thefeatartist = '';
    if( isset( $_POST['the-song-feat-artist'] ) ){
        $thefeatartist = $_POST['the-song-feat-artist'];
    }
    update_post_meta( $postID, 'the-song-feat-artist', $thefeatartist );
    
    $theproducer = '';
    if( isset( $_POST['the-song-producer'] ) ){
        $theproducer = $_POST['the-song-producer'];
    }
    update_post_meta( $postID, 'the-song-producer', $theproducer );
    
    $thelabel = '';
    if( isset( $_POST['the-song-record-label'] ) ){
        $thelabel = $_POST['the-song-record-label'];
    }
    update_post_meta( $postID, 'the-song-record-label', $thelabel );
    
    $therelease = '';
    if( isset( $_POST['the-song-release-date'] ) ){
        $therelease = $_POST['the-song-release-date'];
    }
    update_post_meta( $postID, 'the-song-release-date', $therelease );
}


//Show the Song Lyrics If any at frontpage (Single post)
add_filter( 'the_content', 'hram_setup_the_post_lyrics', 11, 1 );
 function hram_setup_the_post_lyrics( $content ){

    if( is_single()){
        $title  = get_post_meta( get_the_id(), 'the-song-title', true );
     $artist = get_post_meta( get_the_id(), 'the-song-artist', true );
     
     
      $lyrics = get_post_meta( get_the_id(), 'lyrics-extra-field', true );
      if (!empty($lyrics)){
      $lyrics = '<div class="post-lyrics"><h3>'. $artist.' '.$title.' Lyrics:</h3>'.wpautop($lyrics).'</div>';
      $content .= $lyrics;
     }
    }
      
    return $content;
 }
 
 
 // Get audio meta data 
 function pikin_get_audio_meta($path, $key=null){
     
     // Requires the media library that unlocks the function
     require_once ABSPATH . 'wp-admin/includes/media.php';

    // Get the audio metadata
    $meta = wp_read_audio_metadata($path );
    if($key == "length"){
        return $meta['length_formatted'];
    }
    else return $meta ;
 }
 
 
 
 //Show the Song Details If any at frontpage (Single post)
 function show_the_song_details($path){
     
    $title  = get_post_meta( get_the_id(), 'the-song-title', true );
     $artist = get_post_meta( get_the_id(), 'the-song-artist', true );
     
    if( is_single() & !empty($title) & !empty($artist) ){
         
          $album  = get_post_meta( get_the_id(), 'the-song-album', true );
          $featartist = get_post_meta( get_the_id(), 'the-song-feat-artist', true );
          $producer = get_post_meta( get_the_id(), 'the-song-producer', true );
          $label = get_post_meta( get_the_id(), 'the-song-record-label', true );
          $releasedate = get_post_meta( get_the_id(), 'the-song-release-date', true );
          $length = pikin_get_audio_meta(ABSPATH.$path,"length");
          
          
          $info = '<ul style="list-style-type:none;">
 	               <li><i class="fa fa-music" aria-hidden="true"></i> <b>Song: </b>'.$title.'</li>
 	               <li><i class="fa fa-microphone" aria-hidden="true"></i> <b>Artist: </b>'.$artist.'</li>';
 	               
 	               if(!empty($featartist)){ $info .= '<li><i class="fa fa-headphones" aria-hidden="true"></i>  <b>Featuring: </b>'.$featartist.'</li> ' ; }
 	               if(!empty($album)){ $info .= '<li><i class="fa fa-plus-circle" aria-hidden="true"></i> <b>Album: </b>'.$album.'</li> ' ; }
 	               if(!empty($producer)){ $info .= '<li><i class="fa fa-podcast" aria-hidden="true"></i> <b>Producer: </b>'.$producer.'</li> ' ; }
 	               if(!empty($releasedate)){ $info .= '<li><i class="fa fa-clock-o" aria-hidden="true"></i> <b>Released: </b>'.date('F, Y',strtotime($releasedate)).'</li> ' ; }
 	               if(!empty($label)){ $info .= '<li><i class="fa fa-users" aria-hidden="true"></i> <b>Band/Label: </b>'.$label.'</li> ' ; }
 	
 	                if(!empty($length)){ $info .= '<li><i class="fa fa-clock-o" aria-hidden="true"></i> <b>Duration: </b>'.$length.'</li> ' ; }
 	 
 
      
       $info .= '</ul>';
      
      $info = '<div class="the-song-info">'.$info.'</div>';
      return $info;
    }
 }
 
 
 
 
 
// Setup Schema at frontpage <head>
 function hram_setup_song_schema() {
     
     $title  = get_post_meta( get_the_id(), 'the-song-title', true );
     $artist = get_post_meta( get_the_id(), 'the-song-artist', true );
     
     if( is_single() & !empty($title) & !empty($artist) )
     {
          $lyrics = get_post_meta( get_the_id(), 'lyrics-extra-field', true );
          $album  = get_post_meta( get_the_id(), 'the-song-album', true );
          $featartist = get_post_meta( get_the_id(), 'the-song-feat-artist', true );
          $producer = get_post_meta( get_the_id(), 'the-song-producer', true );
          $label = get_post_meta( get_the_id(), 'the-song-record-label', true );
          $url   = get_permalink(get_the_id());
          $author = get_the_author_meta(get_the_id());
          $releasedate = get_post_meta( get_the_id(), 'the-song-release-date', true );
          $featured_image = get_the_post_thumbnail_url(get_the_id());
          
          //Sanitize lyrics text 
          $lyrics = strip_tags($lyrics);
          
          if(empty($releasedate))
          { $releasedate = get_the_date(); }
          
          $schema = '{
  "@context": "https://schema.org",
  "@type": "MusicComposition",
  "name": "'.$title.'",
  "composer": {
    "@type": "Person",
    "name": "'.$artist.'"
  },
   "inAlbum": {
      "@type": "MusicAlbum",
      "name": "'.$album.'",
      "byArtist": {
        "@type": "MusicGroup",
        "name": "'.$artist.'"
      }
      },
  "lyricist": {
    "@type": "Person",
    "name": "'.$author.'"
  },
  "datePublished": "'.$releasedate.'",
  "inLanguage": "",
  "iswcCode": "",
  "url": "'.$url.'",
  "lyrics": {
    "@type": "CreativeWork",
    "text": "'.$lyrics.'",
    "inLanguage": ""
  },
  "recordingOf": {
    "@type": "MusicRecording",
    "name": "'.$title.'",
    "byArtist": {
      "@type": "MusicGroup",
      "name": "'.$artist.', '.$featartist.'"
    },
    "duration": "",
    "recordLabel": {
      "@type": "Organization",
      "name": "'.$label.'"
    },
    "url": "'.$url.'",
    "image": "'.$featured_image.'"
  }
}
' ;
          
          
   echo '<script type="application/ld+json">'.$schema.'</script>';
    
     }
}
// Add hook for front-end <head></head>
add_action( 'wp_head', 'hram_setup_song_schema' );
 
 
 
 


