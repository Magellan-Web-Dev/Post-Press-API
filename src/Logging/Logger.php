<?php

declare(strict_types=1);

namespace PostPressAPI\Logging;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Support\Clock;
use PostPressAPI\Support\Redactor;

/**
 * Bounded activity log (newest first, at most 200 entries, not autoloaded).
 *
 * Entries hold short messages and identifiers only. Context passes through
 * {@see Redactor}: credential-like keys are dropped, URLs lose their query
 * strings, known secret values are masked, and strings are truncated. Payloads
 * are never logged.
 */
final class Logger
{
    public const OPTION = 'ppapi_log';

    /** @var int Entries kept. */
    public const LIMIT = 200;

    public const INFO    = 'info';
    public const WARNING = 'warning';
    public const ERROR   = 'error';

    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * Adds an entry.
     *
     * @param string               $level     info, warning, or error.
     * @param string               $message   Message.
     * @param array<string, mixed> $context   Identifiers (preset_id, run_id, counts, url…).
     * @param Redactor|null        $redactor  Redactor aware of secrets in play.
     *
     * @return void
     */
    public function log(string $level, string $message, array $context = [], ?Redactor $redactor = null): void
    {
        $redactor ??= new Redactor();

        $entries = $this->entries();
        array_unshift($entries, [
            'time'    => $this->clock->now(),
            'level'   => in_array($level, [self::INFO, self::WARNING, self::ERROR], true) ? $level : self::INFO,
            'message' => substr($redactor->text($message), 0, 500),
            'context' => $redactor->context($context),
        ]);

        update_option(self::OPTION, array_slice($entries, 0, self::LIMIT), false);

        /**
         * Fires after Post Press API logs an entry (already redacted).
         *
         * @param string               $level   Level.
         * @param string               $message Message.
         * @param array<string, mixed> $context Redacted context.
         */
        do_action('ppapi_log', $level, $entries[0]['message'], $entries[0]['context']);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * Entries, newest first.
     *
     * @return list<array{time: int, level: string, message: string, context: array<string, mixed>}>
     */
    public function entries(): array
    {
        $entries = get_option(self::OPTION, []);

        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }

    public function clear(): void
    {
        delete_option(self::OPTION);
    }
}
