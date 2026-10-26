<?php

declare(strict_types=1);

namespace PostPressAPI\Admin;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Admin\Views\AboutView;
use PostPressAPI\Admin\Views\EditorView;
use PostPressAPI\Admin\Views\HistoryView;
use PostPressAPI\Admin\Views\ListView;
use PostPressAPI\Admin\Views\LogView;
use PostPressAPI\Admin\Views\TransferView;
use PostPressAPI\Presets\PresetRepository;
use PostPressAPI\Presets\PresetSchema;
use PostPressAPI\Services;
use PostPressAPI\Support\Permissions;

/**
 * The "Post Press API" admin menu and its screens.
 *
 *   admin.php?page=ppapi                          preset list
 *   admin.php?page=ppapi&view=new                 new preset
 *   admin.php?page=ppapi&view=edit&preset=…       edit preset
 *   admin.php?page=ppapi&view=history&preset=…    run history
 *   admin.php?page=ppapi-log                      activity log
 *   admin.php?page=ppapi-about                    feature guide and site status
 *
 * The editor posts back to its own screen; the submission is handled on the
 * screen's load-* hook (before output) so a successful save redirects and a
 * failed one re-renders with the submitted values and errors. Scripts and
 * styles load only on these screens.
 */
final class AdminPage
{
    public const SLUG          = 'ppapi';
    public const SLUG_NEW      = 'ppapi-new';
    public const SLUG_LOG      = 'ppapi-log';
    public const SLUG_ABOUT    = 'ppapi-about';
    public const SCRIPT_HANDLE = 'ppapi-admin';
    public const STYLE_HANDLE  = 'ppapi-admin';

    /** @var list<string> Hook suffixes of this plugin's screens. */
    private array $hooks = [];

    /**
     * Failed save awaiting re-render.
     *
     * @var array{ok: bool, preset: array<string, mixed>, errors: array<string, list<string>>, warnings: list<string>, sample: string}|null
     */
    private ?array $failed_save = null;

    /** @var array<string,mixed>|null Original upload retained for the review response. */
    private ?array $import_data = null;
    /** @var array<string,mixed>|null Local dependency review. */
    private ?array $import_review = null;
    /** @var list<string> Import submission errors. */
    private array $import_errors = [];
    /** @var list<string> Import review notices that are not errors. */
    private array $import_notices = [];

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
     * Registers hooks.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_filter('plugin_action_links_' . plugin_basename(PPAPI_FILE), [$this, 'action_links']);
    }

    /**
     * URL of a plugin screen.
     *
     * @param array<string, string> $args Query arguments.
     * @param string                $page Page slug.
     *
     * @return string
     */
    public static function url(array $args = [], string $page = self::SLUG): string
    {
        return add_query_arg(['page' => $page] + $args, admin_url('admin.php'));
    }

    /**
     * Adds the menu and screens.
     *
     * @return void
     */
    public function menu(): void
    {
        $main = add_menu_page(
            __('Post Press API', 'post-press-api'),
            __('Post Press API', 'post-press-api'),
            Permissions::MANAGE,
            self::SLUG,
            [$this, 'render'],
            'dashicons-database-import',
            81
        );
        add_submenu_page(self::SLUG, __('Presets', 'post-press-api'), __('Presets', 'post-press-api'), Permissions::MANAGE, self::SLUG, [$this, 'render']);
        $new = add_submenu_page(self::SLUG, __('Add New Preset', 'post-press-api'), __('Add New Preset', 'post-press-api'), Permissions::MANAGE, self::SLUG_NEW, [$this, 'render_new']);
        $log = add_submenu_page(self::SLUG, __('Activity Log', 'post-press-api'), __('Activity Log', 'post-press-api'), Permissions::MANAGE, self::SLUG_LOG, [$this, 'render_log']);
        $about = add_submenu_page(self::SLUG, __('About Post Press API', 'post-press-api'), __('About', 'post-press-api'), Permissions::MANAGE, self::SLUG_ABOUT, [$this, 'render_about']);

        $this->hooks = array_values(array_filter([$main, $new, $log, $about], 'is_string'));

        if (is_string($main)) {
            add_action('load-' . $main, [$this, 'handle_save']);
        }
    }

    /**
     * Enqueues assets on this plugin's screens only.
     *
     * @param string $hook_suffix Current screen hook.
     *
     * @return void
     */
    public function enqueue(string $hook_suffix): void
    {
        if (!in_array($hook_suffix, $this->hooks, true)) {
            return;
        }

        wp_enqueue_style(self::STYLE_HANDLE, PPAPI_URL . 'assets/css/admin.css', [], PPAPI_VERSION);
        wp_enqueue_script(self::SCRIPT_HANDLE, PPAPI_URL . 'assets/js/admin.js', ['wp-api-fetch'], PPAPI_VERSION, true);
    }

    /**
     * Handles an editor submission (load-* hook, before output).
     *
     * @return void
     */
    public function handle_save(): void
    {
        $action = is_string($_POST['ppapi_action'] ?? null) ? $_POST['ppapi_action'] : '';
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST' && in_array($action, ['import_upload', 'import_review', 'import_save'], true)) {
            $this->handle_import($action);
            return;
        }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST' || ($_POST['ppapi_action'] ?? '') !== 'save') {
            return;
        }

        if (!Permissions::can_manage()) {
            wp_die(esc_html__('You are not allowed to manage Post Press API.', 'post-press-api'), '', ['response' => 403]);
        }

        check_admin_referer('ppapi_save_preset', '_ppapi_nonce');

        $preset_id = isset($_POST['preset_id']) ? sanitize_key(wp_unslash($_POST['preset_id'])) : '';
        $preset_id = PresetRepository::is_valid_id($preset_id) ? $preset_id : null;

        $result = (new PresetEditor($this->services))->save(wp_unslash($_POST), $preset_id);

        if (!$result['ok']) {
            $this->failed_save = $result;
            return;
        }

        if ($result['warnings'] !== []) {
            set_transient('ppapi_warnings_' . get_current_user_id(), $result['warnings'], 60);
        }

        ($this->terminate)(self::url([
            'view'         => 'edit',
            'preset'       => (string) $result['preset']['id'],
            'ppapi_notice' => $preset_id === null ? 'created' : 'saved',
        ]));
    }

    /**
     * Routes the main screen.
     *
     * @return void
     */
    public function render(): void
    {
        $this->assert_access();

        $view      = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : '';
        $preset_id = isset($_GET['preset']) ? sanitize_key(wp_unslash($_GET['preset'])) : '';

        if ($view === 'import') {
            (new \PostPressAPI\Admin\Views\TransferView($this->services))->render($this->import_data, $this->import_review, $this->import_errors, $this->import_notices);
            return;
        }

        if ($view === 'new') {
            $this->render_editor(null);
            return;
        }

        if ($view === 'edit' || $view === 'history' || $view === 'export') {
            $preset = PresetRepository::is_valid_id($preset_id) ? $this->services->presets()->find($preset_id) : null;
            if ($preset === null) {
                echo '<div class="wrap ppapi-wrap"><h1>' . esc_html__('Post Press API', 'post-press-api') . '</h1>';
                Notices::render_list('error', [__('That preset no longer exists.', 'post-press-api')]);
                echo '<p><a href="' . esc_url(self::url()) . '">' . esc_html__('Back to presets', 'post-press-api') . '</a></p></div>';
                return;
            }

            if ($view === 'history') {
                (new HistoryView($this->services))->render($preset);
                return;
            }

            if ($view === 'export') {
                (new \PostPressAPI\Admin\Views\TransferView($this->services))->export_form($preset);
                return;
            }

            $this->render_editor($preset);
            return;
        }

        (new ListView($this->services))->render();
    }

    /**
     * "Add New Preset" submenu.
     *
     * @return void
     */
    public function render_new(): void
    {
        $this->assert_access();
        $this->render_editor(null);
    }

    /**
     * Activity log submenu.
     *
     * @return void
     */
    public function render_log(): void
    {
        $this->assert_access();
        (new LogView($this->services))->render();
    }

    /**
     * "About" submenu: feature guide and site status.
     *
     * @return void
     */
    public function render_about(): void
    {
        $this->assert_access();
        (new AboutView($this->services))->render();
    }

    /**
     * Adds "Presets" and "About" links to the plugin's row on the Plugins screen.
     *
     * @param array<int|string, string> $links Existing links.
     *
     * @return array<int|string, string>
     */
    public function action_links(array $links): array
    {
        if (!Permissions::can_manage()) {
            return $links;
        }

        array_unshift(
            $links,
            '<a href="' . esc_url(self::url()) . '">' . esc_html__('Presets', 'post-press-api') . '</a>',
            '<a href="' . esc_url(self::url([], self::SLUG_ABOUT)) . '">' . esc_html__('About', 'post-press-api') . '</a>'
        );

        return $links;
    }

    /**
     * Strings used by assets/js/admin.js, including optional mapping conditions.
     *
     * @return array<string, string>
     */
    public static function script_strings(): array
    {
        return [
            'downloadImage' => __('Download image URL to Media Library', 'post-press-api'),
            'mediaOutput' => __('Store downloaded image as', 'post-press-api'),
            'attachmentId' => __('Attachment ID', 'post-press-api'),
            'localMediaUrl' => __('Local media URL', 'post-press-api'),
            'imageHelp' => __('Public image URLs only, up to 10 MB / 40 megapixels. Reuses images by URL. Missing/null rules still apply; a fallback must be an image URL. Previews do not download images.', 'post-press-api'),
            'mapping'            => __('Mapping %d', 'post-press-api'),
            'subMapping'         => __('Sub field %d', 'post-press-api'),
            'source'             => __('Source', 'post-press-api'),
            'sourceType'         => __('Source type', 'post-press-api'),
            'sourcePath'         => __('Path', 'post-press-api'),
            'sourceConstant'     => __('Constant', 'post-press-api'),
            'sourceTemplate'     => __('Template', 'post-press-api'),
            'destination'        => __('Destination', 'post-press-api'),
            'destinationType'    => __('Destination type', 'post-press-api'),
            'destCore'           => __('Post field', 'post-press-api'),
            'destMeta'           => __('Custom meta', 'post-press-api'),
            'destAcf'            => __('ACF field', 'post-press-api'),
            'field'              => __('Field', 'post-press-api'),
            'metaKey'            => __('Meta key', 'post-press-api'),
            'chooseField'        => __('— Choose a field —', 'post-press-api'),
            'transform'          => __('Transformation', 'post-press-api'),
            'missingNull'        => __('Missing or null values', 'post-press-api'),
            'condition'          => __('Condition (optional)', 'post-press-api'),
            'conditionEnable'    => __('Enable a condition', 'post-press-api'),
            'conditionWhen'      => __('When the source', 'post-press-api'),
            'conditionCount'     => __('Number of items', 'post-press-api'),
            'conditionText'      => __('Literal text or character', 'post-press-api'),
            'conditionCase'      => __('Case-sensitive match', 'post-press-api'),
            'conditionAction'    => __('When the condition matches', 'post-press-api'),
            'conditionHelp'      => __('Checks this mapping\'s source before transformation. If the condition does not match, the mapping runs normally. Missing/null values use their settings below.', 'post-press-api'),
            'conditionPreserve'  => __('Keep leaves any old value in place; Clear removes it. Only this mapping is affected.', 'post-press-api'),
            'conditionRepeater'  => __('Repeater rows are rebuilt. Clear this field or skip the entire repeater row when the condition matches.', 'post-press-api'),
            'whenMissing'        => __('When the source is missing', 'post-press-api'),
            'whenNull'           => __('When the source is null', 'post-press-api'),
            'preserve'           => __('Keep the existing value', 'post-press-api'),
            'clear'              => __('Clear the field', 'post-press-api'),
            'clearRow'           => __('Leave empty in this row', 'post-press-api'),
            'fallback'           => __('Use the fallback value', 'post-press-api'),
            'fail'               => __('Mark the record as failed', 'post-press-api'),
            'skipRow'            => __('Skip this repeater row', 'post-press-api'),
            'fallbackValue'      => __('Fallback value', 'post-press-api'),
            'separator'          => __('Separator (\\n = new line)', 'post-press-api'),
            'joinDateTime'       => __('Format each item as a date/time before joining', 'post-press-api'),
            'joinDateHelp'       => __('For a list of dates, use a source such as sessions[*].date. Enable date/time formatting and set Output format to F j for November 18, November 20, December 2. Null items are skipped; invalid dates fail the mapping.', 'post-press-api'),
            'listDateTime'       => __('Format each item as a date/time', 'post-press-api'),
            'listHelp'           => __('Keeps a list (array) for ACF List Items, checkbox, and multiple-select fields. Use the list path, such as dates_text or sessions[*].date. Null items are skipped and other values become text items in the same order. With date/time formatting and Output format F j, the stored items read November 18, November 20, December 2; invalid dates fail the mapping.', 'post-press-api'),
            'listDestination'    => __('This destination cannot store a list. Choose an ACF List Items, checkbox, or multiple-select field, or use Join list into text.', 'post-press-api'),
            'inputFormat'        => __('Input format (optional, PHP date format)', 'post-press-api'),
            'inputTimezone'      => __('Input time zone (for values without an offset)', 'post-press-api'),
            'outputFormat'       => __('Output format (optional)', 'post-press-api'),
            'outputTimezone'     => __('Output time zone (default: site)', 'post-press-api'),
            'decimals'           => __('Round to decimals (optional)', 'post-press-api'),
            'mapFrom'            => __('Source value', 'post-press-api'),
            'mapTo'              => __('Becomes', 'post-press-api'),
            'addMapEntry'        => __('Add value', 'post-press-api'),
            'unmatched'          => __('Values not listed', 'post-press-api'),
            'unmatchedKeep'      => __('Keep as is', 'post-press-api'),
            'unmatchedFallback'  => __('Use the fallback value', 'post-press-api'),
            'unmatchedError'     => __('Mark the record as failed', 'post-press-api'),
            'remove'             => __('Remove', 'post-press-api'),
            'moveUp'             => __('Move up', 'post-press-api'),
            'moveDown'           => __('Move down', 'post-press-api'),
            'moveToPosition'     => __('Move to position', 'post-press-api'),
            'move'               => __('Move', 'post-press-api'),
            /* translators: %d: number of sibling mappings. */
            'positionRange'      => __('of %d', 'post-press-api'),
            /* translators: %d: maximum allowed position. */
            'positionError'      => __('Enter a whole number from 1 to %d, then choose Move.', 'post-press-api'),
            'addSubMapping'      => __('Add sub field mapping', 'post-press-api'),
            'subMappingsGroup'   => __('Sub fields (paths are relative to the group object; use $ for the current record)', 'post-press-api'),
            'subMappingsRepeater' => __('Repeater rows: one row per item of the source list; sub field paths are relative to each item', 'post-press-api'),
            'containerSourceHelp' => __('Path to the object (group) or list (repeater); $ means the current record or item.', 'post-press-api'),
            'unsupported'        => __('not supported', 'post-press-api'),
            'fieldKey'           => __('Key', 'post-press-api'),
            'fieldName'          => __('Name', 'post-press-api'),
            'fieldType'          => __('Type', 'post-press-api'),
            'acfInactive'        => __('Advanced Custom Fields is not active, so ACF fields cannot be chosen.', 'post-press-api'),
            'chooseTypeFirst'    => __('Choose a destination post type to list its fields.', 'post-press-api'),
            'loadingFields'      => __('Loading fields…', 'post-press-api'),
            'fieldsError'        => __('Could not load fields for this post type.', 'post-press-api'),
            'working'            => __('Working…', 'post-press-api'),
            'requestFailed'      => __('The request failed.', 'post-press-api'),
            'sampleSource'       => __('Pasted sample JSON', 'post-press-api'),
            'httpSummary'        => __('HTTP %1$s · %2$s · %3$s ms', 'post-press-api'),
            'recordsFound'       => __('%1$d records found at "%2$s".', 'post-press-api'),
            'recordPaths'        => __('Paths inside each record', 'post-press-api'),
            'responsePaths'      => __('Response paths (for the records path, success flag, and pagination)', 'post-press-api'),
            'path'               => __('Path', 'post-press-api'),
            'type'               => __('Type', 'post-press-api'),
            'example'            => __('Example', 'post-press-api'),
            'sampleRecords'      => __('Sample records', 'post-press-api'),
            'rawPreview'         => __('Response preview', 'post-press-api'),
            'responseBody'       => __('Start of the response', 'post-press-api'),
            'dryRunSummary'      => __('Dry run of %1$d of %2$d records: %3$d would be created, %4$d updated, %5$d unchanged, %6$d skipped, %7$d failed.', 'post-press-api'),
            'record'             => __('Record %1$d', 'post-press-api'),
            'externalId'         => __('ID %s', 'post-press-api'),
            'post'               => __('post #%d', 'post-press-api'),
            'state'              => __('Source state', 'post-press-api'),
            'raw'                => __('Source value', 'post-press-api'),
            'result'             => __('Result', 'post-press-api'),
            'value'              => __('Value written', 'post-press-api'),
            'note'               => __('Note', 'post-press-api'),
            'actionCreated'      => __('create', 'post-press-api'),
            'actionUpdated'      => __('update', 'post-press-api'),
            'actionUnchanged'    => __('unchanged', 'post-press-api'),
            'actionSkipped'      => __('skip', 'post-press-api'),
            'actionFailed'       => __('fail', 'post-press-api'),
            'resultSet'          => __('set', 'post-press-api'),
            'resultClear'        => __('clear', 'post-press-api'),
            'resultPreserve'     => __('keep existing', 'post-press-api'),
            'resultSkipRow'      => __('skip row', 'post-press-api'),
            'resultError'        => __('error', 'post-press-api'),
            'viaFallback'        => __('(fallback)', 'post-press-api'),
            'saveFirst'          => __('You have unsaved changes. Runs use the saved settings — save first, or continue with the saved version?', 'post-press-api'),
            'runStarting'        => __('Starting run…', 'post-press-api'),
            'runProgress'        => __('%1$d records processed from %2$d pages.', 'post-press-api'),
            'runWaiting'         => __('Waiting %d seconds before retrying…', 'post-press-api'),
            'runBusy'            => __('Another process is working on this run; checking again shortly…', 'post-press-api'),
            'runCancelRequested' => __('Cancellation requested; the run stops after its current record.', 'post-press-api'),
            'statusRunning'      => __('Running', 'post-press-api'),
            'statusWaiting'      => __('Waiting to retry', 'post-press-api'),
            'statusCompleted'    => __('Completed', 'post-press-api'),
            'statusCompletedWithErrors' => __('Completed with errors', 'post-press-api'),
            'statusTruncated'    => __('Stopped early (more records remain)', 'post-press-api'),
            'statusFailed'       => __('Failed', 'post-press-api'),
            'statusCancelled'    => __('Cancelled', 'post-press-api'),
            'counts'             => __('%1$d created · %2$d updated · %3$d unchanged · %4$d skipped · %5$d failed', 'post-press-api'),
            'failedRecords'      => __('Failed records', 'post-press-api'),
            'reloadHistory'      => __('Reload the page to see this run in the history table.', 'post-press-api'),
            'confirmRemove'      => __('Remove this mapping?', 'post-press-api'),
            'limitReached'       => __('The maximum number of mappings has been reached.', 'post-press-api'),
            'maxDepth'           => __('Nested mappings can only go three levels deep.', 'post-press-api'),
        ];
    }

    /**
     * Renders the editor for a saved or new preset (or re-renders a failed save).
     *
     * @param array<string, mixed>|null $preset Saved preset, or null for a new one.
     *
     * @return void
     */
    private function render_editor(?array $preset): void
    {
        $is_new   = $preset === null;
        $warnings = [];
        $errors   = [];
        $sample   = $is_new ? '' : $this->services->presets()->sample((string) $preset['id']);

        if ($this->failed_save !== null) {
            $submitted       = $this->failed_save['preset'];
            $submitted['id'] = $is_new ? '' : (string) $preset['id'];
            $preset          = $submitted;
            $errors          = $this->failed_save['errors'];
            $warnings        = $this->failed_save['warnings'];
            $sample          = $this->failed_save['sample'];
        }

        if ($preset === null) {
            $preset                          = PresetSchema::defaults();
            $preset['destination']['author'] = get_current_user_id();
        }

        $transient = 'ppapi_warnings_' . get_current_user_id();
        $stored    = get_transient($transient);
        if (is_array($stored)) {
            $warnings = array_merge($warnings, array_map('strval', $stored));
            delete_transient($transient);
        }

        (new EditorView($this->services))->render($preset, $is_new, $errors, $warnings, $sample);
    }

    /**
     * Stops unauthorized users.
     *
     * @return void
     */
    private function assert_access(): void
    {
        if (!Permissions::can_manage()) {
            wp_die(esc_html__('You are not allowed to manage Post Press API.', 'post-press-api'), '', ['response' => 403]);
        }
    }

    /**
     * Parses an upload or revalidates a reviewed import before creating a preset.
     *
     * @param string $action import_upload, import_review, or import_save.
     *
     * @return void
     */
    private function handle_import(string $action): void
    {
        $this->assert_access();
        check_admin_referer('ppapi_import_preset', '_ppapi_import_nonce');
        $input = wp_unslash($_POST);
        try {
            if ($action === 'import_upload') {
                $file = $_FILES['ppapi_import_file'] ?? null;
                if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                    || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
                    throw new \InvalidArgumentException(__('The upload failed. Choose a JSON file within the server upload limit.', 'post-press-api'));
                }
                $json = file_get_contents($file['tmp_name'], false, null, 0, \PostPressAPI\Presets\PresetTransfer::MAX_BYTES + 1);
                if (!is_string($json)) {
                    throw new \InvalidArgumentException(__('The uploaded file could not be read.', 'post-press-api'));
                }
            } else {
                $json = is_string($input['ppapi_import_json'] ?? null) ? $input['ppapi_import_json'] : '';
            }
            $this->import_data = $this->services->transfer()->decode($json);
            $type = $action === 'import_upload' ? null : (is_string($input['ppapi_import_type'] ?? null) ? $input['ppapi_import_type'] : '');
            $author = $action === 'import_upload' ? null : (is_scalar($input['ppapi_import_author'] ?? null) ? (int) $input['ppapi_import_author'] : 0);
            $remaps = is_array($input['ppapi_import_remaps'] ?? null) ? $input['ppapi_import_remaps'] : [];
            // Blank controls can auto-match after changing the post type or parent.
            $remaps = array_filter($remaps, static fn ($key): bool => $key !== '');
            // Removals are explicit, keyed by path in the uploaded file, and checked against it.
            $excluded = $input['ppapi_import_exclude'] ?? [];
            if (!is_array($excluded)) {
                throw new \InvalidArgumentException(__('Only ACF mappings listed in this file can be removed from the import.', 'post-press-api'));
            }
            $disable_match = ($input['ppapi_import_disable_match'] ?? '') === '1';
            $this->import_review = $this->services->transfer()->review($this->import_data, $type, $author, $remaps, $excluded, $disable_match);
            // Never create a preset whose omissions were not shown in the review just submitted.
            $reviewed = is_string($input['ppapi_import_reviewed'] ?? null) ? $input['ppapi_import_reviewed'] : '';
            if ($action === 'import_save' && $this->import_review['problems'] === [] && $reviewed !== TransferView::omission_key($this->import_review)) {
                $this->import_notices[] = __('The mappings removed from this import changed, so the preset was not created yet. Check what will be omitted below, then choose Create disabled preset again.', 'post-press-api');
            } elseif ($action === 'import_save' && $this->import_review['problems'] === []) {
                $saved = $this->services->transfer()->save($this->import_data, (string) $type, (int) $author, $remaps, $excluded, $disable_match);
                set_transient('ppapi_warnings_' . get_current_user_id(), $this->import_review['warnings'], 60);
                ($this->terminate)(self::url(['view' => 'edit', 'preset' => $saved['id'], 'ppapi_notice' => 'imported']));
            }
        } catch (\InvalidArgumentException $error) {
            $this->import_errors[] = $error->getMessage();
        }
    }
}
