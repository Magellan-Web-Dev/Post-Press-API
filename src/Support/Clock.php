<?php

declare(strict_types=1);

namespace PostPressAPI\Support;

if (!defined('ABSPATH')) exit;

/**
 * Source of the current time, replaceable in tests (stale locks, retry waits).
 */
class Clock
{
    /**
     * Current Unix timestamp.
     *
     * @return int
     */
    public function now(): int
    {
        return time();
    }

    /**
     * High-resolution seconds for measuring time budgets.
     *
     * @return float
     */
    public function microtime(): float
    {
        return microtime(true);
    }
}
