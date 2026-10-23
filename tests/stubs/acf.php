<?php
/**
 * In-memory Advanced Custom Fields API for tests.
 *
 * Storage mirrors ACF: a field's value lives in post meta under its name with a
 * "_name" reference to the field key; repeaters store the row count under the
 * repeater name and sub values under "{repeater}_{row}_{sub}"; groups store sub
 * values under "{group}_{sub}". Values pass through update_post_meta(), which
 * unslashes them, exactly as ACF's update_metadata() path does. Like ACF,
 * update_field() returns false when the stored value (or a repeater's row
 * count) did not change.
 */

declare(strict_types=1);

final class AcfStub
{
    /** @var list<array<string, mixed>> */
    public static array $groups = [];
    /** @var list<string> Registered field types (remove "repeater" to emulate ACF free). */
    public static array $types = [];
    /** @var list<array{0: string, 1: mixed, 2: int}> */
    public static array $update_calls = [];
    /** @var array<string, bool> Field keys whose writes silently fail. */
    public static array $fail_keys = [];

    public static function reset(): void
    {
        self::$groups       = [];
        self::$update_calls = [];
        self::$fail_keys    = [];
        self::$types        = [
            'text', 'textarea', 'wysiwyg', 'number', 'range', 'email', 'url', 'oembed', 'true_false', 'select', 'radio',
            'button_group', 'checkbox', 'date_picker', 'date_time_picker', 'time_picker', 'color_picker', 'link',
            'image', 'file', 'gallery', 'group', 'repeater', 'message', 'tab',
        ];
    }

    /** Registers a field group for post types. Fields may nest via sub_fields. */
    public static function add_group(string $key, string $title, array $post_types, array $fields): void
    {
        self::$groups[] = ['key' => $key, 'title' => $title, 'active' => true, 'post_types' => $post_types, 'fields' => self::prepare($fields, $key)];
    }

    private static function prepare(array $fields, string $parent): array
    {
        $out = [];
        foreach ($fields as $field) {
            $field += ['label' => $field['name'], 'parent' => $parent];
            if (isset($field['sub_fields'])) {
                $field['sub_fields'] = self::prepare($field['sub_fields'], $field['key']);
            }
            $out[] = $field;
        }
        return $out;
    }

    public static function find(string $key, ?array $fields = null): ?array
    {
        if ($fields === null) {
            foreach (self::$groups as $group) {
                $found = self::find($key, $group['fields']);
                if ($found !== null) {
                    return $found;
                }
            }
            return null;
        }
        foreach ($fields as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
            if (!empty($field['sub_fields'])) {
                $found = self::find($key, $field['sub_fields']);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    public static function store(array $field, mixed $value, int $post_id, string $name): mixed
    {
        if (!empty(self::$fail_keys[$field['key']])) {
            return false;
        }

        if ($field['type'] === 'repeater') {
            $rows      = is_array($value) ? array_values($value) : [];
            $old_count = (int) get_post_meta($post_id, $name, true);
            foreach ($rows as $index => $row) {
                foreach ($field['sub_fields'] as $sub) {
                    if (is_array($row) && array_key_exists($sub['key'], $row)) {
                        self::store($sub, $row[$sub['key']], $post_id, $name . '_' . $index . '_' . $sub['name']);
                    }
                }
            }
            for ($index = count($rows); $index < $old_count; $index++) {
                foreach ($field['sub_fields'] as $sub) {
                    self::remove($sub, $post_id, $name . '_' . $index . '_' . $sub['name']);
                }
            }
            update_post_meta($post_id, '_' . $name, $field['key']);
            return update_post_meta($post_id, $name, count($rows));
        }

        if ($field['type'] === 'group') {
            foreach ($field['sub_fields'] as $sub) {
                if (is_array($value) && isset($value[$sub['key']])) {
                    self::store($sub, $value[$sub['key']], $post_id, $name . '_' . $sub['name']);
                }
            }
            update_post_meta($post_id, '_' . $name, $field['key']);
            return update_post_meta($post_id, $name, '');
        }

        if ($value === null) {
            return self::remove($field, $post_id, $name);
        }

        $result = update_post_meta($post_id, $name, $value);
        update_post_meta($post_id, '_' . $name, $field['key']);
        return $result;
    }

    public static function load(array $field, int $post_id, string $name): mixed
    {
        if ($field['type'] === 'repeater') {
            $count = (int) get_post_meta($post_id, $name, true);
            if ($count === 0) {
                return false;
            }
            $rows = [];
            for ($index = 0; $index < $count; $index++) {
                $row = [];
                foreach ($field['sub_fields'] as $sub) {
                    $row[$sub['key']] = self::load($sub, $post_id, $name . '_' . $index . '_' . $sub['name']);
                }
                $rows[] = $row;
            }
            return $rows;
        }

        if ($field['type'] === 'group') {
            $values = [];
            foreach ($field['sub_fields'] as $sub) {
                $values[$sub['key']] = self::load($sub, $post_id, $name . '_' . $sub['name']);
            }
            return $values;
        }

        return metadata_exists('post', $post_id, $name) ? get_post_meta($post_id, $name, true) : null;
    }

    public static function remove(array $field, int $post_id, string $name): bool
    {
        if (in_array($field['type'], ['repeater', 'group'], true)) {
            $count = $field['type'] === 'repeater' ? (int) get_post_meta($post_id, $name, true) : 1;
            foreach ($field['sub_fields'] as $sub) {
                if ($field['type'] === 'repeater') {
                    for ($index = 0; $index < $count; $index++) {
                        self::remove($sub, $post_id, $name . '_' . $index . '_' . $sub['name']);
                    }
                } else {
                    self::remove($sub, $post_id, $name . '_' . $sub['name']);
                }
            }
        }
        delete_post_meta($post_id, '_' . $name);
        return delete_post_meta($post_id, $name);
    }
}

function acf_get_field_groups($filter = []) {
    return array_values(array_filter(AcfStub::$groups, static fn ($group) => $group['active'] && (!isset($filter['post_type']) || in_array($filter['post_type'], $group['post_types'], true))));
}
function acf_get_fields($parent) {
    if (isset($parent['fields'])) {
        return $parent['fields'];
    }
    return $parent['sub_fields'] ?? [];
}
function acf_get_field($selector) { return AcfStub::find((string) $selector); }
function acf_get_field_type($type) { return in_array($type, AcfStub::$types, true) ? (object) ['name' => $type] : false; }
function update_field($selector, $value, $post_id = false) {
    AcfStub::$update_calls[] = [(string) $selector, $value, (int) $post_id];
    $field = AcfStub::find((string) $selector);
    if ($field === null) {
        return update_post_meta((int) $post_id, (string) $selector, $value);
    }
    return AcfStub::store($field, $value, (int) $post_id, $field['name']);
}
function get_field($selector, $post_id = false, $format_value = true) {
    $field = AcfStub::find((string) $selector);
    return $field === null ? null : AcfStub::load($field, (int) $post_id, $field['name']);
}
function delete_field($selector, $post_id = false) {
    $field = AcfStub::find((string) $selector);
    return $field === null ? false : AcfStub::remove($field, (int) $post_id, $field['name']);
}
