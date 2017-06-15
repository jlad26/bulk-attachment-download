<?php
// Template file for custom post type jabd_download

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

// provide file to download if permissions checks passed
global $post;
if ( jabd_user_can_download( $post ) ) {
	
	$file_path = JABD_PLUGIN_DIR.JABD_DOWNLOADS_DIR.'/'.get_post_meta( $post->ID, 'jabd_path', true );
	if( file_exists( $file_path ) ) {
		header( 'Content-type: application/zip' );
		header( 'Content-Disposition: attachment; filename="'.wp_basename( $file_path ).'"' );
		header( 'Content-Length: '.filesize( $file_path ) );
		@readfile( $file_path );
		exit;
	} else { // 404 if file doesn't exist
		jabd_404_redirect();
	}
	
} else { // 404 if permissions failed
	jabd_404_redirect();
}