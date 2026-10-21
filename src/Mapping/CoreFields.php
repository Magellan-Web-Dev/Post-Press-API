<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * Allowlist and value normalization for core post fields.
 *
 * Only the fields listed here can be written. IDs, post type, parent, GUID,
 * menu order, comment status, and every other internal property are never
 * accepted from a mapping or a payload.
 */
final class CoreFields
{
    public const TITLE   = 'post_title';
    public const CONTENT = 'post_content';
    public const EXCERPT = 'post_excerpt';
    public const SLUG    = 'post_name';
    public const DATE    = 'post_date';
    public const STATUS  = 'post_status';
    public const AUTHOR  = 'post_author';
    public const IMAGE   = 'featured_image';

    /** @var list<string> Default statuses an import may set. */
    private const DEFAULT_STATUSES = ['draft', 'pending', 'private', 'publish'];

    /**
     * Field definitions for the editor.
     *
     * @return array<string, array{label: string, clearable: bool, help: string}>
     */
    public function definitions(): array
    {
        return [
            self::IMAGE => ['label' => __('Featured image', 'post-press-api'), 'clearable' => true, 'help' => __('A local image attachment ID, or an image URL with Download image URL to Media Library enabled.', 'post-press-api')],
            self::TITLE   => ['label' => __('Title', 'post-press-api'), 'clearable' => true, 'help' => __('Plain text; HTML tags are removed.', 'post-press-api')],
            self::CONTENT => ['label' => __('Content', 'post-press-api'), 'clearable' => true, 'help' => __('Rich text; HTML allowed in posts is kept.', 'post-press-api')],
            self::EXCERPT => ['label' => __('Excerpt', 'post-press-api'), 'clearable' => true, 'help' => __('Rich text; HTML allowed in posts is kept.', 'post-press-api')],
            self::SLUG    => ['label' => __('Slug', 'post-press-api'), 'clearable' => false, 'help' => __('Sanitized with sanitize_title(); WordPress keeps slugs unique.', 'post-press-api')],
            self::DATE    => ['label' => __('Publish date', 'post-press-api'), 'clearable' => false, 'help' => __('Parsed as a date/time; values without an offset use the site time zone unless a Date/time transformation sets one.', 'post-press-api')],
            self::STATUS  => ['label' => __('Status', 'post-press-api'), 'clearable' => false, 'help' => __('Requires a constant or a Value map that converts source values to draft, pending, private, or publish.', 'post-press-api')],
            self::AUTHOR  => ['label' => __('Author', 'post-press-api'), 'clearable' => false, 'help' => __('A user ID, login, or email of a user who can edit this post type.', 'post-press-api')],
        ];
    }

    /**
     * Whether a field name is on the allowlist.
     *
     * @param string $key Field name.
     *
     * @return bool
     */
    public function is_allowed(string $key): bool
    {
        return array_key_exists($key, $this->definitions());
    }

    /**
     * Post statuses imports may set.
     *
     * @return list<string>
     */
    public function allowed_statuses(): array
    {
        /**
         * Filters the post statuses Post Press API may assign.
         *
         * @param list<string> $statuses Default draft, pending, private, publish.
         */
        $statuses = (array) apply_filters('ppapi_allowed_post_statuses', self::DEFAULT_STATUSES);
        $statuses = array_values(array_filter($statuses, static function ($status): bool {
            return is_string($status) && $status !== 'trash' && $status !== 'auto-draft' && $status !== 'inherit'
                && (!function_exists('get_post_status_object') || get_post_status_object($status) !== null);
        }));

        return $statuses === [] ? ['draft'] : $statuses;
    }

    /**
     * Normalizes a value for a core field.
     *
     * @param string        $key       Field name.
     * @param mixed         $value     Transformed value.
     * @param \DateTimeZone $site_tz   Site time zone.
     * @param string        $post_type Destination post type.
     *
     * @return mixed
     *
     * @throws MappingError When the value is not valid for the field.
     */
    public function normalize(string $key, mixed $value, \DateTimeZone $site_tz, string $post_type): mixed
    {
        switch ($key) {
            case self::IMAGE:
                return ImageReference::attachment_id($value);
            case self::TITLE:
                return sanitize_text_field(Transformer::stringify($value, $site_tz));

            case self::CONTENT:
            case self::EXCERPT:
                return wp_kses_post(Transformer::stringify($value, $site_tz));

            case self::SLUG:
                $slug = sanitize_title(Transformer::stringify($value, $site_tz));
                if ($slug === '') {
                    throw new MappingError(__('The slug is empty after sanitizing.', 'post-press-api'));
                }
                return $slug;

            case self::DATE:
                $date = DateParser::parse($value, null, $site_tz);
                if (!$date->has_date) {
                    throw new MappingError(__('The publish date has no calendar date.', 'post-press-api'));
                }
                // post_date is always site-local; date-only values are not shifted.
                if (!$date->has_time) {
                    return $date->datetime->format('Y-m-d') . ' 00:00:00';
                }
                return $date->datetime->setTimezone($site_tz)->format('Y-m-d H:i:s');

            case self::STATUS:
                $status = is_string($value) ? trim($value) : null;
                if ($status === null || !in_array($status, $this->allowed_statuses(), true)) {
                    throw new MappingError(sprintf(
                        /* translators: 1: value preview, 2: allowed statuses. */
                        __('"%1$s" is not an allowed post status (%2$s). Use a Value map to convert source statuses.', 'post-press-api'),
                        ValueInspector::preview($value, 40),
                        implode(', ', $this->allowed_statuses())
                    ));
                }
                return $status;

            case self::AUTHOR:
                return $this->author_id($value, $post_type);
        }

        throw new MappingError(sprintf(
            /* translators: %s: field name. */
            __('"%s" is not a supported post field.', 'post-press-api'),
            $key
        ));
    }

    /**
     * Resolves an author reference to a user who can edit the post type.
     *
     * @param mixed  $value     User ID, login, or email.
     * @param string $post_type Post type.
     *
     * @return int
     */
    public function author_id(mixed $value, string $post_type): int
    {
        $user = null;

        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            $user = get_userdata((int) $value);
        } elseif (is_string($value) && trim($value) !== '') {
            $text = trim($value);
            $user = str_contains($text, '@') ? get_user_by('email', $text) : get_user_by('login', $text);
        }

        if (!$user instanceof \WP_User || !$user->exists()) {
            throw new MappingError(sprintf(
                /* translators: %s: value preview. */
                __('No user matches the author "%s".', 'post-press-api'),
                ValueInspector::preview($value, 60)
            ));
        }

        $object = get_post_type_object($post_type);
        $cap    = $object && isset($object->cap->edit_posts) ? (string) $object->cap->edit_posts : 'edit_posts';

        if (!user_can($user, $cap)) {
            throw new MappingError(sprintf(
                /* translators: %d: user ID. */
                __('User #%d cannot edit this post type, so they cannot be the author.', 'post-press-api'),
                (int) $user->ID
            ));
        }

        return (int) $user->ID;
    }
}
