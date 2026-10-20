<?php

declare(strict_types=1);

namespace PostPressAPI\Sync;

use PostPressAPI\Mapping\MappingError;
use PostPressAPI\Mapping\PathResolver;
use PostPressAPI\Mapping\PathSyntaxError;
use PostPressAPI\Mapping\Transformer;
use PostPressAPI\Mapping\ValueInspector;

if (!defined('ABSPATH')) exit;

/**
 * Validates a decoded response and selects its records.
 *
 * Response-level selection (success flag, records path) is kept separate from
 * record-level mapping: the records path selects the list, and mapping paths
 * then resolve inside each record.
 */
final class ResponseReader
{
    public function __construct(private readonly PathResolver $paths)
    {
    }

    /**
     * Checks the success flag and extracts the record list.
     *
     * @param mixed                $data   Decoded response.
     * @param array<string, mixed> $preset Preset.
     *
     * @return array{ok: bool, records: list<mixed>, error: string}
     */
    public function records(mixed $data, array $preset): array
    {
        $response = $preset['response'];

        try {
            if ($response['success_path'] !== '') {
                $error = $this->check_success($data, (string) $response['success_path'], (string) $response['success_value']);
                if ($error !== null) {
                    return ['ok' => false, 'records' => [], 'error' => $error];
                }
            }

            $path = (string) $response['records_path'];
            if ($path === '') {
                return ['ok' => false, 'records' => [], 'error' => __('No records path is configured. Use $ when the response itself is the list.', 'post-press-api')];
            }

            $result = $this->paths->resolve($data, $path);
        } catch (PathSyntaxError $e) {
            return ['ok' => false, 'records' => [], 'error' => $e->getMessage()];
        }

        if ($result->is_missing()) {
            return ['ok' => false, 'records' => [], 'error' => sprintf(
                /* translators: 1: records path, 2: detail. */
                __('The records path "%1$s" was not found in the response. %2$s', 'post-press-api'),
                $path,
                (string) $result->note
            )];
        }

        if ($result->is_null()) {
            return ['ok' => false, 'records' => [], 'error' => sprintf(
                /* translators: %s: records path. */
                __('The records path "%s" is null instead of a list.', 'post-press-api'),
                $path
            )];
        }

        $records = $result->value;
        if (!is_array($records) || ($records !== [] && !array_is_list($records))) {
            return ['ok' => false, 'records' => [], 'error' => sprintf(
                /* translators: 1: records path, 2: value type. */
                __('The records path "%1$s" is %2$s, not a list. Select an array, or add [*] to treat an object\'s values as records.', 'post-press-api'),
                $path,
                ValueInspector::type_label($records)
            )];
        }

        return ['ok' => true, 'records' => array_values($records), 'error' => ''];
    }

    /**
     * Compares the success flag with the expected value.
     *
     * @param mixed  $data     Response.
     * @param string $path     Flag path.
     * @param string $expected Expected value ("true", "false", or text).
     *
     * @return string|null Error, or null when the flag matches.
     */
    private function check_success(mixed $data, string $path, string $expected): ?string
    {
        $flag = $this->paths->resolve($data, $path);

        if (!$flag->is_present()) {
            return sprintf(
                /* translators: %s: success flag path. */
                __('The success flag "%s" is missing from the response, so nothing was imported.', 'post-press-api'),
                $path
            );
        }

        $expected_lower = strtolower(trim($expected));
        if ($expected_lower === 'true' || $expected_lower === 'false') {
            try {
                $matches = Transformer::to_bool($flag->value) === ($expected_lower === 'true');
            } catch (MappingError) {
                $matches = false;
            }
        } else {
            $matches = !is_array($flag->value) && Transformer::to_display_string($flag->value) === $expected;
        }

        if ($matches) {
            return null;
        }

        return sprintf(
            /* translators: 1: flag path, 2: actual value, 3: expected value. */
            __('The API reported a failure: "%1$s" is %2$s (expected %3$s). Nothing was imported.', 'post-press-api'),
            $path,
            ValueInspector::preview($flag->value, 40),
            $expected
        );
    }
}
