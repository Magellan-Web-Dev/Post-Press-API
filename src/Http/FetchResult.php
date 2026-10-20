<?php

declare(strict_types=1);

namespace PostPressAPI\Http;

if (!defined('ABSPATH')) exit;

/**
 * Outcome of one API fetch (after following allowed redirects).
 *
 * Messages, snippets, and URLs are already redacted: no credentials, no query strings.
 */
final class FetchResult
{
    /**
     * @param bool        $ok           Whether a 2xx JSON response was decoded.
     * @param int         $status       HTTP status (0 when no response was received).
     * @param mixed       $data         Decoded JSON (assoc arrays; big integers as strings).
     * @param string      $error_code   Machine-readable error code ('' when ok).
     * @param string      $error        Human-readable error ('' when ok).
     * @param bool        $retryable    Whether retrying later may succeed.
     * @param int|null    $retry_after  Seconds the server asked us to wait, if any.
     * @param int         $bytes        Response body size.
     * @param int         $duration_ms  Total request time.
     * @param string      $content_type Response Content-Type.
     * @param string      $snippet      Bounded, redacted start of the body (for error display).
     * @param string      $url          Final URL without query string.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly mixed $data,
        public readonly string $error_code,
        public readonly string $error,
        public readonly bool $retryable,
        public readonly ?int $retry_after,
        public readonly int $bytes,
        public readonly int $duration_ms,
        public readonly string $content_type,
        public readonly string $snippet,
        public readonly string $url
    ) {}

    /**
     * Successful response.
     */
    public static function success(int $status, mixed $data, int $bytes, int $duration_ms, string $content_type, string $snippet, string $url): self
    {
        return new self(true, $status, $data, '', '', false, null, $bytes, $duration_ms, $content_type, $snippet, $url);
    }

    /**
     * Failed fetch.
     */
    public static function failure(string $code, string $message, bool $retryable, int $status = 0, ?int $retry_after = null, int $bytes = 0, int $duration_ms = 0, string $content_type = '', string $snippet = '', string $url = ''): self
    {
        return new self(false, $status, null, $code, $message, $retryable, $retry_after, $bytes, $duration_ms, $content_type, $snippet, $url);
    }

    /**
     * Summary safe for REST responses and history.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'ok'           => $this->ok,
            'status'       => $this->status,
            'error_code'   => $this->error_code,
            'error'        => $this->error,
            'retryable'    => $this->retryable,
            'retry_after'  => $this->retry_after,
            'bytes'        => $this->bytes,
            'duration_ms'  => $this->duration_ms,
            'content_type' => $this->content_type,
            'url'          => $this->url,
        ];
    }
}
