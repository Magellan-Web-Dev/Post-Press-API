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
 */
final class Permissions
{
    /** @var string Capability required for every Post Press API screen and action. */
    public const MANAGE = 'manage_options';

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
        $object = get_post_type_object($post_type);
        if (!$object) {
            return [sprintf(
                /* translators: %s: post type. */
                __('The post type "%s" is not registered.', 'post-press-api'),
                $post_type
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
        if ($author_id > 0 && $author_id !== get_current_user_id()) {
            $caps[] = (string) $object->cap->edit_others_posts;
        }

        $problems = [];
        foreach (array_unique($caps) as $cap) {
            if (!current_user_can($cap)) {
                $problems[] = sprintf(
                    /* translators: 1: capability, 2: post type label. */
                    __('You do not have the "%1$s" capability required to import into %2$s.', 'post-press-api'),
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
     * Used for scheduled runs, which have no logged-in user.
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
}
