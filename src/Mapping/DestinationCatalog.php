<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

use PostPressAPI\Integration\ACF\AcfFields;

if (!defined('ABSPATH')) exit;

/**
 * Resolves mapping targets for one destination post type.
 */
final class DestinationCatalog
{
    public function __construct(
        public readonly string $post_type,
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
     * Resolves a top-level target.
     *
     * @param string $category core, meta, or acf.
     * @param string $key      Field name, meta key, or ACF field key.
     *
     * @return Destination
     *
     * @throws MappingError When the target is not allowed or does not exist.
     */
    public function resolve(string $category, string $key): Destination
    {
        if ($key === '') {
            throw new MappingError(__('Choose a destination field.', 'post-press-api'));
        }

        switch ($category) {
            case Destination::CORE:
                $definitions = $this->core->definitions();
                if (!isset($definitions[$key])) {
                    throw new MappingError(sprintf(
                        /* translators: %s: field name. */
                        __('"%s" is not a supported post field.', 'post-press-api'),
                        $key
                    ));
                }
                return new Destination(Destination::CORE, $key, $definitions[$key]['label'], $definitions[$key]['clearable']);

            case Destination::META:
                $reason = $this->meta_policy->check($key, $this->post_type);
                if ($reason !== null) {
                    throw new MappingError(sprintf('%s: %s', $key, $reason));
                }
                return new Destination(Destination::META, $key, $key, true);

            case Destination::ACF:
                if (!$this->acf->available()) {
                    throw new MappingError(__('Advanced Custom Fields is not active, so ACF destinations cannot be used.', 'post-press-api'));
                }

                $field = $this->acf->top_level_field($this->post_type, $key);
                if ($field === null) {
                    throw new MappingError($this->acf->is_known_field($this->post_type, $key)
                        ? sprintf(
                            /* translators: %s: ACF field key. */
                            __('%s is a sub field. Map it inside its parent group or repeater.', 'post-press-api'),
                            $key
                        )
                        : sprintf(
                            /* translators: 1: ACF field key, 2: post type. */
                            __('The ACF field %1$s is not registered for the post type "%2$s".', 'post-press-api'),
                            $key,
                            $this->post_type
                        ));
                }

                return $this->acf_destination($field);
        }

        throw new MappingError(__('Choose a destination type.', 'post-press-api'));
    }

    /**
     * Resolves a sub field of an ACF group or repeater.
     *
     * @param Destination $parent Container destination.
     * @param string      $key    Sub field key.
     *
     * @return Destination
     */
    public function resolve_child(Destination $parent, string $key): Destination
    {
        if (!$parent->is_container() || $parent->acf_field === null) {
            throw new MappingError(__('Nested mappings are only allowed inside ACF groups and repeaters.', 'post-press-api'));
        }

        $field = $this->acf->sub_field($parent->acf_field, $key);
        if ($field === null) {
            throw new MappingError(sprintf(
                /* translators: 1: sub field key, 2: parent label. */
                __('%1$s is not a sub field of "%2$s".', 'post-press-api'),
                $key === '' ? __('(none)', 'post-press-api') : $key,
                $parent->label
            ));
        }

        return $this->acf_destination($field);
    }

    /**
     * Data the editor needs to offer destinations (no secrets, no raw ACF settings).
     *
     * @return array<string, mixed>
     */
    public function to_editor_array(): array
    {
        $core = [];
        foreach ($this->core->definitions() as $key => $definition) {
            $core[] = ['key' => $key] + $definition;
        }

        return [
            'post_type' => $this->post_type,
            'core'      => $core,
            'statuses'  => $this->core->allowed_statuses(),
            'acf'       => [
                'available' => $this->acf->available(),
                'repeater'  => $this->acf->available() && $this->acf->type_registered('repeater'),
                'groups'    => $this->acf->groups($this->post_type),
            ],
        ];
    }

    /**
     * Wraps a supported ACF field.
     *
     * @param array<string, mixed> $field Field definition.
     *
     * @return Destination
     */
    private function acf_destination(array $field): Destination
    {
        $reason = $this->acf->unsupported_reason($field);
        $label  = (string) (($field['label'] ?? '') !== '' ? $field['label'] : ($field['name'] ?? $field['key']));

        if ($reason !== null) {
            throw new MappingError(sprintf('%s (%s): %s', $label, (string) $field['key'], $reason));
        }

        return new Destination(Destination::ACF, (string) $field['key'], $label, true, $field);
    }
}
