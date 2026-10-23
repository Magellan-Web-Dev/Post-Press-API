<?php
/** Image imports through the real mapping, fingerprint, writer, and run pipeline. */

declare(strict_types=1);

use PostPressAPI\Mapping\CoreFields;
use PostPressAPI\Mapping\MappingSchema;
use PostPressAPI\Media\ImageImporter;
use PostPressAPI\Sync\Fingerprint;
use PostPressAPI\Sync\PostWriter;
use PostPressAPI\Sync\WriteError;
use PostPressAPI\Tests\TestCase;

final class MediaTest extends TestCase
{
    private const URL = 'https://images.example.com/render?size=large&signature=secret-signature';

    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
    }

    /** Valid one-pixel PNG, used even for extensionless image URLs. */
    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    }

    /** A minimal media preset with four ways of storing the same image. */
    private function preset(): array
    {
        $media = ['media' => ['download' => true, 'output' => 'id']];
        return $this->savePreset(['mappings' => [
            $this->row('event.title', 'core', 'post_title'),
            $this->row('image', 'core', CoreFields::IMAGE, $media),
            $this->row('image', 'acf', 'field_image', $media),
            $this->row('image', 'meta', 'image_id', $media),
            $this->row('image', 'meta', 'image_url', ['media' => ['download' => true, 'output' => 'url']]),
        ]]);
    }

    /** One source record containing the desired remote image URL. */
    private function payload(?string $url = self::URL): array
    {
        $payload = $this->fixture();
        $payload['data'][0]['image'] = $url;
        return $payload;
    }

    public function testDownloadsOnceAndWritesLocalIdsAndUrl(): void
    {
        $preset = $this->preset();
        $this->queueJson($this->payload());
        $this->queueResponse(200, $this->png(), ['content-type' => 'application/octet-stream']);
        $run = $this->runPreset($preset['id']);
        $this->assertSame(1, $run['counts']['created'], wp_json_encode($run['issues']));
        $post = $this->posts('events')[0]['ID'];
        $attachment = get_post_thumbnail_id($post);
        $this->assertTrue($attachment > 0);
        $this->assertSame((string) $attachment, get_post_meta($post, 'event_image', true));
        $this->assertSame('field_image', get_post_meta($post, '_event_image', true));
        $this->assertSame((string) $attachment, get_post_meta($post, 'image_id', true));
        $this->assertSame(wp_get_attachment_url($attachment), get_post_meta($post, 'image_url', true));
        $this->assertSame(1, MediaStub::$imports);
        $this->assertTrue(WPStub::$http_log[1]['safe']);
        $this->assertSame([], WPStub::$http_log[1]['args']['cookies']);
        $this->assertSame(['Accept' => 'image/*'], WPStub::$http_log[1]['args']['headers']);
        $this->assertSame(hash('sha256', self::URL), get_post_meta($attachment, ImageImporter::SOURCE_META, true));
        $this->assertNotContains('secret-signature', wp_json_encode(WPStub::$meta));

        $this->queueJson($this->payload());
        $this->assertSame(1, $this->runPreset($preset['id'])['counts']['unchanged']);
        $this->assertSame(1, MediaStub::$imports);
        $this->assertCount(3, WPStub::$http_log, 'unchanged record only fetches JSON');
    }

    public function testDryRunDoesNotDownloadOrWrite(): void
    {
        $preset = $this->preset();
        $options = WPStub::$options;
        $preview = $this->services->preview()->dry_run($preset, null, wp_json_encode($this->payload()));
        $this->assertTrue($preview['ok']);
        $this->assertContains('Would download or reuse', wp_json_encode($preview));
        $this->assertSame([], WPStub::$http_log);
        $this->assertSame([], WPStub::$posts);
        $this->assertSame($options, WPStub::$options);
        $this->assertSame([], MediaStub::$files);
    }

    public function testSkippedRecordNeverDownloadsChangedImage(): void
    {
        $preset = $this->preset();
        $this->queueJson($this->payload());
        $this->queueResponse(200, $this->png());
        $this->runPreset($preset['id']);
        $preset['sync']['policy'] = 'skip_existing';
        $this->services->presets()->save($preset);
        $this->queueJson($this->payload(self::URL . '2'));
        $this->assertSame(1, $this->runPreset($preset['id'])['counts']['skipped']);
        $this->assertSame(1, MediaStub::$imports);
    }

    public function testFailedDownloadPreservesPreviousImageAndRetriesSamePost(): void
    {
        $preset = $this->preset();
        $this->queueJson($this->payload());
        $this->queueResponse(200, $this->png());
        $this->runPreset($preset['id']);
        $post = $this->posts('events')[0]['ID'];
        $old = get_post_thumbnail_id($post);
        $fingerprint = get_post_meta($post, PostWriter::META_FINGERPRINT, true);
        $this->queueJson($this->payload(self::URL . '2'));
        $this->queueResponse(200, '<html>Not an image</html>', ['content-type' => 'image/png']);
        $run = $this->runPreset($preset['id']);
        $this->assertSame(1, $run['counts']['failed']);
        $this->assertSame($old, get_post_thumbnail_id($post));
        $this->assertSame($fingerprint, get_post_meta($post, PostWriter::META_FINGERPRINT, true));
        $this->assertSame('pending', get_post_meta($post, PostWriter::META_STATE, true));
        $this->assertNotContains('secret-signature', wp_json_encode($run));
        foreach (MediaStub::$files as $file) {
            if (!in_array($file, MediaStub::$attachments, true)) { $this->assertFalse(is_file($file)); }
        }
        $this->queueJson($this->payload(self::URL . '2'));
        $this->queueResponse(200, $this->png());
        $this->assertSame(1, $this->runPreset($preset['id'])['counts']['updated']);
        $this->assertCount(1, $this->posts('events'));
        $this->assertNotSame($old, get_post_thumbnail_id($post));
        $this->assertTrue(wp_attachment_is_image($old), 'previous attachment is retained');
    }

    public function testRetryAfterFieldFailureReusesSuccessfulDownload(): void
    {
        $preset = $this->preset();
        WPStub::$meta_fail['image_url'] = true;
        $this->queueJson($this->payload());
        $this->queueResponse(200, $this->png());
        $this->assertSame(1, $this->runPreset($preset['id'])['counts']['failed']);
        unset(WPStub::$meta_fail['image_url']);
        $this->queueJson($this->payload());
        $this->assertSame(1, $this->runPreset($preset['id'])['counts']['updated']);
        $this->assertSame(1, MediaStub::$imports);
        $this->assertCount(1, $this->posts('events'));
    }

    public function testForcedReapplyRepairsDeletedAttachmentAndClearKeepsMedia(): void
    {
        $preset = $this->preset();
        $this->queueJson($this->payload());
        $this->queueResponse(200, $this->png());
        $this->runPreset($preset['id']);
        $post = $this->posts('events')[0]['ID'];
        wp_delete_attachment(get_post_thumbnail_id($post), true);
        $this->queueJson($this->payload());
        $this->queueResponse(200, $this->png());
        $this->assertSame(1, $this->runPreset($preset['id'], true)['counts']['updated']);
        $image = get_post_thumbnail_id($post);
        foreach ($preset['mappings'] as &$row) { if (!empty($row['media']['download'])) { $row['on_null'] = 'clear'; } }
        unset($row);
        $this->services->presets()->save($preset);
        $this->queueJson($this->payload(null));
        $this->runPreset($preset['id']);
        $this->assertSame(0, get_post_thumbnail_id($post));
        $this->assertTrue(wp_attachment_is_image($image));
    }

    public function testNestedGroupAndRepeaterImagesAndFallback(): void
    {
        AcfStub::add_group('group_media', 'Media', ['events'], [
            ['key' => 'field_box', 'name' => 'box', 'type' => 'group', 'sub_fields' => [
                ['key' => 'field_rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => [
                    ['key' => 'field_photo', 'name' => 'photo', 'type' => 'image'],
                ]],
            ]],
        ]);
        $preset = $this->savePreset(['mappings' => [$this->row('$', 'acf', 'field_box', ['children' => [
            $this->row('sessions', 'acf', 'field_rows', ['children' => [
                $this->row('missing_image', 'acf', 'field_photo', ['media' => ['download' => true, 'output' => 'id'], 'on_missing' => 'fallback', 'on_null' => 'clear', 'fallback' => self::URL]),
            ]]),
        ]])]]);
        $this->queueJson($this->payload());
        $this->queueResponse(200, $this->png());
        $run = $this->runPreset($preset['id']);
        $this->assertSame(1, $run['counts']['created'], wp_json_encode($run['issues']));
        $post = $this->posts('events')[0]['ID'];
        $this->assertTrue((int) get_post_meta($post, 'box_rows_0_photo', true) > 0);
        $this->assertSame('field_photo', get_post_meta($post, '_box_rows_0_photo', true));
    }

    public function testRejectsUnsafeRedirectsOversizedAndNonImageResponses(): void
    {
        $importer = new ImageImporter($this->services->lock());
        $this->assertThrows(WriteError::class, fn () => $importer->import('http://127.0.0.1/a.png', 0), 'public');
        $this->assertSame([], WPStub::$http_log);
        $this->queueResponse(302, '', ['location' => 'http://169.254.169.254/image']);
        $this->assertThrows(WriteError::class, fn () => $importer->import(self::URL, 0), 'public');
        $this->queueResponse(200, str_repeat('x', ImageImporter::MAX_BYTES + 1));
        $this->assertThrows(WriteError::class, fn () => $importer->import(self::URL, 0), 'size limit');
        $this->queueResponse(200, '<svg></svg>');
        $this->assertThrows(WriteError::class, fn () => $importer->import(self::URL, 0), 'supported image');
        MediaStub::$dimensions = [100000, 100000];
        $this->queueResponse(200, $this->png());
        $this->assertThrows(WriteError::class, fn () => $importer->import(self::URL, 0), 'megapixel');
        foreach (MediaStub::$files as $file) { $this->assertFalse(is_file($file)); }
    }

    public function testUrlLockPreventsCompetingDownload(): void
    {
        $key = 'image_' . hash('sha256', self::URL);
        $token = $this->services->lock()->acquire($key);
        $importer = new ImageImporter($this->services->lock());
        $this->assertThrows(WriteError::class, fn () => $importer->import(self::URL, 0), 'another run');
        $this->assertSame([], WPStub::$http_log);
        $this->services->lock()->release($key, $token);
    }

    /** Reconstructs historical rows without optional media, date, or condition settings. */
    public function testOffMediaOptionsPreserveHistoricalMappingHash(): void
    {
        $preset = $this->savePreset();
        $oldRows = $preset['mappings'];
        $strip = static function (array $rows) use (&$strip): array {
            foreach ($rows as &$row) {
                // Reconstruct the schema before these opt-in features existed.
                unset($row['media'], $row['id'], $row['condition'], $row['options']['join_datetime']);
                $row['children'] = $strip($row['children']);
            }
            unset($row);
            return $rows;
        };
        $old = hash('sha256', wp_json_encode(['post_type' => 'events', 'mappings' => $strip($oldRows)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $this->assertSame($old, Fingerprint::mapping_hash($preset));
    }

    public function testUploadPermissionIsRequiredForSavingAndScheduledRun(): void
    {
        $preset = $this->preset();
        wpstub_add_user(2, 'editor', ['edit_posts', 'publish_posts']);
        $preset['destination']['author'] = 2;
        $result = $this->services->preset_validator()->validate($preset, null, ['action' => 'remove', 'value' => '']);
        $this->assertContains('upload_files', wp_json_encode($result['errors']));
        $this->services->presets()->save($preset);
        $this->queueJson($this->payload());
        $run = $this->runPreset($preset['id']);
        $this->assertSame('failed', $run['status']);
        $this->assertSame(0, MediaStub::$imports);
    }
}
