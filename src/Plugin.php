<?php

declare(strict_types=1);

namespace PostPressAPI;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Admin\AdminActions;
use PostPressAPI\Admin\AdminPage;
use PostPressAPI\REST\RestController;
use PostPressAPI\Updates\GitHubUpdater;

/**
 * Main plugin singleton.
 *
 * Composition root: owns the {@see Services} container and registers every
 * component's hooks. Keeps a single static instance so the plugin cannot be
 * initialised more than once per WordPress request.
 *
 * Boot sequence:
 *   plugins_loaded (priority 20)
 *     └─ Plugin::instance()
 *          ├─ GitHubUpdater::init()          – inactive until a repository is configured
 *          ├─ init → load_textdomain()
 *          ├─ Scheduler::register()          – cron schedules + ppapi_* cron handlers
 *          ├─ is_admin()?
 *          │    ├─ AdminPage::register()     – menu, screens, scoped assets
 *          │    └─ AdminActions::register()  – nonce/capability-checked form actions
 *          └─ rest_api_init → RestController – ppapi/v1 routes for the admin screens
 *
 * Nothing here reads post types or ACF fields: those are registered on init
 * (ACF loads its field groups on acf/init), so discovery happens lazily when
 * an admin screen, REST request, or cron event needs it.
 */
final class Plugin
{
    /**
     * The single instance of this class.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Shared services.
     *
     * Declared readonly so it cannot be replaced after construction.
     *
     * @var Services
     */
    private readonly Services $services;

    /**
     * Private constructor — use {@see instance()} to obtain the singleton.
     */
    private function __construct()
    {
        $this->services = new Services();
        $this->boot();
    }

    /**
     * Returns (and lazily creates) the singleton instance.
     *
     * @return self
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Shared services container.
     *
     * @return Services
     */
    public function services(): Services
    {
        return $this->services;
    }

    /**
     * Registers all hooks.
     *
     * @return void
     */
    private function boot(): void
    {
        GitHubUpdater::init();

        add_action('init', [$this, 'load_textdomain']);

        $this->services->scheduler()->register();

        if (is_admin()) {
            (new AdminPage($this->services))->register();
            (new AdminActions($this->services))->register();
        }

        add_action('rest_api_init', function (): void {
            (new RestController($this->services))->register_routes();
        });
    }

    /**
     * Loads translations from the plugin's languages/ folder.
     *
     * @return void
     */
    public function load_textdomain(): void
    {
        load_plugin_textdomain('post-press-api', false, dirname(plugin_basename(PPAPI_FILE)) . '/languages');
    }

    /**
     * Prevents cloning of the singleton instance.
     *
     * @return void
     */
    private function __clone() {}

    /**
     * Prevents unserialization of the singleton instance.
     *
     * @return void
     */
    public function __wakeup(): void
    {
        throw new \LogicException('Post Press API cannot be unserialized.');
    }
}
