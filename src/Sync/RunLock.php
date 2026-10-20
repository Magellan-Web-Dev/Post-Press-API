<?php

declare(strict_types=1);

namespace PostPressAPI\Sync;

use PostPressAPI\Support\Clock;

if (!defined('ABSPATH')) exit;

/**
 * Per-preset mutual exclusion for run steps (manual and scheduled alike).
 *
 * Acquisition is atomic: the lock row is created with INSERT IGNORE, the same
 * technique WordPress core uses for its upgrader lock, so exactly one process
 * succeeds. Each lock stores "token|expiry". An expired lock (a process that
 * died mid-step) is taken over with a compare-and-swap UPDATE that only
 * succeeds if the row still holds the stale value, so two processes cannot
 * both recover the same stale lock. Release deletes the row only if it still
 * holds the caller's token.
 *
 * Steps are time-bounded well below the TTL, so a live lock never expires
 * while its owner is working.
 */
final class RunLock
{
    public const PREFIX = 'ppapi_lock_';

    /** @var int Lock lifetime in seconds. */
    public const TTL = 300;

    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * Tries to acquire the lock.
     *
     * @param string $preset_id Preset ID.
     * @param int    $ttl       Lifetime in seconds.
     *
     * @return string|null Token on success, null when another process holds it.
     */
    public function acquire(string $preset_id, int $ttl = self::TTL): ?string
    {
        global $wpdb;

        $name  = self::PREFIX . $preset_id;
        $now   = $this->clock->now();
        $token = bin2hex(random_bytes(8));
        $value = $token . '|' . ($now + $ttl);

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $name,
            $value
        ));
        $this->flush_cache($name);

        if ((int) $inserted === 1) {
            return $token;
        }

        $current = $this->read($name);
        if ($current === null) {
            // Released between our INSERT and SELECT; try once more.
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $name,
                $value
            ));
            $this->flush_cache($name);
            return (int) $inserted === 1 ? $token : null;
        }

        if (self::expiry($current) > $now) {
            return null;
        }

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $value,
            $name,
            $current
        ));
        $this->flush_cache($name);

        return (int) $updated === 1 ? $token : null;
    }

    /**
     * Releases a lock held with $token.
     *
     * @param string $preset_id Preset ID.
     * @param string $token     Token returned by acquire().
     *
     * @return void
     */
    public function release(string $preset_id, string $token): void
    {
        global $wpdb;

        $name = self::PREFIX . $preset_id;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
            $name,
            $wpdb->esc_like($token . '|') . '%'
        ));
        $this->flush_cache($name);
    }

    /**
     * Whether an unexpired lock exists.
     *
     * @param string $preset_id Preset ID.
     *
     * @return bool
     */
    public function is_locked(string $preset_id): bool
    {
        $current = $this->read(self::PREFIX . $preset_id);

        return $current !== null && self::expiry($current) > $this->clock->now();
    }

    /**
     * Removes the lock regardless of owner (used when a preset is deleted).
     *
     * @param string $preset_id Preset ID.
     *
     * @return void
     */
    public function clear(string $preset_id): void
    {
        global $wpdb;

        $name = self::PREFIX . $preset_id;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $name));
        $this->flush_cache($name);
    }

    /**
     * Reads the raw lock value directly from the database (bypassing caches).
     *
     * @param string $name Option name.
     *
     * @return string|null
     */
    private function read(string $name): ?string
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));

        return is_string($value) ? $value : null;
    }

    /**
     * Expiry timestamp from a lock value.
     *
     * @param string $value "token|expiry".
     *
     * @return int
     */
    private static function expiry(string $value): int
    {
        $parts = explode('|', $value, 2);

        return isset($parts[1]) ? (int) $parts[1] : 0;
    }

    /**
     * Keeps the options cache from serving a stale lock value.
     *
     * @param string $name Option name.
     *
     * @return void
     */
    private function flush_cache(string $name): void
    {
        wp_cache_delete($name, 'options');

        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && isset($notoptions[$name])) {
            unset($notoptions[$name]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }
    }
}
