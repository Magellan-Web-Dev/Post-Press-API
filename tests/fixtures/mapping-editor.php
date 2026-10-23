<?php
/**
 * Renders the real editor using only the in-memory WordPress stubs for DOM tests.
 * Run with PHP; output is JSON containing HTML and the localized editor data.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

/**
 * Supplies many mappings and nested containers without loading a live WordPress site.
 */
final class MappingEditorFixture extends \PostPressAPI\Tests\TestCase
{
    /**
     * Builds the editor and captures its script configuration.
     *
     * @return array{html: string, script: string} DOM test input.
     */
    public function renderFixture(): array
    {
        $this->setUp();
        $this->registerEvents();
        $rows = [];
        for ($index = 1; $index <= 30; $index++) {
            $rows[] = $this->row('sessions[*].date', 'meta', 'dates_' . $index, [
                'id' => 'row' . $index, 'transform' => 'join',
                'options' => ['output_format' => 'F j'],
            ]);
        }
        $rows[9] = $this->row('$', 'acf', 'field_website', ['id' => 'group', 'children' => [
            $this->row('sessions[*].date', 'acf', 'field_website_host', [
                'id' => 'host', 'transform' => 'join', 'options' => ['join_datetime' => true, 'output_format' => 'F j'],
            ]),
            $this->row('website.advisor', 'acf', 'field_website_advisor', ['id' => 'advisor']),
        ]]);
        $rows[10] = $this->row('sessions', 'acf', 'field_sessions', ['id' => 'repeater', 'children' => [
            $this->row('date', 'acf', 'field_session_date', ['id' => 'date', 'on_missing' => 'clear', 'on_null' => 'clear']),
            $this->row('time', 'acf', 'field_session_time', ['id' => 'time', 'on_missing' => 'clear', 'on_null' => 'clear']),
        ]]);
        $preset = $this->savePreset(['mappings' => $rows]);
        ob_start();
        (new \PostPressAPI\Admin\Views\EditorView($this->services))->render($preset, false, [], [], '');
        $html = (string) ob_get_clean();
        $script = \WPStub::$inline_scripts['ppapi-admin'];
        $this->tearDown();
        return ['html' => $html, 'script' => $script];
    }
}

echo wp_json_encode((new MappingEditorFixture())->renderFixture());
