<?php
/**
 * @ppapi-bare
 *
 * The root file's PHP version guard. Runs without the test bootstrap's plugin
 * constants so the real root file can be loaded.
 *
 * Only PHP 8.2+ is available in this environment, so the old-runtime branch is
 * exercised by loading a copy of the root file whose PHP_VERSION reads 7.4.33,
 * and the root file is checked for syntax newer than PHP 7.
 */

declare(strict_types=1);

use PostPressAPI\Tests\TestCase;

final class RootGuardTest extends TestCase
{
    private function root(): string
    {
        return PPAPI_TEST_ROOT . '/post-press-api.php';
    }

    public function testAOldPhpShowsAnEscapedNoticeAndLoadsNothing(): void
    {
        $code = str_replace('PHP_VERSION', "'7.4.33<b>'", (string) file_get_contents($this->root()));
        $dir  = sys_get_temp_dir() . '/ppapi-guard-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/post-press-api.php', $code);

        include $dir . '/post-press-api.php';
        unlink($dir . '/post-press-api.php');
        rmdir($dir);

        $this->assertFalse(defined('PPAPI_VERSION'), 'no constants on old PHP');
        $this->assertFalse(class_exists('PostPressAPI\\Autoloader', false), 'no PHP 8.1 code loaded');
        $this->assertFalse(has_action('plugins_loaded'));
        $this->assertTrue(has_action('admin_notices'));

        ob_start();
        do_action('admin_notices');
        $notice = (string) ob_get_clean();
        $this->assertContains('requires PHP 8.1 or higher', $notice);
        $this->assertContains('7.4.33&lt;b&gt;', $notice, 'version is escaped');
        $this->assertContains('notice-error', $notice);
    }

    public function testBCurrentPhpBootsThroughTheGuardedBranch(): void
    {
        include $this->root();

        preg_match('/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents($this->root()), $header);
        $this->assertSame($header[1], PPAPI_VERSION, 'header and runtime versions agree');
        $this->assertSame(PPAPI_TEST_ROOT . '/post-press-api.php', PPAPI_FILE);
        $this->assertSame(PPAPI_TEST_ROOT . '/', PPAPI_PATH);
        $this->assertTrue(class_exists('PostPressAPI\\Autoloader', false));
        $this->assertSame([20], array_keys(\WPStub::$filters['plugins_loaded'] ?? []), 'Plugin::instance() on plugins_loaded priority 20');
        $this->assertTrue((bool) has_action('activate_post-press-api/post-press-api.php'));
        $this->assertTrue((bool) has_action('deactivate_post-press-api/post-press-api.php'));
        $this->assertFalse(has_action('admin_notices'));
    }

    public function testCAutoloaderMapsNamespaceToSrc(): void
    {
        $this->assertTrue(class_exists('PostPressAPI\\Mapping\\PathResolver'));
        $this->assertFalse(class_exists('PostPressAPI\\Nope\\Missing'));
        $this->assertFalse(class_exists('PPAPI\\Mapping\\PathResolver'), 'only the PostPressAPI prefix is handled');
    }

    public function testRootAndUninstallFilesUseOnlyPhp7Syntax(): void
    {
        $modern = [];
        foreach (['T_MATCH', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_ENUM', 'T_READONLY', 'T_ATTRIBUTE'] as $name) {
            if (defined($name)) {
                $modern[constant($name)] = $name;
            }
        }

        foreach ([$this->root(), PPAPI_TEST_ROOT . '/uninstall.php'] as $file) {
            $code = (string) file_get_contents($file);
            foreach (token_get_all($code) as $token) {
                if (is_array($token) && isset($modern[$token[0]])) {
                    $this->fail(basename($file) . ' uses ' . $modern[$token[0]] . ' on line ' . $token[2]);
                }
            }
            $this->assertNotContains('?->', $code);
            $this->assertSame(0, preg_match('/function\s*\([^)]*\)\s*:\s*(mixed|never|static)\b/', $code), basename($file) . ' has PHP 8-only return types');
        }
    }

    public function testPhp81CodeIsOnlyReferencedInsideTheElseBranch(): void
    {
        $code  = (string) file_get_contents($this->root());
        $guard = strpos($code, "if (version_compare(PHP_VERSION, '8.1', '<')) {");
        $else  = strpos($code, '} else {');

        $this->assertTrue($guard !== false && $else !== false && $guard < $else);
        $this->assertTrue(strpos($code, 'src/Autoloader.php') > $else);
        $this->assertTrue(strpos($code, '\\PostPressAPI\\') > $else);
        $this->assertTrue(strpos($code, "define('PPAPI_VERSION'") > $else);
        $this->assertContains('Requires PHP:      8.1', $code);
        $this->assertContains('Requires at least: 6.0', $code);
    }
}
