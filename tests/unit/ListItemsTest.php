<?php

declare(strict_types=1);

namespace PostPressAPI\Tests;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Integration\ACF\AcfNormalizer;
use PostPressAPI\Mapping\FieldWrite;
use PostPressAPI\Mapping\MappedRecord;
use PostPressAPI\Mapping\MappingError;
use PostPressAPI\Mapping\MappingSchema;
use PostPressAPI\Mapping\MappingValidator;
use PostPressAPI\Mapping\PathResolver;
use PostPressAPI\Sync\Fingerprint;
use PostPressAPI\Sync\PostWriter;

/**
 * Exercises custom List Items using the real mapper, writer, and import runner.
 * WordPress, ACF storage, and HTTP use the existing in-memory test harness.
 */
final class ListItemsTest extends TestCase
{
    /** Registers the custom type only for these tests, alongside existing fields. */
    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
        \AcfStub::$types[] = 'list_items';
        \AcfStub::add_group('group_lists', 'Lists', ['events'], [
            ['key' => 'field_list', 'name' => 'event_dates_selection_options', 'type' => 'list_items'],
            ['key' => 'field_list_group', 'name' => 'list_group', 'type' => 'group', 'sub_fields' => [
                ['key' => 'field_group_list', 'name' => 'dates', 'type' => 'list_items'],
                ['key' => 'field_group_note', 'name' => 'note', 'type' => 'text'],
            ]],
            ['key' => 'field_list_repeater', 'name' => 'list_rows', 'type' => 'repeater', 'sub_fields' => [
                ['key' => 'field_row_list', 'name' => 'dates', 'type' => 'list_items'],
            ]],
        ]);
    }

    /** @return list<string> The user's three display dates, without time conversion. */
    private function dates(): array
    {
        return [
            'Nov 18, 2026 at 6:30 PM Central',
            'Nov 20, 2026 at 6:30 PM Central',
            'Dec 2, 2026 at 6:30 PM Central',
        ];
    }

    /**
     * Normalizes an isolated value through the production ACF integration.
     *
     * @param mixed $value Source value.
     * @return list<string> Sanitized items, in source order.
     * @throws MappingError When the input is not a string list.
     */
    private function normalize(mixed $value): array
    {
        return (new AcfNormalizer())->normalize(['type' => 'list_items'], $value, new \DateTimeZone('America/Phoenix'));
    }

    /**
     * Evaluates one mapping without performing any writes.
     *
     * @param array<string, mixed> $record Source record.
     * @param array<string, mixed> $overrides Mapping options.
     * @return MappedRecord Values and preview details.
     */
    private function evaluate(array $record, array $overrides = []): MappedRecord
    {
        return $this->services->engine()->evaluate(
            $record,
            MappingSchema::normalize_rows([$this->row('dates_text', 'acf', 'field_list', $overrides)]),
            $this->services->destinations()->catalog('events'),
            new \DateTimeZone('America/Phoenix'),
            true
        );
    }

    /**
     * Imports one record through mocked HTTP and the normal run lifecycle.
     *
     * @param array<string, mixed> $preset Saved preset.
     * @param array<string, mixed> $record Record fields; identity is supplied here.
     * @param bool $force Whether to rewrite an unchanged record.
     * @return array<string, mixed> Completed run state.
     */
    private function import(array $preset, array $record, bool $force = false): array
    {
        $this->queueJson(['ok' => true, 'data' => [$record + ['id' => 'list-event']]]);
        return $this->runPreset($preset['id'], $force);
    }

    /** Discovery enables registered List Items without treating it as a repeater. */
    public function testDiscoveryAndValidationRequireTheRegisteredCustomType(): void
    {
        $fields = $this->services->acf_fields();
        $groups = $fields->groups('events');
        $descriptor = $groups[1]['fields'][0];
        $this->assertSame('list_items', $descriptor['type']);
        $this->assertTrue($descriptor['supported']);
        $this->assertFalse($descriptor['container']);
        $this->assertSame([], $descriptor['children']);
        $catalog = $this->services->destinations()->catalog('events');
        $rows = MappingSchema::normalize_rows([$this->row('dates_text', 'acf', 'field_list')]);
        $validator = new MappingValidator(new PathResolver());
        $this->assertSame([], $validator->validate($rows, $catalog)['errors']);

        \AcfStub::$types = array_values(array_diff(\AcfStub::$types, ['list_items']));
        $this->assertThrows(MappingError::class, fn () => $catalog->resolve('acf', 'field_list'), 'not available');
        $fields->reset();
        $this->assertFalse($fields->groups('events')[1]['fields'][0]['supported']);
        $this->assertTrue($validator->validate($rows, $catalog)['errors'] !== []);
        $this->assertSame('field_city', $catalog->resolve('acf', 'field_city')->key);
        $this->assertNotNull($fields->unsupported_reason(['type' => 'image_gallery']));
        $this->assertNotNull($fields->unsupported_reason(['type' => 'another_custom_type']));
    }

    /** Plain text sanitization retains list order, duplicates, zero, and escaping. */
    public function testSanitizingPreservesOrderDuplicatesAndZero(): void
    {
        $this->assertSame($this->dates(), $this->normalize($this->dates()));
        $this->assertSame(['First', '0', 'First', 'Last'], $this->normalize([
            ' <b>First</b> ', '', '  ', '0', 'First', '<i></i>', 'Last',
        ]));
        $escaped = ['Speaker\'s "Q&A"', 'C:\\events\\dates', 'Café — meeting'];
        $this->assertSame($escaped, $this->normalize($escaped));
        $this->assertSame([], $this->normalize([]));
        $this->assertSame([], $this->normalize(['', '   ', '<b></b>']));
    }

    /** Scalars, objects, sparse arrays, and non-string list items fail explicitly. */
    public function testInvalidShapesAreRejectedWithoutCoercion(): void
    {
        foreach (['date', '["date"]', '', false, 0, null, (object) ['date' => 'A'], ['date' => 'A'], [1 => 'A']] as $invalid) {
            $this->assertThrows(MappingError::class, fn () => $this->normalize($invalid), 'array of text items');
        }
        foreach ([null, false, true, 0, 1.5, ['nested'], (object) ['text' => 'A']] as $invalid) {
            $this->assertThrows(MappingError::class, fn () => $this->normalize(['valid', $invalid]), 'item 2 must be text');
        }
    }

    /** Both direct lists and wildcard paths reach ACF as arrays, without joining. */
    public function testMappingPathsAndPreviewKeepNativeArrays(): void
    {
        foreach (['dates_text', 'dates_text[*]'] as $path) {
            $mapped = $this->evaluate(['dates_text' => $this->dates()], ['source' => $path]);
            $this->assertFalse($mapped->has_errors());
            $this->assertSame($this->dates(), $mapped->writes()[0]->value);
            $this->assertSame(wp_json_encode($this->dates()), $mapped->details()[0]['value']);
        }
        $projected = $this->evaluate(['sessions' => [['label' => 'A'], ['label' => 'B']]], ['source' => 'sessions[*].label']);
        $this->assertSame(['A', 'B'], $projected->writes()[0]->value);
        foreach (['join', 'json', 'text'] as $transform) {
            $this->assertTrue($this->evaluate(['dates_text' => $this->dates()], ['transform' => $transform])->has_errors());
        }
        $this->assertSame([], \WPStub::$posts);
        $this->assertSame([], \AcfStub::$update_calls);
    }

    /** Missing/null policies stay distinct from a present empty list. */
    public function testMissingNullClearFailAndEmptyListPolicies(): void
    {
        foreach ([[], ['dates_text' => null]] as $record) {
            $this->assertSame([], $this->evaluate($record)->writes());
            $clear = $this->evaluate($record, ['on_missing' => 'clear', 'on_null' => 'clear']);
            $this->assertSame(FieldWrite::CLEAR, $clear->writes()[0]->op);
            $this->assertTrue($this->evaluate($record, ['on_missing' => 'fail', 'on_null' => 'fail'])->has_errors());
            $this->assertTrue($this->evaluate($record, ['on_missing' => 'fallback', 'on_null' => 'fallback', 'fallback' => '["A"]'])->has_errors());
        }
        $empty = $this->evaluate(['dates_text' => []])->writes()[0];
        $this->assertSame(FieldWrite::SET, $empty->op);
        $this->assertSame([], $empty->value);
        $this->assertTrue($this->evaluate(['dates_text' => ''])->has_errors());
    }

    /** The user's payload previews without writes, imports, and skips unchanged runs. */
    public function testPreviewImportAndForcedRepeatUseAcfArraysAndFieldKeys(): void
    {
        $preset = $this->savePreset(['mappings' => [$this->row('dates_text', 'acf', 'field_list')]]);
        $record = ['id' => 'list-event', 'dates_text' => $this->dates()];
        $preview = $this->services->preview()->dry_run($preset, null, wp_json_encode(['ok' => true, 'data' => [$record]]));
        $this->assertSame(1, $preview['counts']['created']);
        $this->assertSame(wp_json_encode($this->dates()), $preview['records'][0]['details'][0]['value']);
        $this->assertSame([], \WPStub::$posts);
        $this->assertSame([], \AcfStub::$update_calls);

        $this->assertSame(1, $this->import($preset, $record)['counts']['created']);
        $id = (int) $this->posts()[0]['ID'];
        $this->assertSame($this->dates(), $this->meta($id, 'event_dates_selection_options'));
        $this->assertSame('field_list', $this->meta($id, '_event_dates_selection_options'));
        $this->assertSame('field_list', \AcfStub::$update_calls[0][0]);
        $this->assertSame($this->dates(), \AcfStub::$update_calls[0][1]);

        $calls = count(\AcfStub::$update_calls);
        $this->assertSame(1, $this->import($preset, $record)['counts']['unchanged']);
        $this->assertSame($calls, count(\AcfStub::$update_calls));
        $this->assertSame(1, $this->import($preset, $record, true)['counts']['updated']);
        $this->assertSame(PostWriter::STATE_COMPLETE, $this->meta($id, PostWriter::META_STATE));
        $this->assertCount(1, $this->posts());
    }

    /** Reordering, deleting, and emptying items updates the same post and keeps local meta. */
    public function testListChangesAndEmptyArraysReplaceOnlyTheMappedValue(): void
    {
        $preset = $this->savePreset(['mappings' => [$this->row('dates_text', 'acf', 'field_list')]]);
        $this->import($preset, ['dates_text' => $this->dates()]);
        $id = (int) $this->posts()[0]['ID'];
        update_post_meta($id, 'local_note', 'Keep me');
        foreach ([array_reverse($this->dates()), [$this->dates()[0]], []] as $items) {
            $this->assertSame(1, $this->import($preset, ['dates_text' => $items])['counts']['updated']);
            $this->assertSame($items, $this->meta($id, 'event_dates_selection_options'));
            $this->assertSame('Keep me', $this->meta($id, 'local_note'));
            $this->assertCount(1, $this->posts());
        }
        $this->assertSame(1, $this->import($preset, ['dates_text' => []])['counts']['unchanged']);
    }

    /** Sanitized values determine fingerprints; quotes and slashes survive ACF writes. */
    public function testSanitizedEquivalentInputsDoNotTriggerUpdates(): void
    {
        $preset = $this->savePreset(['mappings' => [$this->row('dates_text', 'acf', 'field_list')]]);
        $expected = ['Speaker\'s "Q&A"', 'C:\\events\\dates', '0', 'same', 'same'];
        $this->import($preset, ['dates_text' => $expected]);
        $id = (int) $this->posts()[0]['ID'];
        $this->assertSame($expected, $this->meta($id, 'event_dates_selection_options'));
        $source = $expected;
        $source[3] = '<b>same</b>';
        $source[] = '   ';
        $this->assertSame(1, $this->import($preset, ['dates_text' => $source])['counts']['unchanged']);
        $this->assertSame($expected, $this->meta($id, 'event_dates_selection_options'));
    }

    /** Invalid data fails a record before any of its mapped post fields are changed. */
    public function testInvalidImportPreservesExistingListAndTitle(): void
    {
        $preset = $this->savePreset(['mappings' => [
            $this->row('title', 'core', 'post_title'),
            $this->row('dates_text', 'acf', 'field_list'),
        ]]);
        $this->import($preset, ['title' => 'Original', 'dates_text' => $this->dates()]);
        $id = (int) $this->posts()[0]['ID'];
        $calls = count(\AcfStub::$update_calls);
        foreach (['not a list', ['A', null], [['text' => 'A']]] as $invalid) {
            $this->assertSame(1, $this->import($preset, ['title' => 'Should not save', 'dates_text' => $invalid])['counts']['failed']);
            $this->assertSame($this->dates(), $this->meta($id, 'event_dates_selection_options'));
            $this->assertSame('Original', $this->posts()[0]['post_title']);
            $this->assertSame($calls, count(\AcfStub::$update_calls));
        }
    }

    /** Missing and null keep saved lists, while explicit Clear removes the ACF value. */
    public function testPreserveAndClearThroughTheWriter(): void
    {
        $preset = $this->savePreset(['mappings' => [$this->row('dates_text', 'acf', 'field_list')]]);
        $this->import($preset, ['dates_text' => $this->dates()]);
        $id = (int) $this->posts()[0]['ID'];
        foreach ([[], ['dates_text' => null]] as $record) {
            $this->import($preset, $record);
            $this->assertSame($this->dates(), $this->meta($id, 'event_dates_selection_options'));
        }
        $preset['mappings'][0]['on_null'] = 'clear';
        $preset = $this->services->presets()->save($preset, 1);
        $this->assertSame(1, $this->import($preset, ['dates_text' => null])['counts']['updated']);
        $this->assertFalse(metadata_exists('post', $id, 'event_dates_selection_options'));
        $this->assertFalse(metadata_exists('post', $id, '_event_dates_selection_options'));
    }

    /** Group lists preserve unmapped siblings and support nested clearing as arrays. */
    public function testGroupListPreservesSiblingsAndClearsToAnArray(): void
    {
        $preset = $this->savePreset(['mappings' => [$this->row('group', 'acf', 'field_list_group', ['children' => [
            $this->row('dates', 'acf', 'field_group_list', ['on_null' => 'clear']),
        ]])]]);
        $this->import($preset, ['group' => ['dates' => $this->dates()]]);
        $id = (int) $this->posts()[0]['ID'];
        $this->assertSame($this->dates(), $this->meta($id, 'list_group_dates'));
        update_post_meta($id, 'list_group_note', 'Local sibling');
        $this->import($preset, ['group' => []]);
        $this->assertSame($this->dates(), $this->meta($id, 'list_group_dates'));
        $this->assertSame(1, $this->import($preset, ['group' => ['dates' => null]])['counts']['updated']);
        $this->assertSame([], $this->meta($id, 'list_group_dates'));
        $this->assertSame('Local sibling', $this->meta($id, 'list_group_note'));
    }

    /** Repeater subfields keep each row's list and clear missing rows without leakage. */
    public function testRepeaterListsRetainTheirRowsAndClearMissingValues(): void
    {
        $preset = $this->savePreset(['mappings' => [$this->row('rows', 'acf', 'field_list_repeater', ['children' => [
            $this->row('dates', 'acf', 'field_row_list', ['on_missing' => 'clear', 'on_null' => 'clear']),
        ]])]]);
        $this->assertSame(1, $this->import($preset, ['rows' => [
            ['dates' => ['A', 'B']], ['dates' => ['C']],
        ]])['counts']['created']);
        $id = (int) $this->posts()[0]['ID'];
        $this->assertSame(['A', 'B'], $this->meta($id, 'list_rows_0_dates'));
        $this->assertSame(['C'], $this->meta($id, 'list_rows_1_dates'));
        $this->assertSame(1, $this->import($preset, ['rows' => [[], ['dates' => null]]])['counts']['updated']);
        $this->assertSame([], $this->meta($id, 'list_rows_0_dates'));
        $this->assertSame([], $this->meta($id, 'list_rows_1_dates'));
        $this->assertSame(1, $this->import($preset, ['rows' => []])['counts']['updated']);
        $this->assertFalse(metadata_exists('post', $id, 'list_rows_1_dates'));
    }

    /** Silent ACF write failures remain failures and retries reuse the pending post. */
    public function testFailedWritesAreRetriedOnTheSamePost(): void
    {
        $preset = $this->savePreset(['mappings' => [$this->row('dates_text', 'acf', 'field_list')]]);
        \AcfStub::$fail_keys['field_list'] = true;
        $this->assertSame(1, $this->import($preset, ['dates_text' => $this->dates()])['counts']['failed']);
        $id = (int) $this->posts()[0]['ID'];
        $this->assertSame(PostWriter::STATE_PENDING, $this->meta($id, PostWriter::META_STATE));
        unset(\AcfStub::$fail_keys['field_list']);
        $this->assertSame(1, $this->import($preset, ['dates_text' => $this->dates()])['counts']['updated']);
        $this->assertCount(1, $this->posts());
        $this->assertSame($this->dates(), $this->meta($id, 'event_dates_selection_options'));
        $this->assertSame(PostWriter::STATE_COMPLETE, $this->meta($id, PostWriter::META_STATE));
    }

    /** Preset transfer retains the field type and resolves a different local field key. */
    public function testPresetTransferRetainsListMappingsAndResolvesLocalKeys(): void
    {
        $preset = $this->savePreset(['mappings' => [$this->row('dates_text', 'acf', 'field_list')]]);
        $transfer = $this->services->transfer();
        $data = $transfer->decode(wp_json_encode($transfer->export($preset['id'])));
        $copy = $transfer->save($data, 'events', 1, []);
        $this->assertSame($preset['mappings'], $copy['mappings']);
        $this->assertSame(Fingerprint::mapping_hash($preset), Fingerprint::mapping_hash($copy));
        \AcfStub::$groups[1]['fields'][0]['key'] = 'field_list_local';
        $this->services->acf_fields()->reset();
        $review = $transfer->review($data);
        $this->assertSame([], $review['problems']);
        $this->assertSame('field_list_local', $review['preset']['mappings'][0]['target']);
    }

    /** Existing ACF protections and text/meta conversions stay unchanged. */
    public function testOtherDestinationRulesRemainUnchanged(): void
    {
        $catalog = $this->services->destinations()->catalog('events');
        foreach (['event_dates_selection_options', '_event_dates_selection_options'] as $key) {
            $this->assertThrows(MappingError::class, fn () => $catalog->resolve('meta', $key), 'managed by the ACF field');
        }
        foreach (['text', 'textarea'] as $type) {
            $this->assertThrows(MappingError::class, fn () => (new AcfNormalizer())->normalize(['type' => $type], $this->dates(), new \DateTimeZone('UTC')));
        }
        $raw_meta = $this->evaluate(['dates_text' => $this->dates()], ['target_type' => 'meta', 'target' => 'plain_meta']);
        $this->assertTrue($raw_meta->has_errors());
        $joined = $this->evaluate(['dates_text' => $this->dates()], ['target_type' => 'meta', 'target' => 'plain_meta', 'transform' => 'join']);
        $this->assertSame(implode(', ', $this->dates()), $joined->writes()[0]->value);
        $json = $this->evaluate(['dates_text' => $this->dates()], ['target_type' => 'meta', 'target' => 'plain_meta', 'transform' => 'json']);
        $this->assertSame(wp_json_encode($this->dates()), $json->writes()[0]->value);
    }
}
