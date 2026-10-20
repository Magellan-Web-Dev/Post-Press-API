<?php

declare(strict_types=1);

namespace PostPressAPI\Admin;

use PostPressAPI\Http\Url;
use PostPressAPI\Presets\PresetFormParser;
use PostPressAPI\Presets\PresetRepository;
use PostPressAPI\Presets\PresetValidator;
use PostPressAPI\Services;
use PostPressAPI\Support\Permissions;
use PostPressAPI\Sync\ImportRunner;

if (!defined('ABSPATH')) exit;

/**
 * Saves, duplicates, toggles, and deletes presets.
 *
 * Callers are responsible for capability and nonce checks; this class keeps the
 * persistence rules in one place (credentials, sample JSON, schedules, and
 * cancelling runs when a preset is disabled or deleted).
 */
final class PresetEditor
{
    public function __construct(private readonly Services $services)
    {
    }

    /**
     * Validates and saves submitted editor fields.
     *
     * @param array<string, mixed> $input     Unslashed POST data.
     * @param string|null          $preset_id Preset being edited, or null for a new preset.
     *
     * @return array{ok: bool, preset: array<string, mixed>, errors: array<string, list<string>>, warnings: list<string>, sample: string}
     */
    public function save(array $input, ?string $preset_id): array
    {
        $presets  = $this->services->presets();
        $existing = $preset_id !== null ? $presets->find($preset_id) : null;

        if ($preset_id !== null && $existing === null) {
            return ['ok' => false, 'preset' => [], 'errors' => ['general' => [__('The preset no longer exists.', 'post-press-api')]], 'warnings' => [], 'sample' => ''];
        }

        $parsed = $this->services->form_parser()->parse($input);
        $result = $this->services->preset_validator()->validate($parsed['raw'], $existing, $parsed['secret']);
        $errors = $parsed['errors'] + $result['errors'];

        $sample = trim($parsed['sample']);
        if ($sample !== '') {
            if (strlen($sample) > PresetRepository::MAX_SAMPLE_BYTES) {
                $errors['sample'][] = sprintf(
                    /* translators: %s: size. */
                    __('The sample JSON is larger than %s. Paste a smaller excerpt.', 'post-press-api'),
                    size_format(PresetRepository::MAX_SAMPLE_BYTES)
                );
            } else {
                json_decode($sample, true, 512, JSON_BIGINT_AS_STRING);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errors['sample'][] = sprintf(
                        /* translators: %s: JSON error. */
                        __('The sample is not valid JSON (%s).', 'post-press-api'),
                        json_last_error_msg()
                    );
                }
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'preset' => $result['preset'], 'errors' => $errors, 'warnings' => $result['warnings'], 'sample' => $parsed['sample']];
        }

        $preset       = $result['preset'];
        $preset['id'] = $existing['id'] ?? $presets->create_id();

        $saved = $presets->save($preset, get_current_user_id());

        $secrets = $this->services->secrets();
        if ($result['secret']['action'] === PresetFormParser::SECRET_REPLACE) {
            $secrets->set($saved['id'], $result['secret']['value'], $result['secret']['origin']);
        } elseif ($result['secret']['action'] === PresetFormParser::SECRET_REMOVE) {
            $secrets->delete($saved['id']);
        }

        $presets->save_sample($saved['id'], $sample);
        $this->services->scheduler()->sync($saved);

        if (!$saved['enabled']) {
            $this->cancel_active($saved['id'], __('Cancelled because the preset was disabled.', 'post-press-api'));
        }

        $this->services->logger()->info(
            $existing === null ? __('Preset created.', 'post-press-api') : __('Preset saved.', 'post-press-api'),
            ['preset_id' => $saved['id'], 'revision' => $saved['revision'], 'user_id' => get_current_user_id()]
        );

        return ['ok' => true, 'preset' => $saved, 'errors' => [], 'warnings' => $result['warnings'], 'sample' => $sample];
    }

    /**
     * Duplicates a preset (disabled, with its credential and sample copied server-side).
     *
     * @param string $preset_id Source preset ID.
     *
     * @return array<string, mixed>|null The copy, or null when the source is missing.
     */
    public function duplicate(string $preset_id): ?array
    {
        $presets = $this->services->presets();
        $source  = $presets->find($preset_id);
        if ($source === null) {
            return null;
        }

        $copy             = $source;
        $copy['id']       = $presets->create_id();
        $copy['enabled']  = false;
        $copy['revision'] = 0;
        $copy['name']     = sprintf(
            /* translators: %s: preset name. */
            __('Copy of %s', 'post-press-api'),
            $source['name']
        );

        $saved = $presets->save($copy, get_current_user_id());
        $this->services->secrets()->copy($source['id'], $saved['id']);
        $presets->save_sample($saved['id'], $presets->sample($source['id']));
        $this->services->scheduler()->sync($saved);
        $this->services->logger()->info(__('Preset duplicated.', 'post-press-api'), ['preset_id' => $saved['id'], 'source_id' => $source['id']]);

        return $saved;
    }

    /**
     * Enables or disables a preset.
     *
     * @param string $preset_id Preset ID.
     * @param bool   $enabled   Desired state.
     *
     * @return array{ok: bool, problems: list<string>}
     */
    public function set_enabled(string $preset_id, bool $enabled): array
    {
        $presets = $this->services->presets();
        $preset  = $presets->find($preset_id);
        if ($preset === null) {
            return ['ok' => false, 'problems' => [__('The preset no longer exists.', 'post-press-api')]];
        }

        if ($enabled) {
            $secret   = $this->services->secrets()->value_for_origin($preset_id, Url::origin($preset['request']['url']));
            $problems = $this->services->preset_validator()->readiness_problems($preset, $secret !== null);

            $post_type = (string) $preset['destination']['post_type'];
            if ($post_type !== '') {
                $problems = array_merge($problems, Permissions::current_user_problems(
                    $post_type,
                    PresetValidator::may_publish($preset),
                    (int) $preset['destination']['author']
                ));
            }

            if ($problems !== []) {
                return ['ok' => false, 'problems' => $problems];
            }
        }

        $preset['enabled'] = $enabled;
        $saved             = $presets->save($preset, get_current_user_id());
        $this->services->scheduler()->sync($saved);

        if (!$enabled) {
            $this->cancel_active($preset_id, __('Cancelled because the preset was disabled.', 'post-press-api'));
        }

        return ['ok' => true, 'problems' => []];
    }

    /**
     * Deletes a preset and its settings, credential, schedule, and history.
     * Imported posts are kept.
     *
     * @param string $preset_id Preset ID.
     *
     * @return bool Whether a preset was deleted.
     */
    public function delete(string $preset_id): bool
    {
        if ($this->services->presets()->find($preset_id) === null) {
            return false;
        }

        $this->cancel_active($preset_id, __('Cancelled because the preset was deleted.', 'post-press-api'));
        $this->services->scheduler()->clear($preset_id);
        $this->services->runs()->delete_all($preset_id);
        $this->services->secrets()->delete($preset_id);
        $this->services->lock()->clear($preset_id);
        $this->services->presets()->delete($preset_id);
        $this->services->logger()->info(__('Preset deleted; its imported posts were kept.', 'post-press-api'), ['preset_id' => $preset_id]);

        return true;
    }

    /**
     * Cancels an unfinished run, if any.
     *
     * @param string $preset_id Preset ID.
     * @param string $reason    Reason.
     *
     * @return void
     */
    private function cancel_active(string $preset_id, string $reason): void
    {
        $active = $this->services->runs()->active($preset_id);
        if ($active !== null && in_array($active['status'] ?? '', ImportRunner::ACTIVE, true)) {
            $this->services->runner()->cancel($preset_id, $reason);
        }
        $this->services->continuations()->clear($preset_id);
    }
}
