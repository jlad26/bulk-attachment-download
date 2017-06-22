<?php

/**
 * Plugin Name:		Bulk Attachment Download
 * Plugin URI:		https://wordpress.org/plugins/bulk-attachment-download/
 * Description:		Allows bulk downloading of attachments from the Media Library
 * Version:			1.1.5
 * Author:			Jon Anwyl
 * Author URI:		http://www.sneezingtrees.com
 * Text Domain:		st-bulk-download
 * Domain Path:		/languages
 * License:			GPL2
 * License URI:		https://www.gnu.org/licenses/gpl-2.0.html
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/*---------------------------------------------------------------------------------------------------------*/
/*Setup*/

//define constants
if ( ! defined( 'JABD_PLUGIN_DIR' ) ) define( 'JABD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'JABD_PLUGIN_BASE_URL' ) ) define( 'JABD_PLUGIN_BASE_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'JABD_DOWNLOADS_DIR' ) ) define( 'JABD_DOWNLOADS_DIR', 'jabd-downloads' );
if ( ! defined( 'JABD_VERSION' ) ) define( 'JABD_VERSION', '1.1.5' );

//include functions
require_once JABD_PLUGIN_DIR.'incl/jabd-functions.php';

//include admin notice manager class and initialize
require_once JABD_PLUGIN_DIR.'incl/admin-notice-manager/class-admin-notice-manager.php';
Bulk_Attachment_Download_Admin_Notice_Manager::init( array(
	'manager_id'		=>	'jabd',
	'text_domain'		=>	'st-bulk-download',
	'version'			=>	'1.1.5'
) );

//internationalization
add_action( 'plugins_loaded', 'jabd_load_plugin_textdomain' );

/*---------------------------------------------------------------------------------------------------------*/
/*Plugin activation, deactivation and upgrade*/
register_activation_hook( __FILE__, 'jabd_on_activation' );
register_deactivation_hook( __FILE__, 'jabd_on_deactivation' );
add_action('plugins_loaded', 'jabd_check_version');

/*---------------------------------------------------------------------------------------------------------*/
/*Plugin settings*/
add_action( 'admin_init', 'jabd_init_settings' );
add_filter( 'pre_update_option_jabd_options', 'jabd_before_options_update', 10, 3 ); //run actions on change of settings

/*---------------------------------------------------------------------------------------------------------*/
/*Admin notices*/
add_action( 'admin_init', 'jabd_add_opt_out_notices' );
add_filter( 'jabd_display_opt_out_notice', 'jabd_conditional_display_admin_notice', 10, 2 ); //conditional check on display

/*---------------------------------------------------------------------------------------------------------*/
/*Add admin js and css*/
add_action( 'admin_enqueue_scripts', 'jabd_admin_enqueue_scripts' );

/*---------------------------------------------------------------------------------------------------------*/
/*Download custom post type*/

//register custom post type
add_action( 'init', 'jabd_register_download_post_type' );

//prevent add new post functionality
add_action( 'load-post-new.php', 'jabd_prevent_add_new_download' );

//on manual post deletion delete zip file
add_action( 'before_delete_post', 'jabd_delete_download_zip' );

//add columns to post list
add_filter( 'manage_jabd_download_posts_columns' , 'jabd_add_link_columns' );
add_action( 'manage_jabd_download_posts_custom_column', 'jabd_add_link_columns_content' );

//amend display and messages for download post
add_filter( 'post_updated_messages', 'jabd_post_updated_messages' );

/*---------------------------------------------------------------------------------------------------------*/
/*Redirect to get download*/

add_filter('single_template', 'jabd_download_template');

/*---------------------------------------------------------------------------------------------------------*/
/*Handle ajax*/

//Process ajax upload
add_action( 'wp_ajax_jabd_request_download', 'jabd_request_download' );
