<?php

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/*---------------------------------------------------------------------------------------------------------*/
/*Setup*/

/**
 * Internationalization
 */
function jabd_load_plugin_textdomain() {
    load_plugin_textdomain( 'st-bulk-download', FALSE, basename( JABD_PLUGIN_DIR ) . '/languages/' );
}

/*---------------------------------------------------------------------------------------------------------*/
/*Add admin js and css*/

/**
 * Load admin js and css.
 */
function jabd_admin_enqueue_scripts( $hook ) {
	
	global $post;
	
	if ( 'upload.php' == $hook ) { //if we are on media library
		
		// JS for handling download creation on Media library
		wp_enqueue_script( 'jabd-admin-upload-js', JABD_PLUGIN_BASE_URL.'js/admin-upload.js', array( 'jquery' ), '1.0.0' );

		$localization_array = array(
			'download_option' 			=> __( 'Download', 'st-bulk-download' ),
			'download_launched_msg'		=> __( 'Please wait, your download is being created.', 'st-bulk-download' ),
			'gathering_data_msg'		=> __( 'Gathering data...', 'st-bulk-download' ),
			'download_nonce'			=> wp_create_nonce( 'download-request-'.get_current_user_id() ),
		);
		wp_localize_script( 'jabd-admin-upload-js', 'jabd_downloader', $localization_array );
		
		// CSS for handling download creation on Media library
		wp_enqueue_style(
			'jabd-admin-upload-css',
			JABD_PLUGIN_BASE_URL.'css/upload-style.css'
		);
		
	} elseif (
		( 'edit.php' == $hook && 'jabd_download' == get_query_var('post_type') ) || // we are listing download posts
		( 'post.php' == $hook && 'jabd_download' == $post->post_type ) //we are editing a download
	) {
		
		// CSS for styling list of downloads
		wp_enqueue_style(
			'jabd-admin-downloads-css',
			JABD_PLUGIN_BASE_URL.'css/downloads-style.css'
		);
		
	}
}

/*---------------------------------------------------------------------------------------------------------*/
/*Plugin activation, deactivation and upgrade*/

/**
 * Plugin activation.
 */
function jabd_on_activation() {
	
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : '';
	check_admin_referer( 'activate-plugin_' . $plugin );
	
	// add hourly cron event used to delete expired downloads
	wp_schedule_event( time(), 'hourly', 'jabd_hourly_event' );
	
	// register our custom post type and flush rewrite rules
	jabd_register_download_post_type();
	flush_rewrite_rules();

}

/**
 * Plugin deactivation.
 */
function jabd_on_deactivation() {
	
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : '';
	check_admin_referer( 'deactivate-plugin_' . $plugin );
	
	// remove hourly event
	wp_clear_scheduled_hook( 'jabd_hourly_event' );
	
}

/**
 * Delete expired download posts.
 * @hooked jabd_hourly_event
 */
function jabd_delete_download_posts() {
	$download_posts = get_posts( array(
		'post_type'			=> 'jabd_download',
		'posts_per_page'	=> -1,
		'post_status'		=> 'all'
	) );
	if ( ! empty( $download_posts ) ) {
		date_default_timezone_set('UTC');
		$now = time();
		foreach( $download_posts as $download_post ) {
			if ( strtotime( get_post_meta( $download_post->ID, 'jabd_expiry', true ) ) < $now ) {
				$zip_path = get_post_meta( $download_post->ID, 'jabd_path', true );
				if ( file_exists( $zip_path ) ) {
					@unlink($zip_path);
				}
				wp_delete_post( $download_post->ID, true );
			}
		}
	}
}
add_action( 'jabd_hourly_event', 'jabd_delete_download_posts' );

/**
 * Run upgrade processes on upgrade.
 * @hooked plugins_loaded
 */
function jabd_check_version() {
	$prev_version = get_option( 'jabd_version' );
	if ( JABD_VERSION !== $prev_version ) {
		update_option( 'jabd_version', JABD_VERSION );
		add_action( 'admin_init', 'jabd_on_plugin_upgrade', $prev_version );
	}
}

/**
 * Processes to be run on upgrade.
 */
function jabd_on_plugin_upgrade( $prev_version ) {
	if ( empty( $prev_version ) ) {
		$prev_version = 0;
	}
	$current_version = intval( str_replace( '.', '', JABD_VERSION ) );
	
	// If 1.1.4 or earlier (prior to storing of version)...
	if ( ! $prev_version ) {
		// Remove deprecated options and usermeta from db
		delete_option( 'jabd_notices' );
		delete_metadata( 'user', 0, 'jabd_dismissed_notices', false, true );
	}
	
}

/*---------------------------------------------------------------------------------------------------------*/
/*Plugin settings*/

/**
 * Initialize plugin settings.
 * @hooked admin_init
 */
function jabd_init_settings() {
	
	// register a new setting for "Media" page
    register_setting( 'media', 'jabd_options', 'jabd_sanitize_options' );
	
	// register a new section in the "Media" page
	add_settings_section(
		'jabd_settings_section',
		__( 'Bulk Attachment Download', 'st-bulk-download' ),
		null,
		'media'
	);
	
	// register fields in the "jabd_settings_section" section, inside the "Media" page
	add_settings_field(
		'jabd_max_size',
		__( 'Max uncompressed file size', 'st-bulk-download' ),
		'jabd_max_size_input',
		'media',
		'jabd_settings_section',
		array(
			'label_for'	=> 'jabd_max_size',
			'class'	=> 'jabd_row',
		)
    );
	
	add_settings_field(
		'jabd_int_sizes',
		__( 'Include intermediate sizes by default', 'st-bulk-download' ),
		'jabd_int_sizes_default_cb',
		'media',
		'jabd_settings_section',
		array(
			'label_for'	=> 'jabd_int_sizes',
			'class'	=> 'jabd_row',
		)
    );
	
	add_settings_field(
		'jabd_no_folders',
		__( 'Default single folder download', 'st-bulk-download' ),
		'jabd_single_folder_default_cb',
		'media',
		'jabd_settings_section',
		array(
			'label_for'	=> 'jabd_no_folders',
			'class'	=> 'jabd_row',
		)
    );

	add_settings_field(
		'jabd_secure_downloads',
		__( 'Make downloads secure', 'st-bulk-download' ),
		'jabd_secure_downloads_cb',
		'media',
		'jabd_settings_section',
		array(
			'label_for'	=> 'jabd_secure_downloads',
			'class'	=> 'jabd_row',
		)
    );

}

/**
 * Output the max uncompressed file size settings field
 */
function jabd_max_size_input( $args ) {
	$options = get_option( 'jabd_options' );
	$option = 100;
	if ( isset( $options['jabd_max_size'] ) ) {
		if ( $options['jabd_max_size'] > 0 ) {
			$option = $options['jabd_max_size'];
		}
	}
	?>
	<input type="text" size="2" name="jabd_options[<?php echo $args['label_for']; ?>]" id="<?php echo $args['label_for']; ?>" value="<?php echo esc_attr( $option ); ?>" /> MB
	<p class="description"><?php _e( 'Set a limit for the maximum uncompressed file size to be created as a downloadable zip.', 'st-bulk-download' ); ?></p>
	<?php
}

/**
 * Output the default intermediate sizes settings field
 */
function jabd_int_sizes_default_cb( $args ) {
	$options = get_option( 'jabd_options' );
	$option = isset( $options['jabd_int_sizes'] ) ? $options['jabd_int_sizes'] : 0;
	?>
	<input style="margin-top: 6px" type="checkbox" name="jabd_options[<?php echo $args['label_for']; ?>]" id="<?php echo $args['label_for']; ?>" value="1" <?php checked( $option ); ?> />
	<p class="description"><?php _e( 'Check the box if you want to download all intermediate images sizes by default. (This can be changed for each download.)', 'st-bulk-download' ); ?></p>
	<?php
}

/**
 * Output the default single folder settings field
 */
function jabd_single_folder_default_cb( $args ) {
	$options = get_option( 'jabd_options' );
	$option = isset( $options['jabd_no_folders'] ) ? $options['jabd_no_folders'] : 0;
	?>
	<input style="margin-top: 6px" type="checkbox" name="jabd_options[<?php echo $args['label_for']; ?>]" id="<?php echo $args['label_for']; ?>" value="1" <?php checked( $option ); ?> />
	<p class="description"><?php _e( 'Check the box if you want the zip file to include all files in a single folder by default. (This can be changed for each download.)', 'st-bulk-download' ); ?></p>
	<?php
}

/**
 * Output the secure download settings field
 */
function jabd_secure_downloads_cb( $args ) {
	$options = get_option( 'jabd_options' );
	$option = isset( $options['jabd_secure_downloads'] ) ? $options['jabd_secure_downloads'] : 0;
	?>
	<input style="margin-top: 6px" type="checkbox" name="jabd_options[<?php echo $args['label_for']; ?>]" id="<?php echo $args['label_for']; ?>" value="1" <?php checked( $option ); ?> />
	<p class="description"><?php _e( 'Choose whether or not to prevent others accessing downloads while they are (temporarily) stored on the server. There\'s no point doing this unless you are somehow also protecting access to the files in your Uploads folder.', 'st-bulk-download' ); ?></p>
	<?php
}

/**
 * Sanitize the settings before saving
 */
function jabd_sanitize_options( $settings ) {
	
	if ( !empty( $settings ) ) {
		foreach ( $settings as $key => $setting ) {
			if ( 'jabd_max_size' == $key ) {
				$setting = $setting + 0; //convert to a number
				// is posted data is a float or int and is greater than 0
				if (
					( is_int( $setting ) || is_float( $setting ) ) &&
					$setting > 0
				) {
					$settings[$key] = $setting;
				} else { // otherwise set to whatever was set before and default of 100 if not set
					$options = get_option( 'jabd_options' );
					$settings[$key] = isset( $options['jabd_max_size'] ) ? $options['jabd_max_size'] : 100;
				}
			} else {
				$value = intval( $setting );
				$settings[$key] = $value ? 1 : 0;
			}
		}
	}

	return $settings;
}

/**
 * Add / remove htaccess file as necessary when "Make downloads secure" option is updated
 * @hooked pre_update_option_jabd_options
 */
function jabd_before_options_update( $value, $old_value, $option ) {

	if ( current_user_can('manage_options') ) { // make sure user is administrator
		
		$old_setting = isset( $old_value['jabd_secure_downloads'] ) ? $old_value['jabd_secure_downloads'] : '';
		$new_setting = isset( $value['jabd_secure_downloads'] ) ? $value['jabd_secure_downloads'] : '';
		
		if ( $old_setting != $new_setting ) { // if the option has been changed
			
			$htaccess_path = JABD_PLUGIN_DIR.JABD_DOWNLOADS_DIR.'/.htaccess';
			
			if ( $new_setting ) { // if we need a .htaccess file
				
				// create / over-write htaccess file
				$htaccess = @fopen($htaccess_path, 'w');
				if ( ! $htaccess ) {
					$args = array(
						'id'		=>	'no_htaccess_update',
						'message'	=>	__( 'Bulk downloads settings have not been updated. The .htaccess file preventing direct access to your downloads could not be created. This may be an issue with the way permissions are set on your server.', 'st-bulk-download' ),
						'screen_ids'	=>	array( 'options-media' )
					);
					Bulk_Attachment_Download_Admin_Notice_Manager::add_notice( $args );
					$value = $old_value; // reset the options to old value to prevent update
				} else {
					fwrite( $htaccess, "Order Deny,Allow\nDeny from all" );
					fclose( $htaccess );
					if ( ! @chmod( $htaccess_path, 0644 ) ) { // set permissions and give warning if permissions cannot be set
						$disp_htaccess_path = str_replace( '\\', '/', str_replace( trim( ABSPATH, '/' ), '', JABD_PLUGIN_DIR.JABD_DOWNLOADS_DIR.'/.htaccess' ) );
						$args = array(
							'id'			=>	'htaccess_permissions_error',
							/* translators: Filepath to .htaccess file */
							'message'		=>	sprintf( __( 'Bulk Attachment Download: The .htaccess file has been created to prevent access to downloads.  However the plugin could not confirm that permissions have been correctly set on the .htaccess file itself, which is a security risk. Please confirm that permissions on the file have been set to 0644 - it can be found at %s.', 'st-bulk-download' ), $disp_htaccess_path ),
							'type'			=>	'warning',
							'screen_ids'	=>	array( 'options-media' ),
							'persistent'	=>	true,
						);
						Bulk_Attachment_Download_Admin_Notice_Manager::add_notice( $args );
					} else {
						Bulk_Attachment_Download_Admin_Notice_Manager::delete_added_notice_from_all_users( 'htaccess_permissions_error' );
					}
				}
			
			} else { // we are removing the .htaccess file
				if ( file_exists( $htaccess_path ) ) {
					if ( ! @unlink( $htaccess_path ) ) {
						$disp_htaccess_path = str_replace( '\\', '/', str_replace( trim( ABSPATH, '/' ), '', $htaccess_path ) );
						$args = array(
							'id'		=>	'no_htaccess_delete',
							/* translators: Filepath to .htaccess file */
							'message'	=>	sprintf( __( 'Bulk Attachment Download: The .htaccess file preventing direct access to your downloads could not be deleted. Please delete the file manually and then unset the %1$s setting again. The file can be found at %2$s. Alternatively you may uninstall and re-install the plugin.', 'st-bulk-download' ), __( 'Make downloads secure', 'st-bulk-download' ), $disp_htaccess_path ),
							'screen_ids'	=>	array( 'options-media' ),
							'persistent'		=>	true
						);
						Bulk_Attachment_Download_Admin_Notice_Manager::add_notice( $args );
						$value = $old_value; // reset the options to old value to prevent update
					}
				} else {
					Bulk_Attachment_Download_Admin_Notice_Manager::delete_added_notice_from_all_users( 'no_htaccess_delete' );
				}
			}
		}
		
	}
	
	return $value;
}

/*---------------------------------------------------------------------------------------------------------*/
/*Admin notices*/

/**
 * Add opt out admin notices
 * @hooked admin_init
 */
function jabd_add_opt_out_notices() {
	
	$list_mode_html = Bulk_Attachment_Download_Admin_Notice_Manager::dismiss_on_redirect_link( array(
		'redirect'	=>	admin_url( 'upload.php?mode=list' ),
		'content'	=>	_x( 'list mode', 'text for link to switch media mode', 'st-bulk-download' )
	) );
	
	$opt_out_notices = array(
		'number_of_media_items'	=>	array(
			'message'			=>	'<strong>Bulk Attachment Download:</strong> ' . __( 'Don\'t forget you can change the number of media items per page (up to 999) by going to Screen Options at the top right of the screen.', 'st-bulk-download' ),
			'type'				=>	'info',
			'screen_ids'		=>	array( 'upload' ),
			'persistent'		=>	true,
			'no_js_dismissable'	=>	true
		),
		'switch_to_list_mode'	=>	array(
			/* translators: link to switch to list mode */
			'message'			=>	'<strong>Bulk Attachment Download:</strong> ' . sprintf( __( 'To use bulk download, switch to %s.', 'st-bulk-download' ), $list_mode_html ),
			'type'				=>	'info',
			'screen_ids'		=>	array( 'upload' ),
			'persistent'		=>	true,
			'no_js_dismissable'	=>	true
		)

	);
	
	// Add in ratings request if appropriate. Admins are asked to rate when certain numbers of downloads have been made.
	$stored_options = get_option( 'jabd_storage' );
	
	$request_rating = false;
	
	$user_id = get_current_user_id();
	
	// Don't give message if this user has already left a rating or has refused permanently.
	if ( ! isset( $stored_options['no_rating_request'] ) ) {
		$request_rating = true;
	} else {
		if ( ! in_array( $user_id, $stored_options['no_rating_request'] ) ) {
			$request_rating = true;
		}
	}
	
	if ( $request_rating ) {

		$download_count = isset( $stored_options['download_count'] ) ? $stored_options['download_count'] : 0;
		$count_triggers = array( 25, 10, 3 );

		foreach ( $count_triggers as $count_trigger ) {
			if ( $download_count >= $count_trigger ) {
				$downloads_passed = $count_trigger;
				break;
			}
		}
		
		if ( isset( $downloads_passed ) ) {
			
			$rating_message = sprintf(
				/* translators: 1: Number of downloads 2: opening html tag 3: closing html tag */
				__( 'Hi, you and your fellow administrators have downloaded %1$s times using our %2$sBulk Attachment Download%3$s plugin – that’s awesome! If you\'re finding it useful and you have a moment, we\'d be massively grateful if you helped spread the word by rating the plugin on WordPress.', 'st-bulk-download' ),
				'<strong>' . $stored_options['download_count'] . '</strong>',
				'<strong>',
				'</strong>'
			) . '<br />';

			$review_link = 'https://wordpress.org/support/plugin/bulk-attachment-download/reviews/';
			
			// First option - give a review
			$rating_message .= '<span style="display: inline-block">' . Bulk_Attachment_Download_Admin_Notice_Manager::dismiss_on_redirect_link( array(
				'content'	=>	__( 'Sure, I\'d be happy to', 'st-bulk-download' ),
				'redirect'	=>	$review_link,
				'new_tab'	=>	true
			) ) . ' &nbsp;|&nbsp;&nbsp;</span>';
			
			// Second option - not now
			$rating_message .= '<span style="display: inline-block">' . Bulk_Attachment_Download_Admin_Notice_Manager::dismiss_event_button( array(
				'content'	=>	__( 'Nope, maybe later', 'st-bulk-download' ),
				'event'		=>	''
			) ) . ' &nbsp;|&nbsp;&nbsp;</span>';
			
			// Third option - already reviewed
			$rating_message .= '<span style="display: inline-block">' . Bulk_Attachment_Download_Admin_Notice_Manager::dismiss_event_button( array(
				'content'	=>	__( 'I already did', 'st-bulk-download' ),
				'event'		=>	'prevent_rating_request'
			) ) . '</span>';
			
			$opt_out_notices[ 'ratings_request_' . $downloads_passed ] = array(
				'message'		=>	$rating_message,
				'user_ids'		=>	array( 'administrator' ),
				'type'			=>	'info',
				'screen_ids'	=>	array( 'upload' ),
				'persistent'	=>	true,
				'dismissable'	=>	false
			);
		}

	}

	Bulk_Attachment_Download_Admin_Notice_Manager::add_opt_out_notices( $opt_out_notices );
	
}

/**
 * Check conditions for display of admin notice
 * @hooked jabd_display_opt_out_notice
 */
function jabd_conditional_display_admin_notice( $display, $notice ) {

	switch( $notice['id'] ) {
		
		case	'number_of_media_items' :
			$mode = get_user_option( 'media_library_mode', get_current_user_id() ) ? get_user_option( 'media_library_mode', get_current_user_id() ) : 'grid';
			if ( 'grid' == $mode ) {
				$display = false;
			}
			break;
		
		case	'switch_to_list_mode' :
			$mode = get_user_option( 'media_library_mode', get_current_user_id() ) ? get_user_option( 'media_library_mode', get_current_user_id() ) : 'grid';
			if ( 'list' == $mode ) {
				$display = false;
			}
			break;
		
	}
	
	return $display;
	
}

/**
 * Prevent future rating request for a user because has either refused or has rated.
 * @hooked jabd_user_notice_dismissed_ratings_request_{$count_trigger}_prevent_rating_request
 */
function jabd_prevent_rating_request( $user_id ) {
	$options = get_option( 'jabd_storage' );
	if ( ! isset( $options['no_rating_request'] ) ) {
		$options['no_rating_request'] = array( $user_id );
	} else {
		if ( ! in_array( $user_id, $options['no_rating_request'] ) ) {
			$options['no_rating_request'][] = $user_id;
		}
	}
	update_option( 'jabd_storage', $options );
}

/*---------------------------------------------------------------------------------------------------------*/
/*Download custom post type*/

/**
 * Register download custom post type.
 */
function jabd_register_download_post_type() {
	
	$labels = array(			
		'name'					=> _x( 'Downloads', 'post type general name', 'st-bulk-download' ),
		'singular_name'			=> _x( 'Download', 'post type singular name', 'st-bulk-download' ),
		'add_new'				=> _x( 'Add New', 'download item', 'st-bulk-download' ),
		'add_new_item'			=> __( 'Add New Download', 'st-bulk-download'),
		'edit_item'				=> __( 'Edit Download', 'st-bulk-download' ),
		'view_item'				=> __( 'View Download', 'st-bulk-download' ),
		'search_items'			=> __( 'Search Downloads', 'st-bulk-download' ),
		'not_found'				=> __( 'No Downloads found', 'st-bulk-download' ),
		'not_found_in_trash'	=> __('No Downloads found in Trash', 'st-bulk-download'), 
		'parent_item_colon'		=> '',
		'all_items'				=> __( 'Bulk downloads', 'st-bulk-download' ),
		'menu_name'				=> __( 'Bulk downloads', 'st-bulk-download' )
	);
	
	$args = array(
		'labels'				=> $labels,
		'public'				=> false,
		'publicly_queryable'	=> true,
		'show_ui'				=> true, 
		'show_in_menu'			=> 'upload.php', 
		'show_in_nav_menus'		=> false,
		'query_var'				=> false,
		'rewrite'				=> array( 'slug' => 'downloads' ),
		'has_archive'			=> false, 
		'hierarchical'			=> false,
		'supports'				=> array( 'title' ),
		'capabilities'			=> array(

			// primitive/meta caps
			'create_posts'           => 'do_not_allow',

			// primitive caps used outside of map_meta_cap()
			'edit_posts'             => 'upload_files',
			'edit_others_posts'      => 'manage_options',
			'publish_posts'          => 'upload_files',
			'read_private_posts'     => 'read',

			// primitive caps used inside of map_meta_cap()
			'read'                   => 'read',
			'delete_posts'           => 'upload_files',
			'delete_private_posts'   => 'upload_files',
			'delete_published_posts' => 'upload_files',
			'delete_others_posts'    => 'manage_options',
			'edit_private_posts'     => 'upload_files',
			'edit_published_posts'   => 'upload_files'
		),
		'map_meta_cap'			=> true,
	);
	
	register_post_type( 'jabd_download', $args );

}

/**
 * Prevent add new download action.
 */
function jabd_prevent_add_new_download() {
	if ( isset( $_GET['post_type'] ) ) {
		if ( 'jabd_download' == $_GET['post_type'] ) {
			wp_redirect( 'edit.php?post_type=jabd_download' );
		}
	}
}

/**
 * Add columns to post list table.
 */
function jabd_add_link_columns( $columns ) {
	foreach ( $columns as $key => $column ) {
		$new_columns[ $key ] = $column;
		if ( 'title' == $key ) {
			$new_columns['jabd_download_creator'] = __( 'Creator', 'st-bulk-download' );
			$new_columns['jabd_download_button'] = __( 'Download', 'st-bulk-download' );
		}
	}
	return $new_columns;
}

/**
 * Add columns content to post list table.
 */
function jabd_add_link_columns_content( $column ) {
	global $post;
	switch ( $column ) {
		
		case 'jabd_download_creator' :
			$user = get_user_by( 'id', $post->post_author );
			echo esc_html($user->user_login);
			break;
			
		case 'jabd_download_button' :
		$disabled = ( 'trash' == $post->post_status || ! current_user_can( 'edit_post', $post->ID ) ) ? ' disabled' : '';
			echo '<a href="'.get_post_permalink( $post->ID ).'"><button class="button button-primary button-large" type="button"'.$disabled. '>'.__( 'Download', 'st-bulk-download' ).'</button></a>';
			break;
		
	}
}

/**
 * Amend post updated message.
 */
function jabd_post_updated_messages( $messages ) {
	global $post;
	if ( 'jabd_download' == $post->post_type ) {
		$messages['post'][1] = __( 'Download updated.', 'st-bulk-download' );
	}
	return $messages;
}

/*---------------------------------------------------------------------------------------------------------*/
/*Redirect to get download*/

/**
 * Template for download custom post type which delivers zip file
 * @hooked single_template
 */
function jabd_download_template( $template ) {
	if ( 'jabd_download' == get_post_type( get_queried_object_id() ) ) {
		$template = JABD_PLUGIN_DIR.'templates/single-jabd_download.php';
	}
	return $template;
}

/**
 * Increments download count stored in options and adds request notice if appropriate
 */
function jabd_increment_download_count() {
	$options = get_option( 'jabd_storage' );
	if ( isset( $options['download_count'] ) ) {
		$options['download_count']++;
	} else {
		$options['download_count'] = 1;
	}
	update_option( 'jabd_storage', $options );
}

/**
 * Redirects to 404 (called if download file does not exist or user does not have permission to download)
 */
function jabd_404_redirect() {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	get_template_part( 404 );
	exit();
}

/*---------------------------------------------------------------------------------------------------------*/
/*Ajax functions*/

/**
 * Handles ajax request
 * Runs nonce checks, permissions checks, file size checks, and then creates download
 */
function jabd_request_download() {

	$user_id = get_current_user_id();
	
	$permissions_errors = array();

	// check nonce
	if ( ! check_ajax_referer( 'download-request-'.$user_id, 'downloadNonce', false ) ) {
		$permissions_errors[] = __( 'Security checks failed.', 'st-bulk-download' );
	}

	// get file ids
	$valid_file_ids = true;
	if ( empty( $_POST['attmtIds'] ) or ! is_array( $_POST['attmtIds'] ) ) {
		$valid_file_ids = false;
	} else {
		foreach ( $_POST['attmtIds'] as $attmt_id ) {
			if ( ! $attmt_id or ! ctype_digit( $attmt_id ) ) {
				$valid_file_ids = false;
			}
		}
	}

	if ( empty( $permissions_errors ) and $valid_file_ids ) {
		
		// get posts and check they are attachments
		$files_to_download = get_posts( array(
			'posts_per_page'		=> -1,
			'post_type'				=> 'attachment',
			'post__in'				=> $_POST['attmtIds'],
			'ignore_sticky_posts'	=> true
		) );
		
		if ( empty( $files_to_download ) ) {
			$valid_file_ids = false;
		}

	}
	
	if ( ! $valid_file_ids ) {
		$permissions_errors[] = __( 'No valid files selected for download.', 'st-bulk-download' );
	} else {
		
		// check permissions
		foreach ( $files_to_download as $file_to_download ) {
			if ( current_user_can( 'edit_post', $file_to_download->ID ) ) {
				$permitted_files[] = $file_to_download;
			}
		}
		
		if ( empty( $permitted_files ) ) {
			$permissions_errors[] = __( 'You do not have permission to download any of the selected files.', 'st-bulk-download' );
		}
		
	}

	if ( empty( $permissions_errors ) ) { // proceed if no errors

		$under_int_file_limit = $under_file_limit = true;
		$doaction = sanitize_text_field( $_POST['doaction'] );
		
		$download_data = array(
			'count'				=> 0,
			'count_incl_int'	=> 0,
			'size'				=> 0,
			'size_incl_int'		=> 0
		);
		$int_sizes = get_intermediate_image_sizes();
		$upload_dir_info = wp_upload_dir();
		
		foreach ( $permitted_files as $permitted_file ) {
			$file_path = get_attached_file( $permitted_file->ID, true );

			if ( file_exists( $file_path ) ) { // if the file actually exists, include in stats
				$download_data['count']++;
				$download_data['count_incl_int']++;
				$this_file_size = @filesize( $file_path );
				$download_data['size'] += $this_file_size;
				$download_data['size_incl_int'] += $this_file_size;
			}
			
			if ( wp_attachment_is_image( $permitted_file->ID ) ) {
				if ( ! empty( $int_sizes ) ) {
					foreach ( $int_sizes as $size ) {
						if ( $int_image_data = image_get_intermediate_size( $permitted_file->ID, $size ) ) {
							$download_data['count_incl_int']++;
							$int_filepath = false === strpos( $int_image_data['path'], $upload_dir_info['basedir'] ) ? $upload_dir_info['basedir'].'/'.$int_image_data['path'] : $int_image_data['path'];
							$download_data['size_incl_int'] += @filesize( $int_filepath );
						}
					}
				}
			}
			
		}
		
		// if we have files to assess
		if ( $download_data['count'] > 0 ) {
			
			$settings = get_option( 'jabd_options' );
			$max_file_size = apply_filters( 'jabd_max_files_size', ( isset( $settings['jabd_max_size'] ) ? $settings['jabd_max_size'] : 100 ) );
			
			// check where we are relative to the file limit
			if ( ( $download_data['size'] / 1000000 ) > $max_file_size ) {
				$under_file_limit = false;
			} elseif( ( $download_data['size_incl_int'] / 1000000 ) > $max_file_size ) {
				$under_int_file_limit = false;
			}
			
			if ( 'getdata' == $doaction ) {
			
				$download_data_display = $download_data;
				$download_data_display['size'] = jabd_human_filesize( $download_data['size'], 1 );
				$download_data_display['size_incl_int'] = jabd_human_filesize( $download_data['size_incl_int'], 1 );
				
				foreach ( $download_data_display as $key => $value ) {
					$download_data_display[$key] = '<strong>'.$value.'</strong>';
				}
				
				/* translators: Number of files */
				$file_info = '<div>'.sprintf( __( 'Files: %s','st-bulk-download' ), $download_data_display['count'] );
				if ( $download_data['count_incl_int'] > $download_data['count'] ) {
					/* translators: Number of files */
					$file_info .= sprintf( __( ' (%s if intermediate sizes included)' ,'st-bulk-download' ), $download_data_display['count_incl_int'] );
				}
				/* translators: Size of files */
				$file_info .= '</div><div>'.sprintf( __( 'Uncompressed files size: %s' ,'st-bulk-download' ), $download_data_display['size'] );
				if ( $download_data['count_incl_int'] > $download_data['count'] ) {
					/* translators: Number of files */
					$file_info .= sprintf( __( ' (%s if intermediate sizes included)' ,'st-bulk-download' ), $download_data_display['size_incl_int'] );
				}
				$file_info .= '</div>';
				
				$download_btn_html = '<button id="jabd-create-download" type="button" class="button button-primary button-large">'.__( 'Create download', 'st-bulk-download' ).'</button>&nbsp; ';
				
				$incl_int_sizes_default = isset( $settings['jabd_int_sizes'] ) ? $settings['jabd_int_sizes'] : 0;
				$jabd_no_folders = isset( $settings['jabd_no_folders'] ) ? $settings['jabd_no_folders'] : 0;
				
				$results_message =
'<div class="jabd-popup-text-block">
	<strong>'.__( 'File info', 'st-bulk-download' ).'</strong>
	'.$file_info.'
</div>';

				//give warning if we are over the file limit
				if ( ! $under_file_limit ) {
					$download_btn_html = '';
					$results_message .=
'<div class="jabd-popup-text-block" style="color: red">
	'.sprintf (
	/* translators: File size limit in MB */	
		__( 'Your selected files exceed the limit of %sMB', 'st-bulk-download' ), $max_file_size
	).'
</div>';
				} elseif ( ! $under_int_file_limit ) {
					$results_message .=
'<div class="jabd-popup-text-block" style="color: red">
	'.sprintf (
		/* translators: File size limit in MB */
		__( 'Downloading intermediate sizes will exceed limit of %sMB', 'st-bulk-download' ), $max_file_size
	).'
</div>';
				}
				
				if ( $under_file_limit ) {
				
					$results_message .=
'<div class="jabd-popup-text-block">
	<strong>'.__( 'Options', 'st-bulk-download' ).'</strong><br />
	<div'.( $under_int_file_limit ? '' : ' style="color: grey"' ).'>
		<input id="jabd-int-sizes-chkbox" type="checkbox" '.( $under_int_file_limit ? checked( $incl_int_sizes_default, true, false ) : 'style="cursor: default" disabled' ).'/>
		<label'.( $under_int_file_limit ? '' : ' style="cursor: default"' ).' for="jabd-int-sizes-chkbox">'.__( 'Include image intermediate sizes', 'st-bulk-download' ).'</label><br />
	</div>
	<div>
		<input id="jabd-no-folder-chkbox" type="checkbox" '.checked( $jabd_no_folders, true, false ).'/>
		<label for="jabd-no-folder-chkbox">'.__( 'Single folder download (any duplicate filenames will be amended)', 'st-bulk-download' ).'</label>
	</div>
</div>
<div class="jabd-popup-msg">
	<span>'.__( 'Download title (optional)', 'st-bulk-download' ).'&nbsp;</span>
	<input type="text" />
</div>';
				}

				$results_message .=
	'<div class="jabd-popup-buttons">'.$download_btn_html.jabd_close_popup_btn( __( 'Cancel', 'st-bulk-download' ) ).'</div>';

				$ajax_result = array( 'messages' => $results_message );
		
			//now create download if we are downloading
			} elseif ( 'download' == $doaction && empty( $permissions_errors ) && $under_file_limit ) { //downloading
				
				// create user folder if necessary
				$user_id = get_current_user_id();
				$zip_dir = JABD_PLUGIN_DIR.JABD_DOWNLOADS_DIR.'/'.$user_id;
				if ( ! file_exists( $zip_dir ) ) {
					mkdir( $zip_dir, 0755 );
				}
				
				//sanitize data
				$post_title = sanitize_text_field( $_POST['title'] );
				
				//work out whether we are downloading intermediate sizes and whether we are retaining folder structure
				if ( ! $under_int_file_limit ) {
					$incl_int_sizes = false;
				} else {
					$incl_int_sizes = 'true' == sanitize_text_field( $_POST['intsizes'] ) ? true : false;
				}
				$no_folders = 'true' == sanitize_text_field( $_POST['nofolders'] ) ? true : false;

				// create a unique name based on the user title if provided
				if ( empty( $post_title ) ) {
					$post_title = uniqid();
				}
				$zip_name = sanitize_file_name( $post_title );
				$name_count = 0;
				while ( file_exists( $zip_dir.'/'.$zip_name.($name_count > 0 ? $name_count : '').'.zip' ) ) {
					$name_count++;
				}
				$rel_zip_path = $user_id.'/'.$zip_name.( $name_count > 0 ? $name_count : '' ).'.zip';
				$zip_path = JABD_PLUGIN_DIR.JABD_DOWNLOADS_DIR.'/'.$rel_zip_path;
				if ( $name_count > 0 ) {
					$post_title .= $name_count;
				}
				
				// create the zip file
				if ( class_exists( 'ZipArchive' ) ) {
					
					$zip = new ZipArchive();
					$zip_opened = $zip->open( $zip_path, ZipArchive::CREATE );
					
					if ( true === $zip_opened ) {
					
						$upload_dir_info = wp_upload_dir();
						
						$added_rel_filepaths = array();
						
						// add the files to the zip
						foreach ( $permitted_files as $permitted_file ) {
							$file_path = get_attached_file( $permitted_file->ID, true );

							if ( file_exists( $file_path ) ) { // if the file actually exists, add it to the zip file
								
								if ( $no_folders ) {
									
									//just use filename for relative file path
									$relative_file_path = wp_basename( $file_path );
									
								} else {
								
									// attempt to work out the path relative to the uploads folder
									$relative_file_path = jabd_file_path_rel_to_uploads( $file_path, $permitted_file, $upload_dir_info['basedir'] );
									
								}
								
								$added_rel_filepaths = jabd_add_file_to_zip( $zip, $file_path, $relative_file_path, $added_rel_filepaths );

							}
							
							// add in intermediate sizes if required
							if ( $incl_int_sizes && wp_attachment_is_image( $permitted_file->ID ) ) {
								$int_sizes = get_intermediate_image_sizes();
								if ( ! empty( $int_sizes ) ) {
									foreach ( $int_sizes as $size ) {
										if ( $int_image_data = image_get_intermediate_size( $permitted_file->ID, $size ) ) {
											
											$int_file_path = $int_image_data['path'];

											// work out relative and full filepaths
											if ( strpos( $int_file_path, $upload_dir_info['basedir'] ) === false ) { // path is relative
												$int_rel_filepath = $no_folders ? wp_basename( $int_file_path ) : $int_file_path;
												$int_file_path = $upload_dir_info['basedir'].'/'.$int_file_path;
											} else { //path is full
												if ( $no_folders ) {
													$int_rel_filepath = wp_basename( $int_file_path );
												} else {
													$int_rel_filepath = jabd_file_path_rel_to_uploads( $int_file_path, $permitted_file, $upload_dir_info['basedir'] );
												}
											}
											
											$added_rel_filepaths = jabd_add_file_to_zip( $zip, $int_file_path, $int_rel_filepath, $added_rel_filepaths );

										}
									}
								}
							}
							
						}

						// close the zip
						$zip->close();
						
						if ( file_exists( $zip_path ) ) {
						
							// create the download post
							date_default_timezone_set( 'UTC' );
							$download_id = wp_insert_post( array(
								'post_title'	=> $post_title,
								'post_type'		=> 'jabd_download',
								'post_status'	=> 'publish',
								'meta_input'	=> array(
									'jabd_path'		=> addslashes( $rel_zip_path ),
									'jabd_expiry'	=> date( 'Y-m-d H:i:s', strtotime( '+2 hours' ) )
								),
							) );
							
							$results_msg = '<div class="jabd-popup-msg"><span>'.__( 'Download created!', 'st-bulk-download' ).'</span></div>';
							$results_view_btn = '<a href = "'.admin_url( 'edit.php?post_type=jabd_download' ).'"><button class="button button-primary button-large">'.__( 'View', 'st-bulk-download' ).'</button></a>&nbsp; ';
							$results_close_btn = '<button id="jabd-close-download-popup" class="button button-primary button-large">'.__( 'Close', 'st-bulk-download' ).'</button>';
							$results_btns = '<div class="jabd-popup-buttons">'.$results_view_btn.$results_close_btn.'</div>';
							
							$ajax_result = array(
								'messages'	=> $results_msg.$results_btns
							);
						
						} else { //zip file does not exist
							$permissions_errors[] = __( 'Error. Your download could not be created.', 'st-bulk-download' );
						}
						
					} else { // zip file could not be created
						$permissions_errors[] = __( 'Error. Your download could not be created.', 'st-bulk-download' );
					}
					
				} else { // ziparchive class not found
					$permissions_errors[] = __( 'Error. Your download could not be created. It looks like you don\'t have ZipArchive installed on your server.', 'st-bulk-download' );
				}
				
			} else { // no action specified in ajax posted data
				$permissions_errors[] = __( 'Error. Your download could not be created.', 'st-bulk-download' );
			}
			
		} else { // no valid files selected
			$permissions_errors[] = __( 'Error. No files selected that you are permitted to download.', 'st-bulk-download' );
		}

	}
	
	if ( ! empty( $permissions_errors ) ) {
		
		$results_msg = '<div class="jabd-popup-msg"><span>'.$permissions_errors[0].'</span></div>';
		
		$ajax_result = array(
			'messages'	=> $results_msg.jabd_close_popup_btn( __( 'Close', 'st-bulk-download' ) )
		);
		
	}
	
	// send response
	echo wp_json_encode( $ajax_result );
	wp_die();
	
}

/**
 * Add file to zip making sure filename is unique
 */
function jabd_add_file_to_zip( $zip, $file_path, $relative_file_path, $added_rel_filepaths ) {
	
	// if there is another file with the same name in the zip file already then amend filename
	$relative_file_path = jabd_unique_filepath_in_filepaths_array( $relative_file_path, $added_rel_filepaths );
	
	// add the file using the relative file path
	if ( $zip->addFile( $file_path, $relative_file_path ) ) {
		$added_rel_filepaths[] = $relative_file_path;
	}
	
	return $added_rel_filepaths;
	
}

/**
 * Generate html for a close popup button
 */
function jabd_close_popup_btn( $btn_text ) {
	return '<button id="jabd-close-download-popup" class="button button-primary button-large">'.$btn_text.'</button>';
}

/**
 * Delete zip file on deletion of download post by user
 */
function jabd_delete_download_zip( $post_id ) {
	if ( $zip_path = JABD_PLUGIN_DIR.JABD_DOWNLOADS_DIR.'/'.get_post_meta( $post_id, 'jabd_path', true ) ) {
		if ( file_exists( $zip_path ) ) {
			@unlink( $zip_path );
		}
		// delete user folder if empty
		@rmdir( str_replace( '/'.wp_basename( $zip_path ), '', $zip_path ) );
	}
}

/**
 * Returns a unique filepath by checking against an array of filepaths
 */
function jabd_unique_filepath_in_filepaths_array( $relative_file_path, $added_rel_filepaths ) {
	$count = -1;
	do {
		$count++;
		$path_and_ext = jabd_split_filepath_and_ext( $relative_file_path );
		$relative_file_path = $path_and_ext['path'].( $count > 0 ? $count : '' ).$path_and_ext['ext'];
	} while ( in_array( $relative_file_path, $added_rel_filepaths ) );
	return $relative_file_path;
}

/**
 * Returns filepath and extension
 */
function jabd_split_filepath_and_ext( $filepath ) {
	$dotpos = strrpos( $filepath, '.' );
	if ( false === $dotpos ) {
		$output['path'] = $filepath;
		$output['ext'] = '';
	} else {
		$output['path'] = substr( $filepath, 0, $dotpos );
		$output['ext'] = substr( $filepath, $dotpos );
	}
	return $output;
}

/**
 * Attempt to work out path of attachment relative to upload folder
 * @return relative path (excluding leading slash) if found, false if not
 */
function jabd_file_path_rel_to_uploads( $file_path, $attachment, $upload_basedir ) {
	// if no match return false
	if ( false === strpos( $file_path, $upload_basedir ) ) {
		return false;
	}
	// otherwise return relative path
	return apply_filters( 'jabd_file_path_rel_to_uploads', str_replace( $upload_basedir.'/', '', $file_path ), $attachment );
}

/**
 * Converts filesize in bytes to human readable form
 */
function jabd_human_filesize( $bytes, $decimals = 2 ) {
	$sz = 'KMGTP';
	$factor = floor( ( strlen( $bytes ) - 1 ) / 3 );
	return sprintf( "%.{$decimals}f", $bytes / pow( 1000, $factor ) ) . @$sz[ $factor - 1 ].'B';
}