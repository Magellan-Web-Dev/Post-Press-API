<?php

declare(strict_types=1);

namespace PostPressAPI\Admin;

use PostPressAPI\Presets\PresetRepository;
use PostPressAPI\Services;
use PostPressAPI\Support\Permissions;

if (!defined('ABSPATH')) exit;

/**
 * admin-post.php handlers for list-row actions.
 *
 * Every handler checks manage_options and a per-action, per-preset nonce before
 * doing anything, then redirects back to the presets screen with a notice code.
 *
 *   ppapi_duplicate   duplicate a preset (disabled copy)
 *   ppapi_toggle      enable or disable a preset
 *   ppapi_delete      delete a preset (imported posts are kept)
 *   ppapi_clear_log   clear the activity log
 */
final class AdminActions
{
    /**
     * Ends the request after a redirect. Replaceable so tests can observe redirects.
     *
     * @var callable(string): void
     */
    private $terminate;

    /** @var callable(string, string): void Emits JSON and ends the download request. */
    private $download;

    /**
     * @param Services      $services  Shared plugin services.
     * @param callable|null $terminate Redirect responder, replaceable in tests.
     * @param callable|null $download  JSON download responder, replaceable in tests.
     */
    public function __construct(private readonly Services $services, ?callable $terminate = null, ?callable $download = null)
    {
        $this->terminate = $terminate ?? static function (string $url): void {
            wp_safe_redirect($url);
            exit;
        };
        $this->download = $download ?? static function (string $filename, string $json): void {
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $json;
            exit;
        };
    }

    /**
     * Registers handlers.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_post_ppapi_duplicate', [$this, 'duplicate']);
        add_action('admin_post_ppapi_toggle', [$this, 'toggle']);
        add_action('admin_post_ppapi_delete', [$this, 'delete']);
        add_action('admin_post_ppapi_clear_log', [$this, 'clear_log']);
        add_action('admin_post_ppapi_export', [$this, 'export']);
    }

    /**
     * Downloads saved configuration without exposing separately stored credentials.
     *
     * @return void
     */
    public function export(): void
    {
        if (!Permissions::can_manage()) {
            wp_die(esc_html__('You are not allowed to export presets.', 'post-press-api'), '', ['response' => 403]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Use the export form to download a preset.', 'post-press-api'), '', ['response' => 405]);
        }
        $id = is_string($_POST['preset'] ?? null) ? wp_unslash($_POST['preset']) : '';
        if (!PresetRepository::is_valid_id($id)) {
            wp_die(esc_html__('Invalid preset.', 'post-press-api'), '', ['response' => 400]);
        }
        check_admin_referer('ppapi_export_' . $id);
        try {
            $data = $this->services->transfer()->export($id, ($_POST['include_sample'] ?? '') === '1');
            $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json) || strlen($json) > \PostPressAPI\Presets\PresetTransfer::MAX_BYTES) {
                throw new \InvalidArgumentException(__('The preset could not be encoded within the 2 MB transfer limit. Reduce its sample or configuration size.', 'post-press-api'));
            }
            $filename = sanitize_title((string) $data['preset']['name']);
            $filename = preg_replace('/[^a-z0-9_-]/i', '', $filename) ?: 'preset';
            ($this->download)('post-press-api-' . substr($filename, 0, 80) . '.json', $json . "\n");
        } catch (\InvalidArgumentException $error) {
            wp_die(esc_html($error->getMessage()), '', ['response' => 400]);
        }
    }

    /**
     * Nonce-protected URL for a row action.
     *
     * @param string $action    Action name.
     * @param string $preset_id Preset ID.
     * @param array<string, string> $extra Extra query args.
     *
     * @return string
     */
    public static function url(string $action, string $preset_id, array $extra = []): string
    {
        return wp_nonce_url(
            add_query_arg(['action' => $action, 'preset' => $preset_id] + $extra, admin_url('admin-post.php')),
            $action . '_' . $preset_id
        );
    }

    public function duplicate(): void
    {
        $preset_id = $this->authorize('ppapi_duplicate');
        $copy      = (new PresetEditor($this->services))->duplicate($preset_id);

        $this->finish($copy === null ? 'missing' : 'duplicated', $copy['id'] ?? null);
    }

    public function toggle(): void
    {
        $preset_id = $this->authorize('ppapi_toggle');
        $enable    = isset($_GET['enable']) && $_GET['enable'] === '1';
        $result    = (new PresetEditor($this->services))->set_enabled($preset_id, $enable);

        if (!$result['ok']) {
            $this->finish('not_ready', $preset_id);
            return;
        }

        $this->finish($enable ? 'enabled' : 'disabled', $preset_id);
    }

    public function delete(): void
    {
        $preset_id = $this->authorize('ppapi_delete');
        $deleted   = (new PresetEditor($this->services))->delete($preset_id);

        $this->finish($deleted ? 'deleted' : 'missing');
    }

    public function clear_log(): void
    {
        if (!Permissions::can_manage()) {
            wp_die(esc_html__('You are not allowed to manage Post Press API.', 'post-press-api'), '', ['response' => 403]);
        }
        check_admin_referer('ppapi_clear_log');

        $this->services->logger()->clear();
        ($this->terminate)(add_query_arg(['page' => 'ppapi-log', 'ppapi_notice' => 'log_cleared'], admin_url('admin.php')));
    }

    /**
     * Checks capability and nonce; returns the preset ID.
     *
     * @param string $action Action name.
     *
     * @return string
     */
    private function authorize(string $action): string
    {
        if (!Permissions::can_manage()) {
            wp_die(esc_html__('You are not allowed to manage Post Press API.', 'post-press-api'), '', ['response' => 403]);
        }

        $preset_id = isset($_GET['preset']) ? sanitize_key(wp_unslash($_GET['preset'])) : '';
        if (!PresetRepository::is_valid_id($preset_id)) {
            wp_die(esc_html__('Invalid preset.', 'post-press-api'), '', ['response' => 400]);
        }

        check_admin_referer($action . '_' . $preset_id);

        return $preset_id;
    }

    /**
     * Redirects to the presets screen with a notice.
     *
     * @param string      $notice    Notice code.
     * @param string|null $preset_id Related preset.
     *
     * @return void
     */
    private function finish(string $notice, ?string $preset_id = null): void
    {
        $args = ['page' => 'ppapi', 'ppapi_notice' => $notice];
        if ($preset_id !== null) {
            $args['preset'] = $preset_id;
        }

        ($this->terminate)(add_query_arg($args, admin_url('admin.php')));
    }
}
