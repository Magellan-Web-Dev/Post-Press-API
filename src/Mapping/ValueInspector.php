<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * Describes decoded JSON values for previews and error messages.
 */
final class ValueInspector
{
    /**
     * Short machine-friendly type name.
     *
     * @param mixed $value Value.
     *
     * @return string One of null, boolean, integer, number, string, array, object.
     */
    public static function type(mixed $value): string
    {
        return match (true) {
            $value === null    => 'null',
            is_bool($value)    => 'boolean',
            is_int($value)     => 'integer',
            is_float($value)   => 'number',
            is_string($value)  => 'string',
            is_array($value)   => array_is_list($value) ? 'array' : 'object',
            default            => 'unsupported',
        };
    }

    /**
     * Type with size information, e.g. "array (3 items)".
     *
     * @param mixed $value Value.
     *
     * @return string
     */
    public static function describe(mixed $value): string
    {
        $type = self::type($value);

        if ($type === 'array') {
            return sprintf(
                /* translators: %d: number of items. */
                _n('array (%d item)', 'array (%d items)', count($value), 'post-press-api'),
                count($value)
            );
        }

        if ($type === 'object') {
            return sprintf(
                /* translators: %d: number of keys. */
                _n('object (%d key)', 'object (%d keys)', count($value), 'post-press-api'),
                count($value)
            );
        }

        return $type;
    }

    /**
     * Type phrase for sentences, e.g. "a string".
     *
     * @param mixed $value Value.
     *
     * @return string
     */
    public static function type_label(mixed $value): string
    {
        return match (self::type($value)) {
            'null'    => __('null', 'post-press-api'),
            'boolean' => __('a boolean', 'post-press-api'),
            'integer' => __('an integer', 'post-press-api'),
            'number'  => __('a number', 'post-press-api'),
            'string'  => __('a string', 'post-press-api'),
            'array'   => __('an array', 'post-press-api'),
            'object'  => __('an object', 'post-press-api'),
            default   => __('an unsupported value', 'post-press-api'),
        };
    }

    /**
     * Bounded JSON-style preview of a value.
     *
     * @param mixed $value      Value.
     * @param int   $max_length Maximum characters returned.
     *
     * @return string
     */
    public static function preview(mixed $value, int $max_length = 120): string
    {
        if (is_string($value)) {
            $text = $value;
        } else {
            $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $text    = is_string($encoded) ? $encoded : '';
        }

        if (self::length($text) > $max_length) {
            $text = self::substr($text, 0, max(1, $max_length - 1)) . '…';
        }

        return $text;
    }

    /**
     * Multibyte-safe length with a byte-length fallback.
     *
     * @param string $text Text.
     *
     * @return int
     */
    public static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    /**
     * Multibyte-safe substring with a byte fallback.
     *
     * @param string $text   Text.
     * @param int    $start  Start.
     * @param int    $length Length.
     *
     * @return string
     */
    public static function substr(string $text, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, $start, $length, 'UTF-8') : substr($text, $start, $length);
    }
}
