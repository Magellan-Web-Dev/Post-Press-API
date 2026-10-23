<?php

declare(strict_types=1);

namespace PostPressAPI\Sync;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Mapping\CoreFields;
use PostPressAPI\Mapping\MappedRecord;
use PostPressAPI\Mapping\MappingEngine;
use PostPressAPI\Mapping\MarkupPolicy;
use PostPressAPI\Presets\PresetSchema;
use PostPressAPI\Support\Clock;
use PostPressAPI\Support\Permissions;

/**
 * Imports one record: identity → mapping → matching → change detection → writes.
 *
 * Duplicate policies:
 *  - skip_existing:  create new identities; leave matched posts alone.
 *  - update_changed: create new identities; update matched posts whose
 *                    fingerprint changed; leave unchanged ones alone (default).
 *  - always_create:  create a post for every record (duplicates intended).
 *
 * Local edits: changes are detected by comparing the new fingerprint with the
 * fingerprint stored at the last successful import, not with the post's current
 * values. A local edit to a mapped field survives until the source value for that
 * record (or the mapping) changes; then every mapped field is written again.
 * Unmapped fields and content are never touched.
 *
 * Partial failures: posts are created as drafts with their identity and a
 * "pending" state in a single insert. The fingerprint and "complete" state are
 * recorded only after every write (and the final status) succeeded, so a failed
 * record is retried — on the same post — by the next run, even if the source
 * did not change.
 *
 * Authorization: before anything is written, the run's account
 * ({@see RunContext::$user_id}) must be allowed to make that change — create a
 * post for the author and status, or edit, restore, reassign, or publish the
 * actual existing post. A denied record fails without any write or download.
 *
 * Dry runs evaluate the same identity, mapping, matching, authorization, and
 * fingerprint logic but never write posts, meta, or tracking state.
 */
final class RecordProcessor
{
    public function __construct(
        private readonly IdentityResolver $identity,
        private readonly MappingEngine $engine,
        private readonly PostLocator $locator,
        private readonly PostWriter $writer,
        private readonly Clock $clock
    ) {}

    /**
     * Processes a record.
     *
     * @param mixed      $record   Decoded record.
     * @param int        $position 1-based position within the run.
     * @param RunContext $context  Run context.
     *
     * @return RecordOutcome
     */
    public function process(mixed $record, int $position, RunContext $context): RecordOutcome
    {
        $preset    = $context->preset;
        $policy    = (string) $preset['sync']['policy'];
        $identity  = $this->identity->resolve($record, $preset);

        if (!$identity['ok'] && $policy !== PresetSchema::POLICY_CREATE) {
            return new RecordOutcome(RecordOutcome::FAILED, $position, '', null, $identity['error']);
        }

        $display = $identity['display'];
        $mapped  = $this->engine->evaluate($record, (array) $preset['mappings'], $context->catalog, $context->site_tz, $context->dry_run, MarkupPolicy::from_preset($preset));

        if ($mapped->has_errors()) {
            return new RecordOutcome(RecordOutcome::FAILED, $position, $display, null, implode(' ', $mapped->errors()), $mapped->details());
        }

        $fingerprint = Fingerprint::compute($mapped, $context->mapping_hash);

        if ($policy === PresetSchema::POLICY_CREATE) {
            return $this->create($position, $display, $identity, $mapped, $fingerprint, $context);
        }

        $found = $this->locate($identity, $context);
        if ($found['error'] !== '') {
            return new RecordOutcome(RecordOutcome::FAILED, $position, $display, null, $found['error'], $mapped->details());
        }

        if ($found['post_id'] === null) {
            return $this->create($position, $display, $identity, $mapped, $fingerprint, $context);
        }

        $post_id  = $found['post_id'];
        $adopt    = $found['adopt'];
        $trashed  = get_post_status($post_id) === 'trash';
        $complete = !$adopt && get_post_meta($post_id, PostWriter::META_STATE, true) === PostWriter::STATE_COMPLETE;

        if ($policy === PresetSchema::POLICY_SKIP && ($complete || $adopt)) {
            return new RecordOutcome(RecordOutcome::SKIPPED, $position, $display, $post_id, sprintf(
                /* translators: %d: post ID. */
                __('Post #%d already exists; the "skip existing" policy leaves it unchanged.', 'post-press-api'),
                $post_id
            ), $mapped->details());
        }

        if ($trashed && $preset['sync']['trashed'] !== PresetSchema::TRASH_RESTORE) {
            return new RecordOutcome(RecordOutcome::SKIPPED, $position, $display, $post_id, sprintf(
                /* translators: %d: post ID. */
                __('Post #%d is in the trash, so it was left alone and no new post was created.', 'post-press-api'),
                $post_id
            ), $mapped->details());
        }

        if (
            $complete
            && !$trashed
            && !$context->force
            && hash_equals((string) get_post_meta($post_id, PostWriter::META_FINGERPRINT, true), $fingerprint)
        ) {
            return new RecordOutcome(RecordOutcome::UNCHANGED, $position, $display, $post_id, '', $mapped->details());
        }

        return $this->update($position, $display, $post_id, $adopt, $trashed, $complete, $identity, $mapped, $fingerprint, $context);
    }

    /**
     * Creates a post for a record.
     *
     * @param array{ok: bool, external_id: string, display: string, key: string, error: string} $identity Identity.
     */
    private function create(int $position, string $display, array $identity, MappedRecord $mapped, string $fingerprint, RunContext $context): RecordOutcome
    {
        $preset  = $context->preset;
        $core    = $mapped->core_values();
        $author  = isset($core[CoreFields::AUTHOR]) ? (int) $core[CoreFields::AUTHOR] : (int) $preset['destination']['author'];
        $status  = isset($core[CoreFields::STATUS]) ? (string) $core[CoreFields::STATUS] : (string) $preset['destination']['status'];
        $post_id = null;

        $denied = Permissions::create_problem($context->user_id, (string) $preset['destination']['post_type'], $author, $status);
        if ($denied !== null) {
            return new RecordOutcome(RecordOutcome::FAILED, $position, $display, null, $denied, $mapped->details());
        }

        if ($context->dry_run) {
            return new RecordOutcome(RecordOutcome::CREATED, $position, $display, null, __('Would create a new post.', 'post-press-api'), $mapped->details());
        }

        try {
            $post_id = $this->writer->create((string) $preset['destination']['post_type'], $core, $author, $this->tracking($preset, $identity));
            $this->writer->apply_fields($post_id, $mapped);
            $this->writer->set_status($post_id, $status);
            $this->writer->mark_complete($post_id, $fingerprint, $context->run_id, $this->clock->now());
        } catch (WriteError $e) {
            $message = $e->getMessage();
            if ($post_id !== null) {
                $message .= ' ' . sprintf(
                    /* translators: %d: post ID. */
                    __('Post #%d was kept as an incomplete draft and will be completed by the next run.', 'post-press-api'),
                    $post_id
                );
            }
            return new RecordOutcome(RecordOutcome::FAILED, $position, $display, $post_id, $message);
        }

        /**
         * Fires after a record was imported into a post.
         *
         * @param int    $post_id     Post ID.
         * @param string $action      "created" or "updated".
         * @param string $preset_id   Preset ID.
         * @param string $external_id External ID display string.
         */
        do_action('ppapi_record_imported', $post_id, RecordOutcome::CREATED, (string) $preset['id'], $display);

        return new RecordOutcome(RecordOutcome::CREATED, $position, $display, $post_id);
    }

    /**
     * Updates an existing post.
     *
     * @param array{ok: bool, external_id: string, display: string, key: string, error: string} $identity Identity.
     */
    private function update(int $position, string $display, int $post_id, bool $adopt, bool $restore, bool $complete, array $identity, MappedRecord $mapped, string $fingerprint, RunContext $context): RecordOutcome
    {
        // Created by an earlier attempt that failed before its first successful completion.
        $finishing_create = !$adopt && !$complete && !$restore
            && (string) get_post_meta($post_id, PostWriter::META_FINGERPRINT, true) === '';

        // A mapped status always applies. A post whose creation never completed
        // (still a draft from the first attempt) also gets the preset's default status.
        $core   = $mapped->core_values();
        $status = $core[CoreFields::STATUS] ?? ($finishing_create ? (string) $context->preset['destination']['status'] : null);

        $denied = Permissions::edit_problem(
            $context->user_id,
            $post_id,
            $restore,
            isset($core[CoreFields::AUTHOR]) ? (int) $core[CoreFields::AUTHOR] : null,
            $status === null ? null : (string) $status
        );
        if ($denied !== null) {
            return new RecordOutcome(RecordOutcome::FAILED, $position, $display, $post_id, $denied, $mapped->details());
        }

        if ($context->dry_run) {
            $message = match (true) {
                $restore   => __('Would restore post #%d from the trash (as a draft) and update it.', 'post-press-api'),
                $adopt     => __('Would link existing post #%d to this record and update its mapped fields.', 'post-press-api'),
                !$complete => __('Would finish the incomplete import of post #%d.', 'post-press-api'),
                default    => __('Would update the mapped fields of post #%d.', 'post-press-api'),
            };

            return new RecordOutcome(RecordOutcome::UPDATED, $position, $display, $post_id, sprintf($message, $post_id), $mapped->details());
        }

        try {
            if ($restore) {
                $this->writer->untrash($post_id);
            }

            $this->writer->mark_pending($post_id, $adopt ? $this->tracking($context->preset, $identity) : []);

            $this->writer->update_core($post_id, $core);
            $this->writer->apply_fields($post_id, $mapped);

            if ($status !== null) {
                $this->writer->set_status($post_id, (string) $status);
            }
            $this->writer->mark_complete($post_id, $fingerprint, $context->run_id, $this->clock->now());
        } catch (WriteError $e) {
            return new RecordOutcome(RecordOutcome::FAILED, $position, $display, $post_id, $e->getMessage() . ' ' . __('The post stays marked as incomplete and will be retried by the next run.', 'post-press-api'));
        }

        /** This action is documented in src/Sync/RecordProcessor.php */
        do_action('ppapi_record_imported', $post_id, RecordOutcome::UPDATED, (string) $context->preset['id'], $display);

        return new RecordOutcome(RecordOutcome::UPDATED, $position, $display, $post_id, $restore ? __('Restored from the trash as a draft.', 'post-press-api') : '');
    }

    /**
     * Finds the post for an identity.
     *
     * @param array{ok: bool, external_id: string, display: string, key: string, error: string} $identity Identity.
     * @param RunContext                                                                         $context  Context.
     *
     * @return array{post_id: int|null, adopt: bool, error: string}
     */
    private function locate(array $identity, RunContext $context): array
    {
        $preset    = $context->preset;
        $post_type = (string) $preset['destination']['post_type'];
        $ids       = $this->locator->by_identity($post_type, $identity['key']);

        if (count($ids) > 1) {
            return ['post_id' => null, 'adopt' => false, 'error' => sprintf(
                /* translators: %s: post IDs. */
                __('Several posts (%s) carry this record\'s identity, so the record was not imported rather than guessing. Remove or unlink the duplicates.', 'post-press-api'),
                '#' . implode(', #', $ids)
            )];
        }

        if ($ids !== []) {
            return ['post_id' => $ids[0], 'adopt' => false, 'error' => ''];
        }

        $match = $preset['sync']['match'];
        if ($match['type'] === PresetSchema::MATCH_NONE || $preset['response']['identity']['mode'] !== PresetSchema::IDENTITY_PATH) {
            return ['post_id' => null, 'adopt' => false, 'error' => ''];
        }

        $meta_key = (string) $match['key'];
        if ($match['type'] === PresetSchema::MATCH_ACF) {
            $field = $context->catalog->acf()->available() ? $context->catalog->acf()->top_level_field($post_type, $meta_key) : null;
            if ($field === null) {
                return ['post_id' => null, 'adopt' => false, 'error' => sprintf(
                    /* translators: %s: ACF field key. */
                    __('The ACF field used to match existing posts (%s) is no longer available.', 'post-press-api'),
                    $meta_key
                )];
            }
            $meta_key = (string) $field['name'];
        }

        $ids = $this->locator->by_field($post_type, $meta_key, $identity['external_id']);
        if ($ids === []) {
            return ['post_id' => null, 'adopt' => false, 'error' => ''];
        }

        if (count($ids) > 1) {
            return ['post_id' => null, 'adopt' => false, 'error' => sprintf(
                /* translators: 1: post IDs, 2: field name. */
                __('Several existing posts (%1$s) have this external ID in "%2$s", so the record was not imported rather than guessing.', 'post-press-api'),
                '#' . implode(', #', $ids),
                $meta_key
            )];
        }

        $linked = (string) get_post_meta($ids[0], PostWriter::META_KEY, true);
        if ($linked !== '' && !hash_equals($linked, $identity['key'])) {
            return ['post_id' => null, 'adopt' => false, 'error' => sprintf(
                /* translators: %d: post ID. */
                __('Post #%d has this external ID but is already linked to a different preset or record.', 'post-press-api'),
                $ids[0]
            )];
        }

        return ['post_id' => $ids[0], 'adopt' => true, 'error' => ''];
    }

    /**
     * Identity meta written to posts.
     *
     * @param array<string, mixed>                                                               $preset   Preset.
     * @param array{ok: bool, external_id: string, display: string, key: string, error: string} $identity Identity.
     *
     * @return array<string, string>
     */
    private function tracking(array $preset, array $identity): array
    {
        $tracking = [PostWriter::META_PRESET => (string) $preset['id']];

        if ($identity['ok']) {
            $tracking[PostWriter::META_KEY]         = $identity['key'];
            $tracking[PostWriter::META_EXTERNAL_ID] = $identity['external_id'];
        }

        return $tracking;
    }
}
