<?php
/**
 * Preset storage, normalization, validation, slashing, and uninstall.
 */

declare(strict_types=1);

use PostPressAPI\Presets\PresetSchema;
use PostPressAPI\Sync\PostWriter;
use PostPressAPI\Tests\TestCase;

final class PresetsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
    }

    private function validate(array $raw, array $secret = ['action' => 'keep', 'value' => ''], ?array $existing = null): array
    {
        return $this->services->preset_validator()->validate($raw + ['name' => 'x'], $existing, $secret);
    }

    public function testPresetsPersistIndependentlyWithStableIds(): void
    {
        $a = $this->savePreset(['name' => 'A']);
        $b = $this->savePreset(['name' => 'B']);

        $this->assertNotSame($a['id'], $b['id']);
        $this->assertSame(['A', 'B'], array_column($this->services->presets()->all(), 'name'));
        $this->assertSame('no', \WPStub::$options['ppapi_preset_' . $a['id']]['autoload']);
        $this->assertSame('yes', \WPStub::$options['ppapi_presets']['autoload'], 'only the small index is autoloaded');

        $again = $this->services->presets()->save($a, 1);
        $this->assertSame($a['id'], $again['id']);
        $this->assertSame($a['revision'] + 1, $again['revision']);
    }

    public function testGeneratedIdsStartWithALetter(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->assertSame(1, preg_match('/^[a-f][a-f0-9]{11}$/', $this->services->presets()->create_id()));
        }
    }

    public function testAllDigitIdsSurviveTheIndex(): void
    {
        $saved = $this->savePreset(['id' => '123456789012']);

        $this->assertSame('123456789012', $saved['id']);
        $this->assertSame(['123456789012'], $this->services->presets()->ids());
        $this->assertCount(1, $this->services->presets()->all());
    }

    public function testNormalizationClampsAndDropsUnknownKeys(): void
    {
        $preset = PresetSchema::normalize([
            'request'     => ['timeout' => 999, 'method' => 'DELETE', 'max_kb' => -5, 'evil' => 'x', 'query' => [['key' => 'a', 'value' => 'b'], ['key' => '', 'value' => '']]],
            'destination' => ['post_type' => 'Events!'],
            'sync'        => ['policy' => 'nuke_everything'],
            'ID'          => 5,
        ]);

        $this->assertSame(60, $preset['request']['timeout']);
        $this->assertSame('GET', $preset['request']['method']);
        $this->assertSame(16, $preset['request']['max_kb']);
        $this->assertFalse(isset($preset['request']['evil']));
        $this->assertFalse(isset($preset['ID']));
        $this->assertCount(1, $preset['request']['query']);
        $this->assertSame('events', $preset['destination']['post_type']);
        $this->assertSame('update_changed', $preset['sync']['policy']);
    }

    public function testUnsafeRequestSettingsAreRejected(): void
    {
        $result = $this->validate([
            'request' => [
                'url'     => 'https://user:pass@api.example.com/x',
                'method'  => 'GET',
                'body'    => '{"a":1}',
                'headers' => [['name' => 'Authorization', 'value' => 'Bearer x'], ['name' => 'X-Test', 'value' => "a\r\nInjected: 1"], ['name' => 'bad name', 'value' => 'x']],
                'timeout' => '0',
            ],
        ]);

        $this->assertArrayHasKey('request.url', $result['errors']);
        $this->assertArrayHasKey('request.body', $result['errors']);
        $this->assertArrayHasKey('request.timeout', $result['errors']);
        $headers = implode(' ', $result['errors']['request.headers']);
        $this->assertContains('Authorization', $headers);
        $this->assertContains('line breaks', $headers);
        $this->assertContains('valid name', $headers);
    }

    public function testCredentialRulesAreValidated(): void
    {
        $insecure = $this->validate(['request' => ['url' => 'http://api.example.com', 'auth' => ['type' => 'bearer']]], ['action' => 'replace', 'value' => 'token']);
        $this->assertArrayHasKey('request.url', $insecure['errors']);

        $query = $this->validate(['request' => ['url' => 'https://api.example.com/?api_key=1', 'auth' => ['type' => 'api_key_query', 'name' => 'api_key'], 'query' => [['key' => 'api_key', 'value' => 'x']]]], ['action' => 'replace', 'value' => 'token']);
        $this->assertArrayHasKey('request.url', $query['errors']);
        $this->assertArrayHasKey('request.query', $query['errors']);

        $newline = $this->validate(['request' => ['url' => 'https://api.example.com', 'auth' => ['type' => 'bearer']]], ['action' => 'replace', 'value' => "tok\nen"]);
        $this->assertArrayHasKey('request.auth', $newline['errors']);
    }

    public function testEnablingRequiresACompletePreset(): void
    {
        $result = $this->validate(['enabled' => true, 'request' => ['url' => 'https://api.example.com']]);

        $enabled = implode(' ', $result['errors']['enabled'] ?? []);
        $this->assertContains('records path', $enabled);
        $this->assertContains('destination post type', $enabled);
        $this->assertContains('field mapping', $enabled);

        $draft = $this->validate(['enabled' => false, 'request' => ['url' => 'https://api.example.com']]);
        $this->assertSame([], $draft['errors'], 'incomplete presets can still be saved as drafts');
    }

    public function testDestinationsAreValidated(): void
    {
        $internal = $this->validate(['destination' => ['post_type' => 'acf-field-group']]);
        $this->assertArrayHasKey('destination.post_type', $internal['errors'], 'internal storage types are not destinations');

        $status = $this->validate(['destination' => ['post_type' => 'events', 'status' => 'trash']]);
        $this->assertArrayHasKey('destination.status', $status['errors']);

        wpstub_add_user(9, 'reader', ['read']);
        $author = $this->validate(['destination' => ['post_type' => 'events', 'status' => 'draft', 'author' => 9]]);
        $this->assertArrayHasKey('destination.author', $author['errors']);

        $types = $this->services->destinations()->post_types();
        $this->assertArrayHasKey('events', $types);
        $this->assertArrayHasKey('post', $types);
        $this->assertFalse(isset($types['acf-field-group']) || isset($types['attachment']) || isset($types['wp_block']));
    }

    public function testIdentityAndMatchingRules(): void
    {
        $wildcard = $this->validate(['response' => ['identity' => ['mode' => 'path', 'path' => 'items[*].id']]]);
        $this->assertArrayHasKey('response.identity', $wildcard['errors']);

        $composite_match = $this->validate([
            'destination' => ['post_type' => 'events'],
            'response'    => ['identity' => ['mode' => 'composite', 'paths' => "a\nb"]],
            'sync'        => ['match' => ['type' => 'meta', 'key' => 'legacy_id']],
        ]);
        $this->assertArrayHasKey('sync.match', $composite_match['errors']);

        $acf_match = $this->validate(['destination' => ['post_type' => 'events'], 'sync' => ['match' => ['type' => 'acf', 'key' => 'field_sessions']]]);
        $this->assertArrayHasKey('sync.match', $acf_match['errors'], 'only scalar text-like ACF fields can hold an external ID');
    }

    public function testSlashesQuotesAndRichTextSurviveTheRoundTrip(): void
    {
        $text    = 'C:\\Temp\\new "quoted" O\'Reilly \\n';
        $payload = $this->fixture();
        $payload['data'][0]['event']['title']       = $text;
        $payload['data'][0]['event']['description'] = '<p>Hello <strong>world</strong> \\ backslash</p><script>alert(1)</script>';
        $payload['data'][0]['website']['host']      = $text;

        $preset = $this->savePreset(['name' => $text, 'mappings' => [
            $this->row('event.title', 'core', 'post_title'),
            $this->row('event.description', 'core', 'post_content'),
            $this->row('event.title', 'meta', 'raw_title'),
            $this->row('website', 'acf', 'field_website', ['children' => [$this->row('host', 'acf', 'field_website_host')]]),
        ]]);
        $this->assertSame($text, $this->services->presets()->find($preset['id'])['name']);

        $this->queueJson($payload);
        $this->runPreset($preset['id']);
        $post = $this->posts()[0];

        $this->assertSame($text, $post['post_title']);
        $this->assertSame('<p>Hello <strong>world</strong> \\ backslash</p>', $post['post_content'], 'formatting kept, scripts removed');
        $this->assertSame($text, $this->meta((int) $post['ID'], 'raw_title'));
        $this->assertSame($text, $this->meta((int) $post['ID'], 'website_host'));

        $this->queueJson($payload);
        $this->assertSame(1, $this->runPreset($preset['id'])['counts']['unchanged'], 'slashes do not cause false changes');
    }

    public function testUninstallRemovesSettingsButNeverPosts(): void
    {
        $preset = $this->savePreset(['schedule' => ['frequency' => 'hourly']]);
        $this->services->scheduler()->sync($preset);
        $this->services->secrets()->set($preset['id'], 'token', 'https://api.example.com:443');
        $this->services->presets()->save_sample($preset['id'], '{"data":[]}');
        $this->queueJson($this->fixture());
        $this->runPreset($preset['id']);
        $this->services->logger()->info('hello');
        update_option('ppapix_unrelated', 'keep');
        update_option('other_plugin', 'keep');

        $post_id = (int) $this->posts()[0]['ID'];
        define('WP_UNINSTALL_PLUGIN', 'post-press-api/post-press-api.php');
        include PPAPI_TEST_ROOT . '/uninstall.php';

        $left = array_filter(array_keys(\WPStub::$options), static fn (string $name): bool => str_starts_with($name, 'ppapi_'));
        $this->assertSame([], array_values($left), 'all plugin settings removed');
        $this->assertSame('keep', get_option('ppapix_unrelated'));
        $this->assertSame('keep', get_option('other_plugin'));
        $this->assertSame(0, wpstub_count_events('ppapi_scheduled_run'));
        $this->assertCount(1, $this->posts(), 'imported posts kept');
        $this->assertSame('Medicare Open Enrollment Q&A (Virtual)', \WPStub::$posts[$post_id]['post_title']);
        $this->assertNotSame('', $this->meta($post_id, PostWriter::META_KEY), 'tracking meta kept so a reinstall reconnects');
    }
}
