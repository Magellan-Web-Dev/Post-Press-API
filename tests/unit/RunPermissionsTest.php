<?php
/**
 * Object-level authorization: every change to an existing post is checked
 * against the run's explicit account, which is preserved across background
 * continuations and defined for scheduled runs.
 */

declare(strict_types=1);

use PostPressAPI\REST\RestController;
use PostPressAPI\Scheduling\ContinuationScheduler;
use PostPressAPI\Scheduling\Scheduler;
use PostPressAPI\Support\Permissions;
use PostPressAPI\Tests\TestCase;

final class RunPermissionsTest extends TestCase
{
    private const MANAGER = 2;
    private const OTHER   = 3;

    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
        // Manages the plugin and may publish their own posts, but may not edit other users' posts.
        wpstub_add_user(self::MANAGER, 'manager', ['manage_options', 'edit_posts', 'publish_posts', 'edit_published_posts', 'upload_files', 'read']);
        wpstub_add_user(self::OTHER, 'other', ['edit_posts', 'publish_posts', 'edit_published_posts', 'read']);
    }

    /** A matching preset whose default author is the restricted manager, saved by $saved_by. */
    private function matchingPreset(int $saved_by = self::MANAGER, array $overrides = []): array
    {
        $preset = array_replace_recursive([
            'id'          => $this->services->presets()->create_id(),
            'name'        => 'Events feed',
            'enabled'     => true,
            'request'     => ['url' => 'https://api.example.com/events', 'retries' => 0],
            'response'    => ['records_path' => 'data', 'identity' => ['mode' => 'path', 'path' => 'id']],
            'destination' => ['post_type' => 'events', 'status' => 'publish', 'author' => self::MANAGER],
            'sync'        => ['policy' => 'update_changed', 'match' => ['type' => 'meta', 'key' => 'legacy_id'], 'trashed' => 'restore'],
            'schedule'    => ['frequency' => 'hourly'],
            'batch_size'  => 1,
        ], $overrides);
        $preset['mappings'] = $overrides['mappings'] ?? [$this->row('event.title', 'core', 'post_title')];

        return $this->services->presets()->save($preset, $saved_by);
    }

    private function existingPost(int $author, string $status, string $external = 'r1'): int
    {
        return (int) wp_insert_post(['post_type' => 'events', 'post_status' => $status, 'post_author' => $author, 'post_title' => 'Original', 'meta_input' => ['legacy_id' => $external]]);
    }

    private function payload(array $titles): array
    {
        $records = [];
        foreach ($titles as $id => $title) {
            $records[] = ['id' => $id, 'event' => ['title' => $title]];
        }
        return ['ok' => true, 'data' => $records];
    }

    private function untouched(int $post_id, string $status = 'private'): void
    {
        $this->assertSame('Original', \WPStub::$posts[$post_id]['post_title']);
        $this->assertSame($status, \WPStub::$posts[$post_id]['post_status']);
        $this->assertSame('', $this->meta($post_id, '_ppapi_key'), 'not linked to the preset');
        $this->assertSame('', $this->meta($post_id, '_ppapi_state'));
    }

    /** The confirmed bypass: a restricted manager adopting another user's private post. */
    public function testRestrictedManagerCannotChangeAnotherUsersPrivatePost(): void
    {
        $private = $this->existingPost(self::OTHER, 'private');
        $preset  = $this->matchingPreset();
        wp_set_current_user(self::MANAGER);
        $this->queueJson($this->payload(['r1' => 'Hijacked']));

        $started = (new RestController($this->services))->run_start(new WP_REST_Request(['id' => $preset['id']]));
        $this->assertTrue($started->get_data()['active'], 'type-level checks pass for this manager');
        $this->services->runner()->step($preset['id'], 30.0);
        $state = $this->services->runs()->last($preset['id']);

        $this->assertSame('completed_with_errors', $state['status']);
        $this->assertContains('not allowed to edit post #' . $private, $state['issues'][0]['message']);
        $this->untouched($private);
        $this->assertCount(1, $this->posts(), 'no replacement post was created either');
    }

    public function testDryRunReportsTheSameDenial(): void
    {
        $private = $this->existingPost(self::OTHER, 'publish');
        $preset  = $this->matchingPreset();
        wp_set_current_user(self::MANAGER);
        $this->queueJson($this->payload(['r1' => 'Hijacked']));

        $body = ['preset_id' => $preset['id'], 'ppapi' => $preset, 'ppapi_mappings' => json_encode($preset['mappings'])];
        $dry  = (new RestController($this->services))->dry_run(new WP_REST_Request([], $body))->get_data();

        $this->assertSame(1, $dry['counts']['failed']);
        $this->assertContains('not allowed to edit post #' . $private, $dry['records'][0]['message']);
    }

    public function testAuthorizedImportsStillWork(): void
    {
        $own     = $this->existingPost(self::MANAGER, 'publish', 'own');
        $other   = $this->existingPost(self::OTHER, 'publish', 'theirs');
        $preset  = $this->matchingPreset();
        wp_set_current_user(self::MANAGER);
        $this->queueJson($this->payload(['own' => 'Updated own', 'theirs' => 'Hijacked', 'new' => 'Brand new']));

        $state = $this->runPreset($preset['id']);

        $this->assertSame(1, $state['counts']['updated'], 'the manager\'s own post is updated');
        $this->assertSame(1, $state['counts']['created'], 'new posts are created');
        $this->assertSame(1, $state['counts']['failed']);
        $this->assertSame('Updated own', \WPStub::$posts[$own]['post_title']);
        $this->untouched($other, 'publish');

        // An administrator with edit_others_posts can adopt the same post.
        wp_set_current_user(1);
        $admin = $this->matchingPreset(1, ['destination' => ['author' => 1]]);
        $this->queueJson($this->payload(['theirs' => 'Adopted by admin']));
        $this->assertSame(1, $this->runPreset($admin['id'])['counts']['updated']);
        $this->assertSame('Adopted by admin', \WPStub::$posts[$other]['post_title']);
    }

    public function testAuthorChangesNeedEditOthersPosts(): void
    {
        $own    = $this->existingPost(self::MANAGER, 'publish', 'own');
        $preset = $this->matchingPreset(self::MANAGER, ['mappings' => [
            $this->row('event.title', 'core', 'post_title'),
            $this->row('owner', 'core', 'post_author'),
        ]]);
        wp_set_current_user(self::MANAGER);
        $this->queueJson(['ok' => true, 'data' => [
            ['id' => 'own', 'event' => ['title' => 'Give away'], 'owner' => self::OTHER],
            ['id' => 'new', 'event' => ['title' => 'Create for other'], 'owner' => 'other'],
        ]]);

        $state = $this->runPreset($preset['id']);

        $this->assertSame(2, $state['counts']['failed']);
        $this->assertContains('assign post #' . $own . ' to another author', $state['issues'][0]['message']);
        $this->assertContains('may not create posts for another author', $state['issues'][1]['message']);
        $this->assertSame(self::MANAGER, (int) \WPStub::$posts[$own]['post_author']);
        $this->assertCount(1, $this->posts());
    }

    public function testRestoringFromTheTrashNeedsDeletePermission(): void
    {
        wpstub_add_user(5, 'editor', ['manage_options', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'read']);
        $trashed = $this->existingPost(self::OTHER, 'publish');
        wp_trash_post($trashed);
        $preset = $this->matchingPreset(5, ['destination' => ['author' => 5]]);
        wp_set_current_user(5);
        $this->queueJson($this->payload(['r1' => 'Restored']));

        $state = $this->runPreset($preset['id']);

        $this->assertSame(1, $state['counts']['failed']);
        $this->assertContains('restore post #' . $trashed, $state['issues'][0]['message']);
        $this->untouched($trashed, 'trash');
    }

    public function testObjectLevelChecks(): void
    {
        $theirs = $this->existingPost(self::OTHER, 'draft');
        $mine   = $this->existingPost(self::MANAGER, 'draft');
        wpstub_add_user(6, 'contributor', ['edit_posts', 'read']);
        $draft = $this->existingPost(6, 'draft');

        $this->assertNotNull(Permissions::edit_problem(self::MANAGER, $theirs, false, null, null));
        $this->assertNull(Permissions::edit_problem(self::MANAGER, $mine, false, null, null));
        $this->assertNull(Permissions::edit_problem(self::MANAGER, $mine, false, self::MANAGER, 'publish'));
        $this->assertContains('to "publish"', (string) Permissions::edit_problem(6, $draft, false, null, 'publish'), 'publishing needs publish_posts');
        $this->assertNull(Permissions::edit_problem(6, $draft, false, null, 'pending'));
        $this->assertContains('restore', (string) Permissions::edit_problem(6, $draft, true, null, null), 'restoring needs delete_post');
        $this->assertContains('no longer exists', (string) Permissions::edit_problem(1, 99999, false, null, null));
        $this->assertNull(Permissions::edit_problem(1, $theirs, true, self::MANAGER, 'publish'), 'administrators may do all of it');
        $this->assertContains('"private"', (string) Permissions::create_problem(6, 'events', 6, 'private'));
        $this->assertNull(Permissions::create_problem(6, 'events', 6, 'draft'));
    }

    public function testBackgroundContinuationsActAsTheInitiatorNotTheCronVisitor(): void
    {
        $theirs = $this->existingPost(self::OTHER, 'publish', 'theirs');
        $preset = $this->matchingPreset();
        $seen   = [];
        add_action('save_post', static function () use (&$seen): void {
            $seen[] = get_current_user_id();
        });

        wp_set_current_user(self::MANAGER);
        $this->queueJson($this->payload(['new' => 'New', 'theirs' => 'Hijacked']));
        $this->assertSame('running', $this->services->runner()->start($preset['id'], 'manual')['status']);

        // WP-Cron runs inside whichever request comes next — here, a logged-in administrator's.
        wp_set_current_user(1);
        for ($i = 0; $i < 10 && $this->services->runs()->active($preset['id']) !== null; $i++) {
            do_action(ContinuationScheduler::HOOK, $preset['id']);
            $this->assertSame(1, get_current_user_id(), 'the visitor\'s user is restored after every step');
        }

        $last = $this->services->runs()->last($preset['id']);
        $this->assertSame(1, $last['counts']['created']);
        $this->assertSame(1, $last['counts']['failed'], 'the administrator\'s rights were not borrowed');
        $this->untouched($theirs, 'publish');
        $this->assertSame([self::MANAGER], array_values(array_unique($seen)), 'writes happen as the run account');
    }

    public function testRevokedOrDeletedAccountsStopTheRun(): void
    {
        $preset = $this->matchingPreset(1, ['destination' => ['author' => 1]]);
        $this->queueJson($this->payload(['a' => 'A', 'b' => 'B', 'c' => 'C']));
        $this->services->runner()->start($preset['id'], 'manual');
        $this->services->runner()->step($preset['id'], 30.0);
        $this->assertCount(1, $this->posts(), 'one record processed per batch');

        unset(\WPStub::$users[1]['caps']['edit_posts']);
        $result = $this->services->runner()->step($preset['id'], 30.0);

        $this->assertSame('failed', $result['state']['status']);
        $this->assertContains('lacks the "edit_posts" capability', $result['state']['message']);
        $this->assertCount(1, $this->posts(), 'nothing more was written');

        // Deleted account.
        wp_set_current_user(self::MANAGER);
        $second = $this->matchingPreset();
        $this->queueJson($this->payload(['d' => 'D', 'e' => 'E']));
        $this->services->runner()->start($second['id'], 'manual');
        unset(\WPStub::$users[self::MANAGER]);
        wp_set_current_user(0);
        $result = $this->services->runner()->step($second['id'], 30.0);
        $this->assertSame('failed', $result['state']['status']);
        $this->assertContains('no longer exists', $result['state']['message']);
        $this->assertSame(0, get_current_user_id());
    }

    public function testScheduledRunsActAsTheUserWhoLastSavedThePreset(): void
    {
        $theirs = $this->existingPost(self::OTHER, 'publish', 'theirs');
        $preset = $this->matchingPreset(self::MANAGER);

        wp_set_current_user(0);
        $this->queueJson($this->payload(['theirs' => 'Hijacked']));
        do_action(Scheduler::HOOK_RUN, $preset['id']);

        $last = $this->services->runs()->last($preset['id']);
        $this->assertSame('schedule', $last['trigger']);
        $this->assertSame(1, $last['counts']['failed']);
        $this->untouched($theirs, 'publish');

        // An administrator saving the preset takes over scheduled runs.
        $preset = $this->services->presets()->save($preset, 1);
        $this->queueJson($this->payload(['theirs' => 'Adopted on schedule']));
        do_action(Scheduler::HOOK_RUN, $preset['id']);
        $this->assertSame('Adopted on schedule', \WPStub::$posts[$theirs]['post_title']);
        $this->assertSame(0, get_current_user_id(), 'cron context restored');
    }

    public function testScheduledRunsWithoutAUsableAccountAreRecordedAsFailed(): void
    {
        $orphan = $this->matchingPreset(0);
        wp_set_current_user(1);
        do_action(Scheduler::HOOK_RUN, $orphan['id']);
        $this->assertContains('save it to run scheduled imports', $this->services->runs()->last($orphan['id'])['message']);

        $revoked = $this->matchingPreset(self::MANAGER);
        \WPStub::$users[self::MANAGER]['caps'] = ['read' => true];
        do_action(Scheduler::HOOK_RUN, $revoked['id']);
        $last = $this->services->runs()->last($revoked['id']);
        $this->assertSame('failed', $last['status']);
        $this->assertContains('lacks the "edit_posts" capability', $last['message']);

        $this->assertCount(0, \WPStub::$http_log, 'nothing was fetched');
    }
}
