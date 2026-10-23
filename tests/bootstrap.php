<?php
/**
 * Test bootstrap: in-memory WordPress stubs, optional ACF stub, fake $wpdb,
 * and the plugin's own autoloader.
 *
 * Test files can opt out with markers in their header comment:
 *   @ppapi-no-acf   do not define any ACF functions (plugin must work without ACF)
 *   @ppapi-bare     do not define plugin constants or the autoloader (root-file tests)
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Any warning, notice, or deprecation fails the test that caused it.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

define('ABSPATH', sys_get_temp_dir() . '/ppapi-tests-wp/');
define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/ppapi-tests-wp/wp-content/plugins');
define('PPAPI_TEST_ROOT', dirname(__DIR__));

require __DIR__ . '/stubs/wordpress.php';
require __DIR__ . '/stubs/media.php';
require __DIR__ . '/stubs/wpdb.php';

if (!defined('PPAPI_TEST_NO_ACF')) {
    require __DIR__ . '/stubs/acf.php';
}

$GLOBALS['wpdb'] = new FakeWpdb();

if (!defined('PPAPI_TEST_BARE')) {
    preg_match('/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents(PPAPI_TEST_ROOT . '/post-press-api.php'), $plugin_header);
    define('PPAPI_VERSION', $plugin_header[1]);
    define('PPAPI_FILE', PPAPI_TEST_ROOT . '/post-press-api.php');
    define('PPAPI_PATH', PPAPI_TEST_ROOT . '/');
    define('PPAPI_URL', 'https://example.test/wp-content/plugins/post-press-api/');

    require PPAPI_PATH . 'src/Autoloader.php';
    \PostPressAPI\Autoloader::register();
}

require __DIR__ . '/TestCase.php';
