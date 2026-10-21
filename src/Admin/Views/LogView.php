<?php

declare(strict_types=1);

namespace PostPressAPI\Admin\Views;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Admin\AdminPage;
use PostPressAPI\Admin\Format;
use PostPressAPI\Admin\Notices;
use PostPressAPI\Logging\Logger;
use PostPressAPI\Services;

/**
 * Activity log screen.
 */
final class LogView
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
        $entries = $this->services->logger()->entries();
        $names   = [];
        foreach ($this->services->presets()->all() as $preset) {
            $names[$preset['id']] = $preset['name'];
        }
        ?>
        <div class="wrap ppapi-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Post Press API activity log', 'post-press-api'); ?></h1>
            <hr class="wp-header-end">
            <?php Notices::render_from_query(); ?>

            <p class="description">
                <?php
                printf(
                    /* translators: %d: number of entries kept. */
                    esc_html__('The newest %d entries are kept. Entries contain identifiers and messages only — never credentials or payloads.', 'post-press-api'),
                    (int) Logger::LIMIT
                );
                ?>
            </p>

            <?php if ($entries === []) : ?>
                <p><?php esc_html_e('The log is empty.', 'post-press-api'); ?></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ppapi-log-actions">
                    <input type="hidden" name="action" value="ppapi_clear_log">
                    <?php wp_nonce_field('ppapi_clear_log'); ?>
                    <button type="submit" class="button" data-ppapi-confirm="<?php esc_attr_e('Clear the activity log?', 'post-press-api'); ?>"><?php esc_html_e('Clear log', 'post-press-api'); ?></button>
                </form>
                <table class="widefat striped ppapi-log-table">
                    <caption class="screen-reader-text"><?php esc_html_e('Activity log', 'post-press-api'); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Time', 'post-press-api'); ?></th>
                            <th scope="col"><?php esc_html_e('Level', 'post-press-api'); ?></th>
                            <th scope="col"><?php esc_html_e('Preset', 'post-press-api'); ?></th>
                            <th scope="col"><?php esc_html_e('Message', 'post-press-api'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry) : ?>
                            <?php $preset_id = (string) ($entry['context']['preset_id'] ?? ''); ?>
                            <tr>
                                <td><?php echo esc_html(Format::datetime((int) $entry['time'])); ?></td>
                                <td><span class="ppapi-level ppapi-level--<?php echo esc_attr((string) $entry['level']); ?>"><?php echo esc_html(ucfirst((string) $entry['level'])); ?></span></td>
                                <td>
                                    <?php if ($preset_id !== '' && isset($names[$preset_id])) : ?>
                                        <a href="<?php echo esc_url(AdminPage::url(['view' => 'history', 'preset' => $preset_id])); ?>"><?php echo esc_html($names[$preset_id] !== '' ? $names[$preset_id] : $preset_id); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($preset_id !== '' ? $preset_id : '—'); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html((string) $entry['message']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
