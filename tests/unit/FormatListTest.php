<?php

declare(strict_types=1);

namespace PostPressAPI\Tests;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Admin\AdminPage;
use PostPressAPI\Admin\PresetEditor;
use PostPressAPI\Mapping\FieldWrite;
use PostPressAPI\Mapping\MappedRecord;
use PostPressAPI\Mapping\MappingError;
use PostPressAPI\Mapping\MappingSchema;
use PostPressAPI\Mapping\Transformer;
use PostPressAPI\Sync\Fingerprint;

/**
 * Exercises Format list items (array) through conversion, validation, imports, and transfer.
 * WordPress, ACF storage, and HTTP use the existing in-memory test harness.
 */
final class FormatListTest extends TestCase
{
    /** The example's input format, exactly as typed into the editor. */
    private const INPUT_FORMAT = 'M j, Y \a\t g:i A \C\e\n\t\r\a\l';

    /** The example's expected List Items value. */
    private const EXPECTED = ['November 18', 'November 20', 'December 2'];

    /** Registers List Items, choice lists, and nested list fields next to the usual event fields. */
    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
        \AcfStub::$types[] = 'list_items';
        $choices = ['nov-18' => 'November 18', 'nov-20' => 'November 20', 'dec-2' => 'December 2'];
        \AcfStub::add_group('group_lists', 'Lists', ['events'], [
            ['key' => 'field_list', 'name' => 'event_dates_selection_options', 'type' => 'list_items'],
            ['key' => 'field_days', 'name' => 'days', 'type' => 'checkbox', 'choices' => $choices],
            ['key' => 'field_day_select', 'name' => 'day_select', 'type' => 'select', 'multiple' => 1, 'choices' => $choices],
            ['key' => 'field_list_group', 'name' => 'list_group', 'type' => 'group', 'sub_fields' => [
                ['key' => 'field_group_list', 'name' => 'dates', 'type' => 'list_items'],
                ['key' => 'field_group_note', 'name' => 'note', 'type' => 'text'],
            ]],
            ['key' => 'field_list_repeater', 'name' => 'list_rows', 'type' => 'repeater', 'sub_fields' => [
                ['key' => 'field_row_list', 'name' => 'dates', 'type' => 'list_items'],
            ]],
        ]);
    }

    /** @return list<string> The example's source list. */
    private function dates(): array
    {
        return [
            'Nov 18, 2026 at 6:30 PM Central',
            'Nov 20, 2026 at 6:30 PM Central',
            'Dec 2, 2026 at 6:30 PM Central',
        ];
    }

    /** @return array<string, mixed> The example's date settings as entered in the editor. */
    private function example(): array
    {
        return [
            'join_datetime' => true, 'input_format' => self::INPUT_FORMAT, 'input_timezone' => 'America/Chicago',
            'output_format' => 'F j', 'output_timezone' => 'America/Chicago',
        ];
    }

    /**
     * Applies the transformation directly in a UTC site.
     *
     * @param mixed $value Source value.
     * @param array<string, mixed> $options Row options.
     * @return mixed Transformed list.
     * @throws MappingError When the list or an item is invalid.
     */
    private function format(mixed $value, array $options = []): mixed
    {
        return (new Transformer())->apply('format_list', $value, $options, new \DateTimeZone('UTC'));
    }

    /**
     * A Format list items mapping using the example settings, into List Items by default.
     *
     * @param string $source Source path.
     * @param string $target ACF field key.
     * @param array<string, mixed> $row Overrides.
     * @return array<string, mixed> Mapping row.
     */
    private function listRow(string $source = 'dates_text', string $target = 'field_list', array $row = []): array
    {
        return $this->row($source, 'acf', $target, $row + ['transform' => 'format_list', 'options' => $this->example()]);
    }

    /**
     * Evaluates mappings without writes in a Phoenix site, so output zones must be honored.
     *
     * @param array<string, mixed> $record Source record.
     * @param list<array<string, mixed>> $rows Mapping rows.
     * @return MappedRecord Writes, errors, and preview details.
     */
    private function evaluate(array $record, array $rows): MappedRecord
    {
        return $this->services->engine()->evaluate(
            $record,
            MappingSchema::normalize_rows($rows),
            $this->services->destinations()->catalog('events'),
            new \DateTimeZone('America/Phoenix'),
            true
        );
    }

    /**
     * Save-time validation errors for rows.
     *
     * @param list<array<string, mixed>> $rows Mapping rows.
     * @return list<string> Errors.
     */
    private function errors(array $rows): array
    {
        return $this->services->mapping_validator()->validate(
            MappingSchema::normalize_rows($rows),
            $this->services->destinations()->catalog('events')
        )['errors'];
    }

    /**
     * Imports one record through mocked HTTP and the normal run lifecycle.
     *
     * @param array<string, mixed> $preset Saved preset.
     * @param array<string, mixed> $record Record fields; identity is supplied here.
     * @return array<string, mixed> Completed run state.
     */
    private function import(array $preset, array $record): array
    {
        $this->queueJson(['ok' => true, 'data' => [$record + ['id' => 'list-event']]]);
        return $this->runPreset($preset['id']);
    }

    /** The exact example becomes a native array, in preview details shown as JSON. */
    public function testRequestedExampleProducesANativeArray(): void
    {
        $this->assertSame(self::EXPECTED, $this->format($this->dates(), $this->example()));
        $rows = MappingSchema::normalize_rows([$this->listRow()]);
        $this->assertSame(self::INPUT_FORMAT, $rows[0]['options']['input_format']);
        $this->assertSame([], $this->errors([$this->listRow()]));

        $mapped = $this->evaluate(['dates_text' => $this->dates()], [$this->listRow()]);
        $this->assertFalse($mapped->has_errors());
        $this->assertSame(FieldWrite::SET, $mapped->writes()[0]->op);
        $this->assertSame(self::EXPECTED, $mapped->writes()[0]->value);
        $this->assertSame(wp_json_encode(self::EXPECTED), $mapped->details()[0]['value']);
        $this->assertSame('array (3 items)', $mapped->details()[0]['raw_type']);
    }

    /** Uses the Date/time rules: zone conversion, unshifted date-only values, and default formats. */
    public function testTimeZonesDateOnlyValuesAndDefaultFormats(): void
    {
        $this->assertSame(['November 19', 'November 21', 'December 3'], $this->format($this->dates(), ['output_timezone' => 'Asia/Tokyo'] + $this->example()));
        $this->assertSame(['2026-11-19 05:30'], $this->format(['2026-11-18 23:30'], [
            'join_datetime' => true, 'input_timezone' => 'America/Chicago', 'output_timezone' => 'UTC', 'output_format' => 'Y-m-d H:i',
        ]));
        $this->assertSame(['November 18'], $this->format(['2026-11-18'], [
            'join_datetime' => true, 'input_timezone' => 'Pacific/Kiritimati', 'output_timezone' => 'America/Los_Angeles', 'output_format' => 'F j',
        ]));
        $this->assertSame(['2026-11-18', '18:30:00', '2026-11-18 18:30:00'], $this->format(['2026-11-18', '18:30', '2026-11-18 18:30'], ['join_datetime' => true]));
        $this->assertSame(['6:30 PM', '7:45 AM'], $this->format(['18:30', '07:45'], ['join_datetime' => true, 'input_format' => 'H:i', 'output_format' => 'g:i A']));
        $this->assertSame(['November 18'], $this->format(['18/11/2026'], ['join_datetime' => true, 'input_format' => 'd/m/Y', 'output_format' => 'F j']));

        // Without an output zone the site zone applies (Phoenix here); offsets in the source are honored.
        $row = $this->listRow('dates_text', 'field_list', ['options' => ['join_datetime' => true, 'output_format' => 'Y-m-d H:i']]);
        $this->assertSame(['2026-11-17 17:30'], $this->evaluate(['dates_text' => ['2026-11-18T00:30:00Z']], [$row])->writes()[0]->value);

        $this->assertThrows(MappingError::class, fn () => $this->format(['2026-11-18'], ['join_datetime' => true, 'input_timezone' => 'Eastern']), 'List item 1: "Eastern" is not a valid IANA time zone');
        $zones = ['input_timezone' => 'Eastern', 'output_timezone' => 'CST'];
        $this->assertCount(2, $this->errors([$this->listRow('dates_text', 'field_list', ['options' => $zones + $this->example()])]));
        $this->assertSame([], $this->errors([$this->listRow('dates_text', 'field_list', ['options' => ['join_datetime' => false] + $zones])]), 'zones are only checked while date formatting is on');
        $this->assertThrows(MappingError::class, fn () => $this->format(['Nov 18, 2026 6:30 PM CST'], ['join_datetime' => true]), 'ambiguous');
    }

    /** Order and duplicates are kept, nulls are skipped like Join, and scalars use Join's text rules. */
    public function testEmptyNullDuplicatesOrderAndScalarText(): void
    {
        $this->assertSame([], $this->format([]));
        $this->assertSame([], $this->format([], $this->example()));
        $this->assertSame([], $this->format([null, null], $this->example()));
        $this->assertSame(['December 2', 'November 18', 'December 2'], $this->format([
            $this->dates()[2], null, $this->dates()[0], $this->dates()[2],
        ], $this->example()));

        $mixed = ['b', 'a', 'b', 3, 1.5, true, false, '0', '', null, ' spaced '];
        $this->assertSame(['b', 'a', 'b', '3', '1.5', 'true', 'false', '0', '', ' spaced '], $this->format($mixed));
        $this->assertSame(Transformer::join($mixed, '|'), implode('|', $this->format($mixed)), 'same item text as Join');
        $this->assertSame(['plain'], $this->format(['plain'], ['join_datetime' => false, 'input_timezone' => 'Eastern']), 'date options are ignored while off');
    }

    /** A list is required, and bad items fail with their position instead of disappearing. */
    public function testInvalidSourcesAndItemsIdentifyThePosition(): void
    {
        foreach (['text', '["A"]', '', 5, true, ['a' => 'x'], [1 => 'A']] as $source) {
            $this->assertThrows(MappingError::class, fn () => $this->format($source), 'needs a list (array)');
            $this->assertThrows(MappingError::class, fn () => $this->format($source, $this->example()), 'needs a list (array)');
        }
        $this->assertThrows(MappingError::class, fn () => $this->format(['ok', ['nested']]), 'List item 2 is an array');
        $this->assertThrows(MappingError::class, fn () => $this->format([['date' => '2026-11-18']]), 'List item 1 is an object');
        $this->assertThrows(MappingError::class, fn () => $this->format([(object) ['date' => 'x']]), 'List item 1 is an unsupported value');
        foreach (['', '2026-02-30', 'tomorrow', 'not a date', false] as $bad) {
            $this->assertThrows(MappingError::class, fn () => $this->format(['2026-11-18', $bad], ['join_datetime' => true]), 'List item 2: ');
        }
        $this->assertThrows(MappingError::class, fn () => $this->format([$this->dates()[0], 'Nov 31, 2026 at 6:30 PM Central'], $this->example()), 'List item 2: "Nov 31, 2026 at 6:30 PM Central" does not match the date format');

        $mapped = $this->evaluate(['dates_text' => [$this->dates()[0], 'TBD']], [$this->listRow()]);
        $this->assertTrue($mapped->has_errors());
        $this->assertSame([], $mapped->writes());
        $this->assertContains('List item 2', $mapped->errors()[0]);
    }

    /** Direct lists and wildcard projections both stay arrays. */
    public function testDirectPathsAndWildcardProjectionsKeepArrays(): void
    {
        foreach (['dates_text', 'dates_text[*]'] as $path) {
            $this->assertSame(self::EXPECTED, $this->evaluate(['dates_text' => $this->dates()], [$this->listRow($path)])->writes()[0]->value, $path);
        }
        $sessions = ['sessions' => [['date' => '2026-11-18'], ['date' => null], [], ['date' => '2026-12-02'], ['date' => '2026-11-18']]];
        $row = $this->listRow('sessions[*].date', 'field_list', ['options' => ['join_datetime' => true, 'output_format' => 'F j']]);
        $this->assertSame(['November 18', 'December 2', 'November 18'], $this->evaluate($sessions, [$row])->writes()[0]->value);
        $plain = $this->listRow('sessions[*].label', 'field_list', ['options' => []]);
        $this->assertSame(['A', 'B'], $this->evaluate(['sessions' => [['label' => 'A'], ['label' => 'B']]], [$plain])->writes()[0]->value);
    }

    /** Preview writes nothing; imports store an array through ACF and detect real changes. */
    public function testPreviewAndImportStoreListItemsArrays(): void
    {
        $preset = $this->savePreset(['mappings' => [$this->row('title', 'core', 'post_title'), $this->listRow()]]);
        $record = ['id' => 'list-event', 'title' => 'Workshop', 'dates_text' => $this->dates()];
        $preview = $this->services->preview()->dry_run($preset, null, wp_json_encode(['ok' => true, 'data' => [$record]]));
        $this->assertSame(1, $preview['counts']['created']);
        $this->assertSame(wp_json_encode(self::EXPECTED), $preview['records'][0]['details'][1]['value']);
        $this->assertSame([], \WPStub::$posts);
        $this->assertSame([], \AcfStub::$update_calls);

        $this->assertSame(1, $this->import($preset, $record)['counts']['created']);
        $id = (int) $this->posts()[0]['ID'];
        $this->assertSame(self::EXPECTED, $this->meta($id, 'event_dates_selection_options'));
        $this->assertSame('field_list', \AcfStub::$update_calls[0][0]);
        $this->assertSame(self::EXPECTED, \AcfStub::$update_calls[0][1]);
        $this->assertSame(1, $this->import($preset, $record)['counts']['unchanged']);

        $record['dates_text'] = array_reverse($this->dates());
        $this->assertSame(1, $this->import($preset, $record)['counts']['updated']);
        $this->assertSame(array_reverse(self::EXPECTED), $this->meta($id, 'event_dates_selection_options'));

        $record['dates_text'][] = 'Date TBD';
        $record['title'] = 'Should not save';
        $this->assertSame(1, $this->import($preset, $record)['counts']['failed']);
        $this->assertSame(array_reverse(self::EXPECTED), $this->meta($id, 'event_dates_selection_options'));
        $this->assertSame('Workshop', $this->posts()[0]['post_title']);
    }

    /** List Items sub fields in groups and repeaters can format items; containers cannot. */
    public function testNestedListItemsInGroupsAndRepeaters(): void
    {
        $preset = $this->savePreset(['mappings' => [
            $this->row('group', 'acf', 'field_list_group', ['children' => [$this->listRow('dates', 'field_group_list')]]),
            $this->row('rows', 'acf', 'field_list_repeater', ['children' => [
                $this->listRow('dates', 'field_row_list', ['on_missing' => 'clear', 'on_null' => 'clear']),
            ]]),
        ]]);
        $this->assertSame(1, $this->import($preset, [
            'group' => ['dates' => $this->dates()],
            'rows'  => [['dates' => array_slice($this->dates(), 0, 2)], ['dates' => [$this->dates()[2]]], []],
        ])['counts']['created']);
        $id = (int) $this->posts()[0]['ID'];
        $this->assertSame(self::EXPECTED, $this->meta($id, 'list_group_dates'));
        $this->assertSame(['November 18', 'November 20'], $this->meta($id, 'list_rows_0_dates'));
        $this->assertSame(['December 2'], $this->meta($id, 'list_rows_1_dates'));
        $this->assertSame([], $this->meta($id, 'list_rows_2_dates'));

        $container = $this->row('group', 'acf', 'field_list_group', ['transform' => 'format_list', 'children' => [$this->listRow('dates', 'field_group_list')]]);
        $this->assertContains('Mapping 1: Groups and repeaters cannot use a transformation; set transformations on their sub fields.', $this->errors([$container]));
        $nested_text = $this->row('group', 'acf', 'field_list_group', ['children' => [$this->listRow('note', 'field_group_note')]]);
        $this->assertContains('cannot store a list', implode(' ', $this->errors([$nested_text])));
    }

    /** Checkbox and multiple-select fields keep their choice matching and de-duplication. */
    public function testChoiceListsKeepTheirValidation(): void
    {
        $this->assertSame([], $this->errors([$this->listRow('dates_text', 'field_days'), $this->listRow('dates_text', 'field_day_select')]));
        $source = ['dates_text' => [$this->dates()[2], $this->dates()[0], $this->dates()[2]]];
        foreach (['field_days', 'field_day_select'] as $field) {
            $mapped = $this->evaluate($source, [$this->listRow('dates_text', $field)]);
            $this->assertSame(['dec-2', 'nov-18'], $mapped->writes()[0]->value, $field . ' resolves labels to choice keys');
            $other = $this->evaluate(['dates_text' => ['Nov 19, 2026 at 6:30 PM Central']], [$this->listRow('dates_text', $field)]);
            $this->assertContains('"November 19" is not one of this field\'s choices', $other->errors()[0]);
        }
    }

    /** Text, meta, core, and single-value fields are rejected when saving and when running. */
    public function testIncompatibleDestinationsAreRejected(): void
    {
        $incompatible = [
            $this->listRow('dates_text', 'field_city'),
            $this->listRow('dates_text', 'field_mode'),
            $this->listRow('dates_text', 'field_first_date'),
            $this->row('dates_text', 'meta', 'dates_meta', ['transform' => 'format_list']),
            $this->row('dates_text', 'core', 'post_title', ['transform' => 'format_list']),
        ];
        foreach ($incompatible as $row) {
            $errors = implode(' ', $this->errors([$row]));
            $this->assertContains('cannot store a list. Format list items (array) only fills ACF List Items, checkbox, and multiple-choice select fields', $errors, $row['target']);
            $mapped = $this->evaluate(['dates_text' => $this->dates()], [$row]);
            $this->assertSame([], $mapped->writes());
            $this->assertContains('cannot store a list', $mapped->errors()[0]);
        }
        foreach (['constant', 'template'] as $type) {
            $this->assertContains('needs a source path that selects a list', implode(' ', $this->errors([$this->listRow('{dates_text}', 'field_list', ['source_type' => $type])])));
        }

        // A field changed to text after saving fails the record with the same explanation.
        $preset = $this->savePreset(['mappings' => [$this->listRow()]]);
        \AcfStub::$groups[1]['fields'][0]['type'] = 'text';
        $this->services->acf_fields()->reset();
        $state = $this->import($preset, ['dates_text' => $this->dates()]);
        $this->assertSame(1, $state['counts']['failed']);
        $this->assertContains('cannot store a list', wp_json_encode($state['issues']));
    }

    /** Conditions run first, missing/null keep their rules, and empty lists are real values. */
    public function testConditionsMissingNullAndEmptyLists(): void
    {
        foreach ([[], ['dates_text' => null]] as $record) {
            $this->assertSame([], $this->evaluate($record, [$this->listRow()])->writes());
            $clear = $this->evaluate($record, [$this->listRow('dates_text', 'field_list', ['on_missing' => 'clear', 'on_null' => 'clear'])]);
            $this->assertSame(FieldWrite::CLEAR, $clear->writes()[0]->op);
            $this->assertTrue($this->evaluate($record, [$this->listRow('dates_text', 'field_list', ['on_missing' => 'fail', 'on_null' => 'fail'])])->has_errors());
            $fallback = $this->evaluate($record, [$this->listRow('dates_text', 'field_list', ['on_missing' => 'fallback', 'on_null' => 'fallback', 'fallback' => '["November 18"]'])]);
            $this->assertContains('List Items requires an array of text items', $fallback->errors()[0], 'text fallbacks are not read as JSON');
        }
        foreach ([['dates_text' => []], ['sessions' => []]] as $record) {
            $path = isset($record['sessions']) ? 'sessions[*].date' : 'dates_text';
            $empty = $this->evaluate($record, [$this->listRow($path)])->writes()[0];
            $this->assertSame(FieldWrite::SET, $empty->op);
            $this->assertSame([], $empty->value);
        }

        $condition = ['enabled' => true, 'operator' => 'count_eq', 'value' => '1', 'case_sensitive' => false, 'action' => 'clear'];
        $row = $this->listRow('dates_text', 'field_list', ['condition' => $condition]);
        $this->assertSame([], $this->errors([$row]));
        $single = $this->evaluate(['dates_text' => ['not a date']], [$row]);
        $this->assertFalse($single->has_errors(), 'the condition decides before the transformation runs');
        $this->assertSame(FieldWrite::CLEAR, $single->writes()[0]->op);
        $this->assertSame(self::EXPECTED, $this->evaluate(['dates_text' => $this->dates()], [$row])->writes()[0]->value);
    }

    /** Join's output, scalar handling, and errors are unchanged by the new option. */
    public function testJoinOutputIsUnchanged(): void
    {
        $transformer = new Transformer();
        $utc = new \DateTimeZone('UTC');
        $this->assertSame('November 18, November 20, December 2', $transformer->apply('join', $this->dates(), $this->example() + ['separator' => ', '], $utc));
        $this->assertSame("a\nb\n3", $transformer->apply('join', ['a', 'b', 3, null], ['separator' => '\\n'], $utc));
        $this->assertSame('solo', $transformer->apply('join', 'solo', [], $utc));
        $this->assertSame('x, y', $transformer->apply('join', ['k' => 'x', 'j' => 'y'], [], $utc));
        $this->assertThrows(MappingError::class, fn () => $transformer->apply('join', ['2026-11-18', 'bad'], ['join_datetime' => true], $utc), 'Join item 2');
        $this->assertThrows(MappingError::class, fn () => $transformer->apply('join', [['nested']], [], $utc), 'Join can only combine');

        $labels = Transformer::labels();
        $this->assertSame('Join list into text', $labels['join']);
        $this->assertSame('Format list items (array)', $labels['format_list']);
        $joined = $this->evaluate(['dates_text' => $this->dates()], [$this->row('dates_text', 'meta', 'dates_meta', ['transform' => 'join', 'options' => $this->example()])]);
        $this->assertSame('November 18, November 20, December 2', $joined->writes()[0]->value);
        $this->assertSame([], $this->errors([$this->row('dates_text', 'acf', 'field_city', ['transform' => 'join', 'options' => $this->example()])]));
    }

    /** Settings survive the editor form (with its backslashes), reload, duplication, and change detection. */
    public function testSaveReloadDuplicateAndChangeDetection(): void
    {
        $preset = $this->savePreset(['enabled' => false, 'mappings' => [$this->row('title', 'core', 'post_title')]]);
        $mappings = [
            $this->row('title', 'core', 'post_title', ['id' => 'title']),
            $this->listRow('dates_text', 'field_list', ['id' => 'dates']),
            $this->row('group', 'acf', 'field_list_group', ['id' => 'group', 'children' => [$this->listRow('dates', 'field_group_list', ['id' => 'nested'])]]),
        ];
        $_POST = wp_slash([
            'ppapi_action' => 'save', '_ppapi_nonce' => wp_create_nonce('ppapi_save_preset'), 'preset_id' => $preset['id'],
            'ppapi' => $preset, 'ppapi_mappings' => wp_json_encode($mappings),
        ]);
        $_REQUEST = $_POST;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $redirect = '';
        (new AdminPage($this->services, static function (string $url) use (&$redirect): void { $redirect = $url; }))->handle_save();
        $this->assertContains('ppapi_notice=saved', $redirect);

        $saved = $this->services->presets()->find($preset['id']);
        $this->assertSame(MappingSchema::normalize_rows($mappings), $saved['mappings']);
        foreach ([$saved['mappings'][1], $saved['mappings'][2]['children'][0]] as $row) {
            $this->assertSame('format_list', $row['transform']);
            $this->assertSame(['separator' => ', '] + $this->example(), array_intersect_key($row['options'], ['separator' => true] + $this->example()));
        }

        $copy = (new PresetEditor($this->services))->duplicate($preset['id']);
        $this->assertSame($saved['mappings'], $copy['mappings']);
        $this->assertSame(Fingerprint::mapping_hash($saved), Fingerprint::mapping_hash($copy));

        $hash = static function (array $rows) use ($saved): string {
            return Fingerprint::mapping_hash(['mappings' => $rows] + $saved);
        };
        $base = $hash($saved['mappings']);
        $reordered = $saved['mappings'];
        $reordered[1]['id'] = 'renamed';
        $this->assertSame($base, $hash($reordered), 'row IDs do not count');
        foreach ([['join_datetime', false], ['output_format', 'M j'], ['input_timezone', 'America/Denver']] as [$key, $value]) {
            $changed = $saved['mappings'];
            $changed[1]['options'][$key] = $value;
            $this->assertNotSame($base, $hash($changed), $key);
            $nested = $saved['mappings'];
            $nested[2]['children'][0]['options'][$key] = $value;
            $this->assertNotSame($base, $hash($nested), 'nested ' . $key);
        }
        $none = $saved['mappings'];
        $none[1]['transform'] = 'none';
        $this->assertNotSame($base, $hash($none), 'switching transformation');
    }

    /** A changed output format rewrites posts on the next run; an unchanged preset does not. */
    public function testFormatChangesUpdateImportedPosts(): void
    {
        $preset = $this->savePreset(['mappings' => [$this->listRow()]]);
        $record = ['dates_text' => $this->dates()];
        $this->import($preset, $record);
        $id = (int) $this->posts()[0]['ID'];
        $this->assertSame(1, $this->import($preset, $record)['counts']['unchanged']);
        $preset['mappings'][0]['options']['output_format'] = 'M j';
        $preset = $this->services->presets()->save($preset, 1);
        $this->assertSame(1, $this->import($preset, $record)['counts']['updated']);
        $this->assertSame(['Nov 18', 'Nov 20', 'Dec 2'], $this->meta($id, 'event_dates_selection_options'));
    }

    /** Fingerprints of every existing transformation follow the historical rule exactly. */
    public function testExistingFingerprintsAreUnchanged(): void
    {
        $rows = [];
        foreach (array_diff(Transformer::TYPES, ['format_list']) as $index => $type) {
            foreach ([false, true] as $dates) {
                $rows[] = $this->row('value' . $index, 'meta', 'key_' . $type . (int) $dates, [
                    'transform' => $type, 'options' => ['join_datetime' => $dates, 'output_format' => 'F j'],
                ]);
            }
        }
        $preset = $this->savePreset(['mappings' => $rows]);
        $historical = static function (array $rows) use (&$historical): array {
            foreach ($rows as &$row) {
                unset($row['id'], $row['media'], $row['condition']);
                if ($row['transform'] !== 'join' || !$row['options']['join_datetime']) {
                    unset($row['options']['join_datetime']);
                }
                $row['children'] = $historical($row['children']);
            }
            unset($row);
            return $rows;
        };
        $old = hash('sha256', wp_json_encode(['post_type' => 'events', 'mappings' => $historical($preset['mappings'])], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $this->assertSame($old, Fingerprint::mapping_hash($preset));
        $example = $this->savePreset();
        $this->assertSame(Fingerprint::mapping_hash($example), Fingerprint::mapping_hash(['mappings' => $historical($example['mappings'])] + $example));
    }

    /** Exports keep the transformation and options; older and malformed files behave as before. */
    public function testPresetExportImportAndCompatibility(): void
    {
        $preset = $this->savePreset(['mappings' => [
            $this->listRow(),
            $this->listRow('dates_text', 'field_day_select'),
            $this->row('group', 'acf', 'field_list_group', ['children' => [$this->listRow('dates', 'field_group_list')]]),
        ]]);
        $transfer = $this->services->transfer();
        $export = $transfer->export($preset['id']);
        $this->assertSame('format_list', $export['preset']['mappings'][0]['transform']);
        $this->assertSame(self::INPUT_FORMAT, $export['preset']['mappings'][0]['options']['input_format']);
        $imported = $transfer->save($transfer->decode(wp_json_encode($export)), 'events', 1, []);
        $this->assertSame($preset['mappings'], $imported['mappings']);
        $this->assertSame(Fingerprint::mapping_hash($preset), Fingerprint::mapping_hash($imported));

        $legacy = $this->savePreset();
        $old = $transfer->export($legacy['id']);
        $strip = static function (array $rows) use (&$strip): array {
            foreach ($rows as &$row) {
                unset($row['options']['join_datetime'], $row['condition']);
                $row['children'] = $strip($row['children']);
            }
            unset($row);
            return $rows;
        };
        $old['preset']['mappings'] = $strip($old['preset']['mappings']);
        $copy = $transfer->save($transfer->decode(wp_json_encode($old)), 'events', 1, []);
        $this->assertSame(Fingerprint::mapping_hash($legacy), Fingerprint::mapping_hash($copy));

        $bad = $export;
        $bad['preset']['mappings'][0]['options']['join_datetime'] = 'true';
        $this->assertThrows(\InvalidArgumentException::class, fn () => $transfer->decode(wp_json_encode($bad)), 'join_datetime');
        $bad = $export;
        $bad['preset']['mappings'][0]['transform'] = 'format_lists';
        $this->assertThrows(\InvalidArgumentException::class, fn () => $transfer->decode(wp_json_encode($bad)), 'preset.mappings.0.transform');

        // A same-named local select that only takes one value is reported during review.
        \AcfStub::$groups[1]['fields'][2]['multiple'] = 0;
        $this->services->acf_fields()->reset();
        $review = $transfer->review($transfer->decode(wp_json_encode($export)), 'events', 1);
        $this->assertContains('cannot store a list', implode(' ', $review['problems']));
    }
}
