<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * Thrown when a path expression cannot be parsed.
 */
final class PathSyntaxError extends \InvalidArgumentException
{
}
