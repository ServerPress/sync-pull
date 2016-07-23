/*
 * @copyright Copyright (C) 2015 SpectrOMtech.com. - All Rights Reserved.
 * @license GNU General Public License, version 2 (http://www.gnu.org/licenses/gpl-2.0.html)
 * @author SpectrOMtech.com <hello@SpectrOMtech.com>
 * @url https://www.SpectrOMtech.com/products/
 * The PHP code portions are distributed under the GPL license. If not otherwise stated, all images, manuals, cascading style sheets, and included JavaScript *are NOT GPL, and are released under the SpectrOMtech Proprietary Use License v1.0
 * More info at https://SpectrOMtech.com/products/
 */

function WPSiteSyncContent_Pull()
{
}


/**
 * Shows the Pull UI area
 */
WPSiteSyncContent_Pull.prototype.show = function()
{
console.log('.pull.show()');
	this.hide_msgs();
	jQuery('#sync-pull-container').show();
	jQuery('#sync-pull').hide();
};

/**
 * Pull content from a target site
 * @param  {int} post_id The post ID from the current site
 * @param  {int} confirmation Whether the request is confirmed or not
 */
WPSiteSyncContent_Pull.prototype.action = function(post_id, confirmation)
{
console.log('WPSiteSyncContent.pull.action(' + post_id + ')');

	var data = { action: 'spectrom_sync', operation: 'pull', post_id: post_id };
console.log(data);

	jQuery('.pull-actions').hide();
	jQuery('.pull-loading-indicator').show();
	wpsitesynccontent.set_message(jQuery('#sync-msg-pull-working').text(), true);

	jQuery.ajax({
		type: 'post',
		async: true, // false,
		data: data,
		url: ajaxurl,
		success: function(response) {
console.log('in ajax success callback');
//			jQuery('.pull-actions').show();
//			jQuery('.pull-loading-indicator').hide();

console.log(' - response:');
console.log(response);
			if (response.success) {
//				jQuery('.tb-close-icon').trigger('click');
//				alert(response.notices[0]);
				// reload page to show new content
				wpsitesynccontent.set_message(jQuery('#sync-msg-pull-complete').text());
				window.location.reload();
			} else if (0 !== response.error_code) {
				// TODO: write to error message part of DOM
				wpsitesynccontent.set_message(response.error_message, false, true);
			} else {
				// TODO: use a dialog box not an alert
console.log('Failed to execute API.');
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
console.log('.pull.cancel()');
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
console.log('.pull.check_modified_timestamp()');
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
console.log('.pull.hide_msgs()');
	jQuery('#pull-loading-indicator').hide();
	jQuery('#pull-failure-msg').hide();
	jQuery('#pull-success-msg').hide();
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
