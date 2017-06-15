<?php
if( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

if ( ! current_user_can( 'activate_plugins' ) ) {
	return;
}

//delete any downloads
$download_posts = get_posts( array(
	'post_type'			=> 'jabd_download',
	'posts_per_page'	=> -1
) );
if ( !empty( $download_posts ) ) {
	foreach ( $download_posts as $download_post ) {
		wp_delete_post( $download_post->ID, true );
	}
}

// remove deprecated options and usermeta
delete_option( 'jabd_notices' );
delete_metadata( 'user', 0, 'jabd_dismissed_notices', false, true );

//delete options, notices and usermeta
delete_option( 'jabd_options' );
require_once dirname( __FILE__ ) . '/incl/admin-notice-manager/class-admin-notice-manager.php';
Bulk_Attachment_Download_Admin_Notice_Manager::init( array(
	'manager_id'	=> 'jabd'
) );
Bulk_Attachment_Download_Admin_Notice_Manager::remove_all_data();