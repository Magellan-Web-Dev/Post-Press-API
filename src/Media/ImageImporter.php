<?php

declare(strict_types=1);

namespace PostPressAPI\Media;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Http\Url;
use PostPressAPI\Mapping\FieldWrite;
use PostPressAPI\Mapping\ImageReference;
use PostPressAPI\Mapping\MappedRecord;
use PostPressAPI\Support\Clock;
use PostPressAPI\Sync\RunLock;
use PostPressAPI\Sync\WriteError;

/**
 * Downloads and reuses images after duplicate/change detection chooses a write.
 *
 * An attachment's protected source hash enables reuse across records and presets
 * without storing signed URLs. A per-URL lock prevents competing downloads.
 * HTTP and file validation are bounded; temporary files are always removed.
 */
final class ImageImporter
{
    /** @var string Attachment meta holding a SHA-256 hash of the complete source URL. */
    public const SOURCE_META = '_ppapi_image_source_hash';
    /** @var int Maximum download size, before the site's own upload limit. */
    public const MAX_BYTES = 10485760;
    /** @var int Maximum decoded image area. */
    public const MAX_PIXELS = 40000000;
    /** @var int Shared image-work budget for all fields in one record. */
    public const RECORD_SECONDS = 12;

    /**
     * @param RunLock $locks Atomic locks, using image-specific keys.
     */
    public function __construct(private readonly RunLock $locks = new RunLock(new Clock())) {}

    /**
     * Resolves all image references before any image field is replaced.
     *
     * @param MappedRecord $mapped  Evaluated writes, possibly containing image references.
     * @param int          $post_id Destination post.
     *
     * @return MappedRecord Writes containing local IDs/URLs; the original stays unchanged.
     *
     * @throws WriteError When a required image cannot be resolved.
     */
    public function resolve(MappedRecord $mapped, int $post_id): MappedRecord
    {
        $result = new MappedRecord();
        $deadline = microtime(true) + self::RECORD_SECONDS;
        foreach ($mapped->writes() as $write) {
            $result->add_write(new FieldWrite($write->destination, $write->op, $this->value($write->value, $post_id, $deadline)));
        }
        return $result;
    }

    /**
     * Resolves an image reference or recursively visits an ACF container value.
     *
     * @param mixed $value    Normalized mapped value.
     * @param int   $post_id  Destination post.
     * @param float $deadline Wall-clock deadline shared by the record's images.
     *
     * @return mixed Value suitable for WordPress/ACF storage.
     *
     * @throws WriteError
     */
    private function value(mixed $value, int $post_id, float $deadline): mixed
    {
        if ($value instanceof ImageReference) {
            $id = $this->import($value->url, $post_id, $deadline);
            if ($value->output === 'id') {
                return $id;
            }
            $url = wp_get_attachment_url($id);
            if (!$url) {
                throw new WriteError(__('The imported image has no media URL.', 'post-press-api'));
            }
            return $url;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->value($item, $post_id, $deadline);
            }
        }
        return $value;
    }

    /**
     * Finds a reusable attachment whose local file still exists.
     *
     * @param string $hash SHA-256 source URL hash.
     *
     * @return int|null Attachment ID, or null when a fresh download is needed.
     */
    private function existing(string $hash): ?int
    {
        $ids = get_posts([
            'post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids',
            'posts_per_page' => 10, 'orderby' => 'ID', 'order' => 'ASC',
            'meta_key' => self::SOURCE_META, 'meta_value' => $hash,
            'no_found_rows' => true, 'suppress_filters' => true,
        ]);
        foreach ($ids as $id) {
            $file = get_attached_file((int) $id);
            if (wp_attachment_is_image((int) $id) && is_string($file) && is_file($file)) {
                return (int) $id;
            }
        }
        return null;
    }

    /**
     * Reuses or downloads one image under a per-source lock.
     *
     * @param string     $url      Source URL; API credentials are never forwarded.
     * @param int        $post_id  Post associated with a new attachment.
     * @param float|null $deadline Shared wall-clock deadline; defaults to RECORD_SECONDS.
     *
     * @return int Verified local image attachment ID.
     *
     * @throws WriteError For unsafe URLs, download/validation failures, or contention.
     */
    public function import(string $url, int $post_id, ?float $deadline = null): int
    {
        $deadline ??= microtime(true) + self::RECORD_SECONDS;
        // Query strings are significant (signed URLs and image transformations).
        $hash = hash('sha256', $url);
        $lock_key = 'image_' . $hash;
        $token = $this->locks->acquire($lock_key);
        if ($token === null) {
            throw new WriteError(__('This image is being imported by another run. The record will be retried on the next run.', 'post-press-api'));
        }
        $temporary = null;
        try {
            $existing = $this->existing($hash);
            if ($existing !== null) {
                return $existing;
            }
            $this->includes();
            $temporary = wp_tempnam('ppapi-image');
            if (!$temporary) {
                throw new WriteError(__('Could not create a temporary image file.', 'post-press-api'));
            }
            $current = $url;
            for ($hop = 0; ; $hop++) {
                $remaining = $deadline - microtime(true);
                if ($remaining < 1) {
                    throw new WriteError(__('The image processing time limit was reached. Saved images will be reused on the next run.', 'post-press-api'));
                }
                if (!Url::is_valid_endpoint($current) || !wp_http_validate_url($current)) {
                    throw new WriteError(__('The image URL or its redirect is not a public HTTP(S) address.', 'post-press-api'));
                }
                $response = wp_safe_remote_get($current, [
                    'timeout' => min(10, $remaining), 'redirection' => 0, 'sslverify' => true,
                    'stream' => true, 'filename' => $temporary, 'limit_response_size' => self::MAX_BYTES + 1,
                    'headers' => ['Accept' => 'image/*'], 'cookies' => [],
                ]);
                if (is_wp_error($response)) {
                    // Transport errors can contain signed URLs: do not echo their raw text.
                    throw new WriteError(__('The image download failed. Check the URL and try the run again.', 'post-press-api'));
                }
                $status = (int) wp_remote_retrieve_response_code($response);
                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    $next = Url::resolve($current, (string) wp_remote_retrieve_header($response, 'location'));
                    if ($hop >= 3 || $next === null) {
                        throw new WriteError(__('The image redirected too many times or returned an invalid redirect.', 'post-press-api'));
                    }
                    $current = $next;
                    continue;
                }
                if ($status !== 200) {
                    throw new WriteError(sprintf(__('The image server returned HTTP %d.', 'post-press-api'), $status));
                }
                break;
            }
            clearstatcache(true, $temporary);
            $size = filesize($temporary);
            if (!$size || $size > min(self::MAX_BYTES, wp_max_upload_size())) {
                throw new WriteError(__('The image is empty or exceeds the upload size limit (at most 10 MB).', 'post-press-api'));
            }
            $mime = wp_get_image_mime($temporary);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/avif' => 'avif'];
            if (!isset($extensions[$mime]) || !in_array($mime, get_allowed_mime_types(), true)) {
                throw new WriteError(__('The response is not a supported image. Use a WordPress-allowed JPEG, PNG, GIF, WebP, or AVIF image.', 'post-press-api'));
            }
            $dimensions = wp_getimagesize($temporary);
            if (!$dimensions || $dimensions[0] * $dimensions[1] > self::MAX_PIXELS) {
                throw new WriteError(__('The image is invalid or exceeds the 40 megapixel processing limit.', 'post-press-api'));
            }
            // Name from the URL hash and detected MIME; extensionless URLs are supported.
            $file = ['name' => 'import-' . substr($hash, 0, 20) . '.' . $extensions[$mime], 'tmp_name' => $temporary];
            $id = media_handle_sideload($file, $post_id, null, ['meta_input' => [self::SOURCE_META => $hash]]);
            if (is_wp_error($id)) {
                throw new WriteError(__('WordPress could not save the downloaded image to Media Library.', 'post-press-api'));
            }
            if (!wp_attachment_is_image((int) $id) || get_post_meta((int) $id, self::SOURCE_META, true) !== $hash) {
                wp_delete_attachment((int) $id, true);
                throw new WriteError(__('The image attachment could not be verified.', 'post-press-api'));
            }
            return (int) $id;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                wp_delete_file($temporary);
            }
            $this->locks->release($lock_key, $token);
        }
    }

    /**
     * Loads WordPress media helpers in REST and cron requests as well as wp-admin.
     *
     * @return void
     */
    private function includes(): void
    {
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
    }
}
