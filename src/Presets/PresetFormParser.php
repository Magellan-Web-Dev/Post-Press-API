<?php

declare(strict_types=1);

namespace PostPressAPI\Presets;

if (!defined('ABSPATH')) exit;

/**
 * Turns the preset editor's submitted fields into raw preset data.
 *
 * Shared by the save handler and the Test Request / Dry Run REST routes so
 * both see unsaved editor changes the same way. Input must already be unslashed.
 *
 * Fields:
 *   ppapi[...]            preset settings (nested names mirror PresetSchema)
 *   ppapi_mappings        mapping rows as JSON, maintained by the mapping editor
 *   ppapi_secret          new credential (blank = keep the saved one)
 *   ppapi_secret_remove   "1" to delete the saved credential
 *   ppapi_sample          optional sample JSON for offline mapping
 */
final class PresetFormParser
{
    public const SECRET_KEEP    = 'keep';
    public const SECRET_REPLACE = 'replace';
    public const SECRET_REMOVE  = 'remove';

    /** @var int Maximum accepted credential length. */
    private const MAX_SECRET = 4096;

    /**
     * Parses submitted fields.
     *
     * @param array<string, mixed> $input Unslashed request fields.
     *
     * @return array{raw: array<string, mixed>, secret: array{action: string, value: string}, sample: string, errors: array<string, list<string>>}
     */
    public function parse(array $input): array
    {
        $raw    = is_array($input['ppapi'] ?? null) ? $input['ppapi'] : [];
        $errors = [];

        $raw['enabled'] = !empty($raw['enabled']);

        $mappings_json   = is_string($input['ppapi_mappings'] ?? null) ? $input['ppapi_mappings'] : '';
        $raw['mappings'] = [];
        if (trim($mappings_json) !== '') {
            $decoded = json_decode($mappings_json, true, 64);
            if (is_array($decoded)) {
                $raw['mappings'] = $decoded;
            } else {
                $errors['mappings'][] = __('The mapping editor data could not be read. Reload the page and try again.', 'post-press-api');
            }
        }

        $secret_value = is_string($input['ppapi_secret'] ?? null) ? $input['ppapi_secret'] : '';
        $secret_value = substr($secret_value, 0, self::MAX_SECRET);

        if (!empty($input['ppapi_secret_remove'])) {
            $action = self::SECRET_REMOVE;
        } elseif (trim($secret_value) !== '') {
            $action = self::SECRET_REPLACE;
        } else {
            $action = self::SECRET_KEEP;
        }

        $sample = is_string($input['ppapi_sample'] ?? null) ? $input['ppapi_sample'] : '';

        return [
            'raw'    => $raw,
            'secret' => ['action' => $action, 'value' => $action === self::SECRET_REPLACE ? trim($secret_value) : ''],
            'sample' => $sample,
            'errors' => $errors,
        ];
    }
}
