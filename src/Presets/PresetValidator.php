<?php

declare(strict_types=1);

namespace PostPressAPI\Presets;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Http\Url;
use PostPressAPI\Mapping\CoreFields;
use PostPressAPI\Mapping\Destination;
use PostPressAPI\Mapping\DestinationRegistry;
use PostPressAPI\Mapping\MappingValidator;
use PostPressAPI\Mapping\MarkupPolicy;
use PostPressAPI\Mapping\PathResolver;
use PostPressAPI\Support\Permissions;

/**
 * Validates presets.
 *
 * Two levels:
 *  - errors: malformed or unsafe settings; the preset cannot be saved (or tested).
 *  - readiness problems: settings still missing (endpoint, records path, identity,
 *    destination, mappings, credential). An incomplete preset can be saved as a
 *    draft but cannot be enabled or run until they are resolved.
 */
final class PresetValidator
{
    /** @var list<string> Request headers administrators may not set. */
    private const BLOCKED_HEADERS = ['host', 'content-length', 'transfer-encoding', 'connection', 'authorization', 'proxy-authorization', 'cookie', 'te', 'upgrade', 'expect'];

    public function __construct(
        private readonly PathResolver $paths,
        private readonly MappingValidator $mappings,
        private readonly DestinationRegistry $destinations,
        private readonly SecretStore $secrets
    ) {}

    /**
     * Validates submitted preset data.
     *
     * @param array<string, mixed>              $raw      Raw preset data from {@see PresetFormParser}.
     * @param array<string, mixed>|null         $existing Saved preset when editing.
     * @param array{action: string, value: string} $secret Credential input.
     * @param bool                              $check_permissions Whether to check the current user's post type capabilities.
     *
     * @return array{preset: array<string, mixed>, errors: array<string, list<string>>, warnings: list<string>, secret: array{action: string, value: string, origin: string}}
     */
    public function validate(array $raw, ?array $existing, array $secret, bool $check_permissions = true): array
    {
        $errors   = [];
        $warnings = [];
        $add      = static function (string $field, string $message) use (&$errors): void {
            $errors[$field][] = $message;
        };

        $this->check_bounds($raw, $add);

        $preset       = PresetSchema::normalize($raw);
        $preset['id'] = (string) ($existing['id'] ?? $preset['id']);
        if ($preset['destination']['author'] === 0) {
            $preset['destination']['author'] = get_current_user_id();
        }

        if ($preset['name'] === '') {
            $add('name', __('Enter a name for this preset.', 'post-press-api'));
        }

        $origin = $this->validate_request($preset, $add);
        $secret = $this->validate_secret($preset, $existing, $secret, $origin, $add);
        $this->validate_response($preset, $add);
        $this->validate_destination($preset, $add, $warnings, $check_permissions);
        $this->validate_markup($raw, $preset, $add, $warnings);
        $this->validate_sync($preset, $add);
        $this->validate_pagination($preset, $add);

        if ($preset['enabled']) {
            $has_secret = $secret['action'] === PresetFormParser::SECRET_REPLACE
                || ($secret['action'] === PresetFormParser::SECRET_KEEP && $preset['id'] !== '' && $this->secrets->has($preset['id']));
            foreach ($this->readiness_problems($preset, $has_secret) as $problem) {
                $add('enabled', sprintf(
                    /* translators: %s: problem. */
                    __('The preset cannot be enabled yet: %s', 'post-press-api'),
                    $problem
                ));
            }
        }

        return ['preset' => $preset, 'errors' => $errors, 'warnings' => $warnings, 'secret' => $secret];
    }

    /**
     * Settings still required before a preset can be enabled or run.
     *
     * @param array<string, mixed> $preset     Normalized preset.
     * @param bool                 $has_secret Whether a usable credential exists.
     *
     * @return list<string>
     */
    public function readiness_problems(array $preset, bool $has_secret): array
    {
        $problems = [];

        if ($preset['request']['url'] === '') {
            $problems[] = __('enter the endpoint URL.', 'post-press-api');
        }
        if ($preset['request']['auth']['type'] !== PresetSchema::AUTH_NONE && !$has_secret) {
            $problems[] = __('enter the credential for the selected authentication method.', 'post-press-api');
        }
        if ($preset['response']['records_path'] === '') {
            $problems[] = __('enter the records path (use $ when the response itself is the list).', 'post-press-api');
        }

        $identity = $preset['response']['identity'];
        if ($preset['sync']['policy'] !== PresetSchema::POLICY_CREATE) {
            if ($identity['mode'] === PresetSchema::IDENTITY_PATH && $identity['path'] === '') {
                $problems[] = __('enter the external record ID path.', 'post-press-api');
            }
            if ($identity['mode'] === PresetSchema::IDENTITY_COMPOSITE && $identity['paths'] === []) {
                $problems[] = __('enter at least one composite identity path.', 'post-press-api');
            }
        }

        if (!$this->destinations->is_allowed($preset['destination']['post_type'])) {
            $problems[] = __('choose a destination post type.', 'post-press-api');
        }
        if ($preset['mappings'] === []) {
            $problems[] = __('add at least one field mapping.', 'post-press-api');
        }

        return $problems;
    }

    /**
     * Whether the preset can produce published or private posts.
     *
     * @param array<string, mixed> $preset Preset.
     *
     * @return bool
     */
    public static function may_publish(array $preset): bool
    {
        $public = ['publish', 'private', 'future'];
        if (in_array($preset['destination']['status'], $public, true)) {
            return true;
        }

        foreach ((array) $preset['mappings'] as $row) {
            if (($row['target_type'] ?? '') !== Destination::CORE || ($row['target'] ?? '') !== CoreFields::STATUS) {
                continue;
            }
            $values = [(string) $row['source'], (string) $row['fallback']];
            foreach ((array) ($row['options']['map'] ?? []) as $pair) {
                $values[] = (string) ($pair['to'] ?? '');
            }
            if (array_intersect($values, $public) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rejects out-of-range numeric input instead of silently clamping it.
     *
     * @param array<string, mixed> $raw Raw data.
     * @param callable             $add Error callback.
     *
     * @return void
     */
    private function check_bounds(array $raw, callable $add): void
    {
        $checks = [
            ['request.timeout', $raw['request']['timeout'] ?? null, 'timeout', __('Timeout (seconds)', 'post-press-api')],
            ['request.max_kb', $raw['request']['max_kb'] ?? null, 'max_kb', __('Maximum response size (KB)', 'post-press-api')],
            ['request.retries', $raw['request']['retries'] ?? null, 'retries', __('Retries', 'post-press-api')],
            ['batch_size', $raw['batch_size'] ?? null, 'batch_size', __('Records per batch', 'post-press-api')],
            ['schedule.minutes', $raw['schedule']['minutes'] ?? null, 'custom_minutes', __('Custom interval (minutes)', 'post-press-api')],
            ['pagination.page_size', $raw['pagination']['page_size'] ?? null, 'page_size', __('Page size', 'post-press-api')],
            ['pagination.max_pages', $raw['pagination']['max_pages'] ?? null, 'max_pages', __('Maximum pages per run', 'post-press-api')],
            ['pagination.max_records', $raw['pagination']['max_records'] ?? null, 'max_records', __('Maximum records per run', 'post-press-api')],
            ['pagination.start', $raw['pagination']['start'] ?? null, 'start', __('Initial offset/page', 'post-press-api')],
        ];

        foreach ($checks as [$field, $value, $bound, $label]) {
            if ($value === null || $value === '') {
                continue;
            }
            [$min, $max] = PresetSchema::BOUNDS[$bound];
            if (!is_numeric($value) || (int) $value != $value || (int) $value < $min || (int) $value > $max) {
                $add($field, sprintf(
                    /* translators: 1: field label, 2: minimum, 3: maximum. */
                    __('%1$s must be a whole number from %2$d to %3$d.', 'post-press-api'),
                    $label,
                    $min,
                    $max
                ));
            }
        }
    }

    /**
     * Validates request settings.
     *
     * @return string|null Endpoint origin when the URL is valid.
     */
    private function validate_request(array $preset, callable $add): ?string
    {
        $request = $preset['request'];
        $origin  = null;

        if ($request['url'] !== '') {
            if (!Url::is_valid_endpoint($request['url'])) {
                $add('request.url', __('Enter an absolute http:// or https:// URL without a username or password in it.', 'post-press-api'));
            } else {
                $origin = Url::origin($request['url']);
            }
        }

        $reserved = [];
        if ($request['auth']['type'] === PresetSchema::AUTH_QUERY && $request['auth']['name'] !== '') {
            $reserved[$request['auth']['name']] = __('the API key parameter', 'post-press-api');
        }
        if ($preset['pagination']['type'] !== PresetSchema::PAGINATION_NONE && $preset['pagination']['placement'] === PresetSchema::PLACEMENT_QUERY) {
            foreach ($this->pagination_params($preset) as $param) {
                $reserved[$param] = __('a pagination parameter', 'post-press-api');
            }
        }

        foreach ($request['query'] as $index => $row) {
            if ($row['key'] === '') {
                $add('request.query', sprintf(
                    /* translators: %d: row number. */
                    __('Query parameter %d needs a name.', 'post-press-api'),
                    $index + 1
                ));
            } elseif (isset($reserved[$row['key']])) {
                $add('request.query', sprintf(
                    /* translators: 1: parameter, 2: what it is used for. */
                    __('The query parameter "%1$s" is already used as %2$s.', 'post-press-api'),
                    $row['key'],
                    $reserved[$row['key']]
                ));
            }
        }

        if ($request['url'] !== '' && $request['auth']['type'] === PresetSchema::AUTH_QUERY && in_array($request['auth']['name'], Url::query_names($request['url']), true)) {
            $add('request.url', __('Remove the API key parameter from the URL; the credential is added from the Authentication settings.', 'post-press-api'));
        }

        foreach ($request['headers'] as $index => $row) {
            if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $row['name'])) {
                $add('request.headers', sprintf(
                    /* translators: %d: row number. */
                    __('Header %d needs a valid name (letters, numbers, and -).', 'post-press-api'),
                    $index + 1
                ));
                continue;
            }
            if (in_array(strtolower($row['name']), self::BLOCKED_HEADERS, true)) {
                $add('request.headers', sprintf(
                    /* translators: %s: header name. */
                    __('The "%s" header cannot be set here. Use the Authentication settings for credentials.', 'post-press-api'),
                    $row['name']
                ));
            }
            if (strpbrk($row['value'], "\r\n\0") !== false) {
                $add('request.headers', sprintf(
                    /* translators: %s: header name. */
                    __('The value of "%s" cannot contain line breaks.', 'post-press-api'),
                    $row['name']
                ));
            }
        }

        if ($request['method'] === 'GET' && trim($request['body']) !== '') {
            $add('request.body', __('A JSON body can only be sent with POST requests.', 'post-press-api'));
        }
        if ($request['method'] === 'POST' && trim($request['body']) !== '') {
            $decoded = json_decode($request['body'], true, 64);
            if (!is_array($decoded)) {
                $add('request.body', __('The request body must be a valid JSON object or array.', 'post-press-api'));
            } elseif ($preset['pagination']['placement'] === PresetSchema::PLACEMENT_BODY && $decoded !== [] && array_is_list($decoded)) {
                $add('request.body', __('To add pagination parameters to the body, the body must be a JSON object.', 'post-press-api'));
            }
        }

        $auth = $request['auth'];
        if ($auth['type'] === PresetSchema::AUTH_HEADER) {
            if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $auth['name'])) {
                $add('request.auth', __('Enter the header name the API key is sent in, for example X-API-Key.', 'post-press-api'));
            } elseif (in_array(strtolower($auth['name']), array_diff(self::BLOCKED_HEADERS, ['authorization']), true)) {
                $add('request.auth', __('That header name cannot carry an API key.', 'post-press-api'));
            } else {
                foreach ($request['headers'] as $row) {
                    if (strcasecmp($row['name'], $auth['name']) === 0) {
                        $add('request.auth', __('The API key header is also listed under Headers; remove it there.', 'post-press-api'));
                    }
                }
            }
        }
        if ($auth['type'] === PresetSchema::AUTH_QUERY && $auth['name'] === '') {
            $add('request.auth', __('Enter the query parameter name the API key is sent in.', 'post-press-api'));
        }

        if ($auth['type'] !== PresetSchema::AUTH_NONE && $origin !== null && str_starts_with($origin, 'http://')) {
            /**
             * Filters whether credentials may be sent over plain HTTP.
             *
             * @param bool                 $allow  Default false.
             * @param array<string, mixed> $preset Preset.
             */
            if (!apply_filters('ppapi_allow_insecure_credentials', false, $preset)) {
                $add('request.url', __('Credentials can only be sent to https:// endpoints.', 'post-press-api'));
            }
        }

        return $origin;
    }

    /**
     * Decides what happens to the stored credential.
     *
     * @return array{action: string, value: string, origin: string}
     */
    private function validate_secret(array $preset, ?array $existing, array $secret, ?string $origin, callable $add): array
    {
        $action = (string) ($secret['action'] ?? PresetFormParser::SECRET_KEEP);
        $value  = (string) ($secret['value'] ?? '');

        if ($action === PresetFormParser::SECRET_REPLACE) {
            if (strpbrk($value, "\r\n\0") !== false) {
                $add('request.auth', __('The credential cannot contain line breaks.', 'post-press-api'));
            }
            if ($origin === null) {
                $add('request.auth', __('Enter a valid endpoint URL before saving a credential; it will only be sent to that host.', 'post-press-api'));
            }

            return ['action' => $action, 'value' => $value, 'origin' => (string) $origin];
        }

        if ($action === PresetFormParser::SECRET_KEEP && $existing !== null && $preset['request']['auth']['type'] !== PresetSchema::AUTH_NONE) {
            $saved = $this->secrets->get((string) $existing['id']);
            if ($saved !== null && $origin !== null && !hash_equals($saved['origin'], $origin)) {
                $add('request.auth', __('The endpoint host changed. Re-enter the credential (or remove it) so a saved credential is never sent to a different host.', 'post-press-api'));
            }
        }

        return ['action' => $action, 'value' => '', 'origin' => (string) $origin];
    }

    /**
     * Validates response selection and identity.
     */
    private function validate_response(array $preset, callable $add): void
    {
        $response = $preset['response'];

        foreach (['records_path' => 'response.records_path', 'success_path' => 'response.success_path'] as $key => $field) {
            if ($response[$key] !== '' && ($error = $this->paths->validate($response[$key])) !== null) {
                $add($field, $error);
            }
        }

        $identity = $response['identity'];
        $paths    = $identity['mode'] === PresetSchema::IDENTITY_PATH ? array_filter([$identity['path']]) : $identity['paths'];
        foreach ($paths as $path) {
            $error = $this->paths->validate($path);
            if ($error !== null) {
                $add('response.identity', sprintf('%s: %s', $path, $error));
            } elseif ($this->paths->has_wildcard($path)) {
                $add('response.identity', sprintf(
                    /* translators: %s: path. */
                    __('%s: identity paths must select a single value, not a [*] list.', 'post-press-api'),
                    $path
                ));
            }
        }

        if ($identity['mode'] === PresetSchema::IDENTITY_COMPOSITE && count($identity['paths']) > PresetSchema::MAX_IDENTITY_PATHS) {
            $add('response.identity', __('Use at most five composite identity paths.', 'post-press-api'));
        }
    }

    /**
     * Validates destination, permissions, and mappings.
     *
     * @param list<string> $warnings Warning accumulator.
     */
    private function validate_destination(array &$preset, callable $add, array &$warnings, bool $check_permissions): void
    {
        $destination = $preset['destination'];
        $post_type   = $destination['post_type'];
        $statuses    = $this->destinations->core()->allowed_statuses();

        if (!in_array($destination['status'], $statuses, true)) {
            $add('destination.status', sprintf(
                /* translators: %s: allowed statuses. */
                __('The default status must be one of: %s.', 'post-press-api'),
                implode(', ', $statuses)
            ));
        }

        if ($post_type === '') {
            if ($preset['mappings'] !== []) {
                $add('destination.post_type', __('Choose a destination post type for these mappings.', 'post-press-api'));
            }
            return;
        }

        if (!$this->destinations->is_allowed($post_type)) {
            $add('destination.post_type', __('Choose one of the listed destination post types.', 'post-press-api'));
            return;
        }

        $may_publish = self::may_publish($preset);

        foreach (Permissions::author_problems($post_type, $may_publish, (int) $destination['author'], \PostPressAPI\Mapping\ImageReference::used($preset['mappings'])) as $problem) {
            $add('destination.author', $problem);
        }

        if ($check_permissions) {
            foreach (Permissions::current_user_problems($post_type, $may_publish, (int) $destination['author'], \PostPressAPI\Mapping\ImageReference::used($preset['mappings'])) as $problem) {
                $add('destination.post_type', $problem);
            }
        }

        $result = $this->mappings->validate($preset['mappings'], $this->destinations->catalog($post_type));
        foreach ($result['errors'] as $error) {
            $add('mappings', $error);
        }
        foreach ($result['warnings'] as $warning) {
            $warnings[] = $warning;
        }
    }

    /**
     * Validates the shortcode and block policy for imported rich text.
     *
     * @param array<string, mixed> $raw      Raw data.
     * @param array<string, mixed> $preset   Normalized preset.
     * @param list<string>         $warnings Warning accumulator.
     */
    private function validate_markup(array $raw, array $preset, callable $add, array &$warnings): void
    {
        $destination = $preset['destination'];

        if ($destination['markup'] === MarkupPolicy::ALLOWLIST) {
            $submitted = $raw['destination']['shortcodes'] ?? [];
            $submitted = is_string($submitted) ? (preg_split('/[\s,]+/', $submitted) ?: []) : (array) $submitted;
            foreach ($submitted as $tag) {
                $tag = is_string($tag) ? trim($tag, " \t\n\r\0\x0B[]") : '';
                if ($tag !== '' && preg_match(MarkupPolicy::TAG_PATTERN, $tag) !== 1) {
                    $add('destination.markup', sprintf(
                        /* translators: %s: shortcode tag. */
                        __('"%s" is not a valid shortcode name (letters, numbers, - and _).', 'post-press-api'),
                        $tag
                    ));
                }
            }
            if (count(array_filter($submitted, static fn ($tag): bool => is_string($tag) && trim($tag) !== '')) > MarkupPolicy::MAX_TAGS) {
                $add('destination.markup', sprintf(
                    /* translators: %d: maximum number of shortcodes. */
                    __('List at most %d shortcodes.', 'post-press-api'),
                    MarkupPolicy::MAX_TAGS
                ));
            }
            if ($destination['shortcodes'] === []) {
                $add('destination.markup', __('List the shortcodes imported content may use, or choose another setting.', 'post-press-api'));
            }
        }

        if ($destination['markup'] === MarkupPolicy::TRUSTED) {
            $warnings[] = __('Imported content may run any shortcode or block installed on this site. Choose "Trusted source" only for APIs whose content you control.', 'post-press-api');
        }
    }

    /**
     * Validates duplicate handling.
     */
    private function validate_sync(array $preset, callable $add): void
    {
        $match = $preset['sync']['match'];
        if ($match['type'] === PresetSchema::MATCH_NONE || $preset['sync']['policy'] === PresetSchema::POLICY_CREATE) {
            return;
        }

        if ($preset['response']['identity']['mode'] !== PresetSchema::IDENTITY_PATH) {
            $add('sync.match', __('Matching existing posts needs a single external ID path, not a composite identity.', 'post-press-api'));
            return;
        }

        if ($match['key'] === '') {
            $add('sync.match', __('Choose the field that holds the external ID on existing posts.', 'post-press-api'));
            return;
        }

        if ($match['type'] === PresetSchema::MATCH_META) {
            if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_\-.:]{0,190}$/', $match['key']) || str_starts_with($match['key'], '_ppapi_')) {
                $add('sync.match', __('Enter a valid meta key to match on.', 'post-press-api'));
            }
            return;
        }

        $post_type = $preset['destination']['post_type'];
        $acf       = $this->destinations->acf();
        if (!$acf->available()) {
            $add('sync.match', __('Advanced Custom Fields is not active, so an ACF field cannot be used for matching.', 'post-press-api'));
            return;
        }

        $field = $post_type === '' ? null : $acf->top_level_field($post_type, $match['key']);
        if ($field === null || !in_array($field['type'] ?? '', ['text', 'number', 'email', 'url'], true)) {
            $add('sync.match', __('Choose a top-level ACF text, number, email, or URL field registered for this post type.', 'post-press-api'));
        }
    }

    /**
     * Validates pagination settings.
     */
    private function validate_pagination(array $preset, callable $add): void
    {
        $pagination = $preset['pagination'];
        if ($pagination['type'] === PresetSchema::PAGINATION_NONE) {
            return;
        }

        foreach ($this->pagination_params($preset) as $param) {
            if ($param === '' || strlen($param) > 100) {
                $add('pagination', __('Enter the pagination parameter names.', 'post-press-api'));
                break;
            }
        }

        if ($pagination['type'] === PresetSchema::PAGINATION_OFFSET && $pagination['offset_param'] === $pagination['limit_param']) {
            $add('pagination', __('The offset and limit parameters must have different names.', 'post-press-api'));
        }

        if ($pagination['placement'] === PresetSchema::PLACEMENT_BODY && $preset['request']['method'] !== 'POST') {
            $add('pagination', __('Pagination parameters can only be placed in the body of POST requests.', 'post-press-api'));
        }

        foreach (['has_more_path', 'total_path'] as $key) {
            if ($pagination[$key] !== '' && ($error = $this->paths->validate($pagination[$key])) !== null) {
                $add('pagination', $error);
            }
        }
    }

    /**
     * Request parameter names used by pagination.
     *
     * @param array<string, mixed> $preset Preset.
     *
     * @return list<string>
     */
    private function pagination_params(array $preset): array
    {
        $pagination = $preset['pagination'];

        return match ($pagination['type']) {
            PresetSchema::PAGINATION_OFFSET => [$pagination['offset_param'], $pagination['limit_param']],
            PresetSchema::PAGINATION_PAGE   => $pagination['limit_param'] !== '' ? [$pagination['page_param'], $pagination['limit_param']] : [$pagination['page_param']],
            default                         => [],
        };
    }
}
