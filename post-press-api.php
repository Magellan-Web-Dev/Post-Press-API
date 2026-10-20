<?php
/**
 * Plugin Name:       Post Press API
 * Description:       Fetch JSON records from external APIs manually or on a schedule and map them into posts, custom post types, post meta, and Advanced Custom Fields.
 * Version:           1.0.2
 * Author:            Chris Paschall
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       post-press-api
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.1
 *
 * Flow overview:
 *  1. Administrators create request presets under Post Press API (Admin\AdminPage). Each preset stores
 *     its request, response selection, field mappings, duplicate policy, pagination, and schedule.
 *  2. Test Request and Preview/Dry Run (REST\RestController → Sync\PreviewService) fetch a bounded sample
 *     or use pasted JSON and evaluate mappings without writing anything.
 *  3. Run Now and WP-Cron (Scheduling\Scheduler) both start a run through Sync\ImportRunner, which
 *     snapshots the preset, fetches pages, and processes records in bounded, resumable batches.
 *  4. Sync\RecordProcessor resolves each record's identity, evaluates mappings (Mapping\MappingEngine),
 *     compares fingerprints, and writes core fields, post meta, and ACF fields (Sync\PostWriter).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

/**
 * PHP version guard.
 *
 * This file must not use PHP 8.0+ syntax directly. PHP parses the entire file
 * before executing any branch, so newer syntax here would cause a fatal parse
 * error on older runtimes before this guard ever runs. PHP 8.1+ code is safely
 * isolated in the separately required files inside the else block below.
 */
if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        printf(
            '<strong>Post Press API</strong> requires PHP 8.1 or higher. '
            . 'Your server is running PHP %s. Please contact your host to upgrade PHP before activating this plugin.',
            esc_html(PHP_VERSION)
        );
        echo '</p></div>';
    });

/**
 * Main plugin bootstrap.
 * Defines the plugin constants, registers the autoloader and lifecycle hooks, and
 * instantiates the Plugin composition root once all plugins have loaded.
 */
} else {

    /** @var string Plugin version. */
    define('PPAPI_VERSION', '1.0.2');

    /** @var string Absolute path to the main plugin file. */
    define('PPAPI_FILE', __FILE__);

    /** @var string Absolute path to the plugin root directory (with trailing slash). */
    define('PPAPI_PATH', plugin_dir_path(__FILE__));

    /** @var string Public URL to the plugin root directory (with trailing slash). */
    define('PPAPI_URL', plugin_dir_url(__FILE__));

    // Require and register the class-based PSR-4 autoloader before anything else.
    require_once PPAPI_PATH . 'src/Autoloader.php';
    \PostPressAPI\Autoloader::register();

    // Activation restores schedules for enabled presets; deactivation clears plugin cron
    // events but keeps presets, history, and imported content.
    register_activation_hook(__FILE__, array('PostPressAPI\\Lifecycle', 'activate'));
    register_deactivation_hook(__FILE__, array('PostPressAPI\\Lifecycle', 'deactivate'));

    // Initialise after all plugins are loaded so optional integrations such as ACF are available.
    add_action('plugins_loaded', static function (): void {
        \PostPressAPI\Plugin::instance();
    }, 20);
}
