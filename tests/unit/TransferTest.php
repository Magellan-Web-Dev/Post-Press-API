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
}
