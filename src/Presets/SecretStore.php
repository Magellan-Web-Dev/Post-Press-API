<?php

declare(strict_types=1);

namespace PostPressAPI\Presets;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Support\Redactor;

/**
 * Server-side credential storage, one non-autoloaded option per preset.
 *
 * Credentials are never sent to the browser, included in REST responses,
 * previews, run history, or logs. Each credential remembers the endpoint origin
 * (scheme, host, port) it was entered for; it is only ever sent to that origin,
 * so changing a preset's endpoint host requires re-entering the credential.
 *
 * Values are stored in the database as entered (WordPress has no built-in
 * secret encryption); restrict database access and prefer narrowly scoped keys.
 */
final class SecretStore
{
    public const PREFIX = 'ppapi_secret_';

    /**
     * Loads a credential.
     *
     * @param string $id Preset ID.
     *
     * @return array{value: string, origin: string}|null
     */
    public function get(string $id): ?array
    {
        if (!PresetRepository::is_valid_id($id)) {
            return null;
        }

        $stored = get_option(self::PREFIX . $id, null);
        if (!is_array($stored) || !isset($stored['value']) || !is_string($stored['value']) || $stored['value'] === '') {
            return null;
        }

        return ['value' => $stored['value'], 'origin' => (string) ($stored['origin'] ?? '')];
    }

    /**
     * Whether a credential is stored.
     *
     * @param string $id Preset ID.
     *
     * @return bool
     */
    public function has(string $id): bool
    {
        return $this->get($id) !== null;
    }

    /**
     * Returns the credential only when it was entered for the given origin.
     *
     * @param string      $id     Preset ID.
     * @param string|null $origin Endpoint origin.
     *
     * @return string|null
     */
    public function value_for_origin(string $id, ?string $origin): ?string
    {
        $secret = $this->get($id);
        if ($secret === null || $origin === null || !hash_equals($secret['origin'], $origin)) {
            return null;
        }

        return $secret['value'];
    }

    /**
     * A redactor that masks the saved credentials of the given presets.
     *
     * The saved value is masked regardless of origin: an API may echo it back
     * even when it was not sent on this request.
     *
     * @param list<string> $ids Preset IDs.
     *
     * @return Redactor
     */
    public function redactor(array $ids): Redactor
    {
        $redactor = new Redactor();
        foreach ($ids as $id) {
            $redactor->add_secret($this->get((string) $id)['value'] ?? null);
        }

        return $redactor;
    }

    /**
     * Stores a credential.
     *
     * @param string $id     Preset ID.
     * @param string $value  Credential.
     * @param string $origin Endpoint origin it may be sent to.
     *
     * @return void
     */
    public function set(string $id, string $value, string $origin): void
    {
        if (!PresetRepository::is_valid_id($id) || $value === '') {
            return;
        }

        update_option(self::PREFIX . $id, ['value' => $value, 'origin' => $origin, 'updated_at' => time()], false);
    }

    /**
     * Removes a credential.
     *
     * @param string $id Preset ID.
     *
     * @return void
     */
    public function delete(string $id): void
    {
        if (PresetRepository::is_valid_id($id)) {
            delete_option(self::PREFIX . $id);
        }
    }

    /**
     * Copies a credential server-side (used when duplicating a preset).
     *
     * @param string $from Source preset ID.
     * @param string $to   Target preset ID.
     *
     * @return void
     */
    public function copy(string $from, string $to): void
    {
        $secret = $this->get($from);
        if ($secret !== null) {
            $this->set($to, $secret['value'], $secret['origin']);
        }
    }
}
