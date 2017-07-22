// Admin upload jquery
jQuery(document).ready(function($) {

	/*---------------------------------------------------------------------------------------------------------*/
	/*On page load*/

	var popupClosable = true; // Variable to set whether popup can be closed or not

	// Add option to bulk actions
	$('#bulk-action-selector-top, #bulk-action-selector-bottom').each( function() {
		var newOption = '<option value="jabd-download">' + jabd_downloader.download_option + '</option>';
		$(this).append(newOption);
	});
	
	// On form submission, hijack the process if the download option has been selected
	$('#posts-filter').submit( function(e){

		// Check if Download has been selected
		if (
			$('#bulk-action-selector-top').val() != 'jabd-download' &&
			$('#bulk-action-selector-bottom').val() != 'jabd-download'
		) {
			return true;
		} else {

			// Prevent form submitting
			e.preventDefault();

			// Check if checkboxes have been checked
			var attmtIds = getAttmtIds();
			if ( attmtIds.length > 0 ) {

				// Request data
				$.ajax({
					url : ajaxurl,
					type : 'post',
					beforeSend: function() {
						displayGatheringData();
					},
					data : {
						action			: 'jabd_request_download',
						doaction		: 'getdata',
						downloadNonce	: jabd_downloader.download_nonce,
						attmtIds		: attmtIds,
					},
					xhrFields: {
						withCredentials: true
					},
					success : function( response ) {
						requestResponse( response );
					}
				});
				
			} else {
				return false;
			}

		}

    });

	// On click of download button, create the download
	$('body').on( 'click', '#jabd-create-download', function() {
		processDownloadRequest();
	});

	// Handle close of download form
	$('body').on( 'click', '.jabd-popup-overlay, .jabd-popup-container, #jabd-close-download-popup', function() {
		if (popupClosable) { closeDownloadRequestPopup(); }
	});
	$('body').on( 'click', '.jabd-popup', function(evt) {
		evt.stopPropagation();
	});
	
	// Display gathering data message
	function displayGatheringData() {
		openDownloadRequestPopup();
		var downloadLaunchedHtml = '<div class="jabd-popup-msg"><span>' +
			jabd_downloader.gathering_data_msg + '</span><div class="spinner is-active"></div></div>';
		setPopupContents( downloadLaunchedHtml );
		popupClosable = false;
	}
	

	/*---------------------------------------------------------------------------------------------------------*/
	/*Download request popup*/

	// Create popup
	function openDownloadRequestPopup() {
		popupClosable = true;
		var popupHtml = '<div class="jabd-popup-overlay"></div><div class="jabd-popup-container"><div class="jabd-popup"></div></div>';
		$('body').append(popupHtml);
	}

	// Set popup contents
	function setPopupContents( popupContents ) {
		$('.jabd-popup').html( popupContents );
	}

	// Close the popup
	function closeDownloadRequestPopup() {
		$( '.jabd-popup-overlay, .jabd-popup-container' ).remove();
	}

	/*---------------------------------------------------------------------------------------------------------*/
	/*Process download request functions*/

	// Manage the download request
	function processDownloadRequest() {

		var attmtIds = getAttmtIds();
		var downloadNonce = '';
		var title = $( '.jabd-popup-msg input[type="text"]' ).val();
		var intsizes = $( '#jabd-int-sizes-chkbox' ).prop( 'checked' );
		var nofolders = $( '#jabd-no-folder-chkbox' ).prop( 'checked' );

		jQuery.ajax({
			url : ajaxurl,
			type : 'post',
			beforeSend: function() {
				downloadLaunchedMessage();
			},
			data : {
				action			: 'jabd_request_download',
				doaction		: 'download',
				downloadNonce	: jabd_downloader.download_nonce,
				attmtIds		: attmtIds,
				title			: title,
				intsizes		: intsizes,
				nofolders		: nofolders
			},
			xhrFields: {
				withCredentials: true
			},
			success : function( response ) {
				requestResponse( response );
			}
		});

	}

	// Get the selected attachment ids from the checked checkboxs
	function getAttmtIds() {
		var attmtIds = [];
		$('#the-list .check-column input[type="checkbox"]').each( function() {
			if( $(this).prop('checked') ) {
				attmtIds.push( $(this).val() );
			}
		});
		return attmtIds;
	}

	// Show message that download request has been initiated
	function downloadLaunchedMessage() {
		var downloadLaunchedHtml = '<div class="jabd-popup-msg"><span>' +
			jabd_downloader.download_launched_msg + '</span><div class="spinner is-active"></div></div>';
		setPopupContents( downloadLaunchedHtml );
		popupClosable = false;
	}


	// Show results - either link to download or error message
	function requestResponse( response ) {
		var result = JSON.parse(response);
		popupClosable = true;
		setPopupContents( result.messages );
		//give focus to download title field if container is displaying at full height and not scrollable
		var div = $('.jabd-popup').get(0);
		if ( div.scrollHeight <= div.clientHeight ) {
			$('.jabd-popup-msg input[type="text"]').focus();
		}
	}
	
});