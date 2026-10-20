<?php

declare(strict_types=1);

namespace PostPressAPI\Sync;

if (!defined('ABSPATH')) exit;

/**
 * What happened (or, in a dry run, would happen) to one record.
 */
final class RecordOutcome
{
    public const CREATED   = 'created';
    public const UPDATED   = 'updated';
    public const UNCHANGED = 'unchanged';
    public const SKIPPED   = 'skipped';
    public const FAILED    = 'failed';

    /** @var list<string> Outcome actions, in display order. */
    public const ACTIONS = [self::CREATED, self::UPDATED, self::UNCHANGED, self::SKIPPED, self::FAILED];

    /**
     * @param string                     $action      One of the action constants.
     * @param int                        $position    1-based position of the record in the run.
     * @param string                     $external_id External ID display string ('' when unknown).
     * @param int|null                   $post_id     Destination post ID, when known.
     * @param string                     $message     Explanation (errors, skip reasons, dry-run notes).
     * @param list<array<string, mixed>> $details     Per-mapping preview details (dry runs only).
     */
    public function __construct(
        public readonly string $action,
        public readonly int $position,
        public readonly string $external_id = '',
        public readonly ?int $post_id = null,
        public readonly string $message = '',
        public readonly array $details = []
    ) {}

    /**
     * Compact array for history issues and REST responses.
     *
     * @param bool $with_details Include mapping details.
     *
     * @return array<string, mixed>
     */
    public function to_array(bool $with_details = false): array
    {
        $data = [
            'action'      => $this->action,
            'position'    => $this->position,
            'external_id' => function_exists('mb_substr') ? mb_substr($this->external_id, 0, 120) : substr($this->external_id, 0, 120),
            'post_id'     => $this->post_id,
            'message'     => $this->message,
        ];

        if ($with_details) {
            $data['details'] = $this->details;
        }

        return $data;
    }
}
