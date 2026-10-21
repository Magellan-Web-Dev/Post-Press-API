<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * Validates mapping rows against a destination catalog before they are saved.
 */
final class MappingValidator
{
    public function __construct(private readonly PathResolver $paths)
    {
    }

    /**
     * Validates rows.
     *
     * @param list<array<string, mixed>> $rows    Normalized rows.
     * @param DestinationCatalog         $catalog Destination catalog.
     *
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(array $rows, DestinationCatalog $catalog): array
    {
        $errors   = [];
        $warnings = [];
        $seen     = [];

        foreach ($rows as $index => $row) {
            $label = sprintf(
                /* translators: %d: row number. */
                __('Mapping %d', 'post-press-api'),
                $index + 1
            );
            $this->validate_row($row, $label, $catalog, null, $seen, $errors, $warnings);
        }

        return ['errors' => $errors, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Validates one row (recursively for nested rows).
     *
     * @param array<string, mixed>   $row      Row.
     * @param string                 $label    Row label for messages.
     * @param DestinationCatalog     $catalog  Catalog.
     * @param Destination|null       $parent   Parent container for nested rows.
     * @param array<string, bool>    $seen     Destinations already used at this level.
     * @param list<string>           $errors   Error accumulator.
     * @param list<string>           $warnings Warning accumulator.
     *
     * @return void
     */
    private function validate_row(array $row, string $label, DestinationCatalog $catalog, ?Destination $parent, array &$seen, array &$errors, array &$warnings): void
    {
        $add = static function (string $message) use (&$errors, $label): void {
            $errors[] = $label . ': ' . $message;
        };

        $this->validate_source($row, $add);

        if ($parent !== null && $row['target_type'] !== Destination::ACF) {
            $add(__('Nested mappings must target a sub field of the group or repeater.', 'post-press-api'));
            return;
        }

        if ($row['target_type'] === '' || $row['target'] === '') {
            $add(__('Choose a destination.', 'post-press-api'));
            return;
        }

        if ($row['target_type'] === Destination::ACF && !$catalog->acf()->available()) {
            $warnings[] = __('Advanced Custom Fields is not active. ACF mappings were kept but could not be checked, and runs of this preset will fail until ACF is active again.', 'post-press-api');
            return;
        }

        try {
            $destination = $parent !== null
                ? $catalog->resolve_child($parent, (string) $row['target'])
                : $catalog->resolve((string) $row['target_type'], (string) $row['target']);
        } catch (MappingError $e) {
            $add($e->getMessage());
            return;
        }

        $id = $destination->id();
        if (isset($seen[$id])) {
            $add(sprintf(
                /* translators: %s: destination label. */
                __('"%s" is already mapped by another row. Each destination can be mapped once.', 'post-press-api'),
                $destination->label
            ));
        }
        $seen[$id] = true;

        if (!empty($row['media']['download'])) {
            if (!ImageReference::supports($destination)) {
                $add(__('Image downloading is only supported for featured images, custom meta, and ACF image/URL/text fields.', 'post-press-api'));
            } elseif ($destination->category !== Destination::META) {
                $expected = $destination->category === Destination::CORE || $destination->acf_type() === 'image' ? 'id' : 'url';
                if (($row['media']['output'] ?? 'id') !== $expected) {
                    $add(sprintf(__('This image destination requires output: %s.', 'post-press-api'), $expected));
                }
            }
        }

        $in_repeater = $parent !== null && $parent->acf_type() === 'repeater';

        foreach (['on_missing' => __('When missing', 'post-press-api'), 'on_null' => __('When null', 'post-press-api')] as $key => $behavior_label) {
            $behavior = (string) $row[$key];

            if ($behavior === MappingSchema::PRESERVE && $in_repeater) {
                $add(sprintf(
                    /* translators: %s: behavior label. */
                    __('%s: repeater rows are rebuilt on every write, so sub fields cannot "keep existing value". Choose Clear, Fallback, Skip row, or Fail.', 'post-press-api'),
                    $behavior_label
                ));
            }
            if ($behavior === MappingSchema::SKIP_ROW && !$in_repeater) {
                $add(sprintf(
                    /* translators: %s: behavior label. */
                    __('%s: "Skip row" is only available for repeater sub fields.', 'post-press-api'),
                    $behavior_label
                ));
            }
            if ($behavior === MappingSchema::CLEAR && !$destination->clearable) {
                $add(sprintf(
                    /* translators: 1: behavior label, 2: destination label. */
                    __('%1$s: "%2$s" cannot be cleared. Choose Keep existing value, Fallback, or Fail.', 'post-press-api'),
                    $behavior_label,
                    $destination->label
                ));
            }
            if ($behavior === MappingSchema::FALLBACK && $destination->is_container()) {
                $add(sprintf(
                    /* translators: %s: behavior label. */
                    __('%s: groups and repeaters cannot use a fallback value.', 'post-press-api'),
                    $behavior_label
                ));
            }
        }

        if ($destination->is_container()) {
            if ($row['source_type'] !== MappingSchema::SOURCE_PATH) {
                $add(__('Groups and repeaters must use a source path ($ for the current record or item).', 'post-press-api'));
            }
            if ($row['transform'] !== Transformer::NONE) {
                $add(__('Groups and repeaters cannot use a transformation; set transformations on their sub fields.', 'post-press-api'));
            }
            if ($row['children'] === []) {
                $add(__('Add at least one sub field mapping.', 'post-press-api'));
            }

            $child_seen = [];
            foreach ($row['children'] as $index => $child) {
                $this->validate_row($child, $label . ' › ' . ($index + 1), $catalog, $destination, $child_seen, $errors, $warnings);
            }
            return;
        }

        if ($row['children'] !== []) {
            $add(__('Only ACF groups and repeaters can have sub field mappings.', 'post-press-api'));
        }

        $this->validate_transform($row, $add);
        $this->validate_core_rules($row, $destination, $catalog, $add);
    }

    /**
     * Validates the source definition.
     *
     * @param array<string, mixed> $row Row.
     * @param callable             $add Error callback.
     *
     * @return void
     */
    private function validate_source(array $row, callable $add): void
    {
        $source = (string) $row['source'];

        if ($row['source_type'] === MappingSchema::SOURCE_PATH) {
            if ($source === '') {
                $add(__('Enter a source path (use $ for the whole record or item).', 'post-press-api'));
                return;
            }
            $error = $this->paths->validate($source);
            if ($error !== null) {
                $add(sprintf(
                    /* translators: 1: path, 2: error. */
                    __('Source path "%1$s" is invalid: %2$s', 'post-press-api'),
                    $source,
                    $error
                ));
            }
            return;
        }

        if ($row['source_type'] === MappingSchema::SOURCE_TEMPLATE) {
            if (preg_match_all('/\{\{|\}\}|\{([^{}]+)\}/', $source, $matches, PREG_SET_ORDER) === false) {
                return;
            }
            foreach ($matches as $match) {
                if (!isset($match[1])) {
                    continue;
                }
                $error = $this->paths->validate(trim($match[1]));
                if ($error !== null) {
                    $add(sprintf(
                        /* translators: 1: placeholder, 2: error. */
                        __('Template placeholder {%1$s} is invalid: %2$s', 'post-press-api'),
                        trim($match[1]),
                        $error
                    ));
                }
            }
        }
    }

    /**
     * Validates transformation options.
     *
     * @param array<string, mixed> $row Row.
     * @param callable             $add Error callback.
     *
     * @return void
     */
    private function validate_transform(array $row, callable $add): void
    {
        $options = (array) $row['options'];

        if ($row['transform'] === Transformer::DATETIME
            || ($row['transform'] === Transformer::JOIN && !empty($options['join_datetime']))) {
            foreach (['input_timezone', 'output_timezone'] as $key) {
                $name = (string) ($options[$key] ?? '');
                if ($name !== '' && DateParser::timezone($name) === null) {
                    $add(sprintf(
                        /* translators: %s: time zone. */
                        __('"%s" is not a valid IANA time zone (for example America/New_York).', 'post-press-api'),
                        $name
                    ));
                }
            }
        }

        if ($row['transform'] === Transformer::MAP && ($options['map'] ?? []) === []) {
            $add(__('Add at least one value map entry.', 'post-press-api'));
        }
    }

    /**
     * Validates rules specific to core fields.
     *
     * @param array<string, mixed> $row         Row.
     * @param Destination          $destination Destination.
     * @param DestinationCatalog   $catalog     Catalog.
     * @param callable             $add         Error callback.
     *
     * @return void
     */
    private function validate_core_rules(array $row, Destination $destination, DestinationCatalog $catalog, callable $add): void
    {
        if ($destination->category !== Destination::CORE) {
            return;
        }

        $statuses     = $catalog->core()->allowed_statuses();
        $uses_fallback = in_array(MappingSchema::FALLBACK, [$row['on_missing'], $row['on_null']], true)
            || ($row['transform'] === Transformer::MAP && ($row['options']['unmatched'] ?? '') === 'fallback');

        if ($destination->key === CoreFields::STATUS) {
            if ($row['source_type'] === MappingSchema::SOURCE_CONSTANT) {
                if (!in_array($row['source'], $statuses, true)) {
                    $add(sprintf(
                        /* translators: %s: allowed statuses. */
                        __('The status constant must be one of: %s.', 'post-press-api'),
                        implode(', ', $statuses)
                    ));
                }
            } else {
                if ($row['transform'] !== Transformer::MAP) {
                    $add(__('Mapping a source value to the post status needs a Value map that converts source statuses to WordPress statuses. Source statuses are never used as post statuses directly.', 'post-press-api'));
                } else {
                    foreach ((array) $row['options']['map'] as $pair) {
                        if (!in_array($pair['to'], $statuses, true)) {
                            $add(sprintf(
                                /* translators: 1: mapped value, 2: allowed statuses. */
                                __('Value map target "%1$s" is not an allowed status (%2$s).', 'post-press-api'),
                                $pair['to'],
                                implode(', ', $statuses)
                            ));
                        }
                    }
                    if (($row['options']['unmatched'] ?? 'keep') === 'keep') {
                        $add(__('For post status, unlisted source values must use the fallback or be reported as errors.', 'post-press-api'));
                    }
                }
            }

            if ($uses_fallback && !in_array($row['fallback'], $statuses, true)) {
                $add(sprintf(
                    /* translators: %s: allowed statuses. */
                    __('The status fallback must be one of: %s.', 'post-press-api'),
                    implode(', ', $statuses)
                ));
            }
        }

        if ($destination->key === CoreFields::AUTHOR) {
            $candidates = [];
            if ($row['source_type'] === MappingSchema::SOURCE_CONSTANT) {
                $candidates[] = $row['source'];
            }
            if ($uses_fallback) {
                $candidates[] = $row['fallback'];
            }
            foreach ($candidates as $candidate) {
                try {
                    $catalog->core()->author_id($candidate, $catalog->post_type);
                } catch (MappingError $e) {
                    $add($e->getMessage());
                }
            }
        }
    }
}
