<?php

declare(strict_types=1);

namespace PostPressAPI\Admin\Views;

use PostPressAPI\Admin\AdminPage;
use PostPressAPI\Logging\Logger;
use PostPressAPI\Mapping\Transformer;
use PostPressAPI\Presets\PresetSchema;
use PostPressAPI\Services;
use PostPressAPI\Sync\PreviewService;
use PostPressAPI\Sync\RunRepository;
use PostPressAPI\Updates\GitHubUpdater;

if (!defined('ABSPATH')) exit;

/**
 * The "About" screen: what the plugin does, how to set it up, and a reference
 * for every feature, plus a live status check of this site.
 */
final class AboutView
{
    public function __construct(private readonly Services $services)
    {
    }

    /**
     * Renders the screen.
     *
     * @return void
     */
    public function render(): void
    {
        ?>
        <div class="wrap ppapi-wrap ppapi-about">
            <h1 class="wp-heading-inline">
                <?php
                printf(
                    /* translators: %s: plugin version. */
                    esc_html__('About Post Press API %s', 'post-press-api'),
                    esc_html(defined('PPAPI_VERSION') ? PPAPI_VERSION : '')
                );
                ?>
            </h1>
            <a class="page-title-action" href="<?php echo esc_url(AdminPage::url(['view' => 'new'])); ?>"><?php esc_html_e('Add New Preset', 'post-press-api'); ?></a>
            <a class="page-title-action" href="<?php echo esc_url(AdminPage::url()); ?>"><?php esc_html_e('All presets', 'post-press-api'); ?></a>
            <hr class="wp-header-end">

            <p class="ppapi-about-lead">
                <?php esc_html_e('Post Press API fetches JSON records from external APIs — on demand or on a schedule — and turns them into WordPress posts. Each record can fill post fields, custom meta, and Advanced Custom Fields, and later runs update the same posts instead of creating duplicates.', 'post-press-api'); ?>
            </p>

            <nav class="ppapi-section-nav" aria-label="<?php esc_attr_e('About sections', 'post-press-api'); ?>">
                <a href="#ppapi-about-status"><?php esc_html_e('Site status', 'post-press-api'); ?></a>
                <a href="#ppapi-about-setup"><?php esc_html_e('Getting started', 'post-press-api'); ?></a>
                <a href="#ppapi-about-features"><?php esc_html_e('Features', 'post-press-api'); ?></a>
                <a href="#ppapi-about-paths"><?php esc_html_e('Paths', 'post-press-api'); ?></a>
                <a href="#ppapi-about-mappings"><?php esc_html_e('Mappings', 'post-press-api'); ?></a>
                <a href="#ppapi-about-acf"><?php esc_html_e('ACF', 'post-press-api'); ?></a>
                <a href="#ppapi-about-duplicates"><?php esc_html_e('Duplicates', 'post-press-api'); ?></a>
                <a href="#ppapi-about-runs"><?php esc_html_e('Runs & schedules', 'post-press-api'); ?></a>
                <a href="#ppapi-about-example"><?php esc_html_e('Example', 'post-press-api'); ?></a>
                <a href="#ppapi-about-security"><?php esc_html_e('Security', 'post-press-api'); ?></a>
                <a href="#ppapi-about-limits"><?php esc_html_e('Limits', 'post-press-api'); ?></a>
            </nav>

            <?php
            $this->status();
            $this->setup();
            $this->features();
            $this->paths();
            $this->mappings();
            $this->acf();
            $this->duplicates();
            $this->runs();
            $this->example();
            $this->security();
            $this->limits();
            ?>
        </div>
        <?php
    }

    /**
     * Live checks of this site.
     */
    private function status(): void
    {
        $acf        = $this->services->acf_fields();
        $acf_active = $acf->available();
        $repeater   = $acf_active && $acf->type_registered('repeater');
        $index      = $this->services->presets()->index();
        $enabled    = count(array_filter($index, static fn (array $summary): bool => $summary['enabled']));
        $scheduled  = count(array_filter($index, static fn (array $summary): bool => $summary['enabled'] && $summary['frequency'] !== PresetSchema::FREQ_MANUAL));
        $types      = $this->services->destinations()->post_types();
        $cron_off   = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        $rows = [
            [
                __('PHP', 'post-press-api'),
                PHP_VERSION,
                version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'error',
                __('PHP 8.1 or newer is required.', 'post-press-api'),
            ],
            [
                __('WordPress', 'post-press-api'),
                (string) get_bloginfo('version'),
                'ok',
                __('WordPress 6.0 or newer is required.', 'post-press-api'),
            ],
            [
                __('Advanced Custom Fields', 'post-press-api'),
                $acf_active ? (defined('ACF_VERSION') ? sprintf(
                    /* translators: %s: ACF version. */
                    __('Active (%s)', 'post-press-api'),
                    ACF_VERSION
                ) : __('Active', 'post-press-api')) : __('Not active', 'post-press-api'),
                $acf_active ? 'ok' : 'info',
                $acf_active
                    ? __('ACF fields can be mapped.', 'post-press-api')
                    : __('Optional. Post fields and custom meta work without ACF; install ACF to map ACF fields.', 'post-press-api'),
            ],
            [
                __('ACF repeater fields', 'post-press-api'),
                $repeater ? __('Available', 'post-press-api') : __('Not available', 'post-press-api'),
                $repeater ? 'ok' : 'info',
                $repeater
                    ? __('Lists such as sessions can fill repeater rows.', 'post-press-api')
                    : __('Repeater fields require ACF PRO. Groups and other field types still work.', 'post-press-api'),
            ],
            [
                __('Destination post types', 'post-press-api'),
                (string) count($types),
                $types === [] ? 'warning' : 'ok',
                $types === [] ? __('Register a post type first; this plugin never creates post types or fields.', 'post-press-api') : implode(', ', array_keys($types)),
            ],
            [
                __('Presets', 'post-press-api'),
                sprintf(
                    /* translators: 1: total presets, 2: enabled presets, 3: scheduled presets. */
                    __('%1$d total · %2$d enabled · %3$d scheduled', 'post-press-api'),
                    count($index),
                    $enabled,
                    $scheduled
                ),
                'ok',
                '',
            ],
            [
                __('Scheduled runs', 'post-press-api'),
                $cron_off ? __('Server cron (DISABLE_WP_CRON is set)', 'post-press-api') : __('WP-Cron (triggered by site visits)', 'post-press-api'),
                $cron_off ? 'ok' : 'info',
                $cron_off
                    ? __('Make sure the server calls wp-cron.php every few minutes, or scheduled presets will not run.', 'post-press-api')
                    : __('Schedules run when the site gets traffic. See "Runs & schedules" for dependable timing.', 'post-press-api'),
            ],
            [
                __('Site time zone', 'post-press-api'),
                wp_timezone()->getName(),
                'ok',
                __('Run times are shown in this zone, and date values without an offset are read in it unless a mapping sets another zone.', 'post-press-api'),
            ],
            [
                __('Plugin updates', 'post-press-api'),
                GitHubUpdater::isConfigured() ? __('GitHub releases', 'post-press-api') : __('Not configured', 'post-press-api'),
                GitHubUpdater::isConfigured() ? 'ok' : 'info',
                GitHubUpdater::isConfigured()
                    ? __('New GitHub releases appear on the Plugins and Updates screens.', 'post-press-api')
                    : __('No update checks are made until a GitHub repository is set in src/Updates/GitHubUpdater.php.', 'post-press-api'),
            ],
        ];
        ?>
        <section class="ppapi-card" id="ppapi-about-status" aria-labelledby="ppapi-about-status-heading">
            <h2 id="ppapi-about-status-heading"><?php esc_html_e('Site status', 'post-press-api'); ?></h2>
            <table class="widefat striped ppapi-status-table">
                <caption class="screen-reader-text"><?php esc_html_e('Checks for this site', 'post-press-api'); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Check', 'post-press-api'); ?></th>
                        <th scope="col"><?php esc_html_e('Status', 'post-press-api'); ?></th>
                        <th scope="col"><?php esc_html_e('Details', 'post-press-api'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as [$label, $value, $state, $note]) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($label); ?></th>
                            <td>
                                <span class="ppapi-status ppapi-status--<?php echo esc_attr($state === 'ok' ? 'success' : $state); ?>">
                                    <?php echo esc_html($value); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($note); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php
    }

    private function setup(): void
    {
        $steps = [
            [__('Prepare the destination', 'post-press-api'), __('Make sure the post type you want to fill exists, and create any ACF field groups for it. Post Press API only writes to post types and fields that are already registered on this site.', 'post-press-api')],
            [__('Create a preset', 'post-press-api'), __('Go to Post Press API → Add New Preset and give it a name. New presets start disabled and manual-only, so nothing runs until you are ready.', 'post-press-api')],
            [__('Describe the request', 'post-press-api'), __('Enter the endpoint URL, GET or POST, query parameters, headers, an optional JSON body, and authentication (bearer token or an API key in a header or query parameter).', 'post-press-api')],
            [__('Select the records', 'post-press-api'), __('Set the records path (for example data) and the external ID path inside each record (for example id). Click Test request to fetch the first page, or paste a sample response and test it offline. Discovered paths become suggestions in every path field.', 'post-press-api')],
            [__('Map the fields', 'post-press-api'), __('Choose the destination post type, the status and author for new posts, and add mappings from source paths to post fields, custom meta, or ACF fields. Add transformations where values need converting.', 'post-press-api')],
            [__('Preview with a dry run', 'post-press-api'), __('Preview / dry run shows, record by record, whether a post would be created, updated, left unchanged, skipped, or rejected — with every source value, its type, and the value that would be written. Nothing is saved.', 'post-press-api')],
            [__('Enable and run', 'post-press-api'), __('Save, tick Enabled once the editor no longer lists missing settings, and save again. Use Run now, or pick a schedule to run automatically.', 'post-press-api')],
            [__('Monitor', 'post-press-api'), __('The preset list shows each preset\'s last result and next run. History lists the last runs with created, updated, unchanged, skipped, and failed counts, and the Activity Log records errors and scheduling events.', 'post-press-api')],
        ];
        ?>
        <section class="ppapi-card" id="ppapi-about-setup" aria-labelledby="ppapi-about-setup-heading">
            <h2 id="ppapi-about-setup-heading"><?php esc_html_e('Getting started', 'post-press-api'); ?></h2>
            <ol class="ppapi-steps">
                <?php foreach ($steps as [$title, $text]) : ?>
                    <li><strong><?php echo esc_html($title); ?>.</strong> <?php echo esc_html($text); ?></li>
                <?php endforeach; ?>
            </ol>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(AdminPage::url(['view' => 'new'])); ?>"><?php esc_html_e('Create a preset', 'post-press-api'); ?></a>
                <a class="button" href="<?php echo esc_url(AdminPage::url([], AdminPage::SLUG_LOG)); ?>"><?php esc_html_e('Open the activity log', 'post-press-api'); ?></a>
            </p>
        </section>
        <?php
    }

    private function features(): void
    {
        $features = [
            [__('Request presets', 'post-press-api'), __('Any number of independent presets, each with its own endpoint, GET/POST method, query parameters, headers, JSON body, authentication, timeout, response-size limit, and retry count.', 'post-press-api')],
            [__('Test request & sample JSON', 'post-press-api'), __('Fetch a bounded sample without importing, or paste a response to configure mappings offline. See every path in the response with its type and an example value.', 'post-press-api')],
            [__('Nested mapping', 'post-press-api'), __('Read nested keys, array items, and whole lists (event.title, sessions[0].date, sessions[*].date) and write them to post fields, custom meta, or ACF fields — including ACF groups and repeaters.', 'post-press-api')],
            [__('Transformations', 'post-press-api'), __('Convert values to text, numbers, booleans, validated URLs or emails, joined lists, JSON, dates in an explicit time zone, or mapped values such as approved → publish.', 'post-press-api')],
            [__('Safe updates', 'post-press-api'), __('Each record keeps a stable identity, so re-running updates the same post. Only changed records are rewritten, only mapped fields change, and missing or null values keep existing data by default.', 'post-press-api')],
            [__('Preview / dry run', 'post-press-api'), __('Run the same identity, mapping, matching, and change detection as a real import without writing posts, meta, attachments, or tracking data.', 'post-press-api')],
            [__('Pagination & batches', 'post-press-api'), __('Follow offset/limit or page-number pagination until the API reports no more records. Large imports run in small saved batches and resume in the background.', 'post-press-api')],
            [__('Schedules', 'post-press-api'), __('Run manually, every 5 or 15 minutes, hourly, twice daily, daily, or on a custom interval of 5 minutes or more.', 'post-press-api')],
            [__('History & logging', 'post-press-api'), __('Per-preset run history with record counts and failed-record details, plus a bounded activity log — never containing credentials or payloads.', 'post-press-api')],
            [__('Security built in', 'post-press-api'), __('Administrator-only screens, nonces, server-side requests to public addresses only, credentials stored server-side and never shown again, and no credentials sent to other hosts.', 'post-press-api')],
        ];
        ?>
        <section class="ppapi-card" id="ppapi-about-features" aria-labelledby="ppapi-about-features-heading">
            <h2 id="ppapi-about-features-heading"><?php esc_html_e('Features', 'post-press-api'); ?></h2>
            <ul class="ppapi-feature-grid">
                <?php foreach ($features as [$title, $text]) : ?>
                    <li class="ppapi-feature">
                        <h3><?php echo esc_html($title); ?></h3>
                        <p><?php echo esc_html($text); ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
    }

    private function paths(): void
    {
        $rows = [
            ['event.title', __('A nested key.', 'post-press-api')],
            ['sessions.0.date', __('An array item by index (numeric segment).', 'post-press-api')],
            ['sessions[0].date', __('The same, in bracket form.', 'post-press-api')],
            ['items[-1]', __('The last item of any array, in the supplied order.', 'post-press-api')],
            ['groups[-1].members[-1].email', __('Nested last-item selection with any array keys.', 'post-press-api')],
            ['[-1]', __('The last item when the current context itself is an array.', 'post-press-api')],
            ['sessions[*].date', __('The date of every session, as a list.', 'post-press-api')],
            ['dates_text[*]', __('Every item of a list of text values.', 'post-press-api')],
            ['$', __('The current context: the whole response (records path), the record, or the current repeater item.', 'post-press-api')],
            ['["key.with.dots"]', __('A literal key containing ".", "[", "]", a leading "$", or exactly "*".', 'post-press-api')],
            ['{event.title} – {website.host}', __('A template source: placeholders are replaced with values; {{ and }} write literal braces.', 'post-press-api')],
        ];
        ?>
        <section class="ppapi-card" id="ppapi-about-paths" aria-labelledby="ppapi-about-paths-heading">
            <h2 id="ppapi-about-paths-heading"><?php esc_html_e('Paths: selecting data from JSON', 'post-press-api'); ?></h2>
            <p><?php esc_html_e('The records path selects the list of records in the response (use $ when the response itself is the list, or items[*] to use an object\'s values as records). Every other path is read inside each record.', 'post-press-api'); ?></p>
            <?php $this->table([__('Path', 'post-press-api'), __('Meaning', 'post-press-api')], $rows, true); ?>
            <p><?php esc_html_e('A path either finds nothing (missing), finds null, or finds a value. false, 0, empty text, and empty lists are real values and are written as such. A path through a null parent — location.city when location is null — counts as null. [-1] on an empty list is missing; a null last item stays null. Negative indices other than [-1], slices, filters, and expressions are rejected.', 'post-press-api'); ?></p>
        </section>
        <?php
    }

    private function mappings(): void
    {
        $transform_notes = [
            Transformer::NONE     => __('Use the value as received.', 'post-press-api'),
            Transformer::TEXT     => __('Plain text with tags removed; booleans become true/false.', 'post-press-api'),
            Transformer::INTEGER  => __('Whole numbers only; anything else is an error.', 'post-press-api'),
            Transformer::DECIMAL  => __('Decimal numbers, optionally rounded.', 'post-press-api'),
            Transformer::BOOLEAN  => __('true/false, 1/0, yes/no, on/off.', 'post-press-api'),
            Transformer::URL      => __('Must be a valid http(s) URL.', 'post-press-api'),
            Transformer::EMAIL    => __('Must be a valid email address.', 'post-press-api'),
            Transformer::JOIN     => __('Joins a list with a separator (\n for new lines).', 'post-press-api'),
            Transformer::JSON     => __('Stores any value as JSON text.', 'post-press-api'),
            Transformer::DATETIME => __('Parses dates and times with an optional input format and explicit input/output time zones.', 'post-press-api'),
            Transformer::MAP      => __('Converts listed values (approved → publish); unlisted values are kept, replaced by the fallback, or rejected.', 'post-press-api'),
        ];

        $rows = [];
        foreach (Transformer::labels() as $type => $label) {
            $rows[] = [$label, $transform_notes[$type] ?? ''];
        }

        $behaviors = [
            [__('Keep the existing value (default)', 'post-press-api'), __('Nothing is written, so existing data stays.', 'post-press-api')],
            [__('Clear the field', 'post-press-api'), __('Empties the field (not available for slug, date, status, or author).', 'post-press-api')],
            [__('Use the fallback value', 'post-press-api'), __('Writes a literal value, which must already be in the destination\'s format.', 'post-press-api')],
            [__('Mark the record as failed', 'post-press-api'), __('Treats the value as required: the record is reported and not imported.', 'post-press-api')],
            [__('Skip this repeater row', 'post-press-api'), __('Repeater sub fields only: leaves that item out of the repeater.', 'post-press-api')],
        ];
        ?>
        <section class="ppapi-card" id="ppapi-about-mappings" aria-labelledby="ppapi-about-mappings-heading">
            <h2 id="ppapi-about-mappings-heading"><?php esc_html_e('Mappings: where values go', 'post-press-api'); ?></h2>
            <p><?php esc_html_e('Each mapping reads a source — a path, a constant, or a template — optionally transforms it, and writes it to one destination:', 'post-press-api'); ?></p>
            <ul class="ppapi-list">
                <li><strong><?php esc_html_e('Post fields:', 'post-press-api'); ?></strong> <?php esc_html_e('title, content, excerpt, slug, publish date, status, and author. Content and excerpt keep the formatting WordPress allows in posts; scripts are removed. IDs and other internal properties can never be set.', 'post-press-api'); ?></li>
                <li><strong><?php esc_html_e('Custom meta:', 'post-press-api'); ?></strong> <?php esc_html_e('any meta key you name, except WordPress control keys, Post Press API\'s own _ppapi_ keys, and keys that belong to ACF fields.', 'post-press-api'); ?></li>
                <li><strong><?php esc_html_e('ACF fields:', 'post-press-api'); ?></strong> <?php esc_html_e('fields registered for the chosen post type, written through ACF itself (see below).', 'post-press-api'); ?></li>
            </ul>
            <p><?php esc_html_e('A record\'s own status (for example "received") is never used as the post status automatically. To set the status from the API, map it with a Value map to draft, pending, private, or publish.', 'post-press-api'); ?></p>

            <h3><?php esc_html_e('Transformations', 'post-press-api'); ?></h3>
            <?php $this->table([__('Transformation', 'post-press-api'), __('What it does', 'post-press-api')], $rows); ?>
            <p><?php esc_html_e('Lists and objects are never silently turned into the text "Array": choose Join or JSON, or select one item such as sessions[0].', 'post-press-api'); ?></p>

            <h3><?php esc_html_e('When a value is missing or null', 'post-press-api'); ?></h3>
            <?php $this->table([__('Choice', 'post-press-api'), __('Effect', 'post-press-api')], $behaviors); ?>

            <h3><?php esc_html_e('Dates and time zones', 'post-press-api'); ?></h3>
            <p><?php esc_html_e('ISO 8601 values with an offset are converted to the site (or chosen) time zone, and date-only values such as 2026-11-05 are never shifted to another day. Names PHP cannot resolve, such as "Eastern", and ambiguous abbreviations such as CST are rejected rather than guessed: choose the Date/time transformation and set an explicit zone such as America/New_York.', 'post-press-api'); ?></p>
        </section>
        <?php
    }

    private function acf(): void
    {
        $rows = [
            [__('Text, text area, WYSIWYG, number, range, email, URL, oEmbed', 'post-press-api'), __('Converted and validated for the field type.', 'post-press-api')],
            [__('True / false', 'post-press-api'), __('Stored as 1 or 0.', 'post-press-api')],
            [__('Select, radio, button group, checkbox', 'post-press-api'), __('Values must match a choice (by value or label) unless the field allows custom values.', 'post-press-api')],
            [__('Date, date & time, time pickers', 'post-press-api'), __('Normalized to ACF\'s storage formats (Ymd, Y-m-d H:i:s, H:i:s).', 'post-press-api')],
            [__('Color picker, link', 'post-press-api'), __('Hex colors; links from a URL or a url/title/target object.', 'post-press-api')],
            [__('Group', 'post-press-api'), __('Sub field mappings read inside the group\'s source object; unmapped sub fields are kept.', 'post-press-api')],
            [__('Repeater (ACF PRO)', 'post-press-api'), __('One row per item of a source list; sub mappings read inside each item, so values from the same item (such as a session\'s date and time) stay in the same row.', 'post-press-api')],
            [__('Image, file, gallery, relationship, post object, page link, taxonomy, user, flexible content, clone', 'post-press-api'), __('Not supported — shown disabled with the reason. Map media URLs to a URL or text field instead.', 'post-press-api')],
        ];
        ?>
        <section class="ppapi-card" id="ppapi-about-acf" aria-labelledby="ppapi-about-acf-heading">
            <h2 id="ppapi-about-acf-heading"><?php esc_html_e('Advanced Custom Fields', 'post-press-api'); ?></h2>
            <p><?php esc_html_e('When you choose a post type, the editor lists the ACF field groups assigned to it, with each field\'s label, name, key, type, and parent. Values are saved with ACF\'s update_field() using field keys, so ACF links them correctly even on brand-new posts. Field keys always come from this site; nothing is created for you.', 'post-press-api'); ?></p>
            <?php $this->table([__('Field types', 'post-press-api'), __('How they are filled', 'post-press-api')], $rows); ?>
            <p><?php esc_html_e('If ACF is deactivated or a field a preset needs disappears, only that preset stops — with an explanation in its history. Presets that only use post fields and custom meta never need ACF.', 'post-press-api'); ?></p>
        </section>
        <?php
    }

    private function duplicates(): void
    {
        $policies = [
            [__('Update changed records (default)', 'post-press-api'), __('Creates new records, updates posts whose mapped values changed, and leaves unchanged posts alone.', 'post-press-api')],
            [__('Skip existing records', 'post-press-api'), __('Creates new records and never touches posts that already exist.', 'post-press-api')],
            [__('Always create', 'post-press-api'), __('Creates a new post for every record on every run — duplicates are intended.', 'post-press-api')],
        ];
        ?>
        <section class="ppapi-card" id="ppapi-about-duplicates" aria-labelledby="ppapi-about-duplicates-heading">
            <h2 id="ppapi-about-duplicates-heading"><?php esc_html_e('Duplicates and updates', 'post-press-api'); ?></h2>
            <p><?php esc_html_e('Every imported post remembers the preset, post type, and external ID it came from. Titles are never used to detect duplicates, and records without a usable ID are reported instead of imported. For feeds without IDs, a combination of fields can act as the identity — if any of them changes, the record counts as new.', 'post-press-api'); ?></p>
            <?php $this->table([__('Policy', 'post-press-api'), __('Behavior', 'post-press-api')], $policies); ?>
            <ul class="ppapi-list">
                <li><?php esc_html_e('Changes are detected from the values your mappings write, so fields you do not map (such as updated_at) never cause updates.', 'post-press-api'); ?></li>
                <li><?php esc_html_e('Updates change only mapped fields. A local edit to a mapped field is kept until that record changes in the API; then its mapped fields are written again.', 'post-press-api'); ?></li>
                <li><?php esc_html_e('Existing posts created another way can be connected by a meta key or ACF field that holds the external ID. If several posts match, the record is reported instead of guessing.', 'post-press-api'); ?></li>
                <li><?php esc_html_e('Drafts, private, scheduled, and trashed posts all count as matches. Trashed posts are left in the trash by default, or can be restored as drafts.', 'post-press-api'); ?></li>
                <li><?php esc_html_e('If a write fails part-way, the post stays an unpublished draft and the next run completes the same post — never a second one.', 'post-press-api'); ?></li>
                <li><?php esc_html_e('Posts are never deleted: not when records disappear from the API, and not when a preset is deleted.', 'post-press-api'); ?></li>
            </ul>
        </section>
        <?php
    }

    private function runs(): void
    {
        ?>
        <section class="ppapi-card" id="ppapi-about-runs" aria-labelledby="ppapi-about-runs-heading">
            <h2 id="ppapi-about-runs-heading"><?php esc_html_e('Runs, pagination, and schedules', 'post-press-api'); ?></h2>
            <ul class="ppapi-list">
                <li><?php esc_html_e('Pagination: none, offset and limit, or page number. Runs continue while the API\'s has-more flag is true (or until the reported total, or while pages are full), stop on an empty page, and stop with an error if the API repeats a page.', 'post-press-api'); ?></li>
                <li><?php esc_html_e('Safety limits cap the pages and records per run. A run that hits a limit while more records exist is marked "Stopped early", never "Completed".', 'post-press-api'); ?></li>
                <li><?php esc_html_e('Runs work in small saved batches. The editor drives them while it is open, and they continue in the background if you leave. Manual and scheduled runs follow exactly the same steps.', 'post-press-api'); ?></li>
                <li><?php esc_html_e('Each run uses a snapshot of the preset taken when it starts; edits apply to the next run. Only one run per preset can be active at a time.', 'post-press-api'); ?></li>
                <li><?php esc_html_e('Temporary failures (timeouts, rate limits, server errors) are retried a limited number of times, honoring the API\'s Retry-After. Other errors stop the run with an explanation, and a response that reaches the size limit is rejected rather than imported partially.', 'post-press-api'); ?></li>
                <li>
                    <?php
                    printf(
                        /* translators: 1: runs kept per preset, 2: log entries kept. */
                        esc_html__('History keeps the last %1$d runs of each preset; the activity log keeps %2$d entries.', 'post-press-api'),
                        (int) RunRepository::HISTORY_LIMIT,
                        (int) Logger::LIMIT
                    );
                    ?>
                </li>
            </ul>
            <h3><?php esc_html_e('Dependable schedules', 'post-press-api'); ?></h3>
            <p><?php esc_html_e('Scheduled presets use WP-Cron, which only runs when someone visits the site, so times are approximate. For dependable timing, disable the visit trigger and have the server call WordPress cron every few minutes:', 'post-press-api'); ?></p>
            <pre class="ppapi-pre"><code>// wp-config.php
define('DISABLE_WP_CRON', true);

# server crontab
*/5 * * * * curl -fsS "<?php echo esc_html(site_url('wp-cron.php?doing_wp_cron')); ?>" &gt;/dev/null</code></pre>
        </section>
        <?php
    }

    private function example(): void
    {
        $rows = [
            ['event.title', '—', __('Post field: Title', 'post-press-api')],
            ['event.description', '—', __('Post field: Content', 'post-press-api')],
            ['event.virtual', '—', __('ACF true / false field', 'post-press-api')],
            ['event.virtual_meeting_url', '—', __('ACF URL field', 'post-press-api')],
            ['submitter.email', '—', __('ACF email field', 'post-press-api')],
            ['sessions[0].date', '—', __('ACF date picker (stored as 20261105)', 'post-press-api')],
            ['sessions', '—', __('ACF repeater: date → date sub field, time → time sub field', 'post-press-api')],
            ['dates_text[*]', __('Join list into text, separator \n', 'post-press-api'), __('ACF text area or a meta key', 'post-press-api')],
            ['location.city', '—', __('ACF text field (null today, so the existing value is kept)', 'post-press-api')],
        ];
        ?>
        <section class="ppapi-card" id="ppapi-about-example" aria-labelledby="ppapi-about-example-heading">
            <h2 id="ppapi-about-example-heading"><?php esc_html_e('Example: importing events', 'post-press-api'); ?></h2>
            <p><?php esc_html_e('For a response like {"ok": true, "data": [{"id": "…", "event": {"title": "…"}, "sessions": [{"date": "2026-11-05", "time": "12:00"}], …}], "pagination": {"has_more": false}}:', 'post-press-api'); ?></p>
            <ul class="ppapi-list">
                <li><?php echo wp_kses(__('Records path <code>data</code>, success flag <code>ok</code> must equal <code>true</code>, external ID path <code>id</code>.', 'post-press-api'), ['code' => []]); ?></li>
                <li><?php echo wp_kses(__('Optional pagination: offset/limit with has-more path <code>pagination.has_more</code>.', 'post-press-api'), ['code' => []]); ?></li>
                <li><?php esc_html_e('Destination: your existing events post type.', 'post-press-api'); ?></li>
            </ul>
            <?php $this->table([__('Source', 'post-press-api'), __('Transformation', 'post-press-api'), __('Destination', 'post-press-api')], $rows, true); ?>
            <p><?php esc_html_e('Leave the record\'s "status": "received" unmapped (new posts use the preset\'s status), or convert it with a Value map. "timezone": "Eastern" is not interpreted; set America/New_York explicitly in a Date/time transformation if conversion is needed.', 'post-press-api'); ?></p>
        </section>
        <?php
    }

    private function security(): void
    {
        ?>
        <section class="ppapi-card" id="ppapi-about-security" aria-labelledby="ppapi-about-security-heading">
            <h2 id="ppapi-about-security-heading"><?php esc_html_e('Security and your data', 'post-press-api'); ?></h2>
            <ul class="ppapi-list">
                <li><?php esc_html_e('Only administrators (manage_options) can use Post Press API, and saving or running a preset also requires permission to create — and if needed publish — posts of the destination type.', 'post-press-api'); ?></li>
                <li><?php esc_html_e('Requests are made by this server to public addresses only, with TLS certificate checks on. Credentials are only sent to https:// endpoints and never follow a redirect to another host.', 'post-press-api'); ?></li>
                <li><?php esc_html_e('Tokens and API keys are stored server-side, separately from the preset, and never shown again: leave the field blank to keep a saved credential, or remove it explicitly. Changing the endpoint host requires entering the credential again.', 'post-press-api'); ?></li>
                <li><?php esc_html_e('Previews, history, and the activity log never contain credentials (even if an API echoes one back) and never store full payloads.', 'post-press-api'); ?></li>
                <li>
                    <?php
                    printf(
                        /* translators: %d: number of records. */
                        esc_html__('Sample JSON you paste is saved with its preset (do not paste credentials). Test Request shows a bounded preview, and dry runs evaluate the first %d records.', 'post-press-api'),
                        (int) PreviewService::DRY_RUN_RECORDS
                    );
                    ?>
                </li>
                <li><?php esc_html_e('Deactivating stops scheduled runs but keeps presets, history, and posts. Deleting the plugin removes its settings and history but never the posts it imported.', 'post-press-api'); ?></li>
            </ul>
        </section>
        <?php
    }

    private function limits(): void
    {
        ?>
        <section class="ppapi-card" id="ppapi-about-limits" aria-labelledby="ppapi-about-limits-heading">
            <h2 id="ppapi-about-limits-heading"><?php esc_html_e('Not included in this version', 'post-press-api'); ?></h2>
            <ul class="ppapi-list">
                <li><?php esc_html_e('Receiving webhooks or sending changes back to the API (imports are one-way and outbound).', 'post-press-api'); ?></li>
                <li><?php esc_html_e('Cursor or Link-header pagination.', 'post-press-api'); ?></li>
                <li><?php esc_html_e('Downloading media: featured images and ACF image, file, and gallery fields.', 'post-press-api'); ?></li>
                <li><?php esc_html_e('ACF relationship-type, flexible content, and clone fields.', 'post-press-api'); ?></li>
                <li><?php esc_html_e('Encrypting stored credentials (WordPress has no built-in secret storage); protect database access and backups.', 'post-press-api'); ?></li>
            </ul>
            <p class="description">
                <?php esc_html_e('Developers: the plugin README describes every hook (ppapi_destination_post_types, ppapi_allowed_post_statuses, ppapi_blocked_meta_keys, ppapi_request_args, ppapi_record_imported, ppapi_run_finished, ppapi_log) and the architecture.', 'post-press-api'); ?>
            </p>
        </section>
        <?php
    }

    /**
     * Renders a simple reference table.
     *
     * @param list<string>       $headers  Column headers.
     * @param list<list<string>> $rows     Rows of plain text.
     * @param bool               $code_first Whether to render the first column as code.
     */
    private function table(array $headers, array $rows, bool $code_first = false): void
    {
        ?>
        <table class="widefat striped ppapi-reference-table">
            <thead>
                <tr>
                    <?php foreach ($headers as $header) : ?>
                        <th scope="col"><?php echo esc_html($header); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <?php foreach ($row as $index => $cell) : ?>
                            <td><?php echo $index === 0 && $code_first ? '<code>' . esc_html($cell) . '</code>' : esc_html($cell); ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
