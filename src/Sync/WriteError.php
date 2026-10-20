<?php

declare(strict_types=1);

namespace PostPressAPI\Sync;

if (!defined('ABSPATH')) exit;

/**
 * A write to the destination post did not take effect.
 *
 * The message is shown to administrators and stored in run history, so it must
 * never include secrets or payload contents beyond short identifiers.
 */
final class WriteError extends \RuntimeException
{
}
