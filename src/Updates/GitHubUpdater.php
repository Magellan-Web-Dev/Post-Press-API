<?php
declare(strict_types=1);

namespace PostPressAPI\Updates;

if (!defined('ABSPATH')) exit;

/**
 * Connects this plugin to its GitHub releases for automatic update notifications.
 *
 * Hooks into WordPress's built-in update check pipeline so that any published
 * GitHub release triggers the standard "update available" banner in the Plugins
 * screen.  Also adds a "Check for updates" row action so admins can force a
 * fresh check without waiting for the next scheduled transient refresh.
 *
 * Repository configuration:
 *  The GitHub owner/repo lives in the REPOSITORY constant below and is empty by
 *  default.  While it is empty (or malformed) init() registers no hooks at all:
 *  no GitHub requests are made, no "Check for updates" link is shown, and no
 *  update-check notices are displayed.
 *
 * Release detection flow:
 *  1. WordPress fires pre_set_site_transient_update_plugins on a scheduled check.
 *  2. checkForUpdate() fetches (or returns a 12-hour cached copy of) the latest
 *     GitHub release via the REST API.
 *  3. If the remote version is newer than PPAPI_VERSION the plugin is injected into
 *     WordPress's $transient->response array, which triggers the standard update UI.
 *     Otherwise it is listed in $transient->no_update so the auto-update toggle works.
 *
 * Folder integrity:
 *  GitHub tag archives extract into a version-stamped folder (e.g. repo-1.2.3/).
 *  The upgrader_post_install filter moves that freshly installed folder to the
 *  canonical post-press-api/ name so the plugin keeps the basename WordPress
 *  uses to track activation.  If a post-press-api/ folder is unexpectedly still
 *  present it is moved aside to a backup first; if that or the final move fails,
 *  the existing installation is left in place (or restored from the backup) and
 *  the update is reported as failed.  Nothing outside this plugin's own folders
 *  is touched, and no folder is ever deleted as a fallback for a failed move.
 */
final class GitHubUpdater
{
    /**
     * GitHub "owner/repo" that publishes this plugin's releases.
     *
     * >>> CONFIGURE THE UPDATE SOURCE HERE <<<
     * This is the only place the repository is configured.  Enter it as
     * 'owner/repo' (for example 'example-org/post-press-api').  While this is
     * empty the updater stays completely inactive.
     *
     * @var string
     */
    private const REPOSITORY = 'Magellan-Web-Dev/Post-Press-API';

    /** @var string GitHub REST API endpoint template for the latest release; %s is the owner/repo. */
    private const API_URL = 'https://api.github.com/repos/%s/releases/latest';

    /** @var string Download URL template; the first %s is owner/repo, the second the URL-encoded tag name. */
    private const ZIP_URL = 'https://github.com/%s/archive/refs/tags/%s.zip';

    /** @var string Site transient key used to cache the latest release data. */
    private const CACHE_KEY = 'ppapi_github_release';

    /** @var int Cache lifetime in seconds (12 hours). */
    private const CACHE_TTL = 43200;

    /** @var string Slug used in the WordPress updates API and row actions. */
    private const PLUGIN_SLUG = 'post-press-api';

    /** @var string Main plugin file name (relative to the plugin folder). */
    private const PLUGIN_FILE = 'post-press-api.php';

    /** @var string The folder name this plugin must always occupy under wp-content/plugins/. */
    private const DESIRED_FOLDER = 'post-press-api';

    /** @var string Admin action and nonce action for the manual "Check for updates" link. */
    private const CHECK_ACTION = 'ppapi_check_for_updates';

    /** @var string Query argument carrying the nonce for the manual check. */
    private const NONCE_ARG = 'ppapi_nonce';

    /** @var string Query flag added after a manual check so the result notice can be shown. */
    private const CHECKED_FLAG = 'ppapi_update_checked';

    /**
     * Registers all hooks needed for GitHub-based update checking.
     *
     * Called once from {@see \PostPressAPI\Plugin::boot()}.  Does nothing until the
     * repository is configured.
     *
     * @return void
     */
    public static function init(): void
    {
        if (!self::isConfigured()) {
            return;
        }

        add_filter('pre_set_site_transient_update_plugins', [self::class, 'checkForUpdate']);
        add_filter('plugins_api',                           [self::class, 'pluginsApi'], 10, 3);

        // Runs after WordPress has installed the package and before the update
        // screen or automatic updater reactivates the plugin by its basename.
        add_filter('upgrader_post_install', [self::class, 'normalizeFolderAfterInstall'], 10, 3);

        if (is_admin()) {
            add_filter('plugin_action_links_' . self::pluginBasename(), [self::class, 'addActionLinks']);
            add_action('admin_init',    [self::class, 'handleCheckForUpdates']);
            add_action('admin_notices', [self::class, 'maybeShowCheckedNotice']);
        }
    }

    /**
     * Whether a syntactically valid owner/repo has been configured.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return self::repository() !== '';
    }

    /**
     * Injects an update entry into the WordPress update transient when a newer
     * GitHub release is available.
     *
     * @param false|object|array $transient The current value of the update_plugins transient.
     *
     * @return false|object|array
     */
    public static function checkForUpdate(mixed $transient): mixed
    {
        if (!self::isConfigured() || !is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $release = self::getLatestRelease();
        if (!$release) {
            return $transient;
        }

        $plugin_basename = self::pluginBasename();
        $item            = self::buildUpdateItem($release, self::currentVersion());

        if (version_compare($release['version'], self::currentVersion(), '>')) {
            $transient->response[$plugin_basename] = $item;
            if (isset($transient->no_update) && is_array($transient->no_update)) {
                unset($transient->no_update[$plugin_basename]);
            }
        } else {
            $transient->no_update[$plugin_basename] = $item;
        }

        return $transient;
    }

    /**
     * Builds the update-transient entry for a release.
     *
     * Separated from checkForUpdate() so the version comparison and package URL
     * handling can be tested without network access.
     *
     * @param array{tag: string, version: string, zip_url: string, html_url: string} $release
     * @param string                                                                $current Installed version.
     *
     * @return object
     */
    public static function buildUpdateItem(array $release, string $current): object
    {
        $newer = version_compare($release['version'], $current, '>');

        return (object) [
            'id'           => self::PLUGIN_SLUG,
            'slug'         => self::PLUGIN_SLUG,
            'plugin'       => self::pluginBasename(),
            'new_version'  => $newer ? $release['version'] : $current,
            'url'          => $release['html_url'],
            'package'      => $newer ? $release['zip_url'] : '',
            'requires'     => '6.0',
            'requires_php' => '8.1',
        ];
    }

    /**
     * Supplies plugin metadata for the "View version X details" thickbox popup
     * that appears on the Plugins and Updates screens.
     *
     * @param false|object|array $result The result so far (passed through when not handling).
     * @param string             $action The type of information being requested.
     * @param mixed              $args   Additional arguments, including the requested slug.
     *
     * @return false|object|array
     */
    public static function pluginsApi(mixed $result, string $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information' || !is_object($args) || empty($args->slug) || $args->slug !== self::PLUGIN_SLUG) {
            return $result;
        }

        $release = self::getLatestRelease();
        if (!$release) {
            return $result;
        }

        return (object) [
            'name'          => 'Post Press API',
            'slug'          => self::PLUGIN_SLUG,
            'version'       => $release['version'],
            'author'        => 'Chris Paschall',
            'homepage'      => $release['html_url'],
            'download_link' => $release['zip_url'],
            'requires'      => '6.0',
            'requires_php'  => '8.1',
            'sections'      => [
                'description' => 'Fetch JSON records from external APIs manually or on a schedule and map them into posts, custom post types, post meta, and Advanced Custom Fields.',
            ],
            'banners' => [],
        ];
    }

    /**
     * Adds a "Check for updates" link to the plugin's row on the Plugins screen.
     *
     * @param array<int|string, string> $links Existing action links for this plugin row.
     *
     * @return array<int|string, string>
     */
    public static function addActionLinks(array $links): array
    {
        if (!current_user_can('update_plugins')) {
            return $links;
        }

        $check_url = wp_nonce_url(
            add_query_arg('action', self::CHECK_ACTION, self_admin_url('plugins.php')),
            self::CHECK_ACTION,
            self::NONCE_ARG
        );

        $links[] = '<a href="' . esc_url($check_url) . '">' . esc_html__('Check for updates', 'post-press-api') . '</a>';

        return $links;
    }

    /**
     * Handles the "Check for updates" admin action triggered from the Plugins screen.
     *
     * Clears the cached release data and the WordPress update_plugins transient,
     * re-runs the update check, then redirects back to the Plugins screen with a
     * query flag so maybeShowCheckedNotice() can display the result.
     *
     * @return void
     */
    public static function handleCheckForUpdates(): void
    {
        if (
            !self::isConfigured() ||
            empty($_GET['action']) ||
            $_GET['action'] !== self::CHECK_ACTION ||
            empty($_GET[self::NONCE_ARG]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET[self::NONCE_ARG])), self::CHECK_ACTION) ||
            !current_user_can('update_plugins')
        ) {
            return;
        }

        // Clear cached release so the API is hit fresh.
        delete_site_transient(self::CACHE_KEY);

        // Force WordPress to re-evaluate all plugin updates immediately.
        delete_site_transient('update_plugins');
        wp_update_plugins();

        wp_safe_redirect(self_admin_url('plugins.php?' . self::CHECKED_FLAG . '=1'));
        exit;
    }

    /**
     * Displays an admin notice after a manual "Check for updates" action completes.
     *
     * Shows whether a newer version is available, confirms the plugin is up to date,
     * or warns when the GitHub release check could not be completed.
     *
     * @return void
     */
    public static function maybeShowCheckedNotice(): void
    {
        if (
            !self::isConfigured() ||
            empty($_GET[self::CHECKED_FLAG]) ||
            $_GET[self::CHECKED_FLAG] !== '1' ||
            !current_user_can('update_plugins')
        ) {
            return;
        }

        $release = self::getLatestRelease();
        $current = self::currentVersion();

        if ($release && version_compare($release['version'], $current, '>')) {
            $msg = sprintf(
                /* translators: 1: available version, 2: Updates screen URL. */
                __('Post Press API: Version <strong>%1$s</strong> is available. <a href="%2$s">Go to Updates</a>.', 'post-press-api'),
                esc_html($release['version']),
                esc_url(self_admin_url('update-core.php'))
            );
        } elseif ($release) {
            $msg = esc_html__('Post Press API: You are running the latest version.', 'post-press-api');
        } else {
            $msg = esc_html__('Post Press API: Could not check for updates. The GitHub repository or release API may be unavailable.', 'post-press-api');
        }

        $notice_class = $release ? 'notice-success' : 'notice-warning';

        echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p>' . wp_kses_post($msg) . '</p></div>';
    }

    /**
     * Fetches the latest release from GitHub, caching the result for 12 hours to
     * avoid hitting the API rate limit on every page load.
     *
     * Returns null without making a request while the repository is unconfigured.
     *
     * @return array{tag: string, version: string, zip_url: string, html_url: string}|null
     *         Null when unconfigured, when the API request fails, or when the response is malformed.
     */
    private static function getLatestRelease(): ?array
    {
        $repository = self::repository();
        if ($repository === '') {
            return null;
        }

        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['version'], $cached['zip_url'])) {
            return $cached;
        }

        $response = wp_remote_get(sprintf(self::API_URL, $repository), [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/Post-Press-API',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['tag_name']) || !is_string($data['tag_name'])) {
            return null;
        }

        $tag     = $data['tag_name'];
        $version = ltrim($tag, 'vV');

        // Ignore tags that are not version numbers so version_compare() stays meaningful.
        if (!preg_match('/^\d+(\.\d+)*([.-][0-9A-Za-z.-]+)?$/', $version)) {
            return null;
        }

        $release = [
            'tag'      => $tag,
            'version'  => $version,
            'zip_url'  => sprintf(self::ZIP_URL, $repository, rawurlencode($tag)),
            'html_url' => isset($data['html_url']) && is_string($data['html_url']) ? $data['html_url'] : '',
        ];

        set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);

        return $release;
    }

    /**
     * Returns the configured owner/repo, or an empty string when it is blank or malformed.
     *
     * @return string
     */
    private static function repository(): string
    {
        $repository = trim(self::REPOSITORY, " \t\n\r\0\x0B/");

        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) === 1 ? $repository : '';
    }

    /**
     * Returns the installed plugin version.
     *
     * @return string
     */
    private static function currentVersion(): string
    {
        return defined('PPAPI_VERSION') ? (string) PPAPI_VERSION : '0.0.0';
    }

    /**
     * Returns the plugin basename WordPress uses for update matching.
     *
     * Example: post-press-api/post-press-api.php
     *
     * @return string
     */
    private static function pluginBasename(): string
    {
        return self::DESIRED_FOLDER . '/' . self::PLUGIN_FILE;
    }

    /**
     * Moves the freshly installed plugin directory to the canonical folder name
     * after an update.
     *
     * Only acts on updates of this plugin.  The directory WordPress just installed
     * must be a direct child of the plugins directory and contain this plugin's
     * main file.  If the canonical folder already exists it is first moved aside
     * to a uniquely named backup inside wp-content/upgrade/.  On any failure the
     * existing installation is kept (or restored from that backup) and a WP_Error
     * is returned so WordPress reports the update as failed; no directory is
     * deleted to make room.  The backup is removed only after the new version is
     * in place.
     *
     * @param mixed $response   Pass-through value; a WP_Error here means the install
     *                          already failed — it is returned unchanged.
     * @param mixed $hook_extra Hook extra data (type, action, plugin basename).
     * @param mixed $result     Result array from install_package(), including
     *                          'destination' / 'remote_destination' — where WordPress installed the files.
     *
     * @return mixed
     */
    public static function normalizeFolderAfterInstall(mixed $response, mixed $hook_extra, mixed $result): mixed
    {
        // Only handle our plugin.
        if (!is_array($hook_extra) || ($hook_extra['plugin'] ?? '') !== self::pluginBasename()) {
            return $response;
        }

        // Don't touch a failed installation.
        if (is_wp_error($response)) {
            return $response;
        }

        if (!is_array($result) || (empty($result['remote_destination']) && empty($result['destination']))) {
            return $response;
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if (!$wp_filesystem) {
            return new \WP_Error(
                'ppapi_update_filesystem_unavailable',
                __('Post Press API could not access the filesystem to move the update into its plugin folder.', 'post-press-api')
            );
        }

        $plugins_dir   = untrailingslashit(wp_normalize_path((string) $wp_filesystem->wp_plugins_dir()));
        $desired_dir   = $plugins_dir . '/' . self::DESIRED_FOLDER;
        $installed_dir = untrailingslashit(wp_normalize_path((string) ($result['remote_destination'] ?? $result['destination'])));

        // Already in the right place — nothing to do.
        if ($installed_dir === $desired_dir) {
            return $response;
        }

        // Only ever move a directory WordPress placed directly inside the plugins directory.
        if ($installed_dir === '' || dirname($installed_dir) !== $plugins_dir) {
            return new \WP_Error(
                'ppapi_update_unexpected_location',
                __('Post Press API was installed to an unexpected location, so its folder was not changed.', 'post-press-api')
            );
        }

        // Confirm our plugin file is actually present in the installed directory.
        if (!$wp_filesystem->exists($installed_dir . '/' . self::PLUGIN_FILE)) {
            return new \WP_Error(
                'ppapi_update_missing_main_file',
                __('The downloaded Post Press API package does not contain post-press-api.php, so it was not moved into place.', 'post-press-api')
            );
        }

        // If a correctly named directory still exists, move it aside so it can be
        // restored if the final move fails.  Never delete it as a fallback.
        $backup_dir = null;
        if ($wp_filesystem->exists($desired_dir)) {
            $backup_dir = self::backupPath($wp_filesystem);
            if ($backup_dir === null || !$wp_filesystem->move($desired_dir, $backup_dir)) {
                return new \WP_Error(
                    'ppapi_update_backup_failed',
                    sprintf(
                        /* translators: 1: canonical folder name, 2: folder holding the downloaded update. */
                        __('Post Press API could not move the existing %1$s folder aside, so the update was not moved into place. The existing installation was left unchanged; the downloaded files remain in %2$s.', 'post-press-api'),
                        self::DESIRED_FOLDER,
                        basename($installed_dir)
                    )
                );
            }
        }

        // Move to the canonical folder name.
        if (!$wp_filesystem->move($installed_dir, $desired_dir)) {
            if ($backup_dir !== null && !$wp_filesystem->move($backup_dir, $desired_dir)) {
                return new \WP_Error(
                    'ppapi_update_restore_failed',
                    sprintf(
                        /* translators: %s: backup folder path. */
                        __('Post Press API could not be moved into its plugin folder, and the previous version could not be restored automatically. It is preserved at %s.', 'post-press-api'),
                        $backup_dir
                    )
                );
            }

            return new \WP_Error(
                'ppapi_update_move_failed',
                sprintf(
                    /* translators: 1: folder holding the downloaded update, 2: canonical folder name. */
                    __('Post Press API could not move the update from %1$s to %2$s. The previous installation was kept.', 'post-press-api'),
                    basename($installed_dir),
                    self::DESIRED_FOLDER
                )
            );
        }

        // The new version is in place — remove the backup of the previous copy of this plugin.
        if ($backup_dir !== null && $wp_filesystem->exists($backup_dir)) {
            $wp_filesystem->delete($backup_dir, true);
        }

        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        }

        return $response;
    }

    /**
     * Returns a unique backup location for the canonical folder inside wp-content/upgrade/.
     *
     * Backups live outside the plugins directory so WordPress never lists them as a
     * second copy of the plugin.
     *
     * @param object $filesystem WP_Filesystem_Base instance.
     *
     * @return string|null Null when the upgrade directory cannot be created.
     */
    private static function backupPath(object $filesystem): ?string
    {
        $upgrade_dir = untrailingslashit(wp_normalize_path((string) $filesystem->wp_content_dir())) . '/upgrade';

        if (!$filesystem->is_dir($upgrade_dir) && !$filesystem->mkdir($upgrade_dir)) {
            return null;
        }

        do {
            $candidate = $upgrade_dir . '/' . self::DESIRED_FOLDER . '-previous-' . gmdate('YmdHis') . '-' . strtolower(wp_generate_password(6, false, false));
        } while ($filesystem->exists($candidate));

        return $candidate;
    }
}
