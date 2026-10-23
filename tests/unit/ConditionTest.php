<?php

declare(strict_types=1);

namespace PostPressAPI\Tests;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Mapping\FieldWrite;
use PostPressAPI\Mapping\MappedRecord;
use PostPressAPI\Mapping\MappingCondition;
use PostPressAPI\Mapping\MappingSchema;
use PostPressAPI\Sync\Fingerprint;

/**
 * Conditional mapping behavior through validation, previews, imports, and transfer.
 * HTTP, WordPress, and ACF storage use the existing isolated test harness.
 */
final class ConditionTest extends TestCase
{
    /** Registers ordinary fields plus List Items and a group inside a repeater. */
    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
        \AcfStub::$types[] = 'list_items';
        \AcfStub::add_group('group_conditions', 'Conditional fields', ['events'], [
            ['key' => 'field_list', 'name' => 'date_options', 'type' => 'list_items'],
            ['key' => 'field_nested', 'name' => 'nested', 'type' => 'repeater', 'sub_fields' => [
                ['key' => 'field_nested_group', 'name' => 'details', 'type' => 'group', 'sub_fields' => [
                    ['key' => 'field_nested_text', 'name' => 'text', 'type' => 'text'],
                ]],
            ]],
        ]);
    }

    /**
     * Builds a complete enabled condition for tests.
     *
     * @param string $operator Count comparison or literal text operation.
     * @param string $value Comparison operand.
     * @param string $action Action on a match.
     * @return array<string, mixed> Condition settings.
     */
    private function condition(string $operator = 'count_eq', string $value = '1', string $action = 'preserve'): array
    {
        return ['enabled' => true, 'operator' => $operator, 'value' => $value, 'action' => $action] + MappingCondition::DEFAULTS;
    }

    /**
     * Evaluates one conditional mapping against a record without writes.
     *
     * @param array<string, mixed> $record Source record.
     * @param array<string, mixed> $condition Condition settings.
     * @param array<string, mixed> $overrides Mapping overrides.
     * @return MappedRecord Writes, errors, and preview details.
     */
    private function evaluate(array $record, array $condition, array $overrides = []): MappedRecord
    {
        return $this->evaluateRows($record, [$this->row('value', 'meta', 'output', $overrides + ['condition' => $condition])]);
    }

    /**
     * Resolves complete mapping rows through the production engine.
     *
     * @param array<string, mixed> $record Source record.
     * @param list<array<string, mixed>> $rows Mapping settings.
     * @return MappedRecord Evaluation result with preview details.
     */
    private function evaluateRows(array $record, array $rows): MappedRecord
    {
        return $this->services->engine()->evaluate($record, MappingSchema::normalize_rows($rows),
            $this->services->destinations()->catalog('events'), new \DateTimeZone('UTC'), true);
    }

    /**
     * Runs one event through the normal import lifecycle with mocked HTTP.
     *
     * @param array<string, mixed> $preset Saved preset.
     * @param array<string, mixed> $record Record data.
     * @return array<string, mixed> Final run state.
     */
    private function import(array $preset, array $record): array
    {
        $this->queueJson(['ok' => true, 'data' => [$record + ['id' => 'conditional-event']]]);
        return $this->runPreset($preset['id']);
    }

    /** Count rules cover zero, exact thresholds, both sides, duplicates, and blanks. */
    public function testAllCountOperatorsUseRawListLength(): void
    {
        $expectations = [
            'count_eq' => [false, true, false], 'count_ne' => [true, false, true],
            'count_lt' => [true, false, false], 'count_lte' => [true, true, false],
            'count_gt' => [false, false, true], 'count_gte' => [false, true, true],
        ];
        foreach ($expectations as $operator => $matches) {
            foreach ([[], ['A'], ['A', 'A', '']] as $index => $value) {
                $mapped = $this->evaluate(['value' => $value], $this->condition($operator), ['transform' => 'join']);
                $this->assertFalse($mapped->has_errors());
                $this->assertSame($matches[$index] ? 0 : 1, count($mapped->writes()), $operator);
                $this->assertContains($matches[$index] ? 'Condition matched' : 'Condition did not match', $mapped->details()[0]['note']);
            }
        }
        $zero = $this->evaluate(['value' => []], $this->condition('count_eq', '0', 'clear'));
        $this->assertSame(FieldWrite::CLEAR, $zero->writes()[0]->op);
    }

    /** Text rules are literal, Unicode aware, and optionally case sensitive. */
    public function testTextMatchingAndCaseSensitivity(): void
    {
        foreach (['CANCELLED event', 'Meeting Cancelled'] as $value) {
            $this->assertSame([], $this->evaluate(['value' => $value], $this->condition('contains', 'cancelled'))->writes());
        }
        $rule = $this->condition('contains', 'cancelled');
        $rule['case_sensitive'] = true;
        $this->assertSame('CANCELLED event', $this->evaluate(['value' => 'CANCELLED event'], $rule)->writes()[0]->value);
        $this->assertSame([], $this->evaluate(['value' => 'CAFÉ'], $this->condition('contains', 'café'))->writes());
        $this->assertSame([], $this->evaluate(['value' => 'Price? [.*]/'], $this->condition('contains', '[.*]/'))->writes());
        $this->assertCount(1, $this->evaluate(['value' => 'anything'], $this->condition('contains', '.*'))->writes());
        $this->assertSame([], $this->evaluate(['value' => 'anything'], $this->condition('not_contains', '?'))->writes());
        $this->assertCount(1, $this->evaluate(['value' => 'anything?'], $this->condition('not_contains', '?'))->writes());
        $this->assertSame([], $this->evaluate(['value' => ''], $this->condition('not_contains', '?'))->writes());
        $this->assertSame([], $this->evaluate(['value' => '0'], $this->condition('contains', '0'))->writes());
    }

    /** A byte-length limit must not let truncated Unicode become an invalid regex. */
    public function testTruncatedUnicodeConditionFailsValidationWithoutWarnings(): void
    {
        $condition = $this->condition('contains', str_repeat('a', MappingSchema::MAX_TEXT - 1) . 'é');
        $rows = MappingSchema::normalize_rows([$this->row('value', 'meta', 'output', ['condition' => $condition])]);
        $checked = $this->services->mapping_validator()->validate($rows, $this->services->destinations()->catalog('events'));
        $this->assertContains('UTF-8', implode(' ', $checked['errors']));
        $mapped = $this->evaluate(['value' => 'Ordinary text'], $condition);
        $this->assertTrue($mapped->has_errors());
        $this->assertContains('UTF-8', $mapped->details()[0]['note']);
    }

    /** A matching condition avoids a transformation that would otherwise fail. */
    public function testConditionsRunBeforeTransformationsAndContainers(): void
    {
        $mapped = $this->evaluate(['value' => 'Cancelled'], $this->condition('contains', 'cancelled'), ['transform' => 'datetime']);
        $this->assertFalse($mapped->has_errors());
        $this->assertSame([], $mapped->writes());
        $this->assertTrue($this->evaluate(['value' => 'Cancelled'], $this->condition('contains', 'active'), ['transform' => 'datetime'])->has_errors());
        $container = $this->evaluate(['value' => [['date' => 'invalid']]], $this->condition(), [
            'target_type' => 'acf', 'target' => 'field_sessions', 'children' => [
                $this->row('date', 'acf', 'field_session_date', ['on_missing' => 'clear', 'on_null' => 'clear']),
            ],
        ]);
        $this->assertFalse($container->has_errors());
        $this->assertSame([], $container->writes());
    }

    /** Present values with the wrong type fail rather than coercing or matching. */
    public function testWrongSourceTypesFailTheMapping(): void
    {
        foreach (['1', false, 1, ['named' => 'entry']] as $value) {
            $this->assertTrue($this->evaluate(['value' => $value], $this->condition())->has_errors());
        }
        foreach ([['text'], [], false, 0] as $value) {
            $this->assertTrue($this->evaluate(['value' => $value], $this->condition('not_contains', 'x'))->has_errors());
        }
    }

    /** Missing/null sources retain Preserve, Clear, Fallback, and Fail behavior. */
    public function testMissingAndNullKeepExistingPoliciesAndBypassConditions(): void
    {
        $condition = $this->condition('contains', 'fallback', 'clear');
        foreach ([[], ['value' => null]] as $record) {
            $this->assertSame([], $this->evaluate($record, $condition)->writes());
            $this->assertSame(FieldWrite::CLEAR, $this->evaluate($record, $condition, ['on_missing' => 'clear', 'on_null' => 'clear'])->writes()[0]->op);
            $fallback = $this->evaluate($record, $condition, ['on_missing' => 'fallback', 'on_null' => 'fallback', 'fallback' => 'fallback']);
            $this->assertSame('fallback', $fallback->writes()[0]->value);
            $this->assertNotContains('Condition matched', $fallback->details()[0]['note']);
            $this->assertTrue($this->evaluate($record, $condition, ['on_missing' => 'fail', 'on_null' => 'fail'])->has_errors());
        }
    }

    /** Constant and expanded template values can be checked as text. */
    public function testConstantsAndTemplatesUseTheirResolvedText(): void
    {
        $rule = $this->condition('contains', 'cancelled');
        $this->assertSame([], $this->evaluate([], $rule, ['source_type' => 'constant', 'source' => 'Cancelled'])->writes());
        $this->assertSame([], $this->evaluate(['status' => 'Cancelled'], $rule, ['source_type' => 'template', 'source' => 'Event: {status}'])->writes());
        $this->assertSame([], $this->evaluate([], $rule, ['source_type' => 'template', 'source' => 'Event: {status}'])->writes());
    }

    /** Settings and action restrictions are checked at save time and runtime. */
    public function testValidationRejectsInvalidOperandsOperatorsAndActions(): void
    {
        $invalid = [
            $this->condition('count_eq', '-1'), $this->condition('count_eq', '1.5'),
            $this->condition('count_eq', ''), $this->condition('count_eq', '999999999999999999999999999'),
            $this->condition('contains', ''), $this->condition('execute', 'x'),
            $this->condition('count_eq', '1', 'delete_post'), $this->condition('count_eq', '1', 'skip_row'),
        ];
        foreach ($invalid as $condition) {
            $rows = MappingSchema::normalize_rows([$this->row('value', 'meta', 'output', ['condition' => $condition])]);
            $checked = $this->services->mapping_validator()->validate($rows, $this->services->destinations()->catalog('events'));
            $this->assertTrue($checked['errors'] !== []);
            $this->assertTrue($this->evaluate(['value' => ['A']], $condition)->has_errors());
        }
        $slug = $this->row('value', 'core', 'post_name', ['condition' => $this->condition('contains', '?', 'clear')]);
        $checked = $this->services->mapping_validator()->validate(MappingSchema::normalize_rows([$slug]), $this->services->destinations()->catalog('events'));
        $this->assertContains('cannot be cleared', implode(' ', $checked['errors']));
    }

    /** Disabled rules cannot affect writes, validation, or historical fingerprints. */
    public function testDisabledConditionsPreserveHistoricalBehaviorAndFingerprints(): void
    {
        $row = $this->row('value', 'meta', 'output', ['id' => 'fixed']);
        $normalized = MappingSchema::normalize_rows([$row]);
        $old = $normalized;
        unset($old[0]['condition']);
        $this->assertSame(MappingSchema::fingerprint_config($old), MappingSchema::fingerprint_config($normalized));
        $disabled = $this->condition('invalid', '', 'invalid');
        $disabled['enabled'] = false;
        $current = $this->evaluate(['value' => 'Unchanged'], $disabled);
        $legacy = $this->evaluateRows(['value' => 'Unchanged'], [$row]);
        $this->assertSame($legacy->fingerprint_payload(), $current->fingerprint_payload());
        $this->assertSame('Unchanged', $current->writes()[0]->value);
        $this->assertSame('', $current->details()[0]['note']);
        $normalized[0]['condition'] = $disabled;
        $this->assertSame(MappingSchema::fingerprint_config($old), MappingSchema::fingerprint_config($normalized));
        $normalized[0]['condition'] = $this->condition();
        $this->assertNotSame(MappingSchema::fingerprint_config($old), MappingSchema::fingerprint_config($normalized));
    }

    /** Clear removes previous list values, while Preserve retains them on updates. */
    public function testImportClearPreserveAndRestoreListsOnTheSamePost(): void
    {
        $preset = $this->savePreset(['mappings' => [
            $this->row('title', 'core', 'post_title'),
            $this->row('dates_text', 'acf', 'field_list', ['condition' => $this->condition('count_eq', '1', 'clear')]),
        ]]);
        $this->assertSame(1, $this->import($preset, ['title' => 'First', 'dates_text' => ['A', 'B', 'C']])['counts']['created']);
        $id = (int) $this->posts()[0]['ID'];
        update_post_meta($id, 'local_note', 'Keep');
        $one = ['title' => 'Updated', 'dates_text' => ['A']];
        $this->assertSame(1, $this->import($preset, $one)['counts']['updated']);
        $this->assertFalse(metadata_exists('post', $id, 'date_options'));
        $this->assertSame('Updated', $this->posts()[0]['post_title']);
        $this->assertSame('Keep', $this->meta($id, 'local_note'));
        $this->assertSame(1, $this->import($preset, $one)['counts']['unchanged']);
        $this->import($preset, ['dates_text' => ['A', 'B']]);
        $this->assertSame(['A', 'B'], $this->meta($id, 'date_options'));
        $preset['mappings'][1]['condition']['action'] = 'preserve';
        $preset = $this->services->presets()->save($preset, 1);
        $this->import($preset, ['dates_text' => ['A']]);
        $this->assertSame(['A', 'B'], $this->meta($id, 'date_options'));
        $this->assertCount(1, $this->posts());
    }

    /** New records still import other fields when a condition suppresses one mapping. */
    public function testNewRecordLeavesSuppressedFieldUnsetAndExplainsPreview(): void
    {
        $preset = $this->savePreset(['mappings' => [
            $this->row('title', 'core', 'post_title'),
            $this->row('value', 'meta', 'output', ['condition' => $this->condition('contains', '?')]),
        ]]);
        $payload = ['ok' => true, 'data' => [['id' => 'conditional-event', 'title' => 'Event', 'value' => 'TBD?']]];
        $preview = $this->services->preview()->dry_run($preset, null, wp_json_encode($payload));
        $detail = $preview['records'][0]['details'][1];
        $this->assertSame('preserve', $detail['result']);
        $this->assertSame('condition', $detail['via']);
        $this->assertContains('Condition matched', $detail['note']);
        $this->assertSame([], \WPStub::$posts);
        $this->assertSame(1, $this->import($preset, $payload['data'][0])['counts']['created']);
        $id = (int) $this->posts()[0]['ID'];
        $this->assertSame('Event', $this->posts()[0]['post_title']);
        $this->assertFalse(metadata_exists('post', $id, 'output'));
    }

    /** Editing a rule re-evaluates unchanged source data through change detection. */
    public function testChangedConditionReappliesAnUnchangedSource(): void
    {
        $preset = $this->savePreset(['mappings' => [$this->row('value', 'meta', 'output')]]);
        $record = ['value' => 'Cancelled'];
        $this->import($preset, $record);
        $id = (int) $this->posts()[0]['ID'];
        $preset['mappings'][0]['condition'] = $this->condition('contains', 'Cancelled', 'clear');
        $preset = $this->services->presets()->save($preset, 1);
        $this->assertSame(1, $this->import($preset, $record)['counts']['updated']);
        $this->assertFalse(metadata_exists('post', $id, 'output'));
        $preset['mappings'][0]['condition']['enabled'] = false;
        $preset = $this->services->presets()->save($preset, 1);
        $this->assertSame(1, $this->import($preset, $record)['counts']['updated']);
        $this->assertSame('Cancelled', $this->meta($id, 'output'));
    }

    /** A present source of the wrong type fails before any mapped field is written. */
    public function testInvalidConditionSourceDoesNotPartiallyUpdateAPost(): void
    {
        $preset = $this->savePreset(['mappings' => [
            $this->row('title', 'core', 'post_title'),
            $this->row('value', 'meta', 'output', ['condition' => $this->condition('contains', '?')]),
        ]]);
        $this->import($preset, ['title' => 'Original', 'value' => 'Original']);
        $id = (int) $this->posts()[0]['ID'];
        $this->assertSame(1, $this->import($preset, ['title' => 'Wrong', 'value' => []])['counts']['failed']);
        $this->assertSame('Original', $this->posts()[0]['post_title']);
        $this->assertSame('Original', $this->meta($id, 'output'));
    }

    /** Matched image conditions avoid validation/downloads and clear only their field. */
    public function testSuppressedImageMappingNeverDownloads(): void
    {
        $preset = $this->savePreset(['mappings' => [$this->row('image', 'meta', 'image_url', [
            'media' => ['download' => true, 'output' => 'url'],
            'condition' => $this->condition('contains', 'placeholder', 'clear'),
        ])]]);
        $this->assertSame(1, $this->import($preset, ['image' => 'placeholder'])['counts']['created']);
        $this->assertCount(1, \WPStub::$http_log, 'Only the record request runs; no image fetch.');
        $this->assertSame([], $this->posts('attachment'));
        $id = (int) $this->posts()[0]['ID'];
        $this->assertFalse(metadata_exists('post', $id, 'image_url'));
    }

    /** Group subfields use relative source values and preserve unmapped siblings. */
    public function testGroupConditionsApplyToOnlyTheirSubfield(): void
    {
        $preset = $this->savePreset(['mappings' => [$this->row('site', 'acf', 'field_website', ['children' => [
            $this->row('host', 'acf', 'field_website_host', ['condition' => $this->condition('contains', '?', 'clear')]),
        ]])]]);
        $this->import($preset, ['site' => ['host' => 'Original']]);
        $id = (int) $this->posts()[0]['ID'];
        update_post_meta($id, 'website_advisor', 'Local advisor');
        $this->import($preset, ['site' => ['host' => 'Unknown?']]);
        $this->assertSame('', $this->meta($id, 'website_host'));
        $this->assertSame('Local advisor', $this->meta($id, 'website_advisor'));
    }

    /** Repeater rules clear fields or skip rows before incompatible date conversion. */
    public function testRepeaterConditionsSkipRowsOrClearFields(): void
    {
        $rows = [$this->row('sessions', 'acf', 'field_sessions', ['children' => [
            $this->row('date', 'acf', 'field_session_date', ['on_missing' => 'clear', 'on_null' => 'clear',
                'condition' => $this->condition('contains', 'Cancelled', 'skip_row')]),
            $this->row('time', 'acf', 'field_session_time', ['on_missing' => 'clear', 'on_null' => 'clear']),
        ]])];
        $preset = $this->savePreset(['mappings' => $rows]);
        $this->assertSame(1, $this->import($preset, ['sessions' => [
            ['date' => 'Cancelled', 'time' => 'invalid'], ['date' => '2026-11-18', 'time' => '18:30'],
        ]])['counts']['created']);
        $id = (int) $this->posts()[0]['ID'];
        $this->assertSame('1', $this->meta($id, 'sessions'));
        $this->assertSame('20261118', $this->meta($id, 'sessions_0_session_date'));
        $rows[0]['children'][0]['condition']['action'] = 'clear';
        $mapped = $this->evaluateRows(['sessions' => [['date' => 'Cancelled', 'time' => '18:30']]], $rows);
        $this->assertSame('', $mapped->writes()[0]->value[0]['field_session_date']);
        $this->assertContains('Condition matched', $mapped->details()[0]['children'][0]['note']);
        $rows[0]['children'][0]['condition']['action'] = 'preserve';
        $checked = $this->services->mapping_validator()->validate(MappingSchema::normalize_rows($rows), $this->services->destinations()->catalog('events'));
        $this->assertContains('cannot keep', implode(' ', $checked['errors']));
    }

    /** Nested groups carry condition restrictions and row skipping to their repeater. */
    public function testGroupInsideRepeaterCannotPreserveAndCanSkipItsRow(): void
    {
        $rows = [$this->row('rows', 'acf', 'field_nested', ['children' => [
            $this->row('details', 'acf', 'field_nested_group', ['on_missing' => 'clear', 'on_null' => 'clear', 'children' => [
                $this->row('text', 'acf', 'field_nested_text', ['condition' => $this->condition('contains', '?', 'skip_row')]),
            ]]),
        ]])];
        $data = ['rows' => [['details' => ['text' => '?']], ['details' => ['text' => 'Keep']]]];
        $checked = $this->services->mapping_validator()->validate(MappingSchema::normalize_rows($rows), $this->services->destinations()->catalog('events'));
        $this->assertSame([], $checked['errors']);
        $mapped = $this->evaluateRows($data, $rows);
        $this->assertFalse($mapped->has_errors());
        $this->assertSame([['field_nested_group' => ['field_nested_text' => 'Keep']]], $mapped->writes()[0]->value);
        $this->assertContains('Condition matched', $mapped->details()[0]['children'][0]['children'][0]['note']);
        $rows[0]['children'][0]['children'][0]['condition']['action'] = 'preserve';
        $checked = $this->services->mapping_validator()->validate(MappingSchema::normalize_rows($rows), $this->services->destinations()->catalog('events'));
        $this->assertContains('cannot keep', implode(' ', $checked['errors']));
        $this->assertTrue($this->evaluateRows($data, $rows)->has_errors());
    }

    /** Conditions survive transfers and legacy exports may omit them at every depth. */
    public function testPresetRoundTripAndLegacyExports(): void
    {
        $preset = $this->savePreset(['mappings' => [
            $this->row('value', 'meta', 'output', ['condition' => $this->condition('contains', '?', 'clear')]),
            $this->row('site', 'acf', 'field_website', ['children' => [
                $this->row('host', 'acf', 'field_website_host', ['condition' => $this->condition('contains', 'Cancelled')]),
            ]]),
        ]]);
        $transfer = $this->services->transfer();
        $export = $transfer->export($preset['id']);
        $copy = $transfer->save($transfer->decode(wp_json_encode($export)), 'events', 1, []);
        $this->assertSame($preset['mappings'], $copy['mappings']);
        $this->assertSame(Fingerprint::mapping_hash($preset), Fingerprint::mapping_hash($copy));
        $strip = static function (array &$rows) use (&$strip): void {
            foreach ($rows as &$row) {
                unset($row['condition']);
                $strip($row['children']);
            }
            unset($row);
        };
        $strip($export['preset']['mappings']);
        $legacy = $transfer->save($transfer->decode(wp_json_encode($export)), 'events', 1, []);
        $this->assertFalse($legacy['mappings'][0]['condition']['enabled']);
        $this->assertFalse($legacy['mappings'][1]['children'][0]['condition']['enabled']);
        $this->assertSame(MappingSchema::fingerprint_config($export['preset']['mappings']), MappingSchema::fingerprint_config($legacy['mappings']));
    }

    /** Malformed transfer conditions cannot silently change type or lose settings. */
    public function testMalformedExportConditionsAreRejected(): void
    {
        $preset = $this->savePreset();
        $transfer = $this->services->transfer();
        foreach (['bad', null, [], ['enabled' => true]] as $bad) {
            $data = $transfer->export($preset['id']);
            $data['preset']['mappings'][0]['condition'] = $bad;
            $this->assertThrows(\InvalidArgumentException::class, fn () => $transfer->decode(wp_json_encode($data)));
        }
        foreach (['enabled' => 'true', 'value' => 1, 'case_sensitive' => 1, 'extra' => 'ignored'] as $key => $value) {
            $data = $transfer->export($preset['id']);
            $data['preset']['mappings'][0]['condition'][$key] = $value;
            $this->assertThrows(\InvalidArgumentException::class, fn () => $transfer->decode(wp_json_encode($data)));
        }
        $data = $transfer->export($preset['id']);
        $data['preset']['mappings'][0]['condition'] = $this->condition('count_eq', '-1');
        $decoded = $transfer->decode(wp_json_encode($data));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $transfer->save($decoded, 'events', 1, []));
    }
}
