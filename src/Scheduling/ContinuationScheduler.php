<?php

declare(strict_types=1);

namespace PostPressAPI\Scheduling;

if (!defined('ABSPATH')) exit;

/**
 * Schedules the single WP-Cron event that resumes an unfinished run.
 *
 * Every active run keeps exactly one ppapi_continue_run event (args: [preset ID])
 * so it finishes even if the admin who started it closes the page.
 */
final class ContinuationScheduler
{
    public const HOOK = 'ppapi_continue_run';

    /**
     * Ensures one continuation event at (about) $timestamp.
     *
     * @param string $preset_id Preset ID.
     * @param int    $timestamp When to resume.
     *
     * @return void
     */
    public function schedule(string $preset_id, int $timestamp): void
    {
        $args = [$preset_id];
        $next = wp_next_scheduled(self::HOOK, $args);

        if ($next !== false && abs((int) $next - $timestamp) < 30) {
            return;
        }

        wp_clear_scheduled_hook(self::HOOK, $args);
        wp_schedule_single_event($timestamp, self::HOOK, $args);
    }

    /**
     * Removes a preset's continuation event.
     *
     * @param string $preset_id Preset ID.
     *
     * @return void
     */
    public function clear(string $preset_id): void
    {
        wp_clear_scheduled_hook(self::HOOK, [$preset_id]);
    }
}
