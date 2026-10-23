<?php

declare(strict_types=1);

namespace PostPressAPI\Admin\Views;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Admin\AdminPage;
use PostPressAPI\Admin\Format;
use PostPressAPI\Admin\Notices;
use PostPressAPI\Http\Url;
use PostPressAPI\Mapping\MappingCondition;
use PostPressAPI\Mapping\MappingSchema;
use PostPressAPI\Mapping\MarkupPolicy;
use PostPressAPI\Mapping\Transformer;
use PostPressAPI\Presets\PresetSchema;
use PostPressAPI\REST\RestController;
use PostPressAPI\Scheduling\Scheduler;
use PostPressAPI\Services;
use PostPressAPI\Sync\ImportRunner;
use PostPressAPI\Sync\PreviewService;

/**
 * The preset editor.
 *
 * Sections: general, request, response selection, field mappings, duplicate
 * handling, pagination, scheduling, and results. Validation errors appear in a
 * linked summary and next to each field. Credentials are never printed: the
 * credential field is always empty and only states whether one is saved.
 */
final class EditorView
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, string> Error keys → element IDs they link to. */
    private const ERROR_TARGETS = [
        'general'               => 'ppapi-name',
        'name'                  => 'ppapi-name',
        'enabled'               => 'ppapi-enabled',
        'request.url'           => 'ppapi-request-url',
        'request.query'         => 'ppapi-query-add',
        'request.headers'       => 'ppapi-headers-add',
        'request.body'          => 'ppapi-request-body',
        'request.auth'          => 'ppapi-auth-type',
        'request.timeout'       => 'ppapi-timeout',
        'request.max_kb'        => 'ppapi-max-kb',
        'request.retries'       => 'ppapi-retries',
        'response.records_path' => 'ppapi-records-path',
        'response.success_path' => 'ppapi-success-path',
        'response.identity'     => 'ppapi-identity-path',
        'sample'                => 'ppapi-sample',
        'destination.post_type' => 'ppapi-post-type',
        'destination.status'    => 'ppapi-default-status',
        'destination.author'    => 'ppapi-author',
        'mappings'              => 'ppapi-add-mapping',
        'sync.match'            => 'ppapi-match-type',
        'pagination'            => 'ppapi-pagination-none',
        'pagination.page_size'  => 'ppapi-page-size',
        'pagination.max_pages'  => 'ppapi-max-pages',
        'pagination.max_records' => 'ppapi-max-records',
        'pagination.start'      => 'ppapi-page-start',
        'schedule.minutes'      => 'ppapi-schedule-minutes',
        'batch_size'            => 'ppapi-batch-size',
    ];

    public function __construct(private readonly Services $services)
    {
    }

    /**
     * Renders the editor.
     *
     * @param array<string, mixed>         $preset   Preset (saved, new defaults, or submitted values after an error).
     * @param bool                         $is_new   Whether the preset has not been saved yet.
     * @param array<string, list<string>>  $errors   Validation errors by field.
     * @param list<string>                 $warnings Warnings.
     * @param string                       $sample   Sample JSON.
     *
     * @return void
     */
    public function render(array $preset, bool $is_new, array $errors, array $warnings, string $sample): void
    {
        $this->errors = $errors;
        $id           = (string) $preset['id'];
        $action_args  = $is_new ? ['view' => 'new'] : ['view' => 'edit', 'preset' => $id];
        $saved_secret = $is_new ? null : $this->services->secrets()->get($id);
        $readiness    = $is_new ? [] : $this->services->preset_validator()->readiness_problems(
            $preset,
            $this->services->secrets()->value_for_origin($id, Url::origin((string) $preset['request']['url'])) !== null
        );

        $this->enqueue_data($preset, $is_new, $sample);
        ?>
        <div class="wrap ppapi-wrap ppapi-editor">
            <h1 class="wp-heading-inline">
                <?php
                if ($is_new) {
                    esc_html_e('Add New Preset', 'post-press-api');
                } else {
                    printf(
                        /* translators: %s: preset name. */
                        esc_html__('Edit preset: %s', 'post-press-api'),
                        esc_html($preset['name'] !== '' ? $preset['name'] : __('(untitled preset)', 'post-press-api'))
                    );
                }
                ?>
            </h1>
            <a class="page-title-action" href="<?php echo esc_url(AdminPage::url()); ?>"><?php esc_html_e('All presets', 'post-press-api'); ?></a>
            <?php if (!$is_new) : ?>
                <a class="page-title-action" href="<?php echo esc_url(AdminPage::url(['view' => 'export', 'preset' => $id])); ?>"><?php esc_html_e('Export JSON', 'post-press-api'); ?></a>
                <a class="page-title-action" href="<?php echo esc_url(AdminPage::url(['view' => 'history', 'preset' => $id])); ?>"><?php esc_html_e('Run history', 'post-press-api'); ?></a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <?php
            Notices::render_from_query();
            $this->error_summary();
            Notices::render_list('warning', $warnings);

            if ($readiness !== [] && $errors === []) {
                Notices::render_list('info', array_map(
                    static fn (string $problem): string => sprintf(
                        /* translators: %s: missing setting. */
                        __('Before this preset can be enabled: %s', 'post-press-api'),
                        $problem
                    ),
                    $readiness
                ));
            }
            ?>

            <nav class="ppapi-section-nav" aria-label="<?php esc_attr_e('Preset sections', 'post-press-api'); ?>">
                <a href="#ppapi-section-request"><?php esc_html_e('Request', 'post-press-api'); ?></a>
                <a href="#ppapi-section-response"><?php esc_html_e('Response', 'post-press-api'); ?></a>
                <a href="#ppapi-section-mappings"><?php esc_html_e('Field mappings', 'post-press-api'); ?></a>
                <a href="#ppapi-section-duplicates"><?php esc_html_e('Duplicates', 'post-press-api'); ?></a>
                <a href="#ppapi-section-pagination"><?php esc_html_e('Pagination', 'post-press-api'); ?></a>
                <a href="#ppapi-section-schedule"><?php esc_html_e('Schedule', 'post-press-api'); ?></a>
                <a href="#ppapi-section-results"><?php esc_html_e('Preview & run', 'post-press-api'); ?></a>
            </nav>

            <form method="post" action="<?php echo esc_url(AdminPage::url($action_args)); ?>" id="ppapi-preset-form" class="ppapi-form" novalidate>
                <?php wp_nonce_field('ppapi_save_preset', '_ppapi_nonce'); ?>
                <input type="hidden" name="ppapi_action" value="save">
                <input type="hidden" name="preset_id" value="<?php echo esc_attr($is_new ? '' : $id); ?>">
                <input type="hidden" name="ppapi_mappings" id="ppapi-mappings-json" value="<?php echo esc_attr((string) wp_json_encode($preset['mappings'])); ?>">

                <?php
                $this->section_general($preset);
                $this->section_request($preset, $saved_secret);
                $this->section_response($preset, $sample);
                $this->section_mappings($preset);
                $this->section_duplicates($preset);
                $this->section_pagination($preset);
                $this->section_schedule($preset, $is_new);
                $this->section_results($preset, $is_new, $readiness);
                ?>

                <p class="submit ppapi-submit">
                    <button type="submit" class="button button-primary button-large"><?php echo $is_new ? esc_html__('Create preset', 'post-press-api') : esc_html__('Save preset', 'post-press-api'); ?></button>
                    <span class="ppapi-dirty-note" id="ppapi-dirty-note" hidden><?php esc_html_e('You have unsaved changes.', 'post-press-api'); ?></span>
                </p>
            </form>

            <datalist id="ppapi-record-paths"></datalist>
            <datalist id="ppapi-response-paths"></datalist>
            <datalist id="ppapi-acf-match-fields"></datalist>
            <datalist id="ppapi-timezones">
                <?php foreach (\DateTimeZone::listIdentifiers() as $zone) : ?>
                    <option value="<?php echo esc_attr($zone); ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <?php $this->templates(); ?>
        </div>
        <?php
    }

    private function section_general(array $preset): void
    {
        ?>
        <section class="ppapi-card" id="ppapi-section-general" aria-labelledby="ppapi-heading-general">
            <h2 id="ppapi-heading-general"><?php esc_html_e('General', 'post-press-api'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ppapi-name"><?php esc_html_e('Name', 'post-press-api'); ?> <span class="ppapi-required" aria-hidden="true">*</span></label></th>
                    <td>
                        <input type="text" class="regular-text" id="ppapi-name" name="ppapi[name]" value="<?php echo esc_attr((string) $preset['name']); ?>" required<?php $this->invalid('name', 'ppapi-name'); ?>>
                        <?php $this->field_errors('name', 'ppapi-name'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Enabled', 'post-press-api'); ?></th>
                    <td>
                        <label for="ppapi-enabled">
                            <input type="checkbox" id="ppapi-enabled" name="ppapi[enabled]" value="1" <?php checked((bool) $preset['enabled']); ?><?php $this->invalid('enabled', 'ppapi-enabled'); ?>>
                            <?php esc_html_e('Allow scheduled and manual runs', 'post-press-api'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Test Request and Dry Run work while the preset is disabled.', 'post-press-api'); ?></p>
                        <?php $this->field_errors('enabled', 'ppapi-enabled'); ?>
                    </td>
                </tr>
            </table>
        </section>
        <?php
    }

    /**
     * @param array{value: string, origin: string}|null $saved_secret Saved credential metadata.
     */
    private function section_request(array $preset, ?array $saved_secret): void
    {
        $request = $preset['request'];
        $auth    = $request['auth'];
        ?>
        <section class="ppapi-card" id="ppapi-section-request" aria-labelledby="ppapi-heading-request">
            <h2 id="ppapi-heading-request"><?php esc_html_e('1. Request', 'post-press-api'); ?></h2>
            <p class="ppapi-section-intro"><?php esc_html_e('The endpoint is called from this server. Private and local network addresses are refused.', 'post-press-api'); ?></p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ppapi-request-url"><?php esc_html_e('Endpoint URL', 'post-press-api'); ?></label></th>
                    <td>
                        <input type="url" class="large-text code" id="ppapi-request-url" name="ppapi[request][url]" value="<?php echo esc_attr((string) $request['url']); ?>" placeholder="https://api.example.com/v1/items" spellcheck="false"<?php $this->invalid('request.url', 'ppapi-request-url'); ?>>
                        <?php $this->field_errors('request.url', 'ppapi-request-url'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ppapi-request-method"><?php esc_html_e('Method', 'post-press-api'); ?></label></th>
                    <td>
                        <select id="ppapi-request-method" name="ppapi[request][method]">
                            <?php foreach (PresetSchema::METHODS as $method) : ?>
                                <option value="<?php echo esc_attr($method); ?>" <?php selected($request['method'], $method); ?>><?php echo esc_html($method); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Query parameters', 'post-press-api'); ?></th>
                    <td>
                        <?php $this->pairs_table('query', 'key', __('Name', 'post-press-api'), $request['query']); ?>
                        <?php $this->field_errors('request.query', 'ppapi-query-add'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Headers', 'post-press-api'); ?></th>
                    <td>
                        <?php $this->pairs_table('headers', 'name', __('Header', 'post-press-api'), $request['headers']); ?>
                        <p class="description"><?php esc_html_e('Headers are stored as plain settings and shown here. Put credentials in Authentication instead.', 'post-press-api'); ?></p>
                        <?php $this->field_errors('request.headers', 'ppapi-headers-add'); ?>
                    </td>
                </tr>
                <tr data-ppapi-show-for="#ppapi-request-method" data-ppapi-show-values="POST">
                    <th scope="row"><label for="ppapi-request-body"><?php esc_html_e('JSON body', 'post-press-api'); ?></label></th>
                    <td>
                        <textarea class="large-text code" rows="5" id="ppapi-request-body" name="ppapi[request][body]" spellcheck="false"<?php $this->invalid('request.body', 'ppapi-request-body', 'ppapi-request-body-help'); ?>><?php echo esc_textarea((string) $request['body']); ?></textarea>
                        <p class="description" id="ppapi-request-body-help"><?php esc_html_e('Optional. Sent with Content-Type: application/json.', 'post-press-api'); ?></p>
                        <?php $this->field_errors('request.body', 'ppapi-request-body'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ppapi-auth-type"><?php esc_html_e('Authentication', 'post-press-api'); ?></label></th>
                    <td>
                        <select id="ppapi-auth-type" name="ppapi[request][auth][type]"<?php $this->invalid('request.auth', 'ppapi-auth-type'); ?>>
                            <option value="none" <?php selected($auth['type'], 'none'); ?>><?php esc_html_e('None', 'post-press-api'); ?></option>
                            <option value="bearer" <?php selected($auth['type'], 'bearer'); ?>><?php esc_html_e('Bearer token (Authorization header)', 'post-press-api'); ?></option>
                            <option value="api_key_header" <?php selected($auth['type'], 'api_key_header'); ?>><?php esc_html_e('API key in a header', 'post-press-api'); ?></option>
                            <option value="api_key_query" <?php selected($auth['type'], 'api_key_query'); ?>><?php esc_html_e('API key in a query parameter', 'post-press-api'); ?></option>
                        </select>
                        <div class="ppapi-auth-fields" data-ppapi-show-for="#ppapi-auth-type" data-ppapi-show-values="bearer api_key_header api_key_query">
                            <p data-ppapi-show-for="#ppapi-auth-type" data-ppapi-show-values="api_key_header api_key_query">
                                <label for="ppapi-auth-name"><?php esc_html_e('Header or parameter name', 'post-press-api'); ?></label><br>
                                <input type="text" class="regular-text code" id="ppapi-auth-name" name="ppapi[request][auth][name]" value="<?php echo esc_attr((string) $auth['name']); ?>" placeholder="X-API-Key" spellcheck="false">
                            </p>
                            <p>
                                <label for="ppapi-secret"><?php esc_html_e('Credential', 'post-press-api'); ?></label><br>
                                <input type="password" class="regular-text" id="ppapi-secret" name="ppapi_secret" value="" autocomplete="new-password" spellcheck="false"
                                    placeholder="<?php echo esc_attr($saved_secret !== null ? __('Saved — leave blank to keep it', 'post-press-api') : __('Enter the token or key', 'post-press-api')); ?>"
                                    aria-describedby="ppapi-secret-status">
                            </p>
                            <p class="description" id="ppapi-secret-status">
                                <?php
                                if ($saved_secret !== null) {
                                    printf(
                                        /* translators: %s: origin the credential may be sent to. */
                                        esc_html__('A credential is saved for %s. It is never displayed again; type a new value to replace it.', 'post-press-api'),
                                        '<code>' . esc_html($saved_secret['origin']) . '</code>'
                                    );
                                } else {
                                    esc_html_e('No credential is saved. It is stored server-side and only sent to this endpoint\'s host.', 'post-press-api');
                                }
                                ?>
                            </p>
                            <?php if ($saved_secret !== null) : ?>
                                <p>
                                    <label for="ppapi-secret-remove">
                                        <input type="checkbox" id="ppapi-secret-remove" name="ppapi_secret_remove" value="1">
                                        <?php esc_html_e('Remove the saved credential', 'post-press-api'); ?>
                                    </label>
                                </p>
                            <?php endif; ?>
                        </div>
                        <?php $this->field_errors('request.auth', 'ppapi-auth-type'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Limits', 'post-press-api'); ?></th>
                    <td class="ppapi-inline-fields">
                        <?php
                        $this->number_field('ppapi-timeout', 'ppapi[request][timeout]', (int) $request['timeout'], __('Timeout (seconds)', 'post-press-api'), 'timeout', 'request.timeout');
                        $this->number_field('ppapi-max-kb', 'ppapi[request][max_kb]', (int) $request['max_kb'], __('Maximum response size (KB)', 'post-press-api'), 'max_kb', 'request.max_kb');
                        $this->number_field('ppapi-retries', 'ppapi[request][retries]', (int) $request['retries'], __('Retries for temporary failures', 'post-press-api'), 'retries', 'request.retries');
                        ?>
                        <p class="description"><?php esc_html_e('Network errors, timeouts, HTTP 408/425/429, and 5xx responses are retried with increasing waits (or the Retry-After the API sends). A response that reaches the size limit is rejected rather than imported partially.', 'post-press-api'); ?></p>
                    </td>
                </tr>
            </table>
        </section>
        <?php
    }

    private function section_response(array $preset, string $sample): void
    {
        $response = $preset['response'];
        $identity = $response['identity'];
        ?>
        <section class="ppapi-card" id="ppapi-section-response" aria-labelledby="ppapi-heading-response">
            <h2 id="ppapi-heading-response"><?php esc_html_e('2. Response selection', 'post-press-api'); ?></h2>
            <p class="ppapi-section-intro"><?php esc_html_e('First select the list of records in the response, then the record identity. Mapping paths later resolve inside each record.', 'post-press-api'); ?></p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ppapi-records-path"><?php esc_html_e('Records path', 'post-press-api'); ?></label></th>
                    <td>
                        <input type="text" class="regular-text code" id="ppapi-records-path" name="ppapi[response][records_path]" value="<?php echo esc_attr((string) $response['records_path']); ?>" list="ppapi-response-paths" placeholder="data" spellcheck="false"<?php $this->invalid('response.records_path', 'ppapi-records-path', 'ppapi-records-path-help'); ?>>
                        <p class="description" id="ppapi-records-path-help"><?php echo wp_kses(__('Path to the array of records, e.g. <code>data</code>. Use <code>$</code> when the response itself is the array, or <code>items[*]</code> to treat an object\'s values as records.', 'post-press-api'), ['code' => []]); ?></p>
                        <?php $this->field_errors('response.records_path', 'ppapi-records-path'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ppapi-success-path"><?php esc_html_e('Success flag (optional)', 'post-press-api'); ?></label></th>
                    <td class="ppapi-inline-fields">
                        <span class="ppapi-inline-field">
                            <input type="text" class="regular-text code" id="ppapi-success-path" name="ppapi[response][success_path]" value="<?php echo esc_attr((string) $response['success_path']); ?>" list="ppapi-response-paths" placeholder="ok" spellcheck="false"<?php $this->invalid('response.success_path', 'ppapi-success-path'); ?>>
                        </span>
                        <span class="ppapi-inline-field">
                            <label for="ppapi-success-value"><?php esc_html_e('must equal', 'post-press-api'); ?></label>
                            <input type="text" class="small-text code" id="ppapi-success-value" name="ppapi[response][success_value]" value="<?php echo esc_attr((string) $response['success_value']); ?>" spellcheck="false">
                        </span>
                        <p class="description"><?php esc_html_e('When set, a response whose flag does not match imports nothing and the run fails with the reason.', 'post-press-api'); ?></p>
                        <?php $this->field_errors('response.success_path', 'ppapi-success-path'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Record identity', 'post-press-api'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Record identity', 'post-press-api'); ?></legend>
                            <label><input type="radio" name="ppapi[response][identity][mode]" value="path" <?php checked($identity['mode'], 'path'); ?>> <?php esc_html_e('A stable external ID field', 'post-press-api'); ?></label><br>
                            <label><input type="radio" name="ppapi[response][identity][mode]" value="composite" <?php checked($identity['mode'], 'composite'); ?>> <?php esc_html_e('A combination of fields (for feeds without IDs)', 'post-press-api'); ?></label>
                        </fieldset>
                        <p data-ppapi-show-for="input[name='ppapi[response][identity][mode]']" data-ppapi-show-values="path">
                            <label for="ppapi-identity-path"><?php esc_html_e('External ID path (inside each record)', 'post-press-api'); ?></label><br>
                            <input type="text" class="regular-text code" id="ppapi-identity-path" name="ppapi[response][identity][path]" value="<?php echo esc_attr((string) $identity['path']); ?>" list="ppapi-record-paths" placeholder="id" spellcheck="false"<?php $this->invalid('response.identity', 'ppapi-identity-path'); ?>>
                        </p>
                        <div data-ppapi-show-for="input[name='ppapi[response][identity][mode]']" data-ppapi-show-values="composite">
                            <label for="ppapi-identity-paths"><?php esc_html_e('Identity paths, one per line (up to 5)', 'post-press-api'); ?></label><br>
                            <textarea class="regular-text code" rows="3" id="ppapi-identity-paths" name="ppapi[response][identity][paths]" spellcheck="false"><?php echo esc_textarea(implode("\n", (array) $identity['paths'])); ?></textarea>
                            <p class="description"><?php esc_html_e('If any of these values changes in the API, the record gets a new identity and is imported as a new post.', 'post-press-api'); ?></p>
                        </div>
                        <p class="description"><?php esc_html_e('Identity is this preset + the destination post type + the external ID, stored as text exactly as received. Records without a usable identity are reported and skipped, never imported with an empty identity. Titles are never used to detect duplicates.', 'post-press-api'); ?></p>
                        <?php $this->field_errors('response.identity', 'ppapi-identity-path'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ppapi-sample"><?php esc_html_e('Sample JSON (optional)', 'post-press-api'); ?></label></th>
                    <td>
                        <textarea class="large-text code" rows="6" id="ppapi-sample" name="ppapi_sample" spellcheck="false"<?php $this->invalid('sample', 'ppapi-sample', 'ppapi-sample-help'); ?>><?php echo esc_textarea($sample); ?></textarea>
                        <p class="description" id="ppapi-sample-help"><?php esc_html_e('Paste a response to configure mappings offline. It is saved with the preset (do not paste credentials) and is only used when you choose it below.', 'post-press-api'); ?></p>
                        <?php $this->field_errors('sample', 'ppapi-sample'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Test', 'post-press-api'); ?></th>
                    <td>
                        <fieldset class="ppapi-source-choice">
                            <legend><?php esc_html_e('Data for Test Request and Dry Run', 'post-press-api'); ?></legend>
                            <label><input type="radio" name="use_sample" value="" checked> <?php esc_html_e('Request the API now', 'post-press-api'); ?></label>
                            <label><input type="radio" name="use_sample" value="1"> <?php esc_html_e('Use the pasted sample JSON', 'post-press-api'); ?></label>
                        </fieldset>
                        <p>
                            <button type="button" class="button" id="ppapi-test-request" data-ppapi-requires-js><?php esc_html_e('Test request', 'post-press-api'); ?></button>
                            <span class="description"><?php esc_html_e('Fetches the first page (or reads the sample) and lists its paths without importing anything. Unsaved changes are used.', 'post-press-api'); ?></span>
                        </p>
                        <div id="ppapi-test-result" class="ppapi-result" role="region" aria-live="polite" aria-label="<?php esc_attr_e('Test request result', 'post-press-api'); ?>"></div>
                    </td>
                </tr>
            </table>
        </section>
        <?php
    }

    private function section_mappings(array $preset): void
    {
        $destination = $preset['destination'];
        $registry    = $this->services->destinations();
        ?>
        <section class="ppapi-card" id="ppapi-section-mappings" aria-labelledby="ppapi-heading-mappings">
            <h2 id="ppapi-heading-mappings"><?php esc_html_e('3. Field mappings', 'post-press-api'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ppapi-post-type"><?php esc_html_e('Destination post type', 'post-press-api'); ?></label></th>
                    <td>
                        <select id="ppapi-post-type" name="ppapi[destination][post_type]"<?php $this->invalid('destination.post_type', 'ppapi-post-type'); ?>>
                            <option value=""><?php esc_html_e('— Select —', 'post-press-api'); ?></option>
                            <?php foreach ($registry->post_types() as $name => $label) : ?>
                                <option value="<?php echo esc_attr($name); ?>" <?php selected($destination['post_type'], $name); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Only existing post types are listed; this plugin never registers post types or fields.', 'post-press-api'); ?></p>
                        <?php $this->field_errors('destination.post_type', 'ppapi-post-type'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ppapi-default-status"><?php esc_html_e('Status for new posts', 'post-press-api'); ?></label></th>
                    <td>
                        <select id="ppapi-default-status" name="ppapi[destination][status]"<?php $this->invalid('destination.status', 'ppapi-default-status'); ?>>
                            <?php foreach ($registry->core()->allowed_statuses() as $status) : ?>
                                <?php $object = get_post_status_object($status); ?>
                                <option value="<?php echo esc_attr($status); ?>" <?php selected($destination['status'], $status); ?>><?php echo esc_html($object->label ?? $status); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Applied only when a post is created. A record\'s own status field is never used as the post status unless you map it with a Value map.', 'post-press-api'); ?></p>
                        <?php $this->field_errors('destination.status', 'ppapi-default-status'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ppapi-author"><?php esc_html_e('Author for new posts', 'post-press-api'); ?></label></th>
                    <td>
                        <?php
                        wp_dropdown_users([
                            'name'       => 'ppapi[destination][author]',
                            'id'         => 'ppapi-author',
                            'selected'   => (int) $destination['author'] > 0 ? (int) $destination['author'] : get_current_user_id(),
                            'capability' => ['edit_posts'],
                            'show'       => 'display_name_with_login',
                        ]);
                        $this->field_errors('destination.author', 'ppapi-author');
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ppapi-markup"><?php esc_html_e('Shortcodes and blocks in imported content', 'post-press-api'); ?></label></th>
                    <td>
                        <select id="ppapi-markup" name="ppapi[destination][markup]" aria-describedby="ppapi-markup-help"<?php $this->invalid('destination.markup', 'ppapi-markup'); ?>>
                            <option value="<?php echo esc_attr(MarkupPolicy::NEUTRALIZE); ?>" <?php selected($destination['markup'], MarkupPolicy::NEUTRALIZE); ?>><?php esc_html_e('Neutralize (recommended)', 'post-press-api'); ?></option>
                            <option value="<?php echo esc_attr(MarkupPolicy::ALLOWLIST); ?>" <?php selected($destination['markup'], MarkupPolicy::ALLOWLIST); ?>><?php esc_html_e('Allow only listed shortcodes', 'post-press-api'); ?></option>
                            <option value="<?php echo esc_attr(MarkupPolicy::TRUSTED); ?>" <?php selected($destination['markup'], MarkupPolicy::TRUSTED); ?>><?php esc_html_e('Trusted source: keep all shortcodes and blocks', 'post-press-api'); ?></option>
                        </select>
                        <span data-ppapi-show-for="#ppapi-markup" data-ppapi-show-values="allowlist">
                            <label for="ppapi-shortcodes" class="screen-reader-text"><?php esc_html_e('Allowed shortcodes', 'post-press-api'); ?></label>
                            <input type="text" class="regular-text" id="ppapi-shortcodes" name="ppapi[destination][shortcodes]" value="<?php echo esc_attr(implode(', ', (array) $destination['shortcodes'])); ?>" placeholder="<?php esc_attr_e('gallery, caption', 'post-press-api'); ?>">
                        </span>
                        <p class="description" id="ppapi-markup-help"><?php esc_html_e('Applies to post content, excerpts, and ACF WYSIWYG fields. HTML filtering keeps [shortcodes] and block markup, which run whatever matching code is installed when the post is displayed. Neutralize shows shortcodes as plain text and imports blocks as plain HTML. A new setting applies from the next run: posts whose imported content contains shortcodes or block markup are then updated with the new handling; other posts are not touched.', 'post-press-api'); ?></p>
                        <?php if ($destination['markup'] === MarkupPolicy::TRUSTED) : ?>
                            <p class="description"><strong><?php esc_html_e('Imported content may run any shortcode or block installed on this site. Presets saved before this setting existed use this mode until you choose another.', 'post-press-api'); ?></strong></p>
                        <?php endif; ?>
                        <?php $this->field_errors('destination.markup', 'ppapi-markup'); ?>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e('Mappings', 'post-press-api'); ?></h3>
            <p class="ppapi-section-intro">
                <?php esc_html_e('Each mapping reads a source path, a constant, or a template from the record and writes it to a post field, a custom meta key, or an ACF field. On updates only mapped fields change; missing or null values keep the existing value unless you choose otherwise.', 'post-press-api'); ?>
            </p>
            <div id="ppapi-mappings" class="ppapi-mappings" aria-live="polite"></div>
            <p>
                <button type="button" class="button" id="ppapi-add-mapping" data-ppapi-requires-js<?php $this->invalid('mappings', 'ppapi-add-mapping'); ?>><?php esc_html_e('Add mapping', 'post-press-api'); ?></button>
            </p>
            <noscript><p class="notice notice-warning inline"><?php esc_html_e('The mapping editor needs JavaScript. Existing mappings are kept when you save without it.', 'post-press-api'); ?></p></noscript>
            <?php $this->field_errors('mappings', 'ppapi-add-mapping'); ?>

            <details class="ppapi-help">
                <summary><?php esc_html_e('Path and template syntax', 'post-press-api'); ?></summary>
                <ul>
                    <li><code>event.title</code> — <?php esc_html_e('nested keys', 'post-press-api'); ?></li>
                    <li><code>sessions.0.date</code>, <code>sessions[0].date</code> — <?php esc_html_e('array index', 'post-press-api'); ?></li>
                    <li><code>items[-1]</code>, <code>products[-1].name</code>, <code>groups[-1].members[-1].email</code> — <?php esc_html_e('the last item of any array, with optional nested keys; use [-1] when the current value itself is an array', 'post-press-api'); ?></li>
                    <li><code>sessions[*].date</code>, <code>dates_text[*]</code> — <?php esc_html_e('every item (a list; use Join to make text)', 'post-press-api'); ?></li>
                    <li><code>["key.with.dots"]</code>, <code>meta['a[b]']</code> — <?php esc_html_e('keys containing ".", "[", "]" or starting with "$"', 'post-press-api'); ?></li>
                    <li><code>$</code> — <?php esc_html_e('the whole record (or the current repeater item)', 'post-press-api'); ?></li>
                    <li><code>{event.title} – {website.host}</code> — <?php esc_html_e('template placeholders; {{ and }} write literal braces', 'post-press-api'); ?></li>
                </ul>
                <p><?php esc_html_e('[-1] selects the final item in the supplied order, even when it is null. An empty list is missing. Other negative indices are not supported.', 'post-press-api'); ?></p>
                <p><?php esc_html_e('Missing keys and null values are handled separately; false, 0, empty text, and empty lists are real values. Fallback values skip the transformation and must already be in the destination\'s format. Repeater rows are rebuilt from the source list on every write, so values from one source item (such as a session\'s date and time) always stay in the same row.', 'post-press-api'); ?></p>
            </details>
        </section>
        <?php
    }

    private function section_duplicates(array $preset): void
    {
        $sync = $preset['sync'];
        ?>
        <section class="ppapi-card" id="ppapi-section-duplicates" aria-labelledby="ppapi-heading-duplicates">
            <h2 id="ppapi-heading-duplicates"><?php esc_html_e('4. Duplicate handling', 'post-press-api'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('When a record already has a post', 'post-press-api'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Duplicate policy', 'post-press-api'); ?></legend>
                            <label><input type="radio" name="ppapi[sync][policy]" value="update_changed" <?php checked($sync['policy'], 'update_changed'); ?>> <strong><?php esc_html_e('Update changed records', 'post-press-api'); ?></strong> — <?php esc_html_e('create new records, update matches whose mapped values changed, skip unchanged ones (default).', 'post-press-api'); ?></label><br>
                            <label><input type="radio" name="ppapi[sync][policy]" value="skip_existing" <?php checked($sync['policy'], 'skip_existing'); ?>> <strong><?php esc_html_e('Skip existing records', 'post-press-api'); ?></strong> — <?php esc_html_e('create new records and leave existing posts alone.', 'post-press-api'); ?></label><br>
                            <label><input type="radio" name="ppapi[sync][policy]" value="always_create" <?php checked($sync['policy'], 'always_create'); ?>> <strong><?php esc_html_e('Always create', 'post-press-api'); ?></strong> — <?php esc_html_e('every run creates new posts; duplicates are intended.', 'post-press-api'); ?></label>
                        </fieldset>
                        <p class="description"><?php esc_html_e('Changes are detected with a fingerprint of the mapped values only, so unmapped API fields such as updated_at never cause updates. A local edit to a mapped field is kept until that record\'s source value (or the mapping) changes; then all mapped fields are written again. Unmapped fields are never touched, and posts are never deleted when records disappear from the API.', 'post-press-api'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ppapi-match-type"><?php esc_html_e('Connect existing posts', 'post-press-api'); ?></label></th>
                    <td>
                        <select id="ppapi-match-type" name="ppapi[sync][match][type]"<?php $this->invalid('sync.match', 'ppapi-match-type'); ?>>
                            <option value="none" <?php selected($sync['match']['type'], 'none'); ?>><?php esc_html_e('No — only posts this preset created', 'post-press-api'); ?></option>
                            <option value="meta" <?php selected($sync['match']['type'], 'meta'); ?>><?php esc_html_e('Match a custom meta key holding the external ID', 'post-press-api'); ?></option>
                            <option value="acf" <?php selected($sync['match']['type'], 'acf'); ?>><?php esc_html_e('Match an ACF field holding the external ID', 'post-press-api'); ?></option>
                        </select>
                        <p data-ppapi-show-for="#ppapi-match-type" data-ppapi-show-values="meta acf">
                            <label for="ppapi-match-key"><?php esc_html_e('Meta key or ACF field key', 'post-press-api'); ?></label><br>
                            <input type="text" class="regular-text code" id="ppapi-match-key" name="ppapi[sync][match][key]" value="<?php echo esc_attr((string) $sync['match']['key']); ?>" list="ppapi-acf-match-fields" spellcheck="false">
                        </p>
                        <p class="description"><?php esc_html_e('Posts created by this preset are always found by its own identity. Matching by field searches every post of the destination type (not just this preset\'s); a post linked to a different preset is never taken over, and if several posts match, the record is reported instead of guessing.', 'post-press-api'); ?></p>
                        <?php $this->field_errors('sync.match', 'ppapi-match-type'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Trashed matches', 'post-press-api'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Trashed matches', 'post-press-api'); ?></legend>
                            <label><input type="radio" name="ppapi[sync][trashed]" value="skip" <?php checked($sync['trashed'], 'skip'); ?>> <?php esc_html_e('Leave trashed posts in the trash and skip the record (default)', 'post-press-api'); ?></label><br>
                            <label><input type="radio" name="ppapi[sync][trashed]" value="restore" <?php checked($sync['trashed'], 'restore'); ?>> <?php esc_html_e('Restore them as drafts and update them', 'post-press-api'); ?></label>
                        </fieldset>
                        <p class="description"><?php esc_html_e('Matching includes drafts, pending, private, scheduled, and trashed posts, so a trashed record is never imported a second time.', 'post-press-api'); ?></p>
                    </td>
                </tr>
            </table>
        </section>
        <?php
    }

    private function section_pagination(array $preset): void
    {
        $page = $preset['pagination'];
        $show = "input[name='ppapi[pagination][type]']";
        ?>
        <section class="ppapi-card" id="ppapi-section-pagination" aria-labelledby="ppapi-heading-pagination">
            <h2 id="ppapi-heading-pagination"><?php esc_html_e('5. Pagination', 'post-press-api'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Type', 'post-press-api'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Pagination type', 'post-press-api'); ?></legend>
                            <label><input type="radio" id="ppapi-pagination-none" name="ppapi[pagination][type]" value="none" <?php checked($page['type'], 'none'); ?>> <?php esc_html_e('No pagination (one request)', 'post-press-api'); ?></label><br>
                            <label><input type="radio" name="ppapi[pagination][type]" value="offset" <?php checked($page['type'], 'offset'); ?>> <?php esc_html_e('Offset and limit', 'post-press-api'); ?></label><br>
                            <label><input type="radio" name="ppapi[pagination][type]" value="page" <?php checked($page['type'], 'page'); ?>> <?php esc_html_e('Page number', 'post-press-api'); ?></label>
                        </fieldset>
                        <?php $this->field_errors('pagination', 'ppapi-pagination-none'); ?>
                    </td>
                </tr>
                <tr data-ppapi-show-for="<?php echo esc_attr($show); ?>" data-ppapi-show-values="offset page">
                    <th scope="row"><?php esc_html_e('Parameters', 'post-press-api'); ?></th>
                    <td class="ppapi-inline-fields">
                        <span class="ppapi-inline-field" data-ppapi-show-for="<?php echo esc_attr($show); ?>" data-ppapi-show-values="offset">
                            <label for="ppapi-offset-param"><?php esc_html_e('Offset parameter', 'post-press-api'); ?></label>
                            <input type="text" class="regular-text code" id="ppapi-offset-param" name="ppapi[pagination][offset_param]" value="<?php echo esc_attr((string) $page['offset_param']); ?>" spellcheck="false">
                        </span>
                        <span class="ppapi-inline-field" data-ppapi-show-for="<?php echo esc_attr($show); ?>" data-ppapi-show-values="page">
                            <label for="ppapi-page-param"><?php esc_html_e('Page parameter', 'post-press-api'); ?></label>
                            <input type="text" class="regular-text code" id="ppapi-page-param" name="ppapi[pagination][page_param]" value="<?php echo esc_attr((string) $page['page_param']); ?>" spellcheck="false">
                        </span>
                        <span class="ppapi-inline-field">
                            <label for="ppapi-limit-param"><?php esc_html_e('Limit / page-size parameter', 'post-press-api'); ?></label>
                            <input type="text" class="regular-text code" id="ppapi-limit-param" name="ppapi[pagination][limit_param]" value="<?php echo esc_attr((string) $page['limit_param']); ?>" spellcheck="false">
                        </span>
                        <?php
                        $this->number_field('ppapi-page-start', 'ppapi[pagination][start]', (int) $page['start'], __('Initial offset (or first page)', 'post-press-api'), 'start', 'pagination.start');
                        $this->number_field('ppapi-page-size', 'ppapi[pagination][page_size]', (int) $page['page_size'], __('Page size', 'post-press-api'), 'page_size', 'pagination.page_size');
                        ?>
                        <span class="ppapi-inline-field">
                            <label for="ppapi-placement"><?php esc_html_e('Send parameters in', 'post-press-api'); ?></label>
                            <select id="ppapi-placement" name="ppapi[pagination][placement]">
                                <option value="query" <?php selected($page['placement'], 'query'); ?>><?php esc_html_e('Query string', 'post-press-api'); ?></option>
                                <option value="body" <?php selected($page['placement'], 'body'); ?>><?php esc_html_e('JSON body (POST only)', 'post-press-api'); ?></option>
                            </select>
                        </span>
                    </td>
                </tr>
                <tr data-ppapi-show-for="<?php echo esc_attr($show); ?>" data-ppapi-show-values="offset page">
                    <th scope="row"><?php esc_html_e('When to stop', 'post-press-api'); ?></th>
                    <td class="ppapi-inline-fields">
                        <span class="ppapi-inline-field">
                            <label for="ppapi-has-more"><?php esc_html_e('Has-more path', 'post-press-api'); ?></label>
                            <input type="text" class="regular-text code" id="ppapi-has-more" name="ppapi[pagination][has_more_path]" value="<?php echo esc_attr((string) $page['has_more_path']); ?>" list="ppapi-response-paths" placeholder="pagination.has_more" spellcheck="false">
                        </span>
                        <span class="ppapi-inline-field">
                            <label for="ppapi-total-path"><?php esc_html_e('Total path (optional)', 'post-press-api'); ?></label>
                            <input type="text" class="regular-text code" id="ppapi-total-path" name="ppapi[pagination][total_path]" value="<?php echo esc_attr((string) $page['total_path']); ?>" list="ppapi-response-paths" placeholder="pagination.total" spellcheck="false">
                        </span>
                        <?php
                        $this->number_field('ppapi-max-pages', 'ppapi[pagination][max_pages]', (int) $page['max_pages'], __('Maximum pages per run', 'post-press-api'), 'max_pages', 'pagination.max_pages');
                        $this->number_field('ppapi-max-records', 'ppapi[pagination][max_records]', (int) $page['max_records'], __('Maximum records per run', 'post-press-api'), 'max_records', 'pagination.max_records');
                        ?>
                        <p class="description"><?php esc_html_e('Runs continue while the has-more flag is true (or, without one, while pages are full or below the total). Empty pages end the run; repeated pages or a missing has-more flag stop it with an error; hitting a safety limit while more records remain marks the run "stopped early", never complete.', 'post-press-api'); ?></p>
                    </td>
                </tr>
            </table>
        </section>
        <?php
    }

    private function section_schedule(array $preset, bool $is_new): void
    {
        $schedule = $preset['schedule'];
        $next     = $is_new ? null : $this->services->scheduler()->next_run((string) $preset['id']);
        ?>
        <section class="ppapi-card" id="ppapi-section-schedule" aria-labelledby="ppapi-heading-schedule">
            <h2 id="ppapi-heading-schedule"><?php esc_html_e('6. Schedule', 'post-press-api'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ppapi-frequency"><?php esc_html_e('Run automatically', 'post-press-api'); ?></label></th>
                    <td>
                        <select id="ppapi-frequency" name="ppapi[schedule][frequency]">
                            <?php foreach (Format::frequencies() as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($schedule['frequency'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span data-ppapi-show-for="#ppapi-frequency" data-ppapi-show-values="custom">
                            <label for="ppapi-schedule-minutes"><?php esc_html_e('every', 'post-press-api'); ?></label>
                            <input type="number" class="small-text" id="ppapi-schedule-minutes" name="ppapi[schedule][minutes]" value="<?php echo esc_attr((string) $schedule['minutes']); ?>" min="<?php echo esc_attr((string) PresetSchema::BOUNDS['custom_minutes'][0]); ?>" max="<?php echo esc_attr((string) PresetSchema::BOUNDS['custom_minutes'][1]); ?>"<?php $this->invalid('schedule.minutes', 'ppapi-schedule-minutes'); ?>>
                            <?php esc_html_e('minutes (minimum 5)', 'post-press-api'); ?>
                        </span>
                        <?php $this->field_errors('schedule.minutes', 'ppapi-schedule-minutes'); ?>
                        <p class="description">
                            <?php
                            if (!$preset['enabled'] || Scheduler::recurrence($preset) === null) {
                                esc_html_e('No scheduled runs: the preset is disabled or manual-only.', 'post-press-api');
                            } elseif ($next !== null) {
                                printf(
                                    /* translators: %s: date and time. */
                                    esc_html__('Next scheduled run: %s (site time zone).', 'post-press-api'),
                                    esc_html(Format::datetime($next))
                                );
                            } else {
                                esc_html_e('Not currently scheduled. Save the preset to schedule it.', 'post-press-api');
                            }
                            ?>
                        </p>
                        <p class="description">
                            <?php
                            $account = $is_new ? wp_get_current_user() : get_userdata(ImportRunner::scheduled_account($preset));
                            printf(
                                /* translators: %s: user display name. */
                                esc_html__('Scheduled runs act as %s — the user who last saved or enabled this preset — and can only change posts that account may edit. Saving the preset makes your account the scheduled account.', 'post-press-api'),
                                $account instanceof \WP_User && $account->exists() ? esc_html($account->display_name) : esc_html__('no existing user (save the preset to assign your account)', 'post-press-api')
                            );
                            ?>
                        </p>
                        <p class="description"><?php esc_html_e('WP-Cron only runs when the site receives visits, so times are approximate. For dependable timing, set DISABLE_WP_CRON in wp-config.php and have the server call wp-cron.php (or run "wp cron event run --due-now") every few minutes.', 'post-press-api'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ppapi-batch-size"><?php esc_html_e('Records per batch', 'post-press-api'); ?></label></th>
                    <td>
                        <input type="number" class="small-text" id="ppapi-batch-size" name="ppapi[batch_size]" value="<?php echo esc_attr((string) $preset['batch_size']); ?>" min="1" max="200"<?php $this->invalid('batch_size', 'ppapi-batch-size'); ?>>
                        <p class="description"><?php esc_html_e('Large imports are processed in small batches with saved progress, so no single request runs for long.', 'post-press-api'); ?></p>
                        <?php $this->field_errors('batch_size', 'ppapi-batch-size'); ?>
                    </td>
                </tr>
            </table>
        </section>
        <?php
    }

    /**
     * @param list<string> $readiness Readiness problems.
     */
    private function section_results(array $preset, bool $is_new, array $readiness): void
    {
        $history = $is_new ? [] : $this->services->runs()->history((string) $preset['id']);
        ?>
        <section class="ppapi-card" id="ppapi-section-results" aria-labelledby="ppapi-heading-results">
            <h2 id="ppapi-heading-results"><?php esc_html_e('7. Preview & run', 'post-press-api'); ?></h2>

            <h3><?php esc_html_e('Preview / dry run', 'post-press-api'); ?></h3>
            <p>
                <button type="button" class="button" id="ppapi-dry-run" data-ppapi-requires-js><?php esc_html_e('Preview / dry run', 'post-press-api'); ?></button>
                <span class="description">
                    <?php
                    printf(
                        /* translators: %d: number of records. */
                        esc_html__('Evaluates identity, mappings, matching, and change detection for the first %d records using the data source chosen under Response selection. Nothing is written.', 'post-press-api'),
                        (int) PreviewService::DRY_RUN_RECORDS
                    );
                    ?>
                </span>
            </p>
            <div id="ppapi-dry-run-result" class="ppapi-result" role="region" aria-live="polite" aria-label="<?php esc_attr_e('Dry run result', 'post-press-api'); ?>"></div>

            <h3><?php esc_html_e('Run now', 'post-press-api'); ?></h3>
            <?php if ($is_new) : ?>
                <p><?php esc_html_e('Save the preset first.', 'post-press-api'); ?></p>
            <?php else : ?>
                <?php if (!$preset['enabled'] || $readiness !== []) : ?>
                    <p class="ppapi-text-warning"><?php esc_html_e('Runs use the saved settings. Complete and enable the preset, then save, to run it.', 'post-press-api'); ?></p>
                <?php else : ?>
                    <p class="description"><?php esc_html_e('Runs use the saved settings (a snapshot taken when the run starts). Save your changes first. The run continues in the background if you leave this page.', 'post-press-api'); ?></p>
                <?php endif; ?>
                <p class="ppapi-run-controls">
                    <button type="button" class="button button-primary" id="ppapi-run-now" data-ppapi-requires-js <?php disabled(!$preset['enabled'] || $readiness !== []); ?>><?php esc_html_e('Run now', 'post-press-api'); ?></button>
                    <label for="ppapi-run-force">
                        <input type="checkbox" id="ppapi-run-force">
                        <?php esc_html_e('Re-apply mapped values to unchanged posts', 'post-press-api'); ?>
                    </label>
                    <button type="button" class="button" id="ppapi-run-cancel" hidden><?php esc_html_e('Cancel run', 'post-press-api'); ?></button>
                </p>
                <div id="ppapi-run-status" class="ppapi-result ppapi-run-status" role="status" aria-live="polite"></div>

                <h3><?php esc_html_e('Recent runs', 'post-press-api'); ?></h3>
                <?php HistoryView::table($history, 5, (string) $preset['id']); ?>
                <?php if (count($history) > 5) : ?>
                    <p><a href="<?php echo esc_url(AdminPage::url(['view' => 'history', 'preset' => $preset['id']])); ?>"><?php esc_html_e('View all runs', 'post-press-api'); ?></a></p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * Repeatable name/value rows for query parameters and headers.
     *
     * @param string                          $kind  query or headers.
     * @param string                          $key   Name key in each row (key or name).
     * @param string                          $label Column label.
     * @param list<array<string, string>>     $rows  Rows.
     */
    private function pairs_table(string $kind, string $key, string $label, array $rows): void
    {
        ?>
        <table class="ppapi-pairs" data-ppapi-pairs="<?php echo esc_attr($kind); ?>">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html($label); ?></th>
                    <th scope="col"><?php esc_html_e('Value', 'post-press-api'); ?></th>
                    <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'post-press-api'); ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $index => $row) : ?>
                    <?php $this->pair_row($kind, $key, (string) $index, (string) ($row[$key] ?? ''), (string) ($row['value'] ?? '')); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <button type="button" class="button button-small" id="ppapi-<?php echo esc_attr($kind); ?>-add" data-ppapi-add-pair="<?php echo esc_attr($kind); ?>" data-ppapi-requires-js>
            <?php echo $kind === 'query' ? esc_html__('Add parameter', 'post-press-api') : esc_html__('Add header', 'post-press-api'); ?>
        </button>
        <template id="ppapi-tpl-<?php echo esc_attr($kind); ?>"><?php $this->pair_row($kind, $key, '__i__', '', ''); ?></template>
        <?php
    }

    private function pair_row(string $kind, string $key, string $index, string $name, string $value): void
    {
        $base = 'ppapi[request][' . $kind . '][' . $index . ']';
        $id   = 'ppapi-' . $kind . '-' . $index;
        ?>
        <tr>
            <td>
                <label class="screen-reader-text" for="<?php echo esc_attr($id . '-name'); ?>"><?php esc_html_e('Name', 'post-press-api'); ?></label>
                <input type="text" class="code" id="<?php echo esc_attr($id . '-name'); ?>" name="<?php echo esc_attr($base . '[' . $key . ']'); ?>" value="<?php echo esc_attr($name); ?>" spellcheck="false">
            </td>
            <td>
                <label class="screen-reader-text" for="<?php echo esc_attr($id . '-value'); ?>"><?php esc_html_e('Value', 'post-press-api'); ?></label>
                <input type="text" class="code" id="<?php echo esc_attr($id . '-value'); ?>" name="<?php echo esc_attr($base . '[value]'); ?>" value="<?php echo esc_attr($value); ?>" spellcheck="false">
            </td>
            <td>
                <button type="button" class="button-link ppapi-link-delete" data-ppapi-remove-pair><?php esc_html_e('Remove', 'post-press-api'); ?></button>
            </td>
        </tr>
        <?php
    }

    private function number_field(string $id, string $name, int $value, string $label, string $bound, string $error_key): void
    {
        [$min, $max] = PresetSchema::BOUNDS[$bound];
        ?>
        <span class="ppapi-inline-field">
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
            <input type="number" class="small-text" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>" min="<?php echo esc_attr((string) $min); ?>" max="<?php echo esc_attr((string) $max); ?>"<?php $this->invalid($error_key, $id); ?>>
            <?php $this->field_errors($error_key, $id); ?>
        </span>
        <?php
    }

    /**
     * Templates cloned by the editor script.
     */
    private function templates(): void
    {
        ?>
        <template id="ppapi-tpl-empty-mappings">
            <p class="ppapi-empty-mappings"><?php esc_html_e('No mappings yet. Add one, or run Test Request first to get path suggestions.', 'post-press-api'); ?></p>
        </template>
        <?php
    }

    /**
     * Prints the linked error summary.
     */
    private function error_summary(): void
    {
        if ($this->errors === []) {
            return;
        }

        $count = array_sum(array_map('count', $this->errors));
        ?>
        <div class="notice notice-error ppapi-error-summary" id="ppapi-error-summary" role="alert" tabindex="-1">
            <p><strong>
                <?php
                printf(
                    /* translators: %d: number of problems. */
                    esc_html(_n('The preset was not saved. Fix %d problem:', 'The preset was not saved. Fix %d problems:', $count, 'post-press-api')),
                    (int) $count
                );
                ?>
            </strong></p>
            <ul>
                <?php foreach ($this->errors as $key => $messages) : ?>
                    <?php foreach ($messages as $message) : ?>
                        <li>
                            <?php if (isset(self::ERROR_TARGETS[$key])) : ?>
                                <a href="#<?php echo esc_attr(self::ERROR_TARGETS[$key]); ?>"><?php echo esc_html($message); ?></a>
                            <?php else : ?>
                                <?php echo esc_html($message); ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Prints inline errors for a field.
     */
    private function field_errors(string $key, string $id): void
    {
        if (empty($this->errors[$key])) {
            return;
        }

        echo '<div class="ppapi-field-error" id="' . esc_attr($id . '-error') . '">';
        foreach ($this->errors[$key] as $message) {
            echo '<p>' . esc_html($message) . '</p>';
        }
        echo '</div>';
    }

    /**
     * Prints aria-describedby (help text and inline errors) and aria-invalid for a field.
     */
    private function invalid(string $key, string $id, string $help_id = ''): void
    {
        $described = $help_id === '' ? [] : [$help_id];

        if (!empty($this->errors[$key])) {
            echo ' aria-invalid="true"';
            $described[] = $id . '-error';
        }

        if ($described !== []) {
            echo ' aria-describedby="' . esc_attr(implode(' ', $described)) . '"';
        }
    }

    /**
     * Adds editor data and supported condition operators. Contains no credentials.
     *
     * @param array<string, mixed> $preset Preset.
     * @param bool                 $is_new Whether unsaved.
     * @param string               $sample Sample JSON.
     */
    private function enqueue_data(array $preset, bool $is_new, string $sample): void
    {
        $registry  = $this->services->destinations();
        $post_type = (string) $preset['destination']['post_type'];
        $active    = $is_new ? null : $this->services->runs()->active((string) $preset['id']);

        $record_paths   = [];
        $response_paths = [];
        if (trim($sample) !== '') {
            $data = json_decode($sample, true, 512, JSON_BIGINT_AS_STRING);
            if (json_last_error() === JSON_ERROR_NONE) {
                $response_paths = $this->services->discovery()->discover_response($data);
                if ($preset['response']['records_path'] !== '') {
                    $read = $this->services->response_reader()->records($data, $preset);
                    if ($read['ok']) {
                        $record_paths = $this->services->discovery()->discover_records($read['records']);
                    }
                }
            }
        }

        $data = [
            'presetId'      => $is_new ? '' : (string) $preset['id'],
            'restNamespace' => RestController::NAMESPACE,
            'mappings'      => $preset['mappings'],
            'catalog'       => $registry->is_allowed($post_type) ? $registry->catalog($post_type)->to_editor_array() : null,
            'transforms'    => Transformer::labels(),
            'conditionOperators' => MappingCondition::labels(),
            'recordPaths'   => $record_paths,
            'responsePaths' => $response_paths,
            'activeRun'     => $active !== null && in_array($active['status'] ?? '', ImportRunner::ACTIVE, true) ? ImportRunner::summary($active) : null,
            'limits'        => ['maxChildren' => MappingSchema::MAX_CHILDREN, 'maxRows' => MappingSchema::MAX_ROWS, 'maxDepth' => MappingSchema::MAX_DEPTH],
            'i18n'          => AdminPage::script_strings(),
        ];

        wp_add_inline_script(
            AdminPage::SCRIPT_HANDLE,
            'window.ppapiEditor = ' . wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR) . ';',
            'before'
        );
    }
}
