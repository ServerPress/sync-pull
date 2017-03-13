<?php

/*
 * Allows management of users between the Source and Target sites and allow posting of content on behalf of users on the Target site
 * @package Sync
 * @author Dave Jesch
 */
class SyncPullAdmin
{
	private static $_instance = NULL;

	private function __construct()
	{
		add_filter('spectrom_sync_ajax_operation', array($this, 'check_ajax_query'), 10, 3);

		add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
		add_action('spectrom_sync_metabox_after_button', array($this, 'add_pull_to_metabox'), 10, 1);
		add_action('spectrom_sync_ui_messages', array($this, 'add_pull_ui_messages'));
		add_action('admin_footer', array($this, 'add_dialog_modal'));
	}

	/*
	 * retrieve singleton class instance
	 * @return instance reference to plugin
	 */
	public static function get_instance()
	{
		if (NULL === self::$_instance)
			self::$_instance = new self();
		return self::$_instance;
	}

	/**
	 * Registers js and css to be used.
	 */
	public function admin_enqueue_scripts($hook_suffix)
	{
		wp_register_script('sync-pull', WPSiteSync_Pull::get_asset('js/sync-pull.js'), array('sync', 'jquery', 'jquery-ui-dialog'), WPSiteSync_Pull::PLUGIN_VERSION, TRUE);
		wp_register_style('sync-pull', WPSiteSync_Pull::get_asset('css/sync-pull.css'), array('wp-jquery-ui-dialog'), WPSiteSync_Pull::PLUGIN_VERSION);

		if ('post.php' === $hook_suffix) {
			wp_enqueue_script('sync-pull');
			wp_enqueue_style('sync-pull');
		}
	}

	/**
	 * Checks if the current ajax operation is for this plugin
	 * @param  boolean          $found     Return TRUE or FALSE if the operation is found
	 * @param  string           $operation The type of operation requested
	 * @param  SyncApiResponse $response  The response to be sent
	 * @return boolean Return TRUE if the current ajax operation is for this plugin, otherwise return $found
	 */
	// TODO: move the real work of this method to SyncPullAjaxRequest::pull_content()
	// TODO: ensure only called once Sync is initialized
	public function check_ajax_query($found, $operation, SyncApiResponse $resp)
	{
SyncDebug::log(__METHOD__.'() operation="' . $operation . '"');
		$lic = WPSiteSyncContent::get_instance()->get_license(); // new SyncLicensing();
		if (!$lic->check_license('sync_pull', WPSiteSync_Pull::PLUGIN_KEY, WPSiteSync_Pull::PLUGIN_NAME))
			return $found;

		if ('pull' === $operation) {
SyncDebug::log(' - post=' . var_export($_POST, TRUE));
			// TODO: this method should be:
			//		$pullapi = new SyncPullApiRequest();
			//		$pullapi->pull_content($post_id);
			//		...check for errors / handle UI feedback
			$found = TRUE;

			$input = new SyncInput();
			$model = new SyncModel();

			$source_post_id = $input->post_int('post_id', 0);
			// check for 0 == post_id and exit here
			if (0 === $source_post_id) {
				// no post id provided. Return error message
				WPSiteSync_Pull::get_instance()->load_class('pullapirequest');
				$resp->error_code(SyncPullApiRequest::ERROR_POST_NOT_FOUND);
				$resp->success(FALSE);
				return TRUE;		// return, signaling that we've handled the request
			}

			$sync_data = $model->get_sync_target_post($source_post_id, SyncOptions::get('target_site_key'));
			if (NULL === $sync_data) {
				// could not find Target post ID. Return error message
				WPSiteSync_Pull::get_instance()->load_class('pullapirequest');
				$resp->error_code(SyncPullApiRequest::ERROR_TARGET_POST_NOT_FOUND);
				return TRUE;		// return, signaling that we've handled the request
			}

			// perform the Sync Pull operation
SyncDebug::log(__METHOD__.'() sync_data=' . var_export($sync_data, TRUE));
			$source_post_id = abs($sync_data->source_content_id);
			$target_post_id = abs($sync_data->target_content_id);
SyncDebug::log(' - source=' . $sync_data->source_content_id . ' target=' . $sync_data->target_content_id);
SyncDebug::log(' - args source=' . $source_post_id . ' target=' . $target_post_id);
			$args = array('post_id' => $source_post_id, 'target_id' => $target_post_id);
			$api = new SyncApiRequest();
			$api_response = $api->api('pullcontent', $args);
			// copy contents of SyncApiResponse object from API call into the Response object for AJAX call
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - returned from api() call; copying response');
			$resp->copy($api_response);
			if (0 === $api_response->get_error_code()) {
SyncDebug::log(' - no error, setting success');
				$resp->success(TRUE);
			}
else SyncDebug::log(' - error code: ' . $api_response->get_error_code());
			return TRUE;			// return, signlaing that we've handled the request
		} else if ('check_modified_timestamp' === $operation) {
			$found = TRUE;

			$input = new SyncInput();
			$api = new SyncApiRequest();
			$model = new SyncModel();

			$post_id = $input->post_int('post_id', 0);
			// TODO: use a better variable name than $sync
			$sync = $model->get_sync_data($post_id);
			$source_post_id = $sync->target_content_id;

			$request = $api->api('pullcontent', array('post_id' => $source_post_id));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - api() response for "pull": ' . var_export($request, TRUE));

			$source_post = $request->data->content->post_data;

			if (strtotime($sync->last_update) < strtotime($source_post->post_modified)) {
				$resp->success(FALSE);
				$resp->notice_code(SyncPullApiRequest::NOTICE_CONTENT_MODIFIED);
				$resp->notice(__('Content has been modified on the Target site since the last Push. Continue?', 'wpsitesync-pull'));
			} else
				$resp->success(TRUE);
		}

		return $found;
	}

	/**
	 * Adds the Pull UI elements to the Sync metabox
	 * @param boolean $error TRUE or FALSE depending on whether the connection settings are valid
	 */
	public function add_pull_to_metabox($error)
	{
SyncDebug::log(__METHOD__.'(error=' . var_export($error, TRUE) . ')');
		$lic = WPSiteSyncContent::get_instance()->get_license(); // new SyncLicensing();
		if ($error || !$lic->check_license('sync_pull', WPSiteSync_Pull::PLUGIN_KEY, WPSiteSync_Pull::PLUGIN_NAME))
			return;
		// check configuration to see if we can talk to the target
		$auth = SyncOptions::is_auth(); // abs(SyncOptions::get('auth', '0'));
		$target = SyncOptions::get('host', '');
		if (!$auth || empty($target))
			return;

		global $post;

/*
SyncDebug::log(__METHOD__.'() post id=' . $post->ID);
		$sync_model = new SyncModel();
		$target_post_id = 0;
		// Displayed only if the content of the current post has been previously Pushed to the Target site
//		if (NULL === ($sync_data = $sync_model->get_sync_target_data($post->ID, SyncOptions::get('target_site_key')))) {
		if (NULL === ($sync_data = $sync_model->get_sync_target_post($post->ID, SyncOptions::get('target_site_key')))) {
SyncDebug::log(__METHOD__.'() data has not been previously syncd');
			return;
		} else {
			$target_post_id = $sync_data->target_content_id;
		}

		// TODO: move the part that does the actual work into a SyncPullApiRequest class - make it easier for unit testing
		$api = new SyncApiRequest();
		$options = SyncOptions::get_all();

		// get the post id on the Target for Source post_id
SyncDebug::log(__METHOD__.'() sync data: ' . var_export($sync_data, TRUE));


		// ask the Target for the post's content
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - retrieving Target post ID ' . $target_post_id);
		$response = $request = $api->api('pullinfo', array('post_id' => $target_post_id, 'post_name' => $post->post_name));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - returned object: ' . var_export($response, TRUE));

		// examine API response to see if Pull is running on Target
		$pull_active = TRUE;
		if (isset($response->result['body'])) {
			$response_body = json_decode($response->result['body']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - result data: ' . var_export($response_body, TRUE));
			if (NULL === $response_body) {
				$pull_active = FALSE;
			} else if (SyncApiRequest::ERROR_UNRECOGNIZED_REQUEST === $response_body->error_code) {
				$pull_active = FALSE;
			} else if (0 !== $response_body->error_code) {
				$msg = $api->error_code_to_string($response_body->error_code);
				echo '<p>', sprintf(__('Error #%1$d: %2$s', 'wpsitesync-pull'), $response_body->error_code, $msg), '</p>';
				$pull_active = FALSE;
			}
		}

		if ($pull_active) {
			// Pull is active on Target, add UI elements to meta box
			$target_post = (isset($response_body->data)) ? $response_body->data->post_data : NULL;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - target post: ' . var_export($target_post, TRUE));
*/

			// display the button that goes in the Metabox
			echo '<button id="sync-pull-content" type="button" class="button button-primary sync-button btn-sync" onclick="wpsitesynccontent.pull.show_dialog(', $post->ID, ')" ';
			if ($error)
				echo ' disabled';
			echo ' title="', __('Pull this Content from the Target site', 'wpsitesync-pull'), '" ';
			echo '>';
			echo '<span><span class="sync-button-icon sync-button-icon-rotate dashicons dashicons-migrate"></span>', __('Pull from Target', 'wpsitesync-pull'), '</span>';
			echo '</button>';

			wp_enqueue_script('sync-pull');

/*			echo '&nbsp;<button id="sync-pull" type="button" class="button button-primary btn-sync" onclick="wpsitesynccontent.pull.show(); return false" ';
			if (is_wp_error($response))
				echo ' title="', esc_attr($response->get_error_message()), '" disabled>';
			else
				echo ' title="', esc_attr(__('Pull Content from Target', 'wpsitesync-pull')), '">';
			echo '<span>', __('Pull', 'wpsitesync-pull'), '</span></button>';
*/

/*
			if (empty($target_post)) {
				return;
			}

			// TODO: use jQuery dialog instead of thickbox
			add_thickbox();


			$modified = date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($target_post->post_modified_gmt));
			$excerpt = substr(strip_tags($target_post->post_content), 0, 200);

			$data = array(
				'target' => $options['host'],
				'source_post_id' => $post->ID,
				'target_post_id' => $target_post_id,
				'post_title' => $target_post->post_title,
				'post_author' => $response_body->data->username,
				'modified' => $modified,
				'content' => $excerpt,
			);

			WPSiteSync_Pull::get_instance()->load_class('pullview');
			SyncPullView::load_view('pullui', $data);
		} else {
			// Pull not active on Target
			echo '<div style="margin-top: .5rem">', __('WPSiteSync Pull is not Active on Target', 'wpsitesync-pull'), '</div>';
		}
 */
	}
	public function add_pull_ui_messages()
	{
		echo '<span id="sync-msg-pull-working">', __('Pulling Content from Target...', 'wpsitesync-pull'), '</span>';
		echo '<span id="sync-msg-pull-complete">', __('Pull Complete. Reloading Page...', 'wpsitesync-pull'), '</span>';
	}

	/**
	 * Add the HTML for a jQuery Dialog modal
	 *
	 * @since 1.0.0
	 */
	public function add_dialog_modal()
	{
		$screen = get_current_screen();

		// if on the post.php screen
		if (is_object($screen) && 'post' === $screen->base) {
			global $post;

			// @todo move into view?
			$post_type = get_post_type_object(get_post_type());
			$title = sprintf(__('Search for %1$s Content on Target: %2$s', 'wpsitesync-pull'), $post_type->labels->singular_name, SyncOptions::get('host'));
			$sync_model = new SyncModel();
			$target_post_id = 0;

			// display dialog HTML
			echo '<div id="sync-pull-dialog" style="display:none" title="', esc_html($title), '">';
			echo '<p>', esc_html__('Search for', 'wpsitesync-pull');
			echo ' <input type="search" id="sync-pull-search" value=""></p>';

			if (NULL !== ($sync_data = $sync_model->get_sync_target_post($post->ID, SyncOptions::get('target_site_key')))) {
				// display associated content if it exists
				$target_post_id = $sync_data->target_content_id;
				$content_details = SyncAdmin::get_instance()->_get_content_details();
				echo '<div id="sync-details">';
				echo $content_details;
				echo '</div>';    // contains content detail information
			}


//				$post_data = get_post($source_post_id, OBJECT);
				echo '<div id="sync-pull-search-results" style="display:none"><p>Found x posts matching search:</p>';
				echo '<div id="sync-pull-results-header" class="sync-pull-row">
						<div class="sync-pull-column-id">', __('ID', 'wpsitesync-pull'), '</div>
						<div class="sync-pull-column-title">', __('Title', 'wpsitesync-pull'), '</div>
						<div class="sync-pull-column-content">', __('Content', 'wpsitesync-pull'), '</div>
						<div class="sync-pull-column-modified">', __('Modified', 'wpsitesync-pull'), '</div>
						<div class="sync-pull-column-author">', __('Author', 'wpsitesync-pull'), '</div>
					</div>';
//				echo '<div id="sync-pull-id-#" class="sync-pull-row">
//						<div class="sync-pull-column-id">', esc_html($post_data->ID), '</div>
//						<div class="sync-pull-column-title">', esc_html($post_data->post_title), '</div>
//						<div class="sync-pull-column-content">', esc_html($post_data->post_excerpt), '</div>
//						<div class="sync-pull-column-modified">', esc_html($post_data->post_modified), '</div>
//						<div class="sync-pull-column-author">', esc_html($post_data->post_author), '</div>
//					</div></div>';

echo '<div id="sync-pull-id-#" class="sync-pull-row">
   <div class="sync-pull-column-id">123</div>
   <div class="sync-pull-column-title">Some title</div>
   <div class="sync-pull-column-content">this is the content of the post on the target</div>
   <div class="sync-pull-column-modified">Mar 1 2015</div>
   <div class="sync-pull-column-author">George</div>
</div>
</div>';
			//}

			echo '<div id="sync-pull-messages"></div>';

			echo '<p><button id="sync-pull-cancel" type="button" class="button button-secondary" title="', __('Cancel', 'wpsitesync-pull'), '">', __('Cancel', 'wpsitesync-pull'), '</button>';
			echo ' &nbsp; <input type="radio" id="sync-pull-current" checked="checked">';
			echo __('Pull Content into current Post', 'wpsitesync-pull');
			echo ' &nbsp; <input type="radio" id="sync-pull-new">';
			echo __('Pull into new Post', 'wpsitesync-pull');
			echo ' &nbsp; <button id="sync-pull-selected" type="button" onclick="wpsitesynccontent.pull.pull(';
			if (0 !== $target_post_id) {
				echo esc_attr($target_post_id);
			}
			echo '); return false;"class="button button-primary" title="', __('Pull Selected Content', 'wpsitesync-pull'), '">', __('Pull Selected Content', 'wpsitesync-pull'), '</button>';

			echo '</p></div>'; // close dialog HTML
		}
	}
}

// EOF
