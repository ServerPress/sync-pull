<?php

/*
 * Allows user to move content from Target site to Source site
 * @package WPSiteSync
 * @author Dave Jesch
 */
class SyncPullAdmin extends SyncInput
{
	private static $_instance = NULL;

	private function __construct()
	{
		// check the current post type against allowed / known post types
		$post_type = $this->get('post_type', 'post');
		$allowed_post_types = apply_filters('spectrom_sync_allowed_post_types', array('page', 'post'));
		if (!in_array($post_type, $allowed_post_types))
			return;

		if (SyncOptions::has_cap()) {
			add_filter('spectrom_sync_ajax_operation', array($this, 'check_ajax_query'), 10, 3);

			add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
			add_action('spectrom_sync_metabox_after_button', array($this, 'add_pull_to_metabox'), 10, 1);
			//add_action('spectrom_sync_ui_messages', array($this, 'add_pull_ui_messages'));
			add_action('admin_footer', array($this, 'add_dialog_modal'));
			add_action('admin_print_scripts-edit.php', array($this, 'print_hidden_div'));
		}
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
		wp_register_script('sync-pull', WPSiteSync_Pull::get_asset('js/sync-pull.js'), array('sync', 'jquery', 'underscore', 'jquery-ui-dialog'), WPSiteSync_Pull::PLUGIN_VERSION, TRUE);
		wp_register_style('sync-pull', WPSiteSync_Pull::get_asset('css/sync-pull.css'), array('wp-jquery-ui-dialog', 'sync-admin'), WPSiteSync_Pull::PLUGIN_VERSION);

		if (SyncOptions::has_cap() &&
			('post.php' === $hook_suffix || 'edit.php' === $hook_suffix) ||
			('post-new.php' === $hook_suffix && $this->is_gutenberg()) ) {
			wp_enqueue_script('sync-pull');
			wp_enqueue_style('sync-pull');

			// setup the syncpulldata object
			global $post;
			$post_id = 0;
			$post_type = '';
			if (isset($post)) {
				if (isset($post->ID))
					$post_id = $post->ID;
				if (isset($post->post_type))
					$post_type = $post->post_type;
			}
			$data = array(
				'post_id' => $post_id,
				'post_type' => $post_type,
			);
			wp_localize_script('sync', 'syncpulldata', $data);
		}
	}

	/**
	 * Checks to see if Gutenberg is present (using function and /or WP version) and the page
	 * is using Gutenberg
	 * @return boolean TRUE if Gutenberg is present and used on current page
	 */
	private function is_gutenberg()
	{
		if (function_exists('is_gutenberg_page') && is_gutenberg_page())
			return TRUE;
		if (version_compare($GLOBALS['wp_version'], '5.0', '>=') && $this->is_gutenberg_page())
			return TRUE;
		return FALSE;
	}

	/**
	 * Checks whether we're currently loading a Gutenberg page
	 * @return boolean Whether Gutenberg is being loaded.
	 */
	private function is_gutenberg_page()
	{
		// taken from Gutenberg Plugin v4.8
		if (!is_admin())
			return FALSE;

		/*
		 * There have been reports of specialized loading scenarios where `get_current_screen`
		 * does not exist. In these cases, it is safe to say we are not loading Gutenberg.
		 */
		if (!function_exists('get_current_screen'))
			return FALSE;

		if ('post' !== get_current_screen()->base)
			return FALSE;

		if (isset($_GET['classic-editor']))
			return FALSE;

		global $post;
		if (!$this->gutenberg_can_edit_post($post))
			return FALSE;

		return TRUE;
	}

	/**
	 * Return whether the post can be edited in Gutenberg and by the current user.
	 * @param int|WP_Post $post Post ID or WP_Post object.
	 * @return bool Whether the post can be edited with Gutenberg.
	 */
	private function gutenberg_can_edit_post($post)
	{
		// taken from Gutenberg Plugin v4.8
		$post = get_post($post);
		$can_edit = TRUE;

		if (!$post)
			$can_edit = FALSE;

		if ($can_edit && 'trash' === $post->post_status)
			$can_edit = FALSE;

		if ($can_edit && !$this->gutenberg_can_edit_post_type($post->post_type))
			$can_edit = FALSE;

		if ($can_edit && !current_user_can('edit_post', $post->ID))
			$can_edit = FALSE;

		// Disable the editor if on the blog page and there is no content.
		// TODO: this is probably not a necessary check for WPSS
		if ($can_edit && abs(get_option('page_for_posts')) === $post->ID && empty($post->post_content))
			$can_edit = FALSE;

		/**
		 * Filter to allow plugins to enable/disable Gutenberg for particular post.
		 *
		 * @param bool $can_edit Whether the post can be edited or not.
		 * @param WP_Post $post The post being checked.
		 */
		return apply_filters('gutenberg_can_edit_post', $can_edit, $post);
	}

	/**
	 * Return whether the post type can be edited in Gutenberg.
	 *
	 * Gutenberg depends on the REST API, and if the post type is not shown in the
	 * REST API, then the post cannot be edited in Gutenberg.
	 *
	 * @param string $post_type The post type.
	 * @return bool Whether the post type can be edited with Gutenberg.
	 */
	private function gutenberg_can_edit_post_type($post_type)
	{
		$can_edit = TRUE;
		if (!post_type_exists($post_type))
			$can_edit = FALSE;

		if (!post_type_supports($post_type, 'editor'))
			$can_edit = FALSE;

		$post_type_object = get_post_type_object($post_type);
		if ($post_type_object && !$post_type_object->show_in_rest)
			$can_edit = FALSE;

		/**
		 * Filter to allow plugins to enable/disable Gutenberg for particular post types.
		 *
		 * @param bool $can_edit Whether the post type can be edited or not.
		 * @param string $post_type The post type being checked.
		 */
		return apply_filters('gutenberg_can_edit_post_type', $can_edit, $post_type);
	}

	/**
	 * Checks if the current ajax operation is for this plugin
	 * @param boolean $found Return TRUE or FALSE if the operation is found
	 * @param string $operation The type of operation requested
	 * @param SyncApiResponse $response The response to be sent
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
			$target_post_id = $input->post_int('target_id', 0);
			$content = $input->post('content', 'current');

			// check for 0 == post_id and exit here
			if (0 === $source_post_id && 'current' === $content) {
				// no post id provided. Return error message
				WPSiteSync_Pull::get_instance()->load_class('pullapirequest');
				$resp->error_code(SyncPullApiRequest::ERROR_POST_NOT_FOUND);
				$resp->success(FALSE);
				return TRUE;		// return, signaling that we've handled the request
			}

			if (0 === $target_post_id) {
				WPSiteSync_Pull::get_instance()->load_class('pullapirequest');
				$resp->error_code(SyncPullApiRequest::ERROR_TARGET_POST_NOT_FOUND);
				return TRUE;		// return, signaling that we've handled the request
			}

//			$sync_data = $model->get_sync_target_post($source_post_id, SyncOptions::get('target_site_key'));
//
//			if (NULL === $sync_data) {
//				WPSiteSync_Pull::get_instance()->load_class('pullapirequest');
//				$resp->error_code(SyncPullApiRequest::ERROR_TARGET_POST_NOT_FOUND);
//				return TRUE;		// return, signaling that we've handled the request
//			}

			// perform the Sync Pull operation
//SyncDebug::log(__METHOD__.'() sync_data=' . var_export($sync_data, TRUE));
//			$source_post_id = abs($sync_data->source_content_id);
//			$target_post_id = abs($sync_data->target_content_id);
//SyncDebug::log(' - source=' . $sync_data->source_content_id . ' target=' . $sync_data->target_content_id);
//SyncDebug::log(' - args source=' . $source_post_id . ' target=' . $target_post_id);
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
		} else if ('pullsearch' === $operation) {
			$found = TRUE;

			$input = new SyncInput();

			$post_type = $input->post('posttype', NULL);
			// check for NULL == post_type and exit here
			if (NULL === $post_type) {
				// no post type provided. Return error message
				WPSiteSync_Pull::get_instance()->load_class('pullapirequest');
				$resp->error_code(SyncPullApiRequest::ERROR_POST_TYPE_NOT_FOUND);
				$resp->success(FALSE);
				return TRUE;		// return, signaling that we've handled the request
			}

			$search = $input->post('search', NULL);
			// check for NULL == search and exit here
			if (NULL === $search) {
				// no search text provided. Return error message
				WPSiteSync_Pull::get_instance()->load_class('pullapirequest');
				$resp->error_code(SyncPullApiRequest::ERROR_SEARCH_NOT_FOUND);
				$resp->success(FALSE);
				return TRUE;		// return, signaling that we've handled the request
			}

			$args = array('posttype' => $post_type, 'search' => $search);
			$api = new SyncApiRequest();
			$api_response = $api->api('pullsearch', $args);

			// copy contents of SyncApiResponse object from API call into the Response object for AJAX call
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - returned from api() call; copying response');
			$resp->copy($api_response);
			if (0 === $api_response->get_error_code()) {
SyncDebug::log(' - no error, setting success');
				$resp->success(TRUE);
			}
		}

		return $found;
	}

	/**
	 * Adds the Pull UI elements to the Sync metabox
	 * @param boolean $error TRUE or FALSE depending on whether the connection settings are valid
	 */
	public function add_pull_to_metabox($error)
	{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' (error=' . var_export($error, TRUE) . ')');
		$lic = WPSiteSyncContent::get_instance()->get_license(); // new SyncLicensing();
		if ($error || !$lic->check_license('sync_pull', WPSiteSync_Pull::PLUGIN_KEY, WPSiteSync_Pull::PLUGIN_NAME))
			return;
		// check configuration to see if we can talk to the target
		$auth = SyncOptions::is_auth(); // abs(SyncOptions::get('auth', '0'));
		$target = SyncOptions::get('host', '');
		if (!$auth || empty($target))
			return;

		// allow add-on to disable Pull as desired
		if (!apply_filters('spectrom_sync_show_pull', TRUE))
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
			echo ' title="', esc_attr__('Pull this Content from the Target site', 'wpsitesync-pull'), '" ';
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

	/**
	 * Adds messages used in Pull UI operations to the DOM
	 */
	public function add_pull_ui_messages()
	{
		echo '<div id="sync-message-container" style="display:none">
			<span id="sync-content-anim" style="display:none">
			<img src="', WPSiteSyncContent::get_asset('imgs/ajax-loader.gif'), '">
			</span><span id="sync-message"></span>';
		echo '<span id="sync-msg-pull-working" style="display:none">', __('Pulling Content from Target...', 'wpsitesync-pull'), '</span>';
		echo '<span id="sync-msg-pull-complete" style="display:none">', __('Pull Complete. Reloading Page...', 'wpsitesync-pull'), '</span>';
		echo '<span id="sync-msg-pull-searching" style="display:none">', __('Searching for Content...', 'wpsitesync-pull'), '</span>';
		echo '</div>';
	}

	/**
	 * Add the HTML for a jQuery Dialog modal
	 */
	public function add_dialog_modal()
	{
		if (!SyncOptions::has_cap())
			return;

		$screen = get_current_screen();

		// if on the post.php screen
		// TODO: check to see if post type is allowed
		if (is_object($screen) && ('post' === $screen->base || 'edit' === $screen->base)) {
			global $post;

			$post_type = get_post_type();
			if (FALSE === $post_type) {
				if (isset($_GET['post_type']))
					$post_type = $_GET['post_type'];
				else
					$post_type = 'post';
			}
			$this->output_dialog_modal($post->ID, $post_type, $screen->base);
		}
	}

	/**
	 * Outputs HTML content for the Search Modal
	 * @param int $post_id The post ID of the Content being edited
	 * @param string $post_type The post type of the Content to be Pulled
	 * @param string $screen_base The $screen->base value
	 */
	public function output_dialog_modal($post_id, $post_type, $screen_base = 'post')
	{
		if (!SyncOptions::has_cap())
			return;

		$post_type_info = get_post_type_object($post_type);

		$title = sprintf(__('WPSiteSync&#8482;: Search for %1$s Content on Target: %2$s', 'wpsitesync-pull'), $post_type_info->labels->singular_name, SyncOptions::get('host'));
		$sync_model = new SyncModel();
		$target_post_id = 0;

		// display dialog HTML
		echo '<div id="sync-pull-dialog" style="display:none" title="', esc_html($title), '"><div id="spectrom_sync">';
		echo '<p>', esc_html__('Search for', 'wpsitesync-pull');
		echo ' <input type="search" id="sync-pull-search" value=""></p>';

		if ('post' === $screen_base) {
			echo '<div id="sync-details">';
			if (NULL !== ($sync_data = $sync_model->get_sync_target_post($post_id, SyncOptions::get('target_site_key')))) {
				// display associated content if it exists
				$target_post_id = $sync_data->target_content_id;
				$content_details = SyncAdmin::get_instance()->get_content_details();
				echo $content_details;
			} else {
				echo '<p>',
					__('There is no post on the Target site that is currently associated with this post.', 'wpsitesync-pull'), '<br/>',
					__('Search for something by entering a search phrase above, then select Content from the search results.', 'wpsitesync-pull'), '<br/>',
					__('Once a post from the Target is selected, you can choose to Pull that into the current post, or create a new post with that Content.', 'wpsitesync-pull'),
					'</p>';
			}
			echo '</div>';		// contains content detail information
		}
		echo '<div id="sync-pull-search-results" style="display:none"></div>';

		echo $this->add_pull_ui_messages();

		echo '<p><button id="sync-pull-cancel" type="button" class="button button-secondary" onclick="wpsitesynccontent.pull.close();"
			title="', __('Cancel', 'wpsitesync-pull'), '">', __('Cancel', 'wpsitesync-pull'), '</button>';
//			if ('post' === $screen->base) {
			echo ' &nbsp; <input type="radio" id="sync-pull-current" name="sync-pull-where" value="current" checked="checked" />';
			echo __('Pull into current Content', 'wpsitesync-pull');
//			}
		echo ' &nbsp; <input type="radio" id="sync-pull-new" name="sync-pull-where" value="new"';
		if ('edit' === $screen_base) {
			echo ' checked="checked"';
		}
		echo ' />';
		echo __('Pull into new Content', 'wpsitesync-pull');
		echo ' &nbsp; <button id="sync-pull-selected" type="button" disabled="disabled" ',
			' onclick="wpsitesynccontent.pull.pull(', abs($target_post_id), '); return false;" class="button button-primary" ',
			' title="', __('Pull Selected Content', 'wpsitesync-pull'), '">';
		echo '<span class="sync-button-icon sync-button-icon-rotate dashicons dashicons-migrate"></span>',
			__('Pull Selected Content', 'wpsitesync-pull'),
			'</button>';

		echo '</p></div></div>'; // close dialog HTML
	}

	/**
	 * Prints hidden button ui div
	 */
	public function print_hidden_div()
	{
		if (SyncOptions::has_cap()) {
			// make sure user has capability to perform Sync options
?>
			<div id="sync-pull-search-ui" style="display:none">
<!-- cap=<?php if (SyncOptions::has_cap()) echo 'has cap'; else echo 'does not have cap'; ?> -->
				<button class="sync-pull button sync-button button-primary" onclick="wpsitesynccontent.pull.show_dialog()"
				 type="button" title="<?php esc_attr_e('Search to Pull Content from the Target site', 'wpsitesync-pull'); ?>">
				<span class="sync-button-icon dashicons dashicons-search"></span><?php esc_html_e('Search for Pull', 'wpsitesync-pull'); ?>
				</button>
			</div>
<?php
		}
	}
}

// EOF
