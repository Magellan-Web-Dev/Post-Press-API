<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * One normalized write to a destination.
 */
final class FieldWrite
{
    public const SET   = 'set';
    public const CLEAR = 'clear';

    /**
     * @param Destination $destination Where the value goes.
     * @param string      $op          set or clear.
     * @param mixed       $value       Normalized value for set; null for clear.
     */
    public function __construct(
        public readonly Destination $destination,
        public readonly string $op,
        public readonly mixed $value = null
    ) {}
}
