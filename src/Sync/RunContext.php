<?php

declare(strict_types=1);

namespace PostPressAPI\Sync;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Mapping\DestinationCatalog;

/**
 * Everything record processing needs for one run (or dry run).
 */
final class RunContext
{
    /**
     * @param array<string, mixed> $preset       Preset snapshot used for the whole run.
     * @param DestinationCatalog   $catalog      Destination catalog for the post type.
     * @param \DateTimeZone        $site_tz      Site time zone.
     * @param string               $mapping_hash Hash of the mapping configuration.
     * @param string               $run_id       Run identifier ('' for dry runs).
     * @param bool                 $dry_run      When true nothing is written.
     * @param bool                 $force        Re-apply mapped values even when fingerprints match.
     */
    public function __construct(
        public readonly array $preset,
        public readonly DestinationCatalog $catalog,
        public readonly \DateTimeZone $site_tz,
        public readonly string $mapping_hash,
        public readonly string $run_id,
        public readonly bool $dry_run,
        public readonly bool $force = false
    ) {}
}
