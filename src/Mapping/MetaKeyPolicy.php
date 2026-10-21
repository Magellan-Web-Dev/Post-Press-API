<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Integration\ACF\AcfFields;

/**
 * Decides which custom meta keys a mapping may write.
 *
 * Blocks WordPress control keys, this plugin's own identity/locking/tracking
 * keys, and keys ACF manages (field values and their "_name" reference keys),
 * which must be written through the ACF integration instead.
 */
final class MetaKeyPolicy
{
    /** @var string Prefix of this plugin's internal post meta. */
    public const INTERNAL_PREFIX = '_ppapi_';

    /** @var list<string> Exact WordPress control keys. */
    private const BLOCKED_KEYS = [
        '_edit_lock', '_edit_last', '_thumbnail_id', '_wp_page_template', '_wp_old_slug', '_wp_old_date',
        '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_trash_meta_comments_status', '_wp_desired_post_slug',
        '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_encloseme', '_pingme',
        '_menu_item_type', '_menu_item_object', '_menu_item_object_id', '_menu_item_url', '_menu_item_menu_item_parent',
        'enclosure',
    ];

    /** @var list<string> Blocked prefixes. */
    private const BLOCKED_PREFIXES = ['_wp_', '_edit_', self::INTERNAL_PREFIX, '_oembed_', '_menu_item_', '_transient_', '_site_transient_'];

    public function __construct(private readonly AcfFields $acf)
    {
    }

    /**
     * Validates a meta key for a post type.
     *
     * @param string $key       Meta key.
     * @param string $post_type Destination post type.
     *
     * @return string|null Null when allowed, otherwise the reason it is blocked.
     */
    public function check(string $key, string $post_type): ?string
    {
        if ($key === '' || strlen($key) > 191 || !preg_match('/^[A-Za-z0-9_][A-Za-z0-9_\-.:]*$/', $key)) {
            return __('Meta keys must be 1–191 characters using letters, numbers, "_", "-", ".", or ":".', 'post-press-api');
        }

        $lower = strtolower($key);

        /**
         * Filters the meta keys mappings may never write.
         *
         * @param list<string> $blocked   Exact keys.
         * @param string       $post_type Destination post type.
         */
        $blocked = (array) apply_filters('ppapi_blocked_meta_keys', self::BLOCKED_KEYS, $post_type);
        if (in_array($lower, array_map('strtolower', array_filter($blocked, 'is_string')), true)) {
            return __('This is a WordPress control key and cannot be mapped.', 'post-press-api');
        }

        foreach (self::BLOCKED_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return $prefix === self::INTERNAL_PREFIX
                    ? __('Keys starting with _ppapi_ hold Post Press API identity and sync state and cannot be mapped.', 'post-press-api')
                    : __('This key belongs to WordPress internals and cannot be mapped.', 'post-press-api');
            }
        }

        $acf_reason = $this->acf_conflict($key, $post_type);
        if ($acf_reason !== null) {
            return $acf_reason;
        }

        return null;
    }

    /**
     * Detects keys that ACF manages for this post type.
     *
     * @param string $key       Meta key.
     * @param string $post_type Post type.
     *
     * @return string|null
     */
    private function acf_conflict(string $key, string $post_type): ?string
    {
        if (!$this->acf->available()) {
            return null;
        }

        $bare = ltrim($key, '_');

        foreach ($this->acf->top_level_fields($post_type) as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $is_container = in_array($field['type'] ?? '', ['group', 'repeater', 'flexible_content', 'clone'], true);

            if ($bare === $name || ($is_container && str_starts_with($bare, $name . '_'))) {
                return sprintf(
                    /* translators: 1: ACF field label, 2: ACF field key. */
                    __('This key is managed by the ACF field "%1$s" (%2$s). Map it as an ACF field instead.', 'post-press-api'),
                    (string) ($field['label'] ?? $name),
                    (string) ($field['key'] ?? '')
                );
            }
        }

        return null;
    }
}
