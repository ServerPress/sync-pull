<div id="sync-pull-container" style="display:none;">
	<p><?php printf(__('WPSiteSync Pull will move the Content from the Target system: %s', 'wpsitesync-pull'), $data['target']); ?></p>
	<ul style="border:1px solid gray; padding:.2rem; margin: -4px">
		<li><?php printf(__('Target Content Id: %d', 'wpsitesync-pull'), $data['target_post_id']); ?></li>
		<li><?php printf(__('Content Title: %s', 'wpsitesync-pull'), $data['post_title']); ?></li>
		<li><?php printf(__('Content Author: %s', 'wpsitesync-pull'), $data['post_author']); ?></li>
		<li><?php printf(__('Last Modified: %s', 'wpsitesync-pull'), $data['modified']); ?></li>
		<li><?php printf(__('Content: %s', 'wpsitesync-pull'), $data['content']); ?></li>
	</ul>
	<p><?php _e('Moving this Content from the Target will overwrite the current contents on your local site. Are you sure you wish to continue with this operation?', 'wpsitesync-pull'); ?></p>
	<div class="pull-actions" style="padding: 10px; clear: both; border-top: 1px solid #ddd;">
		<button type="button" onclick="wpsitesynccontent.pull.cancel(); return false;" class="button" style="float:left;"><?php _e('Cancel', 'wpsitesync-pull'); ?></button>
		<button id="btn-sync" onclick="wpsitesynccontent.pull.action(<?php echo $data['source_post_id']; ?>, true); return false;" type="button" class="button button-primary" style="float:right;"><?php _e('Pull Content', 'wpsitesync-pull'); ?></button>
		<div class="clear"></div>
	</div>
	<div class="pull-loading-indicator" style="display: none;">
		<?php printf(__('Synchronizing Content from %s...', 'wpsitesync-pull'), $data['target']); ?>
	</div>
	<div id="pull-failure-msg"><?php _e('Failed to Pull Content from Target.', 'wpsitesync-pull'); ?><span id="pull-fail-detail"></span></div>
	<div id="pull-success-msg"></div>
</div>
