<?php

declare(strict_types=1);

namespace PostPressAPI\Sync;

use PostPressAPI\Mapping\MappedRecord;
use PostPressAPI\Mapping\MappingSchema;

if (!defined('ABSPATH')) exit;

/**
 * Deterministic content fingerprints.
 *
 * A record's fingerprint covers only the normalized values its mappings would
 * write (plus the mapping configuration). Unmapped API fields such as
 * updated_at therefore never trigger an update, while editing a mapping
 * re-applies it on the next run. Preset defaults used only when creating posts
 * (status, author) are not included.
 */
final class Fingerprint
{
    /** @var int Bumped if the fingerprint format changes. */
    private const VERSION = 1;

    /**
     * Hash of the mapping configuration that affects written values.
     *
     * @param array<string, mixed> $preset Preset.
     *
     * @return string
     */
    public static function mapping_hash(array $preset): string
    {
        return hash('sha256', self::encode([
            'post_type' => (string) $preset['destination']['post_type'],
            'mappings'  => MappingSchema::fingerprint_config((array) $preset['mappings']),
        ]));
    }

    /**
     * Fingerprint of a mapped record.
     *
     * @param MappedRecord $mapped       Mapped record.
     * @param string       $mapping_hash Mapping hash.
     *
     * @return string
     */
    public static function compute(MappedRecord $mapped, string $mapping_hash): string
    {
        return hash('sha256', self::encode([
            'v' => self::VERSION,
            'm' => $mapping_hash,
            'w' => $mapped->fingerprint_payload(),
        ]));
    }

    /**
     * Stable JSON encoding.
     *
     * @param mixed $value Value.
     *
     * @return string
     */
    private static function encode(mixed $value): string
    {
        return (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
