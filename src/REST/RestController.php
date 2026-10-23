<?php

declare(strict_types=1);

namespace PostPressAPI\REST;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Http\Url;
use PostPressAPI\Presets\PresetFormParser;
use PostPressAPI\Presets\PresetRepository;
use PostPressAPI\Presets\PresetSchema;
use PostPressAPI\Presets\PresetValidator;
use PostPressAPI\Services;
use PostPressAPI\Support\Permissions;
use PostPressAPI\Sync\ImportRunner;

/**
 * REST routes (namespace ppapi/v1) used by the preset editor.
 *
 * Every route requires manage_options through its permission callback and uses
 * WordPress's standard REST authentication (cookie + X-WP-Nonce from the admin
 * screen). Routes that run imports also require the destination post type's
 * capabilities. Responses are redacted: the preset's saved credential and
 * credential-like request values are masked in previews and run summaries.
 *
 *   GET  /destinations?post_type=…        core fields, statuses, and ACF fields for a post type
 *   POST /test-request                     fetch (or decode pasted JSON) and describe the sample
 *   POST /dry-run                          evaluate mappings/matching for the first records, no writes
 *   GET  /presets/{id}/run                 current or most recent run
 *   POST /presets/{id}/run                 start a manual run
 *   POST /presets/{id}/run/step            advance the active run by one bounded step
 *   POST /presets/{id}/run/cancel          cancel the active run
 */
final class RestController
{
    public const NAMESPACE = 'ppapi/v1';

    /** @var float Seconds one REST-driven step may spend. */
    private const STEP_BUDGET = 8.0;

    public function __construct(private readonly Services $services)
    {
    }

    /**
     * Registers routes.
     *
     * @return void
     */
    public function register_routes(): void
    {
        $permission = [$this, 'can_manage'];
        $id_arg     = ['id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{12}$', 'required' => true]];

        register_rest_route(self::NAMESPACE, '/presets/(?P<id>[a-f0-9]{12})/history/delete', [
            'methods' => 'POST', 'callback' => [$this, 'delete_history'],
            'permission_callback' => $permission, 'args' => $id_arg,
        ]);

        register_rest_route(self::NAMESPACE, '/destinations', [
            'methods'             => 'GET',
            'callback'            => [$this, 'destinations'],
            'permission_callback' => $permission,
            'args'                => ['post_type' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key']],
        ]);

        register_rest_route(self::NAMESPACE, '/test-request', [
            'methods'             => 'POST',
            'callback'            => [$this, 'test_request'],
            'permission_callback' => $permission,
        ]);

        register_rest_route(self::NAMESPACE, '/dry-run', [
            'methods'             => 'POST',
            'callback'            => [$this, 'dry_run'],
            'permission_callback' => $permission,
        ]);

        register_rest_route(self::NAMESPACE, '/presets/(?P<id>[a-f0-9]{12})/run', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'run_status'],
                'permission_callback' => $permission,
                'args'                => $id_arg,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'run_start'],
                'permission_callback' => $permission,
                'args'                => $id_arg + ['force' => ['type' => 'boolean', 'default' => false]],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/presets/(?P<id>[a-f0-9]{12})/run/step', [
            'methods'             => 'POST',
            'callback'            => [$this, 'run_step'],
            'permission_callback' => $permission,
            'args'                => $id_arg,
        ]);

        register_rest_route(self::NAMESPACE, '/presets/(?P<id>[a-f0-9]{12})/run/cancel', [
            'methods'             => 'POST',
            'callback'            => [$this, 'run_cancel'],
            'permission_callback' => $permission,
            'args'                => $id_arg,
        ]);
    }

    /**
     * Permission callback for every route.
     *
     * @return bool|\WP_Error
     */
    public function delete_history(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!Permissions::can_manage()) {
            return new \WP_Error('ppapi_forbidden', __('You cannot delete this history.', 'post-press-api'), ['status' => 403]);
        }
        $id = (string) $request->get_param('id');
        $nonce = $request->get_param('history_nonce');
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'ppapi_history_' . $id)) {
            return new \WP_Error('ppapi_nonce', __('The history request expired. Reload the page and try again.', 'post-press-api'), ['status' => 403]);
        }
        if ($this->services->presets()->find($id) === null) {
            return $this->not_found();
        }
        $ids = $request->get_param('run_ids') ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > \PostPressAPI\Sync\RunRepository::HISTORY_LIMIT) {
            return new \WP_Error('ppapi_invalid', __('Invalid run selection.', 'post-press-api'), ['status' => 400]);
        }
        foreach ($ids as $run_id) {
            if (!is_string($run_id) || !preg_match('/^[a-f0-9-]{36}$/i', $run_id)) {
                return new \WP_Error('ppapi_invalid', __('Invalid run ID.', 'post-press-api'), ['status' => 400]);
            }
        }
        try {
            $deleted = $request->get_param('clear_all') === true
                ? $this->services->runs()->clear_history($id)
                : $this->services->runs()->delete_history($id, array_unique($ids));
        } catch (\RuntimeException $error) {
            return new \WP_Error('ppapi_history_busy', $error->getMessage(), ['status' => 409]);
        }
        $limit = $request->get_param('limit') === 5 ? 5 : \PostPressAPI\Sync\RunRepository::HISTORY_LIMIT;
        ob_start();
        \PostPressAPI\Admin\Views\HistoryView::table($this->services->runs()->history($id), $limit, $id);
        $html = (string) ob_get_clean();
        return new \WP_REST_Response(['html' => $html, 'deleted' => $deleted, 'message' => sprintf(__('%d run history entries deleted. Posts, media, and active runs were kept.', 'post-press-api'), $deleted)]);
    }

    public function can_manage(): bool|\WP_Error
    {
        if (Permissions::can_manage()) {
            return true;
        }

        return new \WP_Error(
            'rest_forbidden',
            __('You are not allowed to manage Post Press API.', 'post-press-api'),
            ['status' => rest_authorization_required_code()]
        );
    }

    /**
     * Destination catalog for a post type.
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function destinations(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $post_type = (string) $request->get_param('post_type');
        $registry  = $this->services->destinations();

        if (!$registry->is_allowed($post_type)) {
            return new \WP_Error('ppapi_invalid_post_type', __('That post type cannot be used as a destination.', 'post-press-api'), ['status' => 400]);
        }

        return rest_ensure_response($registry->catalog($post_type)->to_editor_array());
    }

    /**
     * Test Request.
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function test_request(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $input = $this->preview_input($request, false);
        if (is_wp_error($input)) {
            return $input;
        }

        return rest_ensure_response($this->services->preview()->test_request($input['preset'], $input['secret'], $input['sample'], $input['redactor']));
    }

    /**
     * Preview / Dry Run.
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function dry_run(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $input = $this->preview_input($request, true);
        if (is_wp_error($input)) {
            return $input;
        }

        return rest_ensure_response($this->services->preview()->dry_run($input['preset'], $input['secret'], $input['sample'], $input['redactor']));
    }

    /**
     * Current (or last) run.
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function run_status(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $preset = $this->services->presets()->find((string) $request['id']);
        if ($preset === null) {
            return $this->not_found();
        }

        return rest_ensure_response($this->run_response($preset['id'], $this->services->runs()->active($preset['id']), false));
    }

    /**
     * Starts a manual run.
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function run_start(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $preset = $this->services->presets()->find((string) $request['id']);
        if ($preset === null) {
            return $this->not_found();
        }

        $forbidden = $this->type_permission_error($preset);
        if ($forbidden !== null) {
            return $forbidden;
        }

        $state = $this->services->runner()->start($preset['id'], ImportRunner::TRIGGER_MANUAL, (bool) $request->get_param('force'));
        if (is_wp_error($state)) {
            return $state;
        }

        return rest_ensure_response($this->run_response($preset['id'], $state, false));
    }

    /**
     * Advances the active run.
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function run_step(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $preset = $this->services->presets()->find((string) $request['id']);
        if ($preset === null) {
            return $this->not_found();
        }

        $forbidden = $this->type_permission_error($preset);
        if ($forbidden !== null) {
            return $forbidden;
        }

        $result = $this->services->runner()->step($preset['id'], self::STEP_BUDGET);

        return rest_ensure_response($this->run_response($preset['id'], $result['state'], $result['busy']));
    }

    /**
     * Cancels the active run.
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function run_cancel(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $preset = $this->services->presets()->find((string) $request['id']);
        if ($preset === null) {
            return $this->not_found();
        }

        $user   = wp_get_current_user();
        $result = $this->services->runner()->cancel($preset['id'], sprintf(
            /* translators: %s: user display name. */
            __('Cancelled by %s.', 'post-press-api'),
            $user->exists() ? $user->display_name : __('an administrator', 'post-press-api')
        ));

        $response            = $this->run_response($preset['id'], $this->services->runs()->active($preset['id']), false);
        $response['pending'] = $result['pending'];

        return rest_ensure_response($response);
    }

    /**
     * Parses, validates, and authorizes Test Request / Dry Run input.
     *
     * Uses the editor's current (possibly unsaved) settings. A saved credential is
     * used only when the field was left unchanged and the endpoint origin still
     * matches the one it was entered for.
     *
     * @param \WP_REST_Request $request    Request.
     * @param bool             $is_dry_run Whether destination checks apply.
     *
     * @return array{preset: array<string, mixed>, secret: string|null, sample: string|null, redactor: \PostPressAPI\Support\Redactor}|\WP_Error
     */
    private function preview_input(\WP_REST_Request $request, bool $is_dry_run): array|\WP_Error
    {
        $input     = (array) $request->get_body_params();
        $preset_id = is_string($input['preset_id'] ?? null) ? $input['preset_id'] : '';
        $existing  = null;

        if ($preset_id !== '') {
            $existing = $this->services->presets()->find($preset_id);
            if ($existing === null) {
                return $this->not_found();
            }
        }

        $parsed = $this->services->form_parser()->parse($input);
        $result = $this->services->preset_validator()->validate($parsed['raw'], $existing, $parsed['secret'], $is_dry_run);

        // Testing does not need a name, an enable-ready preset, or (for Test Request) a destination.
        $errors = $parsed['errors'] + $result['errors'];
        unset($errors['name'], $errors['enabled']);
        if (!$is_dry_run) {
            unset($errors['destination.post_type'], $errors['destination.status'], $errors['destination.author'], $errors['mappings'], $errors['sync.match']);
        }

        if ($errors !== []) {
            return new \WP_Error(
                'ppapi_invalid',
                implode(' ', array_merge(...array_values($errors))),
                ['status' => 400, 'errors' => $errors]
            );
        }

        $preset       = $result['preset'];
        $preset['id'] = $existing['id'] ?? '';

        $secret = null;
        if ($preset['request']['auth']['type'] !== PresetSchema::AUTH_NONE) {
            if ($result['secret']['action'] === PresetFormParser::SECRET_REPLACE) {
                $secret = $result['secret']['value'];
            } elseif ($result['secret']['action'] === PresetFormParser::SECRET_KEEP && $existing !== null) {
                $secret = $this->services->secrets()->value_for_origin($existing['id'], Url::origin($preset['request']['url']));
            }
        }

        $use_sample = !empty($input['use_sample']);

        return [
            'preset'   => $preset,
            'secret'   => $secret,
            'sample'   => $use_sample ? substr((string) $parsed['sample'], 0, PresetRepository::MAX_SAMPLE_BYTES) : null,
            // The saved credential is masked even when a new one is being tested.
            'redactor' => $this->services->secrets()->redactor($existing === null ? [] : [(string) $existing['id']]),
        ];
    }

    /**
     * Post type capability check for runs.
     *
     * @param array<string, mixed> $preset Preset.
     *
     * @return \WP_Error|null
     */
    private function type_permission_error(array $preset): ?\WP_Error
    {
        $problems = Permissions::current_user_problems(
            (string) $preset['destination']['post_type'],
            PresetValidator::may_publish($preset),
            (int) $preset['destination']['author'],
                    \PostPressAPI\Mapping\ImageReference::used($preset['mappings'])
        );

        return $problems === [] ? null : new \WP_Error('ppapi_forbidden', implode(' ', $problems), ['status' => 403]);
    }

    /**
     * Response describing a run.
     *
     * @param string                    $preset_id Preset ID.
     * @param array<string, mixed>|null $state     Active state, or null.
     * @param bool                      $busy      Whether another process held the lock.
     *
     * @return array<string, mixed>
     */
    private function run_response(string $preset_id, ?array $state, bool $busy): array
    {
        $now = $this->services->clock()->now();

        if ($state === null) {
            $last = $this->services->runs()->last($preset_id);

            return ['active' => false, 'busy' => $busy, 'resume_in' => 0, 'run' => $last === null ? null : $this->services->runner()->public_summary($last)];
        }

        $active = in_array($state['status'] ?? '', ImportRunner::ACTIVE, true);

        return [
            'active'    => $active,
            'busy'      => $busy,
            'resume_in' => $active ? max(0, (int) ($state['resume_at'] ?? 0) - $now) : 0,
            'run'       => $this->services->runner()->public_summary($state),
        ];
    }

    /**
     * @return \WP_Error
     */
    private function not_found(): \WP_Error
    {
        return new \WP_Error('ppapi_not_found', __('The preset does not exist.', 'post-press-api'), ['status' => 404]);
    }
}
