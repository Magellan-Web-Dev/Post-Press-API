<?php
/**
 * WP-Cron scheduling: one event per preset, rescheduling, cleanup, and lifecycle.
 */

declare(strict_types=1);

use PostPressAPI\Admin\PresetEditor;
use PostPressAPI\Presets\PresetSchema;
use PostPressAPI\Scheduling\Scheduler;
use PostPressAPI\Tests\TestCase;

final class SchedulerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents();
    }

    private function scheduled(array $overrides = []): array
    {
        $preset = $this->savePreset(array_replace_recursive(['schedule' => ['frequency' => 'hourly']], $overrides));
        $this->services->scheduler()->sync($preset);
        return $preset;
    }

    public function testNewPresetsStartDisabledAndManualOnly(): void
    {
        $defaults = PresetSchema::defaults();
        $this->assertFalse($defaults['enabled']);
        $this->assertSame('manual', $defaults['schedule']['frequency']);
        $this->assertSame('update_changed', $defaults['sync']['policy']);
        $this->assertSame('draft', $defaults['destination']['status']);
    }

    public function testEnabledScheduledPresetGetsExactlyOneEvent(): void
    {
        $preset = $this->scheduled();
        $this->services->scheduler()->sync($preset);
        $this->services->scheduler()->sync($preset);

        $this->assertSame(1, wpstub_count_events(Scheduler::HOOK_RUN, [$preset['id']]));
        $event = wp_get_scheduled_event(Scheduler::HOOK_RUN, [$preset['id']]);
        $this->assertSame('hourly', $event->schedule);
        $this->assertSame([$preset['id']], $event->args, 'stable preset argument');
    }

    public function testChangingTheScheduleReschedules(): void
    {
        $preset = $this->scheduled();
        $preset['schedule']['frequency'] = 'every_15_minutes';
        $preset = $this->services->presets()->save($preset, 1);
        $this->services->scheduler()->sync($preset);

        $this->assertSame(1, wpstub_count_events(Scheduler::HOOK_RUN, [$preset['id']]));
        $this->assertSame('ppapi_15min', wp_get_scheduled_event(Scheduler::HOOK_RUN, [$preset['id']])->schedule);
    }

    public function testDuplicateEventsAreCollapsed(): void
    {
        $preset = $this->scheduled();
        wp_schedule_event(time() + 999, 'hourly', Scheduler::HOOK_RUN, [$preset['id']]);
        $this->assertSame(2, wpstub_count_events(Scheduler::HOOK_RUN, [$preset['id']]));

        $this->services->scheduler()->sync($preset);
        $this->assertSame(1, wpstub_count_events(Scheduler::HOOK_RUN, [$preset['id']]));
    }

    public function testCustomIntervalsAreRegisteredWithAMinimum(): void
    {
        $preset = $this->scheduled(['schedule' => ['frequency' => 'custom', 'minutes' => 7]]);

        $this->assertSame('ppapi_7min', wp_get_scheduled_event(Scheduler::HOOK_RUN, [$preset['id']])->schedule);
        $this->assertSame(420, wp_get_schedules()['ppapi_7min']['interval']);
        $this->assertSame(5, PresetSchema::bounded(2, 'custom_minutes'), 'stored intervals are clamped to five minutes');

        $result = $this->services->preset_validator()->validate(['name' => 'x', 'schedule' => ['frequency' => 'custom', 'minutes' => '2']], null, ['action' => 'keep', 'value' => '']);
        $this->assertArrayHasKey('schedule.minutes', $result['errors'], 'the editor rejects intervals under five minutes');
    }

    public function testDisablingClearsScheduledAndContinuationEvents(): void
    {
        $preset = $this->scheduled();
        $this->queueJson($this->fixture());
        $this->services->runner()->start($preset['id'], 'manual');
        $this->assertSame(1, wpstub_count_events('ppapi_continue_run', [$preset['id']]));

        $result = (new PresetEditor($this->services))->set_enabled($preset['id'], false);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, wpstub_count_events(Scheduler::HOOK_RUN, [$preset['id']]));
        $this->assertSame(0, wpstub_count_events('ppapi_continue_run', [$preset['id']]));
        $this->assertSame('cancelled', $this->services->runs()->last($preset['id'])['status']);
    }

    public function testManualOnlyPresetHasNoEvent(): void
    {
        $preset = $this->scheduled(['schedule' => ['frequency' => 'manual']]);
        $this->assertSame(0, wpstub_count_events(Scheduler::HOOK_RUN, [$preset['id']]));
    }

    public function testDeletingAPresetClearsEventsAndKeepsPosts(): void
    {
        $preset = $this->scheduled();
        $this->queueJson($this->fixture());
        $this->runPreset($preset['id']);
        $this->services->secrets()->set($preset['id'], 'token', 'https://api.example.com:443');

        $this->assertTrue((new PresetEditor($this->services))->delete($preset['id']));

        $this->assertSame(0, wpstub_count_events(Scheduler::HOOK_RUN));
        $this->assertNull($this->services->presets()->find($preset['id']));
        $this->assertNull($this->services->secrets()->get($preset['id']));
        $this->assertSame([], $this->services->runs()->history($preset['id']));
        $this->assertCount(1, $this->posts(), 'imported posts are retained');
    }

    public function testDeactivationClearsAllEventsAndActivationRestoresEnabledOnes(): void
    {
        $enabled  = $this->scheduled();
        $disabled = $this->scheduled(['enabled' => false]);
        $manual   = $this->scheduled(['schedule' => ['frequency' => 'manual']]);
        wp_schedule_single_event(time() + 60, 'ppapi_continue_run', [$enabled['id']]);

        \PostPressAPI\Lifecycle::deactivate();
        $this->assertSame(0, wpstub_count_events(Scheduler::HOOK_RUN));
        $this->assertSame(0, wpstub_count_events('ppapi_continue_run'));
        $this->assertNotNull($this->services->presets()->find($enabled['id']), 'settings retained');

        \PostPressAPI\Lifecycle::activate();
        $this->assertSame(1, wpstub_count_events(Scheduler::HOOK_RUN, [$enabled['id']]));
        $this->assertSame(0, wpstub_count_events(Scheduler::HOOK_RUN, [$disabled['id']]));
        $this->assertSame(0, wpstub_count_events(Scheduler::HOOK_RUN, [$manual['id']]));
    }

    public function testScheduledEventRunsTheSamePipeline(): void
    {
        $preset = $this->scheduled();
        $this->queueJson($this->fixture());

        do_action(Scheduler::HOOK_RUN, $preset['id']);

        $last = $this->services->runs()->last($preset['id']);
        $this->assertSame('completed', $last['status']);
        $this->assertSame('schedule', $last['trigger']);
        $this->assertCount(1, $this->posts());
        $this->assertSame(0, wpstub_count_events('ppapi_continue_run'));
    }

    public function testScheduledRunSkipsWhileAPreviousRunIsActive(): void
    {
        $preset = $this->scheduled();
        $this->queueJson($this->fixture());
        $this->services->runner()->start($preset['id'], 'manual');

        $this->services->scheduler()->handle_scheduled_run($preset['id']);

        $this->assertCount(1, \WPStub::$http_queue, 'no second run started');
        $this->assertContains('still in progress', \WPStub::$options['ppapi_log']['value'][0]['message']);
    }

    public function testScheduledRunOfIncompletePresetIsRecordedAsFailed(): void
    {
        $preset = $this->scheduled(['response' => ['records_path' => '']]);

        $this->services->scheduler()->handle_scheduled_run($preset['id']);

        $last = $this->services->runs()->last($preset['id']);
        $this->assertSame('failed', $last['status']);
        $this->assertContains('not ready', $last['message']);
        $this->assertCount(0, \WPStub::$http_log);
    }

    public function testOrphanedEventsAreRemoved(): void
    {
        wp_schedule_event(time() + 60, 'hourly', Scheduler::HOOK_RUN, ['abcdefabcdef']);

        $this->services->scheduler()->handle_scheduled_run('abcdefabcdef');

        $this->assertSame(0, wpstub_count_events(Scheduler::HOOK_RUN));
    }

    public function testContinuationEventFinishesARunWithoutTheBrowser(): void
    {
        $preset = $this->savePreset(['batch_size' => 1]);
        $payload = $this->fixture();
        $second  = $payload['data'][0];
        $second['id'] = 'second';
        $payload['data'][] = $second;
        $this->queueJson($payload);

        $this->services->runner()->start($preset['id'], 'manual');
        $this->assertSame(1, wpstub_count_events('ppapi_continue_run', [$preset['id']]));

        for ($i = 0; $i < 5 && $this->services->runs()->active($preset['id']) !== null; $i++) {
            do_action('ppapi_continue_run', $preset['id']);
        }

        $this->assertNull($this->services->runs()->active($preset['id']));
        $this->assertSame(2, $this->services->runs()->last($preset['id'])['counts']['created']);
    }
}
