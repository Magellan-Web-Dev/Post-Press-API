<?php

declare(strict_types=1);

namespace PostPressAPI\Presets;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Mapping\CoreFields;
use PostPressAPI\Mapping\Destination;
use PostPressAPI\Mapping\MappingSchema;
use PostPressAPI\Mapping\MarkupPolicy;
use PostPressAPI\Services;

/**
 * Versioned, configuration-only preset exports and cross-site import review.
 *
 * Exported ACF descriptors retain field names/types and parent paths, allowing
 * local keys to differ. Imports always receive a new identity and start disabled.
 * Credential storage, run state, and content never participate in this format.
 * Parsing and review are read-only; only save() persists the reviewed preset.
 *
 * Review choices (field selections, mappings removed from the import, and
 * disabling ACF post matching) are keyed by the mapping's path in the uploaded
 * file. Fields are resolved on that original tree before removed subtrees are
 * dropped, so removing one row never shifts another row's choices.
 */
final class PresetTransfer
{
    /** @var int Portable file format version (independent of plugin releases). */
    public const VERSION = 1;
    /** @var int Maximum uploaded JSON size. */
    public const MAX_BYTES = 2097152;
    /** @var list<string> Reusable preset settings allowed in a transfer. */
    private const SETTINGS = ['name', 'request', 'response', 'destination', 'mappings', 'sync', 'pagination', 'schedule', 'batch_size'];

    /**
     * @param Services $services Existing repositories, discovery, and validation services.
     */
    public function __construct(private readonly Services $services) {}

    /**
     * Builds a portable envelope from a saved preset, excluding credentials.
     *
     * @param string $preset_id      Source preset ID.
     * @param bool   $include_sample Include the separately stored sample JSON.
     *
     * @return array<string, mixed> Portable JSON envelope.
     *
     * @throws \InvalidArgumentException When the preset no longer exists.
     */
    public function export(string $preset_id, bool $include_sample = false): array
    {
        $preset = $this->services->presets()->find($preset_id);
        if ($preset === null) {
            throw new \InvalidArgumentException(__('The preset no longer exists.', 'post-press-api'));
        }
        $settings = $this->settings($preset);
        $author = get_userdata((int) $settings['destination']['author']);
        $settings['destination']['author'] = 0;
        $fields = $this->fields((string) $settings['destination']['post_type']);
        $bindings = [];
        $this->describe_rows($settings['mappings'], 'mappings', $fields, $bindings);
        if ($settings['sync']['match']['type'] === PresetSchema::MATCH_ACF) {
            $bindings['sync.match'] = $fields[$settings['sync']['match']['key']] ?? null;
        }
        return [
            'format' => 'post-press-api-preset', 'format_version' => self::VERSION,
            'preset_schema' => PresetSchema::VERSION, 'plugin_version' => PPAPI_VERSION,
            'preset' => $settings, 'author_login' => $author ? (string) $author->user_login : '',
            'acf_bindings' => $bindings,
            'sample' => $include_sample ? $this->services->presets()->sample($preset_id) : '',
        ];
    }

    /**
     * Strictly parses a bounded JSON file before normalization can discard values.
     *
     * @param string $json Uploaded file contents or the unchanged review envelope.
     *
     * @return array<string, mixed> Validated portable envelope.
     *
     * @throws \InvalidArgumentException For malformed, oversized, or unsupported input.
     */
    public function decode(string $json): array
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(__('Choose a preset JSON file no larger than 2 MB.', 'post-press-api'));
        }
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException(__('The file is not valid preset JSON: ', 'post-press-api') . $error->getMessage());
        }
        if (!is_array($data) || ($data['format'] ?? null) !== 'post-press-api-preset'
            || ($data['format_version'] ?? null) !== self::VERSION || ($data['preset_schema'] ?? null) !== PresetSchema::VERSION) {
            throw new \InvalidArgumentException(__('This is not a supported Post Press API preset export. Check its format and version.', 'post-press-api'));
        }
        $keys = ['format', 'format_version', 'preset_schema', 'plugin_version', 'preset', 'author_login', 'acf_bindings', 'sample'];
        if (array_diff(array_keys($data), $keys) || array_diff($keys, array_keys($data))
            || !is_string($data['plugin_version']) || !is_string($data['author_login']) || strlen($data['author_login']) > 200
            || !is_array($data['preset']) || !is_array($data['acf_bindings']) || !is_string($data['sample'])) {
            throw new \InvalidArgumentException(__('The preset export has missing, unknown, or incorrectly typed properties.', 'post-press-api'));
        }
        $this->assert_structure($data['preset']);
        // Compare every submitted setting with the normalized value: reject any
        // dropped key, truncated row/string, invalid enum, coercion, or clamping.
        $this->assert_same($data['preset'], $this->settings(PresetSchema::normalize($data['preset'])), 'preset');
        if (strlen($data['sample']) > PresetRepository::MAX_SAMPLE_BYTES) {
            throw new \InvalidArgumentException(__('The exported sample exceeds the sample size limit.', 'post-press-api'));
        }
        if ($data['sample'] !== '') {
            json_decode($data['sample'], true, 64);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException(__('The exported sample is not valid JSON.', 'post-press-api'));
            }
        }
        foreach ($data['acf_bindings'] as $path => $field) {
            if (!is_string($path) || !preg_match('/^(mappings\.\d+(?:\.children\.\d+){0,2}|sync\.match)$/', $path)) {
                throw new \InvalidArgumentException(__('The export contains an invalid ACF mapping path.', 'post-press-api'));
            }
            if ($field === null) {
                continue; // The source site's dependency was unavailable at export.
            }
            if (!is_array($field) || array_diff(array_keys($field), ['key', 'name', 'type', 'parents', 'group', 'parent_key'])) {
                throw new \InvalidArgumentException(__('The export contains an invalid ACF descriptor.', 'post-press-api'));
            }
            foreach (['key', 'name', 'type', 'group', 'parent_key'] as $key) {
                if (!isset($field[$key]) || !is_string($field[$key]) || strlen($field[$key]) > 500) {
                    throw new \InvalidArgumentException(__('The export contains an invalid ACF descriptor.', 'post-press-api'));
                }
            }
            if (!isset($field['parents']) || !is_array($field['parents']) || !array_is_list($field['parents']) || count($field['parents']) > MappingSchema::MAX_DEPTH - 1) {
                throw new \InvalidArgumentException(__('The export contains an invalid ACF parent path.', 'post-press-api'));
            }
            foreach ($field['parents'] as $parent) {
                if (!is_array($parent) || array_keys($parent) !== ['name', 'type'] || !is_string($parent['name']) || !is_string($parent['type'])) {
                    throw new \InvalidArgumentException(__('The export contains an invalid ACF parent descriptor.', 'post-press-api'));
                }
            }
        }
        return $data;
    }

    /**
     * Builds a local candidate and selectable mappings without persisting anything.
     *
     * @param array<string, mixed> $data      Decoded transfer envelope.
     * @param string|null         $post_type Reviewed local post type, or the source type.
     * @param int|null            $author_id Reviewed local author, or a login-based match.
     * @param array<string, string> $remaps   Explicit field choices keyed by mapping path.
     * @param array<mixed>        $excluded  Paths of ACF mappings to leave out, with their nested rows.
     * @param bool                $disable_match Turn off matching existing posts by the exported ACF field.
     *
     * @return array{preset: array, choices: array, omitted: list<string>, match_disabled: bool, warnings: list<string>, problems: list<string>, sample: string}
     *
     * @throws \InvalidArgumentException For malformed selections or removals not present in the upload.
     */
    public function review(array $data, ?string $post_type = null, ?int $author_id = null, array $remaps = [], array $excluded = [], bool $disable_match = false): array
    {
        foreach ($remaps as $path => $key) {
            if (!is_string($path) || !is_string($key) || strlen($key) > 191) {
                throw new \InvalidArgumentException(__('Invalid field selection.', 'post-press-api'));
            }
        }
        $preset = PresetSchema::normalize($data['preset']);
        $excluded = $this->exclusions($preset['mappings'], $excluded);
        if ($disable_match && $preset['sync']['match']['type'] !== PresetSchema::MATCH_ACF) {
            throw new \InvalidArgumentException(__('This file does not match existing posts by an ACF field, so there is no matching to disable.', 'post-press-api'));
        }
        $preset['id'] = '';
        $preset['enabled'] = false;
        $preset['destination']['post_type'] = $post_type ?? $preset['destination']['post_type'];
        $warnings = [__('Imported presets start disabled. Credentials must be re-entered. Existing posts are not migrated or automatically linked to the new preset.', 'post-press-api')];
        if (self::predates_markup($data['preset']['destination'])) {
            $warnings[] = __('This file was exported before the shortcode and block setting existed, so the new preset neutralizes shortcodes and block markup in imported content (the default for new presets). If this API\'s content must run them, change "Shortcodes and blocks in imported content" in the editor before enabling the preset.', 'post-press-api');
        }
        if ($author_id === null) {
            $user = $data['author_login'] === '' ? false : get_user_by('login', $data['author_login']);
            $author_id = $user ? (int) $user->ID : get_current_user_id();
            if (!$user) {
                $warnings[] = __('The source author was not found. Your account is selected; review the local author before importing.', 'post-press-api');
            }
        }
        $preset['destination']['author'] = $author_id;
        $fields = $this->fields($preset['destination']['post_type']);
        $choices = [];
        $problems = [];
        if (!$this->services->destinations()->is_allowed($preset['destination']['post_type'])) {
            $problems[] = __('Choose a registered local post type before importing.', 'post-press-api');
        }
        if ($author_id < 1) {
            $problems[] = __('Choose a local author before importing.', 'post-press-api');
        }
        $this->resolve_rows($preset['mappings'], 'mappings', null, $data['acf_bindings'], $fields, $remaps, $excluded, null, $choices, $problems, $warnings);
        if ($preset['sync']['match']['type'] === PresetSchema::MATCH_ACF) {
            $key = $this->resolve_field('sync.match', $preset['sync']['match']['key'], null, $data['acf_bindings'], $fields, $remaps, $choices, $problems, !$disable_match);
            // Only the field lookup is turned off; identity, policy, and tracking are untouched.
            $preset['sync']['match'] = $disable_match ? ['type' => PresetSchema::MATCH_NONE, 'key' => ''] : ['type' => PresetSchema::MATCH_ACF, 'key' => $key];
            if ($disable_match) {
                $warnings[] = sprintf(__('Matching existing posts is disabled: the new preset will not look for existing posts through the ACF field %s. The external record ID, duplicate policy, and tracking of posts this preset imports are unchanged. To match again later, choose a field under "Connect existing posts" in the editor.', 'post-press-api'), $choices['sync.match']['source']['name'] ?? $choices['sync.match']['original']);
            }
        }
        $retained = $this->retain($preset['mappings'], 'mappings', '', $excluded, $choices, $problems);
        $omitted = array_keys(array_filter($choices, static fn (array $choice): bool => !empty($choice['omitted'])));
        if ($omitted !== []) {
            $warnings[] = __('Removed from this import and not included in the new preset:', 'post-press-api') . ' ' . $this->describe_omissions($choices) . '.';
            if ($retained === [] && $preset['mappings'] !== []) {
                $warnings[] = __('The new preset has no field mappings. It can be saved disabled, but add a mapping in the editor before enabling it.', 'post-press-api');
            }
        }
        $preset['mappings'] = $retained;
        $validation = $this->services->preset_validator()->validate($preset, null, ['action' => PresetFormParser::SECRET_REMOVE, 'value' => '']);
        foreach ($validation['errors'] as $messages) {
            $problems = array_merge($problems, $messages);
        }
        $warnings = array_merge($warnings, $validation['warnings']);
        return ['preset' => $preset, 'choices' => $choices, 'omitted' => $omitted, 'match_disabled' => $disable_match, 'warnings' => array_values(array_unique($warnings)), 'problems' => array_values(array_unique($problems)), 'sample' => $data['sample']];
    }

    /**
     * Saves a validated local candidate as a new disabled preset.
     *
     * @param array<string, mixed>  $data      Decoded envelope.
     * @param string              $post_type Selected local post type.
     * @param int                 $author_id Selected local author.
     * @param array<string,string> $remaps    Explicit ACF field selections.
     * @param array<mixed>        $excluded  Paths of ACF mappings removed from this import.
     * @param bool                $disable_match Turn off matching existing posts by the exported ACF field.
     *
     * @return array<string, mixed> Saved preset.
     *
     * @throws \InvalidArgumentException Until local mapping/dependency problems are fixed.
     */
    public function save(array $data, string $post_type, int $author_id, array $remaps, array $excluded = [], bool $disable_match = false): array
    {
        $review = $this->review($data, $post_type, $author_id, $remaps, $excluded, $disable_match);
        if ($review['problems'] !== []) {
            throw new \InvalidArgumentException(implode(' ', $review['problems']));
        }
        $preset = $review['preset'];
        $preset['id'] = $this->services->presets()->create_id();
        $saved = $this->services->presets()->save($preset, get_current_user_id());
        $this->services->presets()->save_sample($saved['id'], $review['sample']);
        $this->services->scheduler()->sync($saved);
        $this->services->logger()->info(__('Preset imported (disabled).', 'post-press-api'), ['preset_id' => $saved['id']] + array_filter(['omitted_mappings' => count($review['omitted']), 'match_disabled' => $review['match_disabled']]));
        return $saved;
    }

    /**
     * Selects only portable configuration, excluding IDs and runtime metadata.
     *
     * @param array<string, mixed> $preset Normalized preset.
     *
     * @return array<string, mixed>
     */
    private function settings(array $preset): array
    {
        return array_intersect_key($preset, array_fill_keys(self::SETTINGS, true));
    }

    /**
     * Describes the site's registered ACF fields, indexed by actual local key.
     *
     * @param string $post_type Destination post type.
     *
     * @return array<string, array{key: string, name: string, type: string, parents: array, group: string}>
     */
    private function fields(string $post_type): array
    {
        $index = [];
        $walk = static function (array $fields, array $parents, string $group) use (&$walk, &$index): void {
            foreach ($fields as $field) {
                $index[$field['key']] = array_intersect_key($field, array_flip(['key', 'name', 'type'])) + ['parents' => $parents, 'group' => $group, 'parent_key' => $field['parent'] ?? ''];
                $walk($field['children'] ?? [], [...$parents, ['name' => $field['name'], 'type' => $field['type']]], $group);
            }
        };
        foreach ($this->services->acf_fields()->groups($post_type) as $group) {
            $walk($group['fields'], [], (string) $group['title']);
        }
        return $index;
    }

    /**
     * Adds portable descriptors for every ACF mapping, including nested rows.
     *
     * @param array $rows     Mapping rows.
     * @param string $prefix  Path prefix in the portable configuration.
     * @param array $fields   Local descriptor index.
     * @param array $bindings Output descriptors keyed by mapping path.
     *
     * @return void
     */
    private function describe_rows(array $rows, string $prefix, array $fields, array &$bindings): void
    {
        foreach ($rows as $i => $row) {
            $path = $prefix . '.' . $i;
            if ($row['target_type'] === Destination::ACF) {
                $bindings[$path] = $fields[$row['target']] ?? null;
            }
            $this->describe_rows($row['children'], $path . '.children', $fields, $bindings);
        }
    }

    /**
     * Resolves nested field keys on the uploaded tree, including rows removed from the import.
     *
     * Removed rows (and everything nested under them) keep their review controls so
     * they can be restored, but report no unresolved fields or site-specific warnings.
     *
     * @param array $rows       Candidate mapping rows, updated in place.
     * @param string $prefix    Mapping path prefix.
     * @param string|null $parent Resolved local parent key, or null at the top level.
     * @param array $bindings   Exported source descriptors.
     * @param array $fields     Local field index.
     * @param array $remaps     Explicit selections.
     * @param array<string, true> $excluded Validated removed paths.
     * @param string|null $omitted_with Outermost removed ancestor, or null.
     * @param array $choices    Review controls to render.
     * @param array $problems   Unresolved dependencies.
     * @param array $warnings   Site-specific values needing review.
     *
     * @return void
     */
    private function resolve_rows(array &$rows, string $prefix, ?string $parent, array $bindings, array $fields, array $remaps, array $excluded, ?string $omitted_with, array &$choices, array &$problems, array &$warnings): void
    {
        foreach ($rows as $i => &$row) {
            $path = $prefix . '.' . $i;
            $omitted_by = $omitted_with ?? (isset($excluded[$path]) ? $path : null);
            if ($row['target_type'] === Destination::ACF) {
                $row['target'] = $this->resolve_field($path, $row['target'], $parent, $bindings, $fields, $remaps, $choices, $problems, $omitted_by === null);
                $choices[$path] += [
                    'depth' => substr_count($path, '.children.'), 'excluded' => isset($excluded[$path]),
                    'omitted_with' => $omitted_with, 'omitted' => $omitted_by !== null, 'label' => '',
                ];
            }
            if ($omitted_by === null && (($row['target_type'] === Destination::CORE && $row['target'] === CoreFields::AUTHOR)
                || (empty($row['media']['download']) && ($row['target'] === CoreFields::IMAGE || ($bindings[$path]['type'] ?? '') === 'image')))) {
                $warnings[] = sprintf(__('%s uses author or local attachment values. Review constants, fallbacks, and value maps on this site before enabling.', 'post-press-api'), $path);
            }
            $this->resolve_rows($row['children'], $path . '.children', $row['target'], $bindings, $fields, $remaps, $excluded, $omitted_by, $choices, $problems, $warnings);
        }
        unset($row);
    }

    /**
     * Matches one ACF field by compatible key, then unique name/type/parent path.
     *
     * @param string $path       Mapping path or sync.match.
     * @param string $original   Source site's key.
     * @param string|null $parent Resolved local parent key.
     * @param array $bindings    Exported descriptors.
     * @param array $fields      Local field index.
     * @param array $remaps      Explicit review selections.
     * @param array $choices     Output controls with compatible candidates.
     * @param array $problems    Output unresolved-field messages.
     * @param bool  $report      Whether an unresolved field blocks the import (false once removed or disabled).
     *
     * @return string Resolved key, or original key when review is still required.
     */
    private function resolve_field(string $path, string $original, ?string $parent, array $bindings, array $fields, array $remaps, array &$choices, array &$problems, bool $report = true): string
    {
        $source = $bindings[$path] ?? null;
        $parents = $parent === null ? [] : (isset($fields[$parent]) ? [...$fields[$parent]['parents'], ['name' => $fields[$parent]['name'], 'type' => $fields[$parent]['type']]] : null);
        $candidates = array_filter($fields, static fn (array $field): bool => $parents !== null && $field['parent_key'] === ($parent ?? '') && ($source === null || $field['type'] === $source['type']));
        $matches = $source === null ? [] : array_filter($candidates, static fn (array $field): bool => $field['name'] === $source['name'] && $field['parents'] === $source['parents']);
        $selected = '';
        // An explicit choice is kept (and reported) even when it is not a candidate
        // here, so it survives post type or parent changes; blank means automatic.
        $remap = array_key_exists($path, $remaps) ? $remaps[$path] : null;
        if ($remap !== null) {
            $selected = isset($candidates[$remap]) ? $remap : '';
        } elseif (isset($matches[$original])) {
            $selected = $original;
        } elseif (count($matches) === 1) {
            $selected = (string) array_key_first($matches);
        }
        $choices[$path] = ['original' => $original, 'source' => $source, 'selected' => $selected, 'remap' => $remap, 'candidates' => $candidates];
        if ($selected === '' && $report) {
            $problems[] = $path === 'sync.match'
                ? sprintf(__('%s: choose a local ACF field for %s, or disable matching existing posts. No unique compatible match was found.', 'post-press-api'), $path, $source['name'] ?? $original)
                : sprintf(__('%s: choose a local ACF field for %s, or remove this mapping from the import. No unique compatible match was found.', 'post-press-api'), $path, $source['name'] ?? $original);
        }
        return $selected === '' ? $original : $selected;
    }

    /**
     * Validates requested removals against the ACF mappings in this upload.
     *
     * @param array $rows     Normalized uploaded mapping rows.
     * @param array $excluded Submitted paths.
     *
     * @return array<string, true> Removed paths.
     *
     * @throws \InvalidArgumentException For anything other than an uploaded ACF mapping path.
     */
    private function exclusions(array $rows, array $excluded): array
    {
        $paths = [];
        $walk = static function (array $rows, string $prefix) use (&$walk, &$paths): void {
            foreach ($rows as $i => $row) {
                $path = $prefix . '.' . $i;
                if ($row['target_type'] === Destination::ACF) {
                    $paths[$path] = true;
                }
                $walk($row['children'], $path . '.children');
            }
        };
        $walk($rows, 'mappings');
        $set = [];
        foreach ($excluded as $path) {
            if (!is_string($path) || !isset($paths[$path])) {
                throw new \InvalidArgumentException(__('Only ACF mappings listed in this file can be removed from the import.', 'post-press-api'));
            }
            $set[$path] = true;
        }
        return $set;
    }

    /**
     * Drops removed subtrees, keeping retained rows (and their settings) in order.
     * Records each retained row's label in the new preset, matching validation messages.
     *
     * @param array  $rows     Resolved rows.
     * @param string $prefix   Original path prefix.
     * @param string $label    Parent label in the new preset, or '' at the top level.
     * @param array<string, true> $excluded Removed paths.
     * @param array  $choices  Review controls, updated with labels.
     * @param array  $problems Output for containers left empty.
     *
     * @return list<array<string, mixed>>
     */
    private function retain(array $rows, string $prefix, string $label, array $excluded, array &$choices, array &$problems): array
    {
        $kept = [];
        foreach ($rows as $i => $row) {
            $path = $prefix . '.' . $i;
            if (isset($excluded[$path])) {
                continue;
            }
            $row_label = $label === '' ? sprintf(__('Mapping %d', 'post-press-api'), count($kept) + 1) : $label . ' › ' . (count($kept) + 1);
            if (isset($choices[$path])) {
                $choices[$path]['label'] = $row_label;
            }
            $children = $this->retain($row['children'], $path . '.children', $row_label, $excluded, $choices, $problems);
            if ($row['children'] !== [] && $children === []) {
                $problems[] = sprintf(__('%1$s (%2$s): every nested mapping is removed from this import, which would leave this group or repeater empty. Restore at least one nested mapping, or remove %1$s as well.', 'post-press-api'), $path, $choices[$path]['source']['name'] ?? $row['target']);
            }
            $row['children'] = $children;
            $kept[] = $row;
        }
        return $kept;
    }

    /**
     * Summarizes removed mappings: each removed row with no removed ancestor, plus its nested count.
     *
     * @param array $choices Review controls.
     *
     * @return string
     */
    private function describe_omissions(array $choices): string
    {
        $items = [];
        foreach ($choices as $path => $choice) {
            if (empty($choice['excluded']) || $choice['omitted_with'] !== null) {
                continue;
            }
            $nested = count(array_filter($choices, static fn (array $other): bool => ($other['omitted_with'] ?? null) === $path));
            $name = $choice['source']['name'] ?? $choice['original'];
            $items[] = $nested === 0 ? sprintf('%s (%s)', $path, $name)
                : sprintf(_n('%1$s (%2$s) with %3$d nested mapping', '%1$s (%2$s) with %3$d nested mappings', $nested, 'post-press-api'), $path, $name, $nested);
        }
        return implode('; ', $items);
    }

    /**
     * Checks container types, including optional conditions, before normalization.
     *
     * @param array<string, mixed> $preset Portable settings.
     *
     * @return void
     *
     * @throws \InvalidArgumentException For invalid objects/lists or excessive nesting.
     */
    private function assert_structure(array $preset): void
    {
        foreach (['request', 'response', 'destination', 'sync', 'pagination', 'schedule', 'mappings'] as $key) {
            if (!isset($preset[$key]) || !is_array($preset[$key])) {
                throw new \InvalidArgumentException(sprintf(__('Expected an object or list at preset.%s.', 'post-press-api'), $key));
            }
        }
        foreach ([['request', 'auth'], ['response', 'identity'], ['sync', 'match']] as [$section, $key]) {
            if (!isset($preset[$section][$key]) || !is_array($preset[$section][$key])) {
                throw new \InvalidArgumentException(__('The preset has an invalid settings object.', 'post-press-api'));
            }
        }
        $walk = static function (mixed $rows, int $depth = 0) use (&$walk): void {
            if (!is_array($rows) || !array_is_list($rows) || ($depth >= MappingSchema::MAX_DEPTH && $rows !== [])) {
                throw new \InvalidArgumentException(__('Mappings must be lists within the supported nesting limit.', 'post-press-api'));
            }
            foreach ($rows as $row) {
                if (!is_array($row) || !is_array($row['options'] ?? null) || !is_array($row['media'] ?? null)) {
                    throw new \InvalidArgumentException(__('The export contains an invalid mapping row.', 'post-press-api'));
                }
                if (array_key_exists('condition', $row) && !is_array($row['condition'])) {
                    throw new \InvalidArgumentException(__('The export contains an invalid condition object.', 'post-press-api'));
                }
                $walk($row['children'] ?? null, $depth + 1);
            }
        };
        $walk($preset['mappings']);
    }

    /**
     * Rejects any normalization that would silently lose or reinterpret settings.
     * Older exports may omit the Join date flag or condition object; both default off.
     * Exports made before the shortcode/block setting existed omit both
     * destination.markup and destination.shortcodes; they import as Neutralize,
     * the default for new presets ({@see review()} warns). Unlike stored presets,
     * which keep Trusted source on upgrade, an import is a new preset under review.
     *
     * @param mixed  $input      Submitted JSON value.
     * @param mixed  $normalized Corresponding normalized value.
     * @param string $path       Human-readable setting path.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When values, types, or keys differ.
     */
    private function assert_same(mixed $input, mixed $normalized, string $path): void
    {
        if (is_array($input) && is_array($normalized)) {
            if (preg_match('/^preset\.mappings\.\d+(?:\.children\.\d+)*$/', $path)
                && !array_key_exists('condition', $input)) {
                $input['condition'] = \PostPressAPI\Mapping\MappingCondition::DEFAULTS;
            }
            if (preg_match('/^preset\.mappings\.\d+(?:\.children\.\d+)*\.options$/', $path)
                && !array_key_exists('join_datetime', $input) && ($normalized['join_datetime'] ?? null) === false) {
                $input['join_datetime'] = false;
            }
            if ($path === 'preset.destination' && self::predates_markup($input)) {
                $input += ['markup' => MarkupPolicy::NEUTRALIZE, 'shortcodes' => []];
            }
            if (count($input) !== count($normalized) || array_diff(array_keys($input), array_keys($normalized))) {
                throw new \InvalidArgumentException(sprintf(__('Missing, unknown, or excessive settings at %s.', 'post-press-api'), $path));
            }
            foreach ($input as $key => $value) {
                $this->assert_same($value, $normalized[$key], $path . '.' . $key);
            }
        } elseif ($input !== $normalized) {
            throw new \InvalidArgumentException(sprintf(__('Invalid type, unsupported value, or exceeded limit at %s.', 'post-press-api'), $path));
        }
    }

    /**
     * Whether exported destination settings predate the shortcode/block setting
     * (both of its keys are missing; one alone is a malformed file).
     *
     * @param array<string, mixed> $destination Exported destination settings.
     *
     * @return bool
     */
    private static function predates_markup(array $destination): bool
    {
        return !array_key_exists('markup', $destination) && !array_key_exists('shortcodes', $destination);
    }
}
