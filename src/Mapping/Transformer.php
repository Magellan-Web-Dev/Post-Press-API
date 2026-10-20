<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * The small, explicit set of value transformations a mapping row can apply.
 *
 * Transformations never evaluate code or expressions. Every conversion either
 * succeeds with a well-defined result or throws a {@see MappingError}; arrays
 * and objects are never silently cast to the string "Array".
 */
final class Transformer
{
    public const NONE     = 'none';
    public const TEXT     = 'text';
    public const INTEGER  = 'integer';
    public const DECIMAL  = 'decimal';
    public const BOOLEAN  = 'boolean';
    public const URL      = 'url';
    public const EMAIL    = 'email';
    public const JOIN     = 'join';
    public const JSON     = 'json';
    public const DATETIME = 'datetime';
    public const MAP      = 'map';

    /** @var list<string> Every supported transformation. */
    public const TYPES = [
        self::NONE, self::TEXT, self::INTEGER, self::DECIMAL, self::BOOLEAN, self::URL,
        self::EMAIL, self::JOIN, self::JSON, self::DATETIME, self::MAP,
    ];

    /** @var array<string, bool> Accepted boolean spellings (lower-case). */
    private const BOOLEAN_WORDS = [
        'true' => true, '1' => true, 'yes' => true, 'y' => true, 'on' => true,
        'false' => false, '0' => false, 'no' => false, 'n' => false, 'off' => false,
    ];

    /**
     * Human-readable labels for the editor.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::NONE     => __('No transformation', 'post-press-api'),
            self::TEXT     => __('Plain text', 'post-press-api'),
            self::INTEGER  => __('Integer', 'post-press-api'),
            self::DECIMAL  => __('Decimal number', 'post-press-api'),
            self::BOOLEAN  => __('Boolean (true/false)', 'post-press-api'),
            self::URL      => __('Validate URL', 'post-press-api'),
            self::EMAIL    => __('Validate email', 'post-press-api'),
            self::JOIN     => __('Join list into text', 'post-press-api'),
            self::JSON     => __('JSON encode', 'post-press-api'),
            self::DATETIME => __('Date/time (parse and convert)', 'post-press-api'),
            self::MAP      => __('Value map (convert listed values)', 'post-press-api'),
        ];
    }

    /**
     * Applies a transformation to a present, non-null value.
     *
     * The value-map transformation is resolved by {@see MappingEngine} because its
     * "use fallback" option depends on row-level behavior; here it only performs
     * the lookup when called through {@see map_lookup()}.
     *
     * @param string               $type    Transformation type.
     * @param mixed                $value   Source value.
     * @param array<string, mixed> $options Row options.
     * @param \DateTimeZone        $site_tz Site time zone.
     *
     * @return mixed
     *
     * @throws MappingError When the value cannot be converted.
     */
    public function apply(string $type, mixed $value, array $options, \DateTimeZone $site_tz): mixed
    {
        return match ($type) {
            self::NONE, self::MAP => $value,
            self::TEXT     => sanitize_textarea_field(self::to_display_string($value)),
            self::INTEGER  => self::to_integer($value),
            self::DECIMAL  => self::to_decimal($value, $options['decimals'] ?? ''),
            self::BOOLEAN  => self::to_bool($value),
            self::URL      => self::to_url($value),
            self::EMAIL    => self::to_email($value),
            self::JOIN     => self::join($value, self::decode_separator((string) ($options['separator'] ?? ', '))),
            self::JSON     => self::to_json($value),
            self::DATETIME => self::to_datetime($value, $options, $site_tz),
            default        => throw new MappingError(sprintf(
                /* translators: %s: transformation name. */
                __('Unknown transformation "%s".', 'post-press-api'),
                $type
            )),
        };
    }

    /**
     * Looks up a value in a value-map table.
     *
     * @param mixed                $value   Source value (scalar).
     * @param array<string, mixed> $options Row options containing "map".
     *
     * @return string|null The mapped value, or null when no entry matches.
     *
     * @throws MappingError When the value is not scalar.
     */
    public function map_lookup(mixed $value, array $options): ?string
    {
        $needle = self::to_display_string($value);

        foreach ((array) ($options['map'] ?? []) as $pair) {
            if (is_array($pair) && isset($pair['from']) && (string) $pair['from'] === $needle) {
                return (string) ($pair['to'] ?? '');
            }
        }

        return null;
    }

    /**
     * Converts a scalar to a human-readable string (booleans become true/false).
     *
     * @param mixed $value Value.
     *
     * @return string
     *
     * @throws MappingError For arrays, objects, and null.
     */
    public static function to_display_string(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return self::scalar_to_string($value);
    }

    /**
     * Converts a scalar to its storage string (booleans become 1/0).
     *
     * @param mixed $value Value.
     *
     * @return string
     *
     * @throws MappingError For arrays, objects, and null.
     */
    public static function to_storage_string(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return self::scalar_to_string($value);
    }

    /**
     * Converts a transformed value to text for text-like destinations.
     *
     * Parsed dates use the mapping's output format when set, otherwise
     * "Y-m-d H:i:s", "Y-m-d", or "H:i:s" depending on which parts the source had.
     *
     * @param mixed         $value   Value.
     * @param \DateTimeZone $site_tz Site time zone.
     *
     * @return string
     */
    public static function stringify(mixed $value, \DateTimeZone $site_tz): string
    {
        if ($value instanceof DateValue) {
            $format = $value->output_format
                ?? ($value->has_date && $value->has_time ? 'Y-m-d H:i:s' : ($value->has_date ? 'Y-m-d' : 'H:i:s'));

            return $value->format($format, $site_tz);
        }

        return self::to_storage_string($value);
    }

    /**
     * Interprets common boolean spellings.
     *
     * @param mixed $value Value.
     *
     * @return bool
     *
     * @throws MappingError When the value is not recognisably boolean.
     */
    public static function to_bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $word = strtolower(trim($value));
            if (array_key_exists($word, self::BOOLEAN_WORDS)) {
                return self::BOOLEAN_WORDS[$word];
            }
        }

        throw new MappingError(sprintf(
            /* translators: %s: value preview. */
            __('"%s" is not a recognised boolean. Use true/false, 1/0, yes/no, or on/off.', 'post-press-api'),
            ValueInspector::preview($value, 60)
        ));
    }

    /**
     * Converts numeric input to int|float.
     *
     * @param mixed $value Value.
     *
     * @return int|float
     *
     * @throws MappingError When the value is not numeric.
     */
    public static function to_number(mixed $value): int|float
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return $value;
        }

        if (is_string($value)) {
            $text = trim($value);
            if ($text !== '' && is_numeric($text)) {
                $number = $text + 0;
                if (is_float($number) && !is_finite($number)) {
                    throw new MappingError(__('The number is out of range.', 'post-press-api'));
                }
                return $number;
            }
        }

        throw new MappingError(sprintf(
            /* translators: %s: value preview. */
            __('"%s" is not a number.', 'post-press-api'),
            ValueInspector::preview($value, 60)
        ));
    }

    /**
     * Converts to an integer, rejecting fractional values.
     *
     * @param mixed $value Value.
     *
     * @return int
     */
    public static function to_integer(mixed $value): int
    {
        $number = self::to_number($value);

        if (is_int($number)) {
            return $number;
        }

        if (floor($number) !== $number || abs($number) >= (float) PHP_INT_MAX) {
            throw new MappingError(sprintf(
                /* translators: %s: value preview. */
                __('"%s" is not a whole number.', 'post-press-api'),
                ValueInspector::preview($value, 60)
            ));
        }

        return (int) $number;
    }

    /**
     * Converts to a float, optionally rounding.
     *
     * @param mixed      $value    Value.
     * @param int|string $decimals Decimal places ("" for no rounding).
     *
     * @return float
     */
    public static function to_decimal(mixed $value, int|string $decimals): float
    {
        $number = (float) self::to_number($value);

        if ($decimals !== '' && is_numeric($decimals)) {
            $number = round($number, max(0, min(10, (int) $decimals)));
        }

        return $number;
    }

    /**
     * Validates an absolute http(s) URL.
     *
     * @param mixed $value Value.
     *
     * @return string
     */
    public static function to_url(mixed $value): string
    {
        $text   = trim(self::scalar_to_string($value));
        $scheme = strtolower((string) wp_parse_url($text, PHP_URL_SCHEME));

        if ($text === '' || !in_array($scheme, ['http', 'https'], true) || filter_var($text, FILTER_VALIDATE_URL) === false) {
            throw new MappingError(sprintf(
                /* translators: %s: value preview. */
                __('"%s" is not a valid http(s) URL.', 'post-press-api'),
                ValueInspector::preview($text, 80)
            ));
        }

        $clean = esc_url_raw($text, ['http', 'https']);
        if ($clean === '') {
            throw new MappingError(sprintf(
                /* translators: %s: value preview. */
                __('"%s" is not a valid http(s) URL.', 'post-press-api'),
                ValueInspector::preview($text, 80)
            ));
        }

        return $clean;
    }

    /**
     * Validates an email address.
     *
     * @param mixed $value Value.
     *
     * @return string
     */
    public static function to_email(mixed $value): string
    {
        $text = trim(self::scalar_to_string($value));

        if ($text === '' || !is_email($text)) {
            throw new MappingError(sprintf(
                /* translators: %s: value preview. */
                __('"%s" is not a valid email address.', 'post-press-api'),
                ValueInspector::preview($text, 80)
            ));
        }

        return sanitize_email($text);
    }

    /**
     * Joins a list of scalars. Null items are skipped; nested arrays are rejected.
     *
     * @param mixed  $value     List or scalar.
     * @param string $separator Separator (already decoded).
     *
     * @return string
     */
    public static function join(mixed $value, string $separator): string
    {
        $items = is_array($value) ? $value : [$value];
        $parts = [];

        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }
            if (is_array($item)) {
                throw new MappingError(__('Join can only combine text, numbers, and booleans; this list contains nested arrays or objects. Select a field inside each item, for example items[*].name.', 'post-press-api'));
            }
            $parts[] = self::to_display_string($item);
        }

        return implode($separator, $parts);
    }

    /**
     * Decodes the escape sequences \n, \t, \r, and \\ in a separator.
     *
     * @param string $separator Stored separator.
     *
     * @return string
     */
    public static function decode_separator(string $separator): string
    {
        return strtr($separator, ['\\\\' => '\\', '\\n' => "\n", '\\t' => "\t", '\\r' => "\r"]);
    }

    /**
     * JSON-encodes any value.
     *
     * @param mixed $value Value.
     *
     * @return string
     */
    public static function to_json(mixed $value): string
    {
        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($encoded)) {
            throw new MappingError(__('The value could not be JSON encoded.', 'post-press-api'));
        }

        return $encoded;
    }

    /**
     * Parses a date/time and attaches the configured output preferences.
     *
     * @param mixed                $value   Value.
     * @param array<string, mixed> $options Row options.
     * @param \DateTimeZone        $site_tz Site time zone.
     *
     * @return DateValue
     */
    public static function to_datetime(mixed $value, array $options, \DateTimeZone $site_tz): DateValue
    {
        $input_tz  = self::option_timezone($options, 'input_timezone') ?? $site_tz;
        $output_tz = self::option_timezone($options, 'output_timezone');
        $format    = trim((string) ($options['input_format'] ?? ''));
        $output    = trim((string) ($options['output_format'] ?? ''));

        return DateParser::parse($value, $format === '' ? null : $format, $input_tz)
            ->with_output($output === '' ? null : $output, $output_tz);
    }

    /**
     * Reads a validated time zone option.
     *
     * @param array<string, mixed> $options Row options.
     * @param string               $key     Option key.
     *
     * @return \DateTimeZone|null
     */
    private static function option_timezone(array $options, string $key): ?\DateTimeZone
    {
        $name = trim((string) ($options[$key] ?? ''));
        if ($name === '') {
            return null;
        }

        $timezone = DateParser::timezone($name);
        if ($timezone === null) {
            throw new MappingError(sprintf(
                /* translators: %s: time zone name. */
                __('"%s" is not a valid IANA time zone.', 'post-press-api'),
                $name
            ));
        }

        return $timezone;
    }

    /**
     * Converts int/float/string to string; rejects everything else.
     *
     * @param mixed $value Value.
     *
     * @return string
     */
    private static function scalar_to_string(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof DateValue) {
            throw new MappingError(__('A parsed date cannot be used here without a destination format.', 'post-press-api'));
        }

        throw new MappingError(sprintf(
            /* translators: %s: value type. */
            __('Expected a single text, number, or boolean value but received %s. Choose the Join or JSON transformation, or select one item, for example path[0].', 'post-press-api'),
            ValueInspector::type_label($value)
        ));
    }
}
