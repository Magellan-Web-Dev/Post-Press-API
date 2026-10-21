<?php

declare(strict_types=1);

namespace PostPressAPI\Sync;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Integration\ACF\AcfWriter;
use PostPressAPI\Mapping\CoreFields;
use PostPressAPI\Mapping\Destination;
use PostPressAPI\Mapping\FieldWrite;
use PostPressAPI\Mapping\MappedRecord;

/**
 * Applies mapped values to posts and maintains tracking metadata.
 *
 * Tracking meta (all prefixed _ppapi_, never writable through mappings):
 *   _ppapi_preset       preset ID that manages the post
 *   _ppapi_key          identity key (sha256 of preset ID, post type, external ID)
 *   _ppapi_external_id  external ID as a string
 *   _ppapi_state        "pending" while a write is in progress or after a failure,
 *                       "complete" once every required write succeeded
 *   _ppapi_fingerprint  fingerprint of the last complete write
 *   _ppapi_synced_at    Unix time of the last complete write
 *   _ppapi_run          ID of the run that last completed the post
 *
 * Every write is verified: update_post_meta() returning false is only accepted
 * when the stored value already equals the intended one.
 */
final class PostWriter
{
    public const META_PRESET      = '_ppapi_preset';
    public const META_KEY         = '_ppapi_key';
    public const META_EXTERNAL_ID = '_ppapi_external_id';
    public const META_STATE       = '_ppapi_state';
    public const META_FINGERPRINT = '_ppapi_fingerprint';
    public const META_SYNCED_AT   = '_ppapi_synced_at';
    public const META_RUN         = '_ppapi_run';

    public const STATE_PENDING  = 'pending';
    public const STATE_COMPLETE = 'complete';

    public function __construct(private readonly AcfWriter $acf, private readonly \PostPressAPI\Media\ImageImporter $images = new \PostPressAPI\Media\ImageImporter())
    {
    }

    /**
     * Creates a draft post carrying the identity meta and a pending state.
     *
     * The identity is stored in the same insert, so if any later write fails the
     * next run finds this post and completes it instead of creating another one.
     *
     * @param string               $post_type Post type.
     * @param array<string, mixed> $core      Core field values (post_status is ignored here).
     * @param int                  $author    Author user ID.
     * @param array<string, string> $tracking Identity meta.
     *
     * @return int Post ID.
     *
     * @throws WriteError
     */
    public function create(string $post_type, array $core, int $author, array $tracking): int
    {
        unset($core[CoreFields::STATUS]);

        $postarr = [
            'post_type'   => $post_type,
            'post_status' => 'draft',
            'post_author' => $author,
        ] + $this->core_fields($core);

        $postarr['meta_input'] = $tracking + [self::META_STATE => self::STATE_PENDING];

        // Records may map only meta/ACF fields; allow posts without title/content/excerpt.
        add_filter('wp_insert_post_empty_content', '__return_false', PHP_INT_MAX);
        try {
            $post_id = wp_insert_post(wp_slash($postarr), true);
        } finally {
            remove_filter('wp_insert_post_empty_content', '__return_false', PHP_INT_MAX);
        }

        if (is_wp_error($post_id) || !$post_id) {
            throw new WriteError(sprintf(
                /* translators: %s: error. */
                __('The post could not be created: %s', 'post-press-api'),
                is_wp_error($post_id) ? $post_id->get_error_message() : __('unknown error', 'post-press-api')
            ));
        }

        $post_id = (int) $post_id;
        if (get_post_meta($post_id, self::META_KEY, true) !== ($tracking[self::META_KEY] ?? '')) {
            throw new WriteError(__('The post was created but its identity could not be stored.', 'post-press-api'));
        }

        return $post_id;
    }

    /**
     * Marks an existing post as being written (and links it to the identity).
     *
     * @param int                   $post_id  Post ID.
     * @param array<string, string> $tracking Identity meta.
     *
     * @return void
     */
    public function mark_pending(int $post_id, array $tracking): void
    {
        foreach ($tracking as $key => $value) {
            $this->write_meta($post_id, $key, $value);
        }
        $this->write_meta($post_id, self::META_STATE, self::STATE_PENDING);
    }

    /**
     * Updates core fields on an existing post (status excluded; see set_status()).
     *
     * @param int                  $post_id Post ID.
     * @param array<string, mixed> $core    Core values.
     *
     * @return void
     */
    public function update_core(int $post_id, array $core): void
    {
        unset($core[CoreFields::STATUS]);
        if ($core === []) {
            return;
        }

        $postarr = ['ID' => $post_id] + $this->core_fields($core);
        if (isset($core[CoreFields::DATE])) {
            $postarr['edit_date'] = true;
        }

        add_filter('wp_insert_post_empty_content', '__return_false', PHP_INT_MAX);
        try {
            $result = wp_update_post(wp_slash($postarr), true);
        } finally {
            remove_filter('wp_insert_post_empty_content', '__return_false', PHP_INT_MAX);
        }

        if (is_wp_error($result) || !$result) {
            throw new WriteError(sprintf(
                /* translators: %s: error. */
                __('The post fields could not be updated: %s', 'post-press-api'),
                is_wp_error($result) ? $result->get_error_message() : __('unknown error', 'post-press-api')
            ));
        }
    }

    /**
     * Writes mapped post meta and ACF fields.
     *
     * @param int          $post_id Post ID.
     * @param MappedRecord $mapped  Mapped record.
     *
     * @return void
     *
     * @throws WriteError On the first failed write.
     */
    public function apply_fields(int $post_id, MappedRecord $mapped): void
    {
        // Resolve every download before replacing any image field or ACF container.
        $mapped = $this->images->resolve($mapped, $post_id);
        foreach ($mapped->writes_for(Destination::CORE) as $write) {
            if ($write->destination->key !== CoreFields::IMAGE) {
                continue;
            }
            $id = $write->op === FieldWrite::CLEAR ? 0 : (int) $write->value;
            $previous = (int) get_post_thumbnail_id($post_id);
            if ($id === 0) {
                delete_post_thumbnail($post_id);
            } else {
                set_post_thumbnail($post_id, $id);
            }
            if ((int) get_post_thumbnail_id($post_id) !== $id) {
                if ($previous > 0) {
                    set_post_thumbnail($post_id, $previous);
                }
                throw new WriteError(__('The featured image could not be saved.', 'post-press-api'));
            }
        }
        foreach ($mapped->writes_for(Destination::META) as $write) {
            if ($write->op === FieldWrite::CLEAR) {
                $this->clear_meta($post_id, $write->destination->key);
            } else {
                $this->write_meta($post_id, $write->destination->key, $write->value);
            }
        }

        foreach ($mapped->writes_for(Destination::ACF) as $write) {
            if ($write->op === FieldWrite::CLEAR) {
                $this->acf->clear($post_id, (array) $write->destination->acf_field);
            } else {
                $this->acf->write($post_id, (array) $write->destination->acf_field, $write->value);
            }
        }
    }

    /**
     * Sets the post status.
     *
     * @param int    $post_id Post ID.
     * @param string $status  Allowed status.
     *
     * @return void
     */
    public function set_status(int $post_id, string $status): void
    {
        if (get_post_status($post_id) === $status) {
            return;
        }

        $result = wp_update_post(['ID' => $post_id, 'post_status' => $status], true);
        if (is_wp_error($result) || !$result) {
            throw new WriteError(sprintf(
                /* translators: %s: status. */
                __('The post status could not be set to "%s".', 'post-press-api'),
                $status
            ));
        }
    }

    /**
     * Restores a trashed post (WordPress restores it as a draft).
     *
     * @param int $post_id Post ID.
     *
     * @return void
     */
    public function untrash(int $post_id): void
    {
        if (!wp_untrash_post($post_id)) {
            throw new WriteError(__('The trashed post could not be restored.', 'post-press-api'));
        }
    }

    /**
     * Records a successful write. Called only after every required write succeeded.
     *
     * @param int    $post_id     Post ID.
     * @param string $fingerprint Fingerprint.
     * @param string $run_id      Run ID.
     * @param int    $time        Timestamp.
     *
     * @return void
     */
    public function mark_complete(int $post_id, string $fingerprint, string $run_id, int $time): void
    {
        $this->write_meta($post_id, self::META_FINGERPRINT, $fingerprint);
        $this->write_meta($post_id, self::META_SYNCED_AT, (string) $time);
        $this->write_meta($post_id, self::META_RUN, $run_id);
        $this->write_meta($post_id, self::META_STATE, self::STATE_COMPLETE);
    }

    /**
     * Writes and verifies one meta value.
     *
     * @param int    $post_id Post ID.
     * @param string $key     Meta key.
     * @param mixed  $value   Value.
     *
     * @return void
     */
    private function write_meta(int $post_id, string $key, mixed $value): void
    {
        $result = update_post_meta($post_id, $key, wp_slash($value));
        if ($result !== false) {
            return;
        }

        // false also means "unchanged": compare with what is stored.
        if (!metadata_exists('post', $post_id, $key) || !ValueComparator::equivalent(get_post_meta($post_id, $key, true), $value)) {
            throw new WriteError(sprintf(
                /* translators: %s: meta key. */
                __('The meta field "%s" could not be saved.', 'post-press-api'),
                $key
            ));
        }
    }

    /**
     * Deletes and verifies one meta key.
     *
     * @param int    $post_id Post ID.
     * @param string $key     Meta key.
     *
     * @return void
     */
    private function clear_meta(int $post_id, string $key): void
    {
        delete_post_meta($post_id, $key);

        if (metadata_exists('post', $post_id, $key)) {
            throw new WriteError(sprintf(
                /* translators: %s: meta key. */
                __('The meta field "%s" could not be cleared.', 'post-press-api'),
                $key
            ));
        }
    }

    /**
     * Converts normalized core values into wp_insert_post() fields.
     *
     * @param array<string, mixed> $core Core values.
     *
     * @return array<string, mixed>
     */
    private function core_fields(array $core): array
    {
        $fields = [];
        foreach ($core as $key => $value) {
            $fields[$key] = $value;
        }

        if (isset($core[CoreFields::DATE])) {
            $fields['post_date_gmt'] = get_gmt_from_date((string) $core[CoreFields::DATE]);
        }

        return $fields;
    }
}
