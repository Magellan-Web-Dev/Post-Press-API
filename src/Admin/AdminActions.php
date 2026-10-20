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

    public function __construct(private readonly Services $services, ?callable $terminate = null)
    {
        $this->terminate = $terminate ?? static function (string $url): void {
            wp_safe_redirect($url);
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
