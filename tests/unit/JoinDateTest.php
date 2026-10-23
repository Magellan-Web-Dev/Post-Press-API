<?php

declare(strict_types=1);

namespace PostPressAPI\Tests;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Mapping\FieldWrite;
use PostPressAPI\Mapping\MappingError;
use PostPressAPI\Mapping\MappingSchema;
use PostPressAPI\Mapping\Transformer;
use PostPressAPI\Sync\Fingerprint;

/**
 * Exercises date formatting inside Join through conversion, persistence, and imports.
 * WordPress, ACF, and HTTP are mocked by the shared test harness.
 */
final class JoinDateTest extends TestCase
{
    /** Registers the test post type and ACF fields for each isolated case. */
    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
    }

    /**
     * Formats a list using the same options passed by saved mappings.
     *
     * @param mixed $value Source list or scalar.
     * @param array<string, mixed> $options Overrides of the example format.
     * @return string Formatted and joined text.
     */
    private function join(mixed $value, array $options = []): string
    {
        return (new Transformer())->apply('join', $value, $options + [
            'join_datetime' => true, 'output_format' => 'F j', 'separator' => ', ',
        ], new \DateTimeZone('UTC'));
    }

    /**
     * Makes the user's three-session example.
     *
     * @return list<array<string, string>> Session dates and times.
     */
    private function sessions(): array
    {
        return array_map(static fn (string $date): array => [
            'date' => $date, 'time' => '18:30', 'time_12h' => '6:30 PM',
        ], ['2026-11-18', '2026-11-20', '2026-12-02']);
    }

    /** Produces the exact requested output while retaining order and duplicate dates. */
    public function testRequestedDatesAndCustomSeparators(): void
    {
        $dates = array_column($this->sessions(), 'date');
        $this->assertSame('November 18, November 20, December 2', $this->join($dates));
        $this->assertSame("Nov 18\nNov 20\nDec 2", $this->join($dates, ['separator' => '\n', 'output_format' => 'M j']));
        $this->assertSame('December 2, November 18, December 2', $this->join([$dates[2], $dates[0], $dates[2]]));
        $this->assertSame('November 18', $this->join('18/11/2026', ['input_format' => 'd/m/Y']));
    }

    /** Handles time lists, offsets, and date-only timezone invariance consistently. */
    public function testTimesOffsetsAndCalendarDays(): void
    {
        $this->assertSame('6:30 PM, 7:45 AM', $this->join(['18:30', '07:45'], ['input_format' => 'H:i', 'output_format' => 'g:i A']));
        $this->assertSame('November 18', $this->join(['2026-11-18'], [
            'input_timezone' => 'Pacific/Kiritimati', 'output_timezone' => 'America/Los_Angeles',
        ]));
        $this->assertSame('2026-11-17 17:30', $this->join(['2026-11-18T00:30:00Z'], [
            'output_format' => 'Y-m-d H:i', 'output_timezone' => 'America/Phoenix',
        ]));
        $this->assertSame('2026-11-18 23:30', $this->join(['2026-11-18 18:30'], [
            'input_timezone' => 'America/New_York', 'output_timezone' => 'UTC', 'output_format' => 'Y-m-d H:i',
        ]));
        $this->assertSame('2026-11-18, 18:30:00', $this->join(['2026-11-18', '18:30'], ['output_format' => '']));
    }

    /** Nulls are omitted, but invalid non-null items fail instead of being hidden. */
    public function testNullEmptyAndInvalidItems(): void
    {
        $this->assertSame('', $this->join([]));
        $this->assertSame('', $this->join([null, null]));
        $this->assertSame('November 18, December 2', $this->join(['2026-11-18', null, '2026-12-02']));
        foreach (['', '2026-02-30', 'tomorrow', 'not a date', false] as $bad) {
            $this->assertThrows(MappingError::class, fn () => $this->join(['2026-11-18', $bad]), 'Join item 2');
        }
        $this->assertThrows(MappingError::class, fn () => $this->join([['date' => '2026-11-18']]), 'Select a field');
        $this->assertThrows(MappingError::class, fn () => $this->join(['2026-11-18'], ['input_timezone' => 'Eastern']), 'IANA');
        $this->assertThrows(MappingError::class, fn () => $this->join(['2026-11-18'], ['output_timezone' => 'CST']), 'IANA');
    }

    /** Existing plain Join mappings and their historical fingerprints remain unchanged. */
    public function testOptOutRetainsPlainJoinAndOldFingerprints(): void
    {
        $row = $this->row('dates[*]', 'meta', 'dates_text', ['transform' => 'join']);
        $legacy = MappingSchema::normalize_rows([$row]);
        unset($legacy[0]['options']['join_datetime']);
        $normalized = MappingSchema::normalize_rows([$row]);
        $this->assertFalse($normalized[0]['options']['join_datetime']);
        $this->assertSame(MappingSchema::fingerprint_config($legacy), MappingSchema::fingerprint_config($normalized));
        $normalized[0]['options']['join_datetime'] = true;
        $this->assertFalse(MappingSchema::fingerprint_config($legacy) === MappingSchema::fingerprint_config($normalized));
        $this->assertSame('false, 0, plain', $this->join([false, 0, null, 'plain'], [
            'join_datetime' => false, 'input_timezone' => 'Eastern',
        ]));
    }

    /** Save-time validation checks time zones only while the date option is active. */
    public function testValidationOfJoinDateOptions(): void
    {
        $rows = MappingSchema::normalize_rows([$this->row('sessions[*].date', 'meta', 'dates_text', [
            'transform' => 'join', 'options' => ['join_datetime' => true, 'input_timezone' => 'Eastern', 'output_timezone' => 'CST'],
        ])]);
        $catalog = $this->services->destinations()->catalog('events');
        $this->assertCount(2, $this->services->mapping_validator()->validate($rows, $catalog)['errors']);
        $rows[0]['options']['join_datetime'] = false;
        $this->assertSame([], $this->services->mapping_validator()->validate($rows, $catalog)['errors']);
    }

    /** Wildcards work for core, meta, and ACF text destinations without writes during preview. */
    public function testPreviewAndImportWriteTheSameFormattedValues(): void
    {
        $settings = ['transform' => 'join', 'options' => ['join_datetime' => true, 'output_format' => 'F j']];
        $preset = $this->savePreset(['mappings' => [
            $this->row('sessions[*].date', 'core', 'post_title', $settings),
            $this->row('sessions[*].date', 'meta', 'joined_dates', $settings),
            $this->row('sessions[*].date', 'acf', 'field_city', $settings),
        ]]);
        $payload = ['ok' => true, 'data' => [['id' => 'session-dates', 'sessions' => $this->sessions()]]];
        $expected = 'November 18, November 20, December 2';
        $preview = $this->services->preview()->dry_run($preset, null, wp_json_encode($payload));
        $this->assertSame(1, $preview['counts']['created']);
        foreach ($preview['records'][0]['details'] as $detail) {
            $this->assertSame($expected, $detail['value']);
        }
        $this->assertSame([], \WPStub::$posts);
        $this->queueJson($payload);
        $this->assertSame(1, $this->runPreset($preset['id'])['counts']['created']);
        $post = $this->posts()[0];
        $this->assertSame($expected, $post['post_title']);
        $this->assertSame($expected, $this->meta((int) $post['ID'], 'joined_dates'));
        $this->assertSame($expected, $this->meta((int) $post['ID'], 'location_city'));
        $this->queueJson($payload);
        $this->assertSame(1, $this->runPreset($preset['id'])['counts']['unchanged']);
        $payload['data'][0]['sessions'][1]['date'] = 'invalid';
        $this->queueJson($payload);
        $this->assertSame(1, $this->runPreset($preset['id'])['counts']['failed']);
        $this->assertSame($expected, $this->meta((int) $post['ID'], 'joined_dates'));
    }

    /** Missing/null source policies and already-formatted fallbacks retain their meaning. */
    public function testMissingNullFallbackAndEmptyList(): void
    {
        $row = $this->row('sessions', 'meta', 'joined_dates', [
            'transform' => 'join', 'options' => ['join_datetime' => true, 'output_format' => 'F j'],
            'on_missing' => 'fallback', 'fallback' => 'Dates to be announced',
        ]);
        $evaluate = fn (array $record) => $this->services->engine()->evaluate(
            $record, MappingSchema::normalize_rows([$row]),
            $this->services->destinations()->catalog('events'), new \DateTimeZone('UTC'), true
        );
        $this->assertSame('Dates to be announced', $evaluate([])->writes()[0]->value);
        $this->assertSame([], $evaluate(['sessions' => null])->writes());
        $empty = $evaluate(['sessions' => []])->writes()[0];
        $this->assertSame(FieldWrite::SET, $empty->op);
        $this->assertSame('', $empty->value);
    }

    /** New exports retain options, while old exports without the flag still import. */
    public function testPresetExportCompatibilityAndNestedOptions(): void
    {
        $preset = $this->savePreset(['mappings' => [
            $this->row('sessions[*].date', 'meta', 'joined_dates', [
                'transform' => 'join', 'options' => ['join_datetime' => true, 'output_format' => 'F j', 'input_format' => 'Y-m-d'],
            ]),
            $this->row('$', 'acf', 'field_website', ['children' => [
                $this->row('sessions[*].date', 'acf', 'field_website_host', ['transform' => 'join']),
            ]]),
        ]]);
        $transfer = $this->services->transfer();
        $export = $transfer->export($preset['id']);
        $imported = $transfer->save($transfer->decode(wp_json_encode($export)), 'events', 1, []);
        $this->assertSame($preset['mappings'], $imported['mappings']);
        $this->assertSame(Fingerprint::mapping_hash($preset), Fingerprint::mapping_hash($imported));
        unset($export['preset']['mappings'][0]['options']['join_datetime']);
        unset($export['preset']['mappings'][1]['options']['join_datetime']);
        unset($export['preset']['mappings'][1]['children'][0]['options']['join_datetime']);
        $legacy = $transfer->save($transfer->decode(wp_json_encode($export)), 'events', 1, []);
        $this->assertFalse($legacy['mappings'][0]['options']['join_datetime']);
        $this->assertFalse($legacy['mappings'][1]['children'][0]['options']['join_datetime']);
        $export['preset']['mappings'][0]['options']['join_datetime'] = 'true';
        $this->assertThrows(\InvalidArgumentException::class, fn () => $transfer->decode(wp_json_encode($export)), 'join_datetime');
    }
}
