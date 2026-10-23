<?php

declare(strict_types=1);

namespace PostPressAPI\Http;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Presets\PresetSchema;
use PostPressAPI\Support\Clock;
use PostPressAPI\Support\Redactor;

/**
 * Fetches and decodes one page of JSON from a preset's endpoint.
 *
 * - Uses wp_safe_remote_request(), which rejects private/local addresses, with
 *   TLS verification on; every hop is re-validated.
 * - Follows at most three redirects itself, and only within the endpoint's
 *   origin (scheme, host, and effective port). A redirect to another origin —
 *   including an https:// to http:// downgrade — is refused for every
 *   authentication mode, because custom headers, query parameters, and POST
 *   bodies may be as sensitive as a dedicated credential.
 * - Enforces the configured response size; a body that reaches the limit is
 *   reported as an error instead of being parsed as if complete.
 * - Decodes JSON with big integers kept as strings, so long numeric IDs are
 *   not rounded.
 * - Makes a single attempt. Retry and backoff decisions belong to the caller
 *   (see Sync\ImportRunner), which persists them across batches.
 */
final class ApiClient
{
    /** @var int Maximum redirects followed. */
    private const MAX_REDIRECTS = 3;

    /** @var list<int> Statuses worth retrying later. */
    public const RETRYABLE_STATUSES = [408, 425, 429, 500, 502, 503, 504];

    /** @var int Characters of the body kept for error display. */
    private const SNIPPET_LENGTH = 2000;

    public function __construct(
        private readonly RequestBuilder $builder,
        private readonly Clock $clock
    ) {}

    /**
     * Fetches one page.
     *
     * @param array<string, mixed>      $preset      Normalized preset.
     * @param string|null               $secret      Credential allowed for this endpoint's origin, or null.
     * @param array<string, int|string> $page_params Pagination parameters.
     *
     * @return FetchResult
     */
    public function fetch(array $preset, ?string $secret, array $page_params = []): FetchResult
    {
        $redactor = new Redactor([$secret]);
        $redactor->add_preset($preset);
        $built    = $this->builder->build($preset, $secret, $page_params);
        $url      = $built['url'];
        $args     = $built['args'];
        $origin   = Url::origin((string) $preset['request']['url']);
        $started  = $this->clock->microtime();

        for ($hop = 0; ; $hop++) {
            if (!wp_http_validate_url($url)) {
                return FetchResult::failure(
                    'unsafe_url',
                    __('The endpoint URL is not allowed. It must be a public http(s) address.', 'post-press-api'),
                    false,
                    0,
                    null,
                    0,
                    $this->elapsed($started),
                    '',
                    '',
                    Redactor::url($url)
                );
            }

            $response = wp_safe_remote_request($url, $args);

            if (is_wp_error($response)) {
                return FetchResult::failure(
                    'network_error',
                    sprintf(
                        /* translators: %s: transport error. */
                        __('The request failed: %s', 'post-press-api'),
                        $redactor->text($response->get_error_message())
                    ),
                    true,
                    0,
                    null,
                    0,
                    $this->elapsed($started),
                    '',
                    '',
                    Redactor::url($url)
                );
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if (!in_array($status, [301, 302, 303, 307, 308], true)) {
                break;
            }

            if ($hop >= self::MAX_REDIRECTS) {
                return FetchResult::failure('too_many_redirects', __('The API redirected too many times.', 'post-press-api'), false, $status, null, 0, $this->elapsed($started), '', '', Redactor::url($url));
            }

            $next = Url::resolve($url, $this->header($response, 'location'));
            if ($next === null) {
                return FetchResult::failure('bad_redirect', __('The API sent a redirect without a valid http(s) location.', 'post-press-api'), false, $status, null, 0, $this->elapsed($started), '', '', Redactor::url($url));
            }

            if (Url::origin($next) !== $origin) {
                return FetchResult::failure(
                    'redirect_blocked',
                    $this->redirect_error($origin, $next, $redactor),
                    false,
                    $status,
                    null,
                    0,
                    $this->elapsed($started),
                    '',
                    '',
                    Redactor::url($url)
                );
            }

            if ($status === 303 || (($status === 301 || $status === 302) && $args['method'] === 'POST')) {
                $args['method'] = 'GET';
                unset($args['body'], $args['headers']['Content-Type']);
            }

            if ($secret !== null && $preset['request']['auth']['type'] === PresetSchema::AUTH_QUERY) {
                $next = Url::with_query($next, [[(string) $preset['request']['auth']['name'], $secret]]);
            }

            $url = $next;
        }

        $body         = (string) wp_remote_retrieve_body($response);
        $bytes        = strlen($body);
        $content_type = $this->header($response, 'content-type');
        $snippet      = $this->snippet($body, $redactor);
        $final_url    = Redactor::url($url);
        $duration     = $this->elapsed($started);

        if ($status < 200 || $status >= 300) {
            $message = trim((string) wp_remote_retrieve_response_message($response));

            return FetchResult::failure(
                'http_status',
                sprintf(
                    /* translators: 1: HTTP status code, 2: reason phrase. */
                    __('The API responded with HTTP %1$d %2$s.', 'post-press-api'),
                    $status,
                    $redactor->text($message)
                ),
                in_array($status, self::RETRYABLE_STATUSES, true),
                $status,
                self::parse_retry_after($this->header($response, 'retry-after'), $this->clock->now()),
                $bytes,
                $duration,
                $content_type,
                $snippet,
                $final_url
            );
        }

        $limit = (int) ($args['limit_response_size'] ?? 0);
        if ($limit > 0 && $bytes >= $limit) {
            return FetchResult::failure(
                'too_large',
                sprintf(
                    /* translators: %s: size limit. */
                    __('The response reached the %s size limit and may be truncated, so it was not imported. Raise the limit or request smaller pages.', 'post-press-api'),
                    size_format($limit)
                ),
                false,
                $status,
                null,
                $bytes,
                $duration,
                $content_type,
                '',
                $final_url
            );
        }

        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }

        if (trim($body) === '') {
            return FetchResult::failure('invalid_json', __('The API returned an empty response instead of JSON.', 'post-press-api'), false, $status, null, $bytes, $duration, $content_type, '', $final_url);
        }

        $data = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return FetchResult::failure(
                'invalid_json',
                sprintf(
                    /* translators: %s: JSON error. */
                    __('The response is not valid JSON (%s).', 'post-press-api'),
                    json_last_error_msg()
                ),
                false,
                $status,
                null,
                $bytes,
                $duration,
                $content_type,
                $snippet,
                $final_url
            );
        }

        return FetchResult::success($status, $data, $bytes, $duration, $content_type, $snippet, $final_url);
    }

    /**
     * Parses a Retry-After header (seconds or HTTP date), capped at one hour.
     *
     * @param mixed $header Header value.
     * @param int   $now    Current time.
     *
     * @return int|null
     */
    public static function parse_retry_after(mixed $header, int $now): ?int
    {
        if (is_array($header)) {
            $header = reset($header);
        }

        $header = trim((string) $header);
        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return min(3600, (int) $header);
        }

        $time = strtotime($header);
        if ($time === false) {
            return null;
        }

        return max(0, min(3600, $time - $now));
    }

    /**
     * Explains a refused cross-origin redirect without the target's path or query.
     *
     * @param string|null $origin   Endpoint origin.
     * @param string      $next     Redirect target.
     * @param Redactor    $redactor Redactor.
     *
     * @return string
     */
    private function redirect_error(?string $origin, string $next, Redactor $redactor): string
    {
        $parts  = (array) wp_parse_url($next);
        $target = $redactor->text(strtolower((string) ($parts['scheme'] ?? '')) . '://' . strtolower((string) ($parts['host'] ?? '')) . (isset($parts['port']) ? ':' . (int) $parts['port'] : ''));

        if ($origin !== null && str_starts_with($origin, 'https://') && str_starts_with((string) Url::origin($next), 'http://')) {
            return sprintf(
                /* translators: %s: redirect target origin. */
                __('The API redirected from https:// to an insecure http:// address (%s), so the request was stopped. Nothing from the request was sent there.', 'post-press-api'),
                $target
            );
        }

        return sprintf(
            /* translators: %s: redirect target origin. */
            __('The API redirected to a different host, scheme, or port (%s), so the request was stopped. Headers, credentials, and request bodies are never forwarded to another origin; if the new address is correct, use it as the endpoint URL.', 'post-press-api'),
            $target
        );
    }

    /**
     * Reads a response header as a string.
     *
     * @param array<string, mixed> $response Response.
     * @param string               $name     Header name.
     *
     * @return string
     */
    private function header(array $response, string $name): string
    {
        $value = wp_remote_retrieve_header($response, $name);
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_string($value) ? $value : '';
    }

    /**
     * Bounded, redacted, valid-UTF-8 start of a body. Masking happens before the
     * cut, so a credential at the cut cannot be left partly visible.
     *
     * @param string   $body     Body.
     * @param Redactor $redactor Redactor.
     *
     * @return string
     */
    private function snippet(string $body, Redactor $redactor): string
    {
        $snippet = $redactor->excerpt($body, self::SNIPPET_LENGTH);
        if (function_exists('wp_check_invalid_utf8')) {
            $snippet = wp_check_invalid_utf8($snippet, true);
        }

        return $snippet;
    }

    /**
     * Milliseconds since $started.
     *
     * @param float $started Start time.
     *
     * @return int
     */
    private function elapsed(float $started): int
    {
        return (int) round(($this->clock->microtime() - $started) * 1000);
    }
}
