<?php

declare(strict_types=1);

namespace PostPressAPI\Integration\ACF;

use PostPressAPI\Mapping\DateParser;
use PostPressAPI\Mapping\DateValue;
use PostPressAPI\Mapping\MappingError;
use PostPressAPI\Mapping\Transformer;
use PostPressAPI\Mapping\ValueInspector;

if (!defined('ABSPATH')) exit;

/**
 * Converts mapped values into the storage format of single-value ACF fields.
 *
 * Group and repeater values are assembled by the mapping engine from nested
 * mappings; this class handles their leaf fields and the empty value used
 * when a nested mapping explicitly clears a sub field.
 *
 * Storage formats follow ACF: date_picker "Ymd", date_time_picker
 * "Y-m-d H:i:s", time_picker "H:i:s", true_false 1/0, link arrays with
 * url/title/target, and choice keys for select/radio/checkbox/button_group.
 */
final class AcfNormalizer
{
    /**
     * Normalizes a value for a non-container field.
     *
     * @param array<string, mixed> $field   ACF field definition.
     * @param mixed                $value   Transformed value (never null).
     * @param \DateTimeZone        $site_tz Site time zone.
     *
     * @return mixed
     *
     * @throws MappingError When the value does not fit the field.
     */
    public function normalize(array $field, mixed $value, \DateTimeZone $site_tz): mixed
    {
        $type = (string) ($field['type'] ?? '');

        switch ($type) {
            case 'text':
                return sanitize_text_field(Transformer::stringify($value, $site_tz));

            case 'textarea':
            case 'wysiwyg':
                return wp_kses_post(Transformer::stringify($value, $site_tz));

            case 'number':
            case 'range':
                return Transformer::to_number($value instanceof DateValue ? Transformer::stringify($value, $site_tz) : $value);

            case 'email':
                return Transformer::to_email($value);

            case 'url':
            case 'oembed':
                return Transformer::to_url($value);

            case 'true_false':
                return Transformer::to_bool($value) ? 1 : 0;

            case 'select':
                if (!empty($field['multiple'])) {
                    return $this->choice_list($field, $value);
                }
                return $this->choice($field, $value);

            case 'radio':
            case 'button_group':
                return $this->choice($field, $value);

            case 'checkbox':
                return $this->choice_list($field, $value);

            case 'date_picker':
                $date = $this->date($value, $site_tz);
                if (!$date->has_date) {
                    throw new MappingError(__('The value has no calendar date for this date field.', 'post-press-api'));
                }
                return $date->format('Ymd', $site_tz);

            case 'date_time_picker':
                $date = $this->date($value, $site_tz);
                if (!$date->has_date) {
                    throw new MappingError(__('The value has no calendar date for this date/time field.', 'post-press-api'));
                }
                return $date->format('Y-m-d H:i:s', $site_tz);

            case 'time_picker':
                $date = $this->date($value, $site_tz);
                if (!$date->has_time) {
                    throw new MappingError(__('The value has no time of day for this time field.', 'post-press-api'));
                }
                return $date->format('H:i:s', $site_tz);

            case 'color_picker':
                return $this->color($field, $value);

            case 'link':
                return $this->link($value);
        }

        throw new MappingError(sprintf(
            /* translators: %s: ACF field type. */
            __('The ACF field type "%s" cannot receive a single value.', 'post-press-api'),
            $type
        ));
    }

    /**
     * The value written when a nested mapping clears a sub field.
     *
     * @param array<string, mixed> $field ACF field definition.
     *
     * @return mixed
     */
    public function empty_value(array $field): mixed
    {
        $type = (string) ($field['type'] ?? '');

        if ($type === 'repeater') {
            return [];
        }

        if ($type === 'group') {
            $values = [];
            foreach ((array) ($field['sub_fields'] ?? []) as $sub_field) {
                if (is_array($sub_field) && !empty($sub_field['key'])) {
                    $values[(string) $sub_field['key']] = $this->empty_value($sub_field);
                }
            }
            return $values;
        }

        if ($type === 'checkbox' || ($type === 'select' && !empty($field['multiple']))) {
            return [];
        }

        return '';
    }

    /**
     * Parses a date for a date-type field.
     *
     * @param mixed         $value   Value.
     * @param \DateTimeZone $site_tz Site time zone.
     *
     * @return DateValue
     */
    private function date(mixed $value, \DateTimeZone $site_tz): DateValue
    {
        return $value instanceof DateValue ? $value : DateParser::parse($value, null, $site_tz);
    }

    /**
     * Resolves a single choice by key, then by exact label.
     *
     * @param array<string, mixed> $field Field definition.
     * @param mixed                $value Value.
     *
     * @return string
     */
    private function choice(array $field, mixed $value): string
    {
        $text    = Transformer::to_display_string($value);
        $choices = (array) ($field['choices'] ?? []);

        foreach ($choices as $choice_value => $choice_label) {
            if ((string) $choice_value === $text) {
                return (string) $choice_value;
            }
        }

        foreach ($choices as $choice_value => $choice_label) {
            if (is_scalar($choice_label) && (string) $choice_label === $text) {
                return (string) $choice_value;
            }
        }

        if (!empty($field['allow_custom']) || !empty($field['other_choice']) || !empty($field['create_options'])) {
            return sanitize_text_field($text);
        }

        $allowed = array_slice(array_map('strval', array_keys($choices)), 0, 12);

        throw new MappingError(sprintf(
            /* translators: 1: value, 2: allowed choices. */
            __('"%1$s" is not one of this field\'s choices (%2$s). Use a Value map to convert it.', 'post-press-api'),
            ValueInspector::preview($text, 40),
            $allowed === [] ? __('none defined', 'post-press-api') : implode(', ', $allowed)
        ));
    }

    /**
     * Resolves a list of choices.
     *
     * @param array<string, mixed> $field Field definition.
     * @param mixed                $value List or scalar.
     *
     * @return list<string>
     */
    private function choice_list(array $field, mixed $value): array
    {
        $items  = is_array($value) ? $value : [$value];
        $result = [];

        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }
            if (is_array($item)) {
                throw new MappingError(__('Choice lists can only contain text or numbers.', 'post-press-api'));
            }
            $result[] = $this->choice($field, $item);
        }

        return array_values(array_unique($result));
    }

    /**
     * Validates a color.
     *
     * @param array<string, mixed> $field Field definition.
     * @param mixed                $value Value.
     *
     * @return string
     */
    private function color(array $field, mixed $value): string
    {
        $text = trim(Transformer::to_display_string($value));

        if (preg_match('/^#([A-Fa-f0-9]{3}){1,2}$/', $text)) {
            return strtolower($text);
        }

        if (!empty($field['enable_opacity']) && preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/', $text)) {
            return $text;
        }

        throw new MappingError(sprintf(
            /* translators: %s: value preview. */
            __('"%s" is not a hex color such as #1e73be.', 'post-press-api'),
            ValueInspector::preview($text, 40)
        ));
    }

    /**
     * Builds an ACF link array from a URL or an object with url/title/target.
     *
     * @param mixed $value Value.
     *
     * @return array{title: string, url: string, target: string}
     */
    private function link(mixed $value): array
    {
        if (is_array($value) && array_key_exists('url', $value)) {
            $target = (string) ($value['target'] ?? '');

            return [
                'title'  => sanitize_text_field(is_scalar($value['title'] ?? null) ? (string) $value['title'] : ''),
                'url'    => Transformer::to_url($value['url']),
                'target' => $target === '_blank' ? '_blank' : '',
            ];
        }

        return ['title' => '', 'url' => Transformer::to_url($value), 'target' => ''];
    }
}
