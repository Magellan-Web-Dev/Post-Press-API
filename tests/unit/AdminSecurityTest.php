<?php
/**
 * Capabilities, nonces, REST permissions, and credential handling in the admin.
 */

declare(strict_types=1);

use PostPressAPI\Admin\AdminActions;
use PostPressAPI\Admin\AdminPage;
use PostPressAPI\REST\RestController;
use PostPressAPI\Tests\TestCase;

final class RedirectSignal extends RuntimeException
{
}

final class AdminSecurityTest extends TestCase
{
    private const SECRET = 'sk_live_TOPSECRET_9f8e7d';

    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
        wpstub_add_user(2, 'subscriber', ['read']);
        wpstub_add_user(3, 'editor', ['manage_options', 'edit_posts', 'read']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    private function terminator(): callable
    {
        return static function (string $url): void {
            \WPStub::$redirects[] = $url;
            throw new RedirectSignal($url);
        };
    }

    /** Editor form fields for a complete preset. */
    private function form(array $overrides = []): array
    {
        $form = [
            'ppapi_action'   => 'save',
            '_ppapi_nonce'   => wp_create_nonce('ppapi_save_preset'),
            'ppapi'          => [
                'name'        => 'Events feed',
                'enabled'     => '1',
                'request'     => ['url' => 'https://api.example.com/events', 'method' => 'GET', 'timeout' => '20', 'max_kb' => '2048', 'retries' => '2', 'auth' => ['type' => 'bearer', 'name' => '']],
                'response'    => ['records_path' => 'data', 'success_path' => 'ok', 'success_value' => 'true', 'identity' => ['mode' => 'path', 'path' => 'id']],
                'destination' => ['post_type' => 'events', 'status' => 'draft', 'author' => '1'],
                'sync'        => ['policy' => 'update_changed', 'match' => ['type' => 'none', 'key' => ''], 'trashed' => 'skip'],
                'pagination'  => ['type' => 'none'],
                'schedule'    => ['frequency' => 'hourly', 'minutes' => '60'],
                'batch_size'  => '25',
            ],
            'ppapi_mappings' => json_encode([$this->row('event.title', 'core', 'post_title')]),
            'ppapi_secret'   => self::SECRET,
        ];

        return array_replace_recursive($form, $overrides);
    }

    private function submit(array $post, ?string $preset_id = null): AdminPage
    {
        if ($preset_id !== null) {
            $post['preset_id'] = $preset_id;
        }
        $_POST    = wp_slash($post);
        $_REQUEST = $_POST;
        $page     = new AdminPage($this->services, $this->terminator());

        try {
            $page->handle_save();
        } catch (RedirectSignal) {
        }

        return $page;
    }

    private function render(AdminPage $page, array $get): string
    {
        $_GET = $get;
        ob_start();
        $page->render();
        return (string) ob_get_clean();
    }

    public function testSubscribersCannotSavePresets(): void
    {
        wp_set_current_user(2);
        $post = $this->form();

        $this->assertThrows(WpDieException::class, fn () => $this->submit($post));
        $this->assertSame([], $this->services->presets()->all());
    }

    public function testSavingRequiresAValidNonce(): void
    {
        $this->assertThrows(WpDieException::class, fn () => $this->submit($this->form(['_ppapi_nonce' => 'forged'])));
        $this->assertSame([], $this->services->presets()->all());
    }

    public function testAdministratorsSaveThroughTheForm(): void
    {
        $this->submit($this->form());

        $presets = $this->services->presets()->all();
        $this->assertCount(1, $presets);
        $this->assertContains('ppapi_notice=created', \WPStub::$redirects[0]);

        $id = $presets[0]['id'];
        $this->assertSame(self::SECRET, $this->services->secrets()->get($id)['value']);
        $this->assertSame('https://api.example.com:443', $this->services->secrets()->get($id)['origin']);
        $this->assertSame('no', \WPStub::$options['ppapi_secret_' . $id]['autoload'], 'credentials are not autoloaded');
        $this->assertSame('no', \WPStub::$options['ppapi_preset_' . $id]['autoload']);
        $this->assertNotContains(self::SECRET, serialize(\WPStub::$options['ppapi_preset_' . $id]['value']), 'the preset itself holds no credential');
        $this->assertSame(1, wpstub_count_events('ppapi_scheduled_run', [$id]));
    }

    public function testInvalidInputIsRerenderedWithEscapedValuesAndLinkedErrors(): void
    {
        $page = $this->submit($this->form(['ppapi' => ['name' => '<script>alert(1)</script>', 'request' => ['url' => 'ftp://nope']]]));

        $this->assertSame([], \WPStub::$redirects, 'no redirect on error');
        $this->assertSame([], $this->services->presets()->all());

        $html = $this->render($page, ['page' => 'ppapi', 'view' => 'new']);
        $this->assertContains('ppapi-error-summary', $html);
        $this->assertContains('href="#ppapi-request-url"', $html);
        $this->assertContains('aria-invalid="true"', $html);
        $this->assertNotContains('<script>alert(1)</script>', $html);
        $this->assertContains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertNotContains(self::SECRET, $html, 'a submitted credential is never echoed back');
    }

    public function testCredentialsAreMaskedAndNeverRendered(): void
    {
        $this->submit($this->form());
        $id = $this->services->presets()->all()[0]['id'];

        $html = $this->render(new AdminPage($this->services), ['page' => 'ppapi', 'view' => 'edit', 'preset' => $id]);
        $this->assertContains('A credential is saved for', $html);
        $this->assertContains('name="ppapi_secret" value=""', $html);
        $this->assertNotContains(self::SECRET, $html);
        $this->assertNotContains(self::SECRET, implode('', \WPStub::$inline_scripts), 'not in editor data either');

        $list = $this->render(new AdminPage($this->services), ['page' => 'ppapi']);
        $this->assertNotContains(self::SECRET, $list);
    }

    public function testUnchangedMaskedFieldKeepsCredentialAndRemovalIsExplicit(): void
    {
        $this->submit($this->form());
        $id = $this->services->presets()->all()[0]['id'];

        $this->submit($this->form(['ppapi_secret' => '', 'ppapi' => ['name' => 'Renamed']]), $id);
        $this->assertSame(self::SECRET, $this->services->secrets()->get($id)['value'], 'blank field keeps the credential');
        $this->assertSame('Renamed', $this->services->presets()->find($id)['name']);

        $this->submit($this->form(['ppapi_secret' => 'new-token-value']), $id);
        $this->assertSame('new-token-value', $this->services->secrets()->get($id)['value']);

        $this->submit($this->form(['ppapi_secret' => '', 'ppapi_secret_remove' => '1', 'ppapi' => ['enabled' => '', 'request' => ['auth' => ['type' => 'none']]]]), $id);
        $this->assertNull($this->services->secrets()->get($id));
    }

    public function testChangingTheHostRequiresReenteringTheCredential(): void
    {
        $this->submit($this->form());
        $id = $this->services->presets()->all()[0]['id'];
        \WPStub::$redirects = [];

        $page = $this->submit($this->form(['ppapi_secret' => '', 'ppapi' => ['request' => ['url' => 'https://attacker.example.net/collect']]]), $id);

        $this->assertSame([], \WPStub::$redirects);
        $this->assertContains('Re-enter the credential', $this->render($page, ['page' => 'ppapi', 'view' => 'edit', 'preset' => $id]));
        $this->assertSame('https://api.example.com/events', $this->services->presets()->find($id)['request']['url']);
    }

    public function testCredentialsRequireHttps(): void
    {
        $page = $this->submit($this->form(['ppapi' => ['request' => ['url' => 'http://api.example.com/events']]]));
        $this->assertSame([], $this->services->presets()->all());
        $this->assertContains('https://', $this->render($page, ['page' => 'ppapi', 'view' => 'new']));
    }

    public function testRowActionsRequireCapabilityAndNonce(): void
    {
        $this->submit($this->form());
        $id      = $this->services->presets()->all()[0]['id'];
        $actions = new AdminActions($this->services, $this->terminator());

        $_GET = $_REQUEST = ['preset' => $id];
        $this->assertThrows(WpDieException::class, fn () => $actions->duplicate(), 'expired');

        wp_set_current_user(2);
        $_GET = $_REQUEST = ['preset' => $id, '_wpnonce' => wp_create_nonce('ppapi_duplicate_' . $id)];
        $this->assertThrows(WpDieException::class, fn () => $actions->duplicate());
        $this->assertThrows(WpDieException::class, fn () => $actions->delete());
        $this->assertCount(1, $this->services->presets()->all());

        wp_set_current_user(1);
        $_GET = $_REQUEST = ['preset' => $id, '_wpnonce' => wp_create_nonce('ppapi_delete_' . $id)];
        $this->assertThrows(WpDieException::class, fn () => $actions->duplicate(), 'expired'); // a nonce for another action is rejected

        $_GET = $_REQUEST = ['preset' => $id, '_wpnonce' => wp_create_nonce('ppapi_duplicate_' . $id)];
        $this->assertThrows(RedirectSignal::class, fn () => $actions->duplicate());
        $presets = $this->services->presets()->all();
        $this->assertCount(2, $presets);
        $this->assertFalse($presets[1]['enabled'], 'duplicates start disabled');
        $this->assertSame(self::SECRET, $this->services->secrets()->get($presets[1]['id'])['value'], 'credential copied server-side');
    }

    public function testEnablingAnIncompletePresetIsRefused(): void
    {
        $preset  = $this->savePreset(['enabled' => false, 'mappings' => []]);
        $actions = new AdminActions($this->services, $this->terminator());

        $_GET = $_REQUEST = ['preset' => $preset['id'], 'enable' => '1', '_wpnonce' => wp_create_nonce('ppapi_toggle_' . $preset['id'])];
        $this->assertThrows(RedirectSignal::class, fn () => $actions->toggle());

        $this->assertContains('ppapi_notice=not_ready', end(\WPStub::$redirects));
        $this->assertFalse($this->services->presets()->find($preset['id'])['enabled']);
    }

    public function testRestRoutesAllRequireManageOptions(): void
    {
        (new RestController($this->services))->register_routes();
        $this->assertCount(7, \WPStub::$rest_routes);

        foreach (\WPStub::$rest_routes as $route => $args) {
            $this->assertTrue(str_starts_with($route, 'ppapi/v1/'), $route);
            foreach (isset($args['methods']) ? [$args] : $args as $endpoint) {
                $this->assertTrue(is_callable($endpoint['permission_callback']), $route);
                $this->assertNotSame('__return_true', $endpoint['permission_callback']);
            }
        }

        $controller = new RestController($this->services);
        $this->assertTrue($controller->can_manage());

        wp_set_current_user(2);
        $denied = $controller->can_manage();
        $this->assertInstanceOf(WP_Error::class, $denied);
        $this->assertSame(403, $denied->get_error_data()['status']);

        wp_set_current_user(0);
        $this->assertSame(401, $controller->can_manage()->get_error_data()['status'], 'cookie auth without a valid REST nonce is logged out');
    }

    public function testRunsRequireTheDestinationCapabilities(): void
    {
        $preset = $this->savePreset();
        wp_set_current_user(3);

        $response = (new RestController($this->services))->run_start(new WP_REST_Request(['id' => $preset['id']]));

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame(403, $response->get_error_data()['status']);
        $this->assertContains('publish_posts', $response->get_error_message());
        $this->assertNull($this->services->runs()->active($preset['id']));
    }

    public function testPreviewResponsesNeverContainCredentials(): void
    {
        $this->submit($this->form());
        $id = $this->services->presets()->all()[0]['id'];

        // A misbehaving API that echoes the token back must not leak it into the preview.
        $this->queueJson(['ok' => true, 'debug' => 'received Bearer ' . self::SECRET, 'data' => [['id' => 1, 'event' => ['title' => self::SECRET]]]]);
        $body = $this->form(['ppapi_secret' => '']) + ['preset_id' => $id];
        unset($body['ppapi_action'], $body['_ppapi_nonce']);

        $response = (new RestController($this->services))->test_request(new WP_REST_Request([], $body));
        $json     = json_encode($response->get_data());

        $this->assertContains('[redacted]', $json);
        $this->assertNotContains(self::SECRET, $json);
        $this->assertSame('Bearer ' . self::SECRET, \WPStub::$http_log[0]['args']['headers']['Authorization'], 'the saved credential was used server-side');

        $this->queueJson(['ok' => true, 'data' => [['id' => 1, 'event' => ['title' => self::SECRET]]]]);
        $dry  = (new RestController($this->services))->dry_run(new WP_REST_Request([], $body));
        $this->assertNotContains(self::SECRET, json_encode($dry->get_data()));
        $this->assertCount(0, $this->posts(), 'dry run wrote nothing');
    }

    public function testRunStatusResponsesExposeNoSnapshotOrCredential(): void
    {
        $this->submit($this->form());
        $id = $this->services->presets()->all()[0]['id'];
        $this->queueJson($this->fixture());

        $response = (new RestController($this->services))->run_start(new WP_REST_Request(['id' => $id, 'force' => false]));
        $data     = $response->get_data();

        $this->assertTrue($data['active']);
        $this->assertArrayHasKey('counts', $data['run']);
        $this->assertSame(false, array_key_exists('snapshot', $data['run']));
        $this->assertNotContains(self::SECRET, json_encode($data));
    }
}
