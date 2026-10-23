<?php
/**
 * Admin screens and the remaining REST routes.
 */

declare(strict_types=1);

use PostPressAPI\Admin\AdminPage;
use PostPressAPI\REST\RestController;
use PostPressAPI\Tests\TestCase;

final class AdminViewsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
    }

    private function render(array $get, string $method = 'render'): string
    {
        $_GET = $get;
        ob_start();
        (new AdminPage($this->services))->$method();
        return (string) ob_get_clean();
    }

    public function testListShowsScheduleAndRunTimesInTheSiteTimezone(): void
    {
        update_option('timezone_string', 'America/New_York');
        $preset = $this->savePreset(['schedule' => ['frequency' => 'hourly']]);
        $this->services->scheduler()->sync($preset);
        $this->queueJson($this->fixture());
        $this->runPreset($preset['id']);

        $html = $this->render(['page' => 'ppapi']);
        $next = wp_next_scheduled('ppapi_scheduled_run', [$preset['id']]);

        $this->assertContains('Events feed', $html);
        $this->assertContains('Enabled', $html);
        $this->assertContains('Hourly', $html);
        $this->assertContains(esc_html(wp_date('Y-m-d H:i', $next)), $html, 'next run in site time zone');
        $this->assertContains('Completed', $html);
        $this->assertContains('1 created', $html);
        $this->assertContains('data-ppapi-confirm', $html, 'delete asks for confirmation');
        $this->assertContains('WP-Cron', $html);
    }

    public function testEmptyListOffersToCreateAPreset(): void
    {
        $this->assertContains('Create your first preset', $this->render(['page' => 'ppapi']));
    }

    public function testHistoryShowsCountsMessagesAndFailedRecords(): void
    {
        $preset  = $this->savePreset();
        $payload = $this->fixture();
        $broken  = $payload['data'][0];
        unset($broken['id']);
        $payload['data'][] = $broken;
        $this->queueJson($payload);
        $this->runPreset($preset['id']);

        $html = $this->render(['page' => 'ppapi', 'view' => 'history', 'preset' => $preset['id']]);

        $this->assertContains('Completed with errors', $html);
        $this->assertContains('1 failed record', $html);
        $this->assertContains('external ID', $html);
        foreach (['Created', 'Updated', 'Unchanged', 'Skipped', 'Failed'] as $column) {
            $this->assertContains('>' . $column . '<', $html);
        }
    }

    public function testEditorRendersEverySection(): void
    {
        $preset = $this->savePreset();
        $html   = $this->render(['page' => 'ppapi', 'view' => 'edit', 'preset' => $preset['id']]);

        foreach (['request', 'response', 'mappings', 'duplicates', 'pagination', 'schedule', 'results'] as $section) {
            $this->assertContains('id="ppapi-section-' . $section . '"', $html);
        }
        foreach (['ppapi-test-request', 'ppapi-dry-run', 'ppapi-run-now', 'ppapi-add-mapping'] as $control) {
            $this->assertContains('id="' . $control . '"', $html);
        }
        $this->assertContains('name="_ppapi_nonce"', $html);

        $data = \WPStub::$inline_scripts['ppapi-admin'];
        $this->assertContains('window.ppapiEditor', $data);
        $this->assertContains('field_sessions', $data, 'ACF catalog for the chosen post type');
        $this->assertNotContains('</script>', $data, 'JSON is HEX-escaped for safe inline output');
    }

    public function testMissingPresetShowsAMessage(): void
    {
        $this->assertContains('no longer exists', $this->render(['page' => 'ppapi', 'view' => 'edit', 'preset' => 'abcdefabcdef']));
    }

    public function testLogEntriesAreEscaped(): void
    {
        $this->services->logger()->error('<img src=x onerror=alert(1)> failed', ['preset_id' => 'abcdefabcdef', 'authorization' => 'Bearer x']);

        $html = $this->render(['page' => 'ppapi-log'], 'render_log');
        $this->assertContains('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertNotContains('<img src=x', $html);
        $this->assertFalse(isset(\WPStub::$options['ppapi_log']['value'][0]['context']['authorization']), 'credential-like context keys are dropped');
    }

    public function testViewsRequireManageOptions(): void
    {
        wpstub_add_user(2, 'subscriber', ['read']);
        wp_set_current_user(2);

        $this->assertThrows(WpDieException::class, fn () => $this->render(['page' => 'ppapi']));
        $this->assertThrows(WpDieException::class, fn () => $this->render(['page' => 'ppapi-log'], 'render_log'));
        $this->assertThrows(WpDieException::class, fn () => $this->render(['page' => 'ppapi-new'], 'render_new'));
    }

    public function testRestRunLifecycle(): void
    {
        $preset     = $this->savePreset();
        $controller = new RestController($this->services);
        $request    = new WP_REST_Request(['id' => $preset['id']]);

        $idle = $controller->run_status($request)->get_data();
        $this->assertFalse($idle['active']);
        $this->assertNull($idle['run']);

        $this->queueJson($this->fixture());
        $this->assertTrue($controller->run_start(new WP_REST_Request(['id' => $preset['id'], 'force' => false]))->get_data()['active']);

        $step = $controller->run_step($request)->get_data();
        $this->assertFalse($step['active']);
        $this->assertSame('completed', $step['run']['status']);
        $this->assertSame(1, $step['run']['counts']['created']);

        $status = $controller->run_status($request)->get_data();
        $this->assertSame('completed', $status['run']['status'], 'last run reported after completion');

        $cancel = $controller->run_cancel($request)->get_data();
        $this->assertFalse($cancel['active']);
        $this->assertFalse($cancel['pending']);
    }

    public function testDestinationsEndpoint(): void
    {
        $controller = new RestController($this->services);

        $catalog = $controller->destinations(new WP_REST_Request(['post_type' => 'events']))->get_data();
        $this->assertTrue($catalog['acf']['available']);
        $this->assertSame('Event details', $catalog['acf']['groups'][0]['title']);
        $sessions = array_values(array_filter($catalog['acf']['groups'][0]['fields'], static fn ($field) => $field['key'] === 'field_sessions'))[0];
        $this->assertSame(['field_session_date', 'field_session_time'], array_column($sessions['children'], 'key'), 'parent/sub field relationships kept');
        $this->assertSame('Event details › Sessions › Date', $sessions['children'][0]['trail']);

        $bad = $controller->destinations(new WP_REST_Request(['post_type' => 'acf-field-group']));
        $this->assertInstanceOf(WP_Error::class, $bad);
        $this->assertSame(400, $bad->get_error_data()['status']);
    }

    public function testTestRequestWithPastedSampleListsPaths(): void
    {
        $controller = new RestController($this->services);
        $body       = [
            'ppapi'        => ['name' => '', 'request' => ['url' => ''], 'response' => ['records_path' => 'data', 'identity' => ['mode' => 'path', 'path' => 'id']]],
            'use_sample'   => '1',
            'ppapi_sample' => (string) file_get_contents(PPAPI_TEST_ROOT . '/tests/fixtures/sample.json'),
        ];

        $result = $controller->test_request(new WP_REST_Request([], $body))->get_data();

        $this->assertTrue($result['ok']);
        $this->assertSame('sample', $result['source']);
        $this->assertSame(1, $result['record_count']);
        $this->assertContains('sessions[*].date', array_column($result['record_paths'], 'path'));
        $this->assertContains('pagination.has_more', array_column($result['response_paths'], 'path'));
        $this->assertSame([], \WPStub::$http_log);
    }

    public function testAboutPageExplainsFeaturesAndShowsSiteStatus(): void
    {
        $html = $this->render(['page' => 'ppapi-about'], 'render_about');

        foreach (['status', 'setup', 'features', 'paths', 'mappings', 'acf', 'duplicates', 'runs', 'example', 'security', 'limits'] as $section) {
            $this->assertContains('id="ppapi-about-' . $section . '"', $html);
        }
        $this->assertContains('About Post Press API ' . PPAPI_VERSION, $html);
        $this->assertContains('sessions[*].date', $html);
        $this->assertContains('America/New_York', $html);
        $this->assertContains('Value map (convert listed values)', $html, 'transformations listed from the plugin itself');
        $this->assertContains('Repeater (ACF PRO)', $html);
        $this->assertContains('DISABLE_WP_CRON', $html);
        $this->assertContains('https://example.test/wp-cron.php?doing_wp_cron', $html);

        $this->assertContains('>Available<', str_replace(["\n", ' '], '', $html), 'repeater available with the PRO stub');
        $this->assertContains(\PostPressAPI\Updates\GitHubUpdater::isConfigured() ? 'GitHub releases' : 'Not configured', $html, 'updater status');
        $this->assertContains('events', $html, 'destination post types listed');
    }

    public function testAboutStatusReflectsMissingRepeaterSupport(): void
    {
        \AcfStub::$types = array_values(array_diff(\AcfStub::$types, ['repeater']));

        $html = $this->render(['page' => 'ppapi-about'], 'render_about');
        $this->assertContains('Repeater fields require ACF PRO', $html);
    }

    public function testAboutPageRequiresManageOptions(): void
    {
        wpstub_add_user(2, 'subscriber', ['read']);
        wp_set_current_user(2);

        $this->assertThrows(WpDieException::class, fn () => $this->render(['page' => 'ppapi-about'], 'render_about'));
    }

    public function testMenuRegistersScreensAndLoadsAssetsOnlyThere(): void
    {
        $page = new AdminPage($this->services);
        $page->menu();

        foreach (['ppapi', 'ppapi@ppapi', 'ppapi-new@ppapi', 'ppapi-log@ppapi', 'ppapi-about@ppapi'] as $slug) {
            $this->assertArrayHasKey($slug, \WPStub::$menu);
            $this->assertSame('manage_options', \WPStub::$menu[$slug]['cap']);
        }
        $this->assertSame('About', \WPStub::$menu['ppapi-about@ppapi']['title']);

        $page->enqueue('edit.php');
        $page->enqueue('index.php');
        $this->assertSame([], \WPStub::$enqueued, 'no assets on other screens');

        $page->enqueue('post-press-api_page_ppapi-about');
        $this->assertSame(['style:ppapi-admin', 'script:ppapi-admin'], \WPStub::$enqueued);
    }

    public function testPluginsScreenLinks(): void
    {
        $links = (new AdminPage($this->services))->action_links(['deactivate' => '<a>Deactivate</a>']);
        $this->assertCount(3, $links);
        $this->assertContains('page=ppapi-about', $links[1]);

        wpstub_add_user(2, 'subscriber', ['read']);
        wp_set_current_user(2);
        $this->assertCount(1, (new AdminPage($this->services))->action_links(['deactivate' => '<a>Deactivate</a>']));
    }
}
