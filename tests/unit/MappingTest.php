<?php
/**
 * Transformations and mapping evaluation (no writes).
 */

declare(strict_types=1);

use PostPressAPI\Mapping\DateParser;
use PostPressAPI\Mapping\FieldWrite;
use PostPressAPI\Mapping\MappingError;
use PostPressAPI\Mapping\MappingSchema;
use PostPressAPI\Mapping\Transformer;
use PostPressAPI\Tests\TestCase;

final class MappingTest extends TestCase
{
    private Transformer $transformer;
    private \DateTimeZone $utc;

    public function setUp(): void
    {
        parent::setUp();
        $this->transformer = new Transformer();
        $this->utc         = new \DateTimeZone('UTC');
        $this->registerEvents();
    }

    private function evaluate(array $rows, mixed $record = null): \PostPressAPI\Mapping\MappedRecord
    {
        return $this->services->engine()->evaluate(
            $record ?? $this->fixture()['data'][0],
            MappingSchema::normalize_rows($rows),
            $this->services->destinations()->catalog('events'),
            new \DateTimeZone('America/Chicago'),
            true
        );
    }

    /** @return array<string, FieldWrite> */
    private function writes(\PostPressAPI\Mapping\MappedRecord $mapped): array
    {
        $writes = [];
        foreach ($mapped->writes() as $write) {
            $writes[$write->destination->key] = $write;
        }
        return $writes;
    }

    public function testScalarTransformations(): void
    {
        $this->assertSame('true', $this->transformer->apply('text', true, [], $this->utc));
        $this->assertSame(42, $this->transformer->apply('integer', '42', [], $this->utc));
        $this->assertSame(0, $this->transformer->apply('integer', 0, [], $this->utc));
        $this->assertSame(3.14, $this->transformer->apply('decimal', '3.14159', ['decimals' => '2'], $this->utc));
        $this->assertFalse($this->transformer->apply('boolean', 'no', [], $this->utc));
        $this->assertTrue($this->transformer->apply('boolean', 1, [], $this->utc));
        $this->assertSame('https://zoom.us/j/98765432100', $this->transformer->apply('url', 'https://zoom.us/j/98765432100', [], $this->utc));
        $this->assertSame('submitter@example.com', $this->transformer->apply('email', ' submitter@example.com ', [], $this->utc));
        $this->assertSame('["a",1,true,null]', $this->transformer->apply('json', ['a', 1, true, null], [], $this->utc));
    }

    public function testInvalidConversionsThrowInsteadOfGuessing(): void
    {
        $this->assertThrows(MappingError::class, fn () => $this->transformer->apply('integer', '1.5', [], $this->utc));
        $this->assertThrows(MappingError::class, fn () => $this->transformer->apply('integer', 'abc', [], $this->utc));
        $this->assertThrows(MappingError::class, fn () => $this->transformer->apply('boolean', 'maybe', [], $this->utc));
        $this->assertThrows(MappingError::class, fn () => $this->transformer->apply('url', 'javascript:alert(1)', [], $this->utc));
        $this->assertThrows(MappingError::class, fn () => $this->transformer->apply('email', 'not-an-email', [], $this->utc));
    }

    public function testArraysAreNeverSilentlyCastToArrayString(): void
    {
        $this->assertThrows(MappingError::class, fn () => $this->transformer->apply('text', ['a', 'b'], [], $this->utc));
        $this->assertThrows(MappingError::class, fn () => Transformer::stringify(['a'], $this->utc));

        $mapped = $this->evaluate([$this->row('sessions', 'core', 'post_title')]);
        $this->assertTrue($mapped->has_errors());
        $this->assertNotContains('Array', implode(' ', array_map(fn ($w) => (string) $w->value, $mapped->writes())));
    }

    public function testJoinUsesConfiguredSeparatorAndRejectsNestedArrays(): void
    {
        $this->assertSame("a\nb\n3", $this->transformer->apply('join', ['a', 'b', 3, null], ['separator' => '\\n'], $this->utc));
        $this->assertSame('a, b', $this->transformer->apply('join', ['a', 'b'], ['separator' => ', '], $this->utc));
        $this->assertThrows(MappingError::class, fn () => $this->transformer->apply('join', [['nested']], [], $this->utc));
    }

    public function testDateTimeUsesExplicitTimezoneAndNeverGuessesEastern(): void
    {
        $date = $this->transformer->apply('datetime', '2026-11-05 12:00', ['input_timezone' => 'America/New_York', 'output_timezone' => 'UTC'], $this->utc);
        $this->assertSame('2026-11-05 17:00:00', $date->format('Y-m-d H:i:s', $this->utc));

        $this->assertThrows(MappingError::class, fn () => DateParser::parse('Nov 5, 2026 at 12:00 PM Eastern', null, $this->utc));
        $this->assertThrows(MappingError::class, fn () => DateParser::parse('2026-11-05 12:00 CST', null, $this->utc));
        $this->assertThrows(MappingError::class, fn () => DateParser::parse('tomorrow', null, $this->utc));
        $this->assertThrows(MappingError::class, fn () => $this->transformer->apply('datetime', '2026-11-05', ['input_timezone' => 'Eastern'], $this->utc));

        $iso = DateParser::parse('2026-10-18T20:05:37.463391+00:00', null, $this->utc);
        $this->assertTrue($iso->has_offset);
        $this->assertSame('2026-10-18 15:05:37', $iso->format('Y-m-d H:i:s', new \DateTimeZone('America/Chicago')));

        $date_only = DateParser::parse('2026-11-05', null, new \DateTimeZone('Asia/Tokyo'));
        $this->assertSame('20261105', $date_only->format('Ymd', new \DateTimeZone('America/Los_Angeles')), 'date-only values are never shifted');
    }

    public function testValueMapConvertsOnlyListedValues(): void
    {
        $rows = [$this->row('status', 'core', 'post_status', [
            'transform' => 'map',
            'options'   => ['map' => [['from' => 'approved', 'to' => 'publish']], 'unmatched' => 'fallback'],
            'fallback'  => 'draft',
        ])];

        $received = $this->writes($this->evaluate($rows));
        $this->assertSame('draft', $received['post_status']->value, '"received" is not a WordPress status; the fallback applies');

        $approved = $this->writes($this->evaluate($rows, ['status' => 'approved']));
        $this->assertSame('publish', $approved['post_status']->value);

        $raw = $this->evaluate([$this->row('status', 'core', 'post_status')]);
        $this->assertTrue($raw->has_errors(), 'unmapped source status is rejected');
    }

    public function testMissingAndNullPreserveByDefault(): void
    {
        $mapped = $this->writes($this->evaluate([
            $this->row('location.city', 'acf', 'field_city'),
            $this->row('image', 'meta', 'event_image_url'),
            $this->row('does.not.exist', 'meta', 'missing_value'),
        ]));

        $this->assertSame([], $mapped, 'missing and null sources produce no writes');
    }

    public function testExplicitClearFallbackAndFailBehaviors(): void
    {
        $mapped = $this->evaluate([
            $this->row('image', 'meta', 'event_image_url', ['on_null' => 'clear']),
            $this->row('location.city', 'acf', 'field_city', ['on_null' => 'fallback', 'fallback' => 'Online']),
            $this->row('nope', 'meta', 'note', ['on_missing' => 'fallback', 'fallback' => '0']),
        ]);
        $writes = $this->writes($mapped);

        $this->assertSame(FieldWrite::CLEAR, $writes['event_image_url']->op);
        $this->assertSame('Online', $writes['field_city']->value);
        $this->assertSame('0', $writes['note']->value, 'a "0" fallback is a real value');

        $failing = $this->evaluate([$this->row('nope', 'core', 'post_title', ['on_missing' => 'fail'])]);
        $this->assertTrue($failing->has_errors());
    }

    public function testFalseZeroAndEmptyValuesAreWritten(): void
    {
        $record = ['flag' => false, 'count' => 0, 'text' => '', 'list' => []];
        $writes = $this->writes($this->evaluate([
            $this->row('flag', 'acf', 'field_virtual'),
            $this->row('count', 'meta', 'count'),
            $this->row('text', 'meta', 'text'),
            $this->row('list', 'acf', 'field_sessions', ['children' => [$this->row('date', 'acf', 'field_session_date', ['on_missing' => 'clear', 'on_null' => 'clear'])]]),
        ], $record));

        $this->assertSame(0, $writes['field_virtual']->value);
        $this->assertSame('0', $writes['count']->value);
        $this->assertSame('', $writes['text']->value);
        $this->assertSame([], $writes['field_sessions']->value, 'an empty source list empties the repeater');
    }

    public function testTemplatesAndConstants(): void
    {
        $writes = $this->writes($this->evaluate([
            $this->row('{event.title} – {website.host} {{x}}', 'core', 'post_title', ['source_type' => 'template']),
            $this->row('in_person', 'acf', 'field_mode', ['source_type' => 'constant']),
            $this->row('{location.city}', 'meta', 'where', ['source_type' => 'template', 'on_null' => 'fallback', 'fallback' => 'n/a']),
        ]));

        $this->assertSame('Medicare Open Enrollment Q&A (Virtual) – gces.us {x}', $writes['post_title']->value);
        $this->assertSame('in_person', $writes['field_mode']->value);
        $this->assertSame('n/a', $writes['where']->value, 'a null placeholder makes the template null');
    }

    public function testLastItemPathsValidateAndMapInTemplatesAndNestedAcfFields(): void
    {
        $rows = MappingSchema::normalize_rows([
            $this->row('products[-1].name', 'meta', 'last_product'),
            $this->row('Product: {products[-1].name}', 'core', 'post_title', ['source_type' => 'template']),
            $this->row('sites[-1]', 'acf', 'field_website', ['children' => [
                $this->row('hosts[-1]', 'acf', 'field_website_host'),
            ]]),
            $this->row('batches[-1].appointments', 'acf', 'field_sessions', ['children' => [
                $this->row('revisions[-1].date', 'acf', 'field_session_date', ['on_missing' => 'clear', 'on_null' => 'clear']),
                $this->row('revisions[-1].time', 'acf', 'field_session_time', ['on_missing' => 'clear', 'on_null' => 'clear']),
            ]]),
        ]);
        $validation = $this->services->mapping_validator()->validate($rows, $this->services->destinations()->catalog('events'));
        $this->assertSame([], $validation['errors']);

        $record = [
            'products' => [['name' => 'First'], ['name' => 'Last']],
            'sites' => [['hosts' => ['old.example.test', 'last.example.test']]],
            'batches' => [
                ['appointments' => []],
                ['appointments' => [
                    ['revisions' => [['date' => '2026-11-01', 'time' => '10:00'], ['date' => '2026-11-05', 'time' => '12:00']]],
                    ['revisions' => [['date' => '2026-11-12', 'time' => '18:30']]],
                ]],
            ],
        ];
        $mapped = $this->evaluate($rows, $record);
        $this->assertFalse($mapped->has_errors(), implode(' ', $mapped->errors()));
        $writes = $this->writes($mapped);
        $this->assertSame('Last', $writes['last_product']->value);
        $this->assertSame('Product: Last', $writes['post_title']->value);
        $this->assertSame(['field_website_host' => 'last.example.test'], $writes['field_website']->value);
        $this->assertSame([
            ['field_session_date' => '20261105', 'field_session_time' => '12:00:00'],
            ['field_session_date' => '20261112', 'field_session_time' => '18:30:00'],
        ], $writes['field_sessions']->value);
    }

    public function testLastItemMappingsKeepMissingAndNullPoliciesDistinct(): void
    {
        $record = ['empty' => [], 'nullable' => ['earlier', null]];
        $mapped = $this->evaluate([
            $this->row('empty[-1]', 'meta', 'preserved_missing'),
            $this->row('nullable[-1]', 'meta', 'preserved_null'),
            $this->row('empty[-1]', 'meta', 'fallback', ['on_missing' => 'fallback', 'fallback' => 'No items', 'on_null' => 'fail']),
            $this->row('{nullable[-1]}', 'meta', 'cleared', ['source_type' => 'template', 'on_null' => 'clear', 'on_missing' => 'fail']),
        ], $record);
        $this->assertFalse($mapped->has_errors(), implode(' ', $mapped->errors()));
        $writes = $this->writes($mapped);
        $this->assertCount(2, $writes);
        $this->assertSame('No items', $writes['fallback']->value);
        $this->assertSame(FieldWrite::CLEAR, $writes['cleared']->op);
        $this->assertTrue($this->evaluate([$this->row('empty[-1]', 'meta', 'required', ['on_missing' => 'fail'])], $record)->has_errors());
        $this->assertTrue($this->evaluate([$this->row('nullable[-1]', 'meta', 'required', ['on_null' => 'fail'])], $record)->has_errors());
    }

    public function testLastObjectStillNeedsACompatibleDestinationOrTransformation(): void
    {
        $record = ['products' => [['name' => 'First'], ['name' => 'Last']]];
        $this->assertTrue($this->evaluate([$this->row('products[-1]', 'meta', 'last_product')], $record)->has_errors());
        $this->assertTrue($this->evaluate([$this->row('{products[-1]}', 'core', 'post_title', ['source_type' => 'template'])], $record)->has_errors());
        $mapped = $this->evaluate([$this->row('products[-1]', 'meta', 'last_product', ['transform' => 'json'])], $record);
        $this->assertFalse($mapped->has_errors());
        $this->assertSame('{"name":"Last"}', $this->writes($mapped)['last_product']->value);
    }

    public function testAcfTypeNormalization(): void
    {
        $writes = $this->writes($this->evaluate($this->exampleMappings()));

        $this->assertSame(1, $writes['field_virtual']->value);
        $this->assertSame('https://zoom.us/j/98765432100', $writes['field_meeting']->value);
        $this->assertSame('submitter@example.com', $writes['field_email']->value);
        $this->assertSame('20261105', $writes['field_first_date']->value);
        $this->assertSame([['field_session_date' => '20261105', 'field_session_time' => '12:00:00']], $writes['field_sessions']->value);
        $this->assertSame('Nov 5, 2026 at 12:00 PM Eastern', $writes['event_dates_text']->value);
    }

    public function testSelectChoicesAcceptKeysOrLabelsOnly(): void
    {
        $this->assertSame('in_person', $this->writes($this->evaluate([$this->row('m', 'acf', 'field_mode')], ['m' => 'In person']))['field_mode']->value);
        $this->assertTrue($this->evaluate([$this->row('m', 'acf', 'field_mode')], ['m' => 'hybrid'])->has_errors());
    }

    public function testRepeaterKeepsEachSessionsDateAndTimeTogether(): void
    {
        $record = ['sessions' => [
            ['date' => '2026-11-05', 'time' => '12:00'],
            ['date' => '2026-11-12', 'time' => '6:30 PM'],
            ['time' => '09:15', 'date' => '2026-12-01'],
        ]];
        $writes = $this->writes($this->evaluate([$this->exampleMappings()[6]], $record));

        $this->assertSame([
            ['field_session_date' => '20261105', 'field_session_time' => '12:00:00'],
            ['field_session_date' => '20261112', 'field_session_time' => '18:30:00'],
            ['field_session_date' => '20261201', 'field_session_time' => '09:15:00'],
        ], $writes['field_sessions']->value);
    }

    public function testRepeaterSkipRowAndMissingSubValues(): void
    {
        $record = ['sessions' => [['date' => '2026-11-05', 'time' => '12:00'], ['time' => '13:00'], ['date' => '2026-11-07']]];
        $writes = $this->writes($this->evaluate([$this->row('sessions', 'acf', 'field_sessions', ['children' => [
            $this->row('date', 'acf', 'field_session_date', ['on_missing' => 'skip_row', 'on_null' => 'skip_row']),
            $this->row('time', 'acf', 'field_session_time', ['on_missing' => 'clear', 'on_null' => 'clear']),
        ]])], $record));

        $this->assertSame([
            ['field_session_date' => '20261105', 'field_session_time' => '12:00:00'],
            ['field_session_date' => '20261107', 'field_session_time' => ''],
        ], $writes['field_sessions']->value);
    }

    public function testGroupMapsNestedKeysToSubFields(): void
    {
        $writes = $this->writes($this->evaluate([$this->row('website', 'acf', 'field_website', ['children' => [
            $this->row('host', 'acf', 'field_website_host'),
            $this->row('advisor', 'acf', 'field_website_advisor'),
        ]])]));

        $this->assertSame(['field_website_host' => 'gces.us', 'field_website_advisor' => 'Galen Bargerstock'], $writes['field_website']->value);
    }

    public function testUnsupportedAndUnknownDestinationsAreRejected(): void
    {
        $this->assertTrue($this->evaluate([$this->row('id', 'acf', 'field_image')])->has_errors(), 'image fields require an attachment ID or download option');
        $this->assertTrue($this->evaluate([$this->row('id', 'acf', 'field_session_date')])->has_errors(), 'sub fields cannot be mapped at the top level');
        $this->assertTrue($this->evaluate([$this->row('id', 'acf', 'field_not_registered')])->has_errors());
        $this->assertTrue($this->evaluate([$this->row('id', 'core', 'ID')])->has_errors(), 'post IDs are never writable');
        $this->assertTrue($this->evaluate([$this->row('id', 'core', 'post_type')])->has_errors());
    }

    public function testMetaKeyPolicyBlocksControlAndAcfKeys(): void
    {
        $policy = $this->services->meta_policy();
        foreach (['_edit_lock', '_thumbnail_id', '_wp_page_template', '_ppapi_key', '_ppapi_anything', 'is_virtual', '_is_virtual', 'sessions_0_session_date', 'website_host', 'bad key'] as $key) {
            $this->assertNotNull($policy->check($key, 'events'), $key . ' must be blocked');
        }
        $this->assertNull($policy->check('event_dates_text', 'events'));
        $this->assertNull($policy->check('_price', 'events'));
    }

    public function testValidatorReportsRowProblems(): void
    {
        $result = $this->services->mapping_validator()->validate(MappingSchema::normalize_rows([
            $this->row('a..b', 'core', 'post_title'),
            $this->row('x', 'core', 'post_title'),
            $this->row('status', 'core', 'post_status'),
            $this->row('sessions', 'acf', 'field_sessions', ['children' => [$this->row('date', 'acf', 'field_session_date')]]),
            $this->row('x', 'core', 'post_name', ['on_missing' => 'clear']),
        ]), $this->services->destinations()->catalog('events'));

        $errors = implode("\n", $result['errors']);
        $this->assertContains('Mapping 1', $errors);
        $this->assertContains('already mapped', $errors);
        $this->assertContains('Value map', $errors);
        $this->assertContains('repeater rows are rebuilt', $errors);
        $this->assertContains('cannot be cleared', $errors);
    }

    public function testRepeaterUnavailableWithoutAcfPro(): void
    {
        \AcfStub::$types = array_values(array_diff(\AcfStub::$types, ['repeater']));
        $this->services->acf_fields()->reset();

        $mapped = $this->evaluate([$this->exampleMappings()[6]]);
        $this->assertTrue($mapped->has_errors());
        $this->assertContains('ACF PRO', implode(' ', $mapped->errors()));
    }
}
