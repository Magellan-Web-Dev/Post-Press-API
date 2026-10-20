<?php

declare(strict_types=1);

namespace PostPressAPI\Sync;

use PostPressAPI\Mapping\PathResolver;
use PostPressAPI\Mapping\PathSyntaxError;
use PostPressAPI\Mapping\ValueInspector;
use PostPressAPI\Presets\PresetSchema;

if (!defined('ABSPATH')) exit;

/**
 * Determines a record's identity, separately from its content.
 *
 * Identity = preset ID + destination post type + external record ID. The
 * external ID is kept as a string exactly as the API sent it (integers are
 * converted without rounding; big integers are already strings because the
 * response is decoded with JSON_BIGINT_AS_STRING). Titles are never used.
 *
 * Composite identities combine several source values. Changing any of those
 * values in the API changes the identity, so the record is treated as new.
 * Missing, null, empty, boolean, or array values never produce an identity.
 */
final class IdentityResolver
{
    public function __construct(private readonly PathResolver $paths)
    {
    }

    /**
     * Resolves the identity of a record.
     *
     * @param mixed                $record Record.
     * @param array<string, mixed> $preset Preset (id, destination.post_type, response.identity).
     *
     * @return array{ok: bool, external_id: string, display: string, key: string, error: string}
     */
    public function resolve(mixed $record, array $preset): array
    {
        $identity = $preset['response']['identity'];
        $paths    = $identity['mode'] === PresetSchema::IDENTITY_COMPOSITE ? $identity['paths'] : [$identity['path']];
        $paths    = array_values(array_filter($paths, static fn ($path): bool => $path !== ''));

        if ($paths === []) {
            return $this->failure(__('No external ID path is configured.', 'post-press-api'));
        }

        $values = [];
        foreach ($paths as $path) {
            try {
                $result = $this->paths->resolve($record, (string) $path);
            } catch (PathSyntaxError $e) {
                return $this->failure($e->getMessage());
            }

            if (!$result->is_present() || $result->multiple) {
                return $this->failure(sprintf(
                    /* translators: 1: path, 2: state. */
                    __('The external ID at "%1$s" is %2$s, so the record was skipped instead of being imported without an identity.', 'post-press-api'),
                    $path,
                    $result->is_null() ? __('null', 'post-press-api') : ($result->multiple ? __('a list', 'post-press-api') : __('missing', 'post-press-api'))
                ));
            }

            $value = self::id_string($result->value);
            if ($value === null) {
                return $this->failure(sprintf(
                    /* translators: 1: path, 2: value type. */
                    __('The external ID at "%1$s" is %2$s; it must be non-empty text or a number.', 'post-press-api'),
                    $path,
                    ValueInspector::type_label($result->value)
                ));
            }

            $values[] = $value;
        }

        if ($identity['mode'] === PresetSchema::IDENTITY_COMPOSITE) {
            $external_id = (string) wp_json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $display     = implode(' | ', $values);
        } else {
            $external_id = $values[0];
            $display     = $values[0];
        }

        return [
            'ok'          => true,
            'external_id' => $external_id,
            'display'     => $display,
            'key'         => self::key((string) $preset['id'], (string) $preset['destination']['post_type'], $external_id),
            'error'       => '',
        ];
    }

    /**
     * Identity key stored on posts and used for lookups.
     *
     * @param string $preset_id   Preset ID.
     * @param string $post_type   Destination post type.
     * @param string $external_id External ID string.
     *
     * @return string
     */
    public static function key(string $preset_id, string $post_type, string $external_id): string
    {
        return hash('sha256', $preset_id . "\x1F" . $post_type . "\x1F" . $external_id);
    }

    /**
     * Converts an ID value to a string without lossy conversion.
     *
     * @param mixed $value Value.
     *
     * @return string|null
     */
    public static function id_string(mixed $value): ?string
    {
        if (is_string($value)) {
            return trim($value) === '' ? null : $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value) && is_finite($value)) {
            if (floor($value) === $value && abs($value) < 9007199254740992.0) {
                return sprintf('%.0f', $value);
            }
            $encoded = wp_json_encode($value);
            return is_string($encoded) ? $encoded : null;
        }

        return null;
    }

    /**
     * @param string $message Error.
     *
     * @return array{ok: bool, external_id: string, display: string, key: string, error: string}
     */
    private function failure(string $message): array
    {
        return ['ok' => false, 'external_id' => '', 'display' => '', 'key' => '', 'error' => $message];
    }
}
