<?php
// Template file for custom post type jabd_download

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Redirects to 404 (called if download file does not exist or user does not have permission to download).
 */
function jabd_404_redirect() {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	get_template_part( 404 );
	exit();
}

// provide file to download if permissions checks passed
global $post;
if ( current_user_can( 'edit_post', $post->ID ) ) {
	
	$file_path = JABD_UPLOADS_DIR.JABD_DOWNLOADS_DIR.'/'.get_post_meta( $post->ID, 'jabd_path', true );
	if( file_exists( $file_path ) ) {
		header( 'Content-type: application/zip' );
		header( 'Content-Disposition: attachment; filename="'.wp_basename( $file_path ).'"' );
		header( 'Content-Length: '.filesize( $file_path ) );
		@readfile( $file_path );
		jabd_increment_download_count();
		exit;
	} else { // 404 if file doesn't exist
		jabd_404_redirect();
	}
	
} else { // 404 if permissions failed
	jabd_404_redirect();
}