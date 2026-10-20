<?php

declare(strict_types=1);

namespace PostPressAPI\Integration\ACF;

if (!defined('ABSPATH')) exit;

/**
 * Discovers the ACF fields registered for a post type.
 *
 * Field groups come from acf_get_field_groups(['post_type' => …]), so only groups
 * whose location rules match the post type are offered. Field keys, names,
 * labels, types, and parent/sub-field relationships are kept exactly as ACF
 * registered them; nothing is invented or created.
 *
 * Discovery is lazy and must run after ACF has loaded its local JSON/PHP field
 * groups (acf/init on init), i.e. from admin screens, REST requests, or cron.
 */
final class AcfFields
{
    /** @var list<string> Field types written as single values. */
    public const SCALAR_TYPES = [
        'text', 'textarea', 'wysiwyg', 'number', 'range', 'email', 'url', 'oembed', 'true_false',
        'select', 'radio', 'button_group', 'checkbox', 'date_picker', 'date_time_picker', 'time_picker',
        'color_picker', 'link',
    ];

    /** @var list<string> Field types that need nested mappings. */
    public const CONTAINER_TYPES = ['group', 'repeater'];

    /** @var list<string> Presentational field types that hold no data. */
    private const LAYOUT_TYPES = ['message', 'tab', 'accordion', 'separator'];

    /** @var list<string> Choice-based field types. */
    private const CHOICE_TYPES = ['select', 'radio', 'button_group', 'checkbox'];

    /**
     * Per-post-type discovery cache.
     *
     * @var array<string, array{groups: list<array<string, mixed>>, index: array<string, array{field: array<string, mixed>, parent: string|null}>}>
     */
    private array $cache = [];

    /**
     * Whether the ACF functions this integration relies on are available.
     *
     * @return bool
     */
    public function available(): bool
    {
        return function_exists('acf_get_field_groups')
            && function_exists('acf_get_fields')
            && function_exists('acf_get_field')
            && function_exists('update_field')
            && function_exists('get_field')
            && function_exists('delete_field');
    }

    /**
     * Whether ACF currently has a field type registered (e.g. repeater requires ACF PRO).
     *
     * @param string $type Field type.
     *
     * @return bool
     */
    public function type_registered(string $type): bool
    {
        if (!function_exists('acf_get_field_type')) {
            return $this->available();
        }

        return (bool) acf_get_field_type($type);
    }

    /**
     * Clears the discovery cache (e.g. after ACF field groups change).
     *
     * @return void
     */
    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * Field groups and fields for the editor, without raw ACF settings.
     *
     * @param string $post_type Post type.
     *
     * @return list<array<string, mixed>>
     */
    public function groups(string $post_type): array
    {
        return $this->load($post_type)['groups'];
    }

    /**
     * Raw definition of a top-level field registered for the post type.
     *
     * @param string $post_type Post type.
     * @param string $key       Field key.
     *
     * @return array<string, mixed>|null
     */
    public function top_level_field(string $post_type, string $key): ?array
    {
        $entry = $this->load($post_type)['index'][$key] ?? null;

        return $entry !== null && $entry['parent'] === null ? $entry['field'] : null;
    }

    /**
     * Whether a key belongs to any field (top-level or nested) for the post type.
     *
     * @param string $post_type Post type.
     * @param string $key       Field key.
     *
     * @return bool
     */
    public function is_known_field(string $post_type, string $key): bool
    {
        return isset($this->load($post_type)['index'][$key]);
    }

    /**
     * Raw top-level field definitions for the post type.
     *
     * @param string $post_type Post type.
     *
     * @return list<array<string, mixed>>
     */
    public function top_level_fields(string $post_type): array
    {
        $fields = [];
        foreach ($this->load($post_type)['index'] as $entry) {
            if ($entry['parent'] === null) {
                $fields[] = $entry['field'];
            }
        }

        return $fields;
    }

    /**
     * Raw definition of a direct sub field of a group or repeater.
     *
     * @param array<string, mixed> $parent Parent field definition.
     * @param string               $key    Sub field key.
     *
     * @return array<string, mixed>|null
     */
    public function sub_field(array $parent, string $key): ?array
    {
        foreach ((array) ($parent['sub_fields'] ?? []) as $sub_field) {
            if (is_array($sub_field) && ($sub_field['key'] ?? null) === $key) {
                return $sub_field;
            }
        }

        return null;
    }

    /**
     * Explains why a field cannot be mapped, or returns null when it can.
     *
     * @param array<string, mixed> $field Field definition.
     *
     * @return string|null
     */
    public function unsupported_reason(array $field): ?string
    {
        $type = (string) ($field['type'] ?? '');

        if (in_array($type, self::SCALAR_TYPES, true) || in_array($type, self::CONTAINER_TYPES, true)) {
            if (!$this->type_registered($type)) {
                return sprintf(
                    /* translators: %s: ACF field type. */
                    __('The ACF "%s" field type is not available on this site (it may require ACF PRO).', 'post-press-api'),
                    $type
                );
            }

            if (in_array($type, self::CONTAINER_TYPES, true) && empty($field['sub_fields'])) {
                return __('This group/repeater has no sub fields.', 'post-press-api');
            }

            return null;
        }

        return match ($type) {
            'image', 'file', 'gallery' => __('Media fields are not supported: importing files is out of scope for this version. Map the media URL to a URL or text field instead.', 'post-press-api'),
            'post_object', 'page_link', 'relationship', 'taxonomy', 'user' => __('Relationship fields need local WordPress IDs, which an external API cannot provide, so they are not supported.', 'post-press-api'),
            'flexible_content', 'clone' => __('Flexible content and clone fields are not supported. Map their data into separate fields instead.', 'post-press-api'),
            default => sprintf(
                /* translators: %s: ACF field type. */
                __('The ACF field type "%s" is not supported.', 'post-press-api'),
                $type
            ),
        };
    }

    /**
     * Loads and caches discovery results for a post type.
     *
     * @param string $post_type Post type.
     *
     * @return array{groups: list<array<string, mixed>>, index: array<string, array{field: array<string, mixed>, parent: string|null}>}
     */
    private function load(string $post_type): array
    {
        if (isset($this->cache[$post_type])) {
            return $this->cache[$post_type];
        }

        $groups = [];
        $index  = [];

        if ($this->available() && $post_type !== '') {
            foreach ((array) acf_get_field_groups(['post_type' => $post_type]) as $group) {
                if (!is_array($group) || empty($group['key']) || (array_key_exists('active', $group) && !$group['active'])) {
                    continue;
                }

                $title       = (string) ($group['title'] ?? $group['key']);
                $descriptors = [];

                foreach ((array) acf_get_fields($group) as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $descriptor = $this->describe($field, (string) $group['key'], $title, [$title], null, $index);
                    if ($descriptor !== null) {
                        $descriptors[] = $descriptor;
                    }
                }

                $groups[] = [
                    'key'    => (string) $group['key'],
                    'title'  => $title,
                    'fields' => $descriptors,
                ];
            }
        }

        return $this->cache[$post_type] = ['groups' => $groups, 'index' => $index];
    }

    /**
     * Builds an editor descriptor for a field and indexes the raw definition.
     *
     * @param array<string, mixed>                                                      $field       Field definition.
     * @param string                                                                    $group_key   Field group key.
     * @param string                                                                    $group_title Field group title.
     * @param list<string>                                                              $trail       Labels of ancestors.
     * @param string|null                                                               $parent_key  Parent field key.
     * @param array<string, array{field: array<string, mixed>, parent: string|null}>   $index       Index accumulator.
     *
     * @return array<string, mixed>|null Null for layout-only fields.
     */
    private function describe(array $field, string $group_key, string $group_title, array $trail, ?string $parent_key, array &$index): ?array
    {
        $type = (string) ($field['type'] ?? '');
        $key  = (string) ($field['key'] ?? '');

        if ($key === '' || in_array($type, self::LAYOUT_TYPES, true)) {
            return null;
        }

        $label  = (string) (($field['label'] ?? '') !== '' ? $field['label'] : ($field['name'] ?? $key));
        $reason = $this->unsupported_reason($field);

        $descriptor = [
            'key'         => $key,
            'name'        => (string) ($field['name'] ?? ''),
            'label'       => $label,
            'type'        => $type,
            'supported'   => $reason === null,
            'reason'      => $reason,
            'container'   => in_array($type, self::CONTAINER_TYPES, true),
            'multiple'    => $type === 'checkbox' || ($type === 'select' && !empty($field['multiple'])),
            'choices'     => $this->choices($field),
            'group_key'   => $group_key,
            'group_title' => $group_title,
            'parent'      => $parent_key,
            'trail'       => implode(' › ', [...$trail, $label]),
            'children'    => [],
        ];

        $index[$key] = ['field' => $field, 'parent' => $parent_key];

        if ($descriptor['container']) {
            foreach ((array) ($field['sub_fields'] ?? []) as $sub_field) {
                if (!is_array($sub_field)) {
                    continue;
                }
                $child = $this->describe($sub_field, $group_key, $group_title, [...$trail, $label], $key, $index);
                if ($child !== null) {
                    $descriptor['children'][] = $child;
                }
            }
        }

        return $descriptor;
    }

    /**
     * Choice list for choice-based fields (bounded).
     *
     * @param array<string, mixed> $field Field definition.
     *
     * @return list<array{value: string, label: string}>
     */
    private function choices(array $field): array
    {
        if (!in_array($field['type'] ?? '', self::CHOICE_TYPES, true)) {
            return [];
        }

        $choices = [];
        foreach ((array) ($field['choices'] ?? []) as $value => $label) {
            $choices[] = ['value' => (string) $value, 'label' => is_scalar($label) ? (string) $label : (string) $value];
            if (count($choices) >= 200) {
                break;
            }
        }

        return $choices;
    }
}
