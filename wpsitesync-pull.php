<?php
/*
Plugin Name: WPSiteSync for Pull
Plugin URI: https://wpsitesync.com/downloads/wpsitesync-for-pull/
Description: Allow Content Creators to "Pull" Content from the Target site into the Source site.
Author: WPSiteSync
Author URI: https://wpsitesync.com
Version: 2.3
Text Domain: wpsitesync-pull

The PHP code portions are distributed under the GPL license. If not otherwise stated, all
images, manuals, cascading stylesheets and included JavaScript are NOT GPL.
*/

/**
 * Declares two new API names:
 *	'pullinfo' - to get basic post information to be displayed in the Sync metabox
 *	'pullcontent' - perform the Pull operation itself
 */

if (!class_exists('WPSiteSync_Pull')) {
	/*
	 * @package WPSiteSync_Pull
	 * @author Dave Jesch
	 */
	class WPSiteSync_Pull
	{
		private static $_instance = NULL;

		const PLUGIN_NAME = 'WPSiteSync for Pull';
		const PLUGIN_VERSION = '2.3';
		const PLUGIN_KEY = '4151f50e546c7b0a53994d4c27f4cf31';
		const REQUIRED_VERSION = '1.6';								// minimum version of WPSiteSync required for this add-on to initialize

		private $_license = NULL;
		private $_push_controller = NULL;

		private function __construct()
		{
//SyncDebug::log(__METHOD__.'()');
			add_action('spectrom_sync_init', array($this, 'init'));
			if (is_admin())
				add_action('wp_loaded', array($this, 'wp_loaded'));		// checks that WPSiteSync is running
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
		 * Callback for SpectrOM Sync initialization action
		 */
		public function init()
		{
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' adding active extension check');
			add_filter('spectrom_sync_active_extensions', array($this, 'filter_active_extensions'), 10, 2);

			$this->_license = WPSiteSyncContent::get_instance()->get_license(); // new SyncLicensing();
			if (!$this->_license->check_license('sync_pull', self::PLUGIN_KEY, self::PLUGIN_NAME))
				return;

			if (is_admin())
				add_action('wp_loaded', array($this, 'pull_init'));
			add_action('spectrom_sync_api_init', array($this, 'api_init'));

			add_filter('spectrom_sync_error_code_to_text', array($this, 'filter_error_codes'), 10, 2);
			add_filter('spectrom_sync_notice_code_to_text', array($this, 'filter_notice_codes'), 10, 2);
		}

		/**
		 * Initialize hooks and filters for API handling
		 */
		public function api_init()
		{
			add_filter('spectrom_sync_api_request_action', array($this, 'api_request'), 20, 4);		// called by SyncApiRequest
			add_filter('spectrom_sync_api', array($this, 'api_controller_request'), 10, 3);			// called by SyncApiController
			add_action('spectrom_sync_api_request_response', array($this, 'api_response'), 10, 3);		// called by SyncApiRequest->api()
		}

		/**
		 * Initialize the admin interface after all plugins have been loaded
		 */
		public function pull_init()
		{
			// this needs to be loaded after all plugins have initialized. This allows
			// plugins a chance to add filters for 'spectrom_sync_allowed_post_types'
			$this->load_class('pulladmin');
			SyncPullAdmin::get_instance();
		}

		/**
		 * Helper method to display notices
		 * @param string $msg Message to display within notice
		 * @param string $class The CSS class used on the <div> wrapping the notice
		 * @param boolean $dismissable TRUE if message is to be dismissable; otherwise FALSE.
		 */
		private function _show_notice($msg, $class = 'notice-success', $dismissable = FALSE)
		{
			echo '<div class="notice ', $class, ' ', ($dismissable ? 'is-dismissible' : ''), '">';
			echo '<p>', $msg, '</p>';
			echo '</div>';
		}

		/**
		 * Disables the plugin if WPSiteSync not installed or ACF is too old
		 */
		public function disable_plugin()
		{
			deactivate_plugins(plugin_basename(__FILE__));
		}

		/**
		 * Callback for the 'wp_loaded' action. Used to display admin notice if WPSiteSync for Content is not activated
		 */
		public function wp_loaded()
		{
			// make sure WPSiteSync is running
			if (!class_exists('WPSiteSyncContent', FALSE) && current_user_can('activate_plugins')) {
				if (is_admin())
					add_action('admin_notices', array($this, 'notice_requires_wpss'));
				add_action('admin_init', array($this, 'disable_plugin'));
			} else {
				// check for minimum WPSiteSync version
				if (is_admin() && version_compare(WPSiteSyncContent::PLUGIN_VERSION, self::REQUIRED_VERSION) < 0 && current_user_can('activate_plugins')) {
					add_action('admin_notices', array($this, 'notice_minimum_version'));
					add_action('admin_init', array($this, 'disable_plugin'));
				}
			}
		}

		/**
		 * Display admin notice to install/activate WPSiteSync for Content
		 */
		public function notice_requires_wpss()
		{
			$this->_show_notice(sprintf(__('<em>WPSiteSync for Pull</em> requires the main <em>WPSiteSync for Content</em> plugin to be installed and activated. Please <a href="%1$s">click here</a> to install or <a href="%2$s">click here</a> to activate.', 'wpsitesync-pull'),
				admin_url('plugin-install.php?tab=search&s=wpsitesync'),
				admin_url('plugins.php')), 'notice-warning');
		}

		/**
		 * Display admin notice to upgrade WPSiteSync for Content plugin
		 */
		public function notice_minimum_version()
		{
			$this->_show_notice(
				sprintf(__('WPSiteSync for Pull requires version %1$s or greater of <em>WPSiteSync for Content</em> to be installed. Please <a href="2%s">click here</a> to update.', 'wpsitesync-pull'),
					self::REQUIRED_VERSION, admin_url('plugins.php')),
				'notice-warning');
		}

		/*
		 * return reference to asset, relative to the base plugin's /assets/ directory
		 * @param string $ref asset name to reference
		 * @return string href to fully qualified location of referenced asset
		 */
		public static function get_asset($ref)
		{
			$ret = plugin_dir_url(__FILE__) . 'assets/' . $ref;
			return $ret;
		}

		/**
		 * Checks the API request if the action is to Pull the content
		 * @param array $args The arguments array sent to SyncApiRequest::api()
		 * @param string $action The API requested
		 * @param array $remote_args Array of arguments sent to SyncRequestApi::api()
		 * @return array The modified $args array, with any additional information added to it
		 */
		// TODO: logic needs to be moved to a SyncPullApiRequest class
		// TODO: ensure only called once Sync is initialized
		// TODO: move to SyncPullSourceApi class
		public function api_request($args, $action, $remote_args, $api_request = NULL)
		{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' action=' . $action);
			if (!$this->_license->check_license('sync_pull', self::PLUGIN_KEY, self::PLUGIN_NAME))
				return $args;

			$input = new SyncInput();
			$model = new SyncModel();

			// TODO: nonce verification to be done in SyncApiController::__construct() so we don't have to do it here
//			if (!wp_verify_nonce($input->get('_spectrom_sync_nonce'), $input->get('site_key'))) {
//				$response->error_code(SyncApiRequest::ERROR_SESSION_EXPIRED);
////				$response->success(FALSE);
////				$response->set('errorcode', SyncApiRequest::ERR_INVALID_NONCE);
//				return;
//			}

			if ('pullcontent' === $action) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' args=' . var_export($args, TRUE));
				$source_post_id = $input->post_int('post_id', 0);
				$target_post_id = $input->post_int('target_id', 0);
				$content = $input->post('content', 'current');

				if ('new' === $content) {
					// remove old sync data
					if (0 !== $source_post_id) {
						$model->remove_sync_data($source_post_id);
						$model->remove_sync_data($target_post_id);
						$meta_key = '_spectrom_sync_details_' . sanitize_key(SyncOptions::get('target'));
						delete_post_meta($source_post_id, $meta_key);
					}

					// add new post
					$post_args = array('post_title' => 'title', 'post_content' => 'content');
					$source_post_id = wp_insert_post($post_args, TRUE);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' inserted post ID#' . var_export($source_post_id, TRUE));

					if (is_wp_error($source_post_id)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: unable to create post on Source');
						$this->load_class('pullapirequest');
						if (NULL !== $api_request) {
							$resp = $api_request->get_response();
							$resp->error_code(SyncPullApiRequest::ERROR_CANNOT_CREATE_NEW_POST);
						}
						return TRUE;        // return, signaling that we've handled the request
					}
				}

				$sync_data = $model->get_sync_target_post($source_post_id, SyncOptions::get('target_site_key'));

				if (NULL !== $sync_data && $target_post_id !== $sync_data->target_content_id) {
					$model->remove_sync_data($source_post_id);
					$model->remove_sync_data($target_post_id);
					$meta_key = '_spectrom_sync_details_' . sanitize_key(SyncOptions::get('target'));
					delete_post_meta($source_post_id, $meta_key);
					$sync_data = NULL;
				}

				if (NULL === $sync_data) {
					// insert into sync table
					$data = array(
						'content_type' => 'post',
						'site_key' => SyncOptions::get('site_key'),
						'target_site_key' => SyncOptions::get('target_site_key'),
						'source_content_id' => $source_post_id,
						'target_content_id' => $target_post_id,
					);
					$model->save_sync_data($data);

					$data = array(
						'content_type' => 'post',
						'site_key' => SyncOptions::get('target_site_key'),
						'target_site_key' => SyncOptions::get('site_key'),
						'source_content_id' => $target_post_id,
						'target_content_id' => $source_post_id,
					);
					$model->save_sync_data($data);
				}

				$args['post_id'] = $source_post_id;
			}
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' args=' . SyncDebug::arr_sanitize($args));

			// return the filter value
			return $args;
		}

		/**
		 * Handles the requests being processed on the Target from SyncApiController
		 * @param type $return
		 * @param type $action
		 * @param SyncApiResponse $response
		 */
		public function api_controller_request($return, $action, SyncApiResponse $response)
		{
SyncDebug::log(__METHOD__."() handling '{$action}' action");

			if (!$this->_license->check_license('sync_pull', self::PLUGIN_KEY, self::PLUGIN_NAME))
				return $return;

			// handle 'pullinfo' requests
			if ('pullinfo' === $action) {
				// TODO: move this implementation into SyncPullApiRequest class to reduce size of WPSiteSync_Pull class
				$input = new SyncInput();
				$source_post_id = $input->post_int('post_id', 0);

				// check api parameters
				if (0 === $source_post_id) {
					$this->load_class('pullapirequest');
					$response->error_code(SyncPullApiRequest::ERROR_TARGET_POST_NOT_FOUND);
					return TRUE;			// return, signaling that the API request was processed
				}

				// build up post information to be returned via API
				$model = new SyncModel();
				$post_data = get_post($source_post_id, OBJECT);

				$response->set('post_data', $post_data);		// add all the post information to the ApiResponse object
				$response->set('site_key', SyncOptions::get('site_key'));

				// if post_author provided, also give their name
				if (isset($post_data->post_author)) {
					$author = abs($post_data->post_author);
					$user = get_user_by('id', $author);
					if (FALSE !== $user)
						$response->set('username', $user->user_login);
				}

				$return = TRUE;						// tell the SyncApiController that the request was handled
			}

			if ('pullcontent' === $action) {
				// TODO: move this implementation into SyncPullApiRequest class to reduce size of WPSiteSync_Pull class
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post data: ' . var_export($_POST, TRUE));
				$input = new SyncInput();

				$source_post_id = $input->post_int('post_id', 0);
				$target_post_id = $input->post_int('target_id', 0);
				$content = $input->post('content', 'current');

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source id=' . $source_post_id . ' target id=' . $target_post_id);

				// TODO: improve lookup logic
				// TODO: if no post id, look up by name

				// check api parameters
				if (0 === $source_post_id && 'current' === $content) {
					$this->load_class('pullapirequest');
					$response->error_code(SyncPullApiRequest::ERROR_TARGET_POST_NOT_FOUND);
					return TRUE;			// return, signaling that the API request was processed
				}

				$api = new SyncApiRequest();
				$data = array();
				add_filter('spectrom_sync_api_push_content', array($this, 'filter_push_data'), 10, 2);
				$data = $api->get_push_data($target_post_id, $data);

				// build up post information to be returned via API
//				$model = new SyncModel();
//				$data = $model->build_sync_data($target_post_id);	// use post id provided via the API
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - build_sync_data() returned ' . var_export($data, TRUE));

				foreach ($data as $key => $value) {
					$response->set($key, $value);
				}
//				$response->set('post_data', $data['post_data']);		// add all the post information to the ApiResponse object
				$response->set('site_key', SyncOptions::get('site_key'));
				$response->set('post_id', $source_post_id);

				// if post_author provided, also give their name
				if (isset($data['post_data']['post_author'])) {
					$author = abs($data['post_data']['post_author']);
					$user = get_user_by('id', $author);
					if (FALSE !== $user)
						$response->set('username', $user->user_login);
				}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - response data=' . var_export($response, TRUE));

				$return = TRUE;						// tell the SyncApiController that the request was handled
			}

			// process pullsearch
			if ('pullsearch' === $action) {
				// TODO: move this implementation into SyncPullApiRequest class to reduce size of WPSiteSync_Pull class
				$input = new SyncInput();
				$post_type = $input->post('posttype', NULL);
				$search = $input->post('search', NULL);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' post type=' . $post_type . ' search=' . $search);

				// check api parameters
				if (NULL === $post_type) {
					$this->load_class('pullapirequest');
					$response->error_code(SyncPullApiRequest::ERROR_POST_TYPE_NOT_FOUND);
					return TRUE;            // return, signaling that the API request was processed
				}
				if (NULL === $search) {
					$this->load_class('pullapirequest');
					$response->error_code(SyncPullApiRequest::ERROR_SEARCH_NOT_FOUND);
					return TRUE;            // return, signaling that the API request was processed
				}

				if ('' === $search) {
					$response->set('search_results', __('0 posts found', 'wpsitesync-pull'));
					return TRUE;
				}

				// build a list of all the post status values we need to look for #146
				$stati = get_post_stati();
				// not looking for auto-draft (won't be edited) and inherit (we want only content not images)
				$post_stati = array_diff(array_keys($stati), array('auto-draft', 'inherit'));

				// search for results
				$args = array(
					'post_type' => array($post_type),
					's' => $search,
					'nopaging' => TRUE,
					'posts_per_page' => '100',
					'orderby' => 'post_date',
					'order' => 'desc',
					'post_status' => $post_stati, // array('publish', 'pending', 'draft', 'future', 'private', 'trash'),
					'cache_results' => FALSE,
					'update_post_meta_cache' => FALSE,
					'update_post_term_cache' => FALSE,
				);

				$query = new WP_Query($args);

				ob_start();

				if ($query->have_posts()) {
					echo '<p>', sprintf(__('Found %d items matching search. Click on an item below to select it, then you can Pull.', 'wpsitesync-pull'), $query->post_count), '</p>';

					echo '<div id="sync-pull-results-header" class="sync-pull-row">
						<div class="sync-pull-column-id">', __('ID', 'wpsitesync-pull'), '</div>
						<div class="sync-pull-column-title">', __('Title', 'wpsitesync-pull'), '</div>
						<div class="sync-pull-column-content">', __('Content', 'wpsitesync-pull'), '</div>
						<div class="sync-pull-column-modified">', __('Modified', 'wpsitesync-pull'), '</div>
						<div class="sync-pull-column-author">', __('Author', 'wpsitesync-pull'), '</div>
						<div class="sync-pull-column-status">', __('Status', 'wpsitesync-pull'), '</div>
					</div>';

					global $post;
					while ($query->have_posts()) {
						$query->the_post();
						?>
						<div id="sync-pull-id-<?php the_ID(); ?>" class="sync-pull-row">
							<div class="sync-pull-column-id"><?php the_ID(); ?></div>
							<div class="sync-pull-column-title"><?php the_title(); ?></div>
							<div class="sync-pull-column-content"><?php echo get_the_excerpt(); ?></div>
							<div class="sync-pull-column-modified"><?php the_modified_date(); ?></div>
							<div class="sync-pull-column-author"><?php the_author(); ?></div>
							<div class="sync-pull-column-status"><?php
								switch ($post->post_status) {
								case 'publish': echo 'published';	break;
								case 'pending': echo 'pending';		break;
								case 'draft':	echo 'draft';		break;
								case 'future':	echo 'future';		break;
								case 'private':	echo 'private';		break;
								case 'trash':	echo 'trash';		break;
								default:		echo ' ';			break;
								}
							?></div>
						</div>
						<?php
					}
				} else {
					echo __('No Content found that match your search. Try searching for something else.', 'wpsitesync-pull');
					echo ' <a href="https://wpsitesync.com/knowledgebase/wpsitesync-pull-error-messages/#error103" target="_blank" style="text-decoration:none"><span class="dashicons dashicons-info"></span></a>';
				}

				$search_results = ob_get_clean();

				wp_reset_postdata();

				$response->set('search_results', $search_results);        // add all the information to the ApiResponse object
				$response->set('site_key', SyncOptions::get('site_key'));

SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - response data=' . var_export($response, TRUE));

				$return = TRUE; // tell the SyncApiController that the request was handled
			}

			// TODO: this still used?
			if ('pull_process' === $action)
				return TRUE;

			return $return;
		}

		/** adds the media information for the post to the data returned via API call
		 * @param array $data The data array being constructed for the API call
		 * @param SyncApiRequest $api_request The API Request object building the data
		 * @return array The modified $data array
		 */
		public function filter_push_data($data, $api_request)
		{
SyncDebug::log(__METHOD__.'()');
			$queue = $api_request->get_queue();
			if (!empty($queue)) {
				$media_list = array();
				foreach ($queue as $queue_entry) {
					unset($queue_entry['data']['contents']);
					$media_list[] = $queue_entry['data'];
				}
				$data['pull_media'] = $media_list;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' moving media queue entries: ' . var_export($media_list, TRUE));
				$api_request->clear_queue();
			}

			return $data;
		}

		/**
		 * Handles the request on the Source after API Requests are made and the response is ready to be interpreted
		 * @param string $action The API name, i.e. 'push' or 'pull'
		 * @param array $remote_args The arguments sent to SyncApiRequest::api()
		 * @param SyncApiResponse $response The response object after the API requesst has been made
		 */
		public function api_response($action, $remote_args, $response)
		{
SyncDebug::log(__METHOD__."('{$action}')");
			if ('pullcontent' === $action) {
				// TODO: check for error code
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' response from API request: ' . var_export($response, TRUE));
				$api_response = NULL;
				if (isset($response->response)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' decoding response: ' . var_export($response->response, TRUE));
					$api_response = $response->response;
				}
else SyncDebug::log(__METHOD__.'():' . __LINE__ . ' no reponse->response element');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' api response body=' . var_export($api_response, TRUE));

				if (NULL !== $api_response) {
					$save_post = $_POST;

					$site_key = $api_response->data->site_key; // $pull_data->site_key;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target\'s site key: ' . $site_key);
					$target_url = SyncOptions::get('target');

					// copy content from API results into $_POST array to simulate call to SyncApiController
					$post_data = json_decode(json_encode($api_response->data), TRUE);
					foreach ($post_data as $key => $value)
						$_POST[$key] = $value;

					// after copying from API results, reset some of the data to simulate the API call correctly
					$_POST['post_id'] = abs($api_response->data->post_data->ID);
					$_POST['target_post_id'] = abs($api_response->data->post_id);	// used by SyncApiController->push() to identify target post
###					$_POST['post_data'] = $pull_data;
					$_POST['action'] = 'push';
					// TODO: set up headers

					$args = array(
						'action' => 'push',
						'parent_action' => 'pull',
						'site_key' => $site_key,
						'source' => $target_url,
						'response' => $response,
						'no_response' => TRUE,
						'auth' => 0,
					);
					// creating the controller object will call the 'spectrom_sync_api_process' filter to process the data
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' creating controller with: ' . var_export($args, TRUE));
					add_action('spectrom_sync_push_content', array($this, 'process_push_request'), 20, 3);
					$this->_push_controller = SyncApiController::get_instance($args);
					$this->_push_controller->dispatch();
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - returned from controller');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - response=' . var_export($response, TRUE));

					// process media entries
SyncDebug::log(__METHOD__.'(): ' . __LINE__ . ' - checking for media items');
					if (isset($_POST['pull_media'])) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found ' . count($_POST['pull_media']) . ' media items');
						$this->_handle_media(intval($_POST['target_post_id']), $_POST['pull_media'], $response);
					}

					// signal end of processing
					do_action('spectrom_sync_pull_complete');

					$_POST = $save_post;
					if (0 === $response->get_error_code()) {
						$edit_url = admin_url('post.php?post=' . $api_response->data->post_id . '&action=edit');
						$response->set('edit_url', $edit_url);
						$response->success(TRUE);
					}
				}
//else SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - no response body');
			} else if ('pullsearch' === $action) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' response from API request: ' . var_export($response, TRUE));
				$api_response = NULL;
				if (isset($response->response) && isset($response->response->data->search_results)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' decoding response: ' . var_export($response->response, TRUE));
					$api_response = $response->response;
					$response->set('search_results', $response->response->data->search_results);
				}
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' api response body=' . var_export($api_response, TRUE));
			}
		}

		/**
		 * Handle media file transfers during 'pull' operations
		 * @param int $source_post_id The post ID on the Source
		 * @param array $media_items The $_POST['pull_media'] data
		 * @param SyncApiResponse $response The response instance
		 */
		private function _handle_media($source_post_id, $media_items, $response)
		{
			// adopted from SyncApiController::upload_media()

/*		The media data - built in SyncApiRequest->_upload_media()
			'name' => 'value',
			'post_id' => 219,
			'featured' => 0,
			'boundary' => 'zLR%keXstULAd!#89fmZIq2%',
			'img_path' => '/path/to/wp/wp-content/uploads/2016/04',
			'img_name' => 'image-name.jpg',
			'img_url' => 'http://target.com/wp-content/uploads/2016/04/image-name.jpg',
			'attach_id' => 277,
			'attach_desc' => '',
			'attach_title' => 'image-name',
			'attach_caption' => '',
			'attach_name' => 'image-name',
			'attach_alt' => '',
 */
			// check that user can upload files
			if (!current_user_can('upload_files')) {
				$this->load_class('pullapirequest');
				$response->notice_code(SyncPullApiRequest::NOTICE_CANNOT_UPLOAD);
			}

			require_once(ABSPATH . 'wp-admin/includes/image.php');
			require_once(ABSPATH . 'wp-admin/includes/file.php');
			require_once(ABSPATH . 'wp-admin/includes/media.php');

			add_filter('wp_handle_upload', array($this, 'handle_upload'));

			// TODO: check uploaded file contents to ensure it's an image
			// https://en.wikipedia.org/wiki/List_of_file_signatures

			$upload_dir = wp_upload_dir();
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' upload dir=' . var_export($upload_dir, TRUE));
			foreach ($media_items as $media_file) {
				// check if this is the featured image
				$featured = isset($media_file['featured']) ? intval($media_file['featured']) : 0;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' featured=' . $featured);

				// move remote file to local site
				$path = $upload_dir['basedir'] . '/' . $media_file['img_name']; // tempnam(sys_get_temp_dir(), 'snc');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' work file=' . $path . ' url=' . $media_file['img_url']);
				file_put_contents($path, file_get_contents($media_file['img_url']));
				$temp_name = tempnam(sys_get_temp_dir(), 'syn');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' temp name=' . $temp_name);
				copy($path, $temp_name);

				// get just the basename - no extension - of the image being transferred
				$ext = pathinfo($media_file['img_name'], PATHINFO_EXTENSION);
				$basename = basename($media_file['img_name'], $ext);

				// check file type
				$img_type = wp_check_filetype($path);
				$mime_type = $img_type['type'];
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found image type=' . $img_type['ext'] . '=' . $img_type['type']);
				// TODO: instead of explicitly checking for image and pdf, add a 'spectrom_sync_upload_media_allowed_mime_type' filter and allow PDFs
				// TODO: resulting test should only be if (FALSE === apply_filters('spectrom_sync_upload_media_allowed_mime_type', FALSE, $img_type))
				if ((FALSE === strpos($mime_type, 'image/') && 'pdf' !== $img_type['ext']) ||
					apply_filters('spectrom_sync_upload_media_allowed_mime_type', FALSE, $img_type)) {
					$response->error_code(SyncApiRequest::ERROR_INVALID_IMG_TYPE);
					$response->send();
				}

				// TODO: move this to a model
				global $wpdb;
				$sql = "SELECT `ID`
						FROM `{$wpdb->posts}`
						WHERE `post_name`=%s AND `post_type`='attachment'";
				$res = $wpdb->get_col($wpdb->prepare($sql, $basename));
				$attachment_id = 0;
				if (0 != count($res))
					$attachment_id = intval($res[0]);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' attach id=' . $attachment_id);

				$target_post_id = intval($media_file['post_id']);

				$this->media_id = 0;
				$this->local_media_name = '';

				// set this up for wp_handle_upload() calls
				$overrides = array(
					'test_form' => FALSE,			// really needed because we're not submitting via a form
					'test_size' => FALSE,			// don't worry about the size
					'unique_filename_callback' => array($this, 'unique_filename_callback'),
					'action' => 'wp_handle_sideload', // 'wp_handle_upload',
				);

				// check if attachment exists
				if (0 !== $attachment_id) {
					$this->media_id = $attachment_id;
					// TODO: check if files need to be updated / replaced / deleted
					// TODO: handle overwriting/replacing image files of the same name
					// if it's the featured image, set that
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking featured image - source=' . $source_post_id . ' attach=' . $attachment_id);
					if ($featured && 0 !== $source_post_id)
						set_post_thumbnail($source_post_id, $attachment_id);
				} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found no image - adding to library');
					$time = str_replace('\\', '/', substr($media_file['img_path'], -7));
					$_POST['action'] = 'wp_handle_upload';		// shouldn't have to do this with $overrides['test_form'] = FALSE
$_POST['action'] = 'wp_handle_sideload';
					// construct the $_FILES element
					$file_info = array(
						'name' => $media_file['img_name'],
						'type' => $img_type['type'],
						'tmp_name' => $temp_name,
						'error' => 0,
						'size' => filesize($path),
					);
					$_FILES['sync_file_upload'] = $file_info;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' files=' . var_export($_FILES, TRUE));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' sending to wp_handle_upload(): ' . var_export($file_info, TRUE));
					$file = wp_handle_upload($file_info, $overrides, $time);
//					$ret = media_handle_sideload($file_info, $source_post_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' returned: ' . var_export($file, TRUE));
					if (!is_array($file) || isset($file['error'])) {
//					if (is_wp_error($ret)) {
						$has_error = TRUE;
						$err_msg = __('Error returned from wp_handle_upload()', 'wpsitesync-pull');
						if (isset($file['error']))
							$err_msg = sprintf(__('Error returned from wp_handle_upload(): %1$s', 'wpsitesync-pull'), $file['error']);
						$response->notice_code(SyncApiRequest::ERROR_FILE_UPLOAD, $err_msg /*$ret->get_error_message() #31 */ );
					} else {
						$upload_file = $upload_dir['baseurl'] . '/' . $time . '/' . basename($file['file']);

						$attachment = array (		// create attachment for our post
							'post_title' => $media_file['attach_title'],
							'post_name' => $media_file['attach_name'],
							'post_content' => $media_file['attach_desc'],
							'post_excerpt' => $media_file['attach_caption'],
							'post_status' => 'inherit',
							'post_mime_type' => $file['type'],	// type of attachment
							'post_parent' => $source_post_id,	// post id
							'guid' => $upload_file,
						);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' insert attachment parameters: ' . var_export($attachment, TRUE));
						$attach_id = wp_insert_attachment($attachment, $file['file'], $source_post_id);	// insert post attachment
SyncDebug::log(__METHOD__.'():' . __LINE__ . " wp_insert_attachment([..., '{$file['file']}', {$source_post_id}) returned {$attach_id}");
						$attach = wp_generate_attachment_metadata($attach_id, $file['file']);	// generate metadata for new attacment
SyncDebug::log(__METHOD__.'():' . __LINE__ . "() wp_generate_attachment_metadata({$attach_id}, '{$file['file']}') returned " . var_export($attach, TRUE));
						update_post_meta($attach_id, '_wp_attachment_image_alt', $media_file['attach_alt'], TRUE);
						wp_update_attachment_metadata($attach_id, $attach);
						$this->media_id = $attach_id;

						// if it's the featured image, set that
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' featured=' . $featured . ' source=' . $source_post_id . ' attach=' . $attach_id);
						if ($featured && 0 !== $source_post_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . " set_post_thumbnail({$source_post_id}, {$attach_id})");
							set_post_thumbnail($source_post_id, $attach_id);
						}
					}
				}

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' removing work file ' . $path . ' and temp file ' . $temp_name);
				unlink($path);
				if (file_exists($temp_name))
					unlink($temp_name);

				// notify add-ons about media
				do_action('spectrom_sync_media_processed', $source_post_id, $attachment_id, $this->media_id);
			}
		}

		/**
		 * Callback for the 'wp_handle_upload' filter. Stores the media name
		 * @param array $info Array of uploaded data
		 * @param string $context Type of upload action; 'upload' or 'sideload'
		 * @return array Modified info array
		 */
		public function handle_upload($info, $context = '')
		{
SyncDebug::log(__METHOD__.'():' . __LINE__ . var_export($info, TRUE));
			// TODO: use parse_url() instead
			$parts = explode('/', $info['url']);
			$this->local_media_name = array_pop($parts);
			return $info;
		}

		/**
		 * Looks up the media filename and if found, replaces the name with the local system's name of the file
		 * Callback used by the media_handle_upload() call
		 * @param string $dir Directory name
		 * @param string $name File name
		 * @param string $ext Extension name
		 * @return string the filename of media item, adjusted to the previously used name if
		 */
		public function unique_filename_callback($dir, $name, $ext)
		{
SyncDebug::log(__METHOD__."('{$dir}', '{$name}', '{$ext}')");
			// this forces re-use of uploaded image names #54
			if (FALSE !== stripos($name, $ext)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' returning "' . $name . '"');
				return $name;
			}
			return $name . $ext;
		}

		/**
		 * Handles processing of Push requests. Called from SyncApiController->push()
		 * @param int $target_post_id Post ID on Target site
		 * @param array $data Data array to be sent with API request
		 * @param SyncApiResponse $response The Response instance
		 */
		public function process_push_request($target_post_id, $data, $response)
		{
			if (NULL !== $this->_push_controller && 'push' === $this->_push_controller->parent_action) {
				// add image information into the data array of the Push rather than handling as separate 'upload_media' API requests
				// TODO: implement
			}
		}

		/**
		 * Called after the SyncApiController has sent the request to Target and received a response
		 * @param string $action The API action, i.e. 'push', 'pullcontent', etc.
		 * @param SyncApiResponse $response The response instance returned from the Target
		 * @param SyncApiController $controller The controller instance that performed the request
		 */
		// TODO: verify whether or not this is needed. api_controller_request() is handling everything, don't need post-processing
		public function api_process($action, $response, $controller)
		{
SyncDebug::log(__METHOD__."('{$action}')");
			$api_result = array();
			if (isset($controller->args['response']))
				$api_result = $controller->args['response'];
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' controller response: ' . var_export($api_result, TRUE));

//			if ('pull_process' === $action) { //  && isset($response->response->data->pull_data)) {
			if ('pullcontent' === $action) {
				$result = array();
				if (isset($api_result->response->data->pull_data))
					$result = $api_result->response->data->pull_data;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' api result data: ' . var_export($result, TRUE));
				$_POST['post_data'] = get_object_vars($result->post_data);	// turn this into an array
				$_POST['action'] = 'push';					// simulate a push operation for controller->push() call
				$controller->push($response);
//$response_data = $response->response->data->pull_data;
//SyncDebug::log(__METHOD__.'() found pull action response: ' . var_export($response_data, TRUE));
			}
		}

		/**
		 * Performs post processing of the API response. Used as a chance to call the SyncApiController() and simulate a 'push' operation
		 * @param string $action The API action being performed
		 * @param int $post_id The post id that the action is performed on
		 * @param array $data The data returned from the API request
		 * @param SyncApiResponse $response The response object
		 */
		public function api_success($action, $post_id, $data, $response)
		{
SyncDebug::log(__METHOD__."('{$action}', {$post_id}, ...)");
			if ('pullcontent' === $action) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' data: ' . var_export($data, TRUE));
				$save_post = $_POST;
				$_POST['post_id'] = $post_id;
				$_POST['post_data'] = $data;
				$_POST['action'] = 'push';
				// TODO: set up headers

				$args = array(
					'action' => 'push',
					'site_key' => SyncOptions::get('target_site_key'),
					'source' => SyncOptions::get('host'),
					'response' => $response,
					'no_response' => TRUE,
				);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' calling SyncApiController() with arguments');
				$controller = SyncApiController::get_instance($args);
				$controller->dispatch();
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' returned from controller');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' response=' . var_export($response, TRUE));
			}
		}

		/**
		 * Filters the errors list, adding SyncPull specific code-to-string values
		 * @param string $message The error string message to be returned
		 * @param int $code The error code being evaluated
		 * @return string The modified $message string, with Pull specific errors added to it
		 */
		public function filter_error_codes($message, $code)
		{
			WPSiteSync_Pull::get_instance()->load_class('pullapirequest');
			switch ($code) {
			case SyncPullApiRequest::ERROR_TARGET_POST_NOT_FOUND:	$message = __('Matching Content cannot be found on Target site. Please Push first.', 'wpsitesync-pull'); break;
			case SyncPullApiRequest::ERROR_POST_NOT_FOUND:			$message = __('The post cannot be found.', 'wpsitesync-pull'); break;
			case SyncPullApiRequest::ERROR_POST_TYPE_NOT_FOUND: 	$message = __('No post type provided.', 'wpsitesync-pull'); break;
			case SyncPullApiRequest::ERROR_SEARCH_NOT_FOUND: 		$message = __('Search is empty.', 'wpsitesync-pull'); break;
			case SyncPullApiRequest::ERROR_CANNOT_CREATE_NEW_POST: 	$message = __('Unable to create new post.', 'wpsitesync-pull'); break;
			}
			return $message;
		}

		/**
		 * Filters the notices list, adding SyncPull specific code-to-string values
		 * @param string $message The notice string message to be returned
		 * @param int $code The notice code being evaluated
		 * @return string The modified $message string, with Pull specific notices added to it
		 */
		public function filter_notice_codes($message, $code)
		{
			WPSiteSync_Pull::get_instance()->load_class('pullapirequest');
			switch ($code) {
			case SyncPullApiRequest::NOTICE_CONTENT_MODIFIED:		$message = __('Content has been modified on Target site since the last Push. Continue?', 'wpsitesync-pull'); break;
			case SyncPullApiRequest::NOTICE_CANNOT_UPLOAD:			$message = __('Cannot Pull images. You do not have required permissions.', 'wpsitesync-pull'); break;
			case SyncPullApiRequest::NOTICE_FILE_ERROR:				$message = __('Error processing attachments.', 'wpsitesync-pull'); break;
			}
			return $message;
		}

		/**
		 * Loads a specified class file name and optionally creates an instance of it
		 * @param string $name Name of class to load
		 * @param boolean $create TRUE to create an instance of the loaded class
		 * @return object Created instance of $create is TRUE; otherwise FALSE
		 */
		public function load_class($name, $create = FALSE)
		{
//SyncDebug::log(__METHOD__.'(' . $name . ')');
			$file = dirname(__FILE__) . '/classes/' . strtolower($name) . '.php';
			if (file_exists($file))
				require_once($file);
			if ($create) {
				$instance = 'Sync' . $name;
				return new $instance();
			}
			return;
		}

		/**
		 * Adds the WPSiteSync Pull add-on to the list of known WPSiteSync extensions
		 * @param array $extensions The list of extensions
		 * @param boolean TRUE to force adding the extension; otherwise FALSE
		 * @return array Modified list of extensions
		 */
		public function filter_active_extensions($extensions, $set = FALSE)
		{
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking active extensions set=' . ($set ? 'TRUE' : 'FALSE'));
			if ($set || $this->_license->check_license('sync_pull', self::PLUGIN_KEY, self::PLUGIN_NAME))
				$extensions['sync_pull'] = array(
					'name' => self::PLUGIN_NAME,
					'version' => self::PLUGIN_VERSION,
					'file' => __FILE__,
				);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' returning ' . var_export($extensions, TRUE));
			return $extensions;
		}
	}
}

// Initialize the extension
WPSiteSync_Pull::get_instance();

// EOF
