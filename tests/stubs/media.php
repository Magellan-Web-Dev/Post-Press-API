<?php
/** WordPress media helpers with real temporary files and mocked HTTP responses. */

declare(strict_types=1);

final class MediaStub
{
    public static array $files = [];
    public static array $attachments = [];
    public static bool $fail = false;
    public static int $imports = 0;
    public static ?array $dimensions = null;

    /** Removes only temporary files created by this test double. */
    public static function reset(): void
    {
        foreach (self::$files as $file) {
            if (is_file($file)) { unlink($file); }
        }
        self::$files = self::$attachments = [];
        self::$fail = false;
        self::$imports = 0;
        self::$dimensions = null;
    }
}

function wp_tempnam($name = '') {
    $file = tempnam(sys_get_temp_dir(), 'ppapi-media-test-');
    MediaStub::$files[] = $file;
    return $file;
}
function wp_safe_remote_get($url, $args = []) {
    $response = wpstub_http($url, $args, true);
    if (is_array($response) && !empty($args['stream'])) {
        file_put_contents($args['filename'], $response['body']);
        $response['body'] = '';
    }
    return $response;
}
function wp_max_upload_size() { return 10485760; }
function get_allowed_mime_types() { return ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp']; }
function wp_get_image_mime($file) {
    $prefix = file_get_contents($file, false, null, 0, 8);
    return $prefix === "\x89PNG\r\n\x1a\n" ? 'image/png' : false;
}
function wp_getimagesize($file) { return MediaStub::$dimensions ?? getimagesize($file); }
function wp_delete_file($file) { if (is_file($file)) { unlink($file); } }
function media_handle_sideload($file, $post_id, $description = null, $post_data = []) {
    if (MediaStub::$fail) { return new WP_Error('upload_error', 'Mocked media failure'); }
    $path = wp_tempnam();
    rename($file['tmp_name'], $path);
    $id = wp_insert_post(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $post_id, 'post_title' => 'Imported image', 'post_mime_type' => 'image/png'] + $post_data, true);
    MediaStub::$attachments[$id] = $path;
    MediaStub::$imports++;
    return $id;
}
function get_attached_file($id) { return MediaStub::$attachments[$id] ?? false; }
function wp_attachment_is_image($id) { return isset(MediaStub::$attachments[$id]) && get_post_type($id) === 'attachment'; }
function get_post_type($id) { return WPStub::$posts[(int) $id]['post_type'] ?? false; }
function wp_get_attachment_url($id) { return isset(MediaStub::$attachments[$id]) ? 'https://example.test/uploads/image-' . $id . '.png' : false; }
function wp_delete_attachment($id, $force = false) {
    if (isset(MediaStub::$attachments[$id])) { wp_delete_file(MediaStub::$attachments[$id]); }
    unset(MediaStub::$attachments[$id], WPStub::$posts[$id], WPStub::$meta[$id]);
    return true;
}
function get_post_thumbnail_id($id) { return (int) get_post_meta($id, '_thumbnail_id', true); }
function set_post_thumbnail($id, $attachment) { return update_post_meta($id, '_thumbnail_id', $attachment); }
function delete_post_thumbnail($id) { return delete_post_meta($id, '_thumbnail_id'); }
