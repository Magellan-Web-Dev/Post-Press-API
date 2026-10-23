<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Integration\ACF\AcfNormalizer;

/**
 * Evaluates mapping rows against one record.
 *
 * Per row: read the source (path, constant, or template) → decide what to do
 * when it is missing or null → check an optional condition on present values
 * → transform → normalize for the destination.
 * Nothing is written here; the result is a list of {@see FieldWrite}s that
 * the sync layer applies (or a dry run displays).
 *
 * Missing vs. null: a missing key uses the row's on_missing behavior; an
 * explicit null (or a null parent such as location.city when location is null)
 * uses on_null. Both default to "preserve", which leaves the destination
 * untouched on updates. false, 0, "", and [] are ordinary present values.
 *
 * Fallback values skip the transformation and go straight to destination
 * validation, so a fallback must already be in the destination's format.
 *
 * ACF groups take an object (or "$" for the current record/item) and nested
 * rows resolve relative to it. ACF repeaters take a list and nested rows resolve
 * relative to each item, so values from the same source item — e.g. a session's
 * date and time — always land in the same repeater row.
 */
final class MappingEngine
{
    /** @var MarkupPolicy Policy for rich text during the current evaluate() call. */
    private MarkupPolicy $markup;

    public function __construct(
        private readonly PathResolver $paths,
        private readonly Transformer $transformer,
        private readonly AcfNormalizer $acf_normalizer
    ) {}

    /**
     * Evaluates rows for a record.
     *
     * @param mixed                      $record          Decoded record.
     * @param list<array<string, mixed>> $rows            Normalized mapping rows.
     * @param DestinationCatalog         $catalog         Destination catalog for the post type.
     * @param \DateTimeZone              $site_tz         Site time zone.
     * @param bool                       $collect_details Whether to collect preview details.
     * @param MarkupPolicy|null          $markup          Shortcode/block policy for rich text (default: neutralize).
     *
     * @return MappedRecord
     */
    public function evaluate(mixed $record, array $rows, DestinationCatalog $catalog, \DateTimeZone $site_tz, bool $collect_details = false, ?MarkupPolicy $markup = null): MappedRecord
    {
        $this->markup = $markup ?? new MarkupPolicy();
        $result       = new MappedRecord();

        foreach ($rows as $index => $row) {
            $position = (string) ($index + 1);
            $detail   = $collect_details ? $this->base_detail($position, $row) : null;

            try {
                $destination = $catalog->resolve((string) $row['target_type'], (string) $row['target']);
                if ($detail !== null) {
                    $detail['target'] = $destination->label;
                }

                $outcome = $this->evaluate_value($record, $row, $destination, $catalog, $site_tz, false, $collect_details);

                if ($outcome['op'] === FieldWrite::SET) {
                    $result->add_write(new FieldWrite($destination, FieldWrite::SET, $outcome['value']));
                } elseif ($outcome['op'] === FieldWrite::CLEAR) {
                    $result->add_write(new FieldWrite($destination, FieldWrite::CLEAR));
                } elseif ($outcome['op'] === MappingSchema::SKIP_ROW) {
                    throw new MappingError(__('"Skip row" only applies inside repeaters.', 'post-press-api'));
                }

                if ($detail !== null) {
                    $result->add_detail($this->finish_detail($detail, $outcome));
                }
            } catch (MappingError | PathSyntaxError $e) {
                $message = sprintf(
                    /* translators: 1: row number, 2: destination label, 3: error. */
                    __('Mapping %1$s (%2$s): %3$s', 'post-press-api'),
                    $position,
                    $detail['target'] ?? (string) $row['target'],
                    $e->getMessage()
                );
                $result->add_error($message);

                if ($detail !== null) {
                    $detail['result'] = 'error';
                    $detail['note']   = $e->getMessage();
                    $result->add_detail($detail);
                }
            }
        }

        return $result;
    }

    /**
     * Evaluates a row in a context (record, group object, or repeater item).
     * Enabled conditions run on present values before container expansion,
     * transformation, or image downloads. Missing/null policies remain separate.
     *
     * @param mixed                $context      Context value paths resolve against.
     * @param array<string, mixed> $row          Row.
     * @param Destination          $destination  Resolved destination.
     * @param DestinationCatalog   $catalog      Catalog.
     * @param \DateTimeZone        $site_tz      Site time zone.
     * @param bool                 $in_repeater  Whether the row is a repeater sub field.
     * @param bool                 $with_details Whether to collect nested details.
     * @param bool                 $condition_in_repeater Whether an ancestor is a repeater; does not change missing/null policies.
     *
     * @return array{op: string, value?: mixed, source: PathResult, via?: string, note?: string, children?: list<array<string, mixed>>}
     */
    private function evaluate_value(mixed $context, array $row, Destination $destination, DestinationCatalog $catalog, \DateTimeZone $site_tz, bool $in_repeater, bool $with_details, bool $condition_in_repeater = false): array
    {
        $source = $this->read_source($context, $row);
        $condition = $row['condition'] ?? MappingCondition::DEFAULTS;
        $condition_in_repeater = $condition_in_repeater || $in_repeater;
        $condition_note = '';
        if (!empty($condition['enabled'])) {
            $errors = MappingCondition::errors($condition, $destination, $condition_in_repeater);
            if ($errors !== []) {
                throw new MappingError(implode(' ', $errors));
            }
            if ($source->is_present()) {
                $matched = MappingCondition::matches($condition, $source->value);
                $condition_note = MappingCondition::note($condition, $matched);
                if ($matched) {
                    return ['op' => $condition['action'], 'source' => $source, 'via' => 'condition', 'note' => $condition_note];
                }
            }
        }

        if ($destination->is_container()) {
            $outcome = $this->evaluate_container($source, $row, $destination, $catalog, $site_tz, $in_repeater, $with_details, $condition_in_repeater);
        } elseif ($source->is_present()) {
            $outcome = $this->convert($source, $row, $destination, $catalog, $site_tz);
        } else {
            $outcome = $this->apply_behavior($source, $row, $destination, $catalog, $site_tz, $in_repeater);
        }
        if ($condition_note !== '') {
            $outcome['note'] = $condition_note;
        }
        return $outcome;
    }

    /**
     * Transforms and normalizes a present source value.
     *
     * @return array{op: string, value?: mixed, source: PathResult, via?: string}
     */
    private function convert(PathResult $source, array $row, Destination $destination, DestinationCatalog $catalog, \DateTimeZone $site_tz): array
    {
        $value = $source->value;

        if ($row['transform'] === Transformer::MAP) {
            $mapped = $this->transformer->map_lookup($value, (array) $row['options']);

            if ($mapped === null) {
                $unmatched = (string) ($row['options']['unmatched'] ?? 'keep');
                if ($unmatched === 'fallback') {
                    return [
                        'op'     => FieldWrite::SET,
                        'value'  => $this->normalize($destination, (string) $row['fallback'], $catalog, $site_tz, false, $row),
                        'source' => $source,
                        'via'    => 'fallback',
                    ];
                }
                if ($unmatched === 'error') {
                    throw new MappingError(sprintf(
                        /* translators: %s: value preview. */
                        __('"%s" is not listed in the value map.', 'post-press-api'),
                        ValueInspector::preview($value, 60)
                    ));
                }
            } else {
                $value = $mapped;
            }
        } else {
            $value = $this->transformer->apply((string) $row['transform'], $value, (array) $row['options'], $site_tz);
        }

        return [
            'op'     => FieldWrite::SET,
            'value'  => $this->normalize($destination, $value, $catalog, $site_tz, $row['transform'] === Transformer::JSON, $row),
            'source' => $source,
            'via'    => 'source',
        ];
    }

    /**
     * Applies the missing/null behavior of a row.
     *
     * @return array{op: string, value?: mixed, source: PathResult, via?: string}
     */
    private function apply_behavior(PathResult $source, array $row, Destination $destination, DestinationCatalog $catalog, \DateTimeZone $site_tz, bool $in_repeater): array
    {
        $behavior = $source->is_missing() ? (string) $row['on_missing'] : (string) $row['on_null'];

        switch ($behavior) {
            case MappingSchema::PRESERVE:
                if ($in_repeater) {
                    // Repeater rows are rebuilt on every write, so "preserve" would leak another
                    // item's value into this row. The validator rejects it; clear defensively.
                    return ['op' => FieldWrite::CLEAR, 'source' => $source];
                }
                return ['op' => MappingSchema::PRESERVE, 'source' => $source];

            case MappingSchema::CLEAR:
                if (!$destination->clearable) {
                    throw new MappingError(__('This destination cannot be cleared.', 'post-press-api'));
                }
                return ['op' => FieldWrite::CLEAR, 'source' => $source];

            case MappingSchema::FALLBACK:
                if ($destination->is_container()) {
                    throw new MappingError(__('Groups and repeaters cannot use a fallback value.', 'post-press-api'));
                }
                return [
                    'op'     => FieldWrite::SET,
                    'value'  => $this->normalize($destination, (string) $row['fallback'], $catalog, $site_tz, false, $row),
                    'source' => $source,
                    'via'    => 'fallback',
                ];

            case MappingSchema::SKIP_ROW:
                if (!$in_repeater) {
                    throw new MappingError(__('"Skip row" only applies inside repeaters.', 'post-press-api'));
                }
                return ['op' => MappingSchema::SKIP_ROW, 'source' => $source];

            case MappingSchema::FAIL:
            default:
                throw new MappingError(sprintf(
                    /* translators: 1: source description, 2: "missing" or "null", 3: detail. */
                    __('Required source %1$s is %2$s. %3$s', 'post-press-api'),
                    $this->source_label($row),
                    $source->is_missing() ? __('missing', 'post-press-api') : __('null', 'post-press-api'),
                    (string) $source->note
                ));
        }
    }

    /**
     * Builds the value for an ACF group or repeater.
     * A condition may skip the enclosing repeater row through nested groups.
     *
     * @param PathResult $source Resolved container source.
     * @param array<string, mixed> $row Mapping settings.
     * @param Destination $destination Group or repeater destination.
     * @param DestinationCatalog $catalog Available fields.
     * @param \DateTimeZone $site_tz Site time zone.
     * @param bool $in_repeater Whether the immediate parent is a repeater.
     * @param bool $with_details Whether to collect nested previews.
     * @param bool $condition_in_repeater Whether an ancestor is a repeater.
     *
     * @return array{op: string, value?: mixed, source: PathResult, children?: list<array<string, mixed>>}
     */
    private function evaluate_container(PathResult $source, array $row, Destination $destination, DestinationCatalog $catalog, \DateTimeZone $site_tz, bool $in_repeater, bool $with_details, bool $condition_in_repeater = false): array
    {
        if (!$source->is_present()) {
            return $this->apply_behavior($source, $row, $destination, $catalog, $site_tz, $in_repeater);
        }

        $value    = $source->value;
        $children = (array) $row['children'];
        $details  = [];

        if ($destination->acf_type() === 'group') {
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                throw new MappingError(sprintf(
                    /* translators: %s: value type. */
                    __('A group needs an object as its source, but the source is %s.', 'post-press-api'),
                    ValueInspector::type_label($value)
                ));
            }

            $built = $this->build_children($value, $children, $destination, $catalog, $site_tz, false, $with_details, $details, $condition_in_repeater);
            if ($built === null) {
                return ['op' => MappingSchema::SKIP_ROW, 'source' => $source, 'children' => $details];
            }
            if ($built === []) {
                return ['op' => MappingSchema::PRESERVE, 'source' => $source, 'children' => $details];
            }

            return ['op' => FieldWrite::SET, 'value' => $built, 'source' => $source, 'via' => 'source', 'children' => $details];
        }

        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            throw new MappingError(sprintf(
                /* translators: %s: value type. */
                __('A repeater needs a list (array) as its source, but the source is %s.', 'post-press-api'),
                ValueInspector::type_label($value)
            ));
        }

        $rows = [];
        foreach ($value as $index => $item) {
            $built = $this->build_children($item, $children, $destination, $catalog, $site_tz, true, $with_details && $index === 0, $details, true);
            if ($built !== null) {
                $rows[] = $built;
            }
        }

        $max = (int) ($destination->acf_field['max'] ?? 0);
        if ($max > 0 && count($rows) > $max) {
            throw new MappingError(sprintf(
                /* translators: 1: row count, 2: maximum rows. */
                __('The source produced %1$d repeater rows but the field allows at most %2$d.', 'post-press-api'),
                count($rows),
                $max
            ));
        }

        return ['op' => FieldWrite::SET, 'value' => $rows, 'source' => $source, 'via' => 'source', 'children' => $details];
    }

    /**
     * Evaluates nested rows into an array keyed by sub field key.
     *
     * @param mixed                            $context     Group object or repeater item.
     * @param list<array<string, mixed>>       $children    Nested rows.
     * @param Destination                      $parent      Container destination.
     * @param DestinationCatalog               $catalog     Catalog.
     * @param \DateTimeZone                    $site_tz     Site time zone.
     * @param bool                             $in_repeater Whether building a repeater row.
     * @param bool                             $collect     Whether to collect preview details.
     * @param list<array<string, mixed>>       $details     Detail accumulator.
     * @param bool                            $condition_in_repeater Whether an ancestor is a repeater for condition actions.
     *
     * @return array<string, mixed>|null Null when a repeater row is skipped.
     */
    private function build_children(mixed $context, array $children, Destination $parent, DestinationCatalog $catalog, \DateTimeZone $site_tz, bool $in_repeater, bool $collect, array &$details, bool $condition_in_repeater = false): ?array
    {
        $values = [];

        foreach ($children as $index => $child) {
            $child_destination = $catalog->resolve_child($parent, (string) $child['target']);

            try {
                $outcome = $this->evaluate_value($context, $child, $child_destination, $catalog, $site_tz, $in_repeater, $collect, $condition_in_repeater);
            } catch (MappingError $e) {
                throw new MappingError(sprintf('%s › %s', $child_destination->label, $e->getMessage()));
            }

            if ($collect) {
                $detail            = $this->base_detail((string) ($index + 1), $child);
                $detail['target']  = $child_destination->label;
                $details[]         = $this->finish_detail($detail, $outcome);
            }

            switch ($outcome['op']) {
                case FieldWrite::SET:
                    $values[$child_destination->key] = $outcome['value'];
                    break;

                case FieldWrite::CLEAR:
                    $values[$child_destination->key] = $this->acf_normalizer->empty_value((array) $child_destination->acf_field);
                    break;

                case MappingSchema::SKIP_ROW:
                    return null;

                case MappingSchema::PRESERVE:
                default:
                    break;
            }
        }

        return $values;
    }

    /**
     * Normalizes a value for its destination.
     *
     * @param Destination        $destination Destination.
     * @param mixed              $value       Transformed value.
     * @param DestinationCatalog $catalog     Catalog.
     * @param \DateTimeZone      $site_tz     Site time zone.
     * @param bool               $is_json     Whether the value came from the JSON transformation.
     *
     * @return mixed
     */
    private function normalize(Destination $destination, mixed $value, DestinationCatalog $catalog, \DateTimeZone $site_tz, bool $is_json, array $row = []): mixed
    {
        if (!empty($row['media']['download'])) {
            if (!ImageReference::supports($destination) || !is_string($value)) {
                throw new MappingError(__('Image downloading requires a URL string and a compatible destination.', 'post-press-api'));
            }
            $output = $destination->category === Destination::META ? ($row['media']['output'] ?? 'id')
                : ($destination->category === Destination::CORE || $destination->acf_type() === 'image' ? 'id' : 'url');
            return new ImageReference(trim($value), $output);
        }
        switch ($destination->category) {
            case Destination::CORE:
                $value = $catalog->core()->normalize($destination->key, $value, $site_tz, $catalog->post_type);
                return is_string($value) && in_array($destination->key, [CoreFields::CONTENT, CoreFields::EXCERPT], true) ? $this->markup->apply($value) : $value;

            case Destination::META:
                $text = Transformer::stringify($value, $site_tz);
                // JSON strings are data; running them through KSES could corrupt them.
                return $is_json ? $text : wp_kses_post($text);

            case Destination::ACF:
                $value = $this->acf_normalizer->normalize((array) $destination->acf_field, $value, $site_tz);
                return is_string($value) && $destination->acf_type() === 'wysiwyg' ? $this->markup->apply($value) : $value;
        }

        throw new MappingError(__('Unknown destination.', 'post-press-api'));
    }

    /**
     * Reads a row's source value.
     *
     * @param mixed                $context Context value.
     * @param array<string, mixed> $row     Row.
     *
     * @return PathResult
     */
    private function read_source(mixed $context, array $row): PathResult
    {
        $source = (string) $row['source'];

        switch ($row['source_type']) {
            case MappingSchema::SOURCE_CONSTANT:
                return PathResult::present($source);

            case MappingSchema::SOURCE_TEMPLATE:
                return $this->read_template($context, $source);

            case MappingSchema::SOURCE_PATH:
            default:
                return $this->paths->resolve($context, $source === '' ? PathResolver::ROOT : $source);
        }
    }

    /**
     * Fills {path} placeholders. {{ and }} produce literal braces.
     *
     * If any placeholder is missing the template counts as missing; otherwise if
     * any is null it counts as null, so the row's behaviors apply.
     *
     * @param mixed  $context  Context value.
     * @param string $template Template text.
     *
     * @return PathResult
     */
    private function read_template(mixed $context, string $template): PathResult
    {
        $missing = null;
        $null    = null;

        $text = preg_replace_callback(
            '/\{\{|\}\}|\{([^{}]+)\}/',
            function (array $match) use ($context, &$missing, &$null): string {
                if ($match[0] === '{{') {
                    return '{';
                }
                if ($match[0] === '}}') {
                    return '}';
                }

                $path   = trim($match[1]);
                $result = $this->paths->resolve($context, $path);

                if ($result->is_missing()) {
                    $missing ??= $result;
                    return '';
                }
                if ($result->is_null()) {
                    $null ??= $result;
                    return '';
                }
                if (is_array($result->value)) {
                    throw new MappingError(sprintf(
                        /* translators: %s: placeholder path. */
                        __('The placeholder {%s} is a list or object; select a single value.', 'post-press-api'),
                        $path
                    ));
                }

                return Transformer::to_display_string($result->value);
            },
            $template
        );

        if ($missing instanceof PathResult) {
            return PathResult::missing($missing->note);
        }
        if ($null instanceof PathResult) {
            return PathResult::null($null->note);
        }

        return PathResult::present((string) $text);
    }

    /**
     * Short description of a row's source for messages.
     *
     * @param array<string, mixed> $row Row.
     *
     * @return string
     */
    private function source_label(array $row): string
    {
        return match ($row['source_type']) {
            MappingSchema::SOURCE_CONSTANT => __('constant', 'post-press-api'),
            MappingSchema::SOURCE_TEMPLATE => sprintf('"%s"', ValueInspector::preview((string) $row['source'], 60)),
            default                        => sprintf('"%s"', (string) $row['source'] === '' ? PathResolver::ROOT : (string) $row['source']),
        };
    }

    /**
     * Starts a preview detail entry.
     *
     * @param string               $position Row position label.
     * @param array<string, mixed> $row      Row.
     *
     * @return array<string, mixed>
     */
    private function base_detail(string $position, array $row): array
    {
        return [
            'row'         => $position,
            'target'      => (string) $row['target'],
            'category'    => (string) $row['target_type'],
            'source_type' => (string) $row['source_type'],
            'source'      => ValueInspector::preview((string) $row['source'], 120),
            'state'       => '',
            'raw_type'    => '',
            'raw'         => '',
            'result'      => '',
            'via'         => '',
            'value'       => '',
            'note'        => '',
            'children'    => [],
        ];
    }

    /**
     * Completes a preview detail entry, including any condition explanation.
     *
     * @param array<string, mixed>                                        $detail  Detail.
     * @param array{op: string, value?: mixed, source: PathResult, via?: string, note?: string, children?: list<array<string, mixed>>} $outcome Outcome.
     *
     * @return array<string, mixed>
     */
    private function finish_detail(array $detail, array $outcome): array
    {
        $source = $outcome['source'];

        $detail['state']    = $source->state;
        $detail['raw_type'] = $source->is_present() ? ValueInspector::describe($source->value) : '';
        $detail['raw']      = $source->is_present() ? ValueInspector::preview($source->value, 160) : '';
        $detail['result']   = $outcome['op'];
        $detail['via']      = $outcome['via'] ?? '';
        $detail['value']    = array_key_exists('value', $outcome) ? ValueInspector::preview($outcome['value'], 300) : '';
        $detail['note']     = $outcome['note'] ?? ($source->is_present() ? '' : (string) $source->note);
        $detail['children'] = $outcome['children'] ?? [];

        if (($outcome['value'] ?? null) instanceof ImageReference) {
            $detail['raw'] = \PostPressAPI\Support\Redactor::url($outcome['value']->url);
            if ($detail['source_type'] !== MappingSchema::SOURCE_PATH) {
                $detail['source'] = __('Image URL (query parameters hidden)', 'post-press-api');
            }
            $detail['note'] = trim($detail['note'] . ' ' . __('Would download or reuse a Media Library image during a write. Remote availability is not checked in a preview.', 'post-press-api'));
        }

        return $detail;
    }
}
