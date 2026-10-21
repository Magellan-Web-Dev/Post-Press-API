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
 */
final class TransferView
{
    /**
     * @param Services $services Local field discovery and preset transfer services.
     */
    public function __construct(private readonly Services $services) {}

    /**
     * Renders the upload form or a local-field review of a parsed export.
     *
     * @param array<string,mixed>|null $data   Original validated envelope, if uploaded.
     * @param array<string,mixed>|null $review Candidate, field choices, and validation.
     * @param list<string>             $errors Submission errors.
     *
     * @return void
     */
    public function render(?array $data = null, ?array $review = null, array $errors = []): void
    {
        ?>
        <div class="wrap ppapi-wrap">
            <h1><?php esc_html_e('Import preset', 'post-press-api'); ?></h1>
            <p><a href="<?php echo esc_url(AdminPage::url()); ?>"><?php esc_html_e('All presets', 'post-press-api'); ?></a></p>
            <?php Notices::render_list('error', $errors); ?>
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
            <p><?php esc_html_e('Request settings, mappings, duplicate rules, pagination, schedule, and batch size are retained. Credentials are excluded. Importing does not fetch data or download images.', 'post-press-api'); ?></p>
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
            <?php if ($review['choices'] !== []) : ?>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Source mapping', 'post-press-api'); ?></th><th><?php esc_html_e('Local ACF field', 'post-press-api'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($review['choices'] as $path => $choice) : ?>
                        <tr><td><label for="<?php echo esc_attr('ppapi-import-' . $path); ?>"><code><?php echo esc_html($path); ?></code><br><?php echo esc_html(($choice['source']['name'] ?? $choice['original']) . ' (' . ($choice['source']['type'] ?? __('unknown type', 'post-press-api')) . ')'); ?></label></td>
                            <td><select name="ppapi_import_remaps[<?php echo esc_attr($path); ?>]" id="<?php echo esc_attr('ppapi-import-' . $path); ?>">
                                <option value=""><?php esc_html_e('— Choose a compatible field —', 'post-press-api'); ?></option>
                                <?php foreach ($choice['candidates'] as $key => $field) : ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($choice['selected'], $key); ?>><?php echo esc_html($field['group'] . ' › ' . $field['name'] . ' (' . $key . ')'); ?></option>
                                <?php endforeach; ?>
                            </select></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e('After changing the post type or a parent group/repeater, refresh matches to see its local subfields. Blank selections will be matched again where possible.', 'post-press-api'); ?></p>
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
