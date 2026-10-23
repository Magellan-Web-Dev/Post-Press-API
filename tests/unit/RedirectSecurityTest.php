<?php
/**
 * API redirects: the request (custom headers, query values, POST body, and any
 * credential) is never forwarded to another origin, for every authentication mode.
 */

declare(strict_types=1);

use PostPressAPI\Tests\TestCase;

final class RedirectSecurityTest extends TestCase
{
    private const HEADER_KEY = 'hdr_CONFIDENTIAL_7731';
    private const BODY_VALUE = 'body_CONFIDENTIAL_5524';

    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
    }

    /** POST preset with no dedicated authentication but sensitive headers and body. */
    private function postPreset(array $overrides = []): array
    {
        return $this->savePreset(array_replace_recursive([
            'request'  => [
                'method'  => 'POST',
                'headers' => [['name' => 'X-API-Key', 'value' => self::HEADER_KEY]],
                'body'    => json_encode(['account' => self::BODY_VALUE]),
                'auth'    => ['type' => 'none'],
            ],
            'mappings' => [$this->row('event.title', 'core', 'post_title')],
        ], $overrides) + ['mappings' => [$this->row('event.title', 'core', 'post_title')]]);
    }

    private function fetch(array $preset, ?string $secret = null): \PostPressAPI\Http\FetchResult
    {
        return $this->services->api_client()->fetch($preset, $secret);
    }

    public function testCrossOriginRedirectsAreRefusedWithoutDedicatedAuthentication(): void
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            \WPStub::$http_log = [];
            $preset = $this->postPreset();
            $this->queueResponse($status, '', ['location' => 'https://collector.example.net/steal/' . self::HEADER_KEY . '?token=abc'], 'Redirect');

            $result = $this->fetch($preset);

            $this->assertFalse($result->ok, (string) $status);
            $this->assertSame('redirect_blocked', $result->error_code, (string) $status);
            $this->assertCount(1, \WPStub::$http_log, $status . ': nothing was sent to the other origin');
            $this->assertSame(self::HEADER_KEY, \WPStub::$http_log[0]['args']['headers']['X-API-Key'], 'the original origin received the request');
            $this->assertContains(self::BODY_VALUE, \WPStub::$http_log[0]['args']['body']);
            $this->assertContains('https://collector.example.net', $result->error, 'the error names the refused origin');
            $this->assertNotContains('/steal', $result->error, 'the redirect path is not exposed');
            $this->assertNotContains('token=abc', $result->error);
            $this->assertNotContains(self::HEADER_KEY, $result->error);
            $this->assertContains('endpoint URL', $result->error, 'the error says how to fix it');
        }
    }

    public function testDowngradeAndPortChangesAreDifferentOrigins(): void
    {
        $preset = $this->postPreset();

        $this->queueResponse(307, '', ['location' => 'http://api.example.com/events'], 'Temporary Redirect');
        $downgrade = $this->fetch($preset);
        $this->assertSame('redirect_blocked', $downgrade->error_code);
        $this->assertContains('insecure http://', $downgrade->error);

        $this->queueResponse(308, '', ['location' => 'https://api.example.com:8443/events'], 'Permanent Redirect');
        $port = $this->fetch($preset);
        $this->assertSame('redirect_blocked', $port->error_code);
        $this->assertContains('api.example.com:8443', $port->error);

        $this->queueResponse(302, '', ['location' => '//other.example.com/events'], 'Found');
        $this->assertSame('redirect_blocked', $this->fetch($preset)->error_code, 'protocol-relative redirects to another host');

        $this->assertCount(3, \WPStub::$http_log, 'none of the redirects was followed');
    }

    public function testDedicatedCredentialsAreNotForwardedEither(): void
    {
        $query = $this->savePreset(['request' => ['auth' => ['type' => 'api_key_query', 'name' => 'api_key']]]);
        $this->services->secrets()->set($query['id'], 'QUERY_SECRET_991', 'https://api.example.com:443');
        $this->queueResponse(307, '', ['location' => 'https://elsewhere.example.org/events'], 'Temporary Redirect');

        $result = $this->fetch($query, 'QUERY_SECRET_991');

        $this->assertSame('redirect_blocked', $result->error_code);
        $this->assertCount(1, \WPStub::$http_log);
        $this->assertNotContains('QUERY_SECRET_991', $result->error . $result->url);
    }

    public function testSameOriginRedirectsKeepTheirMethodSemantics(): void
    {
        $cases = [
            301 => ['GET', false],
            302 => ['GET', false],
            303 => ['GET', false],
            307 => ['POST', true],
            308 => ['POST', true],
        ];

        foreach ($cases as $status => [$method, $keeps_body]) {
            \WPStub::$http_log = [];
            $preset = $this->postPreset();
            $this->queueResponse($status, '', ['location' => 'https://API.example.com:443/v2/events'], 'Redirect');
            $this->queueJson(['ok' => true, 'data' => []]);

            $result = $this->fetch($preset);

            $this->assertTrue($result->ok, $status . ': same scheme, host (any case), and effective port is the same origin');
            $this->assertCount(2, \WPStub::$http_log);
            $hop = \WPStub::$http_log[1]['args'];
            $this->assertSame($method, $hop['method'], (string) $status);
            $this->assertSame($keeps_body, isset($hop['body']), $status . ' body');
            $this->assertSame(self::HEADER_KEY, $hop['headers']['X-API-Key'], 'headers stay within the origin');
            $this->assertSame(0, $hop['redirection'], 'the transport never follows redirects itself');
            $this->assertTrue($hop['sslverify'], 'TLS verification on every hop');
            $this->assertTrue(\WPStub::$http_log[1]['safe'], 'every hop uses wp_safe_remote_request()');
        }
    }

    public function testRelativeRedirectsAndTheRedirectLimitStillWork(): void
    {
        $preset = $this->postPreset();
        $this->queueResponse(301, '', ['location' => '/v2/events'], 'Moved');
        $this->queueJson(['ok' => true, 'data' => []]);
        $this->assertTrue($this->fetch($preset)->ok);
        $this->assertSame('https://api.example.com/v2/events', \WPStub::$http_log[1]['url']);

        \WPStub::$http_log = [];
        for ($i = 0; $i < 4; $i++) {
            $this->queueResponse(302, '', ['location' => '/hop-' . $i], 'Found');
        }
        $result = $this->fetch($preset);
        $this->assertSame('too_many_redirects', $result->error_code);
        $this->assertCount(4, \WPStub::$http_log, 'at most three redirects are followed');
    }

    public function testRunsFailWithAnActionableMessageAndWriteNothing(): void
    {
        $preset = $this->postPreset();
        $this->queueResponse(307, '', ['location' => 'https://collector.example.net/steal'], 'Temporary Redirect');

        $state = $this->runPreset($preset['id']);

        $this->assertSame('failed', $state['status']);
        $this->assertContains('collector.example.net', $state['message']);
        $this->assertCount(0, $this->posts());
        $this->assertCount(1, \WPStub::$http_log);
        $this->assertNotContains(self::HEADER_KEY, serialize(\WPStub::$options['ppapi_log']['value']));
        $this->assertNotContains(self::HEADER_KEY, serialize(\WPStub::$options['ppapi_history_' . $preset['id']]['value']));
    }
}
