<?php
/** History-only mutations, concurrent append safety, and protected admin controls. */

declare(strict_types=1);

use PostPressAPI\REST\RestController;
use PostPressAPI\Sync\RunRepository;
use PostPressAPI\Tests\TestCase;

final class HistoryTest extends TestCase
{
    /** Adds a representative completed summary and returns its stable UUID. */
    private function entry(string $preset_id): string
    {
        $id = wp_generate_uuid4();
        $this->services->runs()->add_history($preset_id, ['run_id' => $id, 'preset_id' => $preset_id, 'status' => 'completed', 'started_at' => 123, 'finished_at' => 124, 'counts' => []]);
        return $id;
    }

    public function testSelectedDeletionLeavesActiveStateAndOtherPresetsUntouched(): void
    {
        $this->registerEvents();
        $preset = $this->savePreset();
        $other = $this->savePreset();
        $first = $this->entry($preset['id']);
        $second = $this->entry($preset['id']);
        $foreign = $this->entry($other['id']);
        $this->services->runs()->save_active(['preset_id' => $preset['id'], 'status' => 'running']);
        $this->services->runs()->save_buffer($preset['id'], [['id' => 123]]);
        $this->services->runs()->request_cancel($preset['id'], 'keep me');
        $token = $this->services->lock()->acquire($preset['id']);
        $this->assertSame(1, $this->services->runs()->delete_history($preset['id'], [$first, $foreign]));
        $this->assertSame($second, $this->services->runs()->last($preset['id'])['run_id']);
        $this->assertSame($foreign, $this->services->runs()->last($other['id'])['run_id']);
        $this->assertSame('running', $this->services->runs()->active($preset['id'])['status']);
        $this->assertSame([['id' => 123]], $this->services->runs()->buffer($preset['id']));
        $this->assertSame('keep me', $this->services->runs()->cancel_reason($preset['id']));
        $this->assertTrue($this->services->lock()->is_locked($preset['id']));
        $this->assertSame(0, $this->services->runs()->delete_history($preset['id'], [$first]));
        $this->assertSame(1, $this->services->runs()->clear_history($preset['id']));
        $this->assertNull($this->services->runs()->last($preset['id']));
        $this->assertTrue($this->services->lock()->is_locked($preset['id']));
        $this->services->lock()->release($preset['id'], $token);
    }

    public function testConcurrentAppendSurvivesClearAndHistoryDoesNotResurrect(): void
    {
        $id = 'a12345678901';
        $old = $this->entry($id);
        $new = '';
        $GLOBALS['wpdb']->before_update = function () use ($id, &$new): void { $new = $this->entry($id); };
        $this->assertSame(1, $this->services->runs()->clear_history($id));
        $this->assertSame([$new], array_column($this->services->runs()->history($id), 'run_id'));
        $this->assertNotContains($old, array_column($this->services->runs()->history($id), 'run_id'));
        for ($i = 0; $i < 28; $i++) { $this->entry($id); }
        $this->assertCount(RunRepository::HISTORY_LIMIT, $this->services->runs()->history($id));
    }

    public function testRestDeletesAllBeyondFiveDisplayedRowsAndRequiresAuthorization(): void
    {
        $this->registerEvents();
        $preset = $this->savePreset();
        for ($i = 0; $i < 9; $i++) { $this->entry($preset['id']); }
        $rest = new RestController($this->services);
        $params = ['id' => $preset['id'], 'clear_all' => true, 'limit' => 5];
        $this->assertInstanceOf(WP_Error::class, $rest->delete_history(new WP_REST_Request($params)));
        $params['history_nonce'] = wp_create_nonce('ppapi_history_' . $preset['id']);
        $result = $rest->delete_history(new WP_REST_Request($params))->get_data();
        $this->assertSame(9, $result['deleted']);
        $this->assertContains('No runs yet', $result['html']);
        wpstub_add_user(2, 'subscriber', ['read']);
        wp_set_current_user(2);
        $this->assertInstanceOf(WP_Error::class, $rest->delete_history(new WP_REST_Request($params)));
    }

    public function testTableSupportsSelectionWithoutNestedForms(): void
    {
        $id = 'a12345678901';
        for ($i = 0; $i < 7; $i++) { $this->entry($id); }
        ob_start();
        \PostPressAPI\Admin\Views\HistoryView::table($this->services->runs()->history($id), 5, $id);
        $html = ob_get_clean();
        $this->assertSame(5, substr_count($html, 'data-history-id='));
        $this->assertContains('7 runs', $html);
        $this->assertContains('data-history-select-all', $html);
        $this->assertNotContains('<form', $html);
        $this->assertNotContains('type="submit"', $html);
    }
}
