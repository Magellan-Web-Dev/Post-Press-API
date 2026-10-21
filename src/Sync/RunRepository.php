<?php

declare(strict_types=1);

namespace PostPressAPI\Sync;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Presets\PresetRepository;

/**
 * Persists run progress and bounded run history (all non-autoloaded options).
 *
 *   ppapi_run_{id}        active run state, including the preset snapshot
 *   ppapi_runbuf_{id}     records of the page currently being processed
 *   ppapi_runcancel_{id}  cancellation request for a step running elsewhere
 *   ppapi_history_{id}    the last 25 finished runs
 */
final class RunRepository
{
    public const STATE_PREFIX   = 'ppapi_run_';
    public const BUFFER_PREFIX  = 'ppapi_runbuf_';
    public const CANCEL_PREFIX  = 'ppapi_runcancel_';
    public const HISTORY_PREFIX = 'ppapi_history_';

    /** @var int Runs kept per preset. */
    public const HISTORY_LIMIT = 25;

    /**
     * Active (or last unfinished) run state.
     *
     * @param string $preset_id Preset ID.
     *
     * @return array<string, mixed>|null
     */
    public function active(string $preset_id): ?array
    {
        if (!PresetRepository::is_valid_id($preset_id)) {
            return null;
        }

        // Another process may have advanced the run; never trust a cached copy.
        wp_cache_delete(self::STATE_PREFIX . $preset_id, 'options');
        $state = get_option(self::STATE_PREFIX . $preset_id, null);

        return is_array($state) ? $state : null;
    }

    /**
     * @param array<string, mixed> $state Run state.
     */
    public function save_active(array $state): void
    {
        update_option(self::STATE_PREFIX . $state['preset_id'], $state, false);
    }

    public function clear_active(string $preset_id): void
    {
        delete_option(self::STATE_PREFIX . $preset_id);
        delete_option(self::BUFFER_PREFIX . $preset_id);
    }

    /**
     * Records of the page being processed.
     *
     * @param string $preset_id Preset ID.
     *
     * @return list<mixed>
     */
    public function buffer(string $preset_id): array
    {
        wp_cache_delete(self::BUFFER_PREFIX . $preset_id, 'options');
        $buffer = get_option(self::BUFFER_PREFIX . $preset_id, []);

        return is_array($buffer) ? array_values($buffer) : [];
    }

    /**
     * @param string      $preset_id Preset ID.
     * @param list<mixed> $records   Records.
     */
    public function save_buffer(string $preset_id, array $records): void
    {
        update_option(self::BUFFER_PREFIX . $preset_id, $records, false);
    }

    /**
     * Asks a step running in another request to stop after its current record.
     *
     * @param string $preset_id Preset ID.
     * @param string $reason    Message recorded in history.
     */
    public function request_cancel(string $preset_id, string $reason): void
    {
        update_option(self::CANCEL_PREFIX . $preset_id, $reason === '' ? 'cancelled' : $reason, false);
    }

    /**
     * Pending cancellation reason, or null.
     *
     * @param string $preset_id Preset ID.
     *
     * @return string|null
     */
    public function cancel_reason(string $preset_id): ?string
    {
        wp_cache_delete(self::CANCEL_PREFIX . $preset_id, 'options');
        $reason = get_option(self::CANCEL_PREFIX . $preset_id, null);

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    public function clear_cancel(string $preset_id): void
    {
        delete_option(self::CANCEL_PREFIX . $preset_id);
    }

    /**
     * Finished runs, newest first.
     *
     * @param string $preset_id Preset ID.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $preset_id): array
    {
        if (!PresetRepository::is_valid_id($preset_id)) {
            return [];
        }

        wp_cache_delete(self::HISTORY_PREFIX . $preset_id, 'options');
        $history = get_option(self::HISTORY_PREFIX . $preset_id, []);

        return is_array($history) ? array_values(array_filter($history, 'is_array')) : [];
    }

    /**
     * Most recent finished run.
     *
     * @param string $preset_id Preset ID.
     *
     * @return array<string, mixed>|null
     */
    public function last(string $preset_id): ?array
    {
        return $this->history($preset_id)[0] ?? null;
    }

    /**
     * Prepends a finished run, keeping at most HISTORY_LIMIT entries.
     *
     * @param string               $preset_id Preset ID.
     * @param array<string, mixed> $entry     Run summary.
     */
    public function add_history(string $preset_id, array $entry): void
    {
        if (!PresetRepository::is_valid_id($preset_id)) {
            throw new \InvalidArgumentException('Invalid preset ID.');
        }
        \PostPressAPI\Support\AtomicOption::update(self::HISTORY_PREFIX . $preset_id, static function (array $history) use ($entry): array {
            // A retried finish must not duplicate the same run.
            $history = array_values(array_filter($history, static fn ($run): bool => is_array($run) && ($run['run_id'] ?? '') !== ($entry['run_id'] ?? null)));
            array_unshift($history, $entry);
            return array_slice($history, 0, self::HISTORY_LIMIT);
        });
    }

    /**
     * Deletes identified completed summaries without touching active run state.
     *
     * @param string       $preset_id Preset ID.
     * @param list<string> $run_ids   Selected completed run IDs.
     *
     * @return int Number of summaries removed.
     *
     * @throws \InvalidArgumentException When the preset ID is malformed.
     * @throws \RuntimeException When a concurrent update cannot be resolved.
     */
    public function delete_history(string $preset_id, array $run_ids): int
    {
        if (!PresetRepository::is_valid_id($preset_id)) {
            throw new \InvalidArgumentException('Invalid preset ID.');
        }
        $deleted = 0;
        \PostPressAPI\Support\AtomicOption::update(self::HISTORY_PREFIX . $preset_id, static function (array $history) use ($run_ids, &$deleted): array {
            $kept = array_values(array_filter($history, static fn ($run): bool => !is_array($run) || !in_array($run['run_id'] ?? '', $run_ids, true)));
            $deleted = count($history) - count($kept);
            return $kept;
        });
        return $deleted;
    }

    /**
     * Clears a snapshot of retained summaries, preserving later completions.
     *
     * @param string $preset_id Preset ID.
     *
     * @return int Number of summaries removed.
     *
     * @throws \RuntimeException When history cannot be updated.
     */
    public function clear_history(string $preset_id): int
    {
        // Snapshot IDs so a concurrent completion is not lost during CAS retries.
        return $this->delete_history($preset_id, array_column($this->history($preset_id), 'run_id'));
    }

    /**
     * Removes all run data for a preset.
     *
     * @param string $preset_id Preset ID.
     */
    public function delete_all(string $preset_id): void
    {
        $this->clear_active($preset_id);
        $this->clear_cancel($preset_id);
        delete_option(self::HISTORY_PREFIX . $preset_id);
    }
}
