<?php

declare(strict_types=1);

namespace PostPressAPI\Scheduling;

use PostPressAPI\Logging\Logger;
use PostPressAPI\Presets\PresetRepository;
use PostPressAPI\Presets\PresetSchema;
use PostPressAPI\Sync\ImportRunner;

if (!defined('ABSPATH')) exit;

/**
 * WP-Cron integration.
 *
 * Each enabled, scheduled preset has exactly one recurring ppapi_scheduled_run
 * event whose argument is the preset ID. Saving a preset re-syncs its event
 * (kept as-is when the recurrence did not change, otherwise replaced);
 * disabling or deleting a preset removes its scheduled and continuation events;
 * deactivation removes all plugin events; activation restores them.
 *
 * WP-Cron only runs when the site receives traffic. For dependable timing,
 * define DISABLE_WP_CRON and have the server call wp-cron.php (or
 * `wp cron event run --due-now`) every few minutes.
 */
final class Scheduler
{
    public const HOOK_RUN = 'ppapi_scheduled_run';

    /** @var float Seconds a cron-driven step may spend. */
    public const CRON_BUDGET = 20.0;

    public function __construct(
        private readonly PresetRepository $presets,
        private readonly ImportRunner $runner,
        private readonly ContinuationScheduler $continuations,
        private readonly Logger $logger
    ) {}

    /**
     * Registers cron schedules and event handlers.
     *
     * @return void
     */
    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'add_schedules']);
        add_action(self::HOOK_RUN, [$this, 'handle_scheduled_run'], 10, 1);
        add_action(ContinuationScheduler::HOOK, [$this, 'handle_continuation'], 10, 1);
    }

    /**
     * Adds the 5/15-minute recurrences and any custom intervals presets use.
     *
     * @param array<string, array{interval: int, display: string}> $schedules Schedules.
     *
     * @return array<string, array{interval: int, display: string}>
     */
    public function add_schedules(mixed $schedules): array
    {
        $schedules = is_array($schedules) ? $schedules : [];

        $schedules['ppapi_5min'] = [
            'interval' => 300,
            'display'  => __('Every 5 minutes (Post Press API)', 'post-press-api'),
        ];
        $schedules['ppapi_15min'] = [
            'interval' => 900,
            'display'  => __('Every 15 minutes (Post Press API)', 'post-press-api'),
        ];

        foreach ($this->presets->index() as $summary) {
            if ($summary['frequency'] !== PresetSchema::FREQ_CUSTOM) {
                continue;
            }
            $minutes = PresetSchema::bounded($summary['minutes'], 'custom_minutes');
            $schedules['ppapi_' . $minutes . 'min'] ??= [
                'interval' => $minutes * 60,
                'display'  => sprintf(
                    /* translators: %d: minutes. */
                    __('Every %d minutes (Post Press API)', 'post-press-api'),
                    $minutes
                ),
            ];
        }

        return $schedules;
    }

    /**
     * WP-Cron recurrence for a preset, or null for manual-only.
     *
     * @param array<string, mixed> $preset Preset.
     *
     * @return string|null
     */
    public static function recurrence(array $preset): ?string
    {
        return match ($preset['schedule']['frequency']) {
            PresetSchema::FREQ_5      => 'ppapi_5min',
            PresetSchema::FREQ_15     => 'ppapi_15min',
            PresetSchema::FREQ_HOURLY => 'hourly',
            PresetSchema::FREQ_TWICE  => 'twicedaily',
            PresetSchema::FREQ_DAILY  => 'daily',
            PresetSchema::FREQ_CUSTOM => 'ppapi_' . PresetSchema::bounded($preset['schedule']['minutes'], 'custom_minutes') . 'min',
            default                   => null,
        };
    }

    /**
     * Makes the preset's recurring event match its settings.
     *
     * @param array<string, mixed> $preset Saved preset.
     *
     * @return void
     */
    public function sync(array $preset): void
    {
        $id      = (string) $preset['id'];
        $args    = [$id];
        $desired = $preset['enabled'] ? self::recurrence($preset) : null;

        if ($desired === null) {
            wp_clear_scheduled_hook(self::HOOK_RUN, $args);
            return;
        }

        $current = wp_get_scheduled_event(self::HOOK_RUN, $args);
        if ($current && ($current->schedule ?? null) === $desired && $this->count_events(self::HOOK_RUN, $args) === 1) {
            return;
        }

        wp_clear_scheduled_hook(self::HOOK_RUN, $args);

        $schedules = wp_get_schedules();
        $interval  = (int) ($schedules[$desired]['interval'] ?? 0);
        if ($interval <= 0) {
            $this->logger->error(__('Could not schedule the preset: unknown recurrence.', 'post-press-api'), ['preset_id' => $id, 'recurrence' => $desired]);
            return;
        }

        $result = wp_schedule_event(time() + $interval, $desired, self::HOOK_RUN, $args, true);
        if (is_wp_error($result) || $result === false) {
            $this->logger->error(
                __('Could not schedule the preset.', 'post-press-api'),
                ['preset_id' => $id, 'error' => is_wp_error($result) ? $result->get_error_message() : '']
            );
        }
    }

    /**
     * Removes a preset's scheduled and continuation events.
     *
     * @param string $preset_id Preset ID.
     *
     * @return void
     */
    public function clear(string $preset_id): void
    {
        wp_clear_scheduled_hook(self::HOOK_RUN, [$preset_id]);
        $this->continuations->clear($preset_id);
    }

    /**
     * Removes every Post Press API cron event (deactivation).
     *
     * @return void
     */
    public function clear_all(): void
    {
        wp_unschedule_hook(self::HOOK_RUN);
        wp_unschedule_hook(ContinuationScheduler::HOOK);
    }

    /**
     * Re-creates events for all enabled, scheduled presets (activation).
     *
     * @return void
     */
    public function restore_all(): void
    {
        foreach ($this->presets->all() as $preset) {
            $this->sync($preset);
        }
    }

    /**
     * Next scheduled run time.
     *
     * @param string $preset_id Preset ID.
     *
     * @return int|null
     */
    public function next_run(string $preset_id): ?int
    {
        $next = wp_next_scheduled(self::HOOK_RUN, [$preset_id]);

        return $next === false ? null : (int) $next;
    }

    /**
     * Cron handler: starts a scheduled run and performs its first step.
     *
     * @param mixed $preset_id Preset ID from the event arguments.
     *
     * @return void
     */
    public function handle_scheduled_run(mixed $preset_id): void
    {
        if (!is_string($preset_id) || !PresetRepository::is_valid_id($preset_id)) {
            return;
        }

        $preset = $this->presets->find($preset_id);
        if ($preset === null || !$preset['enabled'] || self::recurrence($preset) === null) {
            // Orphaned or outdated event: remove it rather than run.
            wp_clear_scheduled_hook(self::HOOK_RUN, [$preset_id]);
            return;
        }

        $state = $this->runner->start($preset_id, ImportRunner::TRIGGER_SCHEDULE);
        if (is_wp_error($state)) {
            if ($state->get_error_code() === 'ppapi_running') {
                $this->logger->info(__('Scheduled run skipped: the previous run is still in progress.', 'post-press-api'), ['preset_id' => $preset_id]);
            } else {
                $this->runner->record_failed_start($preset_id, ImportRunner::TRIGGER_SCHEDULE, $state->get_error_message());
            }
            return;
        }

        $this->runner->step($preset_id, self::CRON_BUDGET);
    }

    /**
     * Cron handler: continues an unfinished run.
     *
     * @param mixed $preset_id Preset ID from the event arguments.
     *
     * @return void
     */
    public function handle_continuation(mixed $preset_id): void
    {
        if (!is_string($preset_id) || !PresetRepository::is_valid_id($preset_id)) {
            return;
        }

        $this->runner->step($preset_id, self::CRON_BUDGET);
    }

    /**
     * Counts queued events for a hook and argument list.
     *
     * @param string       $hook Hook.
     * @param list<string> $args Arguments.
     *
     * @return int
     */
    private function count_events(string $hook, array $args): int
    {
        $crons = function_exists('_get_cron_array') ? _get_cron_array() : [];
        $key   = md5(serialize($args));
        $count = 0;

        foreach ((array) $crons as $events) {
            if (isset($events[$hook][$key])) {
                $count++;
            }
        }

        return $count;
    }
}
