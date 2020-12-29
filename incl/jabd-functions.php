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
    load_plugin_textdomain( 'bulk-attachment-download', FALSE, basename( JABD_PLUGIN_DIR ) . '/languages/' );
}

/*---------------------------------------------------------------------------------------------------------*/
/*Add admin js and css*/

/**
 * Load admin js and css.
 */
function jabd_admin_enqueue_scripts( $hook ) {
	
	global $post;
	
	if ( 'upload.php' == $hook ) { //if we are on media library
		
		// Don't allow downloading if we can't work with uploads dir.
		if ( ! defined( 'JABD_UPLOADS_DIR' ) ) {
			return false;
		}
		
		// JS for handling download creation on Media library
		wp_enqueue_script( 'jabd-admin-upload-js', JABD_PLUGIN_BASE_URL.'js/admin-upload.js', array( 'jquery', 'media-views' ), '1.0.0', true );

		$localization_array = array(
			'download_option' 			=> _x( 'Download', 'action', 'bulk-attachment-download' ),
			'download_launched_msg'		=> __( 'Please wait, your download is being created.', 'bulk-attachment-download' ),
			'gathering_data_msg'		=> __( 'Gathering data...', 'bulk-attachment-download' ),
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
		
	} elseif ( 'options-media.php' == $hook ) { // we are on media settings
		
		// JS for handling show / hide of guidance
		wp_enqueue_script( 'jabd-guidance-display-js', JABD_PLUGIN_BASE_URL.'js/admin-options-media.js', array( 'jquery' ), '1.0.0' );
		
		// CSS for styling guidance section
		wp_enqueue_style(
			'jabd-admin-media-settings-css',
			JABD_PLUGIN_BASE_URL.'css/media-settings-style.css'
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
	$options = get_option( 'jabd_options' );
	$auto_delete = true;
	if ( isset( $options['jabd_disable_auto_delete'] ) ) {
		if ( $options['jabd_disable_auto_delete'] ) {
			$auto_delete = false;
		}
	}
	
	if ( $auto_delete ) {
		wp_schedule_event( time(), 'hourly', 'jabd_hourly_event' );
	}
	
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
function jabd_delete_expired_download_posts() {
	jabd_delete_download_posts( $only_expired = true );
}
add_action( 'jabd_hourly_event', 'jabd_delete_expired_download_posts' );

/**
 * Delete download posts.
 * @param	bool	$only_expired	whether to delete only expired downloads
 */
function jabd_delete_download_posts( $only_expired = true ) {
	$download_posts = get_posts( array(
		'post_type'			=> 'jabd_download',
		'posts_per_page'	=> -1,
		'post_status'		=> 'all'
	) );
	if ( ! empty( $download_posts ) ) {
		date_default_timezone_set('UTC');
		$now = time();
		foreach( $download_posts as $download_post ) {
			if ( $only_expired ) {
				if ( strtotime( get_post_meta( $download_post->ID, 'jabd_expiry', true ) ) > $now ) {
					continue;
				}
			}
			jabd_delete_download_zip( $download_post->ID );
			wp_delete_post( $download_post->ID, true );
		}
	}
}

/**
 * Run processes on upgrade and installation.
 * @hooked plugins_loaded
 */
function jabd_check_version() {
	$prev_version = get_option( 'jabd_version' );
	if ( JABD_VERSION !== $prev_version ) {
		update_option( 'jabd_version', JABD_VERSION );
		
		if ( ! $prev_version ) {
			jabd_on_plugin_installation();
		} else {
			jabd_on_plugin_upgrade( $prev_version );
		}
	
	}
}

/**
 * Processes to be run on upgrade.
 * @param	string	$prev_version	Plugin version before upgrade
 */
function jabd_on_plugin_upgrade( $prev_version ) {

	// Delete any hangover posts after moving downloads to uploads folder.
	if (  1 == version_compare( '1.3.0', $prev_version ) ) {
		jabd_delete_download_posts( $only_expired = false );

		// recreate .htaccess file if needed.
		$options = get_option( 'jabd_options' );
		if ( isset(  $options['jabd_secure_downloads'] ) ) {
			if ( $options['jabd_secure_downloads'] ) {
				jabd_create_htaccess( 1, 1 );
			}
		}

	}
	
}

/**
 * Processes to be run on installation.
 */
function jabd_on_plugin_installation() {

	/**
	 * Remove deprecated options and usermeta from db since these may have been present from
	 * versions prior to 1.1.4, and version was not stored in those versions.
	 */
	delete_option( 'jabd_notices' );
	delete_metadata( 'user', 0, 'jabd_dismissed_notices', false, true );
	
	// add greeting and guidance message
	$notice_args = array(
		'id'					=>	'greeting_on_installation',
		'type'					=>	'success',
		'message'				=>	sprintf(
										/* translators: 1: plugin name 2: opening anchor tag 3: closing anchor tag 4: opening anchor tag */
										__( '%1$s has been activated. For settings and guidance go to the %2$sMedia settings%3$s page. Or you can go straight to your %4$sMedia%3$s where the Download option is now available (in the Bulk actions dropdown in List mode and as a Download button in Grid mode when you choose Bulk Select).', 'bulk-attachment-download' ),
										'<strong>' . JABD_PLUGIN_NAME . '</strong>',
										'<a href="' . esc_url( admin_url( 'options-media.php' ) ) . '">',
										'</a>',
										'<a href="' . esc_url( admin_url( 'upload.php' ) ) . '">'
									),
		'persistent'			=>	true,
		'no_js_dismissable'		=>	true,
		'screen_ids'			=>	array( 'plugins' )
	);
	Bulk_Attachment_Download_Admin_Notice_Manager::add_notice( $notice_args );
	
}

/*---------------------------------------------------------------------------------------------------------*/
/*Define uploads folder*/

/**
 * Define uploads dir.
 */
function jabd_define_uploads_folder() {
	$uploads_dir_info = wp_upload_dir();
	if ( $uploads_dir_info['error'] ) {
		add_action( 'admin_init', 'jabd_upload_dir_error_notice' );
	} else {
		define( 'JABD_UPLOADS_DIR', $uploads_dir_info['basedir'] . '/' );
	}
}

/**
 * Set an error message that uploads dir is inaccessible.
 */
function jabd_upload_dir_error_notice() {
	$uploads_dir_info = wp_upload_dir();
	$message = '<strong>' . __( 'Error', 'bulk-attachment-download' ) . ':</strong> ';
	/* translators: Plugin name */
	$message .= sprintf(
		__( '%s was unable to access your uploads folder in order to create a Downloads folder. The plugin cannot function unless the error is resolved. The error message is:', 'bulk-attachment-download' ) . '<br />',
		JABD_PLUGIN_NAME
	);
	$message .= $uploads_dir_info['errpr'];
	$notice = array(
		'message'		=>	$message,
		'user_ids'		=>	array( 'administrator' ),
		'type'			=>	'error',
		'screen_ids'	=>	array( 'upload' ),
		'persistent'	=>	true,
		'dismissable'	=>	false
	);
	Bulk_Attachment_Download_Admin_Notice_Manager::add_opt_out_notice( $notice );
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
		'<span id="jabd-settings">' . JABD_PLUGIN_NAME . '</span>',
		'jabd_guidance_section',
		'media'
	);
	
	// register fields in the "jabd_settings_section" section, inside the "Media" page
	add_settings_field(
		'jabd_max_size',
		__( 'Max uncompressed file size', 'bulk-attachment-download' ),
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
		__( 'Include intermediate sizes by default', 'bulk-attachment-download' ),
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
		__( 'Default single folder download', 'bulk-attachment-download' ),
		'jabd_single_folder_default_cb',
		'media',
		'jabd_settings_section',
		array(
			'label_for'	=> 'jabd_no_folders',
			'class'	=> 'jabd_row',
		)
	);

	add_settings_field(
		'jabd_disable_auto_delete',
		__( 'Disable auto deletion', 'bulk-attachment-download' ),
		'jabd_disable_auto_delete_cb',
		'media',
		'jabd_settings_section',
		array(
			'label_for'	=> 'jabd_disable_auto_delete',
			'class'	=> 'jabd_row',
		)
	);
	
	if ( method_exists( 'ZipArchive', 'setEncryptionName' ) ) {
	
		add_settings_field(
			'jabd_pwd_downloads',
			__( 'Optional password protection', 'bulk-attachment-download' ),
			'jabd_pwd_downloads_cb',
			'media',
			'jabd_settings_section',
			array(
				'label_for'	=> 'jabd_pwd_downloads',
				'class'	=> 'jabd_row',
			)
		);

		add_settings_field(
			'jabd_default_pwd',
			__( 'Default password', 'bulk-attachment-download' ),
			'jabd_default_pwd',
			'media',
			'jabd_settings_section',
			array(
				'label_for'	=> 'jabd_default_pwd',
				'class'	=> 'jabd_row',
			)
		);

	} else {

		add_settings_field(
			'jabd_no_encryption',
			__( 'Password protection options', 'bulk-attachment-download' ),
			'jabd_no_encryption',
			'media',
			'jabd_settings_section',
			array(
				'class'	=> 'jabd_row',
			)
		);

	}

	add_settings_field(
		'jabd_store_pwds',
		__( 'Store & view passwords', 'bulk-attachment-download' ),
		'jabd_store_pwds_cb',
		'media',
		'jabd_settings_section',
		array(
			'label_for'	=> 'jabd_store_pwds',
			'class'	=> 'jabd_row',
		)
	);

	add_settings_field(
		'jabd_secure_downloads',
		__( 'Make downloads secure', 'bulk-attachment-download' ),
		'jabd_secure_downloads_cb',
		'media',
		'jabd_settings_section',
		array(
			'label_for'	=> 'jabd_secure_downloads',
			'class'	=> 'jabd_row',
		)
	);
	
	add_settings_field(
		'jabd_delete_on_uninstall',
		__( 'Delete on uninstall', 'bulk-attachment-download' ),
		'jabd_delete_on_uninstall_cb',
		'media',
		'jabd_settings_section',
		array(
			'label_for'	=> 'jabd_delete_on_uninstall',
			'class'	=> 'jabd_row',
		)
    );

}

/**
 * Output the guidance section
 */
function jabd_guidance_section() {
	
	$output_html = '<div class="jabd-guidance-container"><h4><span class="hide-if-js">' . __( 'Guidance', 'bulk-attachment-download' ) . '</span>';
	$output_html .= '<button type="button" class="hide-if-no-js jabd-link-button jabd-guidance-link">';
	$output_html .= _x( 'Show Guidance', 'Display hidden content', 'bulk-attachment-download' ) . '&nbsp;&#9660</button>';
	$output_html .= '<button type="button" class="hide-if-no-js jabd-link-button jabd-guidance-link" style="display: none">';
	$output_html .= _x( 'Hide Guidance', 'Hide content', 'bulk-attachment-download' ) . '&nbsp;&#9650</button></h4>';
	$guidance_html = '<p>' . __( 'To download attachments in bulk:' , 'bulk-attachment-download' ) . '</p>';
	$guidance_html .= '<ol><li>' . sprintf(
	/* translators: 1: Opening anchor tag 2: Closing anchor tag */
		__( 'Go to your %1$sMedia Library%2$s.' , 'bulk-attachment-download' ),
		'<a href="' . esc_url( admin_url( 'upload.php' ) ) . '">',
		'</a>' ) . ' ';
	/* translators: 1: Opening <strong> tag 2: Closing </strong> tag */
	$guidance_html .= '</li><li>' . sprintf( __( 'Select the files you want to download. In Grid mode, click the %1$sBulk Select%2$s button and then click on the items you wish to download. In List mode, check the checkboxes against the items you wish to download. (Remember that in List mode you can change how many items are displayed per page by going to %1$sScreen Options%2$s at the top right of the page.', 'bulk-attachment-download' ),
		'<strong>',
		'</strong>' ) . '</li>';
	/* translators: 1: Opening <strong> tag 2: Closing </strong> tag */
	$guidance_html .= '<li>' . sprintf( __( 'In Grid mode, click the %1$sDownload%2$s button. In List mode, select %1$sDownload%2$s in the %1$sBulk actions%2$s dropdown and click %1$sApply%2$s. You will then be able to choose from a series of options before creating your zip file ready for download.', 'bulk-attachment-download' ),
		'<strong>',
		'</strong>' ) . '</li>';
	/* translators: 1: link to "Bulk downloads" 2: Opening <strong> tag 3: Closing </strong> tag */
	$guidance_html .= '<li>' . sprintf( __( 'Your newly created zip file is ready for download on the %1$ page, accessible via sub-menu under %2$sMedia%3$s on the main toolbar. Just click the relevant %2$sDownload%3$s button and the corresponding zip file will be downloaded by your browser.', 'bulk-attachment-download' ),
		'<a href="' . esc_url( admin_url( 'edit.php?post_type=jabd_download' ) ) . '">' . __( 'Bulk downloads', 'bulk-attachment-download' ) . '</a>',
		'<strong>',
		'</strong>' ) . '</li>';
	/* translators: 1: Opening <strong> tag 2: Closing </strong> tag */
	$guidance_html .= '</ol><p>' . sprintf( __( 'Zip files and their corresponding %1$sDownload%2$s buttons are removed automatically 1-2 hours after their creation, so you don\'t have to worry about using up your server storage quota. However you can disable the auto deletion if you prefer.', 'bulk-attachment-download' ),
	'<strong>',
	'</strong>' ) . '</p>';
	$output_html .= '<div class="jabd-guidance hide-if-js">' . $guidance_html . '</div></div>';
	echo $output_html;
	
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
	<p class="description"><?php _e( 'Set a limit for the maximum uncompressed file size to be created as a downloadable zip.', 'bulk-attachment-download' ); ?></p>
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
	<p class="description"><?php _e( 'Check the box if you want to download all intermediate images sizes by default. (This can be changed for each download.)', 'bulk-attachment-download' ); ?></p>
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
	<p class="description"><?php _e( 'Check the box if you want the zip file to include all files in a single folder by default. (This can be changed for each download.)', 'bulk-attachment-download' ); ?></p>
	<?php
}

/**
 * Output the disable auto deletion settings field
 */
function jabd_disable_auto_delete_cb( $args ) {
	$options = get_option( 'jabd_options' );
	$option = isset( $options['jabd_disable_auto_delete'] ) ? $options['jabd_disable_auto_delete'] : 0;
	?>
	<input style="margin-top: 6px" type="checkbox" name="jabd_options[<?php echo $args['label_for']; ?>]" id="<?php echo $args['label_for']; ?>" value="1" <?php checked( $option ); ?> />
	<p class="description"><?php _e( 'Disables the automatic deletion of downloads (which are otherwise deleted 1 - 2 hours after being created).', 'bulk-attachment-download' ); ?></p>
	<?php
}

/**
 * Output the optional password settings field
 */
function jabd_pwd_downloads_cb( $args ) {
	$options = get_option( 'jabd_options' );
	$option = isset( $options['jabd_pwd_downloads'] ) ? $options['jabd_pwd_downloads'] : 0;
	?>
	<input style="margin-top: 6px" type="checkbox" name="jabd_options[<?php echo $args['label_for']; ?>]" id="<?php echo $args['label_for']; ?>" value="1" <?php checked( $option ); ?> />
	<p class="description"><?php _e( 'Gives the option for a password to be set on the zip file when downloading. Note that password protected files are also encrypted using AES-256 and may not be openable using the standard Windows facility. Use 7-Zip instead.', 'bulk-attachment-download' ); ?></p>
	<?php
}

/**
 * Output the store passwords settings field
 */
function jabd_store_pwds_cb( $args ) {
	$options = get_option( 'jabd_options' );
	$option = isset( $options['jabd_store_pwds'] ) ? $options['jabd_store_pwds'] : 0;
	$description = __( 'Whether to store passwords set on zip files. Stored passwords are displayed in the Downloads table.', 'bulk-attachment-download' );
	if ( ! method_exists( 'ZipArchive', 'setEncryptionName' ) ) {
		$description .= ' ' . __( 'This option is only available in case passwords have been stored in the past and you wish to enable or disable their display. Password protection is not available (see above).', 'bulk-attachment-download' );
	}
	?>
	<input style="margin-top: 6px" type="checkbox" name="jabd_options[<?php echo $args['label_for']; ?>]" id="<?php echo $args['label_for']; ?>" value="1" <?php checked( $option ); ?> />
	<p class="description"><?php echo $description; ?></p>
	<?php
}

/**
 * Output the default password settings field
 */
function jabd_default_pwd( $args ) {
	$options = get_option( 'jabd_options' );
	$option = isset( $options['jabd_default_pwd'] ) ? $options['jabd_default_pwd'] : '';
	?>
	<input type="text" size="20" name="jabd_options[<?php echo $args['label_for']; ?>]" id="<?php echo $args['label_for']; ?>" value="<?php esc_html_e( $option ); ?>" />
	<p class="description"><?php _e( 'Set a default password that will be used on all zip files. If the option to choose a password at download is enabled, the default password can be overwritten then.', 'bulk-attachment-download' ); ?></p>
	<?php
}

/**
 * Output section explaining that password protection is not available
 */
function jabd_no_encryption( $args ) {
	?>
	<p class="description"><?php _e( 'Password protection options are not available, most likely because your server is running a version of PHP older than 7.2. The option to store and view passwords below remains active only in case passwords have been stored in the past.', 'bulk-attachment-download' ); ?></p>
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
	<p class="description"><?php _e( 'Choose whether or not to prevent others accessing downloads while they are (temporarily) stored on the server. There\'s no point doing this unless you are somehow also protecting access to the files in your Uploads folder.', 'bulk-attachment-download' ); ?></p>
	<?php
}

/**
 * Output the delete on uninstall settings field
 */
function jabd_delete_on_uninstall_cb( $args ) {
	$options = get_option( 'jabd_options' );
	$option = isset( $options['jabd_delete_on_uninstall'] ) ? $options['jabd_delete_on_uninstall'] : 0;
	?>
	<input style="margin-top: 6px" type="checkbox" name="jabd_options[<?php echo $args['label_for']; ?>]" id="<?php echo $args['label_for']; ?>" value="1" <?php checked( $option ); ?> />
	<p class="description"><?php _e( 'Choose whether to delete any existing zip files when uninstalling the plugin.', 'bulk-attachment-download' ); ?></p>
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
			
			} elseif ( 'jabd_default_pwd' == $key ) {
				$settings[$key] = sanitize_text_field( $setting );
			} else {
				$value = intval( $setting );
				$settings[$key] = $value ? 1 : 0;
			}
		}
	}

	return $settings;
}

/**
 * Add / remove htaccess file as necessary when "Make downloads secure" option is updated, and
 * Add/ remove automatic cron event for deletion of downloads
 * @hooked pre_update_option_jabd_options
 */
function jabd_before_options_update( $value, $old_value, $option ) {

	if ( 'jabd_options' != $option ) {
		return false;
	}
	
	if ( current_user_can('manage_options') ) { // make sure user is administrator
		
		// Handle change of .htaccess setting.
		$old_htaccess_setting = isset( $old_value['jabd_secure_downloads'] ) ? $old_value['jabd_secure_downloads'] : '';
		$new_htaccess_setting = isset( $value['jabd_secure_downloads'] ) ? $value['jabd_secure_downloads'] : '';
		
		if ( $old_htaccess_setting != $new_htaccess_setting ) { // if the option has been changed

			if ( $new_htaccess_setting ) { // if we need a .htaccess file
				$value = jabd_create_htaccess( $value, $old_value );
			} else { // we are removing the .htaccess file
				$value = jabd_remove_htaccess( $value, $old_value );
			}
		}

		// Handle change of automatic delete setting.
		$old_auto_delete_setting = isset( $old_value['jabd_disable_auto_delete'] ) ? $old_value['jabd_disable_auto_delete'] : '';
		$new_auto_delete_setting = isset( $value['jabd_disable_auto_delete'] ) ? $value['jabd_disable_auto_delete'] : '';
		
		if ( $old_auto_delete_setting != $new_auto_delete_setting ) { // if the option has been changed

			if ( $new_auto_delete_setting ) { // if we need to disable auto deletion
				wp_clear_scheduled_hook( 'jabd_hourly_event' );
			} else { // we are enabling auto deletion
				wp_schedule_event( time(), 'hourly', 'jabd_hourly_event' );
			}
		}
		
	}
	
	return $value;
}

/**
 * Create / overwrite .htaccess file.
 * @param	int		$value		new setting value
 * @param	int		$old_value	old setting value
 * @return	int		returns value set to old value if failure
 */
function jabd_create_htaccess( $value, $old_value ) {

	// create downloads dir if necessary
	if ( ! file_exists( JABD_UPLOADS_DIR.JABD_DOWNLOADS_DIR ) ) {
		mkdir( JABD_UPLOADS_DIR.JABD_DOWNLOADS_DIR, 0755 );
	}
	
	$htaccess_path = JABD_UPLOADS_DIR.JABD_DOWNLOADS_DIR.'/.htaccess';
			
	// create / over-write htaccess file
	$htaccess = @fopen($htaccess_path, 'w');
	if ( ! $htaccess ) {
		$args = array(
			'id'		=>	'no_htaccess_update',
			'message'	=>	__( 'Bulk downloads settings have not been updated. The .htaccess file preventing direct access to your downloads could not be created. This may be an issue with the way permissions are set on your server.', 'bulk-attachment-download' ),
			'screen_ids'	=>	array( 'options-media' )
		);
		Bulk_Attachment_Download_Admin_Notice_Manager::add_notice( $args );
		$value = $old_value; // reset the options to old value to prevent update
	} else {
		fwrite( $htaccess, "Order Deny,Allow\nDeny from all" );
		fclose( $htaccess );
		if ( ! @chmod( $htaccess_path, 0644 ) ) { // set permissions and give warning if permissions cannot be set
			$disp_htaccess_path = str_replace( '\\', '/', str_replace( trim( ABSPATH, '/' ), '', JABD_UPLOADS_DIR.JABD_DOWNLOADS_DIR.'/.htaccess' ) );
			$args = array(
				'id'			=>	'htaccess_permissions_error',
				/* translators: Filepath to .htaccess file */
				'message'		=>	JABD_PLUGIN_NAME . ': '. sprintf( __( 'The .htaccess file has been created to prevent access to downloads. However the plugin could not confirm that permissions have been correctly set on the .htaccess file itself, which is a security risk. Please confirm that permissions on the file have been set to 0644 - it can be found at %s.', 'bulk-attachment-download' ), $disp_htaccess_path ),
				'type'			=>	'warning',
				'screen_ids'	=>	array( 'options-media' ),
				'persistent'	=>	true,
			);
			Bulk_Attachment_Download_Admin_Notice_Manager::add_notice( $args );
		} else {
			Bulk_Attachment_Download_Admin_Notice_Manager::delete_added_notice_from_all_users( 'htaccess_permissions_error' );
		}
	}

	return $value;
	
}

/**
 * Remove .htaccess file.
 * @param	int		$value		new setting value
 * @param	int		$old_value	old setting value
 * @return	int		returns value set to old value if failure
 */
function jabd_remove_htaccess( $value, $old_value ) {

	$htaccess_path = JABD_UPLOADS_DIR.JABD_DOWNLOADS_DIR.'/.htaccess';
				
	if ( file_exists( $htaccess_path ) ) {
		if ( ! @unlink( $htaccess_path ) ) {
			$disp_htaccess_path = str_replace( '\\', '/', str_replace( trim( ABSPATH, '/' ), '', $htaccess_path ) );
			$args = array(
				'id'		=>	'no_htaccess_delete',
				/* translators: Filepath to .htaccess file */
				'message'	=>	JABD_PLUGIN_NAME . ': ' . sprintf( __( 'The .htaccess file preventing direct access to your downloads could not be deleted. Please delete the file manually and then unset the Make downloads secure setting again. The file can be found at %s. Alternatively you may uninstall and re-install the plugin.', 'bulk-attachment-download' ), $disp_htaccess_path ),
				'screen_ids'	=>	array( 'options-media' ),
				'persistent'		=>	true
			);
			Bulk_Attachment_Download_Admin_Notice_Manager::add_notice( $args );
			$value = $old_value; // reset the options to old value to prevent update
		}
	} else {
		Bulk_Attachment_Download_Admin_Notice_Manager::delete_added_notice_from_all_users( 'no_htaccess_delete' );
	}

	return $value;

}

/*---------------------------------------------------------------------------------------------------------*/
/*Plugin page*/

/**
 * Add settings and guidance link to description on plugins page.
 * @hooked plugin_row_meta
 */
function jabd_plugin_row_meta( $links, $file ) {
	
	if ( strpos( $file, 'bulk-attachment-download.php' ) !== false ) {
		$new_links = array(
			'<a href="' . esc_url( admin_url( 'options-media.php#jabd-settings' ) ) . '">' . __( 'Settings and guidance', 'bulk-attachment-download' ) . '</a>'
			);
		
		$links = array_merge( $links, $new_links );
	}
	
	return $links;
	
}

/*---------------------------------------------------------------------------------------------------------*/
/*Admin notices*/

/**
 * Add no js error admin notices to Plugins page, Media settings, Media page, and Bulk Downloads page.
 * @hooked admin_notices
 */
function jabd_no_js_error_notice() {

	if ( function_exists( 'get_current_screen' ) ) {
		$screen = get_current_screen();
		if ( ! empty( $screen ) ) {
			if ( in_array( $screen->id, array( 'upload', 'plugins', 'edit-jabd_download', 'options-media' ) ) ) {
	?>
<noscript>
	<div class="notice notice-error is-dismissible">
		<p><strong><?php echo JABD_PLUGIN_NAME ?>: </strong><?php _e( 'This plugin does not function without Javascript enabled. Please enable Javascript or use another browser.', 'bulk-attachment-download' ); ?></p>
	</div>
</noscript>
	<?php
			}
		}
	}
}

/**
 * Add opt out admin notices
 * @hooked admin_init
 */
function jabd_add_opt_out_notices() {
	
	$opt_out_notices = array(
		'number_of_media_items'	=>	array(
			'message'			=>	'<strong>' . JABD_PLUGIN_NAME . ':</strong> ' . __( 'Don\'t forget you can change the number of media items per page (up to 999) by going to Screen Options at the top right of the screen.', 'bulk-attachment-download' ),
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
				/* translators: 1: Number of downloads 2: plugin name */
				__( 'Hi, you and your fellow administrators have downloaded %1$s times using our %2$s plugin – that’s awesome! If you\'re finding it useful and you have a moment, we\'d be massively grateful if you helped spread the word by rating the plugin on WordPress.', 'bulk-attachment-download' ),
				'<strong>' . $stored_options['download_count'] . '</strong>',
				'<strong>' . JABD_PLUGIN_NAME . '</strong>'
			) . '<br />';

			$review_link = 'https://wordpress.org/support/plugin/bulk-attachment-download/reviews/';
			
			// First option - give a review
			$rating_message .= '<span style="display: inline-block">' . Bulk_Attachment_Download_Admin_Notice_Manager::dismiss_on_redirect_link( array(
				'content'	=>	__( 'Sure, I\'d be happy to', 'bulk-attachment-download' ),
				'redirect'	=>	$review_link,
				'new_tab'	=>	true
			) ) . ' &nbsp;|&nbsp;&nbsp;</span>';
			
			// Second option - not now
			$rating_message .= '<span style="display: inline-block">' . Bulk_Attachment_Download_Admin_Notice_Manager::dismiss_event_button( array(
				'content'	=>	__( 'Nope, maybe later', 'bulk-attachment-download' ),
				'event'		=>	''
			) ) . ' &nbsp;|&nbsp;&nbsp;</span>';
			
			// Third option - already reviewed
			$rating_message .= '<span style="display: inline-block">' . Bulk_Attachment_Download_Admin_Notice_Manager::dismiss_event_button( array(
				'content'	=>	__( 'I already did', 'bulk-attachment-download' ),
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
		'name'					=> _x( 'Downloads', 'noun', 'bulk-attachment-download' ),
		'singular_name'			=> _x( 'Download', 'noun', 'bulk-attachment-download' ),
		'add_new'				=> _x( 'Add New', 'download item', 'bulk-attachment-download' ),
		'add_new_item'			=> __( 'Add New Download', 'bulk-attachment-download'),
		'edit_item'				=> __( 'Edit Download', 'bulk-attachment-download' ),
		'view_item'				=> __( 'View Download', 'bulk-attachment-download' ),
		'search_items'			=> __( 'Search Downloads', 'bulk-attachment-download' ),
		'not_found'				=> __( 'No Downloads found', 'bulk-attachment-download' ),
		'not_found_in_trash'	=> __('No Downloads found in Trash', 'bulk-attachment-download'), 
		'parent_item_colon'		=> '',
		'all_items'				=> __( 'Bulk downloads', 'bulk-attachment-download' ),
		'menu_name'				=> __( 'Bulk downloads', 'bulk-attachment-download' )
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
			if ( display_passwords() ) {
				$new_columns['jabd_download_pword'] = __( 'Zipfile Password', 'bulk-attachment-download' );
			}
			$new_columns['jabd_download_creator'] = __( 'Creator', 'bulk-attachment-download' );
			$new_columns['jabd_download_button'] = _x( 'Download', 'action', 'bulk-attachment-download' );
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
		
		case 'jabd_download_pword' :
			$pword = get_post_meta( $post->ID, 'jabd_pword', true );
			echo esc_html($pword);
			break;
		
		case 'jabd_download_creator' :
			$user = get_user_by( 'id', $post->post_author );
			echo esc_html($user->user_login);
			break;
			
		case 'jabd_download_button' :
		$disabled = ( 'trash' == $post->post_status || ! current_user_can( 'edit_post', $post->ID ) ) ? ' disabled' : '';
			echo '<a href="'.get_post_permalink( $post->ID ).'"><button class="button button-primary button-large" type="button"'.$disabled. '>'._x( 'Download', 'action', 'bulk-attachment-download' ).'</button></a>';
			break;
		
	}
}

/**
 * Checks whether passwords are to be displayed in the Downloads table.
 */
function display_passwords() {
	$options = get_option( 'jabd_options' );
	$display_pwds = false;
	if ( isset( $options['jabd_store_pwds'] ) ) {
		if ( $options['jabd_store_pwds'] ) {
			$display_pwds = true;
		}
	}
	return apply_filters( 'jabd_display_pass_words', $display_pwds );
}

/**
 * Amend post updated message.
 */
function jabd_post_updated_messages( $messages ) {
	global $post;
	if ( 'jabd_download' == $post->post_type ) {
		$messages['post'][1] = __( 'Download updated.', 'bulk-attachment-download' );
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
		$permissions_errors[] = __( 'Security checks failed.', 'bulk-attachment-download' );
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
		
		// Compatibilty with Formidable Forms Pro
		if ( class_exists( 'FrmProFileField' ) ) {
			remove_action( 'pre_get_posts', 'FrmProFileField::filter_media_library', 99 );
		}

		// get posts and check they are attachments
		$files_to_download = get_posts( array(
			'posts_per_page'		=> -1,
			'post_type'				=> 'attachment',
			'post__in'				=> $_POST['attmtIds'],
			'ignore_sticky_posts'	=> true
		) );

		// Compatibilty with Formidable Forms Pro
		if ( class_exists( 'FrmProFileField' ) ) {
			add_action( 'pre_get_posts', 'FrmProFileField::filter_media_library', 99 );
		}
		
		if ( empty( $files_to_download ) ) {
			$valid_file_ids = false;
		}

	}
	
	if ( ! $valid_file_ids ) {
		$permissions_errors[] = __( 'No valid files selected for download.', 'bulk-attachment-download' );
	} else {
		
		// check permissions
		foreach ( $files_to_download as $file_to_download ) {
			if ( current_user_can( 'edit_post', $file_to_download->ID ) ) {
				$permitted_files[] = $file_to_download;
			}
		}
		
		if ( empty( $permitted_files ) ) {
			$permissions_errors[] = __( 'You do not have permission to download any of the selected files.', 'bulk-attachment-download' );
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
				$file_info = '<div>'.sprintf( __( 'Files: %s','bulk-attachment-download' ), $download_data_display['count'] );
				if ( $download_data['count_incl_int'] > $download_data['count'] ) {
					/* translators: Number of files */
					$file_info .= sprintf( __( ' (%s if intermediate sizes included)' ,'bulk-attachment-download' ), $download_data_display['count_incl_int'] );
				}
				/* translators: Size of files */
				$file_info .= '</div><div>'.sprintf( __( 'Uncompressed files size: %s' ,'bulk-attachment-download' ), $download_data_display['size'] );
				if ( $download_data['count_incl_int'] > $download_data['count'] ) {
					/* translators: Number of files */
					$file_info .= sprintf( __( ' (%s if intermediate sizes included)' ,'bulk-attachment-download' ), $download_data_display['size_incl_int'] );
				}
				$file_info .= '</div>';
				
				$download_btn_html = '<button id="jabd-create-download" type="button" class="button button-primary button-large">'.__( 'Create download', 'bulk-attachment-download' ).'</button>&nbsp; ';
				
				$incl_int_sizes_default = isset( $settings['jabd_int_sizes'] ) ? $settings['jabd_int_sizes'] : 0;
				$jabd_no_folders = isset( $settings['jabd_no_folders'] ) ? $settings['jabd_no_folders'] : 0;
				
				$results_message =
'<div class="jabd-popup-text-block">
	<strong>'.__( 'File info', 'bulk-attachment-download' ).'</strong>
	'.$file_info.'
</div>';

				//give warning if we are over the file limit
				if ( ! $under_file_limit ) {
					$download_btn_html = '';
					$results_message .=
'<div class="jabd-popup-text-block" style="color: red">
	'.sprintf (
	/* translators: File size limit in MB */	
		__( 'Your selected files exceed the limit of %sMB', 'bulk-attachment-download' ), $max_file_size
	).'
</div>';
				} elseif ( ! $under_int_file_limit ) {
					$results_message .=
'<div class="jabd-popup-text-block" style="color: red">
	'.sprintf (
		/* translators: File size limit in MB */
		__( 'Downloading intermediate sizes will exceed limit of %sMB', 'bulk-attachment-download' ), $max_file_size
	).'
</div>';
				}
				
				if ( $under_file_limit ) {
				
					$results_message .=
'<div class="jabd-popup-text-block">
	<strong>'.__( 'Options', 'bulk-attachment-download' ).'</strong><br />
	<div'.( $under_int_file_limit ? '' : ' style="color: grey"' ).'>
		<input id="jabd-int-sizes-chkbox" type="checkbox" '.( $under_int_file_limit ? checked( $incl_int_sizes_default, true, false ) : 'style="cursor: default" disabled' ).'/>
		<label'.( $under_int_file_limit ? '' : ' style="cursor: default"' ).' for="jabd-int-sizes-chkbox">'.__( 'Include image intermediate sizes', 'bulk-attachment-download' ).'</label><br />
	</div>
	<div>
		<input id="jabd-no-folder-chkbox" type="checkbox" '.checked( $jabd_no_folders, true, false ).'/>
		<label for="jabd-no-folder-chkbox">'.__( 'Single folder download (any duplicate filenames will be amended)', 'bulk-attachment-download' ).'</label>
	</div>
</div>
<div class="jabd-popup-msg">
	<span>'.__( 'Download title (optional)', 'bulk-attachment-download' ).'&nbsp;</span>
	<input type="text" />
</div>';

					if ( isset( $settings['jabd_pwd_downloads'] ) ) {
						if ( $settings['jabd_pwd_downloads'] ) {
							$results_message .= '
<div class="jabd-popup-msg">
	<span>'.__( 'Password (optional)', 'bulk-attachment-download' ).'&nbsp;</span>
	<input id="zipfile-password" type="text" />
</div>';
						}					
					}
				}

				$results_message .=
	'<div class="jabd-popup-buttons">'.$download_btn_html.jabd_close_popup_btn( __( 'Cancel', 'bulk-attachment-download' ) ).'</div>';

				$ajax_result = array( 'messages' => $results_message );
		
			//now create download if we are downloading
			} elseif ( 'download' == $doaction && empty( $permissions_errors ) && $under_file_limit ) { //downloading
				
				// create downloads dir if necessary
				if ( ! file_exists( JABD_UPLOADS_DIR.JABD_DOWNLOADS_DIR ) ) {
					mkdir( JABD_UPLOADS_DIR.JABD_DOWNLOADS_DIR, 0755 );
				}
				
				// create user folder if necessary
				$user_id = get_current_user_id();
				$zip_dir = JABD_UPLOADS_DIR.JABD_DOWNLOADS_DIR.'/'.$user_id;
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
				$zip_path = JABD_UPLOADS_DIR.JABD_DOWNLOADS_DIR.'/'.$rel_zip_path;
				if ( $name_count > 0 ) {
					$post_title .= $name_count;
				}

				// Get the zip password and set to default if necessary.
				$zip_pword = sanitize_text_field( $_POST['pword'] );
				if ( empty( $zip_pword ) ) {
					if ( isset( $settings['jabd_default_pwd'] ) ) {
						if ( ! empty( $settings['jabd_default_pwd'] ) ) {
							$zip_pword = $settings['jabd_default_pwd'];
						}
					}
				}
				$zip_pword = apply_filters( 'jabd_zip_password', $zip_pword );
				
				// create the zip file
				if ( class_exists( 'ZipArchive' ) ) {
					
					$zip = new ZipArchive();
					$zip_opened = $zip->open( $zip_path, ZipArchive::CREATE );
					
					if ( true === $zip_opened ) {
					
						if ( $zip_pword ) {
							$zip->setPassword( $zip_pword );
						}
						
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
								
								$added_rel_filepaths = jabd_add_file_to_zip( $zip, $file_path, $relative_file_path, $added_rel_filepaths, $zip_pword );

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
											
											$added_rel_filepaths = jabd_add_file_to_zip( $zip, $int_file_path, $int_rel_filepath, $added_rel_filepaths, $zip_pword );

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

							$meta_input = array(
								'jabd_path'		=> addslashes( $rel_zip_path ),
								'jabd_expiry'	=> date( 'Y-m-d H:i:s', strtotime( '+2 hours' ) )
							);

							$store_pwd = false;
							if ( isset( $settings['jabd_store_pwds'] ) ) {
								if ( $settings['jabd_store_pwds'] ) {
									$store_pwd = true;
								}
							}
							if ( $zip_pword && $store_pwd ) {
								$meta_input['jabd_pword'] = $zip_pword;
							}

							$download_id = wp_insert_post( array(
								'post_title'	=> $post_title,
								'post_type'		=> 'jabd_download',
								'post_status'	=> 'publish',
								'meta_input'	=> $meta_input
							) );
							
							$results_msg = '<div class="jabd-popup-msg"><span>'.__( 'Download created!', 'bulk-attachment-download' ).'</span></div>';
							$results_view_btn = '<a href = "'.admin_url( 'edit.php?post_type=jabd_download' ).'"><button class="button button-primary button-large">'.__( 'View', 'bulk-attachment-download' ).'</button></a>&nbsp; ';
							$results_close_btn = '<button id="jabd-close-download-popup" class="button button-primary button-large">'.__( 'Close', 'bulk-attachment-download' ).'</button>';
							$results_btns = '<div class="jabd-popup-buttons">'.$results_view_btn.$results_close_btn.'</div>';
							
							$ajax_result = array(
								'messages'	=> $results_msg.$results_btns
							);
						
						} else { //zip file does not exist
							$permissions_errors[] = __( 'Error. Your download could not be created.', 'bulk-attachment-download' );
						}
						
					} else { // zip file could not be created
						$permissions_errors[] = __( 'Error. Your download could not be created.', 'bulk-attachment-download' );
					}
					
				} else { // ziparchive class not found
					$permissions_errors[] = __( 'Error. Your download could not be created. It looks like you don\'t have ZipArchive installed on your server.', 'bulk-attachment-download' );
				}
				
			} else { // no action specified in ajax posted data
				$permissions_errors[] = __( 'Error. Your download could not be created.', 'bulk-attachment-download' );
			}
			
		} else { // no valid files selected
			$permissions_errors[] = __( 'Error. No files selected that you are permitted to download.', 'bulk-attachment-download' );
		}

	}
	
	if ( ! empty( $permissions_errors ) ) {
		
		$results_msg = '<div class="jabd-popup-msg"><span>'.$permissions_errors[0].'</span></div>';
		
		$ajax_result = array(
			'messages'	=> $results_msg.jabd_close_popup_btn( __( 'Close', 'bulk-attachment-download' ) )
		);
		
	}
	
	// send response
	echo wp_json_encode( $ajax_result );
	wp_die();
	
}

/**
 * Add file to zip making sure filename is unique
 */
function jabd_add_file_to_zip( $zip, $file_path, $relative_file_path, $added_rel_filepaths, $zip_pword ) {
	
	// if there is another file with the same name in the zip file already then amend filename
	$relative_file_path = jabd_unique_filepath_in_filepaths_array( $relative_file_path, $added_rel_filepaths );
	
	// add the file using the relative file path
	if ( $zip->addFile( $file_path, $relative_file_path ) ) {
		$added_rel_filepaths[] = $relative_file_path;
		
		// encrypt if password-protected
		if ( $zip_pword ) {
			$file_encrypted = $zip->setEncryptionName( $relative_file_path, ZipArchive::EM_AES_256 );
		}
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
	if ( $zip_path = JABD_UPLOADS_DIR.JABD_DOWNLOADS_DIR.'/'.get_post_meta( $post_id, 'jabd_path', true ) ) {
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