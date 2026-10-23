<?php
/**
 * "Maximum records per run" is a hard limit: excess records are never buffered,
 * written, or used to download images, whatever the API's pagination does.
 */

declare(strict_types=1);

use PostPressAPI\Mapping\CoreFields;
use PostPressAPI\Tests\TestCase;

final class RecordLimitTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
    }

    private function records(int $from, int $count, array $extra = []): array
    {
        $records = [];
        for ($i = $from; $i < $from + $count; $i++) {
            $records[] = ['id' => 'rec-' . $i, 'event' => ['title' => 'Event ' . $i]] + $extra;
        }
        return $records;
    }

    private function limited(int $max, array $pagination = [], array $extra = []): array
    {
        return $this->savePreset(array_replace_recursive([
            'batch_size' => 1,
            'pagination' => $pagination + ['max_records' => $max],
        ], $extra) + ['mappings' => $extra['mappings'] ?? [$this->row('event.title', 'core', 'post_title')]]);
    }

    private function offset(int $page_size): array
    {
        return ['type' => 'offset', 'offset_param' => 'offset', 'limit_param' => 'limit', 'page_size' => $page_size, 'has_more_path' => 'has_more'];
    }

    /** The confirmed bypass: page_size=1 and max_records=1, but the API returns three records. */
    public function testOversizedFirstPageIsCutToTheLimit(): void
    {
        $preset = $this->limited(1, $this->offset(1));
        $this->queueJson(['ok' => true, 'data' => $this->records(0, 3), 'has_more' => false]);

        $state = $this->runPreset($preset['id']);

        $this->assertSame('truncated', $state['status'], 'never reported as complete');
        $this->assertCount(1, $this->posts());
        $this->assertSame(1, $state['processed']);
        $this->assertSame(1, $state['counts']['created']);
        $this->assertContains('limit of 1 records per run', $state['message']);
        $this->assertContains('2 more records', $state['message']);
        $this->assertCount(1, \WPStub::$http_log, 'no further page is requested');
    }

    public function testTheLimitAppliesWithoutPagination(): void
    {
        $preset = $this->limited(2);
        $this->queueJson(['ok' => true, 'data' => $this->records(0, 5)]);

        $state = $this->runPreset($preset['id']);

        $this->assertSame('truncated', $state['status']);
        $this->assertCount(2, $this->posts());
        $this->assertSame(['Event 0', 'Event 1'], array_column($this->posts(), 'post_title'), 'records are taken in API order');
        $this->assertContains('3 more records', $state['message']);
    }

    public function testFinalPageCrossingTheLimit(): void
    {
        $preset = $this->limited(3, $this->offset(2));
        $this->queueJson(['ok' => true, 'data' => $this->records(0, 2), 'has_more' => true]);
        $this->queueJson(['ok' => true, 'data' => $this->records(2, 2), 'has_more' => false]);

        $state = $this->runPreset($preset['id']);

        $this->assertSame('truncated', $state['status']);
        $this->assertSame(3, $state['processed']);
        $this->assertCount(3, $this->posts());
        $this->assertContains('1 more record received', $state['message']);
        $this->assertSame(2, $state['pages']);
    }

    public function testReachingTheLimitExactlyCompletes(): void
    {
        $preset = $this->limited(3);
        $this->queueJson(['ok' => true, 'data' => $this->records(0, 3)]);
        $state = $this->runPreset($preset['id']);
        $this->assertSame('completed', $state['status']);
        $this->assertSame('', $state['message']);
        $this->assertCount(3, $this->posts());

        $paged = $this->limited(4, $this->offset(2));
        $this->queueJson(['ok' => true, 'data' => $this->records(10, 2), 'has_more' => true]);
        $this->queueJson(['ok' => true, 'data' => $this->records(12, 2), 'has_more' => false]);
        $this->assertSame('completed', $this->runPreset($paged['id'])['status'], 'the API reported no more records');

        // At the limit while the API still reports more: stopped early, as before.
        $more = $this->limited(2, $this->offset(2));
        $this->queueJson(['ok' => true, 'data' => $this->records(20, 2), 'has_more' => true]);
        $state = $this->runPreset($more['id']);
        $this->assertSame('truncated', $state['status']);
        $this->assertContains('safety limit', $state['message']);
    }

    public function testResumedBatchesNeverProcessMoreThanTheLimit(): void
    {
        $preset = $this->limited(2, $this->offset(10));
        $this->queueJson(['ok' => true, 'data' => $this->records(0, 6), 'has_more' => true]);

        $this->services->runner()->start($preset['id'], 'manual');
        $first = $this->services->runner()->step($preset['id'], 30.0)['state'];
        $this->assertSame('running', $first['status']);
        $this->assertSame(2, $first['buffer_size'], 'only the allowance is buffered');
        $this->assertCount(2, $this->services->runs()->buffer($preset['id']));
        $this->assertSame(1, $first['processed']);

        // Resume later, as the continuation event would.
        $this->clock->advance(60);
        do_action('ppapi_continue_run', $preset['id']);

        $last = $this->services->runs()->last($preset['id']);
        $this->assertSame('truncated', $last['status']);
        $this->assertSame(2, $last['processed']);
        $this->assertSame(6, $last['fetched'], 'records received are still reported');
        $this->assertCount(2, $this->posts());
        $this->assertCount(1, \WPStub::$http_log);
    }

    /** Confirmed gap: a run buffered by 1.0.5, which did not cut pages to the limit, resumed after upgrading. */
    public function testRunsBufferedBeforeUpgradingStopAtTheLimit(): void
    {
        foreach ([false, true] as $more_pages) {
            \WPStub::$http_log = [];
            $preset = $this->limited(1, $this->offset(3));
            $runner = $this->services->runner();
            $state  = $runner->start($preset['id'], 'manual');

            // As 1.0.5 left it: the whole page buffered and one record already processed.
            unset($state['run_as']);
            $state['pages']        = 1;
            $state['fetched']      = 3;
            $state['processed']    = 1;
            $state['buffer_index'] = 1;
            $state['buffer_size']  = 3;
            $state['more_pages']   = $more_pages;
            $state['counts']['created'] = 1;
            $this->services->runs()->save_buffer($preset['id'], $this->records(0, 3));
            $this->services->runs()->save_active($state);

            $result = $runner->step($preset['id'], 30.0)['state'];

            $label = $more_pages ? 'more pages reported' : 'last page';
            $this->assertSame('truncated', $result['status'], $label);
            $this->assertSame(1, $result['processed'], $label);
            $this->assertContains('limit of 1 records per run', $result['message'], $label);
            $this->assertContains('2 more records', $result['message'], $label);
            $this->assertSame('truncated', $this->services->runs()->last($preset['id'])['status'], $label);
            $this->assertNull($this->services->runs()->active($preset['id']), $label);
            $this->assertCount(0, $this->posts(), $label . ': the remaining buffered records are not imported');
            $this->assertCount(0, \WPStub::$http_log, $label . ': no page is requested');
        }
    }

    public function testOmittedRecordsDownloadNoImages(): void
    {
        $png    = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $preset = $this->limited(1, [], ['mappings' => [
            $this->row('event.title', 'core', 'post_title'),
            $this->row('image', 'core', CoreFields::IMAGE, ['media' => ['download' => true, 'output' => 'id']]),
        ]]);
        $records = [];
        foreach ($this->records(0, 3) as $i => $record) {
            $records[] = $record + ['image' => 'https://images.example.com/' . $i . '.png'];
        }
        $this->queueJson(['ok' => true, 'data' => $records]);
        $this->queueResponse(200, $png, ['content-type' => 'image/png']);

        $state = $this->runPreset($preset['id']);

        $this->assertSame('truncated', $state['status']);
        $this->assertSame(1, MediaStub::$imports);
        $this->assertCount(2, \WPStub::$http_log, 'one JSON page and one image');
    }
}
