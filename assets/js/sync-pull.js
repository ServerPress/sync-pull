/*
 * @copyright Copyright (C) 2015-2019 WPSiteSync.com. - All Rights Reserved.
 * @license GNU General Public License, version 2 (http://www.gnu.org/licenses/gpl-2.0.html)
 * @author WPSiteSync.com <hello@WPSiteSync.com>
 * @url https://wpsitesync.com/downloads/wpsitesync-for-pull/
 * The PHP code portions are distributed under the GPL license. If not otherwise stated, all images, manuals, cascading style sheets, and included JavaScript *are NOT GPL, and are released under the SpectrOMtech Proprietary Use License v1.0
 * More info at https://wpsitesync.com/downloads/
 */

function WPSiteSyncContent_Pull()
{
	this.searching = false;								// true when keyup triggers search process
	this.target_post_id = null;							// post id of content on Target site
	this.post_id = null;								// post id of content on Source site
	this.$dialog = null;								// jQuery reference to dialog instance if it exists
}

WPSiteSyncContent_Pull.prototype.init = function()
{
	this.log('=initializing...');
//	var dialog = jQuery('.ui-dialog.sync-pull-search');
//	if (0 !== dialog.length)
//		this.$dialog = dialog;
};

/**
 * Performs logging to the console
 * @param {string} msg The message to be displayed
 * @param {object} data Any data to output along with the message
 */
WPSiteSyncContent_Pull.prototype.log = function(msg, data)
{
	if ('undefined' !== typeof(console.log)) {
		console.log('.pull: ' + msg);
		if ('undefined' !== typeof(data))
			console.log(data);
	}
};

/**
 * Displays a message within the Dialog if present, or call WPSiteSync core message if not
 * @param {string} msg The message to display
 * @param {boolean|null} anim True to display animation image
 * @param {boolean|null} dismiss True to display dismiss button
 * @param {string|null} css_class CSS class to add to the message container
 * @returns {undefined}
 */
WPSiteSyncContent_Pull.prototype.set_message = function(msg, anim, dismiss, css_class)
{
	if (null !== this.$dialog) {
		var $dlg = wpsitesynccontent.pull.$dialog;

		jQuery('#sync-message', $dlg).attr('class', '').html(msg);
		if ('string' === typeof(css_class))
			jQuery('#sync-message', $dlg).addClass(css_class);

		if ('boolean' === typeof(anim) && anim)
			jQuery('#sync-content-anim', $dlg).show();
		else
			jQuery('#sync-content-anim', $dlg).hide();

		if ('boolean' === typeof(dismiss) && dismiss)
			jQuery('#sync-message-dismiss', $dlg).show();
		else
			jQuery('#sync-message-dismiss', $dlg).hide();

		jQuery('#sync-message-container', $dlg).show();
	} else {
		wpsitesynccontent.set_message(msg, anim, dismiss, css_class);
	}
};

/**
 * Hides the message area within the dialog if present, or call WPSiteSync core if not
 */
WPSiteSyncContent_Pull.prototype.clear_message = function()
{
	if (null !== this.$dialog) {
		var $dlg = wpsitesynccontent.pull.$dialog;

		jQuery('#sync-message-container', $dlg).hide();
		jQuery('#sync-message', $dlg).empty();
		jQuery('#sync-content-anim', $dlg).hide();
		jQuery('#sync-message-dismiss', $dlg).hide();
	} else {
		wpsitesynccontent.clear_message();
	}
};

/**
 * Add Dialog Modal
 * @param {int} post_id The post id
 */
WPSiteSyncContent_Pull.prototype.show_dialog = function(post_id)
{
this.log('show_dialog()');

	if ('undefined' !== typeof(post_id))
		this.post_id = post_id;

	var message_container = jQuery('#sync-contents #sync-message-container').prop('outerHTML');

	jQuery('#sync-contents #sync-message-container').replaceWith('<div id="sync-temp"></div>');

	jQuery('#sync-pull-dialog').dialog({
		resizable: true,
		height: 'auto',
		width: 700,
		modal: true,
		zindex: 1001,
		dialogClass: 'sync-pull-search', // 'wp-dialog'
		closeOnEscape: true,
		close: function(event, ui) {
			jQuery('#sync-temp').replaceWith(message_container);
			wpsitesynccontent.pull.$dialog = null;						// dialog closed, clear the $dialog property
		},
		open: function(event, ui) {
console.log('dialog is open, calling setup_handlers');
			wpsitesynccontent.pull.setup_handlers();
		}
	});

	// if not on the edit page, need to adjust the z-index of the dialog box and the overlay
	var pagename = window.location + '';
this.log('show_dialog() pagename=' + pagename);
	if (-1 === pagename.indexOf('edit.php')) {
		jQuery('.ui-widget-overlay.ui-front').css('z-index', '1000');
		jQuery('.ui-dialog.ui-widget.ui-widget-content.sync-pull-search').css('z-index', '1001');
	}

//this.log('show_dialog() calling setup handlers');
//	this.setup_handlers();
};

/**
 * Shows the Pull UI area
 */
WPSiteSyncContent_Pull.prototype.show = function()
{
this.log('show()');
	this.hide_msgs();
	jQuery('#sync-pull-container').show();
	jQuery('#sync-pull').hide();
};

/**
 * Pull content from a target site
 * @param {int} post_id The post ID from the current site
 * @param {int} confirmation Whether the request is confirmed or not
 * @todo no longer used?
 */
WPSiteSyncContent_Pull.prototype.action = function(post_id, confirmation)
{
this.log('action(' + post_id + ')');

	var data = { action: 'spectrom_sync', operation: 'pull', post_id: post_id };
this.log('data=', data);

	jQuery('.pull-actions').hide();
	jQuery('.pull-loading-indicator').show();
	wpsitesynccontent.pull.set_message(jQuery('#sync-msg-pull-working').text(), true);

	jQuery.ajax({
		type: 'post',
		async: true, // false,
		data: data,
		url: ajaxurl,
		success: function(response) {
wpsitesynccontent.pull.log('in ajax success callback');
//			jQuery('.pull-actions').show();
//			jQuery('.pull-loading-indicator').hide();

wpsitesynccontent.pull.log(' - response:', response);
			if (response.success) {
//				jQuery('.tb-close-icon').trigger('click');
//				alert(response.notices[0]);
				// reload page to show new content
				wpsitesynccontent.pull.set_message(jQuery('#sync-msg-pull-complete').text());
				window.location.reload();
			} else if (0 !== response.error_code) {
				// TODO: write to error message part of DOM
				wpsitesynccontent.pull.set_message(response.error_message, false, true, 'sync-error');
			} else {
				// TODO: use a dialog box not an alert
wpsitesynccontent.pull.log('Failed to execute API.');
//				alert('Failed to fetch data.');
			}
		}
	});
};

/**
 * Cancels the Pull operation, closes the UI area
 */
WPSiteSyncContent_Pull.prototype.cancel = function()
{
this.log('cancel()');
	jQuery('#sync-pull-container').hide();
	jQuery('#sync-pull').show();
};

/**
 * Checks to see if content has been modified on the Target
 * @param {type} xhr
 * @param {type} opts
 * @returns {undefined}
 */
WPSiteSyncContent_Pull.prototype.check_modified_timestamp = function(xhr, opts)
{
this.log('check_modified_timestamp()');
	var data = {
		action: 'spectrom_sync',
		operation: 'check_modified_timestamp',
		post_id: wpsitesynccontent.post_id,
		_sync_nonce: jQuery('#_sync_nonce').val()
	};
	jQuery.ajax({
		type: 'post',
		data: data,
		url: ajaxurl,
		success: function(response) {
			if (!response.success) {
				var continue_push = confirm(response.notices[0]);

				if (!continue_push)
					xhr.abort();
			}
		}
	});
};

/**
 * Hides all messages within the Pull UI area
 * @returns {undefined}
 */
WPSiteSyncContent_Pull.prototype.hide_msgs = function()
{
this.log('hide_msgs()');
	jQuery('#pull-loading-indicator').hide();
	jQuery('#pull-failure-msg').hide();
	jQuery('#pull-success-msg').hide();
	jQuery('#sync-msg-pull-working').hide();
	jQuery('#sync-msg-pull-complete').hide();
};

/**
 * Call the Pull API
 * @param {int} target_post_id The Target post id
 */
WPSiteSyncContent_Pull.prototype.pull = function(target_post_id)
{
	// check for post_id
	if (0 === this.post_id && ! target_post_id)
		return;

	if (!this.target_post_id && target_post_id) {
		this.target_post_id = target_post_id;
	}

	jQuery('.pull-actions').hide();
	jQuery('.pull-loading-indicator').show();
	wpsitesynccontent.pull.set_message(jQuery('#sync-msg-pull-working').text(), true);

	var values = {};
	values.content = jQuery('input[name="sync-pull-where"]:checked').val();

	if (this.target_post_id) {
		values.target_id = this.target_post_id;
	}

	wpsitesynccontent.inited = true;
	wpsitesynccontent.api('pull', this.post_id, jQuery('#sync-msg-pull-working').text(), jQuery('#sync-msg-pull-complete').text(), values);
};

/**
 * Calls the pullsearch API
 */
WPSiteSyncContent_Pull.prototype.search = function()
{
wpsitesynccontent.pull.log('.search()');
	if (!this.searching) {
		this.searching = true;
		wpsitesynccontent.inited = true;
		jQuery('#sync-pull-selected').prop('disabled', true);
		jQuery('#sync-pull-dialog #sync-details').hide();

		wpsitesynccontent.pull.set_message(jQuery('#sync-msg-pull-searching').text(), true);

if ('undefined' === typeof(syncpulldata))
	alert('the syncpulldata object is not defined');

		var data = {
			action: 'spectrom_sync',
			operation: 'pullsearch',
			posttype: syncpulldata.post_type, // typenow,
			search: jQuery('#sync-pull-search', wpsitesynccontent.pull.$dialog).val(),
			_sync_nonce: jQuery('#_sync_nonce').val()
		};
		// TODO: convert to use wpsitesynccontent.api()
		jQuery.ajax({
			type: 'post',
			data: data,
			url: ajaxurl,
			success: function (response)
			{
wpsitesynccontent.pull.log('response=', response);
				wpsitesynccontent.clear_message();
				if (response.success) {
					jQuery('.ui-dialog.sync-pull-search #sync-pull-search-results').html(response.data.search_results).show();
					wpsitesynccontent.pull.clear_message();
				} else if (0 !== response.error_code) {
					wpsitesynccontent.pull.set_message(response.error_message, false, false, 'sync-error');
				} else {
wpsitesynccontent.pull.log('Failed to execute API.');
				}
			}
			// TODO: implement callback for failure; display a message
		});
	}

	this.searching = false;
};

/**
 * Initializes handlers for UI elements within dialog
 */
WPSiteSyncContent_Pull.prototype.setup_handlers = function()
{
this.log('.setup_handlers()');
	var dialog = jQuery('.ui-dialog.sync-pull-search');
console.log(dialog);
	this.$dialog = dialog;												// handlers setup, set $dialog property

	jQuery('#sync-pull-cancel', dialog).on('click', function() {
wpsitesynccontent.pull.log('#sync-pull-cancel button clicked');
console.log(dialog);
		jQuery(dialog).dialog('close');
//		jQuery($dialog/*'#sync-pull-dialog'*/).dialog('close');
	});

wpsitesynccontent.pull.log('hooking #sync-pull-search keyup events');
console.log(jQuery('#sync-pull-search'));
	jQuery('#sync-pull-search', dialog).keyup(_.debounce(wpsitesynccontent.pull.search, 2000));

	jQuery('#sync-pull-search-results', dialog).on('click', '.sync-pull-row', function() {
		jQuery('#sync-pull-search-results .sync-pull-row', dialog).removeClass('selected');
		jQuery(this).addClass('selected');
		wpsitesynccontent.pull.target_post_id = jQuery(this).attr('id').substr(13);
		jQuery('#sync-pull-selected', dialog).prop('disabled', false);
	});

this.log('.setup_handlers() - complete');
};

/*
jQuery(document).ready(function() {
	jQuery(document).on('sync_push', function(e, push_xhr) {
console.log('sync-pull: checking content');
		push_xhr.beforeSend = function(xhr, opts) { wpsitesynccontent.pull.check_modified_timestamp(xhr, opts); };
	});
});
*/

wpsitesynccontent.pull = new WPSiteSyncContent_Pull();

jQuery(document).ready(function() {
	wpsitesynccontent.pull.init();
//	wpsitesynccontent.pull.setup_handlers();

	// TODO: create an .init() method and move this into the method
	// TODO: perform initialization on trigger response
	jQuery(document).on('sync_api_call', function(e, push_xhr)
	{
wpsitesynccontent.pull.log('!sync_api_call');
//		wpsitesynccontent.push_xhr.beforeSend = function (xhr, opts)
//		{
//			wpsitesynccontent.pull.check_modified_timestamp(xhr, opts);
//		};

		wpsitesynccontent.push_xhr.success = function(response)
		{
			if (response.success) {
				wpsitesynccontent.pull.set_message(jQuery('#sync-msg-pull-complete').text());
				window.location.assign(response.data.edit_url);
			} else if (0 !== response.error_code) {
				wpsitesynccontent.pull.set_message(response.error_message, false, true);
			} else {
				// TODO: display a dialog with an error message to alert the user
wpsitesynccontent.pull.log('Failed to execute API.');
			}
		};
	});

	// inject buttons on posts (list view) page
	if (0 !== jQuery('#sync-pull-search-ui').length) {
		if (0 === jQuery('#post-query-submit').length) {
			// WP 4.8+ if input field doesn't exist.... #23
			jQuery('#posts-filter .tablenav').html(jQuery('#sync-pull-search-ui').html());
		} else {
			jQuery('#post-query-submit').after(jQuery('#sync-pull-search-ui').html());
		}
	} else console.log('no ui- no permissions');

	// TODO: rework to remove need for on() calls on buttons

/*
	jQuery('#sync-pull-cancel').on('click', function() {
wpsitesynccontent.pull.log('#sync-pull-cancel button clicked');
		jQuery('#sync-pull-dialog').dialog('close');
	});


wpsitesynccontent.pull.log('hooking #sync-pull-search keyup events');
console.log(jQuery('#sync-pull-search'));
	jQuery('#sync-pull-search').keyup(_.debounce(wpsitesynccontent.pull.search, 2000));

	jQuery('#sync-pull-search-results').on('click', '.sync-pull-row', function() {
		jQuery('#sync-pull-search-results .sync-pull-row').removeClass('selected');
		jQuery(this).addClass('selected');
		wpsitesynccontent.pull.target_post_id = jQuery(this).attr('id').substr(13);
		jQuery('#sync-pull-selected').prop('disabled', false);
	});
*/
});

// EOF
