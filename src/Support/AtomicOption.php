<?php

declare(strict_types=1);

namespace PostPressAPI\Support;

if (!defined('ABSPATH')) exit;

/**
 * Compare-and-swap updates for small non-autoloaded arrays shared by requests.
 *
 * Reads bypass the object cache and writes compare the exact serialized value.
 * A callback may be retried after contention, so it must not perform side effects.
 */
final class AtomicOption
{
    /**
     * Applies a change without overwriting updates from another request.
     *
     * @param string                    $name   Option name.
     * @param callable(array): array    $change Pure transformation of the stored array.
     *
     * @return array<mixed> Successfully stored value.
     *
     * @throws \RuntimeException When the database fails or contention persists.
     * @throws \LogicException When the callback returns a non-array value.
     */
    public static function update(string $name, callable $change): array
    {
        global $wpdb;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
            $old = $raw === null ? [] : maybe_unserialize($raw);
            $next = $change(is_array($old) ? $old : []);
            if (!is_array($next)) {
                throw new \LogicException('Atomic option callbacks must return an array.');
            }
            $encoded = maybe_serialize($next);
            if ($raw === null) {
                $changed = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $encoded));
            } elseif ($encoded === $raw) {
                return $next;
            } else {
                $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", $encoded, $name, $raw));
            }
            self::flush($name);
            if ($changed === 1) {
                return $next;
            }
            if ($changed === false) {
                break;
            }
        }
        throw new \RuntimeException(__('History changed in another request or could not be saved. Please try again.', 'post-press-api'));
    }

    /**
     * Invalidates caches affected by a direct SQL option write.
     *
     * @param string $name Option name.
     *
     * @return void
     */
    private static function flush(string $name): void
    {
        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
