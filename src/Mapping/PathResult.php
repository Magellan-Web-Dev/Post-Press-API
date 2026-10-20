<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * Outcome of resolving a path against JSON data.
 *
 * Keeps "the key does not exist" separate from every present value, including
 * null, false, 0, an empty string, and an empty array. Callers must use
 * {@see is_missing()} / {@see is_null()} rather than empty() to decide whether
 * mapped data exists.
 */
final class PathResult
{
    public const MISSING = 'missing';
    public const NULL    = 'null';
    public const PRESENT = 'present';

    /**
     * @param string      $state    One of the state constants.
     * @param mixed       $value    The resolved value (null when missing).
     * @param bool        $multiple True when the path contained a wildcard and $value is a list of matches.
     * @param string|null $note     Human-readable detail for missing/null results (e.g. which segment failed).
     */
    private function __construct(
        public readonly string $state,
        public readonly mixed $value,
        public readonly bool $multiple = false,
        public readonly ?string $note = null
    ) {}

    public static function missing(?string $note = null): self
    {
        return new self(self::MISSING, null, false, $note);
    }

    public static function null(?string $note = null): self
    {
        return new self(self::NULL, null, false, $note);
    }

    public static function present(mixed $value, bool $multiple = false): self
    {
        if ($value === null) {
            return self::null();
        }

        return new self(self::PRESENT, $value, $multiple);
    }

    public function is_missing(): bool
    {
        return $this->state === self::MISSING;
    }

    public function is_null(): bool
    {
        return $this->state === self::NULL;
    }

    public function is_present(): bool
    {
        return $this->state === self::PRESENT;
    }
}
