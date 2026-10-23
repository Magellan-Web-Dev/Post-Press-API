<?php
/**
 * Update source isolation: updates for this plugin only ever come from its
 * GitHub repository (Update URI), conflicting entries are removed even when
 * GitHub is unavailable, and other plugins' updates are never touched.
 */

declare(strict_types=1);

use PostPressAPI\Tests\TestCase;
use PostPressAPI\Updates\GitHubUpdater;

final class UpdateSourceTest extends TestCase
{
    private const BASENAME   = 'post-press-api/post-press-api.php';
    private const UPDATE_URI = 'https://github.com/Magellan-Web-Dev/Post-Press-API';

    /** An entry a WordPress.org plugin with the same slug would produce. */
    private function directoryEntry(string $version = '9.9.9'): object
    {
        return (object) [
            'id'          => 'w.org/plugins/post-press-api',
            'slug'        => 'post-press-api',
            'plugin'      => self::BASENAME,
            'new_version' => $version,
            'url'         => 'https://wordpress.org/plugins/post-press-api/',
            'package'     => 'https://downloads.wordpress.org/plugin/post-press-api.' . $version . '.zip',
        ];
    }

    private function otherEntry(): object
    {
        return (object) ['id' => 'w.org/plugins/other', 'slug' => 'other', 'plugin' => 'other/other.php', 'new_version' => '2.0', 'package' => 'https://downloads.wordpress.org/plugin/other.2.0.zip'];
    }

    private function transient(array $response = [], array $no_update = []): object
    {
        return (object) [
            'checked'   => [self::BASENAME => PPAPI_VERSION, 'other/other.php' => '1.0'],
            'response'  => $response + ['other/other.php' => $this->otherEntry()],
            'no_update' => $no_update + ['tidy/tidy.php' => (object) ['id' => 'w.org/plugins/tidy', 'plugin' => 'tidy/tidy.php', 'new_version' => '1.0', 'package' => '']],
        ];
    }

    private function assertOthersUntouched(object $transient): void
    {
        $this->assertEquals($this->otherEntry(), $transient->response['other/other.php'], 'other plugins\' updates are kept');
        $this->assertArrayHasKey('tidy/tidy.php', $transient->no_update);
    }

    public function testPluginHeaderDeclaresTheGitHubUpdateUri(): void
    {
        $header = (string) file_get_contents(PPAPI_FILE, false, null, 0, 2000);
        $this->assertSame(1, preg_match('/^\s*\*\s*Update URI:\s*(\S+)\s*$/m', $header, $match));
        $this->assertSame(self::UPDATE_URI, $match[1]);

        GitHubUpdater::init();
        $this->assertTrue((bool) has_filter('update_plugins_github.com'), 'WordPress asks the updater for Update URI hosts');
        $this->assertTrue((bool) has_filter('site_transient_update_plugins'));
    }

    public function testNewerReleaseIsOfferedFromGitHub(): void
    {
        $this->queueJson(['tag_name' => 'v99.0.0', 'html_url' => 'https://github.com/Magellan-Web-Dev/Post-Press-API/releases/tag/v99.0.0']);
        $transient = GitHubUpdater::checkForUpdate($this->transient([self::BASENAME => $this->directoryEntry('99.5.0')]));

        $entry = $transient->response[self::BASENAME];
        $this->assertSame('99.0.0', $entry->new_version, 'the GitHub release replaces the conflicting entry');
        $this->assertSame(self::UPDATE_URI, $entry->id);
        $this->assertSame(self::BASENAME, $entry->plugin);
        $this->assertSame('https://github.com/Magellan-Web-Dev/Post-Press-API/archive/refs/tags/v99.0.0.zip', $entry->package);
        $this->assertOthersUntouched($transient);

        // WordPress's Update URI flow gets the same release (from the cache).
        $update = GitHubUpdater::updateFromSource(false, ['UpdateURI' => self::UPDATE_URI, 'Version' => PPAPI_VERSION], self::BASENAME, []);
        $this->assertSame('99.0.0', $update['version']);
        $this->assertSame($entry->package, $update['package']);
        $this->assertCount(1, \WPStub::$http_log);
    }

    /** The confirmed collision: a directory entry survived when GitHub reported the installed version. */
    public function testConflictingEntryIsRemovedWhenGitHubHasNoNewerRelease(): void
    {
        $this->queueJson(['tag_name' => 'v' . PPAPI_VERSION, 'html_url' => 'https://github.com/Magellan-Web-Dev/Post-Press-API/releases/latest']);
        $transient = GitHubUpdater::checkForUpdate($this->transient([self::BASENAME => $this->directoryEntry()]));

        $this->assertSame(false, isset($transient->response[self::BASENAME]), 'no update is offered from another source');
        $this->assertSame(self::UPDATE_URI, $transient->no_update[self::BASENAME]->id, 'listed as up to date so the auto-update toggle works');
        $this->assertSame('', $transient->no_update[self::BASENAME]->package);
        $this->assertOthersUntouched($transient);

        $update = GitHubUpdater::updateFromSource(false, ['UpdateURI' => self::UPDATE_URI], self::BASENAME, []);
        $this->assertSame(PPAPI_VERSION, $update['version']);
        $this->assertSame('', $update['package']);
    }

    public function testConflictingEntriesAreRemovedWhenGitHubIsUnavailable(): void
    {
        foreach ([new WP_Error('http_request_failed', 'timeout'), \WPStub::response(500, 'oops', [], 'Server Error'), \WPStub::json(['message' => 'Not Found'], 404)] as $failure) {
            delete_site_transient('ppapi_github_release');
            \WPStub::$http_queue[] = $failure;
            $transient = GitHubUpdater::checkForUpdate($this->transient(
                [self::BASENAME => $this->directoryEntry()],
                [self::BASENAME => $this->directoryEntry(PPAPI_VERSION)]
            ));

            $this->assertSame(false, isset($transient->response[self::BASENAME]));
            $this->assertSame(false, isset($transient->no_update[self::BASENAME]));
            $this->assertOthersUntouched($transient);
        }

        \WPStub::$http_queue[] = new WP_Error('http_request_failed', 'timeout');
        $this->assertSame(false, GitHubUpdater::updateFromSource(false, ['UpdateURI' => self::UPDATE_URI], self::BASENAME, []));
    }

    public function testOwnEntriesSurviveAnOutageButStaleOffersDoNot(): void
    {
        $own = (object) ['id' => self::UPDATE_URI, 'slug' => 'post-press-api', 'plugin' => self::BASENAME, 'new_version' => '99.0.0', 'package' => 'https://github.com/Magellan-Web-Dev/Post-Press-API/archive/refs/tags/v99.0.0.zip'];
        \WPStub::$http_queue[] = new WP_Error('http_request_failed', 'timeout');
        $kept = GitHubUpdater::checkForUpdate($this->transient([self::BASENAME => $own]));
        $this->assertSame($own, $kept->response[self::BASENAME]);

        $stale = clone $own;
        $stale->new_version = PPAPI_VERSION;
        $this->assertSame(false, isset(GitHubUpdater::removeForeignEntries($this->transient([self::BASENAME => $stale]))->response[self::BASENAME]));

        $forged = clone $own;
        $forged->package = 'https://example.net/post-press-api.zip';
        $this->assertSame(false, isset(GitHubUpdater::removeForeignEntries($this->transient([self::BASENAME => $forged]))->response[self::BASENAME]), 'packages must come from the repository');
    }

    public function testStoredTransientIsFilteredOnReadWithoutNetworkAccess(): void
    {
        GitHubUpdater::init();
        set_site_transient('update_plugins', $this->transient([self::BASENAME => $this->directoryEntry()]));

        $read = get_site_transient('update_plugins');
        $read = apply_filters('site_transient_update_plugins', $read, 'update_plugins');

        $this->assertSame(false, isset($read->response[self::BASENAME]));
        $this->assertOthersUntouched($read);
        $this->assertCount(0, \WPStub::$http_log);
    }

    public function testOtherPluginsAreNeverAffected(): void
    {
        $sentinel = ['version' => '3.0', 'package' => 'https://github.com/someone/else/archive/v3.zip'];
        $this->assertSame($sentinel, GitHubUpdater::updateFromSource($sentinel, ['UpdateURI' => 'https://github.com/someone/else'], 'else/else.php', []));
        $this->assertSame(false, GitHubUpdater::updateFromSource(false, ['UpdateURI' => 'https://github.com/someone/else'], self::BASENAME, []), 'a different plugin installed in this folder is not ours');
        $this->assertCount(0, \WPStub::$http_log);

        $plain = (object) ['response' => ['other/other.php' => $this->otherEntry()], 'no_update' => []];
        $this->assertEquals((object) ['response' => ['other/other.php' => $this->otherEntry()], 'no_update' => []], GitHubUpdater::removeForeignEntries($plain));
    }
}
