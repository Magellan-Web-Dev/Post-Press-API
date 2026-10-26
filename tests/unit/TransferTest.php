<?php
/** Portable preset configuration, local field resolution, and admin boundaries. */

declare(strict_types=1);

use PostPressAPI\Admin\AdminActions;
use PostPressAPI\Admin\AdminPage;
use PostPressAPI\Admin\Views\TransferView;
use PostPressAPI\Mapping\MarkupPolicy;
use PostPressAPI\Presets\PresetTransfer;
use PostPressAPI\Tests\TestCase;

final class TransferTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
    }

    /** A decoded export with nested mappings and configured scheduling. */
    private function envelope(): array
    {
        $preset = $this->savePreset(['schedule' => ['frequency' => 'daily'], 'sync' => ['match' => ['type' => 'acf', 'key' => 'field_external_id']]]);
        $this->services->secrets()->set($preset['id'], 'do-not-export-token', 'https://api.example.com:443');
        $this->services->presets()->save_sample($preset['id'], wp_json_encode($this->fixture()));
        return $this->services->transfer()->decode(wp_json_encode($this->services->transfer()->export($preset['id'], true)));
    }

    /** Replaces every ACF key while retaining field names and hierarchy. */
    private function changeLocalKeys(): void
    {
        $walk = static function (array &$fields) use (&$walk): void {
            foreach ($fields as &$field) {
                $field['key'] .= '_local';
                $field['parent'] .= '_local';
                if (isset($field['sub_fields'])) { $walk($field['sub_fields']); }
            }
            unset($field);
        };
        foreach (AcfStub::$groups as &$group) { $walk($group['fields']); }
        unset($group);
        $this->services->acf_fields()->reset();
    }

    /** Removes a local ACF field (at any depth), as if this site never registered it. */
    private function removeLocalField(string $key): void
    {
        $walk = static function (array &$fields) use (&$walk, $key): void {
            $fields = array_values(array_filter($fields, static fn (array $field): bool => $field['key'] !== $key));
            foreach ($fields as &$field) {
                if (isset($field['sub_fields'])) { $walk($field['sub_fields']); }
            }
            unset($field);
        };
        foreach (AcfStub::$groups as &$group) { $walk($group['fields']); }
        unset($group);
        $this->services->acf_fields()->reset();
    }

    /** An export whose mappings also carry conditions, fallbacks, formats, and media options. */
    private function richEnvelope(): array
    {
        $mappings = $this->exampleMappings();
        $mappings[4] = $this->row('submitter.email', 'acf', 'field_email', ['on_null' => 'fallback', 'fallback' => 'events@example.com',
            'condition' => ['enabled' => true, 'operator' => 'contains', 'value' => 'example.org', 'action' => 'clear'] + \PostPressAPI\Mapping\MappingCondition::DEFAULTS]);
        $mappings[5] = $this->row('sessions[0].date', 'acf', 'field_first_date', ['transform' => 'datetime', 'options' => ['input_timezone' => 'America/New_York', 'output_format' => 'Ymd']]);
        $mappings[] = $this->row('image', 'acf', 'field_image', ['media' => ['download' => true, 'output' => 'id']]);
        $preset = $this->savePreset(['mappings' => $mappings, 'schedule' => ['frequency' => 'daily'], 'sync' => ['match' => ['type' => 'acf', 'key' => 'field_external_id'], 'trashed' => 'restore']]);
        return $this->services->transfer()->decode(wp_json_encode($this->services->transfer()->export($preset['id'], true)));
    }

    /** Admin screen that handled the latest submitImport(). */
    private ?AdminPage $page = null;

    /** Posts the import form through the admin handler; returns the redirect URL ('' when none). */
    private function submitImport(array $fields): string
    {
        $_POST = wp_slash($fields);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST['_ppapi_import_nonce'] = wp_create_nonce('ppapi_import_preset');
        $redirect = '';
        $this->page = new AdminPage($this->services, static function ($url) use (&$redirect): void { $redirect = $url; });
        $this->page->handle_save();
        return $redirect;
    }

    /** Renders the import screen as it appears after the latest submission. */
    private function renderImport(): string
    {
        $_GET['view'] = 'import';
        ob_start();
        $this->page->render();
        return (string) ob_get_clean();
    }

    /** The values a browser would post from the rendered review form (checked boxes, selected options). */
    private function formValues(string $html): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $form = $xpath->query('//form[.//input[@name="ppapi_import_reviewed"]]')->item(0);
        $this->assertNotNull($form, 'review form');
        $values = [];
        $add = static function (string $name, string $value) use (&$values): void {
            if (preg_match('/^([^\[]+)\[([^\]]*)\]$/', $name, $m)) {
                if ($m[2] === '') { $values[$m[1]][] = $value; } else { $values[$m[1]][$m[2]] = $value; }
            } else {
                $values[$name] = $value;
            }
        };
        /** @var DOMElement $input */
        foreach ($xpath->query('.//input', $form) as $input) {
            if (in_array($input->getAttribute('type'), ['checkbox', 'radio'], true) && !$input->hasAttribute('checked')) { continue; }
            $add($input->getAttribute('name'), $input->getAttribute('value'));
        }
        /** @var DOMElement $select */
        foreach ($xpath->query('.//select', $form) as $select) {
            /** @var DOMElement|null $option */
            $option = $xpath->query('.//option[@selected]', $select)->item(0) ?? $xpath->query('.//option', $select)->item(0);
            if ($option !== null) { $add($select->getAttribute('name'), $option->getAttribute('value')); }
        }
        /** @var DOMElement $textarea */
        foreach ($xpath->query('.//textarea', $form) as $textarea) {
            $add($textarea->getAttribute('name'), $textarea->textContent);
        }
        return $values;
    }

    public function testRoundTripPreservesSettingsButNeverCredentialsOrRuntimeState(): void
    {
        $data = $this->envelope();
        $encoded = wp_json_encode($data);
        $this->assertNotContains('do-not-export-token', $encoded);
        $this->assertFalse(isset($data['preset']['id']));
        $this->assertFalse(isset($data['preset']['enabled']));
        $this->assertFalse(isset($data['preset']['revision']));
        $before = count($this->services->presets()->all());
        $review = $this->services->transfer()->review($data);
        $this->assertSame([], $review['problems']);
        $this->assertSame($before, count($this->services->presets()->all()), 'review creates no preset');
        $saved = $this->services->transfer()->save($data, 'events', 1, []);
        $this->assertFalse($saved['enabled']);
        $this->assertSame(1, $saved['revision']);
        foreach (['request', 'response', 'mappings', 'sync', 'pagination', 'schedule', 'batch_size'] as $key) {
            $this->assertSame($data['preset'][$key], $saved[$key], $key);
        }
        $this->assertSame('daily', $saved['schedule']['frequency']);
        $this->assertNull($this->services->scheduler()->next_run($saved['id']));
        $this->assertFalse($this->services->secrets()->has($saved['id']));
        $this->assertSame($data['sample'], $this->services->presets()->sample($saved['id']));
        $this->assertSame([], $this->services->runs()->history($saved['id']));
        $this->assertSame([], WPStub::$http_log);
        $this->assertSame([], WPStub::$posts);
        $again = $this->services->transfer()->save($data, 'events', 1, []);
        $this->assertNotSame($saved['id'], $again['id']);
    }

    public function testRemapsDifferentAcfKeysIncludingChildrenAndSyncMatch(): void
    {
        $data = $this->envelope();
        $this->changeLocalKeys();
        $review = $this->services->transfer()->review($data);
        $this->assertSame([], $review['problems']);
        $this->assertSame('field_virtual_local', $review['preset']['mappings'][2]['target']);
        $this->assertSame('field_sessions_local', $review['preset']['mappings'][6]['target']);
        $this->assertSame('field_session_date_local', $review['preset']['mappings'][6]['children'][0]['target']);
        $this->assertSame('field_external_id_local', $review['preset']['sync']['match']['key']);
        $saved = $this->services->transfer()->save($data, 'events', 1, []);
        $this->assertSame('field_sessions_local', $saved['mappings'][6]['target']);
    }

    public function testAmbiguousMatchRequiresExplicitCompatibleSelection(): void
    {
        $data = $this->envelope();
        $this->changeLocalKeys();
        AcfStub::add_group('group_other', 'Other', ['events'], [['key' => 'field_virtual_other', 'name' => 'is_virtual', 'type' => 'true_false']]);
        $this->services->acf_fields()->reset();
        $review = $this->services->transfer()->review($data);
        $this->assertContains('No unique compatible match', implode(' ', $review['problems']));
        $this->assertSame('', $review['choices']['mappings.2']['selected']);
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->services->transfer()->save($data, 'events', 1, []));
        $selected = $this->services->transfer()->review($data, 'events', 1, ['mappings.2' => 'field_virtual_local']);
        $this->assertSame([], $selected['problems']);
        $wrong = $this->services->transfer()->review($data, 'events', 1, ['mappings.2' => 'field_email_local']);
        $this->assertTrue($wrong['problems'] !== []);
    }

    public function testMissingDependenciesRetainMappingsForReview(): void
    {
        $data = $this->envelope();
        AcfStub::$groups = [];
        $this->services->acf_fields()->reset();
        $review = $this->services->transfer()->review($data, 'missing_type', 1);
        $this->assertCount(count($data['preset']['mappings']), $review['preset']['mappings']);
        $this->assertContains('registered local post type', implode(' ', $review['problems']));
        $this->assertTrue(count($review['choices']) > 0);
    }

    public function testAuthorResolvedByLoginAndNeverBySourceNumericId(): void
    {
        $data = $this->envelope();
        $data['preset']['destination']['author'] = 987;
        $review = $this->services->transfer()->review($data);
        $this->assertSame(1, $review['preset']['destination']['author']);
        $data['author_login'] = 'not_on_this_site';
        $review = $this->services->transfer()->review($data);
        $this->assertSame(1, $review['preset']['destination']['author']);
        $this->assertContains('source author was not found', implode(' ', $review['warnings']));
        $this->assertThrows(InvalidArgumentException::class, fn () => $this->services->transfer()->save($data, 'events', 0, []));
    }

    public function testMalformedVersionsTypesAndTruncationAreRejected(): void
    {
        $data = $this->envelope();
        foreach (['{broken', '[]', str_repeat(' ', PresetTransfer::MAX_BYTES + 1)] as $json) {
            $this->assertThrows(InvalidArgumentException::class, fn () => $this->services->transfer()->decode($json));
        }
        $mutations = [
            static function (&$d) { $d['format_version'] = 999; },
            static function (&$d) { $d['preset']['request']['timeout'] = 900; },
            static function (&$d) { $d['preset']['mappings'][0]['transform'] = 'execute_php'; },
            static function (&$d) { $d['preset']['mappings'][0]['media']['download'] = 'false'; },
            static function (&$d) { $d['preset']['mappings'] = array_fill(0, 101, $d['preset']['mappings'][0]); },
            static function (&$d) { $d['preset']['mappings'][0]['source'] = str_repeat('x', 10001); },
            static function (&$d) { $d['preset']['request']['auth'] = 'bad'; },
            static function (&$d) { $d['preset']['unexpected'] = 'hidden'; },
            static function (&$d) { $d['preset']['id'] = 'a12345678901'; },
        ];
        foreach ($mutations as $mutate) {
            $copy = $data;
            $mutate($copy);
            $this->assertThrows(InvalidArgumentException::class, fn () => $this->services->transfer()->decode(wp_json_encode($copy)));
        }
    }

    /** Confirmed regression: files exported by 1.0.5 have no shortcode/block setting. */
    public function testExportsFromBeforeTheMarkupSettingImportWithTheNewPresetDefault(): void
    {
        $data = $this->envelope();
        unset($data['preset']['destination']['markup'], $data['preset']['destination']['shortcodes']);
        $data['plugin_version'] = '1.0.5';

        $decoded = $this->services->transfer()->decode(wp_json_encode($data));
        $this->assertSame($data, $decoded, 'the file itself is not rewritten');
        $review = $this->services->transfer()->review($decoded);
        $this->assertSame([], $review['problems']);
        $this->assertContains('exported before the shortcode and block setting existed', implode(' ', $review['warnings']));

        $saved = $this->services->transfer()->save($decoded, 'events', 1, []);
        $this->assertSame(MarkupPolicy::NEUTRALIZE, $saved['destination']['markup']);
        $this->assertSame([], $saved['destination']['shortcodes']);
        $this->assertFalse($saved['enabled']);

        // Current exports keep their setting, and only a file missing both keys is migrated.
        $current = $this->envelope();
        $current['preset']['destination']['markup'] = MarkupPolicy::TRUSTED;
        $this->assertSame(MarkupPolicy::TRUSTED, $this->services->transfer()->save($this->services->transfer()->decode(wp_json_encode($current)), 'events', 1, [])['destination']['markup']);
        $this->assertNotContains('exported before the shortcode', implode(' ', $this->services->transfer()->review($current)['warnings']));
        foreach (['markup', 'shortcodes'] as $key) {
            $partial = $this->envelope();
            unset($partial['preset']['destination'][$key]);
            $this->assertThrows(InvalidArgumentException::class, fn () => $this->services->transfer()->decode(wp_json_encode($partial)), 'preset.destination');
        }
    }

    public function testMediaOptionsAndSampleOptInSurviveExport(): void
    {
        $preset = $this->savePreset(['mappings' => [$this->row('image', 'acf', 'field_image', ['media' => ['download' => true, 'output' => 'id']])]]);
        $this->services->presets()->save_sample($preset['id'], '{"private":"example"}');
        $data = $this->services->transfer()->export($preset['id']);
        $this->assertSame('', $data['sample']);
        $data = $this->services->transfer()->decode(wp_json_encode($data));
        $saved = $this->services->transfer()->save($data, 'events', 1, []);
        $this->assertTrue($saved['mappings'][0]['media']['download']);
        $this->assertSame('id', $saved['mappings'][0]['media']['output']);
    }

    public function testExportHandlerChecksNonceAndReturnsDownload(): void
    {
        $preset = $this->savePreset();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['preset' => $preset['id']];
        $_REQUEST = [];
        $download = null;
        $actions = new AdminActions($this->services, null, static function ($filename, $json) use (&$download): void { $download = [$filename, $json]; });
        $this->assertThrows(RuntimeException::class, fn () => $actions->export());
        $_REQUEST['_wpnonce'] = wp_create_nonce('ppapi_export_' . $preset['id']);
        $actions->export();
        $this->assertContains('.json', $download[0]);
        $this->assertSame('Events feed', json_decode($download[1], true)['preset']['name']);
        wpstub_add_user(2, 'subscriber', ['read']);
        wp_set_current_user(2);
        $this->assertThrows(RuntimeException::class, fn () => $actions->export());
    }

    public function testReviewFormAndAdminImportSaveAreUsableAndEscaped(): void
    {
        $data = $this->envelope();
        $data['preset']['name'] = '<script>alert(1)</script>';
        $review = $this->services->transfer()->review($data);
        ob_start();
        (new TransferView($this->services))->render($data, $review);
        $html = ob_get_clean();
        $this->assertContains('Create disabled preset', $html);
        $this->assertContains('mappings.6.children.0', $html);
        $this->assertNotContains('<script>alert(1)</script>', $html);
        $_POST = wp_slash(['ppapi_action' => 'import_save', 'ppapi_import_json' => wp_json_encode($data), 'ppapi_import_type' => 'events', 'ppapi_import_author' => '1']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST['_ppapi_import_nonce'] = wp_create_nonce('ppapi_import_preset');
        $redirect = '';
        $page = new AdminPage($this->services, static function ($url) use (&$redirect): void { $redirect = $url; });
        $page->handle_save();
        $this->assertContains('ppapi_notice=imported', $redirect);
        $this->assertSame([], WPStub::$http_log);
    }

    /** An unavailable field can be left out explicitly; everything else imports exactly as exported. */
    public function testRemovingAnUnavailableTopLevelFieldImportsTheRest(): void
    {
        $data = $this->richEnvelope();
        $existing = $this->services->presets()->all();
        $this->removeLocalField('field_meeting');
        $transfer = $this->services->transfer();
        $this->assertContains('mappings.3: choose a local ACF field for meeting_url, or remove this mapping from the import.', implode(' ', $transfer->review($data, 'events', 1)['problems']));

        $review = $transfer->review($data, 'events', 1, [], ['mappings.3']);
        $this->assertSame([], $review['problems']);
        $this->assertSame(['mappings.3'], $review['omitted']);
        $this->assertTrue($review['choices']['mappings.3']['excluded']);
        $this->assertSame('Mapping 4', $review['choices']['mappings.4']['label'], 'later rows are numbered as in the new preset');
        $this->assertContains('Removed from this import and not included in the new preset: mappings.3 (meeting_url).', implode(' ', $review['warnings']));

        $saved = $transfer->save($data, 'events', 1, [], ['mappings.3']);
        $expected = $data['preset']['mappings'];
        array_splice($expected, 3, 1);
        $this->assertSame($expected, $saved['mappings'], 'retained rows keep their order, IDs, and every setting');
        $this->assertTrue($saved['mappings'][3]['condition']['enabled']);
        $this->assertSame('events@example.com', $saved['mappings'][3]['fallback']);
        $this->assertSame('Ymd', $saved['mappings'][4]['options']['output_format']);
        $this->assertTrue($saved['mappings'][9]['media']['download']);
        foreach (['name', 'request', 'response', 'sync', 'pagination', 'schedule', 'batch_size'] as $key) {
            $this->assertSame($data['preset'][$key], $saved[$key], $key);
        }
        $destination = $data['preset']['destination'];
        $destination['author'] = 1; // Exports carry the author by login, not ID.
        $this->assertSame($destination, $saved['destination']);
        $this->assertFalse($saved['enabled']);
        $this->assertNull($this->services->scheduler()->next_run($saved['id']));
        $this->assertSame([], WPStub::$http_log, 'no API requests or image downloads');
        $this->assertSame([], WPStub::$posts);
        $this->assertCount(count($existing) + 1, $this->services->presets()->all());
        foreach ($existing as $preset) {
            $this->assertSame($preset, $this->services->presets()->find($preset['id']), 'existing presets are unchanged');
        }
    }

    /** Nothing is dropped automatically: another row's removal or a blank choice leaves the problem in place. */
    public function testUnresolvedFieldsStillBlockImportUntilExplicitlyRemoved(): void
    {
        $data = $this->envelope();
        $this->removeLocalField('field_meeting');
        $transfer = $this->services->transfer();
        $other = $transfer->review($data, 'events', 1, [], ['mappings.8']);
        $this->assertContains('mappings.3: choose a local ACF field', implode(' ', $other['problems']));
        $this->assertSame('field_meeting', $other['preset']['mappings'][3]['target'], 'the unresolved row is kept for review, not discarded');
        $this->assertThrows(InvalidArgumentException::class, fn () => $transfer->save($data, 'events', 1, [], ['mappings.8']));

        $before = count($this->services->presets()->all());
        $redirect = $this->submitImport(['ppapi_action' => 'import_save', 'ppapi_import_json' => wp_json_encode($data), 'ppapi_import_type' => 'events', 'ppapi_import_author' => '1', 'ppapi_import_remaps' => ['mappings.3' => '']]);
        $this->assertSame('', $redirect);
        $this->assertSame($before, count($this->services->presets()->all()));
        $html = $this->renderImport();
        $this->assertContains('mappings.3: choose a local ACF field for meeting_url', $html);
        $this->assertFalse(isset($this->formValues($html)['ppapi_import_exclude']), 'nothing is ticked for removal');
    }

    public function testAnAutomaticallyMatchedMappingCanBeRemoved(): void
    {
        $data = $this->envelope();
        $transfer = $this->services->transfer();
        $review = $transfer->review($data, 'events', 1, [], ['mappings.2']);
        $this->assertSame([], $review['problems']);
        $this->assertSame('field_virtual', $review['choices']['mappings.2']['selected'], 'its match stays visible for a restore');
        $saved = $transfer->save($data, 'events', 1, [], ['mappings.2']);
        $this->assertSame(array_values(array_diff_key($data['preset']['mappings'], [2 => true])), $saved['mappings']);
        $this->assertNotContains('field_virtual', array_column($saved['mappings'], 'target'));
        $this->assertSame($data['preset']['mappings'], $transfer->save($data, 'events', 1, [])['mappings'], 'without the removal it is imported');
    }

    public function testNestedRemovalsKeepSiblingsAndExplainEmptyContainers(): void
    {
        $data = $this->envelope();
        $transfer = $this->services->transfer();
        $repeater = $data['preset']['mappings'][6];

        // One sub field: its sibling and the repeater keep every setting.
        $child = $transfer->save($data, 'events', 1, [], ['mappings.6.children.1']);
        $expected = $repeater;
        $expected['children'] = [$repeater['children'][0]];
        $this->assertSame($expected, $child['mappings'][6]);
        $this->assertCount(count($data['preset']['mappings']), $child['mappings']);

        // The repeater: its whole subtree goes, and an unavailable sub field no longer blocks the import.
        $this->removeLocalField('field_session_date');
        $this->assertContains('mappings.6.children.0: choose a local ACF field', implode(' ', $transfer->review($data, 'events', 1)['problems']));
        $parent = $transfer->review($data, 'events', 1, [], ['mappings.6']);
        $this->assertSame([], $parent['problems']);
        $this->assertSame(['mappings.6', 'mappings.6.children.0', 'mappings.6.children.1'], $parent['omitted']);
        $this->assertSame('mappings.6', $parent['choices']['mappings.6.children.0']['omitted_with']);
        $this->assertFalse($parent['choices']['mappings.6.children.0']['excluded'], 'its own choice is kept for a restore');
        $this->assertContains('mappings.6 (sessions) with 2 nested mappings', implode(' ', $parent['warnings']));
        $saved = $transfer->save($data, 'events', 1, [], ['mappings.6']);
        $this->assertSame(array_values(array_diff_key($data['preset']['mappings'], [6 => true])), $saved['mappings']);

        // Removing every sub field of a retained repeater asks to restore one or remove the repeater.
        $all = ['mappings.6.children.0', 'mappings.6.children.1'];
        $problems = implode(' ', $transfer->review($data, 'events', 1, [], $all)['problems']);
        $this->assertContains('mappings.6 (sessions): every nested mapping is removed from this import', $problems);
        $this->assertContains('Restore at least one nested mapping, or remove mappings.6 as well.', $problems);
        $this->assertThrows(InvalidArgumentException::class, fn () => $transfer->save($data, 'events', 1, [], $all));
        $this->assertSame([], $transfer->review($data, 'events', 1, [], [...$all, 'mappings.6'])['problems']);

        // Restoring the repeater brings back each sub field's own choice: child 0 stays removed.
        $restored = $transfer->save($data, 'events', 1, [], ['mappings.6.children.0']);
        $this->assertSame([$repeater['children'][1]], $restored['mappings'][6]['children']);
    }

    /** The real form round trip: what the review renders is what the next submission posts. */
    public function testRemovalsAndChoicesSurviveRefreshPostTypeChangesAndValidationErrors(): void
    {
        $data = $this->envelope();
        $this->changeLocalKeys();
        $this->removeLocalField('field_meeting_local');
        // A second "session_time" sub field makes that mapping need an explicit choice.
        foreach (AcfStub::$groups[0]['fields'] as &$field) {
            if ($field['key'] === 'field_sessions_local') {
                $field['sub_fields'][] = ['key' => 'field_session_time_alt', 'name' => 'session_time', 'label' => 'Alternate time', 'type' => 'time_picker', 'parent' => 'field_sessions_local'];
            }
        }
        unset($field);
        $this->services->acf_fields()->reset();
        register_post_type('news', ['label' => 'News', 'show_ui' => true]);
        $submit = function (array $form, string $action): string {
            $form['ppapi_action'] = $action;
            $form['ppapi_import_author'] ??= '1'; // The users dropdown stub has no options.
            return $this->submitImport($form);
        };

        $submit(['ppapi_import_json' => wp_json_encode($data), 'ppapi_import_type' => 'events'], 'import_review');
        $form = $this->formValues($this->renderImport());
        $this->assertSame('', $form['ppapi_import_remaps']['mappings.2'], 'automatic matches post blank and are re-checked');
        $this->assertSame('', $form['ppapi_import_remaps']['mappings.6.children.1'], 'ambiguous');

        // Remove the unavailable meeting URL and choose the session time explicitly, then refresh.
        $form['ppapi_import_exclude'] = ['mappings.3'];
        $form['ppapi_import_remaps']['mappings.6.children.1'] = 'field_session_time_alt';
        $submit($form, 'import_review');
        $html = $this->renderImport();
        $this->assertNotContains('choose a local ACF field', $html);
        $this->assertContains('Automatic match: Event details › is_virtual (field_virtual_local)', $html);
        $form = $this->formValues($html);
        $this->assertSame(['mappings.3'], $form['ppapi_import_exclude']);
        $this->assertSame('field_session_time_alt', $form['ppapi_import_remaps']['mappings.6.children.1']);
        $this->assertSame('mappings.3', $form['ppapi_import_reviewed']);

        // A post type without these fields keeps the removal and the explicit choice.
        $form['ppapi_import_type'] = 'news';
        $submit($form, 'import_review');
        $html = $this->renderImport();
        $this->assertContains('mappings.2: choose a local ACF field', $html);
        $this->assertContains('field_session_time_alt (not available here)', $html);
        $form = $this->formValues($html);
        $this->assertSame(['mappings.3'], $form['ppapi_import_exclude']);
        $this->assertSame('field_session_time_alt', $form['ppapi_import_remaps']['mappings.6.children.1']);

        // Back to events: everything resolves again without choosing anything twice.
        $form['ppapi_import_type'] = 'events';
        $submit($form, 'import_review');
        $html = $this->renderImport();
        $this->assertNotContains('choose a local ACF field', $html);
        $form = $this->formValues($html);

        // A validation error on save (no author) keeps every choice.
        $form['ppapi_import_author'] = '0';
        $this->assertSame('', $submit($form, 'import_save'));
        $html = $this->renderImport();
        $this->assertContains('Choose a local author before importing.', $html);
        $form = $this->formValues($html);
        $this->assertSame(['mappings.3'], $form['ppapi_import_exclude']);
        $this->assertSame('field_session_time_alt', $form['ppapi_import_remaps']['mappings.6.children.1']);

        // Fixed: the preset is created with exactly the reviewed choices.
        $redirect = $submit($form, 'import_save');
        $this->assertContains('ppapi_notice=imported', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $saved = $this->services->presets()->find($query['preset']);
        $this->assertCount(count($data['preset']['mappings']) - 1, $saved['mappings']);
        $this->assertSame('event.virtual', $saved['mappings'][2]['source']);
        $this->assertSame('field_virtual_local', $saved['mappings'][2]['target']);
        $this->assertSame('submitter.email', $saved['mappings'][3]['source']);
        $this->assertSame(['field_session_date_local', 'field_session_time_alt'], array_column($saved['mappings'][5]['children'], 'target'));
    }

    /** Choices are keyed by the uploaded path, so removing earlier rows never moves them to a neighbour. */
    public function testRemovingAnEarlierMappingNeverShiftsLaterChoices(): void
    {
        $data = $this->envelope();
        $this->changeLocalKeys();
        // Second compatible fields: these mappings need explicit choices.
        AcfStub::add_group('group_other', 'Other', ['events'], [
            ['key' => 'field_email_other', 'name' => 'contact_email', 'type' => 'email'],
            ['key' => 'field_date_other', 'name' => 'first_date', 'type' => 'date_picker'],
        ]);
        $this->services->acf_fields()->reset();
        $transfer = $this->services->transfer();
        $remaps = ['mappings.4' => 'field_email_other', 'mappings.5' => 'field_date_other', 'mappings.6.children.1' => 'field_session_time_local'];
        $ids = array_column($data['preset']['mappings'], 'id');

        $saved = $transfer->save($data, 'events', 1, $remaps, ['mappings.2', 'mappings.3', 'mappings.6.children.0']);
        $this->assertSame(array_values(array_diff_key($ids, [2 => true, 3 => true])), array_column($saved['mappings'], 'id'));
        $this->assertSame(['submitter.email', 'field_email_other'], [$saved['mappings'][2]['source'], $saved['mappings'][2]['target']]);
        $this->assertSame(['sessions[0].date', 'field_date_other'], [$saved['mappings'][3]['source'], $saved['mappings'][3]['target']]);
        $this->assertSame([['time', 'field_session_time_local']], array_map(static fn (array $row): array => [$row['source'], $row['target']], $saved['mappings'][4]['children']));
        $this->assertSame('field_city_local', $saved['mappings'][6]['target']);

        // Removing the explicitly mapped email row by its path takes that row, not a shifted neighbour.
        $saved = $transfer->save($data, 'events', 1, $remaps, ['mappings.3', 'mappings.4']);
        $this->assertNotContains('submitter.email', array_column($saved['mappings'], 'source'));
        $this->assertSame(['sessions[0].date', 'field_date_other'], [$saved['mappings'][3]['source'], $saved['mappings'][3]['target']]);
    }

    public function testDisablingExistingPostMatchingIsSeparateFromMappings(): void
    {
        $data = $this->envelope();
        $transfer = $this->services->transfer();
        $this->assertThrows(InvalidArgumentException::class, fn () => $transfer->review($data, 'events', 1, [], ['sync.match']), 'Only ACF mappings');

        $review = $transfer->review($data, 'events', 1, [], [], true);
        $this->assertSame([], $review['problems']);
        $this->assertTrue($review['match_disabled']);
        $this->assertSame([], $review['omitted']);
        $this->assertContains('will not look for existing posts through the ACF field legacy_id', implode(' ', $review['warnings']));
        $saved = $transfer->save($data, 'events', 1, [], [], true);
        $this->assertSame(['type' => 'none', 'key' => ''], $saved['sync']['match']);
        $this->assertSame($data['preset']['sync']['policy'], $saved['sync']['policy']);
        $this->assertSame($data['preset']['sync']['trashed'], $saved['sync']['trashed']);
        $this->assertSame($data['preset']['response']['identity'], $saved['response']['identity']);
        $this->assertSame($data['preset']['mappings'], $saved['mappings'], 'field mappings are unaffected');
        $this->assertFalse($saved['enabled']);

        // Once enabled: a post holding the ID is no longer located through the field, but this preset's own posts are tracked.
        $existing = wp_insert_post(['post_type' => 'events', 'post_title' => 'Already here', 'post_status' => 'draft']);
        update_field('field_external_id', 'ada78067-d914-41ae-82ec-9a4f0cb249c9', $existing);
        $saved['enabled'] = true;
        $this->services->presets()->save($saved, 1);
        $this->queueJson($this->fixture());
        $this->assertSame(1, $this->runPreset($saved['id'])['counts']['created']);
        $this->queueJson($this->fixture());
        $second = $this->runPreset($saved['id']);
        $this->assertSame([0, 1], [$second['counts']['created'], $second['counts']['unchanged']]);
        $this->assertCount(2, $this->posts());
        $this->assertSame('', $this->meta($existing, \PostPressAPI\Sync\PostWriter::META_KEY), 'the other post was not linked');

        // An unavailable matching field blocks the import until a field is chosen or matching is disabled.
        $this->removeLocalField('field_external_id');
        $this->assertContains('sync.match: choose a local ACF field for legacy_id, or disable matching existing posts.', implode(' ', $transfer->review($data, 'events', 1)['problems']));
        $this->assertSame([], $transfer->review($data, 'events', 1, [], [], true)['problems']);

        // The review keeps the choice visible and restorable.
        $this->submitImport(['ppapi_action' => 'import_review', 'ppapi_import_json' => wp_json_encode($data), 'ppapi_import_type' => 'events', 'ppapi_import_author' => '1', 'ppapi_import_disable_match' => '1']);
        $html = $this->renderImport();
        $this->assertContains('Disable matching existing posts', $html);
        $form = $this->formValues($html);
        $this->assertSame('1', $form['ppapi_import_disable_match']);
        $this->assertSame('sync.match', $form['ppapi_import_reviewed']);
        $this->assertArrayHasKey('sync.match', $form['ppapi_import_remaps']);

        // Only an ACF matching field can be disabled.
        $meta = $this->savePreset(['sync' => ['match' => ['type' => 'meta', 'key' => 'legacy_id']]]);
        $meta_data = $transfer->decode(wp_json_encode($transfer->export($meta['id'])));
        $this->assertThrows(InvalidArgumentException::class, fn () => $transfer->review($meta_data, 'events', 1, [], [], true), 'no matching to disable');
    }

    public function testInvalidOrManipulatedRemovalsAreRejected(): void
    {
        $data = $this->envelope();
        $transfer = $this->services->transfer();
        $before = count($this->services->presets()->all());
        $invalid = [['mappings.0'], ['mappings.7'], ['mappings.99'], ['mappings.6.children.5'], ['mappings.6.children'], ['mappings.2.children.0'],
            ['sync.match'], ['mappings.3 '], ['mappings.03'], [''], [['mappings.3']], [3], ['mappings.3', 'other'], ['preset.mappings.3']];
        foreach ($invalid as $excluded) {
            $this->assertThrows(InvalidArgumentException::class, fn () => $transfer->review($data, 'events', 1, [], $excluded), 'Only ACF mappings listed in this file');
            $this->assertThrows(InvalidArgumentException::class, fn () => $transfer->save($data, 'events', 1, [], $excluded));
        }

        foreach (['mappings.3', ['mappings.0'], ['x' => 'mappings.7']] as $posted) {
            $redirect = $this->submitImport(['ppapi_action' => 'import_save', 'ppapi_import_json' => wp_json_encode($data), 'ppapi_import_type' => 'events', 'ppapi_import_author' => '1', 'ppapi_import_exclude' => $posted]);
            $this->assertSame('', $redirect);
            $this->assertContains('Only ACF mappings listed in this file can be removed', $this->renderImport());
        }
        $this->assertSame($before, count($this->services->presets()->all()));

        // Choices travel beside the unchanged file, never inside it.
        $tampered = $data;
        $tampered['excluded'] = ['mappings.3'];
        $this->assertThrows(InvalidArgumentException::class, fn () => $transfer->decode(wp_json_encode($tampered)));

        // Nonce and capability checks still apply to removals.
        $_POST = wp_slash(['ppapi_action' => 'import_save', 'ppapi_import_json' => wp_json_encode($data), 'ppapi_import_type' => 'events', 'ppapi_import_author' => '1', 'ppapi_import_exclude' => ['mappings.3']]);
        $_REQUEST['_ppapi_import_nonce'] = 'invalid';
        $this->assertThrows(RuntimeException::class, fn () => (new AdminPage($this->services, static function (): void {}))->handle_save());
        wpstub_add_user(2, 'subscriber', ['read']);
        wp_set_current_user(2);
        $_REQUEST['_ppapi_import_nonce'] = wp_create_nonce('ppapi_import_preset');
        $this->assertThrows(RuntimeException::class, fn () => (new AdminPage($this->services, static function (): void {}))->handle_save());
        $this->assertSame($before, count($this->services->presets()->all()));
    }

    public function testWithoutRemovalsTheImportIsUnchanged(): void
    {
        $data = $this->envelope();
        $transfer = $this->services->transfer();
        $default = $transfer->review($data);
        $this->assertSame($default, $transfer->review($data, null, null, [], [], false));
        $this->assertSame([], $default['omitted']);
        $this->assertFalse($default['match_disabled']);
        $this->assertSame($data['preset']['mappings'], $default['preset']['mappings']);
        $this->assertSame($data['preset']['sync'], $default['preset']['sync']);
        $this->assertNotContains('Removed from this import', implode(' ', $default['warnings']));
        $this->assertNotContains('Matching existing posts is disabled', implode(' ', $default['warnings']));

        $this->submitImport(['ppapi_action' => 'import_review', 'ppapi_import_json' => wp_json_encode($data), 'ppapi_import_type' => 'events', 'ppapi_import_author' => '1']);
        $form = $this->formValues($this->renderImport());
        $this->assertFalse(isset($form['ppapi_import_exclude']));
        $this->assertFalse(isset($form['ppapi_import_disable_match']));
        $this->assertSame('', $form['ppapi_import_reviewed']);
        $form['ppapi_import_author'] = '1';
        $form['ppapi_action'] = 'import_save';
        $redirect = $this->submitImport($form);
        $this->assertContains('ppapi_notice=imported', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $saved = $this->services->presets()->find($query['preset']);
        $this->assertSame($data['preset']['mappings'], $saved['mappings']);
        $this->assertSame($data['preset']['sync'], $saved['sync']);
        $this->assertFalse($saved['enabled']);
    }

    /** Ticking a removal and choosing Create at once shows what will be omitted before anything is created. */
    public function testCreatingWaitsUntilOmissionsHaveBeenReviewed(): void
    {
        $data = $this->envelope();
        $before = count($this->services->presets()->all());
        $redirect = $this->submitImport(['ppapi_action' => 'import_save', 'ppapi_import_json' => wp_json_encode($data), 'ppapi_import_type' => 'events', 'ppapi_import_author' => '1', 'ppapi_import_reviewed' => '', 'ppapi_import_exclude' => ['mappings.6'], 'ppapi_import_disable_match' => '1']);
        $this->assertSame('', $redirect);
        $this->assertSame($before, count($this->services->presets()->all()));
        $html = $this->renderImport();
        $this->assertContains('the preset was not created yet', $html);
        $this->assertContains('mappings.6 (sessions) with 2 nested mappings', $html);
        $this->assertContains('Removed with mappings.6.', $html);
        $this->assertContains('Included as Mapping 8', $html, 'location.city moves up one place');
        $form = $this->formValues($html);
        $this->assertSame('mappings.6 mappings.6.children.0 mappings.6.children.1 sync.match', $form['ppapi_import_reviewed']);

        $form['ppapi_import_author'] = '1';
        $form['ppapi_action'] = 'import_save';
        $this->assertContains('ppapi_notice=imported', $this->submitImport($form));
        $this->assertContains('mappings.6 (sessions) with 2 nested mappings', implode(' ', get_transient('ppapi_warnings_1')), 'the editor lists what was left out');
        $this->assertSame($before + 1, count($this->services->presets()->all()));
    }
}
