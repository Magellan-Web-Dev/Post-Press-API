<?php

declare(strict_types=1);

namespace PostPressAPI\Http;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Presets\PresetSchema;

/**
 * Builds the outbound request for a preset and page.
 *
 * Redirects are disabled at the transport level ("redirection" => 0) so
 * {@see ApiClient} can follow them itself and refuse to carry credentials to
 * another origin. TLS verification stays on.
 */
final class RequestBuilder
{
    /**
     * Builds URL and wp_safe_remote_request() arguments.
     *
     * @param array<string, mixed>       $preset      Normalized preset.
     * @param string|null                $secret      Credential (only when allowed for this origin).
     * @param array<string, int|string>  $page_params Pagination parameters for this page.
     *
     * @return array{url: string, args: array<string, mixed>}
     */
    public function build(array $preset, ?string $secret, array $page_params = []): array
    {
        $request = $preset['request'];
        $auth    = $request['auth'];
        $is_post = $request['method'] === 'POST';
        $in_body = $is_post && $preset['pagination']['placement'] === PresetSchema::PLACEMENT_BODY;

        $query = [];
        foreach ($request['query'] as $row) {
            $query[] = [(string) $row['key'], (string) $row['value']];
        }

        $body_params = [];
        foreach ($page_params as $name => $value) {
            if ($in_body) {
                $body_params[(string) $name] = $value;
            } else {
                $query[] = [(string) $name, (string) $value];
            }
        }

        if ($secret !== null && $auth['type'] === PresetSchema::AUTH_QUERY) {
            $query[] = [(string) $auth['name'], $secret];
        }

        $headers = ['Accept' => 'application/json'];
        foreach ($request['headers'] as $row) {
            $headers[(string) $row['name']] = (string) $row['value'];
        }

        if ($secret !== null) {
            if ($auth['type'] === PresetSchema::AUTH_BEARER) {
                $headers['Authorization'] = 'Bearer ' . $secret;
            } elseif ($auth['type'] === PresetSchema::AUTH_HEADER) {
                $headers[(string) $auth['name']] = $secret;
            }
        }

        $args = [
            'method'              => $request['method'],
            'timeout'             => (int) $request['timeout'],
            'redirection'         => 0,
            'headers'             => $headers,
            'limit_response_size' => (int) $request['max_kb'] * 1024,
            'sslverify'           => true,
            'user-agent'          => 'PostPressAPI/' . (defined('PPAPI_VERSION') ? PPAPI_VERSION : '1') . '; WordPress/' . get_bloginfo('version'),
        ];

        if ($is_post) {
            $template = trim((string) $request['body']);
            $body     = $template === '' ? [] : json_decode($template, true, 64);
            $body     = is_array($body) ? $body : [];

            foreach ($body_params as $name => $value) {
                $body[$name] = $value;
            }

            if ($template !== '' || $body_params !== []) {
                $is_list                         = $body !== [] && array_is_list($body);
                $args['body']                    = (string) wp_json_encode($is_list ? $body : (object) $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $args['headers']['Content-Type'] = 'application/json';
            }
        }

        /**
         * Filters outbound request arguments (server-side only).
         *
         * Redirect following, response size limits, and TLS verification are
         * enforced by Post Press API; changing them here is not supported.
         *
         * @param array<string, mixed> $args   wp_safe_remote_request() arguments.
         * @param array<string, mixed> $preset Preset (without credentials).
         */
        $filtered = apply_filters('ppapi_request_args', $args, $preset);
        if (is_array($filtered)) {
            $args = $filtered;
        }
        $args['redirection'] = 0;
        $args['sslverify']   = true;
        $args['limit_response_size'] = (int) $request['max_kb'] * 1024;

        return ['url' => Url::with_query((string) $request['url'], $query), 'args' => $args];
    }
}
