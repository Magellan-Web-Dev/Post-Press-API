<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * Parses and resolves paths into decoded JSON data.
 *
 * Syntax (see README "Path syntax"):
 *   $                     the current context itself (whole response, record, or repeater item)
 *   event.title           nested object keys
 *   sessions.0.date       numeric segments address array indices (or numeric object keys)
 *   sessions[0].date      bracket indices
 *   sessions[*].date      wildcard: collects "date" from every item
 *   dates_text[*]         wildcard over a list of scalars
 *   sessions.*.date       "*" as a whole segment is also a wildcard
 *   ["key.with.dots"]     quoted keys address literal keys containing ".", "[", "]",
 *   meta['a[b]'].value    a leading "$", or a key that is exactly "*"; \" \' and \\ escape
 *   $.data / $[0]         an optional leading "$" refers to the context root explicitly
 *
 * Negative indices, slices, filters, and expressions are deliberately unsupported
 * and rejected with a {@see PathSyntaxError}.
 *
 * Wildcard results are lists of the matched values. Items where the remainder of
 * the path is missing, and matched values that are null, are left out of that list.
 * A wildcard over an empty array yields an empty list (present, not missing).
 */
final class PathResolver
{
    /** @var string Path that refers to the context root. */
    public const ROOT = '$';

    /** @var int Upper bound on accepted path length. */
    private const MAX_LENGTH = 500;

    /** @var int Upper bound on segments per path. */
    private const MAX_SEGMENTS = 32;

    /**
     * Parsed-token cache keyed by trimmed path.
     *
     * @var array<string, list<array{type: string, key?: string|int}>>
     */
    private array $cache = [];

    /**
     * Parses a path into tokens.
     *
     * @param string $path Path expression.
     *
     * @return list<array{type: string, key?: string|int}> Tokens; an empty list means the context root.
     *
     * @throws PathSyntaxError When the path is invalid.
     */
    public function parse(string $path): array
    {
        $path = trim($path);

        if (isset($this->cache[$path])) {
            return $this->cache[$path];
        }

        if ($path === '') {
            throw new PathSyntaxError(__('The path is empty. Use $ to refer to the whole value.', 'post-press-api'));
        }

        if (strlen($path) > self::MAX_LENGTH) {
            throw new PathSyntaxError(__('The path is too long.', 'post-press-api'));
        }

        $tokens = [];
        $length = strlen($path);
        $i      = 0;

        if ($path[0] === '$') {
            $i = 1;
            if ($length === 1) {
                return $this->cache[$path] = [];
            }
            if ($path[1] === '.') {
                $i = 2;
                if ($i === $length) {
                    throw new PathSyntaxError(__('The path cannot end with ".".', 'post-press-api'));
                }
            } elseif ($path[1] !== '[') {
                throw new PathSyntaxError(__('"$" must be followed by "." or "[". Quote keys that start with "$", for example ["$key"].', 'post-press-api'));
            }
        }

        while ($i < $length) {
            $char = $path[$i];

            if ($char === '[') {
                [$token, $i] = $this->parse_bracket($path, $i);
                $tokens[]    = $token;

                if ($i < $length && $path[$i] !== '.' && $path[$i] !== '[') {
                    throw new PathSyntaxError(sprintf(
                        /* translators: %s: offending character. */
                        __('Unexpected "%s" after "]". Use "." or "[" to continue the path.', 'post-press-api'),
                        $path[$i]
                    ));
                }
                if ($i < $length && $path[$i] === '.') {
                    $i = $this->consume_dot($path, $i);
                }
                continue;
            }

            if ($char === ']') {
                throw new PathSyntaxError(__('Unexpected "]" without a matching "[".', 'post-press-api'));
            }

            if ($char === '.') {
                throw new PathSyntaxError(__('The path contains an empty segment (for example ".." or a leading ".").', 'post-press-api'));
            }

            $start = $i;
            while ($i < $length && $path[$i] !== '.' && $path[$i] !== '[' && $path[$i] !== ']') {
                $i++;
            }
            if ($i < $length && $path[$i] === ']') {
                throw new PathSyntaxError(__('Unexpected "]" without a matching "[".', 'post-press-api'));
            }

            $key      = substr($path, $start, $i - $start);
            $tokens[] = $key === '*' ? ['type' => 'wildcard'] : ['type' => 'key', 'key' => $key];

            if ($i < $length && $path[$i] === '.') {
                $i = $this->consume_dot($path, $i);
            }
        }

        if (count($tokens) > self::MAX_SEGMENTS) {
            throw new PathSyntaxError(__('The path has too many segments.', 'post-press-api'));
        }

        return $this->cache[$path] = $tokens;
    }

    /**
     * Returns null when the path is valid, otherwise a human-readable error.
     *
     * @param string $path Path expression.
     *
     * @return string|null
     */
    public function validate(string $path): ?string
    {
        try {
            $this->parse($path);
            return null;
        } catch (PathSyntaxError $e) {
            return $e->getMessage();
        }
    }

    /**
     * Whether the path contains a wildcard segment.
     *
     * @param string $path Path expression (must be valid).
     *
     * @return bool
     */
    public function has_wildcard(string $path): bool
    {
        foreach ($this->parse($path) as $token) {
            if ($token['type'] === 'wildcard') {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves a path against data.
     *
     * @param mixed                                                 $data Decoded JSON data.
     * @param string|list<array{type: string, key?: string|int}>    $path Path expression or parsed tokens.
     *
     * @return PathResult
     *
     * @throws PathSyntaxError When a string path is invalid.
     */
    public function resolve(mixed $data, string|array $path): PathResult
    {
        $tokens = is_array($path) ? $path : $this->parse($path);

        foreach ($tokens as $index => $token) {
            if ($token['type'] === 'wildcard') {
                return $this->resolve_wildcard($data, $tokens, $index);
            }
        }

        return $this->resolve_direct($data, $tokens);
    }

    /**
     * Renders tokens back into canonical path syntax.
     *
     * @param list<array{type: string, key?: string|int}> $tokens Tokens.
     *
     * @return string
     */
    public function format(array $tokens): string
    {
        if ($tokens === []) {
            return self::ROOT;
        }

        $out = '';
        foreach ($tokens as $token) {
            if ($token['type'] === 'wildcard') {
                $out .= '[*]';
                continue;
            }

            $key = $token['key'];
            if (is_int($key)) {
                $out .= '[' . $key . ']';
            } elseif ($this->is_plain_key($key)) {
                $out .= ($out === '' ? '' : '.') . $key;
            } else {
                $out .= '["' . addcslashes($key, '"\\') . '"]';
            }
        }

        return $out;
    }

    /**
     * Resolves a path without wildcards.
     *
     * @param mixed                                        $value  Starting value.
     * @param list<array{type: string, key?: string|int}> $tokens Tokens.
     *
     * @return PathResult
     */
    private function resolve_direct(mixed $value, array $tokens): PathResult
    {
        $walked = [];

        foreach ($tokens as $token) {
            $where = $this->format($walked);

            if ($value === null) {
                return PathResult::null(sprintf(
                    /* translators: %s: path segment. */
                    __('"%s" is null.', 'post-press-api'),
                    $where
                ));
            }

            if (!is_array($value)) {
                return PathResult::missing(sprintf(
                    /* translators: 1: path segment, 2: value type. */
                    __('"%1$s" is %2$s, not an object or array.', 'post-press-api'),
                    $where,
                    ValueInspector::type_label($value)
                ));
            }

            $key = $token['key'];
            if (!array_key_exists($key, $value)) {
                return PathResult::missing(sprintf(
                    /* translators: 1: path segment, 2: missing key. */
                    __('"%1$s" has no key "%2$s".', 'post-press-api'),
                    $where,
                    (string) $key
                ));
            }

            $value    = $value[$key];
            $walked[] = $token;
        }

        return PathResult::present($value);
    }

    /**
     * Resolves a path whose first wildcard sits at $wildcard_index.
     *
     * @param mixed                                        $data           Starting value.
     * @param list<array{type: string, key?: string|int}> $tokens         Tokens.
     * @param int                                          $wildcard_index Index of the first wildcard token.
     *
     * @return PathResult
     */
    private function resolve_wildcard(mixed $data, array $tokens, int $wildcard_index): PathResult
    {
        $container = $this->resolve_direct($data, array_slice($tokens, 0, $wildcard_index));
        if (!$container->is_present()) {
            return $container;
        }

        $prefix = $this->format(array_slice($tokens, 0, $wildcard_index));

        if (!is_array($container->value)) {
            return PathResult::missing(sprintf(
                /* translators: 1: path segment, 2: value type. */
                __('"%1$s" is %2$s, so "[*]" has nothing to select.', 'post-press-api'),
                $prefix,
                ValueInspector::type_label($container->value)
            ));
        }

        if ($container->value === []) {
            return PathResult::present([], true);
        }

        $rest    = array_slice($tokens, $wildcard_index + 1);
        $matches = [];
        $any     = false;

        foreach ($container->value as $item) {
            if ($rest === []) {
                $any = true;
                if ($item !== null) {
                    $matches[] = $item;
                }
                continue;
            }

            $result = $this->resolve($item, $rest);
            if (!$result->is_present()) {
                continue;
            }

            $any = true;
            if ($result->multiple) {
                foreach ($result->value as $nested) {
                    $matches[] = $nested;
                }
            } else {
                $matches[] = $result->value;
            }
        }

        if (!$any) {
            return PathResult::missing(sprintf(
                /* translators: 1: array path, 2: remaining path. */
                __('No item in "%1$s" contains "%2$s".', 'post-press-api'),
                $prefix,
                $this->format($rest)
            ));
        }

        return PathResult::present($matches, true);
    }

    /**
     * Consumes a "." separator and checks that a key follows it.
     *
     * @param string $path  Full path.
     * @param int    $index Index of the dot.
     *
     * @return int Index after the dot.
     */
    private function consume_dot(string $path, int $index): int
    {
        $index++;

        if ($index >= strlen($path)) {
            throw new PathSyntaxError(__('The path cannot end with ".".', 'post-press-api'));
        }
        if ($path[$index] === '.') {
            throw new PathSyntaxError(__('The path contains an empty segment (for example "..").', 'post-press-api'));
        }
        if ($path[$index] === '[') {
            throw new PathSyntaxError(__('Write brackets directly after a key, for example sessions[0], not sessions.[0].', 'post-press-api'));
        }

        return $index;
    }

    /**
     * Parses a bracket segment starting at "[".
     *
     * @param string $path  Full path.
     * @param int    $index Index of "[".
     *
     * @return array{0: array{type: string, key?: string|int}, 1: int} Token and index after "]".
     */
    private function parse_bracket(string $path, int $index): array
    {
        $length = strlen($path);
        $index++;

        if ($index >= $length) {
            throw new PathSyntaxError(__('Unclosed "[" in path.', 'post-press-api'));
        }

        $char = $path[$index];

        if ($char === '*') {
            if (($path[$index + 1] ?? '') !== ']') {
                throw new PathSyntaxError(__('A wildcard must be written as [*].', 'post-press-api'));
            }
            return [['type' => 'wildcard'], $index + 2];
        }

        if (ctype_digit($char)) {
            $start = $index;
            while ($index < $length && ctype_digit($path[$index])) {
                $index++;
            }
            if (($path[$index] ?? '') !== ']') {
                throw new PathSyntaxError(__('Array indices must be whole numbers, for example [0].', 'post-press-api'));
            }
            $digits = substr($path, $start, $index - $start);
            if (strlen($digits) > 9) {
                throw new PathSyntaxError(__('The array index is too large.', 'post-press-api'));
            }
            return [['type' => 'key', 'key' => (int) $digits], $index + 1];
        }

        if ($char === '"' || $char === "'") {
            $quote = $char;
            $index++;
            $key   = '';
            while ($index < $length && $path[$index] !== $quote) {
                if ($path[$index] === '\\' && $index + 1 < $length) {
                    $index++;
                }
                $key .= $path[$index];
                $index++;
            }
            if ($index >= $length) {
                throw new PathSyntaxError(__('Unclosed quote in path.', 'post-press-api'));
            }
            $index++;
            if (($path[$index] ?? '') !== ']') {
                throw new PathSyntaxError(__('A quoted key must be followed by "]".', 'post-press-api'));
            }
            return [['type' => 'key', 'key' => $key], $index + 1];
        }

        if ($char === '-') {
            throw new PathSyntaxError(__('Negative array indices are not supported.', 'post-press-api'));
        }

        throw new PathSyntaxError(__('Brackets must contain an index such as [0], a wildcard [*], or a quoted key such as ["name"].', 'post-press-api'));
    }

    /**
     * Whether a key can be written without brackets.
     *
     * @param string $key Key.
     *
     * @return bool
     */
    private function is_plain_key(string $key): bool
    {
        return $key !== ''
            && $key !== '*'
            && $key[0] !== '$'
            && trim($key) === $key
            && strpbrk($key, '.[]') === false;
    }
}
