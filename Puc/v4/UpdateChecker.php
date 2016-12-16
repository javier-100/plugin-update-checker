<?php

if ( !class_exists('Puc_v4_UpdateChecker', false) ):

	abstract class Puc_v4_UpdateChecker {
		protected $filterPrefix = 'puc_';
		protected $updateClass = '';
		protected $updateTransient = '';
		protected $translationType = ''; //"plugin" or "theme".

		/**
		 * Set to TRUE to enable error reporting. Errors are raised using trigger_error()
		 * and should be logged to the standard PHP error log.
		 * @var bool
		 */
		public $debugMode = false;

		/**
		 * @var string Where to store the update info.
		 */
		public $optionName = '';

		/**
		 * @var string The URL of the metadata file.
		 */
		public $metadataUrl = '';

		/**
		 * @var string Plugin or theme directory name.
		 */
		public $directoryName = '';

		/**
		 * @var string The slug that will be used in update checker hooks and remote API requests.
		 * Usually matches the directory name unless the plugin/theme directory has been renamed.
		 */
		public $slug = '';

		/**
		 * @var Puc_v4_Scheduler
		 */
		public $scheduler;

		/**
		 * @var string The host component of $metadataUrl.
		 */
		protected $metadataHost = '';

		/**
		 * @var Puc_v4_UpgraderStatus
		 */
		protected $upgraderStatus;

		public function __construct($metadataUrl, $directoryName, $slug = null, $checkPeriod = 12, $optionName = '') {
			$this->debugMode = (bool)(constant('WP_DEBUG'));
			$this->metadataUrl = $metadataUrl;
			$this->directoryName = $directoryName;
			$this->slug = !empty($slug) ? $slug : $this->directoryName;

			$this->optionName = $optionName;
			if ( empty($this->optionName) ) {
				//BC: Initially the library only supported plugin updates and didn't use type prefixes
				//in the option name. Lets use the same prefix-less name when possible.
				if ( $this->filterPrefix === 'puc_' ) {
					$this->optionName = 'external_updates-' . $this->slug;
				} else {
					$this->optionName = $this->filterPrefix . 'external_updates-' . $this->slug;
				}
			}

			$this->scheduler = $this->createScheduler($checkPeriod);
			$this->upgraderStatus = new Puc_v4_UpgraderStatus();

			$this->loadTextDomain();
			$this->installHooks();
		}

		protected function loadTextDomain() {
			//We're not using load_plugin_textdomain() or its siblings because figuring out where
			//the library is located (plugin, mu-plugin, theme, custom wp-content paths) is messy.
			$domain = 'plugin-update-checker';
			$locale = apply_filters('plugin_locale', is_admin() ? get_user_locale() : get_locale(), $domain);

			$moFile = $domain . '-' . $locale . '.mo';
			$path = realpath(dirname(__FILE__) . '/../../languages');

			if ($path && file_exists($path)) {
				load_textdomain($domain, $path . '/ ' . $moFile);
			}
		}

		protected function installHooks() {
			//Insert our update info into the update array maintained by WP.
			add_filter('site_transient_' . $this->updateTransient, array($this,'injectUpdate'));

			//Insert translation updates into the update list.
			add_filter('site_transient_' . $this->updateTransient, array($this, 'injectTranslationUpdates'));

			//Clear translation updates when WP clears the update cache.
			//This needs to be done directly because the library doesn't actually remove obsolete plugin updates,
			//it just hides them (see getUpdate()). We can't do that with translations - too much disk I/O.
			add_action(
				'delete_site_transient_' . $this->updateTransient,
				array($this, 'clearCachedTranslationUpdates')
			);

			//Rename the update directory to be the same as the existing directory.
			add_filter('upgrader_source_selection', array($this, 'fixDirectoryName'), 10, 3);

			//Allow HTTP requests to the metadata URL even if it's on a local host.
			$this->metadataHost = @parse_url($this->metadataUrl, PHP_URL_HOST);
			add_filter('http_request_host_is_external', array($this, 'allowMetadataHost'), 10, 2);

			//TODO: Debugbar
			//TODO: Utility functions for adding filters.
		}

		/**
		 * Explicitly allow HTTP requests to the metadata URL.
		 *
		 * WordPress has a security feature where the HTTP API will reject all requests that are sent to
		 * another site hosted on the same server as the current site (IP match), a local host, or a local
		 * IP, unless the host exactly matches the current site.
		 *
		 * This feature is opt-in (at least in WP 4.4). Apparently some people enable it.
		 *
		 * That can be a problem when you're developing your plugin and you decide to host the update information
		 * on the same server as your test site. Update requests will mysteriously fail.
		 *
		 * We fix that by adding an exception for the metadata host.
		 *
		 * @param bool $allow
		 * @param string $host
		 * @return bool
		 */
		public function allowMetadataHost($allow, $host) {
			if ( strtolower($host) === strtolower($this->metadataHost) ) {
				return true;
			}
			return $allow;
		}

		/**
		 * Create an instance of the scheduler.
		 *
		 * This is implemented as a method to make it possible for plugins to subclass the update checker
		 * and substitute their own scheduler.
		 *
		 * @param int $checkPeriod
		 * @return Puc_v4_Scheduler
		 */
		abstract protected function createScheduler($checkPeriod);

		/**
		 * Check for updates. The results are stored in the DB option specified in $optionName.
		 *
		 * @return Puc_v4_Update|null
		 */
		public function checkForUpdates() {
			$installedVersion = $this->getInstalledVersion();
			//Fail silently if we can't find the plugin/theme or read its header.
			if ( $installedVersion === null ) {
				$this->triggerError(
					sprintf('Skipping update check for %s - installed version unknown.', $this->slug),
					E_USER_WARNING
				);
				return null;
			}

			$state = $this->getUpdateState();
			if ( empty($state) ) {
				$state = new stdClass;
				$state->lastCheck = 0;
				$state->checkedVersion = '';
				$state->update = null;
			}

			$state->lastCheck = time();
			$state->checkedVersion = $installedVersion;
			$this->setUpdateState($state); //Save before checking in case something goes wrong

			$state->update = $this->requestUpdate();
			if ( isset($state->update, $state->update->translations) ) {
				//TODO: Should this be called in requestUpdate, like PluginUpdater does?
				$state->update->translations = $this->filterApplicableTranslations($state->update->translations);
			}
			$this->setUpdateState($state);

			return $this->getUpdate();
		}

		/**
		 * Load the update checker state from the DB.
		 *
		 * @return stdClass|null
		 */
		public function getUpdateState() {
			$state = get_site_option($this->optionName, null);
			if ( empty($state) || !is_object($state) ) {
				$state = null;
			}

			if ( isset($state, $state->update) && is_object($state->update) ) {
				$state->update = call_user_func(array($this->updateClass, 'fromObject'), $state->update);
			}
			return $state;
		}

		/**
		 * Persist the update checker state to the DB.
		 *
		 * @param StdClass $state
		 * @return void
		 */
		protected function setUpdateState($state) {
			if (isset($state->update) && is_object($state->update) && method_exists($state->update, 'toStdClass')) {
				$update = $state->update;
				/** @var Puc_v4_Update $update */
				$state->update = $update->toStdClass();
			}
			update_site_option($this->optionName, $state);
		}

		/**
		 * Reset update checker state - i.e. last check time, cached update data and so on.
		 *
		 * Call this when your plugin is being uninstalled, or if you want to
		 * clear the update cache.
		 */
		public function resetUpdateState() {
			delete_site_option($this->optionName);
		}

		/**
		 * Get the details of the currently available update, if any.
		 *
		 * If no updates are available, or if the last known update version is below or equal
		 * to the currently installed version, this method will return NULL.
		 *
		 * Uses cached update data. To retrieve update information straight from
		 * the metadata URL, call requestUpdate() instead.
		 *
		 * @return Puc_v4_Update|null
		 */
		public function getUpdate() {
			$state = $this->getUpdateState(); /** @var StdClass $state */

			//Is there an update available?
			if ( isset($state, $state->update) ) {
				$update = $state->update;
				//Check if the update is actually newer than the currently installed version.
				$installedVersion = $this->getInstalledVersion();
				if ( ($installedVersion !== null) && version_compare($update->version, $installedVersion, '>') ){
					return $update;
				}
			}
			return null;
		}

		/**
		 * Retrieve the latest update (if any) from the configured API endpoint.
		 *
		 * @return Puc_v4_Update An instance of Update, or NULL when no updates are available.
		 */
		abstract public function requestUpdate();

		/**
		 * Check if $result is a successful update API response.
		 *
		 * @param array|WP_Error $result
		 * @return true|WP_Error
		 */
		protected function validateApiResponse($result) {
			if ( is_wp_error($result) ) { /** @var WP_Error $result */
				return new WP_Error($result->get_error_code(), 'WP HTTP Error: ' . $result->get_error_message());
			}

			if ( !isset($result['response']['code']) ) {
				return new WP_Error(
					$this->filterPrefix . 'no_response_code',
					'wp_remote_get() returned an unexpected result.'
				);
			}

			if ( $result['response']['code'] !== 200 ) {
				return new WP_Error(
					$this->filterPrefix . 'unexpected_response_code',
					'HTTP response code is ' . $result['response']['code'] . ' (expected: 200)'
				);
			}

			if ( empty($result['body']) ) {
				return new WP_Error($this->filterPrefix . 'empty_response', 'The metadata file appears to be empty.');
			}

			return true;
		}

		/**
		 * Get the currently installed version of the plugin or theme.
		 *
		 * @return string Version number.
		 */
		abstract public function getInstalledVersion();

		/**
		 * Register a callback for one of the update checker filters.
		 *
		 * Identical to add_filter(), except it automatically adds the "puc_"/"tuc_" prefix
		 * and the "-$slug" suffix to the filter name. For example, "request_info_result"
		 * becomes "puc_request_info_result-your_plugin_slug".
		 *
		 * @param string $tag
		 * @param callable $callback
		 * @param int $priority
		 * @param int $acceptedArgs
		 */
		public function addFilter($tag, $callback, $priority = 10, $acceptedArgs = 1) {
			add_filter($this->getFilterName($tag), $callback, $priority, $acceptedArgs);
		}

		/**
		 * Get the full name of an update checker filter or action.
		 *
		 * This method adds the "puc_"/"tuc_" prefix and the "-$slug" suffix to the filter name.
		 * For example, "pre_inject_update" becomes "puc_pre_inject_update-plugin-slug".
		 *
		 * @param string $baseTag
		 * @return string
		 */
		public function getFilterName($baseTag) {
			return $this->filterPrefix . $baseTag . '-' . $this->slug;
		}

		/**
		 * Trigger a PHP error, but only when $debugMode is enabled.
		 *
		 * @param string $message
		 * @param int $errorType
		 */
		protected function triggerError($message, $errorType) {
			if ($this->debugMode) {
				trigger_error($message, $errorType);
			}
		}

		/* -------------------------------------------------------------------
		 * Inject updates
		 * -------------------------------------------------------------------
		 */

		/**
		 * Insert the latest update (if any) into the update list maintained by WP.
		 *
		 * @param stdClass $updates Update list.
		 * @return stdClass Modified update list.
		 */
		public function injectUpdate($updates) {
			//Is there an update to insert?
			$update = $this->getUpdate();

			if ( !$this->shouldShowUpdates() ) {
				$update = null;
			}

			if ( !empty($update) ) {
				//Let plugins filter the update info before it's passed on to WordPress.
				$update = apply_filters($this->getFilterName('pre_inject_update'), $update);
				$updates = $this->addUpdateToList($updates, $update->toWpFormat());
			} else {
				//Clean up any stale update info.
				$updates = $this->removeUpdateFromList($updates);
			}

			return $updates;
		}

		/**
		 * @param stdClass|null $updates
		 * @param stdClass|array $updateToAdd
		 * @return stdClass
		 */
		protected function addUpdateToList($updates, $updateToAdd) {
			if ( !is_object($updates) ) {
				$updates = new stdClass();
				$updates->response = array();
			}

			$updates->response[$this->getUpdateListKey()] = $updateToAdd;
			return $updates;
		}

		/**
		 * @param stdClass|null $updates
		 * @return stdClass|null
		 */
		protected function removeUpdateFromList($updates) {
			if ( isset($updates, $updates->response) ) {
				unset($updates->response[$this->getUpdateListKey()]);
			}
			return $updates;
		}

		/**
		 * Get the key that will be used when adding updates to the update list that's maintained
		 * by the WordPress core. The list is always an associative array, but the key is different
		 * for plugins and themes.
		 *
		 * @return string
		 */
		abstract protected function getUpdateListKey();

		/**
		 * Should we show available updates?
		 *
		 * Usually the answer is "yes", but there are exceptions. For example, WordPress doesn't
		 * support automatic updates installation for mu-plugins, so PUC usually won't show update
		 * notifications in that case. See the plugin-specific subclass for details.
		 *
		 * Note: This method only applies to updates that are displayed (or not) in the WordPress
		 * admin. It doesn't affect APIs like requestUpdate and getUpdate.
		 *
		 * @return bool
		 */
		protected function shouldShowUpdates() {
			return true;
		}

		/* -------------------------------------------------------------------
		 * Language packs / Translation updates
		 * -------------------------------------------------------------------
		 */

		/**
		 * Filter a list of translation updates and return a new list that contains only updates
		 * that apply to the current site.
		 *
		 * @param array $translations
		 * @return array
		 */
		protected function filterApplicableTranslations($translations) {
			$languages = array_flip(array_values(get_available_languages()));
			$installedTranslations = $this->getInstalledTranslations();

			$applicableTranslations = array();
			foreach($translations as $translation) {
				//Does it match one of the available core languages?
				$isApplicable = array_key_exists($translation->language, $languages);
				//Is it more recent than an already-installed translation?
				if ( isset($installedTranslations[$translation->language]) ) {
					$updateTimestamp = strtotime($translation->updated);
					$installedTimestamp = strtotime($installedTranslations[$translation->language]['PO-Revision-Date']);
					$isApplicable = $updateTimestamp > $installedTimestamp;
				}

				if ( $isApplicable ) {
					$applicableTranslations[] = $translation;
				}
			}

			return $applicableTranslations;
		}

		/**
		 * Get a list of installed translations for this plugin or theme.
		 *
		 * @return array
		 */
		protected function getInstalledTranslations() {
			$installedTranslations = wp_get_installed_translations($this->translationType . 's');
			if ( isset($installedTranslations[$this->directoryName]) ) {
				$installedTranslations = $installedTranslations[$this->directoryName];
			} else {
				$installedTranslations = array();
			}
			return $installedTranslations;
		}

		/**
		 * Insert translation updates into the list maintained by WordPress.
		 *
		 * @param stdClass $updates
		 * @return stdClass
		 */
		public function injectTranslationUpdates($updates) {
			$translationUpdates = $this->getTranslationUpdates();
			if ( empty($translationUpdates) ) {
				return $updates;
			}

			//Being defensive.
			if ( !is_object($updates) ) {
				$updates = new stdClass();
			}
			if ( !isset($updates->translations) ) {
				$updates->translations = array();
			}

			//In case there's a name collision with a plugin or theme hosted on wordpress.org,
			//remove any preexisting updates that match our thing.
			$filteredTranslations = array();
			foreach($updates->translations as $translation) {
				if (
					($translation['type'] === $this->translationType)
					&& ($translation['slug'] === $this->directoryName)
				) {
					continue;
				}
				$filteredTranslations[] = $translation;
			}
			$updates->translations = $filteredTranslations;

			//Add our updates to the list.
			foreach($translationUpdates as $update) {
				$convertedUpdate = array_merge(
					array(
						'type' => $this->translationType,
						'slug' => $this->directoryName,
						'autoupdate' => 0,
						//AFAICT, WordPress doesn't actually use the "version" field for anything.
						//But lets make sure it's there, just in case.
						'version' => isset($update->version) ? $update->version : ('1.' . strtotime($update->updated)),
					),
					(array)$update
				);

				$updates->translations[] = $convertedUpdate;
			}

			return $updates;
		}

		/**
		 * Get a list of available translation updates.
		 *
		 * This method will return an empty array if there are no updates.
		 * Uses cached update data.
		 *
		 * @return array
		 */
		public function getTranslationUpdates() {
			$state = $this->getUpdateState();
			if ( isset($state, $state->update, $state->update->translations) ) {
				return $state->update->translations;
			}
			return array();
		}

		/**
		 * Remove all cached translation updates.
		 *
		 * @see wp_clean_update_cache
		 */
		public function clearCachedTranslationUpdates() {
			$state = $this->getUpdateState();
			if ( isset($state, $state->update, $state->update->translations) ) {
				$state->update->translations = array();
				$this->setUpdateState($state);
			}
		}

		/* -------------------------------------------------------------------
		 * Fix directory name when installing updates
		 * -------------------------------------------------------------------
		 */

		/**
		 * Rename the update directory to match the existing plugin/theme directory.
		 *
		 * When WordPress installs a plugin or theme update, it assumes that the ZIP file will contain
		 * exactly one directory, and that the directory name will be the same as the directory where
		 * the plugin or theme is currently installed.
		 *
		 * GitHub and other repositories provide ZIP downloads, but they often use directory names like
		 * "project-branch" or "project-tag-hash". We need to change the name to the actual plugin folder.
		 *
		 * This is a hook callback. Don't call it from a plugin.
		 *
		 * @access protected
		 *
		 * @param string $source The directory to copy to /wp-content/plugins or /wp-content/themes. Usually a subdirectory of $remoteSource.
		 * @param string $remoteSource WordPress has extracted the update to this directory.
		 * @param WP_Upgrader $upgrader
		 * @return string|WP_Error
		 */
		public function fixDirectoryName($source, $remoteSource, $upgrader) {
			global $wp_filesystem;
			/** @var WP_Filesystem_Base $wp_filesystem */

			//Basic sanity checks.
			if ( !isset($source, $remoteSource, $upgrader, $upgrader->skin, $wp_filesystem) ) {
				return $source;
			}

			//If WordPress is upgrading anything other than our plugin/theme, leave the directory name unchanged.
			if ( !$this->isBeingUpgraded($upgrader) ) {
				return $source;
			}

			//Rename the source to match the existing directory.
			if ( $this->directoryName === '.' ) {
				return $source;
			}
			$correctedSource = trailingslashit($remoteSource) . $this->directoryName . '/';
			if ( $source !== $correctedSource ) {
				//The update archive should contain a single directory that contains the rest of plugin/theme files.
				//Otherwise, WordPress will try to copy the entire working directory ($source == $remoteSource).
				//We can't rename $remoteSource because that would break WordPress code that cleans up temporary files
				//after update.
				if ($this->isBadDirectoryStructure($remoteSource)) {
					return new WP_Error(
						'puc-incorrect-directory-structure',
						sprintf(
							'The directory structure of the update is incorrect. All files should be inside ' .
							'a directory named <span class="code">%s</span>, not at the root of the ZIP archive.',
							htmlentities($this->slug)
						)
					);
				}

				/** @var WP_Upgrader_Skin $upgrader ->skin */
				$upgrader->skin->feedback(sprintf(
					'Renaming %s to %s&#8230;',
					'<span class="code">' . basename($source) . '</span>',
					'<span class="code">' . $this->directoryName . '</span>'
				));

				if ($wp_filesystem->move($source, $correctedSource, true)) {
					$upgrader->skin->feedback('Directory successfully renamed.');
					return $correctedSource;
				} else {
					return new WP_Error(
						'puc-rename-failed',
						'Unable to rename the update to match the existing directory.'
					);
				}
			}

			return $source;
		}

		/**
		 * Is there an update being installed right now, for this plugin or theme?
		 *
		 * @param WP_Upgrader|null $upgrader The upgrader that's performing the current update.
		 * @return bool
		 */
		abstract public function isBeingUpgraded($upgrader = null);

		/**
		 * Check for incorrect update directory structure. An update must contain a single directory,
		 * all other files should be inside that directory.
		 *
		 * @param string $remoteSource Directory path.
		 * @return bool
		 */
		protected function isBadDirectoryStructure($remoteSource) {
			global $wp_filesystem;
			/** @var WP_Filesystem_Base $wp_filesystem */

			$sourceFiles = $wp_filesystem->dirlist($remoteSource);
			if ( is_array($sourceFiles) ) {
				$sourceFiles = array_keys($sourceFiles);
				$firstFilePath = trailingslashit($remoteSource) . $sourceFiles[0];
				return (count($sourceFiles) > 1) || (!$wp_filesystem->is_dir($firstFilePath));
			}

			//Assume it's fine.
			return false;
		}
	}

endif;