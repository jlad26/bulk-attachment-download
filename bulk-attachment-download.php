<?php

/**
 * Plugin Name:		Bulk Attachment Download
 * Plugin URI:		https://wordpress.org/plugins/bulk-attachment-download/
 * Description:		Bulk download media or attachments selectively from your Media Library as a zip file.
 * Version:			1.3.1
 * Author:			Jon Anwyl
 * Author URI:		https://www.sneezingtrees.com
 * Text Domain:		bulk-attachment-download
 * Domain Path:		/languages
 * License:			GPLv2
 * License URI:		https://www.gnu.org/licenses/gpl-2.0.html
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/*---------------------------------------------------------------------------------------------------------*/
/*Setup*/

// define constants
if ( ! defined( 'JABD_PLUGIN_NAME' ) ) define( 'JABD_PLUGIN_NAME', 'Bulk Attachment Download' );
if ( ! defined( 'JABD_PLUGIN_DIR' ) ) define( 'JABD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'JABD_PLUGIN_BASE_URL' ) ) define( 'JABD_PLUGIN_BASE_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'JABD_DOWNLOADS_DIR' ) ) define( 'JABD_DOWNLOADS_DIR', 'jabd-downloads' );
if ( ! defined( 'JABD_VERSION' ) ) define( 'JABD_VERSION', '1.3.1' );

// include functions
require_once JABD_PLUGIN_DIR . 'incl/jabd-functions.php';

// define uploads constant here so that it's available for uninstall process
jabd_define_uploads_folder();

//include admin notice manager class and initialize
require_once JABD_PLUGIN_DIR . 'incl/admin-notice-manager/class-admin-notice-manager.php';
Bulk_Attachment_Download_Admin_Notice_Manager::init( array(
	'plugin_name'		=>	'Bulk Attachment Download',
	'manager_id'		=>	'jabd',
	'text_domain'		=>	'bulk-attachment-download',
	'version'			=>	JABD_VERSION
) );

// internationalization
add_action( 'plugins_loaded', 'jabd_load_plugin_textdomain' );

/*--------------------------------------------------------------------------------------------------*/
/* Code for integration with Freemius functionality (https://freemius.com/wordpress/insights/) */

if ( ! function_exists( 'jabd_fs' ) ) {
    // Create a helper function for easy SDK access.
    function jabd_fs() {
        global $jabd_fs;

        if ( ! isset( $jabd_fs ) ) {
            // Include Freemius SDK.
            require_once dirname( __FILE__ ) . '/freemius/start.php';

            $jabd_fs = fs_dynamic_init( array(
                'id'                  => '1226',
                'slug'                => 'bulk-attachment-download',
                'type'                => 'plugin',
                'public_key'          => 'pk_b313e39f6475c257bc3aadfbc55df',
                'is_premium'          => false,
                'has_addons'          => false,
                'has_paid_plans'      => false,
                'menu'                => array(
                    'first-path'     => 'plugins.php',
                    'account'        => false,
                    'contact'        => false,
                    'support'        => false,
                ),
            ) );
        }

        return $jabd_fs;
    }

    // Init Freemius.
    jabd_fs();
    // Signal that SDK was initiated.
    do_action( 'jabd_fs_loaded' );
}

// Hook in uninstall actions.
jabd_fs()->add_action( 'after_uninstall', 'jabd_fs_uninstall_cleanup' );

function jabd_fs_uninstall_cleanup() {

	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

    $options = get_option( 'jabd_options' );
    $delete = false;
    if ( isset( $options['jabd_delete_on_uninstall'] ) ) {
        if ( $options['jabd_delete_on_uninstall'] ) {
            $delete = true;
        }
    }
    
    if ( $delete ) {
    
        // delete all downloads
        jabd_delete_download_posts( $only_expired = false );

        // delete .htaccess (if it exists) and downloads folder
        jabd_remove_htaccess( 1, 1 );
        $uploads_dir_info = wp_upload_dir();
        @rmdir( $uploads_dir_info['basedir'] . '/' . JABD_DOWNLOADS_DIR );

    }

	// remove deprecated options and usermeta
	delete_option( 'jabd_notices' );
	delete_metadata( 'user', 0, 'jabd_dismissed_notices', false, true );

	// delete options, notices and usermeta
	delete_option( 'jabd_version' );
	delete_option( 'jabd_options' );
	delete_option( 'jabd_storage' );
	Bulk_Attachment_Download_Admin_Notice_Manager::remove_all_data();
	
}

/*---------------------------------------------------------------------------------------------------------*/
/* Plugin activation, deactivation and upgrade */
register_activation_hook( __FILE__, 'jabd_on_activation' );
register_deactivation_hook( __FILE__, 'jabd_on_deactivation' );
add_action('plugins_loaded', 'jabd_check_version');

/*---------------------------------------------------------------------------------------------------------*/
/* Plugin settings */
add_action( 'admin_init', 'jabd_init_settings' );
add_filter( 'pre_update_option_jabd_options', 'jabd_before_options_update', 10, 3 ); //run actions on change of settings

/*---------------------------------------------------------------------------------------------------------*/
/* Admin notices */
add_action( 'admin_init', 'jabd_add_opt_out_notices' );
add_action( 'admin_notices', 'jabd_no_js_error_notice' );
add_filter( 'jabd_display_opt_out_notice', 'jabd_conditional_display_admin_notice', 10, 2 ); //conditional check on display
$count_triggers = array( 25, 10, 3 );
foreach ( $count_triggers as $count_trigger ) {
	add_action( 'jabd_user_notice_dismissed_ratings_request_' . $count_trigger . '_prevent_rating_request', 'jabd_prevent_rating_request' );
}

/*---------------------------------------------------------------------------------------------------------*/
/* Add settings and guidance link to description on plugins page */
add_filter( 'plugin_row_meta', 'jabd_plugin_row_meta', 10, 2 );

/*---------------------------------------------------------------------------------------------------------*/
/* Add admin js and css */
add_action( 'admin_enqueue_scripts', 'jabd_admin_enqueue_scripts' );

/*---------------------------------------------------------------------------------------------------------*/
/* Download custom post type */

// Register custom post type.
add_action( 'init', 'jabd_register_download_post_type' );

// Prevent add new post functionality.
add_action( 'load-post-new.php', 'jabd_prevent_add_new_download' );

// On manual post deletion delete zip file.
add_action( 'before_delete_post', 'jabd_delete_download_zip' );

// Sdd columns to post list.
add_filter( 'manage_jabd_download_posts_columns' , 'jabd_add_link_columns' );
add_action( 'manage_jabd_download_posts_custom_column', 'jabd_add_link_columns_content' );

// Amend display and messages for download post.
add_filter( 'post_updated_messages', 'jabd_post_updated_messages' );

/*---------------------------------------------------------------------------------------------------------*/
/* Redirect to get download */

add_filter('single_template', 'jabd_download_template');

/*---------------------------------------------------------------------------------------------------------*/
/* Handle ajax */

// Process ajax upload.
add_action( 'wp_ajax_jabd_request_download', 'jabd_request_download' );
