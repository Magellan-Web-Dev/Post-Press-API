<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * Shape and defaults of mapping rows.
 *
 * A row:
 *   id           UI identifier (not part of fingerprints)
 *   source_type  path | constant | template
 *   source       path expression, constant text, or template with {path} placeholders
 *   target_type  core | meta | acf
 *   target       core field name, meta key, or ACF field key
 *   transform    one of Transformer::TYPES
 *   options      transformation options (separator, formats, time zones, decimals, value map)
 *   on_missing   preserve | clear | fallback | fail | skip_row
 *   on_null      same choices as on_missing
 *   fallback     literal fallback value
 *   children     nested rows for ACF groups and repeaters (sources relative to the item)
 *
 * normalize_rows() only coerces types and drops unknown keys; semantic checks
 * live in {@see MappingValidator}.
 */
final class MappingSchema
{
    public const SOURCE_PATH     = 'path';
    public const SOURCE_CONSTANT = 'constant';
    public const SOURCE_TEMPLATE = 'template';

    /** @var list<string> */
    public const SOURCE_TYPES = [self::SOURCE_PATH, self::SOURCE_CONSTANT, self::SOURCE_TEMPLATE];

    public const PRESERVE = 'preserve';
    public const CLEAR    = 'clear';
    public const FALLBACK = 'fallback';
    public const FAIL     = 'fail';
    public const SKIP_ROW = 'skip_row';

    /** @var list<string> */
    public const BEHAVIORS = [self::PRESERVE, self::CLEAR, self::FALLBACK, self::FAIL, self::SKIP_ROW];

    /** @var list<string> What a value map does with unlisted values. */
    public const UNMATCHED = ['keep', 'fallback', 'error'];

    public const MAX_ROWS        = 100;
    public const MAX_CHILDREN    = 50;
    public const MAX_DEPTH       = 3;
    public const MAX_MAP_ENTRIES = 100;
    public const MAX_TEXT        = 10000;

    /**
     * Normalizes a list of rows.
     *
     * @param mixed $rows  Raw rows (array) or anything else (treated as empty).
     * @param int   $depth Nesting depth (0 = top level).
     *
     * @return list<array<string, mixed>>
     */
    public static function normalize_rows(mixed $rows, int $depth = 0): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $limit      = $depth === 0 ? self::MAX_ROWS : self::MAX_CHILDREN;
        $normalized = [];

        foreach (array_values($rows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalized[] = self::normalize_row($row, $depth);
            if (count($normalized) >= $limit) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * Normalizes one row.
     *
     * @param array<string, mixed> $row   Raw row.
     * @param int                  $depth Nesting depth.
     *
     * @return array<string, mixed>
     */
    public static function normalize_row(array $row, int $depth = 0): array
    {
        $id = strtolower(preg_replace('/[^A-Za-z0-9]/', '', self::text($row['id'] ?? '', 24)) ?? '');
        if ($id === '') {
            $id = 'r' . bin2hex(random_bytes(4));
        }

        $source_type = self::choice($row['source_type'] ?? '', self::SOURCE_TYPES, self::SOURCE_PATH);
        $source      = self::text($row['source'] ?? '', self::MAX_TEXT);
        if ($source_type !== self::SOURCE_CONSTANT) {
            $source = trim($source);
        }

        $options = is_array($row['options'] ?? null) ? $row['options'] : [];
        $map     = [];
        foreach (array_slice(array_values((array) ($options['map'] ?? [])), 0, self::MAX_MAP_ENTRIES) as $pair) {
            if (is_array($pair) && (self::text($pair['from'] ?? '', 500) !== '' || self::text($pair['to'] ?? '', 500) !== '')) {
                $map[] = ['from' => self::text($pair['from'] ?? '', 500), 'to' => self::text($pair['to'] ?? '', 500)];
            }
        }

        $decimals = trim(self::text($options['decimals'] ?? '', 4));

        return [
            'id'          => $id,
            'source_type' => $source_type,
            'source'      => $source,
            'target_type' => self::choice($row['target_type'] ?? '', Destination::CATEGORIES, ''),
            'target'      => trim(self::text($row['target'] ?? '', 191)),
            'transform'   => self::choice($row['transform'] ?? '', Transformer::TYPES, Transformer::NONE),
            'options'     => [
                'separator'       => self::text($options['separator'] ?? ', ', 20),
                'input_format'    => trim(self::text($options['input_format'] ?? '', 50)),
                'input_timezone'  => trim(self::text($options['input_timezone'] ?? '', 64)),
                'output_format'   => trim(self::text($options['output_format'] ?? '', 50)),
                'output_timezone' => trim(self::text($options['output_timezone'] ?? '', 64)),
                'decimals'        => ctype_digit($decimals) ? (string) min(10, (int) $decimals) : '',
                'map'             => $map,
                'unmatched'       => self::choice($options['unmatched'] ?? '', self::UNMATCHED, 'keep'),
            ],
            'on_missing'  => self::choice($row['on_missing'] ?? '', self::BEHAVIORS, self::PRESERVE),
            'on_null'     => self::choice($row['on_null'] ?? '', self::BEHAVIORS, self::PRESERVE),
            'fallback'    => self::text($row['fallback'] ?? '', self::MAX_TEXT),
            'children'    => $depth + 1 < self::MAX_DEPTH ? self::normalize_rows($row['children'] ?? [], $depth + 1) : [],
        ];
    }

    /**
     * Mapping configuration that affects written values, for fingerprints.
     *
     * Row IDs are excluded so reordering in the UI alone does not force updates.
     *
     * @param list<array<string, mixed>> $rows Normalized rows.
     *
     * @return list<array<string, mixed>>
     */
    public static function fingerprint_config(array $rows): array
    {
        $config = [];
        foreach ($rows as $row) {
            $copy = $row;
            unset($copy['id']);
            $copy['children'] = self::fingerprint_config($row['children'] ?? []);
            $config[]         = $copy;
        }

        return $config;
    }

    /**
     * Coerces a scalar to a bounded string.
     *
     * @param mixed $value Value.
     * @param int   $max   Maximum length in bytes.
     *
     * @return string
     */
    private static function text(mixed $value, int $max): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $text = (string) $value;
        if (function_exists('wp_check_invalid_utf8')) {
            $text = wp_check_invalid_utf8($text);
        }

        return strlen($text) > $max ? substr($text, 0, $max) : $text;
    }

    /**
     * Returns $value when it is one of $allowed, otherwise $default.
     *
     * @param mixed        $value   Value.
     * @param list<string> $allowed Allowed values.
     * @param string       $default Default.
     *
     * @return string
     */
    private static function choice(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }
}
