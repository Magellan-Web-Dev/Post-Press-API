<?php

declare(strict_types=1);

namespace PostPressAPI\Admin;

use PostPressAPI\Presets\PresetSchema;
use PostPressAPI\Sync\ImportRunner;

if (!defined('ABSPATH')) exit;

/**
 * Display helpers for admin screens. All times use the site time zone.
 */
final class Format
{
    /**
     * Formats a timestamp in the site time zone.
     *
     * @param int $timestamp Unix time (0 = never).
     *
     * @return string
     */
    public static function datetime(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '—';
        }

        return (string) wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), $timestamp);
    }

    /**
     * Human label for a schedule.
     *
     * @param array<string, mixed> $preset Preset.
     *
     * @return string
     */
    public static function schedule(array $preset): string
    {
        $labels = self::frequencies();
        $label  = $labels[$preset['schedule']['frequency']] ?? $labels[PresetSchema::FREQ_MANUAL];

        if ($preset['schedule']['frequency'] === PresetSchema::FREQ_CUSTOM) {
            $label = sprintf(
                /* translators: %d: minutes. */
                __('Every %d minutes', 'post-press-api'),
                (int) $preset['schedule']['minutes']
            );
        }

        return $label;
    }

    /**
     * Schedule choices.
     *
     * @return array<string, string>
     */
    public static function frequencies(): array
    {
        return [
            PresetSchema::FREQ_MANUAL => __('Manual only', 'post-press-api'),
            PresetSchema::FREQ_5      => __('Every 5 minutes', 'post-press-api'),
            PresetSchema::FREQ_15     => __('Every 15 minutes', 'post-press-api'),
            PresetSchema::FREQ_HOURLY => __('Hourly', 'post-press-api'),
            PresetSchema::FREQ_TWICE  => __('Twice daily', 'post-press-api'),
            PresetSchema::FREQ_DAILY  => __('Daily', 'post-press-api'),
            PresetSchema::FREQ_CUSTOM => __('Custom interval', 'post-press-api'),
        ];
    }

    /**
     * Human label for a run status.
     *
     * @param string $status Status.
     *
     * @return string
     */
    public static function status(string $status): string
    {
        return match ($status) {
            ImportRunner::STATUS_RUNNING          => __('Running', 'post-press-api'),
            ImportRunner::STATUS_WAITING          => __('Waiting to retry', 'post-press-api'),
            ImportRunner::STATUS_COMPLETED        => __('Completed', 'post-press-api'),
            ImportRunner::STATUS_COMPLETED_ERRORS => __('Completed with errors', 'post-press-api'),
            ImportRunner::STATUS_TRUNCATED        => __('Stopped early (more records remain)', 'post-press-api'),
            ImportRunner::STATUS_FAILED           => __('Failed', 'post-press-api'),
            ImportRunner::STATUS_CANCELLED        => __('Cancelled', 'post-press-api'),
            default                               => $status,
        };
    }

    /**
     * CSS modifier for a run status.
     *
     * @param string $status Status.
     *
     * @return string
     */
    public static function status_class(string $status): string
    {
        return match ($status) {
            ImportRunner::STATUS_COMPLETED                                    => 'ppapi-status--success',
            ImportRunner::STATUS_FAILED                                       => 'ppapi-status--error',
            ImportRunner::STATUS_RUNNING, ImportRunner::STATUS_WAITING        => 'ppapi-status--info',
            default                                                           => 'ppapi-status--warning',
        };
    }

    /**
     * One-line count summary.
     *
     * @param array<string, int> $counts Counts.
     *
     * @return string
     */
    public static function counts(array $counts): string
    {
        return sprintf(
            /* translators: 1: created, 2: updated, 3: unchanged, 4: skipped, 5: failed. */
            __('%1$d created · %2$d updated · %3$d unchanged · %4$d skipped · %5$d failed', 'post-press-api'),
            (int) ($counts['created'] ?? 0),
            (int) ($counts['updated'] ?? 0),
            (int) ($counts['unchanged'] ?? 0),
            (int) ($counts['skipped'] ?? 0),
            (int) ($counts['failed'] ?? 0)
        );
    }
}
