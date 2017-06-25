=== Bulk Attachment Download ===
Contributors: janwyl
Tags: media, attachments, bulk download, download
Requires at least: 4.6.1
Tested up to: 4.8
Stable tag: 1.1.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Allows bulk downloading of attachments from the media library.

== Description ==

Allows bulk downloading of attachments from the media library.

A 'Download' option is added to the 'Bulk Actions' dropdown in the Media Library.
Choose the attachments you want to download, click 'Apply', and a zip file of those attachments is created that you can then download.

Before the zip file is created, you'll see a) how many files will be downloaded, and b) how big the uncompressed files are.
You are also given the option to:

* Include or exclude image intermediate sizes.
* Include in your download the folder structure you use in your uploads folder (e.g. year/month) or have all files downloaded in a single folder.

You can set a maximum (uncompressed) file size to be downloaded in the plugin settings, found in Settings > Media.

Zip files are automatically removed in 1 - 2 hours, or you can delete them yourself.

If you want to keep the download files inaccessible to others, you can use the 'Make downloads secure' option in Settings > Media.
This creates a .htaccess file in the folder where the download zip files are kept, preventing direct access.
However there's no point in using this feature unless you also have some means of preventing direct access to the attachments themselves
in the Uploads folder.


== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/plugin-name` directory, or install the plugin through the WordPress plugins screen directly.
1. Activate the plugin through the 'Plugins' screen in WordPress

The 'Download' option will now appear in the 'Bulk Actions' dropdown of the Media Library (when in List mode).


== Frequently Asked Questions ==

= Where do I find my downloads? =

Click on 'Bulk downloads' under 'Media'.

= How can I increase the number of attachments I can download in one go? =

To increase the number of attachments you can see on the screen at once, click on 'Screen Options' at the top right of the Media Library,
and increase the 'Number of items per page'.

= What's the maximum number of attachments I can download at once? =

It depends. The theoretical absolute maximum is 999. That's the maximum you can set for 'Number of items per page' in the Media Library (see above).
But there may be other constraints depending on your host setup, such as max script execution time, file size limits and memory limits.
Whether you reach those constraints will depend on the number and size of files you are trying to download in one go.
If you see error messages or your zip file is incomplete or corrupted, try several smaller downloads instead of one big one.

= How long does it take for the zip file to be created? =

That depends on the number and size of files you are downloading, and also on your host setup.
Try downloading smaller numbers of files to get a feel for how long it takes before attempting a large download.

= Who can create downloads? =

Permissions are set so that:
* Anyone who has the capability 'upload_files' can create downloads.
* A user can download an attachment if that user has permission to edit the attachment.
* Only users who have the capability 'manage_options' can download, edit, or delete a download that another user has created.

That means that if the Wordpress default roles and capabilities are being used:
* Administrators and editors can download any attachments.
* Authors and contributors can download only those attachments they uploaded.
* Only administrators can download, edit or delete a download created by another user.

= What filters are available? =

* `jabd_max_files_size`. Max download file size limit is set in the plugin settings in Settings > Media,
but if you wanted to set the file size per user you could use this filter.


== Screenshots ==

1. 'Download' option added to the 'Bulk Actions' dropdown.
2. Downloads are stored for 1 - 2 hours before being deleted.


== Changelog ==

= 1.2.0 =

Release date: 25 June 2017

* Maintenance: Change the permissions for downloads so that they match the permissions for managing attachments generally.
* Maintenance: Remove the filter jabd_user_can_download.
* Bug fix: All download post statuses (including private) are now deleted automatically by cron.

= 1.1.4 =

Release date: 12 June 2017

* Bug fix: Properly include admin notice manager class.

= 1.1.3 =

Release date: 12 June 2017

* Bug fix: Remove warning when when adding new post.
* Maintenance: Refactor admin notices.
* Maintenance: Improve activate / deactivate / uninstall security.

= 1.1.2 =

Release date: 1 June 2017

* Maintenance: Added missing translation strings.
* Bug fix: Disable download button on download posts in Bin.

= 1.1.1 =

Release date: 19 January 2017

* Maintenance: Add dismissable reminders that a) bulk download function is only available in list mode and
b) media items per page can be changed using Screen Options.

= 1.1.0 =

Release date: 19 December 2016

* Enhancement: Give option to include intermediate sizes.
* Enhancement: Give option to have all files in single folder in zip instead of replicating structure within uploads folder.
* Enhancement: Setting to limit uncompressed download size. Also display file count and size info before download.
* Maintenance: Fix undefined index notice on saving settings.
* Maintenance: Remove options to 'view' or 'preview' a download as this just triggers a download.

= 1.0.2 =

Release date: 7 December 2016

* Bug fix: rewrite rules now flushed on activation to prevent 404 on attempted download 

= 1.0.1 =

* Initial version on Wordpress.org plugin repository
