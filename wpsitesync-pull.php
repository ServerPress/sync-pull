<?php
/*
Plugin Name: WPSiteSync for Pull
Plugin URI: http://wpsitesync.com
Description: Allow Content Creators to "Pull" Content from the Target site into the Source site.
Author: WPSiteSync
Author URI: http://wpsitesync.com
Version: 1.0
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
		const PLUGIN_VERSION = '1.0';
		const PLUGIN_KEY = '4151f50e546c7b0a53994d4c27f4cf31'; // '1a127f14595c88504b22839abc40708c';

		private $_license = NULL;
		private $_push_controller = NULL;

		private function __construct()
		{
//SyncDebug::log(__METHOD__.'()');
			add_action('spectrom_sync_init', array(&$this, 'init'));
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
			add_filter('spectrom_sync_active_extensions', array(&$this, 'filter_active_extensions'), 10, 2);

			$this->_license = new SyncLicensing();
			if (!$this->_license->check_license('sync_pull', self::PLUGIN_KEY, self::PLUGIN_NAME))
				return;

			if (is_admin()) {
				$this->load_class('pulladmin');
//				require_once (dirname(__FILE__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'pulladmin.php');
				SyncPullAdmin::get_instance();
			}

			add_filter('spectrom_sync_api_request_action', array(&$this, 'api_request'), 20, 3);		// called by SyncApiRequest
			add_filter('spectrom_sync_api', array(&$this, 'api_controller_request'), 10, 3);			// called by SyncApiController
			add_action('spectrom_sync_api_request_response', array(&$this, 'api_response'), 10, 3);		// called by SyncApiRequest->api()
//			add_action('spectrom_sync_action_success', array(&$this, 'api_success'), 10, 4);			// called after api('pullcontent') is successfully processed in SyncApiRequest
//			add_action('spectrom_sync_api_process', array(&$this, 'api_process'), 10, 3);				// called by SyncApiController::_construct() after processing

			add_filter('spectrom_sync_error_code_to_text', array(&$this, 'filter_error_codes'), 10, 2);
			add_filter('spectrom_sync_notice_code_to_text', array(&$this, 'filter_notice_codes'), 10, 2);
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
		public function api_request($args, $action, $remote_args)
		{
SyncDebug::log(__METHOD__.'() action=' . $action);
			if (!$this->_license->check_license('sync_pull', self::PLUGIN_KEY, self::PLUGIN_NAME))
				return $args;

			$input = new SyncInput();

			// TODO: nonce verification to be done in SyncApiController::__construct() so we don't have to do it here
//			if (!wp_verify_nonce($input->get('_spectrom_sync_nonce'), $input->get('site_key'))) {
//				$response->error_code(SyncApiRequest::ERROR_SESSION_EXPIRED);
////				$response->success(FALSE);
////				$response->set('errorcode', SyncApiRequest::ERR_INVALID_NONCE);
//				return;
//			}

			if ('pullcontent' === $action) {
SyncDebug::log(__METHOD__.'() args=' . var_export($args, TRUE));
// TODO: probably don't need to do anything on the API request since 'post_id' and 'post_name' are already present
###				$post_id = $input->post_int('post_id', 0);
###				if (0 === $post_id && isset($args['source_id']))
###					$post_id = intval($args['source_id']);
###				$target_id = $input->post_int('target_id', 0);
###				if (0 === $target_id && isset($args['target_id']))
###					$target_id = intval($args['target_id']);

###				$data = array(
###					'source_id' => $post_id,
###					'target_id' => $target_id, // isset($args['target_id']) ? $args['target_id'] : 0
###				);
###SyncDebug::log(__METHOD__.'() adding pull_data to API request data: ' . var_export($data, TRUE));

###				$args['pull_data'] = $data;
//				$pull = $this->_load_class('PullApiRequest', TRUE);
//				$pull->get_data($response);
//////////////
/*				// TODO: use SyncModel::is_post_locked()
				if (!function_exists('wp_check_post_lock'))
					require_once(ABSPATH . 'wp-admin/includes/post.php');
				if ($last = wp_check_post_lock($post_id)) {
					$response->success(FALSE);
					$response->error_code(SyncApiRequest::ERROR_CONTENT_LOCKED);
					return TRUE;
				}

				$model = new SyncModel();
				$sync_data = $model->build_sync_data($post_id);

				// TODO: check to see if content is being edited and return SyncApiRequest::ERROR_CONTENT_EDITING
				// TODO: check to see if content is locked and return SyncApiRequest::ERROR_CONTENT_LOCKED

				// also include the user name of the author
				$user_data = get_userdata($sync_data['post_data']['post_author']);
				// User still exists
				if ($user_data) {
					$sync_data['username'] = $user_data->user_login;
					$sync_data['user_id'] = $user_data->ID;
				} else {
					$sync_data['username'] = NULL;
					$sync_data['user_id'] = NULL;
				}

				$response->success(TRUE);
				$response->set('post_id', $post_id);
				$response->set('content', $sync_data);

				// TODO: add data for metadata, attachments, etc. Check to make sure $model->build_sync_data() does this
				// TODO: add comment data associated with this post. Check to make sure $model->build_sync_data() does this

				$return = TRUE;			// notify Sync core that we handled the request
*/
			}

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
SyncDebug::log(__METHOD__.'() post data: ' . var_export($_POST, TRUE));
				$input = new SyncInput();

##				$req_info = $input->post_raw('pull_data', NULL);

##				if (isset($req_info['source_id']))
##					$source_post_id = intval($req_info['source_id']);
				$source_post_id = $input->post_int('post_id', 0);
				$target_post_id = $input->post_int('target_id', 0);

SyncDebug::log(__METHOD__.'() source id=' . $source_post_id . ' target id=' . $target_post_id);

				// TODO: improve lookup logic
				// TODO: if no post id, look up by name

				// check api parameters
				if (0 === $source_post_id) {
					$this->load_class('pullapirequest');
					$response->error_code(SyncPullApiRequest::ERROR_TARGET_POST_NOT_FOUND);
					return TRUE;			// return, signaling that the API request was processed
				}

				$api = new SyncApiRequest();
				$data = array();
				add_filter('spectrom_sync_api_push_content', array(&$this, 'filter_push_data'), 10, 2);
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
SyncDebug::log(__METHOD__.'() moving media queue entries: ' . var_export($media_list, TRUE));
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
SyncDebug::log(__METHOD__.'() response from API request: ' . var_export($response, TRUE));
				$api_response = NULL;
				if (isset($response->response)) {
SyncDebug::log(__METHOD__.'() decoding response: ' . var_export($response->response, TRUE));
					$api_response = $response->response;
				}
else SyncDebug::log(__METHOD__.'() no reponse->response element');
SyncDebug::log(__METHOD__.'() api response body=' . var_export($api_response, TRUE));

				if (NULL !== $api_response) {
					$save_post = $_POST;

					// convert the pull data into an array
###					$pull_data = json_decode(json_encode($api_response->data->post_data), TRUE); // $response->response->data->pull_data;
###SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - pull data=' . var_export($pull_data, TRUE));
					$site_key = $api_response->data->site_key; // $pull_data->site_key;
SyncDebug::log(__METHOD__.'() target\'s site key: ' . $site_key);
					$target_url = SyncOptions::get('target');

					// copy content from API results into $_POST array to simulate call to SyncApiController
					$post_data = json_decode(json_encode($api_response->data), TRUE);
					foreach ($post_data as $key => $value)
						$_POST[$key] = $value;

					// after copying from API results, reset some of the data to simulate the API call correctly
					$_POST['post_id'] = abs($api_response->data->post_data->ID);
					$_POST['target_post_id'] = abs($_REQUEST['post_id']);	// used by SyncApiController->push() to identify target post
###					$_POST['post_data'] = $pull_data;
					$_POST['action'] = 'push';
					// TODO: set up headers

					$args = array(
						'action' => 'push',
						'parent_action' => 'pull',
						'site_key' => $site_key,
						'source' => $target_url,
						'response' => $response,
						'auth' => 0,
					);
					// creating the controller object will call the 'spectrom_sync_api_process' filter to process the data
SyncDebug::log(__METHOD__.'() creating controller with: ' . var_export($args, TRUE));
					add_action('spectrom_sync_push_content', array(&$this, 'process_push_request'), 20, 3);
					$this->_push_controller = new SyncApiController($args);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - returned from controller');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - response=' . var_export($response, TRUE));

					// process media entries
SyncDebug::log(__METHOD__.'(): ' . __LINE__ . ' - checking for media items');
					if (isset($_POST['pull_media'])) {
SyncDebug::log(__METHOD__.'() - found ' . count($_POST['pull_media']) . ' media items');
						$this->_handle_media(intval($_POST['target_post_id']), $_POST['pull_media'], $response);
					}

					$_POST = $save_post;
					if (0 === $response->get_error_code()) {
						$response->success(TRUE);
					} else {
					}
				}
//else SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - no response body');
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

			add_filter('wp_handle_upload', array(&$this, 'handle_upload'));

			// TODO: check uploaded file contents to ensure it's an image
			// https://en.wikipedia.org/wiki/List_of_file_signatures

			$upload_dir = wp_upload_dir();
SyncDebug::log(__METHOD__.'() upload dir=' . var_export($upload_dir, TRUE));
			foreach ($media_items as $media_file) {
				// check if this is the featured image
				$featured = isset($media_file['featured']) ? intval($media_file['featured']) : 0;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' featured=' . $featured);

				// move remote file to local site
				$path = $upload_dir['basedir'] . '/' . $media_file['img_name']; // tempnam(sys_get_temp_dir(), 'snc');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' work file=' . $path . ' url=' . $media_file['img_url']);
				file_put_contents($path, file_get_contents($media_file['img_url']));
				$temp_name = tempnam(sys_get_temp_dir(), 'syn');
SyncDebug::log(__METHOD__.'() temp name=' . $temp_name);
				copy($path, $temp_name);

				// get just the basename - no extension - of the image being transferred
				$ext = pathinfo($media_file['img_name'], PATHINFO_EXTENSION);
				$basename = basename($media_file['img_name'], $ext);

				// check file type
				$img_type = wp_check_filetype($path);
				$mime_type = $img_type['type'];
SyncDebug::log(__METHOD__.'() found image type=' . $img_type['ext'] . '=' . $img_type['type']);
				if (FALSE === strpos($mime_type, 'image/') && 'pdf' !== $img_type['ext']) {
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
					'unique_filename_callback' => array(&$this, 'unique_filename_callback'),
					'action' => 'wp_handle_sideload', // 'wp_handle_upload',
				);

				// check if attachment exists
				if (0 !== $attachment_id) {
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
SyncDebug::log(' files=' . var_export($_FILES, TRUE));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' sending to wp_handle_upload(): ' . var_export($file_info, TRUE));
					$file = wp_handle_upload($file_info, $overrides, $time);
//					$ret = media_handle_sideload($file_info, $source_post_id);
SyncDebug::log(__METHOD__.'() returned: ' . var_export($file, TRUE));
					if (!is_array($file) || isset($file['error'])) {
//					if (is_wp_error($ret)) {
						$has_error = TRUE;
						$response->notice_code(SyncApiRequest::ERROR_FILE_UPLOAD, $ret->get_error_message());
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
SyncDebug::log(__METHOD__.'() insert attachment parameters: ' . var_export($attachment, TRUE));
						$attach_id = wp_insert_attachment($attachment, $file['file'], $source_post_id);	// insert post attachment
SyncDebug::log(__METHOD__."() wp_insert_attachment([..., '{$file['file']}', {$source_post_id}) returned {$attach_id}");
						$attach = wp_generate_attachment_metadata($attach_id, $file['file']);	// generate metadata for new attacment
SyncDebug::log(__METHOD__."() wp_generate_attachment_metadata({$attach_id}, '{$file['file']}') returned " . var_export($attach, TRUE));
						update_post_meta($attach_id, '_wp_attachment_image_alt', $media_file['attach_alt'], TRUE);
						wp_update_attachment_metadata($attach_id, $attach);

						// if it's the featured image, set that
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' featured=' . $featured . ' source=' . $source_post_id . ' attach=' . $attach_id);
						if ($featured && 0 !== $source_post_id) {
SyncDebug::log(__METHOD__."() set_post_thumbnail({$source_post_id}, {$attach_id})");
							set_post_thumbnail($source_post_id, $attach_id);
						}
					}
				}

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' removing work file ' . $path . ' and temp file ' . $temp_name);
				unlink($path);
				unlink($temp_name);
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
SyncDebug::log(__METHOD__.'() ' . var_export($info, TRUE));
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
SyncDebug::log(__METHOD__.'() api result data: ' . var_export($result, TRUE));
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
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - data: ' . var_export($data, TRUE));
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
				);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - calling SyncApiController() with arguments');
				$controller = new SyncApiController($args);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - returned from controller');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - response=' . var_export($response, TRUE));
			}
		}

		/**
		 * Filters the errors list, addint SyncPull specific code-to-string values
		 * @param string $message The error string message to be returned
		 * @param int $code The error code being evaluated
		 * @return string The modified $message string, with Pull specific errors added to it
		 */
		public function filter_error_codes($message, $code)
		{
			WPSiteSync_Pull::get_instance()->load_class('pullapirequest');
			switch ($code) {
			case SyncPullApiRequest::ERROR_TARGET_POST_NOT_FOUND:	$message = __('Content cannot be found on Target site', 'wpsitesync-pull'); break;
			case SyncPullApiRequest::ERROR_POST_NOT_FOUND:			$message = __('The post cannot be found', 'wpsitesync-pull'); break;
			}
			return $message;
		}

		/**
		 * Filters the notices list, addint SyncPull specific code-to-string values
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
			if ($set || $this->_license->check_license('sync_pull', self::PLUGIN_KEY, self::PLUGIN_NAME))
				$extensions['sync_pull'] = array(
					'name' => self::PLUGIN_NAME,
					'version' => self::PLUGIN_VERSION,
					'file' => __FILE__,
				);
			return $extensions;
		}
	}
}

// Initialize the extension
WPSiteSync_Pull::get_instance();

// EOF
