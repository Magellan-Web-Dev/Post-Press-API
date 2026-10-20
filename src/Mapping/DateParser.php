<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * Parses source date/time values without guessing ambiguous time zones.
 *
 * Accepted without an explicit input format:
 *  - ISO 8601 / RFC 3339 strings, with or without an offset (2026-10-18T20:05:37+00:00, …Z)
 *  - Date-only strings such as 2026-11-05, 20261105, or "Nov 5, 2026"
 *  - Time-only strings such as 12:00, 12:00:00, or 12:00 PM
 *  - Unix timestamps (10-digit seconds or 13-digit milliseconds)
 *
 * Values without an offset are interpreted in the configured input time zone
 * (the site time zone by default). Zone names PHP cannot resolve (e.g. "Eastern")
 * and ambiguous abbreviations (e.g. "CST") are rejected: map them to an IANA
 * time zone such as America/New_York with the Date/time transformation instead.
 * Relative phrases such as "tomorrow" are rejected.
 */
final class DateParser
{
    /** @var list<string> Zone abbreviations that are unambiguous. */
    private const SAFE_ABBREVIATIONS = ['Z', 'UTC', 'GMT'];

    /**
     * Parses a value.
     *
     * @param mixed              $value    Source value.
     * @param string|null        $format   Optional PHP date format the source must match exactly.
     * @param \DateTimeZone      $input_tz Time zone for values that do not carry their own.
     *
     * @return DateValue
     *
     * @throws MappingError When the value cannot be parsed unambiguously.
     */
    public static function parse(mixed $value, ?string $format, \DateTimeZone $input_tz): DateValue
    {
        if ($value instanceof DateValue) {
            return $value;
        }

        if ($format !== null && $format !== '') {
            return self::parse_with_format($value, $format, $input_tz);
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (is_float($value) || (is_string($value) && preg_match('/^\d{10}(\d{3})?$/', trim($value)))) {
            return self::parse_timestamp($value);
        }

        if (!is_string($value)) {
            throw new MappingError(sprintf(
                /* translators: %s: value type. */
                __('Expected a date/time string but received %s.', 'post-press-api'),
                ValueInspector::type_label($value)
            ));
        }

        $text = trim($value);
        if ($text === '') {
            throw new MappingError(__('The date/time value is an empty string.', 'post-press-api'));
        }

        if (preg_match('/^\d{8}$/', $text)) {
            return self::parse_with_format($text, 'Ymd', $input_tz);
        }

        $parsed = date_parse($text);
        if (!is_array($parsed) || $parsed['error_count'] > 0 || $parsed['warning_count'] > 0) {
            throw new MappingError(sprintf(
                /* translators: %s: source value. */
                __('Could not read "%s" as a date/time. Configure an input format, and map named time zones to an IANA zone such as America/New_York.', 'post-press-api'),
                ValueInspector::preview($text, 60)
            ));
        }

        if (isset($parsed['relative'])) {
            throw new MappingError(sprintf(
                /* translators: %s: source value. */
                __('Relative dates such as "%s" are not supported.', 'post-press-api'),
                ValueInspector::preview($text, 60)
            ));
        }

        $has_date   = $parsed['year'] !== false && $parsed['month'] !== false && $parsed['day'] !== false;
        $has_time   = $parsed['hour'] !== false;
        $zone_type  = (int) ($parsed['zone_type'] ?? 0);
        $has_offset = $zone_type > 0;

        if (!$has_date && !$has_time) {
            throw new MappingError(sprintf(
                /* translators: %s: source value. */
                __('"%s" does not contain a date or time.', 'post-press-api'),
                ValueInspector::preview($text, 60)
            ));
        }

        if ($zone_type === 2 && !in_array(strtoupper((string) ($parsed['tz_abbr'] ?? '')), self::SAFE_ABBREVIATIONS, true)) {
            throw new MappingError(sprintf(
                /* translators: %s: time zone abbreviation. */
                __('The time zone abbreviation "%s" is ambiguous. Use an input format and an explicit IANA input time zone instead.', 'post-press-api'),
                (string) ($parsed['tz_abbr'] ?? '')
            ));
        }

        try {
            $datetime = new \DateTimeImmutable($text, $input_tz);
        } catch (\Exception) {
            throw new MappingError(sprintf(
                /* translators: %s: source value. */
                __('Could not read "%s" as a date/time.', 'post-press-api'),
                ValueInspector::preview($text, 60)
            ));
        }

        return new DateValue($datetime, $has_date, $has_time, $has_offset);
    }

    /**
     * Validates an IANA time zone identifier.
     *
     * @param string $name Time zone name.
     *
     * @return \DateTimeZone|null Null when invalid.
     */
    public static function timezone(string $name): ?\DateTimeZone
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        if (strtoupper($name) !== 'UTC' && !in_array($name, \DateTimeZone::listIdentifiers(), true)) {
            return null;
        }

        try {
            return new \DateTimeZone($name);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Parses using an explicit format.
     *
     * @param mixed         $value    Source value.
     * @param string        $format   PHP date format.
     * @param \DateTimeZone $input_tz Input time zone.
     *
     * @return DateValue
     */
    private static function parse_with_format(mixed $value, string $format, \DateTimeZone $input_tz): DateValue
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (!is_string($value) || trim($value) === '') {
            throw new MappingError(sprintf(
                /* translators: %s: value type. */
                __('Expected a date/time string but received %s.', 'post-press-api'),
                ValueInspector::type_label($value)
            ));
        }

        $datetime = \DateTimeImmutable::createFromFormat('!' . $format, trim($value), $input_tz);
        $errors   = \DateTimeImmutable::getLastErrors();

        if ($datetime === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new MappingError(sprintf(
                /* translators: 1: source value, 2: expected format. */
                __('"%1$s" does not match the date format "%2$s".', 'post-press-api'),
                ValueInspector::preview($value, 60),
                $format
            ));
        }

        $has_offset = strpbrk($format, 'eOPpTU') !== false;
        $has_time   = strpbrk($format, 'aAgGhHisuvU') !== false;
        $has_date   = strpbrk($format, 'dDjlNSwzWFmMntLoYyU') !== false;

        return new DateValue($datetime, $has_date, $has_time, $has_offset);
    }

    /**
     * Parses a Unix timestamp in seconds or milliseconds.
     *
     * @param float|string $value Timestamp (strings are 10 or 13 digits).
     *
     * @return DateValue
     */
    private static function parse_timestamp(float|string $value): DateValue
    {
        if (is_float($value)) {
            if (!is_finite($value) || abs($value) > 1.0e11) {
                throw new MappingError(__('The numeric timestamp is out of range.', 'post-press-api'));
            }
            $seconds = (int) floor($value);
        } else {
            $text    = trim($value);
            $seconds = strlen($text) === 13 ? intdiv((int) $text, 1000) : (int) $text;
        }

        $datetime = (new \DateTimeImmutable('@' . $seconds))->setTimezone(new \DateTimeZone('UTC'));

        return new DateValue($datetime, true, true, true);
    }
}
