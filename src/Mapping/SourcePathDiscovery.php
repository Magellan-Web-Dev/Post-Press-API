<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * Lists the paths present in sample JSON so administrators can pick source paths
 * instead of typing them. Manual path entry always remains available.
 */
final class SourcePathDiscovery
{
    /** @var int Maximum nesting depth walked. */
    private const MAX_DEPTH = 8;

    /** @var int Items inspected per array when building wildcard paths. */
    private const ITEMS_PER_ARRAY = 5;

    public function __construct(private readonly PathResolver $paths)
    {
    }

    /**
     * Discovers record-relative paths from sample records.
     *
     * @param list<mixed> $records     Records selected by the records path.
     * @param int         $max_records Records to inspect.
     * @param int         $max_paths   Maximum paths returned.
     *
     * @return list<array{path: string, type: string, sample: string}>
     */
    public function discover_records(array $records, int $max_records = 5, int $max_paths = 300): array
    {
        $found = [];

        foreach (array_slice(array_values($records), 0, $max_records) as $record) {
            $this->walk($record, [], 0, $found, $max_paths, true);
        }

        unset($found[PathResolver::ROOT]);

        return array_values($found);
    }

    /**
     * Discovers response-level paths, useful for the records path, success flag,
     * and pagination settings. Lists are reported but not descended into.
     *
     * @param mixed $response  Decoded response.
     * @param int   $max_paths Maximum paths returned.
     *
     * @return list<array{path: string, type: string, sample: string}>
     */
    public function discover_response(mixed $response, int $max_paths = 150): array
    {
        $found = [];
        $this->walk($response, [], 0, $found, $max_paths, false);

        return array_values($found);
    }

    /**
     * Recursively records paths.
     *
     * @param mixed                                        $value        Current value.
     * @param list<array{type: string, key?: string|int}> $tokens       Tokens leading to $value.
     * @param int                                          $depth        Current depth.
     * @param array<string, array{path: string, type: string, sample: string}> $found Accumulator.
     * @param int                                          $max_paths    Limit.
     * @param bool                                         $descend_lists Whether to walk into list items.
     *
     * @return void
     */
    private function walk(mixed $value, array $tokens, int $depth, array &$found, int $max_paths, bool $descend_lists): void
    {
        if (count($found) >= $max_paths) {
            return;
        }

        $path = $this->paths->format($tokens);
        if (!isset($found[$path])) {
            $found[$path] = [
                'path'   => $path,
                'type'   => ValueInspector::describe($value),
                'sample' => ValueInspector::preview($value, 80),
            ];
        }

        if (!is_array($value) || $value === [] || $depth >= self::MAX_DEPTH) {
            return;
        }

        if (array_is_list($value)) {
            if (!$descend_lists) {
                return;
            }

            foreach (array_slice($value, 0, self::ITEMS_PER_ARRAY) as $item) {
                $this->walk($item, [...$tokens, ['type' => 'wildcard']], $depth + 1, $found, $max_paths, true);
            }
            $this->walk($value[0], [...$tokens, ['type' => 'key', 'key' => 0]], $depth + 1, $found, $max_paths, true);
            return;
        }

        foreach ($value as $key => $child) {
            $this->walk($child, [...$tokens, ['type' => 'key', 'key' => $key]], $depth + 1, $found, $max_paths, $descend_lists);
        }
    }
}
