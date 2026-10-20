<?php
/**
 * Post Press API uninstall routine.
 *
 * Runs only when an administrator deletes the plugin from the Plugins screen.
 *
 * Removes:  presets, stored credentials, sample JSON, run state, run history,
 *           locks, the activity log, the update-check cache, and plugin cron events.
 * Keeps:    every imported post and all of its content, meta, and ACF values.
 *           Tracking meta (keys starting with _ppapi_) is also kept on those posts
 *           so reinstalling the plugin reconnects to them. To remove it manually:
 *           DELETE FROM wp_postmeta WHERE meta_key LIKE '\_ppapi\_%';
 *
 * Kept free of PHP 8 syntax because it runs without the plugin's version guard.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Options: ppapi_presets, ppapi_preset_*, ppapi_secret_*, ppapi_sample_*, ppapi_run_*,
// ppapi_runbuf_*, ppapi_runcancel_*, ppapi_history_*, ppapi_lock_*, ppapi_log, and
// any ppapi_ transients.
$ppapi_like = $wpdb->esc_like('ppapi_') . '%';
$ppapi_options = $wpdb->get_col($wpdb->prepare(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
    $ppapi_like,
    $wpdb->esc_like('_transient_ppapi_') . '%',
    $wpdb->esc_like('_transient_timeout_ppapi_') . '%'
));

foreach ((array) $ppapi_options as $ppapi_option) {
    delete_option($ppapi_option);
}

delete_site_transient('ppapi_github_release');

if (function_exists('wp_unschedule_hook')) {
    wp_unschedule_hook('ppapi_scheduled_run');
    wp_unschedule_hook('ppapi_continue_run');
}
