<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Integration\ACF\AcfFields;

/**
 * Lists the post types presets may import into and builds destination catalogs.
 *
 * Only registered post types with an admin UI are offered. WordPress internals,
 * ACF/Elementor configuration storage, and other plugin-internal storage types
 * are excluded; the list can be adjusted with the ppapi_destination_post_types filter.
 */
final class DestinationRegistry
{
    /** @var list<string> Post types that store configuration rather than content. */
    private const EXCLUDED = [
        'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache',
        'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
        'wp_font_family', 'wp_font_face', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy',
        'acf-ui-options-page', 'elementor_library', 'elementor_snippet', 'elementor_font', 'elementor_icons',
        'e-landing-page', 'e-floating-buttons', 'wpcode', 'shop_order', 'shop_order_refund', 'shop_coupon',
    ];

    /** @var array<string, DestinationCatalog> */
    private array $catalogs = [];

    public function __construct(
        private readonly CoreFields $core,
        private readonly MetaKeyPolicy $meta_policy,
        private readonly AcfFields $acf
    ) {}

    public function core(): CoreFields
    {
        return $this->core;
    }

    public function acf(): AcfFields
    {
        return $this->acf;
    }

    /**
     * Destination post types: name → label.
     *
     * @return array<string, string>
     */
    public function post_types(): array
    {
        $types = [];

        foreach (get_post_types(['show_ui' => true], 'objects') as $name => $object) {
            if (in_array($name, self::EXCLUDED, true) || str_starts_with((string) $name, 'ppapi')) {
                continue;
            }
            $label        = isset($object->labels->singular_name) ? (string) $object->labels->singular_name : (string) $name;
            $types[$name] = sprintf('%s (%s)', $label, $name);
        }

        /**
         * Filters the post types offered as import destinations.
         *
         * @param array<string, string> $types Post type name → label.
         */
        $types = (array) apply_filters('ppapi_destination_post_types', $types);

        $clean = [];
        foreach ($types as $name => $label) {
            if (is_string($name) && post_type_exists($name)) {
                $clean[$name] = (string) $label;
            }
        }
        asort($clean, SORT_NATURAL | SORT_FLAG_CASE);

        return $clean;
    }

    /**
     * Whether a post type may be used as a destination.
     *
     * @param string $post_type Post type.
     *
     * @return bool
     */
    public function is_allowed(string $post_type): bool
    {
        return $post_type !== '' && array_key_exists($post_type, $this->post_types());
    }

    /**
     * Destination catalog for a post type.
     *
     * @param string $post_type Post type.
     *
     * @return DestinationCatalog
     */
    public function catalog(string $post_type): DestinationCatalog
    {
        return $this->catalogs[$post_type] ??= new DestinationCatalog($post_type, $this->core, $this->meta_policy, $this->acf);
    }
}
