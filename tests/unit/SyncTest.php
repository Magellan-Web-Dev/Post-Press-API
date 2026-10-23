<?php
/**
 * End-to-end import behavior against the sample payload (mocked HTTP, in-memory WordPress and ACF).
 */

declare(strict_types=1);

use PostPressAPI\Sync\IdentityResolver;
use PostPressAPI\Sync\PostWriter;
use PostPressAPI\Tests\TestCase;

final class SyncTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
    }

    /** Runs the preset once against $payload (defaults to the fixture). */
    private function import(array $preset, ?array $payload = null, bool $force = false): array
    {
        $this->queueJson($payload ?? $this->fixture());
        return $this->runPreset($preset['id'], $force);
    }

    public function testSampleImportsOnePostWithAllMappedFields(): void
    {
        $preset = $this->savePreset();
        $state  = $this->import($preset);

        $this->assertSame('completed', $state['status'], (string) $state['message']);
        $this->assertSame(1, $state['counts']['created']);
        $this->assertCount(1, $this->posts());

        $post = $this->posts()[0];
        $id   = (int) $post['ID'];
        $this->assertSame('Medicare Open Enrollment Q&A (Virtual)', $post['post_title']);
        $this->assertSame('Live Zoom session about Medicare enrollment.', $post['post_content']);
        $this->assertSame('publish', $post['post_status']);
        $this->assertSame(1, (int) $post['post_author']);

        $this->assertSame('1', $this->meta($id, 'is_virtual'));
        $this->assertSame('https://zoom.us/j/98765432100', $this->meta($id, 'meeting_url'));
        $this->assertSame('submitter@example.com', $this->meta($id, 'contact_email'));
        $this->assertSame('20261105', $this->meta($id, 'first_date'));
        $this->assertSame('1', $this->meta($id, 'sessions'));
        $this->assertSame('20261105', $this->meta($id, 'sessions_0_session_date'));
        $this->assertSame('12:00:00', $this->meta($id, 'sessions_0_session_time'));
        $this->assertSame('field_session_date', $this->meta($id, '_sessions_0_session_date'), 'sub field reference kept');
        $this->assertSame('field_virtual', $this->meta($id, '_is_virtual'), 'field-key reference stored on a new post');
        $this->assertSame('Nov 5, 2026 at 12:00 PM Eastern', $this->meta($id, 'event_dates_text'));

        $this->assertSame('ada78067-d914-41ae-82ec-9a4f0cb249c9', $this->meta($id, PostWriter::META_EXTERNAL_ID));
        $this->assertSame(IdentityResolver::key($preset['id'], 'events', 'ada78067-d914-41ae-82ec-9a4f0cb249c9'), $this->meta($id, PostWriter::META_KEY));
        $this->assertSame(PostWriter::STATE_COMPLETE, $this->meta($id, PostWriter::META_STATE));
        $this->assertSame($preset['id'], $this->meta($id, PostWriter::META_PRESET));
        $this->assertSame('', $this->meta($id, 'location_city'), 'null location was not written');
        $this->assertSame('', $this->meta($id, 'event_image_url'), 'null image was not written');

        $this->assertSame('https://api.example.com/events', \WPStub::$http_log[0]['url']);
        $this->assertTrue(\WPStub::$http_log[0]['safe'], 'wp_safe_remote_request is used');
        $this->assertSame(0, \WPStub::$http_log[0]['args']['redirection']);
        $this->assertTrue(\WPStub::$http_log[0]['args']['sslverify']);
    }

    public function testAcfWritesUseFieldKeys(): void
    {
        $this->import($this->savePreset());

        $selectors = array_column(\AcfStub::$update_calls, 0);
        $this->assertSame(['field_virtual', 'field_meeting', 'field_email', 'field_first_date', 'field_sessions'], $selectors, 'null location is preserved, not written');
        foreach ($selectors as $selector) {
            $this->assertTrue(str_starts_with($selector, 'field_'), 'update_field called with key, not name: ' . $selector);
        }
    }

    public function testSecondRunInUpdateModeCreatesNoDuplicate(): void
    {
        $preset = $this->savePreset();
        $this->import($preset);
        $calls = count(\AcfStub::$update_calls);

        $state = $this->import($preset);

        $this->assertSame('completed', $state['status']);
        $this->assertSame(1, $state['counts']['unchanged']);
        $this->assertSame(0, $state['counts']['created']);
        $this->assertCount(1, $this->posts());
        $this->assertSame($calls, count(\AcfStub::$update_calls), 'unchanged records are not rewritten');
    }

    public function testChangingAMappedValueUpdatesTheSamePost(): void
    {
        $preset = $this->savePreset();
        $this->import($preset);
        $id = (int) $this->posts()[0]['ID'];

        $payload                              = $this->fixture();
        $payload['data'][0]['event']['title'] = 'Medicare Q&A — Rescheduled';
        $payload['data'][0]['sessions'][0]['time'] = '13:30';
        $state = $this->import($preset, $payload);

        $this->assertSame(1, $state['counts']['updated']);
        $this->assertCount(1, $this->posts());
        $this->assertSame($id, (int) $this->posts()[0]['ID']);
        $this->assertSame('Medicare Q&A — Rescheduled', \WPStub::$posts[$id]['post_title']);
        $this->assertSame('13:30:00', $this->meta($id, 'sessions_0_session_time'));
    }

    public function testChangingOnlyAnUnmappedTimestampDoesNotUpdate(): void
    {
        $preset = $this->savePreset();
        $this->import($preset);
        $calls = count(\AcfStub::$update_calls);

        $payload                             = $this->fixture();
        $payload['data'][0]['updated_at']    = '2026-10-19T09:00:00+00:00';
        $payload['data'][0]['submitted_at']  = '2026-10-19T09:00:00+00:00';
        $payload['data'][0]['airtable']      = ['record' => 'rec123'];
        $state = $this->import($preset, $payload);

        $this->assertSame(1, $state['counts']['unchanged']);
        $this->assertSame(0, $state['counts']['updated']);
        $this->assertSame($calls, count(\AcfStub::$update_calls));
    }

    public function testMultipleSessionsKeepDateAndTimePairs(): void
    {
        $payload = $this->fixture();
        $payload['data'][0]['sessions'] = [
            ['date' => '2026-11-05', 'time' => '12:00', 'time_12h' => '12:00 PM'],
            ['date' => '2026-11-12', 'time' => '18:30', 'time_12h' => '6:30 PM'],
            ['date' => '2026-11-19', 'time' => '09:00', 'time_12h' => '9:00 AM'],
        ];
        $preset = $this->savePreset();
        $this->import($preset, $payload);
        $id = (int) $this->posts()[0]['ID'];

        $this->assertSame('3', $this->meta($id, 'sessions'));
        $pairs = [];
        for ($i = 0; $i < 3; $i++) {
            $pairs[] = $this->meta($id, "sessions_{$i}_session_date") . ' ' . $this->meta($id, "sessions_{$i}_session_time");
        }
        $this->assertSame(['20261105 12:00:00', '20261112 18:30:00', '20261119 09:00:00'], $pairs);

        // Shrinking the list removes stale rows instead of leaving them behind.
        $payload['data'][0]['sessions'] = [['date' => '2026-12-01', 'time' => '10:00']];
        $this->import($preset, $payload);
        $this->assertSame('1', $this->meta($id, 'sessions'));
        $this->assertSame('20261201', $this->meta($id, 'sessions_0_session_date'));
        $this->assertSame('', $this->meta($id, 'sessions_1_session_date'));
    }

    public function testNullLocationAndImageDoNotEraseExistingValues(): void
    {
        $preset = $this->savePreset();
        $this->import($preset);
        $id = (int) $this->posts()[0]['ID'];

        update_field('field_city', 'Springfield', $id);
        update_post_meta($id, 'event_image_url', 'https://cdn.example.com/existing.jpg');

        $payload                              = $this->fixture();
        $payload['data'][0]['event']['title'] = 'Changed title forces an update';
        $state = $this->import($preset, $payload);

        $this->assertSame(1, $state['counts']['updated']);
        $this->assertSame('Springfield', $this->meta($id, 'location_city'));
        $this->assertSame('https://cdn.example.com/existing.jpg', $this->meta($id, 'event_image_url'));
    }

    public function testUnmappedLocalContentIsPreservedOnUpdate(): void
    {
        $preset = $this->savePreset();
        $this->import($preset);
        $id = (int) $this->posts()[0]['ID'];
        update_post_meta($id, 'editor_notes', 'Keep me');
        \WPStub::$posts[$id]['post_excerpt'] = 'Hand-written excerpt';

        $payload                                    = $this->fixture();
        $payload['data'][0]['event']['description'] = 'New description';
        $this->import($preset, $payload);

        $this->assertSame('New description', \WPStub::$posts[$id]['post_content']);
        $this->assertSame('Hand-written excerpt', \WPStub::$posts[$id]['post_excerpt']);
        $this->assertSame('Keep me', $this->meta($id, 'editor_notes'));
    }

    public function testLocalEditsToMappedFieldsSurviveUntilTheSourceChanges(): void
    {
        $preset = $this->savePreset();
        $this->import($preset);
        $id = (int) $this->posts()[0]['ID'];
        \WPStub::$posts[$id]['post_title'] = 'Edited locally';

        $this->import($preset);
        $this->assertSame('Edited locally', \WPStub::$posts[$id]['post_title'], 'unchanged source leaves local edit');

        $payload                                    = $this->fixture();
        $payload['data'][0]['event']['description'] = 'Source changed';
        $this->import($preset, $payload);
        $this->assertSame('Medicare Open Enrollment Q&A (Virtual)', \WPStub::$posts[$id]['post_title'], 'source change rewrites mapped fields');
    }

    public function testSameTitleWithDifferentIdsCreatesSeparatePosts(): void
    {
        $payload          = $this->fixture();
        $second           = $payload['data'][0];
        $second['id']     = 'b7e1c2d3-0000-4000-8000-000000000002';
        $payload['data'][] = $second;

        $state = $this->import($this->savePreset(), $payload);

        $this->assertSame(2, $state['counts']['created']);
        $this->assertCount(2, $this->posts());
        $this->assertSame($this->posts()[0]['post_title'], $this->posts()[1]['post_title']);
    }

    public function testNumericIdsAreStoredAsExactStrings(): void
    {
        $preset = $this->savePreset();
        $this->queueResponse(200, '{"ok":true,"data":[{"id":98765432109876543210,"event":{"title":"Big"}},{"id":42,"event":{"title":"Small"}}]}');
        $this->runPreset($preset['id']);

        $ids = array_map(fn ($post) => $this->meta((int) $post['ID'], PostWriter::META_EXTERNAL_ID), $this->posts());
        sort($ids);
        $this->assertSame(['42', '98765432109876543210'], $ids);
    }

    public function testRecordsWithoutIdentityAreReportedAndNotImported(): void
    {
        $payload = $this->fixture();
        unset($payload['data'][0]['id']);
        $second       = $this->fixture()['data'][0];
        $second['id'] = '';
        $payload['data'][] = $second;

        $state = $this->import($this->savePreset(), $payload);

        $this->assertSame('completed_with_errors', $state['status']);
        $this->assertSame(2, $state['counts']['failed']);
        $this->assertCount(0, $this->posts());
        $this->assertContains('external ID', $state['issues'][0]['message']);
    }

    public function testCompositeIdentity(): void
    {
        $preset = $this->savePreset(['response' => ['identity' => ['mode' => 'composite', 'path' => '', 'paths' => ['event.title', 'sessions[0].date']]]]);
        $payload = $this->fixture();
        unset($payload['data'][0]['id']);

        $this->import($preset, $payload);
        $this->import($preset, $payload);
        $this->assertCount(1, $this->posts(), 'same composite values → same post');

        $payload['data'][0]['sessions'][0]['date'] = '2026-11-06';
        $this->import($preset, $payload);
        $this->assertCount(2, $this->posts(), 'changing an identity field creates a new identity');
    }

    public function testSkipExistingPolicyLeavesMatchesAlone(): void
    {
        $preset = $this->savePreset(['sync' => ['policy' => 'skip_existing']]);
        $this->import($preset);
        $id = (int) $this->posts()[0]['ID'];

        $payload                              = $this->fixture();
        $payload['data'][0]['event']['title'] = 'Different';
        $state = $this->import($preset, $payload);

        $this->assertSame(1, $state['counts']['skipped']);
        $this->assertSame('Medicare Open Enrollment Q&A (Virtual)', \WPStub::$posts[$id]['post_title']);
    }

    public function testAlwaysCreatePolicyIntentionallyDuplicates(): void
    {
        $preset = $this->savePreset(['sync' => ['policy' => 'always_create']]);
        $this->import($preset);
        $this->import($preset);

        $this->assertCount(2, $this->posts());
    }

    public function testPartialWriteFailureIsRetriedOnTheSamePost(): void
    {
        $preset = $this->savePreset();
        \AcfStub::$fail_keys['field_email'] = true;

        $state = $this->import($preset);
        $this->assertSame(1, $state['counts']['failed']);
        $this->assertContains('field_email', $state['issues'][0]['message']);
        $this->assertCount(1, $this->posts());

        $id = (int) $this->posts()[0]['ID'];
        $this->assertSame('draft', \WPStub::$posts[$id]['post_status'], 'never published with partial data');
        $this->assertSame(PostWriter::STATE_PENDING, $this->meta($id, PostWriter::META_STATE));
        $this->assertSame('', $this->meta($id, PostWriter::META_FINGERPRINT), 'no fingerprint before success');

        unset(\AcfStub::$fail_keys['field_email']);
        $state = $this->import($preset);

        $this->assertSame(1, $state['counts']['updated'], 'the incomplete post is completed even though the source did not change');
        $this->assertCount(1, $this->posts(), 'retry did not create a second post');
        $this->assertSame('publish', \WPStub::$posts[$id]['post_status']);
        $this->assertSame('submitter@example.com', $this->meta($id, 'contact_email'));
        $this->assertSame(PostWriter::STATE_COMPLETE, $this->meta($id, PostWriter::META_STATE));
        $this->assertNotSame('', $this->meta($id, PostWriter::META_FINGERPRINT));
    }

    public function testFailedUpdateIsRetriedEvenIfSourceRevertsToTheOldFingerprint(): void
    {
        $preset = $this->savePreset();
        $this->import($preset);
        $id = (int) $this->posts()[0]['ID'];

        $payload                              = $this->fixture();
        $payload['data'][0]['event']['title'] = 'New';
        \WPStub::$meta_fail['event_dates_text'] = true;
        $payload['data'][0]['dates_text']       = ['changed'];
        $state = $this->import($preset, $payload);
        $this->assertSame(1, $state['counts']['failed']);
        $this->assertSame(PostWriter::STATE_PENDING, $this->meta($id, PostWriter::META_STATE));

        unset(\WPStub::$meta_fail['event_dates_text']);
        $state = $this->import($preset);
        $this->assertSame(1, $state['counts']['updated'], 'a pending post is never skipped as unchanged');
        $this->assertSame('Medicare Open Enrollment Q&A (Virtual)', \WPStub::$posts[$id]['post_title']);
    }

    public function testUnchangedFalseReturnIsNotTreatedAsFailure(): void
    {
        $preset = $this->savePreset();
        $this->import($preset);

        $state = $this->import($preset, null, true);

        $this->assertSame(1, $state['counts']['updated'], 'forced re-apply writes identical values');
        $this->assertSame(0, $state['counts']['failed'], 'update_field()/update_post_meta() returning false for unchanged values is fine');
    }

    public function testMatchesExistingPostsByMetaField(): void
    {
        $existing = wp_insert_post(['post_type' => 'events', 'post_title' => 'Old manual post', 'post_status' => 'publish']);
        update_post_meta($existing, 'legacy_id', 'ada78067-d914-41ae-82ec-9a4f0cb249c9');

        $preset = $this->savePreset(['sync' => ['match' => ['type' => 'meta', 'key' => 'legacy_id']]]);
        $state  = $this->import($preset);

        $this->assertSame(1, $state['counts']['updated']);
        $this->assertCount(1, $this->posts());
        $this->assertSame('Medicare Open Enrollment Q&A (Virtual)', \WPStub::$posts[$existing]['post_title']);
        $this->assertSame(IdentityResolver::key($preset['id'], 'events', 'ada78067-d914-41ae-82ec-9a4f0cb249c9'), $this->meta($existing, PostWriter::META_KEY), 'linked for future runs');
    }

    public function testMatchesExistingPostsByAcfField(): void
    {
        $existing = wp_insert_post(['post_type' => 'events', 'post_title' => 'Old', 'post_status' => 'draft']);
        update_field('field_external_id', 'ada78067-d914-41ae-82ec-9a4f0cb249c9', $existing);

        $state = $this->import($this->savePreset(['sync' => ['match' => ['type' => 'acf', 'key' => 'field_external_id']]]));

        $this->assertSame(1, $state['counts']['updated']);
        $this->assertCount(1, $this->posts(), 'non-public (draft) posts are matched too');
    }

    public function testAmbiguousMatchesAreReportedInsteadOfGuessing(): void
    {
        foreach ([1, 2] as $n) {
            $id = wp_insert_post(['post_type' => 'events', 'post_title' => 'Copy ' . $n, 'post_status' => 'publish']);
            update_post_meta($id, 'legacy_id', 'ada78067-d914-41ae-82ec-9a4f0cb249c9');
        }

        $state = $this->import($this->savePreset(['sync' => ['match' => ['type' => 'meta', 'key' => 'legacy_id']]]));

        $this->assertSame(1, $state['counts']['failed']);
        $this->assertContains('Several existing posts', $state['issues'][0]['message']);
        $this->assertCount(2, $this->posts());
    }

    public function testDuplicatedIdentityMetaIsReportedAsAmbiguous(): void
    {
        $preset = $this->savePreset();
        $this->import($preset);
        $original = (int) $this->posts()[0]['ID'];

        // e.g. a "duplicate page" plugin copied every meta key, including the identity.
        $copy = wp_insert_post(['post_type' => 'events', 'post_title' => 'Copy', 'post_status' => 'draft']);
        update_post_meta($copy, PostWriter::META_KEY, $this->meta($original, PostWriter::META_KEY));

        $state = $this->import($preset);
        $this->assertSame(1, $state['counts']['failed']);
        $this->assertContains('Several posts', $state['issues'][0]['message']);
    }

    public function testTrashedMatchesAreSkippedByDefaultAndNeverRecreated(): void
    {
        $preset = $this->savePreset();
        $this->import($preset);
        $id = (int) $this->posts()[0]['ID'];
        wp_trash_post($id);

        $payload                              = $this->fixture();
        $payload['data'][0]['event']['title'] = 'Changed while trashed';
        $state = $this->import($preset, $payload);

        $this->assertSame(1, $state['counts']['skipped']);
        $this->assertCount(1, $this->posts());
        $this->assertSame('trash', \WPStub::$posts[$id]['post_status']);
    }

    public function testTrashedMatchesCanBeRestored(): void
    {
        $preset = $this->savePreset(['sync' => ['trashed' => 'restore']]);
        $this->import($preset);
        $id = (int) $this->posts()[0]['ID'];
        wp_trash_post($id);

        $state = $this->import($preset);

        $this->assertSame(1, $state['counts']['updated']);
        $this->assertSame('draft', \WPStub::$posts[$id]['post_status']);
    }

    public function testDryRunWritesNothing(): void
    {
        $preset    = $this->savePreset();
        $options   = array_keys(\WPStub::$options);

        $result = $this->services->preview()->dry_run($preset, null, (string) file_get_contents(PPAPI_TEST_ROOT . '/tests/fixtures/sample.json'));

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['counts']['created']);
        $this->assertSame('created', $result['records'][0]['action']);
        $this->assertCount(0, \WPStub::$posts, 'no posts or attachments');
        $this->assertSame([], \WPStub::$meta, 'no meta');
        $this->assertSame([], \AcfStub::$update_calls, 'no ACF writes');
        $this->assertSame($options, array_keys(\WPStub::$options), 'no run, history, or tracking state');
        $this->assertSame([], \WPStub::$http_log, 'pasted sample used instead of the API');

        $details = [];
        foreach ($result['records'][0]['details'] as $detail) {
            $details[$detail['target']] = $detail;
        }
        $this->assertSame('boolean', $details['Virtual']['raw_type']);
        $this->assertSame('null', $details['City']['state']);
        $this->assertSame('preserve', $details['City']['result']);
    }

    public function testDryRunAgainstExistingPostPredictsUpdateWithoutWriting(): void
    {
        $preset = $this->savePreset();
        $this->import($preset);
        $snapshot = [\WPStub::$posts, \WPStub::$meta];

        $payload                              = $this->fixture();
        $payload['data'][0]['event']['title'] = 'Would change';
        $this->queueJson($payload);
        $result = $this->services->preview()->dry_run($preset, null, null);

        $this->assertSame('updated', $result['records'][0]['action']);
        $this->assertSame($snapshot, [\WPStub::$posts, \WPStub::$meta]);
    }
}
