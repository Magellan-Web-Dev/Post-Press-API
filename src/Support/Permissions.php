<?php

declare(strict_types=1);

namespace PostPressAPI\Support;

if (!defined('ABSPATH')) exit;

/**
 * Capability checks for configuring and running presets.
 *
 * Every admin screen, form handler, and REST route requires manage_options.
 * Saving or running a preset additionally requires the destination post type's
 * own capabilities, so a preset cannot be used to create or publish content the
 * user could not create or publish by hand.
 *
 * Runs act as an explicit account (see {@see \PostPressAPI\Sync\ImportRunner}):
 * the user who started a manual run, or — for scheduled runs — the user who last
 * saved or enabled the preset. Before any existing post is changed, that
 * account's object-level capabilities for the actual post are checked
 * ({@see edit_problem()}); WordPress's write functions do not check them.
 */
final class Permissions
{
    /** @var string Capability required for every Post Press API screen and action. */
    public const MANAGE = 'manage_options';

    /** @var list<string> Statuses that require the post type's publish capability. */
    private const PUBLISHING_STATUSES = ['publish', 'future', 'private'];

    /**
     * Whether the current user may manage presets.
     *
     * @return bool
     */
    public static function can_manage(): bool
    {
        return current_user_can(self::MANAGE);
    }

    /**
     * Reasons the current user cannot import into a post type.
     *
     * @param string $post_type   Post type.
     * @param bool   $may_publish Whether the preset can publish (default/mapped status publish or private).
     * @param int    $author_id   Default author for new posts.
     *
     * @return list<string>
     */
    public static function current_user_problems(string $post_type, bool $may_publish, int $author_id, bool $imports_images = false): array
    {
        return self::user_problems(get_current_user_id(), $post_type, $may_publish, $author_id, $imports_images, true);
    }

    /**
     * Reasons a user cannot import into a post type.
     *
     * @param int    $user_id        User the import acts as.
     * @param string $post_type      Post type.
     * @param bool   $may_publish    Whether the preset can publish.
     * @param int    $author_id      Default author for new posts.
     * @param bool   $imports_images Whether a mapping downloads images.
     * @param bool   $is_current     Word messages for the current user ("You …").
     *
     * @return list<string>
     */
    public static function user_problems(int $user_id, string $post_type, bool $may_publish, int $author_id, bool $imports_images = false, bool $is_current = false): array
    {
        $object = get_post_type_object($post_type);
        if (!$object) {
            return [sprintf(
                /* translators: %s: post type. */
                __('The post type "%s" is not registered.', 'post-press-api'),
                $post_type
            )];
        }

        $user = $user_id > 0 ? get_userdata($user_id) : false;
        if (!$user instanceof \WP_User || !$user->exists()) {
            return [$is_current
                ? __('You must be logged in to import posts.', 'post-press-api')
                : sprintf(
                    /* translators: %d: user ID. */
                    __('The account this run acts as (user #%d) no longer exists.', 'post-press-api'),
                    $user_id
                )];
        }

        $caps = [
            (string) ($object->cap->create_posts ?? $object->cap->edit_posts),
            (string) $object->cap->edit_posts,
        ];
        if ($may_publish) {
            $caps[] = (string) $object->cap->publish_posts;
        }
        if ($imports_images) {
            $caps[] = 'upload_files';
        }
        if ($author_id > 0 && $author_id !== $user_id) {
            $caps[] = (string) $object->cap->edit_others_posts;
        }

        $problems = [];
        foreach (array_unique($caps) as $cap) {
            if (!user_can($user, $cap)) {
                $problems[] = $is_current
                    ? sprintf(
                        /* translators: 1: capability, 2: post type label. */
                        __('You do not have the "%1$s" capability required to import into %2$s.', 'post-press-api'),
                        $cap,
                        (string) ($object->labels->name ?? $post_type)
                    )
                    : sprintf(
                        /* translators: 1: user display name, 2: user ID, 3: capability, 4: post type label. */
                        __('The account this run acts as (%1$s, user #%2$d) lacks the "%3$s" capability required to import into %4$s.', 'post-press-api'),
                        (string) $user->display_name,
                        $user_id,
                        $cap,
                        (string) ($object->labels->name ?? $post_type)
                    );
            }
        }

        return $problems;
    }

    /**
     * Reasons a preset's default author cannot own imported posts.
     *
     * @param string $post_type   Post type.
     * @param bool   $may_publish Whether the preset can publish.
     * @param int    $author_id   Author user ID.
     *
     * @return list<string>
     */
    public static function author_problems(string $post_type, bool $may_publish, int $author_id, bool $imports_images = false): array
    {
        $object = get_post_type_object($post_type);
        if (!$object) {
            return [sprintf(
                /* translators: %s: post type. */
                __('The post type "%s" is not registered.', 'post-press-api'),
                $post_type
            )];
        }

        $user = $author_id > 0 ? get_userdata($author_id) : false;
        if (!$user instanceof \WP_User || !$user->exists()) {
            return [__('Choose a default author who still exists on this site.', 'post-press-api')];
        }

        $caps = [(string) $object->cap->edit_posts];
        if ($imports_images) {
            $caps[] = 'upload_files';
        }
        if ($may_publish) {
            $caps[] = (string) $object->cap->publish_posts;
        }

        $problems = [];
        foreach ($caps as $cap) {
            if (!user_can($user, $cap)) {
                $problems[] = sprintf(
                    /* translators: 1: user display name, 2: capability. */
                    __('The default author %1$s lacks the "%2$s" capability for this post type.', 'post-press-api'),
                    (string) $user->display_name,
                    $cap
                );
            }
        }

        return $problems;
    }

    /**
     * Why a user may not create a post with this author and status, or null.
     *
     * @param int    $user_id   Account the run acts as.
     * @param string $post_type Post type.
     * @param int    $author_id Author of the new post.
     * @param string $status    Final status of the new post.
     *
     * @return string|null
     */
    public static function create_problem(int $user_id, string $post_type, int $author_id, string $status): ?string
    {
        $object = get_post_type_object($post_type);
        if (!$object) {
            return sprintf(
                /* translators: %s: post type. */
                __('The post type "%s" is not registered.', 'post-press-api'),
                $post_type
            );
        }

        if ($author_id !== $user_id && !user_can($user_id, (string) $object->cap->edit_others_posts)) {
            return sprintf(
                /* translators: 1: user ID, 2: author user ID. */
                __('The account this run acts as (user #%1$d) may not create posts for another author (user #%2$d), so no post was created.', 'post-press-api'),
                $user_id,
                $author_id
            );
        }

        if (in_array($status, self::PUBLISHING_STATUSES, true) && !user_can($user_id, (string) $object->cap->publish_posts)) {
            return sprintf(
                /* translators: 1: user ID, 2: status. */
                __('The account this run acts as (user #%1$d) may not create "%2$s" posts of this type, so no post was created.', 'post-press-api'),
                $user_id,
                $status
            );
        }

        return null;
    }

    /**
     * Why a user may not make these changes to an existing post, or null.
     *
     * Uses WordPress's object-level (meta) capabilities for the actual post, so
     * another user's posts, private posts, and published posts are covered.
     *
     * @param int         $user_id   Account the run acts as.
     * @param int         $post_id   Post being changed (adopted, updated, completed, or restored).
     * @param bool        $restore   Whether the post is restored from the trash.
     * @param int|null    $author_id New author, when the change sets one.
     * @param string|null $status    New status, when the change sets one.
     *
     * @return string|null
     */
    public static function edit_problem(int $user_id, int $post_id, bool $restore, ?int $author_id, ?string $status): ?string
    {
        $post   = get_post($post_id);
        $object = $post ? get_post_type_object((string) $post->post_type) : null;
        if (!$post || !$object) {
            return sprintf(
                /* translators: %d: post ID. */
                __('Post #%d no longer exists.', 'post-press-api'),
                $post_id
            );
        }

        if (!user_can($user_id, 'edit_post', $post_id)) {
            return sprintf(
                /* translators: 1: user ID, 2: post ID. */
                __('The account this run acts as (user #%1$d) is not allowed to edit post #%2$d, so it was left unchanged.', 'post-press-api'),
                $user_id,
                $post_id
            );
        }

        if ($restore && !user_can($user_id, 'delete_post', $post_id)) {
            return sprintf(
                /* translators: 1: user ID, 2: post ID. */
                __('The account this run acts as (user #%1$d) is not allowed to restore post #%2$d from the trash, so it was left unchanged.', 'post-press-api'),
                $user_id,
                $post_id
            );
        }

        if (
            $author_id !== null
            && $author_id !== (int) $post->post_author
            && $author_id !== $user_id
            && !user_can($user_id, (string) $object->cap->edit_others_posts)
        ) {
            return sprintf(
                /* translators: 1: user ID, 2: post ID. */
                __('The account this run acts as (user #%1$d) is not allowed to assign post #%2$d to another author, so it was left unchanged.', 'post-press-api'),
                $user_id,
                $post_id
            );
        }

        if (
            $status !== null
            && $status !== (string) $post->post_status
            && in_array($status, self::PUBLISHING_STATUSES, true)
            && !user_can($user_id, (string) $object->cap->publish_posts)
        ) {
            return sprintf(
                /* translators: 1: user ID, 2: post ID, 3: status. */
                __('The account this run acts as (user #%1$d) is not allowed to set post #%2$d to "%3$s", so it was left unchanged.', 'post-press-api'),
                $user_id,
                $post_id,
                $status
            );
        }

        return null;
    }
}
