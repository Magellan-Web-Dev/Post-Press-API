<?php

declare(strict_types=1);

namespace PostPressAPI\Logging;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Support\Clock;
use PostPressAPI\Support\Redactor;

/**
 * Bounded activity log (newest first, at most 200 entries, not autoloaded).
 *
 * Entries hold short messages and identifiers only. Messages and context pass
 * through {@see Redactor} before they are stored, handed to the ppapi_log hook,
 * or read back: credential-like keys are dropped, URLs lose their query strings,
 * every saved credential (from all presets) is masked, and strings are
 * truncated only after masking. Payloads are never logged.
 */
final class Logger
{
    public const OPTION = 'ppapi_log';

    /** @var int Entries kept. */
    public const LIMIT = 200;

    public const INFO    = 'info';
    public const WARNING = 'warning';
    public const ERROR   = 'error';

    /**
     * @param Clock                                 $clock   Clock.
     * @param (\Closure(): list<string|null>)|null $secrets Supplies every known credential value.
     */
    public function __construct(private readonly Clock $clock, private readonly ?\Closure $secrets = null)
    {
    }

    /**
     * Adds an entry.
     *
     * @param string               $level     info, warning, or error.
     * @param string               $message   Message.
     * @param array<string, mixed> $context   Identifiers (preset_id, run_id, counts, url…).
     * @param Redactor|null        $redactor  Redactor aware of secrets in play; known credentials are always added.
     *
     * @return void
     */
    public function log(string $level, string $message, array $context = [], ?Redactor $redactor = null): void
    {
        $redactor = $this->redactor($redactor);

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
     * Entries, newest first, masked again on read so entries written before a
     * credential was known (or by an older version) never display it.
     *
     * @return list<array{time: int, level: string, message: string, context: array<string, mixed>}>
     */
    public function entries(): array
    {
        $entries = get_option(self::OPTION, []);
        if (!is_array($entries)) {
            return [];
        }

        return $this->redactor(null)->data(array_values(array_filter($entries, 'is_array')));
    }

    public function clear(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * Redactor that also masks every known credential.
     *
     * @param Redactor|null $redactor Caller's redactor.
     *
     * @return Redactor
     */
    private function redactor(?Redactor $redactor): Redactor
    {
        $redactor = $redactor === null ? new Redactor() : clone $redactor;
        if ($this->secrets !== null) {
            foreach (($this->secrets)() as $secret) {
                $redactor->add_secret($secret);
            }
        }

        return $redactor;
    }
}
