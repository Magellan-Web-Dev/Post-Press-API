<?php

declare(strict_types=1);

namespace PostPressAPI\Presets;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Mapping\MappingSchema;
use PostPressAPI\Mapping\MarkupPolicy;

/**
 * Structure, defaults, and bounds of a request preset.
 *
 * Presets never contain credentials; those live in {@see SecretStore}.
 * normalize() coerces stored or submitted data into this shape and clamps
 * numeric settings to their bounds. Semantic validation lives in
 * {@see PresetValidator}.
 */
final class PresetSchema
{
    public const VERSION = 1;

    /** @var list<string> */
    public const METHODS = ['GET', 'POST'];

    public const AUTH_NONE   = 'none';
    public const AUTH_BEARER = 'bearer';
    public const AUTH_HEADER = 'api_key_header';
    public const AUTH_QUERY  = 'api_key_query';

    /** @var list<string> */
    public const AUTH_TYPES = [self::AUTH_NONE, self::AUTH_BEARER, self::AUTH_HEADER, self::AUTH_QUERY];

    public const IDENTITY_PATH      = 'path';
    public const IDENTITY_COMPOSITE = 'composite';

    /** @var list<string> */
    public const IDENTITY_MODES = [self::IDENTITY_PATH, self::IDENTITY_COMPOSITE];

    public const POLICY_SKIP   = 'skip_existing';
    public const POLICY_UPDATE = 'update_changed';
    public const POLICY_CREATE = 'always_create';

    /** @var list<string> */
    public const POLICIES = [self::POLICY_SKIP, self::POLICY_UPDATE, self::POLICY_CREATE];

    public const MATCH_NONE = 'none';
    public const MATCH_META = 'meta';
    public const MATCH_ACF  = 'acf';

    /** @var list<string> */
    public const MATCH_TYPES = [self::MATCH_NONE, self::MATCH_META, self::MATCH_ACF];

    public const TRASH_SKIP    = 'skip';
    public const TRASH_RESTORE = 'restore';

    /** @var list<string> */
    public const TRASH_POLICIES = [self::TRASH_SKIP, self::TRASH_RESTORE];

    public const PAGINATION_NONE   = 'none';
    public const PAGINATION_OFFSET = 'offset';
    public const PAGINATION_PAGE   = 'page';

    /** @var list<string> */
    public const PAGINATION_TYPES = [self::PAGINATION_NONE, self::PAGINATION_OFFSET, self::PAGINATION_PAGE];

    public const PLACEMENT_QUERY = 'query';
    public const PLACEMENT_BODY  = 'body';

    public const FREQ_MANUAL = 'manual';
    public const FREQ_5      = 'every_5_minutes';
    public const FREQ_15     = 'every_15_minutes';
    public const FREQ_HOURLY = 'hourly';
    public const FREQ_TWICE  = 'twicedaily';
    public const FREQ_DAILY  = 'daily';
    public const FREQ_CUSTOM = 'custom';

    /** @var list<string> */
    public const FREQUENCIES = [self::FREQ_MANUAL, self::FREQ_5, self::FREQ_15, self::FREQ_HOURLY, self::FREQ_TWICE, self::FREQ_DAILY, self::FREQ_CUSTOM];

    /** @var array<string, array{0: int, 1: int, 2: int}> Numeric bounds: [min, max, default]. */
    public const BOUNDS = [
        'timeout'        => [1, 60, 20],
        'max_kb'         => [16, 10240, 2048],
        'retries'        => [0, 5, 2],
        'batch_size'     => [1, 200, 25],
        'custom_minutes' => [5, 43200, 60],
        'page_size'      => [1, 1000, 100],
        'max_pages'      => [1, 1000, 100],
        'max_records'    => [1, 100000, 10000],
        'start'          => [0, 1000000000, 0],
    ];

    public const MAX_QUERY_ROWS  = 50;
    public const MAX_HEADER_ROWS = 30;
    public const MAX_BODY_BYTES  = 65536;
    public const MAX_IDENTITY_PATHS = 5;

    /**
     * Default preset. New presets start disabled and manual-only.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'schema'      => self::VERSION,
            'id'          => '',
            'name'        => '',
            'enabled'     => false,
            'revision'    => 0,
            'created_at'  => 0,
            'updated_at'  => 0,
            'updated_by'  => 0,
            'request'     => [
                'url'     => '',
                'method'  => 'GET',
                'query'   => [],
                'headers' => [],
                'body'    => '',
                'timeout' => self::BOUNDS['timeout'][2],
                'max_kb'  => self::BOUNDS['max_kb'][2],
                'retries' => self::BOUNDS['retries'][2],
                'auth'    => ['type' => self::AUTH_NONE, 'name' => ''],
            ],
            'response'    => [
                'records_path'  => '',
                'success_path'  => '',
                'success_value' => 'true',
                'identity'      => ['mode' => self::IDENTITY_PATH, 'path' => 'id', 'paths' => []],
            ],
            'destination' => [
                'post_type'  => '',
                'status'     => 'draft',
                'author'     => 0,
                'markup'     => MarkupPolicy::NEUTRALIZE,
                'shortcodes' => [],
            ],
            'mappings'    => [],
            'sync'        => [
                'policy'  => self::POLICY_UPDATE,
                'match'   => ['type' => self::MATCH_NONE, 'key' => ''],
                'trashed' => self::TRASH_SKIP,
            ],
            'pagination'  => [
                'type'          => self::PAGINATION_NONE,
                'offset_param'  => 'offset',
                'limit_param'   => 'limit',
                'page_param'    => 'page',
                'start'         => 0,
                'page_size'     => self::BOUNDS['page_size'][2],
                'has_more_path' => '',
                'total_path'    => '',
                'placement'     => self::PLACEMENT_QUERY,
                'max_pages'     => self::BOUNDS['max_pages'][2],
                'max_records'   => self::BOUNDS['max_records'][2],
            ],
            'schedule'    => [
                'frequency' => self::FREQ_MANUAL,
                'minutes'   => self::BOUNDS['custom_minutes'][2],
            ],
            'batch_size'  => self::BOUNDS['batch_size'][2],
        ];
    }

    /**
     * Coerces data into the preset shape.
     *
     * @param array<string, mixed> $data Stored or submitted data.
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $d        = self::defaults();
        $request  = self::arr($data['request'] ?? []);
        $response = self::arr($data['response'] ?? []);
        $dest     = self::arr($data['destination'] ?? []);
        $sync     = self::arr($data['sync'] ?? []);
        $page     = self::arr($data['pagination'] ?? []);
        $schedule = self::arr($data['schedule'] ?? []);
        $auth     = self::arr($request['auth'] ?? []);
        $identity = self::arr($response['identity'] ?? []);
        $match    = self::arr($sync['match'] ?? []);

        $query = [];
        foreach (array_slice(array_values(self::arr($request['query'] ?? [])), 0, self::MAX_QUERY_ROWS) as $row) {
            $row = self::arr($row);
            $key = trim(self::str($row['key'] ?? '', 200));
            if ($key === '' && trim(self::str($row['value'] ?? '', 2000)) === '') {
                continue;
            }
            $query[] = ['key' => $key, 'value' => self::str($row['value'] ?? '', 2000)];
        }

        $headers = [];
        foreach (array_slice(array_values(self::arr($request['headers'] ?? [])), 0, self::MAX_HEADER_ROWS) as $row) {
            $row  = self::arr($row);
            $name = trim(self::str($row['name'] ?? '', 200));
            if ($name === '' && trim(self::str($row['value'] ?? '', 2000)) === '') {
                continue;
            }
            $headers[] = ['name' => $name, 'value' => self::str($row['value'] ?? '', 2000)];
        }

        $paths = $identity['paths'] ?? [];
        if (is_string($paths)) {
            $paths = preg_split('/\r\n|\r|\n/', $paths) ?: [];
        }
        $paths = array_values(array_filter(array_map(static fn ($path): string => trim(self::str($path, 500)), self::arr($paths)), static fn (string $path): bool => $path !== ''));

        return [
            'schema'      => self::VERSION,
            'id'          => self::str($data['id'] ?? '', 12),
            'name'        => trim(self::str($data['name'] ?? '', 200)),
            'enabled'     => !empty($data['enabled']),
            'revision'    => max(0, (int) ($data['revision'] ?? 0)),
            'created_at'  => max(0, (int) ($data['created_at'] ?? 0)),
            'updated_at'  => max(0, (int) ($data['updated_at'] ?? 0)),
            'updated_by'  => max(0, (int) ($data['updated_by'] ?? 0)),
            'request'     => [
                'url'     => trim(self::str($request['url'] ?? '', 2048)),
                'method'  => self::choice(strtoupper(self::str($request['method'] ?? '', 10)), self::METHODS, 'GET'),
                'query'   => $query,
                'headers' => $headers,
                'body'    => self::str($request['body'] ?? '', self::MAX_BODY_BYTES),
                'timeout' => self::bounded($request['timeout'] ?? null, 'timeout'),
                'max_kb'  => self::bounded($request['max_kb'] ?? null, 'max_kb'),
                'retries' => self::bounded($request['retries'] ?? null, 'retries'),
                'auth'    => [
                    'type' => self::choice(self::str($auth['type'] ?? '', 40), self::AUTH_TYPES, self::AUTH_NONE),
                    'name' => trim(self::str($auth['name'] ?? '', 200)),
                ],
            ],
            'response'    => [
                'records_path'  => trim(self::str($response['records_path'] ?? '', 500)),
                'success_path'  => trim(self::str($response['success_path'] ?? '', 500)),
                'success_value' => trim(self::str($response['success_value'] ?? $d['response']['success_value'], 200)),
                'identity'      => [
                    'mode'  => self::choice(self::str($identity['mode'] ?? '', 20), self::IDENTITY_MODES, self::IDENTITY_PATH),
                    'path'  => trim(self::str($identity['path'] ?? '', 500)),
                    'paths' => array_slice($paths, 0, self::MAX_IDENTITY_PATHS),
                ],
            ],
            'destination' => [
                'post_type'  => sanitize_key(self::str($dest['post_type'] ?? '', 20)),
                'status'     => sanitize_key(self::str($dest['status'] ?? 'draft', 20)),
                'author'     => max(0, (int) ($dest['author'] ?? 0)),
                'markup'     => self::choice(self::str($dest['markup'] ?? '', 20), MarkupPolicy::MODES, MarkupPolicy::NEUTRALIZE),
                'shortcodes' => MarkupPolicy::tags($dest['shortcodes'] ?? []),
            ],
            'mappings'    => MappingSchema::normalize_rows($data['mappings'] ?? []),
            'sync'        => [
                'policy'  => self::choice(self::str($sync['policy'] ?? '', 30), self::POLICIES, self::POLICY_UPDATE),
                'match'   => [
                    'type' => self::choice(self::str($match['type'] ?? '', 10), self::MATCH_TYPES, self::MATCH_NONE),
                    'key'  => trim(self::str($match['key'] ?? '', 191)),
                ],
                'trashed' => self::choice(self::str($sync['trashed'] ?? '', 10), self::TRASH_POLICIES, self::TRASH_SKIP),
            ],
            'pagination'  => [
                'type'          => self::choice(self::str($page['type'] ?? '', 10), self::PAGINATION_TYPES, self::PAGINATION_NONE),
                'offset_param'  => trim(self::str($page['offset_param'] ?? $d['pagination']['offset_param'], 100)),
                'limit_param'   => trim(self::str($page['limit_param'] ?? $d['pagination']['limit_param'], 100)),
                'page_param'    => trim(self::str($page['page_param'] ?? $d['pagination']['page_param'], 100)),
                'start'         => self::bounded($page['start'] ?? null, 'start'),
                'page_size'     => self::bounded($page['page_size'] ?? null, 'page_size'),
                'has_more_path' => trim(self::str($page['has_more_path'] ?? '', 500)),
                'total_path'    => trim(self::str($page['total_path'] ?? '', 500)),
                'placement'     => self::choice(self::str($page['placement'] ?? '', 10), [self::PLACEMENT_QUERY, self::PLACEMENT_BODY], self::PLACEMENT_QUERY),
                'max_pages'     => self::bounded($page['max_pages'] ?? null, 'max_pages'),
                'max_records'   => self::bounded($page['max_records'] ?? null, 'max_records'),
            ],
            'schedule'    => [
                'frequency' => self::choice(self::str($schedule['frequency'] ?? '', 20), self::FREQUENCIES, self::FREQ_MANUAL),
                'minutes'   => self::bounded($schedule['minutes'] ?? null, 'custom_minutes'),
            ],
            'batch_size'  => self::bounded($data['batch_size'] ?? null, 'batch_size'),
        ];
    }

    /**
     * Clamps a numeric setting, using the default when the input is not numeric.
     *
     * @param mixed  $value Value.
     * @param string $key   BOUNDS key.
     *
     * @return int
     */
    public static function bounded(mixed $value, string $key): int
    {
        [$min, $max, $default] = self::BOUNDS[$key];

        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * @param mixed $value Value.
     *
     * @return array<mixed>
     */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Bounded string coercion that drops invalid UTF-8.
     *
     * @param mixed $value Value.
     * @param int   $max   Maximum bytes.
     *
     * @return string
     */
    private static function str(mixed $value, int $max): string
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
     * @param string       $value   Value.
     * @param list<string> $allowed Allowed values.
     * @param string       $default Default.
     *
     * @return string
     */
    private static function choice(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
