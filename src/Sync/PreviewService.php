<?php

declare(strict_types=1);

namespace PostPressAPI\Sync;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Http\ApiClient;
use PostPressAPI\Mapping\DestinationRegistry;
use PostPressAPI\Mapping\SourcePathDiscovery;
use PostPressAPI\Mapping\ValueInspector;
use PostPressAPI\Support\Redactor;

/**
 * Test Request and Preview/Dry Run.
 *
 * Both work on unsaved editor settings and either fetch the first page from the
 * API or use pasted sample JSON. Neither writes anything: no posts, meta,
 * attachments, tracking state, run state, or history. Output is bounded and
 * redacted.
 */
final class PreviewService
{
    /** @var int Records whose paths are discovered. */
    private const DISCOVERY_RECORDS = 5;

    /** @var int Records shown as raw samples. */
    private const SAMPLE_RECORDS = 3;

    /** @var int Records evaluated by a dry run. */
    public const DRY_RUN_RECORDS = 10;

    /** @var int Characters of pretty-printed JSON returned. */
    private const PREVIEW_CHARS = 20000;

    /** @var int Characters per sample record. */
    private const RECORD_CHARS = 4000;

    public function __construct(
        private readonly ApiClient $client,
        private readonly ResponseReader $reader,
        private readonly SourcePathDiscovery $discovery,
        private readonly RecordProcessor $processor,
        private readonly DestinationRegistry $destinations,
        private readonly Pagination $pagination
    ) {}

    /**
     * Fetches (or decodes) a sample and describes it, without importing.
     *
     * @param array<string, mixed> $preset      Normalized, validated preset (may be unsaved).
     * @param string|null          $secret      Credential allowed for this origin.
     * @param string|null          $sample_json Pasted JSON, used instead of the API when given.
     *
     * @return array<string, mixed>
     */
    public function test_request(array $preset, ?string $secret, ?string $sample_json): array
    {
        $redactor = new Redactor($secret === null ? [] : [$secret]);
        $loaded   = $this->load($preset, $secret, $sample_json, $redactor);

        if (!$loaded['ok']) {
            return $loaded;
        }

        $data   = $loaded['data'];
        $output = [
            'ok'             => true,
            'source'         => $loaded['source'],
            'http'           => $loaded['http'],
            'response_paths' => $this->discovery->discover_response($data),
            'preview'        => $this->pretty($data, self::PREVIEW_CHARS, $redactor),
            'records_ok'     => false,
            'records_error'  => '',
            'record_count'   => 0,
            'record_paths'   => [],
            'samples'        => [],
        ];

        if ($preset['response']['records_path'] === '') {
            $output['records_error'] = __('Enter a records path to see the records and their fields. Suggestions are listed under Response paths.', 'post-press-api');
            return $this->redact($output, $redactor);
        }

        $read = $this->reader->records($data, $preset);
        if (!$read['ok']) {
            $output['records_error'] = $read['error'];
            return $this->redact($output, $redactor);
        }

        $records = $read['records'];
        $samples = [];
        foreach (array_slice($records, 0, self::SAMPLE_RECORDS) as $record) {
            $samples[] = $this->pretty($record, self::RECORD_CHARS, $redactor);
        }

        $output['records_ok']   = true;
        $output['record_count'] = count($records);
        $output['record_paths'] = $this->discovery->discover_records($records, self::DISCOVERY_RECORDS);
        $output['samples']      = $samples;

        return $this->redact($output, $redactor);
    }

    /**
     * Evaluates mappings, matching, and change detection for the first records,
     * exactly as a run would, but without writing anything.
     *
     * @param array<string, mixed> $preset      Normalized, validated preset (may be unsaved).
     * @param string|null          $secret      Credential allowed for this origin.
     * @param string|null          $sample_json Pasted JSON, used instead of the API when given.
     *
     * @return array<string, mixed>
     */
    public function dry_run(array $preset, ?string $secret, ?string $sample_json): array
    {
        $redactor = new Redactor($secret === null ? [] : [$secret]);
        $loaded   = $this->load($preset, $secret, $sample_json, $redactor);

        if (!$loaded['ok']) {
            return $loaded;
        }

        $read = $this->reader->records($loaded['data'], $preset);
        if (!$read['ok']) {
            return ['ok' => false, 'source' => $loaded['source'], 'http' => $loaded['http'], 'error' => $read['error']];
        }

        $post_type = (string) $preset['destination']['post_type'];
        if (!$this->destinations->is_allowed($post_type)) {
            return ['ok' => false, 'source' => $loaded['source'], 'http' => $loaded['http'], 'error' => __('Choose a destination post type first.', 'post-press-api')];
        }

        $context = new RunContext(
            $preset,
            $this->destinations->catalog($post_type),
            wp_timezone(),
            Fingerprint::mapping_hash($preset),
            '',
            true
        );

        $records  = $read['records'];
        $outcomes = [];
        $counts   = array_fill_keys(RecordOutcome::ACTIONS, 0);

        foreach (array_slice($records, 0, self::DRY_RUN_RECORDS) as $index => $record) {
            $outcome = $this->processor->process($record, $index + 1, $context);
            $counts[$outcome->action]++;
            $outcomes[] = $this->redact($outcome->to_array(true), $redactor);
        }

        return [
            'ok'           => true,
            'source'       => $loaded['source'],
            'http'         => $loaded['http'],
            'record_count' => count($records),
            'evaluated'    => count($outcomes),
            'counts'       => $counts,
            'records'      => $outcomes,
            'note'         => count($records) > self::DRY_RUN_RECORDS || ($loaded['source'] === 'api' && $preset['pagination']['type'] !== 'none')
                ? sprintf(
                    /* translators: %d: number of records. */
                    __('The dry run covers the first %d records of the first page only. Nothing was written.', 'post-press-api'),
                    count($outcomes)
                )
                : __('Nothing was written.', 'post-press-api'),
        ];
    }

    /**
     * Loads data from pasted JSON or the first API page.
     *
     * @param array<string, mixed> $preset      Preset.
     * @param string|null          $secret      Credential.
     * @param string|null          $sample_json Pasted JSON.
     * @param Redactor             $redactor    Redactor.
     *
     * @return array{ok: bool, source: string, http: array<string, mixed>|null, data?: mixed, error?: string, snippet?: string}
     */
    private function load(array $preset, ?string $secret, ?string $sample_json, Redactor $redactor): array
    {
        if ($sample_json !== null) {
            $text = trim($sample_json);
            if (str_starts_with($text, "\xEF\xBB\xBF")) {
                $text = substr($text, 3);
            }
            $data = $text === '' ? null : json_decode($text, true, 512, JSON_BIGINT_AS_STRING);

            if ($text === '' || json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'ok'     => false,
                    'source' => 'sample',
                    'http'   => null,
                    'error'  => $text === ''
                        ? __('Paste sample JSON first.', 'post-press-api')
                        : sprintf(
                            /* translators: %s: JSON error. */
                            __('The sample is not valid JSON (%s).', 'post-press-api'),
                            json_last_error_msg()
                        ),
                ];
            }

            return ['ok' => true, 'source' => 'sample', 'http' => null, 'data' => $data];
        }

        if ((string) $preset['request']['url'] === '') {
            return ['ok' => false, 'source' => 'api', 'http' => null, 'error' => __('Enter the endpoint URL, or paste sample JSON to configure mappings offline.', 'post-press-api')];
        }

        $result = $this->client->fetch($preset, $secret, $this->pagination->params($preset, $this->pagination->start($preset)));
        if (!$result->ok) {
            return [
                'ok'      => false,
                'source'  => 'api',
                'http'    => $result->summary(),
                'error'   => $result->error,
                'snippet' => $redactor->text($result->snippet),
            ];
        }

        return ['ok' => true, 'source' => 'api', 'http' => $result->summary(), 'data' => $result->data];
    }

    /**
     * Pretty-printed, bounded, redacted JSON.
     *
     * @param mixed    $value    Value.
     * @param int      $limit    Characters.
     * @param Redactor $redactor Redactor.
     *
     * @return string
     */
    private function pretty(mixed $value, int $limit, Redactor $redactor): string
    {
        $json = (string) wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (ValueInspector::length($json) > $limit) {
            $json = ValueInspector::substr($json, 0, $limit) . "\n…";
        }

        return $redactor->text($json);
    }

    /**
     * Masks secrets anywhere in a response array (previews, discovered path
     * examples, mapping details), in case the API echoes a credential back.
     *
     * @param array<string, mixed> $data     Response data.
     * @param Redactor             $redactor Redactor.
     *
     * @return array<string, mixed>
     */
    private function redact(array $data, Redactor $redactor): array
    {
        array_walk_recursive($data, static function (&$value) use ($redactor): void {
            if (is_string($value)) {
                $value = $redactor->text($value);
            }
        });

        return $data;
    }
}
