<?php

declare(strict_types=1);

namespace PostPressAPI\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Admin notices selected by a fixed code in the query string (never free text from the URL).
 */
final class Notices
{
    /**
     * Prints the notice for ?ppapi_notice=…, if any.
     *
     * @return void
     */
    public static function render_from_query(): void
    {
        $code = isset($_GET['ppapi_notice']) ? sanitize_key(wp_unslash($_GET['ppapi_notice'])) : '';
        if ($code === '') {
            return;
        }

        $messages = [
            'created'     => ['success', __('Preset created. It starts disabled and manual-only; test it, then enable it.', 'post-press-api')],
            'saved'       => ['success', __('Preset saved.', 'post-press-api')],
            'duplicated'  => ['success', __('Preset duplicated. The copy is disabled until you enable it.', 'post-press-api')],
            'enabled'     => ['success', __('Preset enabled.', 'post-press-api')],
            'disabled'    => ['success', __('Preset disabled. Its scheduled runs were removed.', 'post-press-api')],
            'deleted'     => ['success', __('Preset deleted. Posts it imported were kept.', 'post-press-api')],
            'not_ready'   => ['error', __('The preset cannot be enabled yet. Open it to see which settings are missing.', 'post-press-api')],
            'missing'     => ['error', __('That preset no longer exists.', 'post-press-api')],
            'log_cleared' => ['success', __('Activity log cleared.', 'post-press-api')],
        ];

        if (!isset($messages[$code])) {
            return;
        }

        [$type, $message] = $messages[$code];
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }

    /**
     * Prints a list of messages in one notice.
     *
     * @param string       $type     success, warning, error, or info.
     * @param list<string> $messages Messages (plain text).
     *
     * @return void
     */
    public static function render_list(string $type, array $messages): void
    {
        if ($messages === []) {
            return;
        }

        echo '<div class="notice notice-' . esc_attr($type) . '">';
        if (count($messages) === 1) {
            echo '<p>' . esc_html($messages[0]) . '</p>';
        } else {
            echo '<ul class="ppapi-notice-list">';
            foreach ($messages as $message) {
                echo '<li>' . esc_html($message) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
}
