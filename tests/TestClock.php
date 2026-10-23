<?php
/**
 * Test clock (loaded once the plugin autoloader is available).
 */

declare(strict_types=1);

namespace PostPressAPI\Tests;

/**
 * Controllable clock. microtime() advances slightly on every call so time
 * budgets behave like a real (fast) request.
 */
final class TestClock extends \PostPressAPI\Support\Clock
{
    private float $ticks = 0.0;

    public function __construct(public int $time)
    {
    }

    public function now(): int
    {
        return $this->time;
    }

    public function microtime(): float
    {
        $this->ticks += 0.001;
        return $this->time + $this->ticks;
    }

    public function advance(int $seconds): void
    {
        $this->time += $seconds;
    }
}
