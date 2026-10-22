<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * Optional rules that suppress a mapping before transformation or media work.
 *
 * Rules inspect the mapping's present source value. Missing/null sources keep
 * their existing policies. Text operands are literal, never executable patterns.
 */
final class MappingCondition
{
    /** @var array{enabled: bool, operator: string, value: string, case_sensitive: bool, action: string} Defaults for legacy and new rows. */
    public const DEFAULTS = [
        'enabled' => false,
        'operator' => 'count_eq',
        'value' => '1',
        'case_sensitive' => false,
        'action' => 'preserve',
    ];

    /**
     * Operators shared by validation, previews, and the mapping editor.
     *
     * @return array<string, string> Operator names and translated labels.
     */
    public static function labels(): array
    {
        return [
            'count_eq' => __('Array item count equals', 'post-press-api'),
            'count_ne' => __('Array item count does not equal', 'post-press-api'),
            'count_lt' => __('Array item count is less than', 'post-press-api'),
            'count_lte' => __('Array item count is at most', 'post-press-api'),
            'count_gt' => __('Array item count is greater than', 'post-press-api'),
            'count_gte' => __('Array item count is at least', 'post-press-api'),
            'contains' => __('Text contains', 'post-press-api'),
            'not_contains' => __('Text does not contain', 'post-press-api'),
        ];
    }

    /**
     * Checks enabled rules, valid UTF-8 operands, and destination-specific actions.
     * Rejects text cut mid-character by the schema's byte limit before pattern use.
     *
     * @param array<string, mixed> $condition Normalized condition settings.
     * @param Destination $destination Resolved mapping destination.
     * @param bool $in_repeater Whether any enclosing container is a repeater.
     * @return list<string> Validation errors; disabled rules have no effect.
     */
    public static function errors(array $condition, Destination $destination, bool $in_repeater): array
    {
        if (empty($condition['enabled'])) {
            return [];
        }

        $errors = [];
        $operator = (string) ($condition['operator'] ?? '');
        $value = (string) ($condition['value'] ?? '');
        $action = (string) ($condition['action'] ?? '');
        if (!array_key_exists($operator, self::labels())) {
            $errors[] = __('Choose a supported condition operator.', 'post-press-api');
        } elseif (str_starts_with($operator, 'count_')) {
            if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value)
                || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                $errors[] = __('The condition item count must be a whole number of zero or greater.', 'post-press-api');
            }
        } elseif ($value === '') {
            $errors[] = __('Enter the literal text or character to match in the condition.', 'post-press-api');
        } elseif (preg_match('//u', $value) !== 1) {
            $errors[] = __('Condition text must be valid UTF-8 within the text length limit.', 'post-press-api');
        }

        if (!in_array($action, [MappingSchema::PRESERVE, MappingSchema::CLEAR, MappingSchema::SKIP_ROW], true)) {
            $errors[] = __('Choose a supported action when the condition matches.', 'post-press-api');
        } elseif ($action === MappingSchema::PRESERVE && $in_repeater) {
            $errors[] = __('A condition inside a repeater cannot keep an existing value. Clear the field or skip the repeater row.', 'post-press-api');
        } elseif ($action === MappingSchema::SKIP_ROW && !$in_repeater) {
            $errors[] = __('A condition can skip a row only inside a repeater.', 'post-press-api');
        } elseif ($action === MappingSchema::CLEAR && !$destination->clearable) {
            $errors[] = __('This destination cannot be cleared when a condition matches.', 'post-press-api');
        }

        return $errors;
    }

    /**
     * Compares a present source value with an already validated rule.
     *
     * Array counts use the source list before sanitization or joining, including
     * empty and duplicate items. Text matching uses literal Unicode text; case
     * insensitive matching is implemented with an escaped PCRE Unicode pattern.
     *
     * @param array<string, mixed> $condition Validated condition settings.
     * @param mixed $value Present mapping source before transformation.
     * @return bool Whether the configured action should replace the field write.
     * @throws MappingError When the source type or text encoding is unsuitable.
     */
    public static function matches(array $condition, mixed $value): bool
    {
        $operator = (string) $condition['operator'];
        if (str_starts_with($operator, 'count_')) {
            if (!is_array($value) || !array_is_list($value)) {
                throw new MappingError(__('Array count conditions require a list as the source value.', 'post-press-api'));
            }
            $count = count($value);
            $limit = (int) $condition['value'];
            return match ($operator) {
                'count_eq' => $count === $limit,
                'count_ne' => $count !== $limit,
                'count_lt' => $count < $limit,
                'count_lte' => $count <= $limit,
                'count_gt' => $count > $limit,
                'count_gte' => $count >= $limit,
                default => throw new MappingError(__('Choose a supported condition operator.', 'post-press-api')),
            };
        }

        if (!is_string($value)) {
            throw new MappingError(__('Text conditions require a string as the source value.', 'post-press-api'));
        }
        if (!in_array($operator, ['contains', 'not_contains'], true)) {
            throw new MappingError(__('Choose a supported condition operator.', 'post-press-api'));
        }
        $found = !empty($condition['case_sensitive'])
            ? (int) str_contains($value, (string) $condition['value'])
            : preg_match('/' . preg_quote((string) $condition['value'], '/') . '/iu', $value);
        if ($found === false) {
            throw new MappingError(__('The text condition could not compare the source as UTF-8 text.', 'post-press-api'));
        }

        return $operator === 'contains' ? $found === 1 : $found === 0;
    }

    /**
     * Explains a rule result without exposing the full source value in previews.
     *
     * @param array<string, mixed> $condition Validated condition settings.
     * @param bool $matched Whether the rule matched.
     * @return string Human-readable result and, on a match, the selected action.
     */
    public static function note(array $condition, bool $matched): string
    {
        $note = sprintf(
            /* translators: 1: condition operator label, 2: comparison value. */
            $matched ? __('Condition matched: %1$s %2$s.', 'post-press-api') : __('Condition did not match: %1$s %2$s. Mapping continues.', 'post-press-api'),
            self::labels()[$condition['operator']],
            ValueInspector::preview((string) $condition['value'], 60)
        );
        if ($matched) {
            $note .= ' ' . match ($condition['action']) {
                MappingSchema::CLEAR => __('Clear the field.', 'post-press-api'),
                MappingSchema::SKIP_ROW => __('Skip this repeater row.', 'post-press-api'),
                default => __('Keep the existing value; no field write.', 'post-press-api'),
            };
        }
        return $note;
    }
}
