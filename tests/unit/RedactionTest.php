<?php
/**
 * Credential redaction across every diagnostic: failed-record details, run
 * state and history, REST summaries, previews, the activity log, and hooks.
 * Imported business data is never altered by redaction.
 */

declare(strict_types=1);

use PostPressAPI\REST\RestController;
use PostPressAPI\Support\Redactor;
use PostPressAPI\Tests\TestCase;

final class RedactionTest extends TestCase
{
    /** Long enough that previews (40/60 characters) truncate it before redaction could see it whole. */
    private const SECRET = 'sk_live_SYNTHETIC_7f3a9c2e41d84b6f9e0a1c3d5b7f9e2a4c6d8f0b1a3c5e7d9f';

    private const HEADER_KEY = 'hdrkey_SYNTHETIC_55aa66bb';

    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
    }

    private function bearerPreset(array $overrides = []): array
    {
        $preset = $this->savePreset(array_replace_recursive(['request' => ['auth' => ['type' => 'bearer']]], $overrides) + ['mappings' => $overrides['mappings'] ?? $this->exampleMappings()]);
        $this->services->secrets()->set($preset['id'], self::SECRET, 'https://api.example.com:443');

        return $preset;
    }

    /** Every stored diagnostic (history, active state, log) plus anything given. */
    private function assertNoSecretAnywhere(string $preset_id, mixed ...$extra): void
    {
        $stored = [
            \WPStub::$options['ppapi_log']['value'] ?? [],
            \WPStub::$options['ppapi_history_' . $preset_id]['value'] ?? [],
            \WPStub::$options['ppapi_run_' . $preset_id]['value'] ?? [],
            $this->services->runs()->history($preset_id),
            $this->services->logger()->entries(),
            $extra,
        ];
        $haystack = serialize($stored) . json_encode($stored);

        foreach ([self::SECRET, substr(self::SECRET, 0, 30), rawurlencode(self::SECRET)] as $form) {
            $this->assertNotContains($form, $haystack, 'credential (or a recognizable part of it) leaked');
        }
    }

    /** Confirmed failure 1: a credential echoed in a field that fails numeric conversion. */
    public function testEchoedCredentialInAFailedRecordIsMaskedInIssuesHistoryAndSummary(): void
    {
        $preset = $this->bearerPreset(['mappings' => [
            $this->row('event.title', 'core', 'post_title'),
            $this->row('debug', 'meta', 'attendee_count', ['transform' => 'integer']),
        ]]);
        $this->queueJson(['ok' => true, 'data' => [['id' => 'r1', 'event' => ['title' => 'One'], 'debug' => self::SECRET]]]);

        $state = $this->runPreset($preset['id']);

        $this->assertSame('completed_with_errors', $state['status']);
        $issue = $state['issues'][0]['message'];
        $this->assertContains('is not a number', $issue, 'the diagnostic itself is kept');
        $this->assertContains(Redactor::MASK, $issue);

        $rest = (new RestController($this->services))->run_status(new WP_REST_Request(['id' => $preset['id']]))->get_data();
        $this->assertNoSecretAnywhere($preset['id'], $state, $rest);
    }

    /** Confirmed failure 2: a credential echoed in the success flag. */
    public function testEchoedCredentialInTheSuccessFlagIsMaskedInDryRunHistoryAndLog(): void
    {
        $preset = $this->bearerPreset();
        $payload = ['ok' => self::SECRET, 'data' => []];

        $body = ['preset_id' => $preset['id'], 'ppapi' => $preset, 'ppapi_mappings' => json_encode($preset['mappings'])];
        $this->queueJson($payload);
        $dry = (new RestController($this->services))->dry_run(new WP_REST_Request([], $body))->get_data();
        $this->assertFalse($dry['ok']);
        $this->assertContains('reported a failure', $dry['error']);
        $this->assertSame('Bearer ' . self::SECRET, \WPStub::$http_log[0]['args']['headers']['Authorization'], 'the saved credential was used server-side');

        $this->queueJson($payload);
        $test = (new RestController($this->services))->test_request(new WP_REST_Request([], $body))->get_data();

        $this->queueJson($payload);
        $state = $this->runPreset($preset['id']);
        $this->assertSame('failed', $state['status']);
        $this->assertContains('reported a failure', $state['message']);

        $this->assertNoSecretAnywhere($preset['id'], $dry, $test, $state);
    }

    public function testHttpErrorsAndRetriesAreMasked(): void
    {
        $preset = $this->bearerPreset(['request' => ['retries' => 1]]);
        $this->queueResponse(503, 'invalid token ' . self::SECRET, [], 'Unavailable for ' . self::SECRET);
        $this->queueResponse(401, '{"error":"bad token \"' . self::SECRET . '\""}', [], 'Unauthorized ' . self::SECRET);

        $state = $this->runPreset($preset['id']);

        $this->assertSame('failed', $state['status']);
        $this->assertContains('HTTP 401', $state['message']);
        $this->assertNoSecretAnywhere($preset['id'], $state);

        $this->queueResponse(500, 'echo ' . self::SECRET, [], 'Error ' . self::SECRET);
        $body = ['preset_id' => $preset['id'], 'ppapi' => $preset, 'ppapi_mappings' => json_encode($preset['mappings'])];
        $test = (new RestController($this->services))->test_request(new WP_REST_Request([], $body))->get_data();
        $this->assertFalse($test['ok']);
        $this->assertNoSecretAnywhere($preset['id'], $test);
    }

    /** Confirmed failure 3: the 2,000-byte body snippet cut a credential before it was masked. */
    public function testCredentialCrossingTheResponseSnippetLimitIsMasked(): void
    {
        $preset = $this->bearerPreset();
        $body   = ['preset_id' => $preset['id'], 'ppapi' => $preset, 'ppapi_mappings' => json_encode($preset['mappings'])];
        $rest   = new RestController($this->services);

        foreach ([4, 10, 40, strlen(self::SECRET) - 1] as $visible) {
            $padding = str_repeat('x', 2000 - $visible);
            foreach ([[500, $padding . self::SECRET . ' trailing text'], [200, $padding . self::SECRET . ' is not JSON']] as [$code, $response]) {
                foreach (['test_request', 'dry_run'] as $handler) {
                    $this->queueResponse($code, $response);
                    $data = $rest->$handler(new WP_REST_Request([], $body))->get_data();

                    $label = "{$handler}, HTTP {$code}, {$visible} bytes before the cut";
                    $this->assertFalse($data['ok'], $label);
                    $this->assertSame($padding, substr($data['snippet'], 0, strlen($padding)), $label . ': the snippet is still shown');
                    $this->assertNotContains(substr(self::SECRET, 0, 4), $data['snippet'], $label);
                    $this->assertNoSecretAnywhere($preset['id'], $data);
                }
            }
        }
    }

    public function testUnexpectedErrorsAndDiagnosticHooksAreMasked(): void
    {
        $preset   = $this->bearerPreset();
        $received = [];
        add_action('ppapi_log', static function (...$args) use (&$received): void {
            $received[] = $args;
        }, 10, 3);
        add_action('ppapi_run_finished', static function (...$args) use (&$received): void {
            $received[] = $args;
        }, 10, 2);
        add_action('save_post', static function (): void {
            throw new RuntimeException('database rejected ' . self::SECRET);
        });
        $this->queueJson(['ok' => true, 'data' => [['id' => 'r1', 'event' => ['title' => 'One']]]]);

        $state = $this->runPreset($preset['id']);

        $this->assertSame('failed', $state['status']);
        $this->assertContains('unexpected error', $state['message']);
        $this->assertTrue(count($received) >= 2);
        $this->assertNoSecretAnywhere($preset['id'], $state, $received);
    }

    public function testRequestValuesThatLookLikeCredentialsAreMaskedToo(): void
    {
        $preset = $this->savePreset(['request' => ['headers' => [['name' => 'X-API-Key', 'value' => self::HEADER_KEY]]]]);
        $this->queueJson(['ok' => 'key was ' . self::HEADER_KEY, 'data' => []]);

        $state = $this->runPreset($preset['id']);

        $this->assertSame('failed', $state['status']);
        $rest = (new RestController($this->services))->run_status(new WP_REST_Request(['id' => $preset['id']]))->get_data();
        $this->assertNotContains(self::HEADER_KEY, serialize([$state['message'], $rest, \WPStub::$options['ppapi_log']['value'], $this->services->runs()->history($preset['id'])]));
        $this->assertContains('application/json', serialize(\WPStub::$http_log[0]['args']['headers']), 'ordinary header values are not treated as secrets');
    }

    public function testImportedBusinessDataIsNotAltered(): void
    {
        $preset = $this->bearerPreset(['mappings' => [
            $this->row('event.title', 'core', 'post_title'),
            $this->row('note', 'meta', 'source_note'),
        ]]);
        $this->queueJson(['ok' => true, 'data' => [['id' => 'r1', 'event' => ['title' => 'Echo ' . self::SECRET], 'note' => self::SECRET]]]);

        $state = $this->runPreset($preset['id']);

        $this->assertSame('completed', $state['status']);
        $post = $this->posts()[0];
        $this->assertSame('Echo ' . self::SECRET, $post['post_title'], 'redaction applies to diagnostics, not to imported content');
        $this->assertSame(self::SECRET, $this->meta($post['ID'], 'source_note'));
    }

    public function testLegacyDiagnosticsAreMaskedWhenRead(): void
    {
        $preset = $this->bearerPreset();
        // Written by an earlier version, before redaction covered these paths.
        update_option('ppapi_history_' . $preset['id'], [['run_id' => wp_generate_uuid4(), 'status' => 'failed', 'message' => 'leaked ' . self::SECRET, 'issues' => [['message' => 'x ' . self::SECRET, 'external_id' => self::SECRET]]]], false);
        update_option('ppapi_log', [['time' => 1, 'level' => 'error', 'message' => 'leaked ' . self::SECRET, 'context' => []]], false);

        $history = $this->services->runs()->history($preset['id']);
        $this->assertContains(Redactor::MASK, $history[0]['message']);
        $this->assertNotContains(self::SECRET, serialize($history));
        $this->assertNotContains(self::SECRET, serialize($this->services->logger()->entries()));

        $rest = (new RestController($this->services))->run_status(new WP_REST_Request(['id' => $preset['id']]))->get_data();
        $this->assertNotContains(self::SECRET, json_encode($rest));
    }

    public function testLoggerMasksEveryKnownCredentialByDefault(): void
    {
        $this->bearerPreset();
        $this->services->logger()->error('Something about ' . self::SECRET, ['detail' => 'more ' . self::SECRET]);

        $this->assertNotContains(self::SECRET, serialize(\WPStub::$options['ppapi_log']['value']));
        $this->assertContains(Redactor::MASK, \WPStub::$options['ppapi_log']['value'][0]['message']);
    }

    public function testEncodedAndTruncatedFormsAreMasked(): void
    {
        $secret   = 'ab/cd"ef gh+ij<kl>&mn_0123456789';
        $redactor = new Redactor([$secret]);
        $forms    = [
            $secret,
            rawurlencode($secret),
            urlencode($secret),
            substr((string) json_encode($secret), 1, -1),
            substr((string) json_encode($secret, JSON_UNESCAPED_SLASHES), 1, -1),
            htmlspecialchars($secret, ENT_QUOTES, 'UTF-8'),
            addslashes($secret),
        ];
        foreach ($forms as $form) {
            $this->assertSame('x ' . Redactor::MASK . ' y', $redactor->text('x ' . $form . ' y'), $form);
        }

        // A preview that cut the secret short before redaction could see it.
        $this->assertSame('"' . Redactor::MASK . '…" is not a number.', $redactor->text('"' . substr($secret, 0, 20) . '…" is not a number.'));
        $this->assertSame('Event…', $redactor->text('Event…'), 'unrelated truncated text is left alone');

        $data = $redactor->data(['a' => ['b' => $secret, 'c' => 5], 'd' => null]);
        $this->assertSame(['a' => ['b' => Redactor::MASK, 'c' => 5], 'd' => null], $data);
    }

    public function testExcerptsMaskBeforeCutting(): void
    {
        $redactor = new Redactor([self::SECRET]);

        for ($visible = 1; $visible <= strlen(self::SECRET); $visible++) {
            $text = str_repeat('x', 100 - $visible) . self::SECRET . str_repeat('y', 500);
            $this->assertSame(
                str_repeat('x', 100 - $visible) . substr(Redactor::MASK . str_repeat('y', 100), 0, $visible),
                $redactor->excerpt($text, 100),
                "cut after {$visible} bytes of the credential"
            );
        }

        // Earlier masks shorten the text, pulling in a credential cut by the examined window.
        $this->assertSame(str_repeat(Redactor::MASK, 16) . '…', $redactor->excerpt(str_repeat(self::SECRET, 40), 1000));

        $this->assertSame(str_repeat('a', 10), (new Redactor())->excerpt(str_repeat('a', 50), 10), 'without secrets, a plain prefix');
        $this->assertSame('short ' . Redactor::MASK, $redactor->excerpt('short ' . self::SECRET, 1000), 'uncut text has no marker');
    }

    public function testRedactionNeverFailsOpenOrThrowsOnUnusualSecrets(): void
    {
        $binary   = "bin\xC3\x28\xFF secret";
        $redactor = new Redactor([$binary, 'abc', null]);

        $this->assertSame('[' . Redactor::MASK . ']', $redactor->text('[' . $binary . ']'));
        $this->assertSame('abc stays', $redactor->text('abc stays'), 'values shorter than four bytes are never masked');
        $this->assertSame('', $redactor->text(''));
    }
}
