<?php

declare(strict_types=1);

namespace PostPressAPI\Admin\Views;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Admin\AdminActions;
use PostPressAPI\Admin\AdminPage;
use PostPressAPI\Admin\Notices;
use PostPressAPI\Presets\PresetTransfer;
use PostPressAPI\Services;

/**
 * Preset portability screens: download saved settings or review an uploaded file.
 *
 * The review carries the original bounded JSON in the form, without persisting
 * a staging preset or activating schedules. Server-side validation is repeated
 * on every review/save submission; local field choices are never trusted alone.
 * Field selections and removals are posted separately, keyed by the mapping's
 * path in that unchanged file, so they survive refreshes and validation errors.
 */
final class TransferView
{
    /**
     * @param Services $services Local field discovery and preset transfer services.
     */
    public function __construct(private readonly Services $services) {}

    /**
     * Identifies what a review omits (removed mappings and disabled matching), so a
     * save can be held back until the administrator has seen the same omissions.
     *
     * @param array<string,mixed> $review Result of PresetTransfer::review().
     *
     * @return string
     */
    public static function omission_key(array $review): string
    {
        return implode(' ', [...$review['omitted'], ...($review['match_disabled'] ? ['sync.match'] : [])]);
    }

    /**
     * Renders the upload form or a local-field review of a parsed export.
     *
     * @param array<string,mixed>|null $data    Original validated envelope, if uploaded.
     * @param array<string,mixed>|null $review  Candidate, field choices, and validation.
     * @param list<string>             $errors  Submission errors.
     * @param list<string>             $notices Informational review notices.
     *
     * @return void
     */
    public function render(?array $data = null, ?array $review = null, array $errors = [], array $notices = []): void
    {
        ?>
        <div class="wrap ppapi-wrap">
            <h1><?php esc_html_e('Import preset', 'post-press-api'); ?></h1>
            <p><a href="<?php echo esc_url(AdminPage::url()); ?>"><?php esc_html_e('All presets', 'post-press-api'); ?></a></p>
            <?php Notices::render_list('error', $errors); ?>
            <?php Notices::render_list('info', $notices); ?>
            <p><?php esc_html_e('Import a Post Press API JSON export. Review local fields and the author before creating a new disabled preset. Post types and ACF field groups must already exist on this site.', 'post-press-api'); ?></p>
            <?php if ($data === null || $review === null) : ?>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(AdminPage::url(['view' => 'import'])); ?>" class="ppapi-card">
                    <?php wp_nonce_field('ppapi_import_preset', '_ppapi_import_nonce'); ?>
                    <input type="hidden" name="ppapi_action" value="import_upload">
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo (int) PresetTransfer::MAX_BYTES; ?>">
                    <p><label for="ppapi-import-file"><?php esc_html_e('Preset JSON file (maximum 2 MB)', 'post-press-api'); ?></label></p>
                    <input type="file" id="ppapi-import-file" name="ppapi_import_file" accept=".json,application/json" required>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e('Upload and review', 'post-press-api'); ?></button></p>
                </form>
            <?php else : ?>
                <?php $this->review_form($data, $review); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders local selections alongside the complete configuration to be imported.
     *
     * @param array<string,mixed> $data   Original envelope retained for revalidation.
     * @param array<string,mixed> $review Candidate and selectable compatible fields.
     *
     * @return void
     */
    private function review_form(array $data, array $review): void
    {
        $preset = $review['preset'];
        Notices::render_list('warning', $review['warnings']);
        Notices::render_list('error', $review['problems']);
        ?>
        <form method="post" action="<?php echo esc_url(AdminPage::url(['view' => 'import'])); ?>" class="ppapi-card">
            <?php wp_nonce_field('ppapi_import_preset', '_ppapi_import_nonce'); ?>
            <textarea name="ppapi_import_json" hidden><?php echo esc_textarea((string) wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></textarea>
            <h2><?php echo esc_html($preset['name']); ?></h2>
            <p><?php esc_html_e('Request settings, mappings, duplicate rules, pagination, schedule, and batch size are retained, except for any mapping you remove or matching you disable below. Credentials are excluded. Importing does not fetch data or download images.', 'post-press-api'); ?></p>
            <p><label for="ppapi-import-type"><?php esc_html_e('Local destination post type', 'post-press-api'); ?></label><br>
                <select name="ppapi_import_type" id="ppapi-import-type">
                    <option value=""><?php esc_html_e('— Choose a post type —', 'post-press-api'); ?></option>
                    <?php foreach ($this->services->destinations()->post_types() as $type => $label) : ?>
                        <option value="<?php echo esc_attr($type); ?>" <?php selected($preset['destination']['post_type'], $type); ?>><?php echo esc_html($label . ' (' . $type . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p><label for="ppapi-import-author"><?php esc_html_e('Local author', 'post-press-api'); ?></label><br>
                <?php wp_dropdown_users(['name' => 'ppapi_import_author', 'id' => 'ppapi-import-author', 'selected' => $preset['destination']['author'], 'show_option_none' => __('— Choose an author —', 'post-press-api'), 'option_none_value' => 0]); ?>
            </p>
            <input type="hidden" name="ppapi_import_reviewed" value="<?php echo esc_attr(self::omission_key($review)); ?>">
            <?php $mappings = array_diff_key($review['choices'], ['sync.match' => true]); ?>
            <?php if ($mappings !== []) : ?>
                <h3><?php esc_html_e('ACF field mappings', 'post-press-api'); ?></h3>
                <p><?php esc_html_e('Choose a local field for each ACF mapping, or tick "Remove from this import" to leave a mapping you do not need out of the new preset. Removing a group or repeater also removes its nested mappings. Nothing is removed unless you tick it.', 'post-press-api'); ?></p>
                <table class="widefat striped ppapi-import-fields">
                    <thead><tr><th><?php esc_html_e('Source mapping', 'post-press-api'); ?></th><th><?php esc_html_e('Local ACF field', 'post-press-api'); ?></th><th><?php esc_html_e('In the new preset', 'post-press-api'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($mappings as $path => $choice) : ?>
                        <tr class="<?php echo esc_attr('ppapi-import-depth-' . $choice['depth'] . ($choice['omitted'] ? ' ppapi-import-omitted' : '')); ?>">
                            <td class="ppapi-import-source"><label for="<?php echo esc_attr('ppapi-import-' . $path); ?>"><code><?php echo esc_html($path); ?></code><br><?php echo esc_html(($choice['source']['name'] ?? $choice['original']) . ' (' . ($choice['source']['type'] ?? __('unknown type', 'post-press-api')) . ')'); ?></label></td>
                            <td><?php $this->field_select($path, $choice); ?></td>
                            <td>
                                <?php if ($choice['omitted_with'] !== null) : ?>
                                    <span class="ppapi-badge ppapi-badge--warning"><?php esc_html_e('Omitted', 'post-press-api'); ?></span>
                                    <?php echo esc_html(sprintf(__('Removed with %s.', 'post-press-api'), $choice['omitted_with'])); ?>
                                <?php elseif ($choice['excluded']) : ?>
                                    <span class="ppapi-badge ppapi-badge--warning"><?php esc_html_e('Omitted', 'post-press-api'); ?></span>
                                    <?php esc_html_e('Will not be in the new preset.', 'post-press-api'); ?>
                                <?php else : ?>
                                    <?php echo esc_html(sprintf(__('Included as %s', 'post-press-api'), $choice['label'])); ?>
                                <?php endif; ?>
                                <br><label><input type="checkbox" name="ppapi_import_exclude[]" value="<?php echo esc_attr($path); ?>" <?php checked($choice['excluded']); ?>> <?php esc_html_e('Remove from this import', 'post-press-api'); ?><span class="screen-reader-text"> <?php echo esc_html($path); ?></span></label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e('After changing the post type or a parent group/repeater, refresh matches to see its local subfields. "Automatic match" is checked again on every refresh; a field you choose yourself is kept, even across post type changes, until you change it. Removed mappings stay listed with their selections; clear the box and refresh matches to restore one. "Included as" gives the mapping\'s number in the new preset, as used in validation messages.', 'post-press-api'); ?></p>
            <?php endif; ?>
            <?php if (isset($review['choices']['sync.match'])) : ?>
                <?php $match = $review['choices']['sync.match']; ?>
                <h3><?php esc_html_e('Matching existing posts', 'post-press-api'); ?></h3>
                <p><?php echo esc_html(sprintf(__('The exported preset connects existing posts whose ACF field %s holds the record\'s external ID ("Connect existing posts").', 'post-press-api'), ($match['source']['name'] ?? $match['original']) . ' (' . ($match['source']['type'] ?? __('unknown type', 'post-press-api')) . ')')); ?></p>
                <p><label for="ppapi-import-sync.match"><?php esc_html_e('Local ACF field holding the external ID', 'post-press-api'); ?></label><br><?php $this->field_select('sync.match', $match); ?></p>
                <p><label><input type="checkbox" name="ppapi_import_disable_match" value="1" <?php checked($review['match_disabled']); ?>> <strong><?php esc_html_e('Disable matching existing posts', 'post-press-api'); ?></strong></label>
                    <?php if ($review['match_disabled']) : ?><span class="ppapi-badge ppapi-badge--warning"><?php esc_html_e('Disabled', 'post-press-api'); ?></span><?php endif; ?></p>
                <p class="description"><?php esc_html_e('When disabled, the new preset no longer looks for existing posts it did not create through this field, so a record whose ID is already in such a post creates a new post instead. The external record ID setting, the duplicate policy, and tracking of posts this preset imports are unchanged: its own posts are still found and updated. You can choose a matching field later in the editor.', 'post-press-api'); ?></p>
            <?php endif; ?>
            <details><summary><?php esc_html_e('Review all imported settings', 'post-press-api'); ?></summary>
                <pre class="ppapi-transfer-json"><?php echo esc_html((string) wp_json_encode($preset, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
            </details>
            <p><?php echo esc_html($data['sample'] === '' ? __('No saved sample is included.', 'post-press-api') : __('The saved sample JSON is included.', 'post-press-api')); ?></p>
            <p>
                <button type="submit" name="ppapi_action" value="import_review" class="button"><?php esc_html_e('Refresh matches', 'post-press-api'); ?></button>
                <button type="submit" name="ppapi_action" value="import_save" class="button button-primary"><?php esc_html_e('Create disabled preset', 'post-press-api'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Renders the local field choice for one exported ACF binding.
     *
     * The blank option means "match automatically" and names the current match, so
     * automatic matches are re-checked on every refresh. A field the administrator
     * chose stays selected, even when it is not available for the current post type
     * or parent, until they change it.
     *
     * @param string              $path   Mapping path in the uploaded file, or sync.match.
     * @param array<string,mixed> $choice Selection and compatible candidates.
     *
     * @return void
     */
    private function field_select(string $path, array $choice): void
    {
        $label = static fn (string $key, array $field): string => $field['group'] . ' › ' . $field['name'] . ' (' . $key . ')';
        $automatic = $choice['remap'] === null && $choice['selected'] !== '';
        ?>
        <select name="ppapi_import_remaps[<?php echo esc_attr($path); ?>]" id="<?php echo esc_attr('ppapi-import-' . $path); ?>">
            <option value=""><?php echo esc_html($automatic ? sprintf(__('Automatic match: %s', 'post-press-api'), $label($choice['selected'], $choice['candidates'][$choice['selected']])) : __('— Choose a compatible field —', 'post-press-api')); ?></option>
            <?php if ($choice['remap'] !== null && $choice['remap'] !== '' && !isset($choice['candidates'][$choice['remap']])) : ?>
                <option value="<?php echo esc_attr($choice['remap']); ?>" selected="selected"><?php echo esc_html(sprintf(__('%s (not available here)', 'post-press-api'), $choice['remap'])); ?></option>
            <?php endif; ?>
            <?php foreach ($choice['candidates'] as $key => $field) : ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected((string) $choice['remap'], $key); ?>><?php echo esc_html($label($key, $field)); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Presents the saved configuration export and optional sample inclusion.
     *
     * @param array<string,mixed> $preset Saved preset.
     *
     * @return void
     */
    public function export_form(array $preset): void
    {
        ?>
        <div class="wrap ppapi-wrap">
            <h1><?php echo esc_html(sprintf(__('Export preset: %s', 'post-press-api'), $preset['name'])); ?></h1>
            <p><a href="<?php echo esc_url(AdminPage::url(['view' => 'edit', 'preset' => $preset['id']])); ?>"><?php esc_html_e('Edit preset', 'post-press-api'); ?></a></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ppapi-card">
                <input type="hidden" name="action" value="ppapi_export">
                <input type="hidden" name="preset" value="<?php echo esc_attr($preset['id']); ?>">
                <?php wp_nonce_field('ppapi_export_' . $preset['id']); ?>
                <p><?php esc_html_e('Exports the saved preset settings. Save changes in the editor first. Stored authentication credentials, history, posts, and media files are excluded.', 'post-press-api'); ?></p>
                <p><?php esc_html_e('Values manually placed in URLs, headers, request bodies, mappings, or samples are included. Review the file before sharing it if those fields contain sensitive information.', 'post-press-api'); ?></p>
                <p><label><input type="checkbox" name="include_sample" value="1"> <?php esc_html_e('Include saved sample JSON', 'post-press-api'); ?></label></p>
                <button type="submit" class="button button-primary"><?php esc_html_e('Download JSON', 'post-press-api'); ?></button>
            </form>
        </div>
        <?php
    }
}
