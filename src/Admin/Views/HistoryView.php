<?php

declare(strict_types=1);

namespace PostPressAPI\Admin\Views;

use PostPressAPI\Admin\AdminPage;
use PostPressAPI\Admin\Format;
use PostPressAPI\Services;
use PostPressAPI\Sync\RunRepository;

if (!defined('ABSPATH')) exit;

/**
 * Run history for one preset (the last 25 runs).
 */
final class HistoryView
{
    public function __construct(private readonly Services $services)
    {
    }

    /**
     * Renders the screen.
     *
     * @param array<string, mixed> $preset Preset.
     *
     * @return void
     */
    public function render(array $preset): void
    {
        $history = $this->services->runs()->history((string) $preset['id']);
        ?>
        <div class="wrap ppapi-wrap">
            <h1 class="wp-heading-inline">
                <?php
                printf(
                    /* translators: %s: preset name. */
                    esc_html__('Run history: %s', 'post-press-api'),
                    esc_html($preset['name'] !== '' ? $preset['name'] : __('(untitled preset)', 'post-press-api'))
                );
                ?>
            </h1>
            <a class="page-title-action" href="<?php echo esc_url(AdminPage::url(['view' => 'edit', 'preset' => $preset['id']])); ?>"><?php esc_html_e('Edit preset', 'post-press-api'); ?></a>
            <a class="page-title-action" href="<?php echo esc_url(AdminPage::url()); ?>"><?php esc_html_e('All presets', 'post-press-api'); ?></a>
            <hr class="wp-header-end">

            <p class="description">
                <?php
                printf(
                    /* translators: %d: number of runs kept. */
                    esc_html__('The last %d runs are kept. Times use the site time zone.', 'post-press-api'),
                    (int) RunRepository::HISTORY_LIMIT
                );
                ?>
            </p>

            <?php self::table($history); ?>
        </div>
        <?php
    }

    /**
     * Renders a history table (also used on the editor screen).
     *
     * @param list<array<string, mixed>> $history History entries.
     * @param int|null                   $limit   Maximum rows.
     *
     * @return void
     */
    public static function table(array $history, ?int $limit = null): void
    {
        if ($limit !== null) {
            $history = array_slice($history, 0, $limit);
        }

        if ($history === []) {
            echo '<p>' . esc_html__('No runs yet.', 'post-press-api') . '</p>';
            return;
        }
        ?>
        <table class="widefat striped ppapi-history-table">
            <caption class="screen-reader-text"><?php esc_html_e('Run history', 'post-press-api'); ?></caption>
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Started', 'post-press-api'); ?></th>
                    <th scope="col"><?php esc_html_e('Trigger', 'post-press-api'); ?></th>
                    <th scope="col"><?php esc_html_e('Result', 'post-press-api'); ?></th>
                    <th scope="col" class="ppapi-num"><?php esc_html_e('Created', 'post-press-api'); ?></th>
                    <th scope="col" class="ppapi-num"><?php esc_html_e('Updated', 'post-press-api'); ?></th>
                    <th scope="col" class="ppapi-num"><?php esc_html_e('Unchanged', 'post-press-api'); ?></th>
                    <th scope="col" class="ppapi-num"><?php esc_html_e('Skipped', 'post-press-api'); ?></th>
                    <th scope="col" class="ppapi-num"><?php esc_html_e('Failed', 'post-press-api'); ?></th>
                    <th scope="col" class="ppapi-num"><?php esc_html_e('Pages', 'post-press-api'); ?></th>
                    <th scope="col"><?php esc_html_e('Details', 'post-press-api'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $run) : ?>
                    <?php
                    $counts = (array) ($run['counts'] ?? []);
                    $issues = (array) ($run['issues'] ?? []);
                    ?>
                    <tr>
                        <td><?php echo esc_html(Format::datetime((int) ($run['started_at'] ?? 0))); ?></td>
                        <td><?php echo esc_html(($run['trigger'] ?? '') === 'schedule' ? __('Schedule', 'post-press-api') : __('Manual', 'post-press-api')); ?><?php echo !empty($run['force']) ? ' ' . esc_html__('(re-apply)', 'post-press-api') : ''; ?></td>
                        <td><span class="ppapi-status <?php echo esc_attr(Format::status_class((string) ($run['status'] ?? ''))); ?>"><?php echo esc_html(Format::status((string) ($run['status'] ?? ''))); ?></span></td>
                        <td class="ppapi-num"><?php echo (int) ($counts['created'] ?? 0); ?></td>
                        <td class="ppapi-num"><?php echo (int) ($counts['updated'] ?? 0); ?></td>
                        <td class="ppapi-num"><?php echo (int) ($counts['unchanged'] ?? 0); ?></td>
                        <td class="ppapi-num"><?php echo (int) ($counts['skipped'] ?? 0); ?></td>
                        <td class="ppapi-num"><?php echo (int) ($counts['failed'] ?? 0); ?></td>
                        <td class="ppapi-num"><?php echo (int) ($run['pages'] ?? 0); ?></td>
                        <td>
                            <?php if (($run['message'] ?? '') !== '') : ?>
                                <p class="ppapi-history-message"><?php echo esc_html((string) $run['message']); ?></p>
                            <?php endif; ?>
                            <?php if ($issues !== []) : ?>
                                <details class="ppapi-issues">
                                    <summary>
                                        <?php
                                        printf(
                                            /* translators: %d: number of failed records listed. */
                                            esc_html(_n('%d failed record', '%d failed records', count($issues) + (int) ($run['issue_overflow'] ?? 0), 'post-press-api')),
                                            count($issues) + (int) ($run['issue_overflow'] ?? 0)
                                        );
                                        ?>
                                    </summary>
                                    <ul>
                                        <?php foreach ($issues as $issue) : ?>
                                            <li>
                                                <strong>
                                                    <?php
                                                    printf(
                                                        /* translators: 1: record position, 2: external ID. */
                                                        esc_html__('Record %1$d (ID %2$s):', 'post-press-api'),
                                                        (int) ($issue['position'] ?? 0),
                                                        esc_html(($issue['external_id'] ?? '') !== '' ? (string) $issue['external_id'] : '—')
                                                    );
                                                    ?>
                                                </strong>
                                                <?php echo esc_html((string) ($issue['message'] ?? '')); ?>
                                                <?php if (!empty($issue['post_id'])) : ?>
                                                    <a href="<?php echo esc_url((string) get_edit_post_link((int) $issue['post_id'])); ?>"><?php printf(esc_html__('Post #%d', 'post-press-api'), (int) $issue['post_id']); ?></a>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                        <?php if ((int) ($run['issue_overflow'] ?? 0) > 0) : ?>
                                            <li>
                                                <?php
                                                printf(
                                                    /* translators: %d: number of further failures. */
                                                    esc_html__('…and %d more not listed.', 'post-press-api'),
                                                    (int) $run['issue_overflow']
                                                );
                                                ?>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
