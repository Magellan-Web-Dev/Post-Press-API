<?php
/**
 * Run pipeline: pagination, batching/continuation, retries, failures, locking,
 * snapshots, redirects, and credential handling.
 */

declare(strict_types=1);

use PostPressAPI\Sync\RunLock;
use PostPressAPI\Tests\TestCase;

final class RunnerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
    }

    /** A page of $count simple records starting at $start. */
    private function page(int $start, int $count, ?bool $has_more = null, ?int $total = null): array
    {
        $records = [];
        for ($i = $start; $i < $start + $count; $i++) {
            $records[] = ['id' => 'rec-' . $i, 'event' => ['title' => 'Event ' . $i]];
        }
        $page = ['ok' => true, 'data' => $records, 'pagination' => ['offset' => $start, 'limit' => $count]];
        if ($has_more !== null) {
            $page['pagination']['has_more'] = $has_more;
        }
        if ($total !== null) {
            $page['pagination']['total'] = $total;
        }
        return $page;
    }

    private function pagedPreset(array $pagination = [], array $extra = []): array
    {
        return $this->savePreset(array_replace_recursive([
            'mappings'   => [$this->row('event.title', 'core', 'post_title')],
            'batch_size' => 1,
            'pagination' => $pagination + [
                'type'          => 'offset',
                'offset_param'  => 'offset',
                'limit_param'   => 'limit',
                'page_size'     => 2,
                'has_more_path' => 'pagination.has_more',
            ],
        ], $extra) + ['mappings' => [$this->row('event.title', 'core', 'post_title')]]);
    }

    private function queryOf(int $request): array
    {
        parse_str((string) parse_url(\WPStub::$http_log[$request]['url'], PHP_URL_QUERY), $query);
        return $query;
    }

    public function testOffsetPaginationContinuesAcrossBatchesUntilHasMoreIsFalse(): void
    {
        $preset = $this->pagedPreset();
        $this->queueJson($this->page(0, 2, true));
        $this->queueJson($this->page(2, 2, true));
        $this->queueJson($this->page(4, 1, false));

        $this->assertNotNull($this->services->runner()->start($preset['id'], 'manual'));
        $steps = 0;
        do {
            $result = $this->services->runner()->step($preset['id'], 30.0);
            $steps++;
            if ($result['state']['status'] === 'running') {
                $this->assertSame(1, wpstub_count_events('ppapi_continue_run', [$preset['id']]), 'one continuation event keeps the run going in the background');
            }
        } while (in_array($result['state']['status'], ['running', 'waiting'], true) && $steps < 50);

        $state = $result['state'];
        $this->assertSame('completed', $state['status']);
        $this->assertSame(5, $state['counts']['created']);
        $this->assertSame(3, $state['pages']);
        $this->assertTrue($steps >= 5, 'batch size 1 spreads the work over several steps');
        $this->assertSame(['offset' => '0', 'limit' => '2'], $this->queryOf(0));
        $this->assertSame(['offset' => '2', 'limit' => '2'], $this->queryOf(1));
        $this->assertSame(['offset' => '4', 'limit' => '2'], $this->queryOf(2));
        $this->assertSame(0, wpstub_count_events('ppapi_continue_run', [$preset['id']]), 'continuation cleared when finished');
        $this->assertCount(5, $this->posts());
    }

    public function testPagePaginationInJsonBody(): void
    {
        $preset = $this->pagedPreset(['type' => 'page', 'page_param' => 'page', 'limit_param' => 'per_page', 'placement' => 'body'], ['request' => ['method' => 'POST', 'body' => '{"filter":"upcoming"}']]);
        $this->queueJson($this->page(0, 2, true));
        $this->queueJson($this->page(2, 1, false));

        $state = $this->runPreset($preset['id']);

        $this->assertSame('completed', $state['status']);
        $this->assertSame(['filter' => 'upcoming', 'page' => 1, 'per_page' => 2], json_decode(\WPStub::$http_log[0]['args']['body'], true));
        $this->assertSame(['filter' => 'upcoming', 'page' => 2, 'per_page' => 2], json_decode(\WPStub::$http_log[1]['args']['body'], true));
        $this->assertSame('application/json', \WPStub::$http_log[0]['args']['headers']['Content-Type']);
    }

    public function testWithoutHasMoreFullPagesContinueAndShortPageEnds(): void
    {
        $preset = $this->pagedPreset(['has_more_path' => '']);
        $this->queueJson($this->page(0, 2));
        $this->queueJson($this->page(2, 1));

        $state = $this->runPreset($preset['id']);
        $this->assertSame('completed', $state['status']);
        $this->assertSame(2, $state['pages']);
    }

    public function testTotalPathStopsAtTotal(): void
    {
        $preset = $this->pagedPreset(['has_more_path' => '', 'total_path' => 'pagination.total']);
        $this->queueJson($this->page(0, 2, null, 4));
        $this->queueJson($this->page(2, 2, null, 4));

        $state = $this->runPreset($preset['id']);
        $this->assertSame('completed', $state['status']);
        $this->assertCount(2, \WPStub::$http_log, 'no request beyond the reported total');
    }

    public function testEmptyPageEndsTheRun(): void
    {
        $preset = $this->pagedPreset(['has_more_path' => '']);
        $this->queueJson($this->page(0, 2));
        $this->queueJson($this->page(2, 0));

        $this->assertSame('completed', $this->runPreset($preset['id'])['status']);
    }

    public function testEmptyPageWhileApiClaimsMoreIsReportedAsTruncated(): void
    {
        $preset = $this->pagedPreset();
        $this->queueJson($this->page(0, 2, true));
        $this->queueJson($this->page(2, 0, true));

        $state = $this->runPreset($preset['id']);
        $this->assertSame('truncated', $state['status']);
        $this->assertContains('empty page', $state['message']);
    }

    public function testRepeatedPageStopsTheRun(): void
    {
        $preset = $this->pagedPreset();
        $this->queueJson($this->page(0, 2, true));
        $this->queueJson($this->page(0, 2, true));

        $state = $this->runPreset($preset['id']);
        $this->assertSame('failed', $state['status']);
        $this->assertContains('same records again', $state['message']);
        $this->assertCount(2, $this->posts());
    }

    public function testMissingHasMoreFlagIsAnError(): void
    {
        $preset = $this->pagedPreset();
        $page   = $this->page(0, 2);
        $this->queueJson($page);

        $state = $this->runPreset($preset['id']);
        $this->assertSame('failed', $state['status']);
        $this->assertContains('has-more path', $state['message']);
    }

    public function testSafetyLimitMarksRunTruncatedNotComplete(): void
    {
        $preset = $this->pagedPreset(['max_pages' => 2]);
        $this->queueJson($this->page(0, 2, true));
        $this->queueJson($this->page(2, 2, true));

        $state = $this->runPreset($preset['id']);
        $this->assertSame('truncated', $state['status']);
        $this->assertSame(4, $state['counts']['created']);
        $this->assertContains('safety limit', $state['message']);
    }

    public function testRetryAfterIsHonoredAndPersistedNotSlept(): void
    {
        $preset = $this->savePreset();
        $this->queueResponse(429, '{"error":"slow down"}', ['retry-after' => '120'], 'Too Many Requests');
        $this->queueJson($this->fixture());

        $this->services->runner()->start($preset['id'], 'manual');
        $state = $this->services->runner()->step($preset['id'], 30.0)['state'];

        $this->assertSame('waiting', $state['status']);
        $this->assertSame($this->clock->now() + 120, $state['resume_at']);
        $this->assertSame($this->clock->now() + 120, wp_next_scheduled('ppapi_continue_run', [$preset['id']]), 'continuation scheduled for the retry time');

        $this->services->runner()->step($preset['id'], 30.0);
        $this->assertCount(1, \WPStub::$http_log, 'no request before Retry-After elapses');

        $this->clock->advance(120);
        $state = $this->services->runner()->step($preset['id'], 30.0)['state'];
        $this->assertSame('completed', $state['status']);
        $this->assertCount(2, \WPStub::$http_log);
    }

    public function testRetriesAreBoundedAndFailureIsReported(): void
    {
        $preset = $this->savePreset(['request' => ['retries' => 1]]);
        $this->queueResponse(503, 'down', [], 'Service Unavailable');
        $this->queueResponse(503, 'down', [], 'Service Unavailable');

        $state = $this->runPreset($preset['id']);
        $this->assertSame('failed', $state['status']);
        $this->assertContains('HTTP 503', $state['message']);
        $this->assertContains('Gave up after 1 retry', $state['message']);
        $this->assertCount(2, \WPStub::$http_log);
    }

    public function testNetworkErrorsAreRetried(): void
    {
        $preset = $this->savePreset();
        \WPStub::$http_queue[] = new WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
        $this->queueJson($this->fixture());

        $this->assertSame('completed', $this->runPreset($preset['id'])['status']);
    }

    public function testPermanentFailuresAreNotRetried(): void
    {
        $cases = [
            [fn () => $this->queueResponse(404, 'nope', [], 'Not Found'), 'HTTP 404'],
            [fn () => $this->queueResponse(200, '{"data": [', ['content-type' => 'application/json']), 'not valid JSON'],
            [fn () => $this->queueJson(['ok' => false, 'error' => 'bad token']), 'reported a failure'],
            [fn () => $this->queueJson(['ok' => true, 'items' => []]), 'records path "data" was not found'],
            [fn () => $this->queueJson(['ok' => true, 'data' => ['id' => 1]]), 'not a list'],
            [fn () => $this->queueJson(['ok' => true, 'data' => null]), 'null instead of a list'],
        ];

        foreach ($cases as [$queue, $expected]) {
            \WPStub::$http_log = [];
            $preset = $this->savePreset();
            $queue();
            $state = $this->runPreset($preset['id']);
            $this->assertSame('failed', $state['status'], $expected);
            $this->assertContains($expected, $state['message']);
            $this->assertCount(1, \WPStub::$http_log, $expected . ' is not retried');
        }
        $this->assertCount(0, $this->posts());
    }

    public function testOversizedResponseIsRejectedNotTruncated(): void
    {
        $preset  = $this->savePreset(['request' => ['max_kb' => 16]]);
        $payload = $this->fixture();
        $payload['data'][0]['event']['description'] = str_repeat('x', 20000);
        $this->queueJson($payload);

        $state = $this->runPreset($preset['id']);
        $this->assertSame('failed', $state['status']);
        $this->assertContains('size limit', $state['message']);
        $this->assertCount(0, $this->posts());
    }

    public function testLockPreventsConcurrentRunsOfOnePreset(): void
    {
        $preset = $this->savePreset();
        $lock   = new RunLock($this->clock);
        $token  = $lock->acquire($preset['id']);
        $this->assertNotNull($token);
        $this->assertNull($lock->acquire($preset['id']), 'second acquire fails while held');

        $start = $this->services->runner()->start($preset['id'], 'manual');
        $this->assertInstanceOf(WP_Error::class, $start);

        $lock->release($preset['id'], $token);
        $this->queueJson($this->fixture());
        $state = $this->services->runner()->start($preset['id'], 'manual');
        $this->assertSame('running', $state['status']);

        $second = $this->services->runner()->start($preset['id'], 'manual');
        $this->assertInstanceOf(WP_Error::class, $second, 'an unfinished run blocks a new one');
        $this->assertSame('ppapi_running', $second->get_error_code());

        $busy_token = $lock->acquire($preset['id']);
        $busy       = $this->services->runner()->step($preset['id'], 30.0);
        $this->assertTrue($busy['busy'], 'a step skips while another process holds the lock');
        $this->assertCount(0, \WPStub::$http_log);
        $lock->release($preset['id'], $busy_token);

        $this->assertSame('completed', $this->services->runner()->step($preset['id'], 30.0)['state']['status']);
    }

    public function testStaleLocksAreRecoveredAtomically(): void
    {
        $lock = new RunLock($this->clock);
        $this->assertNotNull($lock->acquire('aaaaaaaaaaaa', 60));

        $this->clock->advance(61);
        $this->assertFalse($lock->is_locked('aaaaaaaaaaaa'));
        $token = $lock->acquire('aaaaaaaaaaaa');
        $this->assertNotNull($token, 'expired lock taken over');
        $this->assertNull($lock->acquire('aaaaaaaaaaaa'), 'taken-over lock is held again');

        $lock->release('aaaaaaaaaaaa', 'not-the-owner');
        $this->assertTrue($lock->is_locked('aaaaaaaaaaaa'), 'only the owner can release');
        $lock->release('aaaaaaaaaaaa', $token);
        $this->assertFalse($lock->is_locked('aaaaaaaaaaaa'));
        $this->assertSame('no', \WPStub::$options['ppapi_lock_aaaaaaaaaaaa']['autoload'] ?? 'no');
    }

    public function testStaleUnfinishedRunIsReplaced(): void
    {
        $preset = $this->savePreset();
        $this->queueJson($this->fixture());
        $this->services->runner()->start($preset['id'], 'manual');

        $this->clock->advance(\PostPressAPI\Sync\ImportRunner::STALE_AFTER + 1);
        $this->queueJson($this->fixture());
        $state = $this->services->runner()->start($preset['id'], 'manual');

        $this->assertSame('running', $state['status']);
        $this->assertSame('failed', $this->services->runs()->last($preset['id'])['status']);
        $this->assertContains('Abandoned', $this->services->runs()->last($preset['id'])['message']);
    }

    public function testRunUsesSnapshotWhenPresetChangesMidRun(): void
    {
        $preset = $this->pagedPreset();
        $this->queueJson($this->page(0, 2, true));
        $this->queueJson($this->page(2, 1, false));

        $this->services->runner()->start($preset['id'], 'manual');
        $this->services->runner()->step($preset['id'], 30.0);

        $changed             = $this->services->presets()->find($preset['id']);
        $changed['mappings'] = [$this->row('id', 'core', 'post_title')];
        $this->services->presets()->save($changed, 1);

        do {
            $state = $this->services->runner()->step($preset['id'], 30.0)['state'];
        } while (in_array($state['status'], ['running', 'waiting'], true));

        $this->assertSame(['Event 0', 'Event 1', 'Event 2'], array_column($this->posts(), 'post_title'), 'the whole run used the snapshot');
        $this->assertSame((int) $preset['revision'], $state['revision']);
    }

    public function testCancellingStopsTheRunAndKeepsImportedPosts(): void
    {
        $preset = $this->pagedPreset();
        $this->queueJson($this->page(0, 2, true));
        $this->services->runner()->start($preset['id'], 'manual');
        $this->services->runner()->step($preset['id'], 30.0);

        $result = $this->services->runner()->cancel($preset['id'], 'Cancelled by test.');
        $this->assertTrue($result['cancelled']);
        $this->assertSame('cancelled', $this->services->runs()->last($preset['id'])['status']);
        $this->assertNull($this->services->runs()->active($preset['id']));
        $this->assertSame(0, wpstub_count_events('ppapi_continue_run'));
        $this->assertCount(1, $this->posts());
    }

    public function testCancelRequestedWhileLockedStopsAfterCurrentRecord(): void
    {
        $preset = $this->pagedPreset();
        $this->queueJson($this->page(0, 2, true));
        $this->services->runner()->start($preset['id'], 'manual');

        $lock  = new RunLock($this->clock);
        $token = $lock->acquire($preset['id']);
        $this->assertTrue($this->services->runner()->cancel($preset['id'], 'Stop please.')['pending']);
        $lock->release($preset['id'], $token);

        $state = $this->services->runner()->step($preset['id'], 30.0)['state'];
        $this->assertSame('cancelled', $state['status']);
        $this->assertSame('Stop please.', $state['message']);
    }

    public function testDisabledOrIncompletePresetsCannotRun(): void
    {
        $disabled = $this->savePreset(['enabled' => false]);
        $this->assertSame('ppapi_disabled', $this->services->runner()->start($disabled['id'], 'manual')->get_error_code());

        $incomplete = $this->savePreset(['response' => ['records_path' => '']]);
        $this->assertSame('ppapi_not_ready', $this->services->runner()->start($incomplete['id'], 'manual')->get_error_code());
        $this->assertCount(0, \WPStub::$http_log);
    }

    public function testBearerTokenIsNeverForwardedToAnotherHost(): void
    {
        $preset = $this->savePreset(['request' => ['auth' => ['type' => 'bearer']]]);
        $this->services->secrets()->set($preset['id'], 'sk_live_SECRET123', 'https://api.example.com:443');
        $this->queueResponse(302, '', ['location' => 'https://evil.example.net/collect'], 'Found');

        $state = $this->runPreset($preset['id']);

        $this->assertSame('failed', $state['status']);
        $this->assertContains('different host', $state['message']);
        $this->assertCount(1, \WPStub::$http_log, 'the redirect was not followed');
        $this->assertSame('Bearer sk_live_SECRET123', \WPStub::$http_log[0]['args']['headers']['Authorization']);
        $this->assertNotContains('SECRET123', serialize(\WPStub::$options['ppapi_log']['value']), 'log never contains the secret');
        $this->assertNotContains('SECRET123', serialize($this->services->runs()->history($preset['id'])));
    }

    public function testSameHostRedirectsAreFollowedWithinLimits(): void
    {
        $preset = $this->savePreset(['request' => ['auth' => ['type' => 'api_key_query', 'name' => 'api_key']]]);
        $this->services->secrets()->set($preset['id'], 'QUERYSECRET', 'https://api.example.com:443');
        $this->queueResponse(301, '', ['location' => '/v2/events'], 'Moved');
        $this->queueJson($this->fixture());

        $state = $this->runPreset($preset['id']);

        $this->assertSame('completed', $state['status']);
        $this->assertSame('https://api.example.com/v2/events?api_key=QUERYSECRET', \WPStub::$http_log[1]['url']);
        $this->assertNotContains('QUERYSECRET', serialize(\WPStub::$options['ppapi_log']['value']));
    }

    public function testCredentialEnteredForAnotherHostIsNotSent(): void
    {
        $preset = $this->savePreset(['request' => ['auth' => ['type' => 'bearer']]]);
        $this->services->secrets()->set($preset['id'], 'TOKEN', 'https://old-host.example.com:443');

        $this->assertSame('ppapi_not_ready', $this->services->runner()->start($preset['id'], 'manual')->get_error_code());
        $this->assertCount(0, \WPStub::$http_log);
    }

    public function testPrivateNetworkEndpointsAreRefused(): void
    {
        $preset = $this->savePreset(['request' => ['url' => 'http://127.0.0.1/admin']]);

        $state = $this->runPreset($preset['id']);
        $this->assertSame('failed', $state['status']);
        $this->assertContains('not allowed', $state['message']);
        $this->assertCount(0, \WPStub::$http_log);
    }

    public function testDestinationProblemsStopOnlyThatPreset(): void
    {
        $broken = $this->savePreset(['mappings' => [$this->row('event.title', 'acf', 'field_removed_later')]]);
        $good   = $this->savePreset();

        $this->queueJson($this->fixture());
        $state = $this->runPreset($broken['id']);
        $this->assertSame('failed', $state['status']);
        $this->assertContains('mapping 1', $state['message']);

        $this->queueJson($this->fixture());
        $this->assertSame('completed', $this->runPreset($good['id'])['status']);
    }

    public function testHistoryIsBounded(): void
    {
        $preset = $this->savePreset();
        for ($i = 0; $i < 27; $i++) {
            $this->queueJson($this->fixture());
            $this->runPreset($preset['id']);
        }

        $history = $this->services->runs()->history($preset['id']);
        $this->assertCount(25, $history);
        $this->assertSame(1, $history[25 - 1]['counts']['unchanged']);
        $this->assertSame('no', \WPStub::$options['ppapi_history_' . $preset['id']]['autoload']);
        $this->assertTrue(count(\WPStub::$options['ppapi_log']['value']) <= 200);
    }
}
