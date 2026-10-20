<?php

declare(strict_types=1);

namespace PostPressAPI;

if (!defined('ABSPATH')) exit;

/**
 * Activation and deactivation handlers, registered from the guarded root file.
 *
 * Deactivation keeps presets, credentials, history, and every imported post;
 * it only stops scheduled work. Uninstalling (uninstall.php) removes plugin
 * settings but never deletes imported posts.
 */
final class Lifecycle
{
    /**
     * Restores the cron events of enabled, scheduled presets.
     *
     * Plugin::instance() registers the custom cron recurrences before events
     * are scheduled, which wp_schedule_event() requires.
     *
     * @return void
     */
    public static function activate(): void
    {
        Plugin::instance()->services()->scheduler()->restore_all();
    }

    /**
     * Cancels unfinished runs and removes all plugin cron events.
     *
     * @return void
     */
    public static function deactivate(): void
    {
        $services = Plugin::instance()->services();
        $services->runner()->cancel_all(__('Cancelled because Post Press API was deactivated.', 'post-press-api'));
        $services->scheduler()->clear_all();
    }

    /**
     * Private constructor — this class is used statically only.
     */
    private function __construct() {}
}
