<?php
    $thelyrics = get_post_meta( $post->ID, 'lyrics-extra-field', true );
    $the_song_title = get_post_meta( $post->ID, 'the-song-title', true );
    $the_song_artist = get_post_meta( $post->ID, 'the-song-artist', true );
    $the_song_album = get_post_meta( $post->ID, 'the-song-album', true );
    $the_song_feat_artist = get_post_meta( $post->ID, 'the-song-feat-artist', true );
    $the_song_producer = get_post_meta( $post->ID, 'the-song-producer', true );
    $the_song_record_label = get_post_meta( $post->ID, 'the-song-record-label', true );
    $the_song_release =  get_post_meta( $post->ID, 'the-song-release-date', true );
    
    
    
    $args = array(
		'media_buttons' => false, // This setting removes the media button.
		'textarea_name' => 'lyrics-extra-field', // Set custom name.
		'textarea_rows' => get_option('default_post_edit_rows', 5), //Determine the number of rows.
		'quicktags' => true, // view as HTML button.
	);
    
 ?>  
    <div>
        <p>Fields marked with arterisks (*) are required.</p>
     <br>
    <div>
        <b><label for="the-song-title">Song Title: *</label><br></b>
        <input name="the-song-title" type="text" value="<?php echo $the_song_title; ?>"/>
    </div>
     <br>
    <div>
        <b><label for="the-song-artist">Main Artist: *</label><br></b>
        <input name="the-song-artist" type="text" value="<?php echo $the_song_artist; ?>"/>
    </div>
     <br>
     <div>
        <b><label for="the-song-feat-artist">Featured Artist(s):</label><br></b>
        <input name="the-song-feat-artist" type="text" value="<?php echo $the_song_feat_artist; ?>"/>
        <br><i>if more than 1 featured artists, separate with comma (,)</i>
    </div>
    <br>
    <div>
        <b><label for="the-song-artist">Album:</label><br></b>
        <input name="the-song-album" type="text" value="<?php echo $the_song_album; ?>"/>
    </div>
     <br>
    <div>
        <b><label for="the-song-producer">Producer:</label><br></b>
        <input name="the-song-producer" type="text" value="<?php echo $the_song_producer; ?>"/>
    </div>
     <br>
    <div>
        <b><label for="the-song-record-label">Band/Record Label:</label><br></b>
        <input name="the-song-record-label" type="text" value="<?php echo $the_song_record_label; ?>"/>
    </div>
    <br>
    <div>
        <b><label for="the-song-release-date">Released Date:</label><br></b>
        <input name="the-song-release-date" type="date" value="<?php echo $the_song_release; ?>"/>
    </div>
    
    <br><label for="lyrics-extra-field"><b>Add the Song Lyrics Here: </b></label>';
    <?php wp_editor(  $thelyrics, 'lyrics-extra-field', $args ); ?>
   
   </div>


