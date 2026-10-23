<?php
/**
 * GitHub updater: inactive while unconfigured, version handling, and safe folder normalization.
 */

declare(strict_types=1);

use PostPressAPI\Tests\TestCase;
use PostPressAPI\Updates\GitHubUpdater;

/** Minimal WP_Filesystem double recording every operation. */
final class FakeFilesystem
{
    /** @var array<string, bool> */
    public array $paths = [];
    /** @var list<array<int, string>> */
    public array $ops = [];
    /** @var list<string> Sources whose move() fails. */
    public array $fail_moves = [];

    public function wp_plugins_dir(): string
    {
        return '/srv/wp/wp-content/plugins/';
    }

    public function wp_content_dir(): string
    {
        return '/srv/wp/wp-content/';
    }

    public function exists(string $path): bool
    {
        return isset($this->paths[rtrim($path, '/')]);
    }

    public function is_dir(string $path): bool
    {
        return $this->exists($path);
    }

    public function mkdir(string $path): bool
    {
        $this->ops[]                       = ['mkdir', $path];
        $this->paths[rtrim($path, '/')] = true;
        return true;
    }

    public function move(string $from, string $to, bool $overwrite = false): bool
    {
        $this->ops[] = ['move', $from, $to];
        if (in_array($from, $this->fail_moves, true) || $this->exists($to)) {
            return false;
        }
        foreach (array_keys($this->paths) as $path) {
            if ($path === $from || str_starts_with($path, $from . '/')) {
                unset($this->paths[$path]);
                $this->paths[$to . substr($path, strlen($from))] = true;
            }
        }
        return true;
    }

    public function delete(string $path, bool $recursive = false): bool
    {
        $this->ops[] = ['delete', $path];
        foreach (array_keys($this->paths) as $existing) {
            if ($existing === $path || str_starts_with($existing, $path . '/')) {
                unset($this->paths[$existing]);
            }
        }
        return true;
    }
}

final class UpdaterTest extends TestCase
{
    private const PLUGINS = '/srv/wp/wp-content/plugins';

    private FakeFilesystem $fs;

    public function setUp(): void
    {
        parent::setUp();
        $this->fs              = new FakeFilesystem();
        $GLOBALS['wp_filesystem'] = $this->fs;
    }

    public function tearDown(): void
    {
        unset($GLOBALS['wp_filesystem']);
    }

    private function install(string $folder): void
    {
        $this->fs->paths[self::PLUGINS . '/' . $folder]                        = true;
        $this->fs->paths[self::PLUGINS . '/' . $folder . '/post-press-api.php'] = true;
    }

    private function normalize(string $installed_folder, string $plugin = 'post-press-api/post-press-api.php'): mixed
    {
        return GitHubUpdater::normalizeFolderAfterInstall(
            true,
            ['plugin' => $plugin, 'type' => 'plugin', 'action' => 'update'],
            ['destination' => self::PLUGINS . '/' . $installed_folder . '/', 'remote_destination' => self::PLUGINS . '/' . $installed_folder . '/']
        );
    }

    private function deletes(): array
    {
        return array_values(array_filter($this->fs->ops, static fn (array $op): bool => $op[0] === 'delete'));
    }

    /** The repository shipped with this plugin enables its update hooks. */
    public function testConfiguredRepositoryRegistersHooks(): void
    {
        GitHubUpdater::init();

        $this->assertTrue(GitHubUpdater::isConfigured());
        foreach (['pre_set_site_transient_update_plugins', 'plugins_api', 'upgrader_post_install', 'admin_init', 'admin_notices', 'plugin_action_links_post-press-api/post-press-api.php'] as $hook) {
            $this->assertTrue((bool) has_filter($hook), $hook . ' must be hooked while configured');
        }
    }

    /** A current release is fetched once, cached, and shown without an update package. */
    public function testConfiguredRepositoryUsesCachedRelease(): void
    {
        $this->queueJson(['tag_name' => 'v' . PPAPI_VERSION, 'html_url' => 'https://github.com/Magellan-Web-Dev/Post-Press-API/releases/latest']);
        $transient = (object) ['checked' => ['post-press-api/post-press-api.php' => PPAPI_VERSION], 'response' => []];
        $this->assertSame($transient, GitHubUpdater::checkForUpdate($transient));
        $this->assertSame([], $transient->response);
        $this->assertSame('', $transient->no_update['post-press-api/post-press-api.php']->package);
        $this->assertSame(PPAPI_VERSION, $transient->no_update['post-press-api/post-press-api.php']->new_version);

        $info = GitHubUpdater::pluginsApi(false, 'plugin_information', (object) ['slug' => 'post-press-api']);
        $this->assertSame(PPAPI_VERSION, $info->version);

        $_GET = ['ppapi_update_checked' => '1'];
        ob_start();
        GitHubUpdater::maybeShowCheckedNotice();
        $this->assertContains('running the latest version', (string) ob_get_clean());
        $this->assertCount(1, \WPStub::$http_log, 'subsequent checks use the cached release');
        $this->assertSame('https://api.github.com/repos/Magellan-Web-Dev/Post-Press-API/releases/latest', \WPStub::$http_log[0]['url']);
    }

    public function testUpdateItemComparesVersions(): void
    {
        $release = ['tag' => 'v1.2.0', 'version' => '1.2.0', 'zip_url' => 'https://github.com/o/r/archive/refs/tags/v1.2.0.zip', 'html_url' => 'https://github.com/o/r/releases/tag/v1.2.0'];

        $newer = GitHubUpdater::buildUpdateItem($release, '1.0.0');
        $this->assertSame('1.2.0', $newer->new_version);
        $this->assertSame($release['zip_url'], $newer->package);
        $this->assertSame('post-press-api/post-press-api.php', $newer->plugin);
        $this->assertSame('8.1', $newer->requires_php);

        $same = GitHubUpdater::buildUpdateItem(['version' => '1.0.0'] + $release, '1.0.0');
        $this->assertSame('1.0.0', $same->new_version);
        $this->assertSame('', $same->package, 'no package offered when not newer');
    }

    public function testFreshInstallMovesIntoCanonicalFolder(): void
    {
        $this->install('post-press-api-1.2.0');

        $this->assertTrue($this->normalize('post-press-api-1.2.0'));
        $this->assertTrue($this->fs->exists(self::PLUGINS . '/post-press-api/post-press-api.php'));
        $this->assertFalse($this->fs->exists(self::PLUGINS . '/post-press-api-1.2.0'));
        $this->assertSame([], $this->deletes());
    }

    public function testExistingFolderIsNeverDeletedWhenBackupFails(): void
    {
        $this->install('post-press-api');
        $this->install('post-press-api-1.2.0');
        $this->fs->fail_moves[] = self::PLUGINS . '/post-press-api';

        $result = $this->normalize('post-press-api-1.2.0');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('ppapi_update_backup_failed', $result->get_error_code());
        $this->assertSame([], $this->deletes(), 'no destructive fallback');
        $this->assertTrue($this->fs->exists(self::PLUGINS . '/post-press-api/post-press-api.php'), 'existing installation preserved');
        $this->assertTrue($this->fs->exists(self::PLUGINS . '/post-press-api-1.2.0/post-press-api.php'));
    }

    public function testFailedMoveRestoresTheBackup(): void
    {
        $this->install('post-press-api');
        $this->install('post-press-api-1.2.0');
        $this->fs->fail_moves[] = self::PLUGINS . '/post-press-api-1.2.0';

        $result = $this->normalize('post-press-api-1.2.0');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('ppapi_update_move_failed', $result->get_error_code());
        $this->assertTrue($this->fs->exists(self::PLUGINS . '/post-press-api/post-press-api.php'), 'backup restored');
        $this->assertSame([], $this->deletes());
    }

    public function testBackupLivesOutsideThePluginsDirectoryAndIsRemovedAfterSuccess(): void
    {
        $this->install('post-press-api');
        $this->install('post-press-api-1.2.0');

        $this->assertTrue($this->normalize('post-press-api-1.2.0'));

        $moves = array_values(array_filter($this->fs->ops, static fn (array $op): bool => $op[0] === 'move'));
        $this->assertTrue(str_starts_with($moves[0][2], '/srv/wp/wp-content/upgrade/post-press-api-previous-'), 'backup goes to wp-content/upgrade');
        $this->assertSame([['delete', $moves[0][2]]], $this->deletes(), 'only this plugin\'s own backup is deleted, after success');
    }

    public function testOtherPluginsAndUnexpectedLocationsAreLeftAlone(): void
    {
        $this->install('other-1.0');
        $this->assertTrue($this->normalize('other-1.0', 'other/other.php'));
        $this->assertSame([], $this->fs->ops);

        $result = GitHubUpdater::normalizeFolderAfterInstall(true, ['plugin' => 'post-press-api/post-press-api.php'], ['remote_destination' => '/srv/elsewhere/post-press-api-1.2.0/']);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame([], $this->fs->ops);

        $failed = new WP_Error('install_failed', 'x');
        $this->assertSame($failed, GitHubUpdater::normalizeFolderAfterInstall($failed, ['plugin' => 'post-press-api/post-press-api.php'], []));
    }
}
