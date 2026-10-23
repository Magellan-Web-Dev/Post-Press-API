<?php
/**
 * @ppapi-no-acf
 *
 * Without Advanced Custom Fields: core-field and meta presets work, ACF presets
 * fail with a clear error, and other presets are unaffected.
 */

declare(strict_types=1);

use PostPressAPI\Mapping\MappingSchema;
use PostPressAPI\Tests\TestCase;

final class NoAcfTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->registerEvents(false);
    }

    private function coreMappings(): array
    {
        return [
            $this->row('event.title', 'core', 'post_title'),
            $this->row('event.description', 'core', 'post_content'),
            $this->row('event.virtual_meeting_url', 'meta', 'meeting_url', ['transform' => 'url']),
            $this->row('sessions[0].date', 'meta', 'first_date', ['transform' => 'datetime', 'options' => ['output_format' => 'Y-m-d']]),
            $this->row('dates_text[*]', 'meta', 'dates_text', ['transform' => 'join', 'options' => ['separator' => '\\n']]),
        ];
    }

    public function testAcfFunctionsAreReallyAbsent(): void
    {
        $this->assertFalse(function_exists('update_field'));
        $this->assertFalse($this->services->acf_fields()->available());
        $this->assertFalse($this->services->destinations()->catalog('events')->to_editor_array()['acf']['available']);
    }

    public function testCoreAndMetaPresetImportsWithoutAcf(): void
    {
        $preset = $this->savePreset(['mappings' => $this->coreMappings()]);
        $this->queueJson($this->fixture());

        $state = $this->runPreset($preset['id']);

        $this->assertSame('completed', $state['status'], (string) $state['message']);
        $post = $this->posts()[0];
        $this->assertSame('Medicare Open Enrollment Q&A (Virtual)', $post['post_title']);
        $this->assertSame('https://zoom.us/j/98765432100', $this->meta((int) $post['ID'], 'meeting_url'));
        $this->assertSame('2026-11-05', $this->meta((int) $post['ID'], 'first_date'));
        $this->assertSame('Nov 5, 2026 at 12:00 PM Eastern', $this->meta((int) $post['ID'], 'dates_text'));

        $this->queueJson($this->fixture());
        $this->assertSame(1, $this->runPreset($preset['id'])['counts']['unchanged']);
    }

    public function testAcfPresetFailsClearlyWhileOtherPresetsStillRun(): void
    {
        $acf  = $this->savePreset(['mappings' => [$this->row('event.virtual', 'acf', 'field_virtual')]]);
        $core = $this->savePreset(['mappings' => $this->coreMappings()]);

        $this->queueJson($this->fixture());
        $failed = $this->runPreset($acf['id']);
        $this->assertSame('failed', $failed['status']);
        $this->assertContains('Advanced Custom Fields is not active', $failed['message']);
        $this->assertCount(0, $this->posts());

        $this->queueJson($this->fixture());
        $this->assertSame('completed', $this->runPreset($core['id'])['status']);
        $this->assertCount(1, $this->posts());
    }

    public function testValidatorKeepsAcfRowsWithAWarning(): void
    {
        $rows   = MappingSchema::normalize_rows([$this->row('event.virtual', 'acf', 'field_virtual')]);
        $result = $this->services->mapping_validator()->validate($rows, $this->services->destinations()->catalog('events'));

        $this->assertSame([], $result['errors']);
        $this->assertContains('not active', implode(' ', $result['warnings']));
    }
}
