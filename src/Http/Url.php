<?php

declare(strict_types=1);

namespace PostPressAPI\Http;

if (!defined('ABSPATH')) exit;

/**
 * URL helpers for outbound requests.
 */
final class Url
{
    /**
     * Normalized origin (scheme://host:port) of an http(s) URL.
     *
     * @param string $url URL.
     *
     * @return string|null Null when the URL is not an absolute http(s) URL.
     */
    public static function origin(string $url): ?string
    {
        $parts = wp_parse_url(trim($url));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . strtolower((string) $parts['host']) . ':' . $port;
    }

    /**
     * Whether a URL is an absolute http(s) URL without embedded credentials.
     *
     * @param string $url URL.
     *
     * @return bool
     */
    public static function is_valid_endpoint(string $url): bool
    {
        $parts = wp_parse_url(trim($url));

        return self::origin($url) !== null
            && is_array($parts)
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && filter_var(trim($url), FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Resolves a redirect Location against the URL that returned it.
     *
     * @param string $base     Request URL.
     * @param string $location Location header.
     *
     * @return string|null
     */
    public static function resolve(string $base, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        $resolved = class_exists('WP_Http') ? \WP_Http::make_absolute_url($location, $base) : $location;

        return self::origin($resolved) === null ? null : $resolved;
    }

    /**
     * Sets query parameters on a URL, replacing existing parameters with the same names.
     *
     * Existing parameters are parsed without PHP's parse_str(), which would
     * rewrite dots and spaces in names.
     *
     * @param string                         $url    URL.
     * @param list<array{0: string, 1: string}> $params Name/value pairs (names may repeat).
     *
     * @return string
     */
    public static function with_query(string $url, array $params): string
    {
        if ($params === []) {
            return $url;
        }

        $fragment = '';
        $hash     = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url      = substr($url, 0, $hash);
        }

        $query    = '';
        $question = strpos($url, '?');
        if ($question !== false) {
            $query = substr($url, $question + 1);
            $url   = substr($url, 0, $question);
        }

        $replace = [];
        foreach ($params as [$name]) {
            $replace[$name] = true;
        }

        $pairs = [];
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = urldecode(explode('=', $pair, 2)[0]);
            if (!isset($replace[$name])) {
                $pairs[] = $pair;
            }
        }

        foreach ($params as [$name, $value]) {
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        return $url . '?' . implode('&', $pairs) . $fragment;
    }

    /**
     * Names of the query parameters already present in a URL.
     *
     * @param string $url URL.
     *
     * @return list<string>
     */
    public static function query_names(string $url): array
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        $names = [];

        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            if ($pair !== '') {
                $names[] = urldecode(explode('=', $pair, 2)[0]);
            }
        }

        return $names;
    }
}
