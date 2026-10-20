<?php

declare(strict_types=1);

namespace PostPressAPI\Sync;

if (!defined('ABSPATH')) exit;

/**
 * Finds existing destination posts for a record.
 *
 * Lookups are scoped to the destination post type and include every status
 * except auto-drafts and revisions — drafts, pending, private, scheduled, and
 * trashed posts all count — so a record never gets a second post just because
 * the first one is not published. Callers decide what to do with trashed matches.
 */
final class PostLocator
{
    /** @var int Matches fetched per lookup; more than one already means "ambiguous". */
    private const LIMIT = 5;

    /**
     * Posts carrying this preset's identity key.
     *
     * @param string $post_type Post type.
     * @param string $key       Identity key.
     *
     * @return list<int>
     */
    public function by_identity(string $post_type, string $key): array
    {
        return $this->query($post_type, PostWriter::META_KEY, $key);
    }

    /**
     * Posts whose meta field (or top-level ACF field, by name) holds the external ID.
     *
     * Not limited to one preset: any post of the type with the value matches.
     *
     * @param string $post_type Post type.
     * @param string $meta_key  Meta key / ACF field name.
     * @param string $value     External ID.
     *
     * @return list<int>
     */
    public function by_field(string $post_type, string $meta_key, string $value): array
    {
        return $this->query($post_type, $meta_key, $value);
    }

    /**
     * Statuses included in lookups.
     *
     * @return list<string>
     */
    public function statuses(): array
    {
        $statuses = array_values(array_diff(array_keys(get_post_stati()), ['auto-draft', 'inherit']));

        return in_array('trash', $statuses, true) ? $statuses : [...$statuses, 'trash'];
    }

    /**
     * Runs a meta lookup.
     *
     * @param string $post_type Post type.
     * @param string $meta_key  Meta key.
     * @param string $value     Value.
     *
     * @return list<int>
     */
    private function query(string $post_type, string $meta_key, string $value): array
    {
        $ids = get_posts([
            'post_type'              => $post_type,
            'post_status'            => $this->statuses(),
            'meta_key'               => $meta_key,
            'meta_value'             => $value,
            'fields'                 => 'ids',
            'posts_per_page'         => self::LIMIT,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'suppress_filters'       => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'cache_results'          => false,
        ]);

        return array_values(array_map('intval', (array) $ids));
    }
}
