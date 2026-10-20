<?php

declare(strict_types=1);

namespace PostPressAPI\Sync;

if (!defined('ABSPATH')) exit;

/**
 * Compares what was written with what is stored.
 *
 * update_post_meta() and update_field() return false both when a write fails
 * and when the stored value already equals the new one. Reading the value back
 * and comparing it here tells the two cases apart.
 *
 * Scalars are compared in their stored string form (booleans as 1/0, null as an
 * empty string). Lists must match item for item; associative arrays (ACF groups,
 * link arrays) only need to contain the keys that were written.
 */
final class ValueComparator
{
    /**
     * Whether a stored value matches the expected value.
     *
     * @param mixed $stored   Value read back from the database.
     * @param mixed $expected Value that was written.
     *
     * @return bool
     */
    public static function equivalent(mixed $stored, mixed $expected): bool
    {
        if (is_array($expected)) {
            if (!is_array($stored)) {
                return $expected === [] && self::is_empty($stored);
            }

            if (array_is_list($expected)) {
                $stored = array_values($stored);
                if (count($stored) !== count($expected)) {
                    return false;
                }
                foreach ($expected as $index => $item) {
                    if (!self::equivalent($stored[$index], $item)) {
                        return false;
                    }
                }
                return true;
            }

            foreach ($expected as $key => $item) {
                if (!array_key_exists($key, $stored)) {
                    if (self::is_empty($item)) {
                        continue;
                    }
                    return false;
                }
                if (!self::equivalent($stored[$key], $item)) {
                    return false;
                }
            }

            return true;
        }

        if (is_array($stored)) {
            return self::is_empty($expected) && self::is_empty($stored);
        }

        return self::scalar($stored) === self::scalar($expected);
    }

    /**
     * Whether a value is empty in the "nothing stored" sense (deeply).
     *
     * Deliberately stricter than empty(): "0" and 0 are values, not emptiness.
     *
     * @param mixed $value Value.
     *
     * @return bool
     */
    public static function is_empty(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === false || $value === []) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::is_empty($item)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Canonical string form of a scalar.
     *
     * @param mixed $value Value.
     *
     * @return string
     */
    private static function scalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) wp_json_encode($value);
    }
}
