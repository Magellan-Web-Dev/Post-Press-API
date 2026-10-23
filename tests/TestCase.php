<?php
/**
 * Base class for Post Press API tests (dependency-free; see tests/run.php).
 */

declare(strict_types=1);

namespace PostPressAPI\Tests;

final class AssertionFailed extends \RuntimeException
{
}

abstract class TestCase
{
    protected TestClock $clock;
    protected ?\PostPressAPI\Services $services = null;

    public function setUp(): void
    {
        \MediaStub::reset();
        \WPStub::reset();
        if (class_exists('AcfStub')) {
            \AcfStub::reset();
        }
        $GLOBALS['wpdb'] = new \FakeWpdb();

        wpstub_add_user(1, 'admin', wpstub_admin_caps());
        wp_set_current_user(1);

        if (class_exists(\PostPressAPI\Services::class)) {
            require_once __DIR__ . '/TestClock.php';
            $this->clock    = new TestClock(1790000000);
            $this->services = new \PostPressAPI\Services($this->clock);
            $this->services->scheduler()->register();
        }
    }

    public function tearDown(): void
    {
        \MediaStub::reset();
    }

    /* Assertions -------------------------------------------------------- */

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $this->fail(($message !== '' ? $message . ': ' : '') . 'expected ' . $this->export($expected) . ', got ' . $this->export($actual));
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            $this->fail(($message !== '' ? $message . ': ' : '') . 'expected ' . $this->export($expected) . ', got ' . $this->export($actual));
        }
    }

    protected function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        if ($unexpected === $actual) {
            $this->fail(($message !== '' ? $message . ': ' : '') . 'did not expect ' . $this->export($actual));
        }
    }

    protected function assertTrue(mixed $value, string $message = ''): void
    {
        $this->assertSame(true, $value, $message);
    }

    protected function assertFalse(mixed $value, string $message = ''): void
    {
        $this->assertSame(false, $value, $message);
    }

    protected function assertNull(mixed $value, string $message = ''): void
    {
        $this->assertSame(null, $value, $message);
    }

    protected function assertNotNull(mixed $value, string $message = ''): void
    {
        if ($value === null) {
            $this->fail($message !== '' ? $message : 'expected a non-null value');
        }
    }

    protected function assertCount(int $expected, mixed $countable, string $message = ''): void
    {
        $this->assertSame($expected, count($countable), $message !== '' ? $message : 'count');
    }

    protected function assertContains(mixed $needle, mixed $haystack, string $message = ''): void
    {
        $found = is_string($haystack) ? str_contains($haystack, (string) $needle) : in_array($needle, (array) $haystack, true);
        if (!$found) {
            $this->fail(($message !== '' ? $message . ': ' : '') . $this->export($needle) . ' not found in ' . $this->export($haystack));
        }
    }

    protected function assertNotContains(mixed $needle, mixed $haystack, string $message = ''): void
    {
        $found = is_string($haystack) ? str_contains($haystack, (string) $needle) : in_array($needle, (array) $haystack, true);
        if ($found) {
            $this->fail(($message !== '' ? $message . ': ' : '') . $this->export($needle) . ' unexpectedly found');
        }
    }

    protected function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        if (!array_key_exists($key, $array)) {
            $this->fail(($message !== '' ? $message . ': ' : '') . 'missing key ' . $this->export($key));
        }
    }

    protected function assertInstanceOf(string $class, mixed $value, string $message = ''): void
    {
        if (!$value instanceof $class) {
            $this->fail(($message !== '' ? $message . ': ' : '') . 'expected instance of ' . $class . ', got ' . get_debug_type($value));
        }
    }

    /**
     * Asserts that $callback throws $class (optionally with a message fragment).
     */
    protected function assertThrows(string $class, callable $callback, ?string $fragment = null): \Throwable
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                $this->fail('expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
            }
            if ($fragment !== null && !str_contains($e->getMessage(), $fragment)) {
                $this->fail('exception message "' . $e->getMessage() . '" does not contain "' . $fragment . '"');
            }
            return $e;
        }
        $this->fail('expected ' . $class . ' to be thrown');
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    private function export(mixed $value): string
    {
        $text = var_export($value, true);
        return strlen($text) > 600 ? substr($text, 0, 600) . '…' : $text;
    }

    /* Fixtures and helpers --------------------------------------------- */

    protected function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/sample.json'), true, 512, JSON_BIGINT_AS_STRING);
    }

    protected function queueJson(mixed $data, int $code = 200, array $headers = []): void
    {
        \WPStub::$http_queue[] = \WPStub::json($data, $code, $headers);
    }

    protected function queueResponse(int $code, string $body, array $headers = [], string $message = 'OK'): void
    {
        \WPStub::$http_queue[] = \WPStub::response($code, $body, $headers, $message);
    }

    /**
     * Registers an "events" post type and an ACF field group like a real site would.
     * Field keys come from this "installation"; the plugin never invents them.
     */
    protected function registerEvents(bool $with_acf = true): void
    {
        register_post_type('events', ['label' => 'Events', 'show_ui' => true]);

        if (!$with_acf || !class_exists('AcfStub')) {
            return;
        }

        \AcfStub::add_group('group_events', 'Event details', ['events'], [
            ['key' => 'field_virtual', 'name' => 'is_virtual', 'label' => 'Virtual', 'type' => 'true_false'],
            ['key' => 'field_meeting', 'name' => 'meeting_url', 'label' => 'Meeting URL', 'type' => 'url'],
            ['key' => 'field_email', 'name' => 'contact_email', 'label' => 'Contact email', 'type' => 'email'],
            ['key' => 'field_first_date', 'name' => 'first_date', 'label' => 'First date', 'type' => 'date_picker'],
            ['key' => 'field_city', 'name' => 'location_city', 'label' => 'City', 'type' => 'text'],
            ['key' => 'field_image', 'name' => 'event_image', 'label' => 'Image', 'type' => 'image'],
            ['key' => 'field_sessions', 'name' => 'sessions', 'label' => 'Sessions', 'type' => 'repeater', 'sub_fields' => [
                ['key' => 'field_session_date', 'name' => 'session_date', 'label' => 'Date', 'type' => 'date_picker'],
                ['key' => 'field_session_time', 'name' => 'session_time', 'label' => 'Time', 'type' => 'time_picker'],
            ]],
            ['key' => 'field_website', 'name' => 'website', 'label' => 'Website', 'type' => 'group', 'sub_fields' => [
                ['key' => 'field_website_host', 'name' => 'host', 'label' => 'Host', 'type' => 'text'],
                ['key' => 'field_website_advisor', 'name' => 'advisor', 'label' => 'Advisor', 'type' => 'text'],
            ]],
            ['key' => 'field_mode', 'name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'choices' => ['virtual' => 'Virtual', 'in_person' => 'In person']],
            ['key' => 'field_external_id', 'name' => 'legacy_id', 'label' => 'Legacy ID', 'type' => 'text'],
        ]);
    }

    /**
     * A mapping row with defaults.
     *
     * @param array<string, mixed> $row Overrides.
     */
    protected function row(string $source, string $target_type, string $target, array $row = []): array
    {
        return $row + [
            'source_type' => 'path',
            'source'      => $source,
            'target_type' => $target_type,
            'target'      => $target,
            'transform'   => 'none',
            'on_missing'  => 'preserve',
            'on_null'     => 'preserve',
        ];
    }

    /**
     * The README example mapping for the sample payload.
     *
     * @return list<array<string, mixed>>
     */
    protected function exampleMappings(): array
    {
        return [
            $this->row('event.title', 'core', 'post_title'),
            $this->row('event.description', 'core', 'post_content'),
            $this->row('event.virtual', 'acf', 'field_virtual'),
            $this->row('event.virtual_meeting_url', 'acf', 'field_meeting'),
            $this->row('submitter.email', 'acf', 'field_email'),
            $this->row('sessions[0].date', 'acf', 'field_first_date'),
            $this->row('sessions', 'acf', 'field_sessions', ['children' => [
                $this->row('date', 'acf', 'field_session_date', ['on_missing' => 'clear', 'on_null' => 'clear']),
                $this->row('time', 'acf', 'field_session_time', ['on_missing' => 'clear', 'on_null' => 'clear']),
            ]]),
            $this->row('dates_text[*]', 'meta', 'event_dates_text', ['transform' => 'join', 'options' => ['separator' => '\\n']]),
            $this->row('location.city', 'acf', 'field_city'),
            $this->row('image', 'meta', 'event_image_url'),
        ];
    }

    /**
     * Saves a preset (via the repository, bypassing the form) and returns it.
     *
     * @param array<string, mixed> $overrides Deep-merged into the default sample preset.
     */
    protected function savePreset(array $overrides = []): array
    {
        $preset = array_replace_recursive([
            'name'        => 'Events feed',
            'enabled'     => true,
            'request'     => ['url' => 'https://api.example.com/events', 'method' => 'GET', 'retries' => 2],
            'response'    => ['records_path' => 'data', 'success_path' => 'ok', 'success_value' => 'true', 'identity' => ['mode' => 'path', 'path' => 'id']],
            'destination' => ['post_type' => 'events', 'status' => 'publish', 'author' => 1],
            'mappings'    => $this->exampleMappings(),
            'sync'        => ['policy' => 'update_changed'],
        ], $overrides);

        if (array_key_exists('mappings', $overrides)) {
            $preset['mappings'] = $overrides['mappings'];
        }

        $repository   = $this->services->presets();
        $preset['id'] = $preset['id'] ?? $repository->create_id();

        return $repository->save($preset, 1);
    }

    /**
     * Starts a run and steps it until it finishes.
     *
     * @return array<string, mixed> Final run state.
     */
    protected function runPreset(string $preset_id, bool $force = false, int $max_steps = 100): array
    {
        $state = $this->services->runner()->start($preset_id, 'manual', $force);
        if ($state instanceof \WP_Error) {
            $this->fail('run did not start: ' . $state->get_error_message());
        }

        for ($i = 0; $i < $max_steps; $i++) {
            $result = $this->services->runner()->step($preset_id, 30.0);
            $state  = $result['state'];
            if ($state === null || !in_array($state['status'], ['running', 'waiting'], true)) {
                return $state ?? [];
            }
            if ($state['status'] === 'waiting') {
                $this->clock->advance(max(1, (int) $state['resume_at'] - $this->clock->now()));
            }
        }

        $this->fail('run did not finish within ' . $max_steps . ' steps');
    }

    /** @return list<array<string, mixed>> */
    protected function posts(string $type = 'events'): array
    {
        return \WPStub::posts_of_type($type);
    }

    protected function meta(int $post_id, string $key): mixed
    {
        return get_post_meta($post_id, $key, true);
    }
}
