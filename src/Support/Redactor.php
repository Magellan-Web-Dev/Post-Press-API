<?php

declare(strict_types=1);

namespace PostPressAPI\Support;

if (!defined('ABSPATH')) exit;

/**
 * Removes credentials from text before it is displayed, stored in history, or logged.
 *
 * Each known secret is also masked in the forms it commonly takes inside
 * diagnostics: URL-encoded, JSON-escaped, HTML-escaped, and slash-escaped. A
 * secret cut short by a preview ("sk_live_abc…") is masked too, so truncation
 * that happened before redaction cannot leave a partial credential visible.
 * Code that cuts text itself should use excerpt(), which masks before cutting.
 *
 * Redaction fails closed: if masking itself fails, the whole text is replaced.
 */
final class Redactor
{
    /** @var string Replacement for redacted values. */
    public const MASK = '[redacted]';

    /** @var int Shortest secret (or truncated secret prefix) that is masked. */
    private const MIN_LENGTH = 4;

    /** @var string Marker that previews append to truncated values. */
    private const ELLIPSIS = '…';

    /** @var list<string> Context keys whose values are always removed. */
    private const SENSITIVE_KEYS = ['secret', 'token', 'password', 'authorization', 'api_key', 'apikey', 'credential', 'cookie', 'auth'];

    /** @var list<string> Header and query parameter names whose values are treated as credentials. */
    private const SENSITIVE_NAMES = ['secret', 'token', 'password', 'passwd', 'auth', 'key', 'credential', 'cookie', 'session', 'signature', 'sig'];

    /**
     * Known secret values (and their encoded forms) to mask, longest first.
     *
     * @var list<string>
     */
    private array $secrets = [];

    /**
     * @param list<string|null> $secrets Secret values to mask.
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
        if ($secret === null || strlen($secret) < self::MIN_LENGTH) {
            return;
        }

        foreach (self::variants($secret) as $variant) {
            if (strlen($variant) >= self::MIN_LENGTH && !in_array($variant, $this->secrets, true)) {
                $this->secrets[] = $variant;
            }
        }

        // Longer forms first, so an encoded form is never left half-masked by a shorter one.
        usort($this->secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    /**
     * Registers the credential-like values typed into a preset's request: values
     * of headers and query parameters whose names suggest a key, token, or secret.
     *
     * @param array<string, mixed> $preset Preset.
     *
     * @return void
     */
    public function add_preset(array $preset): void
    {
        foreach (self::preset_values($preset) as $value) {
            $this->add_secret($value);
        }
    }

    /**
     * Credential-like values typed into a preset's headers and query parameters.
     *
     * @param array<string, mixed> $preset Preset.
     *
     * @return list<string>
     */
    public static function preset_values(array $preset): array
    {
        $request = is_array($preset['request'] ?? null) ? $preset['request'] : [];
        $values  = [];

        foreach ([['headers', 'name'], ['query', 'key']] as [$list, $name_key]) {
            foreach ((array) ($request[$list] ?? []) as $row) {
                if (!is_array($row) || !is_string($row[$name_key] ?? null) || !is_string($row['value'] ?? null)) {
                    continue;
                }
                $name = strtolower($row[$name_key]);
                foreach (self::SENSITIVE_NAMES as $sensitive) {
                    if (str_contains($name, $sensitive)) {
                        $values[] = $row['value'];
                        break;
                    }
                }
            }
        }

        return $values;
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
        if ($this->secrets === [] || $text === '') {
            return $text;
        }

        try {
            return $this->mask_truncated(str_replace($this->secrets, self::MASK, $text));
        } catch (\Throwable) {
            // Never fall back to the unmasked text.
            return self::MASK;
        }
    }

    /**
     * Masked start of a text, at most $length bytes, for display.
     *
     * Masking happens before the cut, so a credential crossing it is never left
     * partly visible. Only the first $length bytes plus the longest secret form
     * are examined (enough to see any secret that starts inside the excerpt
     * whole), so the cost stays bounded however large the text is.
     *
     * @param string $text   Text.
     * @param int    $length Maximum bytes returned.
     *
     * @return string
     */
    public function excerpt(string $text, int $length): string
    {
        $window = substr($text, 0, $length + strlen($this->secrets[0] ?? ''));
        if (strlen($window) < strlen($text)) {
            // Earlier masks shorten the text and can pull a secret cut by the
            // window into the excerpt; the marker makes text() mask its prefix.
            $window .= self::ELLIPSIS;
        }

        return substr($this->text($window), 0, $length);
    }

    /**
     * Masks known secrets in every string of a (nested) array, keeping its shape.
     *
     * @param array<mixed> $data Data.
     *
     * @return array<mixed>
     */
    public function data(array $data): array
    {
        if ($this->secrets === []) {
            return $data;
        }

        array_walk_recursive($data, function (&$value): void {
            if (is_string($value)) {
                $value = $this->text($value);
            }
        });

        return $data;
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
                $text = $lower === 'url' || str_ends_with($lower, '_url') ? $this->text(self::url($value)) : $this->text($value);
                $clean[$key] = strlen($text) > 300 ? substr($text, 0, 300) . '…' : $text;
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Forms a secret commonly takes inside diagnostics.
     *
     * @param string $secret Secret.
     *
     * @return list<string>
     */
    private static function variants(string $secret): array
    {
        $variants = [$secret, rawurlencode($secret), urlencode($secret), addslashes($secret)];

        if (function_exists('mb_check_encoding') ? mb_check_encoding($secret, 'UTF-8') : true) {
            $variants[] = htmlspecialchars($secret, ENT_QUOTES, 'UTF-8');
            foreach ([0, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT] as $flags) {
                $json = json_encode($secret, $flags);
                if (is_string($json) && strlen($json) >= 2) {
                    $variants[] = substr($json, 1, -1);
                }
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * Masks a truncated secret prefix immediately followed by an ellipsis.
     *
     * @param string $text Text with full secrets already masked.
     *
     * @return string
     */
    private function mask_truncated(string $text): string
    {
        $offset = 0;
        while (($pos = strpos($text, self::ELLIPSIS, $offset)) !== false) {
            $cut = 0;
            foreach ($this->secrets as $secret) {
                for ($length = min($pos, strlen($secret) - 1); $length >= self::MIN_LENGTH && $length > $cut; $length--) {
                    if (substr_compare($text, substr($secret, 0, $length), $pos - $length, $length) === 0) {
                        $cut = $length;
                        break;
                    }
                }
            }

            if ($cut > 0) {
                $text = substr($text, 0, $pos - $cut) . self::MASK . substr($text, $pos);
                $pos  = $pos - $cut + strlen(self::MASK);
            }
            $offset = $pos + strlen(self::ELLIPSIS);
        }

        return $text;
    }
}
