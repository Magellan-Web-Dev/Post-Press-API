<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * A validated place a mapping row writes to.
 */
final class Destination
{
    public const CORE = 'core';
    public const META = 'meta';
    public const ACF  = 'acf';

    /** @var list<string> Destination categories. */
    public const CATEGORIES = [self::CORE, self::META, self::ACF];

    /**
     * @param string                    $category  core, meta, or acf.
     * @param string                    $key       Core field name, meta key, or ACF field key.
     * @param string                    $label     Human-readable label.
     * @param bool                      $clearable Whether the "clear" behavior is allowed.
     * @param array<string, mixed>|null $acf_field The ACF field definition for ACF destinations.
     */
    public function __construct(
        public readonly string $category,
        public readonly string $key,
        public readonly string $label,
        public readonly bool $clearable,
        public readonly ?array $acf_field = null
    ) {}

    /**
     * Stable identifier used for uniqueness checks and fingerprints.
     *
     * @return string
     */
    public function id(): string
    {
        return $this->category . ':' . $this->key;
    }

    /**
     * ACF field type, or null for non-ACF destinations.
     *
     * @return string|null
     */
    public function acf_type(): ?string
    {
        return $this->acf_field === null ? null : (string) ($this->acf_field['type'] ?? '');
    }

    /**
     * Whether this is an ACF group or repeater that needs nested mappings.
     *
     * @return bool
     */
    public function is_container(): bool
    {
        return in_array($this->acf_type(), ['group', 'repeater'], true);
    }
}
