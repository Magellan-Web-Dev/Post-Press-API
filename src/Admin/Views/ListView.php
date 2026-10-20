<?php

declare(strict_types=1);

namespace PostPressAPI\Admin\Views;

use PostPressAPI\Admin\AdminActions;
use PostPressAPI\Admin\AdminPage;
use PostPressAPI\Admin\Format;
use PostPressAPI\Admin\Notices;
use PostPressAPI\Http\Url;
use PostPressAPI\Presets\PresetSchema;
use PostPressAPI\Scheduling\Scheduler;
use PostPressAPI\Services;
use PostPressAPI\Sync\ImportRunner;

if (!defined('ABSPATH')) exit;

/**
 * The preset list screen.
 */
final class ListView
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
        $presets    = $this->services->presets()->all();
        $post_types = $this->services->destinations()->post_types();
        ?>
        <div class="wrap ppapi-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Post Press API', 'post-press-api'); ?></h1>
            <a href="<?php echo esc_url(AdminPage::url(['view' => 'new'])); ?>" class="page-title-action"><?php esc_html_e('Add New Preset', 'post-press-api'); ?></a>
            <hr class="wp-header-end">

            <?php Notices::render_from_query(); ?>

            <p class="ppapi-intro">
                <?php esc_html_e('Each preset fetches JSON records from an external API and maps them into posts. Runs never delete posts, and deleting a preset keeps the posts it imported.', 'post-press-api'); ?>
                <a href="<?php echo esc_url(AdminPage::url([], AdminPage::SLUG_ABOUT)); ?>"><?php esc_html_e('Learn how it works', 'post-press-api'); ?></a>
            </p>

            <?php if ($presets === []) : ?>
                <div class="ppapi-card ppapi-empty">
                    <h2><?php esc_html_e('No presets yet', 'post-press-api'); ?></h2>
                    <p><?php esc_html_e('Create a preset to describe an API request, choose which records to import, and map their fields to a post type.', 'post-press-api'); ?></p>
                    <p><a class="button button-primary" href="<?php echo esc_url(AdminPage::url(['view' => 'new'])); ?>"><?php esc_html_e('Create your first preset', 'post-press-api'); ?></a></p>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped table-view-list ppapi-presets-table">
                    <caption class="screen-reader-text"><?php esc_html_e('Request presets', 'post-press-api'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-primary"><?php esc_html_e('Name', 'post-press-api'); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e('Status', 'post-press-api'); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e('Destination', 'post-press-api'); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e('Schedule', 'post-press-api'); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e('Last run', 'post-press-api'); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e('Next run', 'post-press-api'); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e('Last result', 'post-press-api'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($presets as $preset) : ?>
                            <?php $this->row($preset, $post_types); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="description ppapi-cron-note">
                <?php esc_html_e('Times are shown in the site time zone. Scheduled runs use WP-Cron, which only runs when the site receives visits; for dependable timing, disable WP-Cron in wp-config.php (DISABLE_WP_CRON) and have the server call wp-cron.php every few minutes.', 'post-press-api'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Renders one preset row.
     *
     * @param array<string, mixed>  $preset     Preset.
     * @param array<string, string> $post_types Destination post types.
     *
     * @return void
     */
    private function row(array $preset, array $post_types): void
    {
        $id       = (string) $preset['id'];
        $edit_url = AdminPage::url(['view' => 'edit', 'preset' => $id]);
        $active   = $this->services->runs()->active($id);
        $last     = $this->services->runs()->last($id);
        $next     = $this->services->scheduler()->next_run($id);
        $secret   = $this->services->secrets()->value_for_origin($id, Url::origin((string) $preset['request']['url']));
        $problems = $this->services->preset_validator()->readiness_problems($preset, $secret !== null);
        $name     = $preset['name'] !== '' ? $preset['name'] : __('(untitled preset)', 'post-press-api');
        $type     = (string) $preset['destination']['post_type'];
        ?>
        <tr>
            <td class="column-primary" data-colname="<?php esc_attr_e('Name', 'post-press-api'); ?>">
                <strong><a class="row-title" href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($name); ?></a></strong>
                <div class="row-actions">
                    <span class="edit"><a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'post-press-api'); ?></a> | </span>
                    <span><a href="<?php echo esc_url(AdminActions::url('ppapi_duplicate', $id)); ?>"><?php esc_html_e('Duplicate', 'post-press-api'); ?></a> | </span>
                    <span>
                        <?php if ($preset['enabled']) : ?>
                            <a href="<?php echo esc_url(AdminActions::url('ppapi_toggle', $id, ['enable' => '0'])); ?>"><?php esc_html_e('Disable', 'post-press-api'); ?></a>
                        <?php else : ?>
                            <a href="<?php echo esc_url(AdminActions::url('ppapi_toggle', $id, ['enable' => '1'])); ?>"><?php esc_html_e('Enable', 'post-press-api'); ?></a>
                        <?php endif; ?>
                        |
                    </span>
                    <span><a href="<?php echo esc_url(AdminPage::url(['view' => 'history', 'preset' => $id])); ?>"><?php esc_html_e('History', 'post-press-api'); ?></a> | </span>
                    <span class="trash">
                        <a class="submitdelete" href="<?php echo esc_url(AdminActions::url('ppapi_delete', $id)); ?>" data-ppapi-confirm="<?php echo esc_attr(sprintf(
                            /* translators: %s: preset name. */
                            __('Delete the preset "%s"? Its settings, credential, schedule, and run history will be removed. Posts it imported are kept.', 'post-press-api'),
                            $name
                        )); ?>"><?php esc_html_e('Delete', 'post-press-api'); ?></a>
                    </span>
                </div>
                <button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e('Show more details', 'post-press-api'); ?></span></button>
            </td>
            <td data-colname="<?php esc_attr_e('Status', 'post-press-api'); ?>">
                <?php if ($preset['enabled']) : ?>
                    <span class="ppapi-badge ppapi-badge--on"><?php esc_html_e('Enabled', 'post-press-api'); ?></span>
                <?php else : ?>
                    <span class="ppapi-badge"><?php esc_html_e('Disabled', 'post-press-api'); ?></span>
                <?php endif; ?>
                <?php if ($problems !== []) : ?>
                    <span class="ppapi-badge ppapi-badge--warning"><?php esc_html_e('Needs setup', 'post-press-api'); ?></span>
                <?php endif; ?>
                <?php if ($active !== null && in_array($active['status'] ?? '', ImportRunner::ACTIVE, true)) : ?>
                    <span class="ppapi-badge ppapi-badge--info"><?php echo esc_html(Format::status((string) $active['status'])); ?></span>
                <?php endif; ?>
            </td>
            <td data-colname="<?php esc_attr_e('Destination', 'post-press-api'); ?>">
                <?php echo esc_html($type === '' ? '—' : ($post_types[$type] ?? sprintf(
                    /* translators: %s: post type. */
                    __('%s (not available)', 'post-press-api'),
                    $type
                ))); ?>
            </td>
            <td data-colname="<?php esc_attr_e('Schedule', 'post-press-api'); ?>"><?php echo esc_html(Format::schedule($preset)); ?></td>
            <td data-colname="<?php esc_attr_e('Last run', 'post-press-api'); ?>"><?php echo esc_html(Format::datetime((int) ($last['finished_at'] ?? 0))); ?></td>
            <td data-colname="<?php esc_attr_e('Next run', 'post-press-api'); ?>">
                <?php
                if (!$preset['enabled'] || Scheduler::recurrence($preset) === null) {
                    echo esc_html($preset['schedule']['frequency'] === PresetSchema::FREQ_MANUAL ? __('Manual only', 'post-press-api') : __('Not scheduled (disabled)', 'post-press-api'));
                } elseif ($next === null) {
                    echo '<span class="ppapi-text-warning">' . esc_html__('Not scheduled — save the preset again to reschedule.', 'post-press-api') . '</span>';
                } else {
                    echo esc_html(Format::datetime($next));
                }
                ?>
            </td>
            <td data-colname="<?php esc_attr_e('Last result', 'post-press-api'); ?>">
                <?php if ($last === null) : ?>
                    —
                <?php else : ?>
                    <span class="ppapi-status <?php echo esc_attr(Format::status_class((string) $last['status'])); ?>"><?php echo esc_html(Format::status((string) $last['status'])); ?></span>
                    <br><span class="ppapi-counts"><?php echo esc_html(Format::counts((array) $last['counts'])); ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}
