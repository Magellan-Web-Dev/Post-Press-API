<?php

declare(strict_types=1);

namespace PostPressAPI\Support;

if (!defined('ABSPATH')) exit;

/**
 * Removes credentials from text before it is displayed, stored in history, or logged.
 */
final class Redactor
{
    /** @var string Replacement for redacted values. */
    public const MASK = '[redacted]';

    /** @var list<string> Context keys whose values are always removed. */
    private const SENSITIVE_KEYS = ['secret', 'token', 'password', 'authorization', 'api_key', 'apikey', 'credential', 'cookie', 'auth'];

    /**
     * Known secret values to mask.
     *
     * @var list<string>
     */
    private array $secrets = [];

    /**
     * @param list<string> $secrets Secret values to mask.
     */
    public function __construct(array $secrets = [])
    {
        foreach ($secrets as $secret) {
            $this->add_secret($secret);
        }
    }

    /**
     * Registers a secret value. Very short values are ignored to avoid masking ordinary text.
     *
     * @param string|null $secret Secret.
     *
     * @return void
     */
    public function add_secret(?string $secret): void
    {
        if ($secret === null || strlen($secret) < 4) {
            return;
        }

        $this->secrets[] = $secret;
        $encoded         = rawurlencode($secret);
        if ($encoded !== $secret) {
            $this->secrets[] = $encoded;
        }
    }

    /**
     * Masks known secrets in text.
     *
     * @param string $text Text.
     *
     * @return string
     */
    public function text(string $text): string
    {
        return $this->secrets === [] ? $text : str_replace($this->secrets, self::MASK, $text);
    }

    /**
     * Reduces a URL to scheme, host, port, and path (no credentials, query, or fragment).
     *
     * @param string $url URL.
     *
     * @return string
     */
    public static function url(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $out = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '') . $parts['host'];
        if (isset($parts['port'])) {
            $out .= ':' . (int) $parts['port'];
        }

        return $out . ($parts['path'] ?? '');
    }

    /**
     * Sanitizes a log/history context array: drops sensitive keys, bounds strings,
     * masks known secrets, and reduces URLs.
     *
     * @param array<string, mixed> $context Context.
     * @param int                  $depth   Recursion depth.
     *
     * @return array<string, mixed>
     */
    public function context(array $context, int $depth = 0): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            foreach (self::SENSITIVE_KEYS as $sensitive) {
                if (str_contains($lower, $sensitive)) {
                    continue 2;
                }
            }

            if (is_array($value)) {
                $clean[$key] = $depth < 2 ? $this->context($value, $depth + 1) : '[…]';
            } elseif (is_string($value)) {
                $text = $lower === 'url' || str_ends_with($lower, '_url') ? self::url($value) : $this->text($value);
                $clean[$key] = strlen($text) > 300 ? substr($text, 0, 300) . '…' : $text;
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
