<?php

declare(strict_types=1);

namespace PostPressAPI\Sync;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Mapping\MappingError;
use PostPressAPI\Mapping\PathResolver;
use PostPressAPI\Mapping\Transformer;
use PostPressAPI\Mapping\ValueInspector;
use PostPressAPI\Presets\PresetSchema;

/**
 * Offset/limit and page-number pagination.
 *
 * After each page it decides whether to continue:
 *  - an empty page ends the run (as truncated if the API claimed more records);
 *  - with a has-more path, its boolean decides (a missing or non-boolean flag is an error);
 *  - otherwise with a total path, the running count is compared with the total;
 *  - otherwise a full page (records = page size) means "try the next page".
 * A page identical to one already seen, or a position that does not advance,
 * stops the run with an error. Reaching the maximum pages/records while more
 * records remain stops the run as "truncated", never as complete.
 */
final class Pagination
{
    public const CONTINUE  = 'continue';
    public const DONE      = 'done';
    public const TRUNCATED = 'truncated';
    public const ERROR     = 'error';

    public function __construct(private readonly PathResolver $paths)
    {
    }

    /**
     * First cursor for a run.
     *
     * @param array<string, mixed> $preset Preset.
     *
     * @return array{position: int}
     */
    public function start(array $preset): array
    {
        $start = (int) $preset['pagination']['start'];
        if ($preset['pagination']['type'] === PresetSchema::PAGINATION_PAGE && $start < 1) {
            $start = 1;
        }

        return ['position' => $start];
    }

    /**
     * Request parameters for a cursor.
     *
     * @param array<string, mixed>  $preset Preset.
     * @param array{position: int}  $cursor Cursor.
     *
     * @return array<string, int>
     */
    public function params(array $preset, array $cursor): array
    {
        $pagination = $preset['pagination'];

        switch ($pagination['type']) {
            case PresetSchema::PAGINATION_OFFSET:
                return [
                    (string) $pagination['offset_param'] => (int) $cursor['position'],
                    (string) $pagination['limit_param']  => (int) $pagination['page_size'],
                ];

            case PresetSchema::PAGINATION_PAGE:
                $params = [(string) $pagination['page_param'] => (int) $cursor['position']];
                if ($pagination['limit_param'] !== '') {
                    $params[(string) $pagination['limit_param']] = (int) $pagination['page_size'];
                }
                return $params;
        }

        return [];
    }

    /**
     * Decides what follows a fetched page.
     *
     * @param array<string, mixed> $preset        Preset.
     * @param array{position: int} $cursor        Cursor used for this page.
     * @param mixed                $data          Decoded response.
     * @param int                  $count         Records on this page.
     * @param string               $page_hash     Hash of this page's records.
     * @param list<string>         $seen_hashes   Hashes of earlier pages in this run.
     * @param int                  $pages         Pages fetched so far, including this one.
     * @param int                  $records       Records fetched so far, including this page.
     *
     * @return array{state: string, cursor: array{position: int}, message: string}
     */
    public function after_page(array $preset, array $cursor, mixed $data, int $count, string $page_hash, array $seen_hashes, int $pages, int $records): array
    {
        $pagination = $preset['pagination'];
        $type       = $pagination['type'];

        if ($type === PresetSchema::PAGINATION_NONE) {
            return ['state' => self::DONE, 'cursor' => $cursor, 'message' => ''];
        }

        $more = null;
        if ($pagination['has_more_path'] !== '') {
            $flag = $this->paths->resolve($data, (string) $pagination['has_more_path']);
            if (!$flag->is_present()) {
                return $this->error($cursor, sprintf(
                    /* translators: %s: has-more path. */
                    __('The has-more path "%s" was not found in the response, so it is unknown whether more records exist.', 'post-press-api'),
                    $pagination['has_more_path']
                ));
            }
            try {
                $more = Transformer::to_bool($flag->value);
            } catch (MappingError) {
                return $this->error($cursor, sprintf(
                    /* translators: 1: has-more path, 2: value. */
                    __('The has-more value at "%1$s" is %2$s, not true or false.', 'post-press-api'),
                    $pagination['has_more_path'],
                    ValueInspector::preview($flag->value, 40)
                ));
            }
        }

        if ($count === 0) {
            if ($more === true) {
                return ['state' => self::TRUNCATED, 'cursor' => $cursor, 'message' => __('The API reported more records but returned an empty page, so the run stopped early.', 'post-press-api')];
            }
            return ['state' => self::DONE, 'cursor' => $cursor, 'message' => ''];
        }

        if (in_array($page_hash, $seen_hashes, true)) {
            return $this->error($cursor, sprintf(
                /* translators: %d: offset or page number. */
                __('The API returned the same records again for position %d. Check the pagination parameter names.', 'post-press-api'),
                (int) $cursor['position']
            ));
        }

        if ($more === null && $pagination['total_path'] !== '') {
            $total = $this->paths->resolve($data, (string) $pagination['total_path']);
            if ($total->is_present() && is_numeric($total->value)) {
                $more = $type === PresetSchema::PAGINATION_OFFSET
                    ? (int) $cursor['position'] + $count < (int) $total->value
                    : $records < (int) $total->value;
            }
        }

        if ($more === null) {
            $more = $count >= (int) $pagination['page_size'];
        }

        if (!$more) {
            return ['state' => self::DONE, 'cursor' => $cursor, 'message' => ''];
        }

        $next = $type === PresetSchema::PAGINATION_OFFSET ? (int) $cursor['position'] + $count : (int) $cursor['position'] + 1;
        if ($next <= (int) $cursor['position']) {
            return $this->error($cursor, __('Pagination did not advance.', 'post-press-api'));
        }

        if ($pages >= (int) $pagination['max_pages'] || $records >= (int) $pagination['max_records']) {
            return [
                'state'   => self::TRUNCATED,
                'cursor'  => $cursor,
                'message' => sprintf(
                    /* translators: 1: pages, 2: records. */
                    __('Stopped at the safety limit after %1$d pages / %2$d records; the API reports more records. Raise the limits to import the rest.', 'post-press-api'),
                    $pages,
                    $records
                ),
            ];
        }

        return ['state' => self::CONTINUE, 'cursor' => ['position' => $next], 'message' => ''];
    }

    /**
     * @param array{position: int} $cursor  Cursor.
     * @param string               $message Error.
     *
     * @return array{state: string, cursor: array{position: int}, message: string}
     */
    private function error(array $cursor, string $message): array
    {
        return ['state' => self::ERROR, 'cursor' => $cursor, 'message' => $message];
    }
}
