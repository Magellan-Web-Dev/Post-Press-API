<?php

declare(strict_types=1);

namespace PostPressAPI\Presets;

if (!defined('ABSPATH')) exit;

/**
 * Stores presets as independent, non-autoloaded options.
 *
 *   ppapi_presets            autoloaded index: id → {enabled, frequency, minutes} (small;
 *                            read by the cron_schedules filter on every request)
 *   ppapi_preset_{id}        full preset configuration (no credentials)
 *   ppapi_sample_{id}        optional pasted sample JSON for offline mapping
 *
 * Preset IDs are 12 lowercase hex characters, generated once and never reused
 * for a different preset.
 */
final class PresetRepository
{
    public const INDEX_OPTION   = 'ppapi_presets';
    public const PRESET_PREFIX  = 'ppapi_preset_';
    public const SAMPLE_PREFIX  = 'ppapi_sample_';
    public const MAX_SAMPLE_BYTES = 262144;

    /**
     * Whether a string is a well-formed preset ID.
     *
     * @param string $id ID.
     *
     * @return bool
     */
    public static function is_valid_id(string $id): bool
    {
        return preg_match('/^[a-f0-9]{12}$/', $id) === 1;
    }

    /**
     * Generates an unused preset ID.
     *
     * IDs always start with a letter: an all-digit ID would become an integer
     * when used as a PHP array key (as in the index option).
     *
     * @return string
     */
    public function create_id(): string
    {
        do {
            $id = 'abcdef'[random_int(0, 5)] . substr(bin2hex(random_bytes(6)), 0, 11);
        } while ($this->find($id) !== null);

        return $id;
    }

    /**
     * Lightweight index of all presets, in creation order.
     *
     * @return array<string, array{enabled: bool, frequency: string, minutes: int}>
     */
    public function index(): array
    {
        $index = get_option(self::INDEX_OPTION, []);
        if (!is_array($index)) {
            return [];
        }

        $clean = [];
        foreach ($index as $id => $summary) {
            $id = (string) $id;
            if (self::is_valid_id($id) && is_array($summary)) {
                $clean[$id] = [
                    'enabled'   => !empty($summary['enabled']),
                    'frequency' => (string) ($summary['frequency'] ?? PresetSchema::FREQ_MANUAL),
                    'minutes'   => (int) ($summary['minutes'] ?? 0),
                ];
            }
        }

        return $clean;
    }

    /**
     * Preset IDs in creation order.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        // Array keys that look like integers come back as ints; IDs are always strings.
        return array_map('strval', array_keys($this->index()));
    }

    /**
     * All presets in creation order.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $presets = [];
        foreach ($this->ids() as $id) {
            $preset = $this->find($id);
            if ($preset !== null) {
                $presets[] = $preset;
            }
        }

        return $presets;
    }

    /**
     * Loads a preset.
     *
     * @param string $id Preset ID.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        if (!self::is_valid_id($id)) {
            return null;
        }

        $stored = get_option(self::PRESET_PREFIX . $id, null);
        if (!is_array($stored)) {
            return null;
        }

        // Presets saved before the shortcode/block policy existed keep their earlier
        // behavior (content kept as filtered by wp_kses_post) until an administrator
        // chooses a policy; the editor flags this. Existing posts are never rewritten.
        if (is_array($stored['destination'] ?? null) && !array_key_exists('markup', $stored['destination'])) {
            $stored['destination']['markup'] = \PostPressAPI\Mapping\MarkupPolicy::TRUSTED;
        }

        $preset       = PresetSchema::normalize($stored);
        $preset['id'] = $id;

        return $preset;
    }

    /**
     * Saves a preset, bumping its revision and timestamps.
     *
     * @param array<string, mixed> $preset  Preset (id must be set).
     * @param int                  $user_id User making the change.
     *
     * @return array<string, mixed> The stored preset.
     */
    public function save(array $preset, int $user_id = 0): array
    {
        $id = (string) ($preset['id'] ?? '');
        if (!self::is_valid_id($id)) {
            throw new \InvalidArgumentException('Invalid preset ID.');
        }

        $existing = $this->find($id);
        $now      = time();

        $preset               = PresetSchema::normalize($preset);
        $preset['id']         = $id;
        $preset['created_at'] = $existing['created_at'] ?? $now;
        $preset['updated_at'] = $now;
        $preset['updated_by'] = $user_id;
        $preset['revision']   = (int) ($existing['revision'] ?? 0) + 1;

        update_option(self::PRESET_PREFIX . $id, $preset, false);

        $index      = $this->index();
        $index[$id] = [
            'enabled'   => (bool) $preset['enabled'],
            'frequency' => (string) $preset['schedule']['frequency'],
            'minutes'   => (int) $preset['schedule']['minutes'],
        ];
        update_option(self::INDEX_OPTION, $index, true);

        return $preset;
    }

    /**
     * Deletes a preset's configuration and sample. Imported posts are not touched.
     *
     * @param string $id Preset ID.
     *
     * @return void
     */
    public function delete(string $id): void
    {
        if (!self::is_valid_id($id)) {
            return;
        }

        delete_option(self::PRESET_PREFIX . $id);
        delete_option(self::SAMPLE_PREFIX . $id);

        $index = $this->index();
        unset($index[$id]);
        update_option(self::INDEX_OPTION, $index, true);
    }

    /**
     * Saved sample JSON for offline mapping.
     *
     * @param string $id Preset ID.
     *
     * @return string
     */
    public function sample(string $id): string
    {
        if (!self::is_valid_id($id)) {
            return '';
        }

        $sample = get_option(self::SAMPLE_PREFIX . $id, '');

        return is_string($sample) ? $sample : '';
    }

    /**
     * Stores (or removes, when empty) the sample JSON.
     *
     * @param string $id     Preset ID.
     * @param string $sample JSON text (already validated and bounded).
     *
     * @return void
     */
    public function save_sample(string $id, string $sample): void
    {
        if (!self::is_valid_id($id)) {
            return;
        }

        if (trim($sample) === '') {
            delete_option(self::SAMPLE_PREFIX . $id);
            return;
        }

        update_option(self::SAMPLE_PREFIX . $id, substr($sample, 0, self::MAX_SAMPLE_BYTES), false);
    }
}
