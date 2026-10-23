<?php
/**
 * Fake $wpdb backed by WPStub::$options, understanding only the statements
 * Post Press API issues against the options table (RunLock and uninstall.php).
 * Statement semantics match MySQL: INSERT IGNORE affects 0 rows when the key
 * exists; UPDATE/DELETE affect only rows matching every condition.
 */

declare(strict_types=1);

final class FakeWpdb
{
    public string $options = 'wp_options';
    public string $postmeta = 'wp_postmeta';
    /** @var list<string> */
    public array $queries = [];
    public $before_update = null;

    public function prepare(string $query, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $index = 0;
        return (string) preg_replace_callback('/%[sd]/', static function (array $m) use (&$index, $args): string {
            $value = $args[$index++] ?? '';
            return $m[0] === '%d' ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
        }, $query);
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /** Converts a SQL LIKE pattern (with backslash escapes) to a regex. */
    public static function like_to_regex(string $like): string
    {
        $regex  = '';
        $length = strlen($like);
        for ($i = 0; $i < $length; $i++) {
            $char = $like[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $regex .= preg_quote($like[++$i], '/');
            } elseif ($char === '%') {
                $regex .= '.*';
            } elseif ($char === '_') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($char, '/');
            }
        }
        return '/^' . $regex . '$/s';
    }

    /** @return list<string> Quoted literals in order. */
    private function literals(string $sql): array
    {
        $values = [];
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            if ($sql[$i] !== "'") { continue; }
            $value = '';
            for ($i++; $i < $length; $i++) {
                if ($sql[$i] === "\\" && $i + 1 < $length) {
                    $value .= $sql[$i] . $sql[++$i];
                } elseif ($sql[$i] === "'") {
                    break;
                } else {
                    $value .= $sql[$i];
                }
            }
            $values[] = stripslashes($value);
        }
        return $values;
    }

    public function query(string $sql): int|false
    {
        $this->queries[] = $sql;
        $values = $this->literals($sql);

        if (str_starts_with($sql, 'INSERT IGNORE INTO')) {
            [$name, $value, $autoload] = $values;
            if (array_key_exists($name, WPStub::$options)) {
                return 0;
            }
            WPStub::$options[$name] = ['value' => maybe_unserialize($value), 'autoload' => $autoload];
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE')) {
            if ($this->before_update) { $callback = $this->before_update; $this->before_update = null; $callback(); }
            [$new, $name, $old] = $values;
            if (array_key_exists($name, WPStub::$options) && maybe_serialize(WPStub::$options[$name]['value']) === $old) {
                WPStub::$options[$name]['value'] = maybe_unserialize($new);
                return 1;
            }
            return 0;
        }

        if (str_starts_with($sql, 'DELETE')) {
            $name = $values[0];
            if (!array_key_exists($name, WPStub::$options)) {
                return 0;
            }
            if (isset($values[1]) && !preg_match(self::like_to_regex($values[1]), (string) WPStub::$options[$name]['value'])) {
                return 0;
            }
            unset(WPStub::$options[$name]);
            return 1;
        }

        throw new RuntimeException('FakeWpdb cannot run: ' . $sql);
    }

    public function get_var(string $sql): ?string
    {
        $this->queries[] = $sql;
        $name = $this->literals($sql)[0] ?? '';
        return array_key_exists($name, WPStub::$options) ? (string) maybe_serialize(WPStub::$options[$name]['value']) : null;
    }

    /** @return list<string> */
    public function get_col(string $sql): array
    {
        $this->queries[] = $sql;
        $patterns = array_map([self::class, 'like_to_regex'], $this->literals($sql));

        $names = [];
        foreach (array_keys(WPStub::$options) as $name) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $name)) {
                    $names[] = $name;
                    break;
                }
            }
        }
        return $names;
    }
}
