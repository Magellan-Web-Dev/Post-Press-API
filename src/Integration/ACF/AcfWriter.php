<?php

declare(strict_types=1);

namespace PostPressAPI\Integration\ACF;

use PostPressAPI\Sync\ValueComparator;
use PostPressAPI\Sync\WriteError;

if (!defined('ABSPATH')) exit;

/**
 * Writes ACF values through ACF's own API using field keys.
 *
 * update_field() is always called with the field key (never the name) so ACF
 * stores the "_name" → key reference correctly, including on brand-new posts
 * where no reference exists yet. Values are slashed because ACF persists them
 * through update_metadata(), which unslashes.
 *
 * A false return from update_field() is not treated as failure on its own: it
 * also means "unchanged". In that case — and always for groups and repeaters,
 * whose return value reflects only the row count or a placeholder — the value
 * is read back and compared.
 */
final class AcfWriter
{
    /**
     * Writes a value.
     *
     * @param int                  $post_id Post ID.
     * @param array<string, mixed> $field   ACF field definition (must include key and name).
     * @param mixed                $value   Normalized value.
     *
     * @return void
     *
     * @throws WriteError When ACF is unavailable or the stored value does not match.
     */
    public function write(int $post_id, array $field, mixed $value): void
    {
        $this->assert_available();

        $key       = (string) $field['key'];
        $container = in_array($field['type'] ?? '', AcfFields::CONTAINER_TYPES, true);
        $result    = update_field($key, wp_slash($value), $post_id);

        if (!$container && $result !== false && $result !== null) {
            return;
        }

        $stored = get_field($key, $post_id, false);
        if (!ValueComparator::equivalent($stored, $value)) {
            throw new WriteError(sprintf(
                /* translators: 1: field label, 2: field key. */
                __('ACF did not save the field "%1$s" (%2$s).', 'post-press-api'),
                (string) ($field['label'] ?? $field['name'] ?? $key),
                $key
            ));
        }
    }

    /**
     * Clears a field (and, for groups/repeaters, its sub fields).
     *
     * @param int                  $post_id Post ID.
     * @param array<string, mixed> $field   ACF field definition.
     *
     * @return void
     *
     * @throws WriteError When the field still holds a value afterwards.
     */
    public function clear(int $post_id, array $field): void
    {
        $this->assert_available();

        $key = (string) $field['key'];
        delete_field($key, $post_id);

        if (!ValueComparator::is_empty(get_field($key, $post_id, false))) {
            throw new WriteError(sprintf(
                /* translators: 1: field label, 2: field key. */
                __('ACF did not clear the field "%1$s" (%2$s).', 'post-press-api'),
                (string) ($field['label'] ?? $field['name'] ?? $key),
                $key
            ));
        }
    }

    /**
     * Ensures ACF is still loaded.
     *
     * @return void
     */
    private function assert_available(): void
    {
        if (!function_exists('update_field') || !function_exists('get_field') || !function_exists('delete_field')) {
            throw new WriteError(__('Advanced Custom Fields is not active, so ACF fields cannot be written.', 'post-press-api'));
        }
    }
}
