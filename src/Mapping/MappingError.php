<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * A record-level problem converting or validating a mapped value.
 *
 * The message is shown to administrators, so it must never contain secrets
 * or full payloads.
 */
final class MappingError extends \RuntimeException
{
}
