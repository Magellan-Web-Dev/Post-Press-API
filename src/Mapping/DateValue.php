<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * A parsed date/time that still knows which parts the source actually provided.
 *
 * Destinations format it into their own storage format. Values with a time are
 * converted to the output time zone; date-only values are never shifted, so
 * "2026-11-05" stays the 5th regardless of time zones.
 */
final class DateValue
{
    /**
     * @param \DateTimeImmutable  $datetime        Parsed moment (in the input or embedded time zone).
     * @param bool                $has_date        Whether the source contained a calendar date.
     * @param bool                $has_time        Whether the source contained a time of day.
     * @param bool                $has_offset      Whether the source carried its own UTC offset or zone.
     * @param string|null         $output_format   Explicit output format from the mapping, if any.
     * @param \DateTimeZone|null  $output_timezone Explicit output time zone from the mapping, if any.
     */
    public function __construct(
        public readonly \DateTimeImmutable $datetime,
        public readonly bool $has_date,
        public readonly bool $has_time,
        public readonly bool $has_offset,
        public readonly ?string $output_format = null,
        public readonly ?\DateTimeZone $output_timezone = null
    ) {}

    /**
     * Returns a copy carrying output preferences.
     *
     * @param string|null        $format   Output format.
     * @param \DateTimeZone|null $timezone Output time zone.
     *
     * @return self
     */
    public function with_output(?string $format, ?\DateTimeZone $timezone): self
    {
        return new self($this->datetime, $this->has_date, $this->has_time, $this->has_offset, $format, $timezone);
    }

    /**
     * The moment expressed in the output time zone (or unchanged for date-only values).
     *
     * @param \DateTimeZone $default_timezone Used when no explicit output time zone was configured.
     *
     * @return \DateTimeImmutable
     */
    public function in_output_timezone(\DateTimeZone $default_timezone): \DateTimeImmutable
    {
        if (!$this->has_time) {
            return $this->datetime;
        }

        return $this->datetime->setTimezone($this->output_timezone ?? $default_timezone);
    }

    /**
     * Formats the value.
     *
     * @param string        $format           PHP date format.
     * @param \DateTimeZone $default_timezone Used when no explicit output time zone was configured.
     *
     * @return string
     */
    public function format(string $format, \DateTimeZone $default_timezone): string
    {
        return $this->in_output_timezone($default_timezone)->format($format);
    }
}
