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
		add_filter('spectrom_sync_ajax_operation', array(&$this, 'check_ajax_query'), 10, 3);

		add_action('admin_enqueue_scripts', array(&$this, 'admin_enqueue_scripts'));
		add_action('spectrom_sync_metabox_after_button', array(&$this, 'add_pull_to_metabox'), 10, 1);
		add_action('spectrom_sync_ui_messages', array(&$this, 'add_pull_ui_messages'));
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
		wp_register_script('sync-pull', WPSiteSync_Pull::get_asset('js/sync-pull.js'), array('sync'), WPSiteSync_Pull::PLUGIN_VERSION, TRUE);
		if ('post.php' === $hook_suffix)
			wp_enqueue_script('sync-pull');
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
		$lic = new SyncLicensing();
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

########
			// TODO: use a better variable name than $sync
			$sync = $model->get_sync_data($post_id);
			$source_post_id = $sync->target_content_id;
SyncDebug::log(__METHOD__.'():' . __LINE__.' source post=' . $source_post_id);

			// TODO: remove $request
			$response = $request = $api->api('pullcontent', array('post_id' => $source_post_id));

SyncDebug::log(__METHOD__.'():' . __LINE__.' request=' . var_export($response, TRUE));
			$resp->success(FALSE);

			// TODO: move all the work into a SyncPullApiModel class that leverages the SyncApiController / SyncApiModel classes

			if (isset($response->result['body'])) {
				$response_body = json_decode($response->result['data']);
				if (0 !== $response_body->error_code) {
					// return API error code to AJAX caller
					$resp->error_code($response_body->error_code);
					$resp->send();
				}
			}

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' response body=' . var_export($response_body, TRUE));

			if (0 === $post_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__.' no post id provided');
				$resp->error(__('This post has not been Sync\'d yet.', 'wpsitesync-pull'));
			} else if (is_wp_error($request)) {
SyncDebug::log(__METHOD__.'():' . __LINE__.' API Error: ' . $request->get_error_message());
				$resp->error($request->get_error_message());
			} else {
//				$pull_content = $request->data->content;
//				$source_post = $pull_content->post_data;
				$source_post = $response_body->post_data;

SyncDebug::log(__METHOD__.'():' . __LINE__.' source post=' . var_export($source_post, TRUE));

				// TODO: look for request->error_code
				$resp->success(TRUE);
				// TODO: need to update post content
//				$source_post->post_content = str_replace($this->post('origin'), $url['host'], $source_post->post_content);
//				// TODO: check if we need to update anything else like `guid`, `post_excerpt`, `post_content_filtered`
//				// JOSE - `guid`, `post_excerpt`, `post_content_filtered` are in $source_post (line 70)

				$source_post->ID = $post_id;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source post id=' . $post_id);
				$ret = wp_update_post($source_post, TRUE);
				if (is_wp_error($ret)) {
SyncDebug::log(__METHOD__.'():' . __LINE__.' WP_Error: ' . $ret->get_error_message());
					$resp->notice_code(SyncApiRequest::NOTICE_INTERNAL_ERROR, $ret->get_error_message());
					$resp->notice(__('WP Error: ', 'wpsitesync-pull') . $ret->get_error_message());
				} else {
					// update post meta
					$post_meta = $request->data->content->post_meta;

					// update postmeta records from Target
					// TODO: need to handle deletes of postmeta - postmeta records that no longer exist on Target but do on Source
					// TODO: probably better to remove all post meta, then add_post_meta() for each record from Target
					// TODO: also check that this is the process on Target when doing push operations
					foreach ($post_meta as $meta_key => $meta)
						foreach ($meta as $meta_value)
							update_post_meta($post_id, $meta_key, $meta_value);

SyncDebug::log(__METHOD__.'() checking for image / attachments');
					// handle pulling image data / attachments from Target
					// TODO: this should be moved into it's own method so it easier to look at
					require_once(ABSPATH . 'wp-admin/includes/image.php');
					if (!function_exists('wp_handle_upload'))
					    require_once(ABSPATH . 'wp-admin/includes/file.php');

					if (isset($pull_content->post_media) && !empty($pull_content->post_media)) {
						$media = $pull_content->post_media;
						foreach ($media as $attach_id => $attachment) {
SyncDebug::log(__METHOD__.'() id=' . var_export($attach_id, TRUE) . ' attach=' . var_export($attachment, TRUE));
							$rec = $model->get_sync_target_data($attach_id);
SyncDebug::log(__METHOD__.'() rec=' . var_export($rec, TRUE));
							if (NULL === $rec) {
								// does not already exist - create a new attachment
SyncDebug::log(__METHOD__.'() adding media information:');
								// determine where to put the downloaded file
								$upload_dir = wp_upload_dir();
								$path = $upload_dir['basedir'] . $upload_dir['subdir'] . DIRECTORY_SEPARATOR;
								$filename = $path . basename(parse_url($attachment->guid, PHP_URL_PATH));
								$filename = str_replace('\\', '/', $filename);

								// download the file from Target using the guid as the path to the file
SyncDebug::log(__METHOD__.'() downloading ' . $attachment->guid);
								$res = wp_remote_get($attachment->guid);
								$body = wp_remote_retrieve_body($res);
SyncDebug::log(__METHOD__.'() res=' . var_export($res, TRUE) . ' bodylen=' . strlen($body));
								$ret = file_put_contents($filename, $body);
SyncDebug::log(__METHOD__.'() line ' . __LINE__ . ' ret' . var_export($ret, TRUE));
SyncDebug::log(__METHOD__.'() created temp file ' . $filename);

								// build an array that represents the new attachment
								$new_attach = json_decode(json_encode($attachment),TRUE);
SyncDebug::log(' - upload basedir=' . $upload_dir['basedir']);
SyncDebug::log(' - upload baseurl=' . $upload_dir['baseurl']);
								$new_attach['guid'] = str_replace('\\', '/', str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $filename));
								$new_attach['post_parent'] = $post_id;
								unset($new_attach['ID']);
//								$new_id = wp_insert_post($new_attach);
								$new_id = wp_insert_attachment($new_attach, $filename, $post_id);
								if (!is_wp_error($new_id))
									media_handle_upload($new_id, wp_generate_attachment_metadata($new_id, $filename));
SyncDebug::log(__METHOD__.'() line ' . __LINE__ . ' new id ' . $new_id);

								// TODO: create the different image sizes

								// TODO: add to wp_spectrom_sync table
							} else {
								// TODO: handle updates
							}
						}
					}
					// this hook allows for updating of any additional data associated with content, such as comments and app specific data
					do_action('spectrom_sync_update_source_content', $post_id, $request->data->content);

SyncDebug::log(__METHOD__.'():' . __LINE__.' success!');
					$resp->notice_code(SyncApiRequest::NOTICE_CONTENT_SYNCD);
//					$resp->notice(__('Content synchronized.', 'wpsitesync-pull'));
				}
			}
			// TODO: rename AJAX call to 'pull_check_modified_timestamp'
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
		$lic = new SyncLicensing();
		if ($error || !$lic->check_license('sync_pull', WPSiteSync_Pull::PLUGIN_KEY, WPSiteSync_Pull::PLUGIN_NAME))
			return;
		// check configuration to see if we can talk to the target
		$auth = abs(SyncOptions::get('auth', '0'));
		$target = SyncOptions::get('host', '');
		if (1 !== $auth || empty($target))
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
		$options = SyncOptions::get_all(); // get_option(SyncOptions::OPTION_NAME);

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
			echo '<button id="sync-pull-content" type="button" class="button button-primary sync-button btn-sync" onclick="wpsitesynccontent.pull.action(', $post->ID, ')" ';
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
}

// EOF
