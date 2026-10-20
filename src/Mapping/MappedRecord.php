<?php

declare(strict_types=1);

namespace PostPressAPI\Mapping;

if (!defined('ABSPATH')) exit;

/**
 * Result of evaluating all mapping rows for one record.
 */
final class MappedRecord
{
    /** @var list<FieldWrite> */
    private array $writes = [];

    /** @var list<string> */
    private array $errors = [];

    /**
     * Per-row preview details (only collected when requested).
     *
     * @var list<array<string, mixed>>
     */
    private array $details = [];

    public function add_write(FieldWrite $write): void
    {
        $this->writes[] = $write;
    }

    public function add_error(string $message): void
    {
        $this->errors[] = $message;
    }

    /**
     * @param array<string, mixed> $detail Preview detail.
     */
    public function add_detail(array $detail): void
    {
        $this->details[] = $detail;
    }

    /**
     * @return list<FieldWrite>
     */
    public function writes(): array
    {
        return $this->writes;
    }

    /**
     * Writes for one destination category.
     *
     * @param string $category core, meta, or acf.
     *
     * @return list<FieldWrite>
     */
    public function writes_for(string $category): array
    {
        return array_values(array_filter($this->writes, static fn (FieldWrite $write): bool => $write->destination->category === $category));
    }

    /**
     * Core field values to set, keyed by field name.
     *
     * @return array<string, mixed>
     */
    public function core_values(): array
    {
        $values = [];
        foreach ($this->writes_for(Destination::CORE) as $write) {
            if ($write->op === FieldWrite::SET) {
                $values[$write->destination->key] = $write->value;
            } else {
                $values[$write->destination->key] = '';
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function has_errors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * Deterministic description of every write, for fingerprinting.
     *
     * Preserved (unwritten) fields are absent, so a field that later appears in
     * the source changes the fingerprint and triggers an update.
     *
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    public function fingerprint_payload(): array
    {
        $payload = [];
        foreach ($this->writes as $write) {
            $payload[] = [$write->destination->id(), $write->op, $write->op === FieldWrite::SET ? $write->value : null];
        }

        usort($payload, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return $payload;
    }
}
