<?php

declare(strict_types=1);

namespace PostPressAPI\Sync;

use PostPressAPI\Http\ApiClient;
use PostPressAPI\Http\Url;
use PostPressAPI\Logging\Logger;
use PostPressAPI\Mapping\Destination;
use PostPressAPI\Mapping\DestinationCatalog;
use PostPressAPI\Mapping\DestinationRegistry;
use PostPressAPI\Mapping\MappingError;
use PostPressAPI\Presets\PresetRepository;
use PostPressAPI\Presets\PresetSchema;
use PostPressAPI\Presets\PresetValidator;
use PostPressAPI\Presets\SecretStore;
use PostPressAPI\Scheduling\ContinuationScheduler;
use PostPressAPI\Support\Clock;
use PostPressAPI\Support\Permissions;
use PostPressAPI\Support\Redactor;

if (!defined('ABSPATH')) exit;

/**
 * Runs imports in bounded, resumable steps. Manual and scheduled runs share
 * this pipeline.
 *
 *   start()  validates the preset, takes a snapshot of its configuration, and
 *            stores the run state. The snapshot is used for the whole run, so
 *            editing the preset mid-run affects only the next run; deleting
 *            or disabling the preset cancels the run.
 *   step()   holds the preset's {@see RunLock}, then processes up to one batch
 *            of buffered records, or fetches at most one page, within a time
 *            budget. Progress is saved after every record.
 *
 * Callers drive steps: the admin screen calls step() repeatedly via REST, and
 * a single ppapi_continue_run cron event is always scheduled for an unfinished
 * run so it completes without the browser.
 *
 * Transient fetch failures (network errors, 408/425/429/5xx) are retried up to
 * the preset's retry count with exponential backoff or the server's
 * Retry-After value; the wait is persisted (status "waiting"), never slept.
 * A run ends as completed, completed_with_errors, truncated (stopped early, more
 * records remain), failed, or cancelled — partial runs are never reported as complete.
 */
final class ImportRunner
{
    public const STATUS_RUNNING          = 'running';
    public const STATUS_WAITING          = 'waiting';
    public const STATUS_COMPLETED        = 'completed';
    public const STATUS_COMPLETED_ERRORS = 'completed_with_errors';
    public const STATUS_TRUNCATED        = 'truncated';
    public const STATUS_FAILED           = 'failed';
    public const STATUS_CANCELLED        = 'cancelled';

    /** @var list<string> Statuses of an unfinished run. */
    public const ACTIVE = [self::STATUS_RUNNING, self::STATUS_WAITING];

    public const TRIGGER_MANUAL   = 'manual';
    public const TRIGGER_SCHEDULE = 'schedule';

    /** @var int Seconds without progress after which a run may be replaced. */
    public const STALE_AFTER = 1800;

    /** @var int Delay before the fallback continuation event. */
    private const CONTINUE_DELAY = 60;

    /** @var int Longest computed backoff in seconds. */
    private const MAX_BACKOFF = 300;

    /** @var int Failed-record issues kept per run. */
    private const ISSUE_LIMIT = 50;

    /** @var int Page hashes remembered to detect repeated pages. */
    private const HASH_MEMORY = 20;

    public function __construct(
        private readonly PresetRepository $presets,
        private readonly SecretStore $secrets,
        private readonly RunRepository $runs,
        private readonly RunLock $lock,
        private readonly ApiClient $client,
        private readonly ResponseReader $reader,
        private readonly Pagination $pagination,
        private readonly RecordProcessor $processor,
        private readonly DestinationRegistry $destinations,
        private readonly PresetValidator $validator,
        private readonly ContinuationScheduler $continuations,
        private readonly Logger $logger,
        private readonly Clock $clock
    ) {}

    /**
     * Starts a run.
     *
     * @param string $preset_id Preset ID.
     * @param string $trigger   manual or schedule.
     * @param bool   $force     Re-apply mapped values even when fingerprints match.
     *
     * @return array<string, mixed>|\WP_Error Run state.
     */
    public function start(string $preset_id, string $trigger, bool $force = false): array|\WP_Error
    {
        $preset = $this->presets->find($preset_id);
        if ($preset === null) {
            return new \WP_Error('ppapi_not_found', __('The preset does not exist.', 'post-press-api'), ['status' => 404]);
        }

        if (!$preset['enabled']) {
            return new \WP_Error('ppapi_disabled', __('Enable the preset before running it.', 'post-press-api'), ['status' => 409]);
        }

        $problems = $this->validator->readiness_problems($preset, $this->has_usable_secret($preset));
        if ($problems !== []) {
            return new \WP_Error('ppapi_not_ready', __('The preset is not ready to run:', 'post-press-api') . ' ' . implode(' ', $problems), ['status' => 409]);
        }

        $token = $this->lock->acquire($preset_id);
        if ($token === null) {
            return new \WP_Error('ppapi_running', __('A run of this preset is already in progress.', 'post-press-api'), ['status' => 409]);
        }

        try {
            $active = $this->runs->active($preset_id);
            if ($active !== null && in_array($active['status'] ?? '', self::ACTIVE, true)) {
                if (!$this->is_stale($active)) {
                    return new \WP_Error('ppapi_running', __('A run of this preset is already in progress.', 'post-press-api'), ['status' => 409]);
                }
                $this->finish($active, self::STATUS_FAILED, __('Abandoned after 30 minutes without progress; a new run replaced it.', 'post-press-api'));
            }

            $this->runs->clear_cancel($preset_id);
            $state = $this->new_state($preset, $trigger, $force);
            $this->runs->save_buffer($preset_id, []);
            $this->runs->save_active($state);
        } finally {
            $this->lock->release($preset_id, $token);
        }

        $this->continuations->schedule($preset_id, $this->clock->now() + self::CONTINUE_DELAY);
        $this->logger->info(
            $trigger === self::TRIGGER_SCHEDULE ? __('Scheduled run started.', 'post-press-api') : __('Manual run started.', 'post-press-api'),
            ['preset_id' => $preset_id, 'run_id' => $state['run_id']]
        );

        return $state;
    }

    /**
     * Advances the active run by one bounded step.
     *
     * @param string $preset_id Preset ID.
     * @param float  $budget    Seconds this step may spend.
     *
     * @return array{state: array<string, mixed>|null, busy: bool}
     */
    public function step(string $preset_id, float $budget): array
    {
        $token = $this->lock->acquire($preset_id);
        if ($token === null) {
            return ['state' => $this->runs->active($preset_id), 'busy' => true];
        }

        $state = null;
        try {
            $state = $this->runs->active($preset_id);
            if ($state === null || !in_array($state['status'] ?? '', self::ACTIVE, true)) {
                return ['state' => $state, 'busy' => false];
            }

            $state = $this->advance($state, $this->effective_budget($budget));
        } catch (\Throwable $e) {
            if (is_array($state) && in_array($state['status'] ?? '', self::ACTIVE, true)) {
                $redactor = new Redactor();
                $secret   = $this->secrets->get($preset_id);
                $redactor->add_secret($secret['value'] ?? null);
                $state = $this->finish($state, self::STATUS_FAILED, sprintf(
                    /* translators: %s: error message. */
                    __('The run stopped because of an unexpected error: %s', 'post-press-api'),
                    $redactor->text($e->getMessage())
                ));
            }
        } finally {
            $this->lock->release($preset_id, $token);
        }

        if (is_array($state) && in_array($state['status'] ?? '', self::ACTIVE, true)) {
            $resume_at = (int) $state['resume_at'] > $this->clock->now() ? (int) $state['resume_at'] : $this->clock->now() + self::CONTINUE_DELAY;
            $this->continuations->schedule($preset_id, $resume_at);
        } else {
            $this->continuations->clear($preset_id);
        }

        return ['state' => $state, 'busy' => false];
    }

    /**
     * Cancels the active run (or asks a step running elsewhere to stop).
     *
     * @param string $preset_id Preset ID.
     * @param string $reason    Message recorded in history.
     *
     * @return array{cancelled: bool, pending: bool}
     */
    public function cancel(string $preset_id, string $reason): array
    {
        $token = $this->lock->acquire($preset_id);
        if ($token === null) {
            if ($this->runs->active($preset_id) !== null) {
                $this->runs->request_cancel($preset_id, $reason);
                return ['cancelled' => false, 'pending' => true];
            }
            return ['cancelled' => false, 'pending' => false];
        }

        $cancelled = false;
        try {
            $state = $this->runs->active($preset_id);
            if ($state !== null && in_array($state['status'] ?? '', self::ACTIVE, true)) {
                $this->finish($state, self::STATUS_CANCELLED, $reason);
                $cancelled = true;
            }
        } finally {
            $this->lock->release($preset_id, $token);
        }

        $this->continuations->clear($preset_id);

        return ['cancelled' => $cancelled, 'pending' => false];
    }

    /**
     * Cancels every active run (used on deactivation).
     *
     * @param string $reason Message recorded in history.
     *
     * @return void
     */
    public function cancel_all(string $reason): void
    {
        foreach ($this->presets->ids() as $preset_id) {
            if ($this->runs->active($preset_id) !== null) {
                $this->cancel($preset_id, $reason);
            }
        }
    }

    /**
     * Records a run that could not start (e.g. a scheduled run of an incomplete preset).
     *
     * @param string $preset_id Preset ID.
     * @param string $trigger   Trigger.
     * @param string $message   Reason.
     *
     * @return void
     */
    public function record_failed_start(string $preset_id, string $trigger, string $message): void
    {
        $now = $this->clock->now();
        $this->runs->add_history($preset_id, [
            'run_id'         => wp_generate_uuid4(),
            'preset_id'      => $preset_id,
            'trigger'        => $trigger,
            'status'         => self::STATUS_FAILED,
            'force'          => false,
            'started_at'     => $now,
            'updated_at'     => $now,
            'finished_at'    => $now,
            'pages'          => 0,
            'fetched'        => 0,
            'processed'      => 0,
            'counts'         => array_fill_keys(RecordOutcome::ACTIONS, 0),
            'message'        => $message,
            'issues'         => [],
            'issue_overflow' => 0,
            'revision'       => 0,
            'resume_at'      => 0,
            'buffer_index'   => 0,
            'buffer_size'    => 0,
        ]);
        $this->logger->warning($message, ['preset_id' => $preset_id, 'trigger' => $trigger]);
    }

    /**
     * Public view of a run state (no snapshot, buffer, or internal cursors).
     *
     * @param array<string, mixed> $state Run state or history entry.
     *
     * @return array<string, mixed>
     */
    public static function summary(array $state): array
    {
        return [
            'run_id'         => (string) ($state['run_id'] ?? ''),
            'preset_id'      => (string) ($state['preset_id'] ?? ''),
            'trigger'        => (string) ($state['trigger'] ?? ''),
            'status'         => (string) ($state['status'] ?? ''),
            'force'          => (bool) ($state['force'] ?? false),
            'started_at'     => (int) ($state['started_at'] ?? 0),
            'updated_at'     => (int) ($state['updated_at'] ?? 0),
            'finished_at'    => (int) ($state['finished_at'] ?? 0),
            'pages'          => (int) ($state['pages'] ?? 0),
            'fetched'        => (int) ($state['fetched'] ?? 0),
            'processed'      => (int) ($state['processed'] ?? 0),
            'counts'         => array_merge(array_fill_keys(RecordOutcome::ACTIONS, 0), (array) ($state['counts'] ?? [])),
            'message'        => (string) ($state['message'] ?? ''),
            'issues'         => array_values((array) ($state['issues'] ?? [])),
            'issue_overflow' => (int) ($state['issue_overflow'] ?? 0),
            'revision'       => (int) ($state['revision'] ?? 0),
            'resume_at'      => (int) ($state['resume_at'] ?? 0),
            'buffer_index'   => (int) ($state['buffer_index'] ?? 0),
            'buffer_size'    => (int) ($state['buffer_size'] ?? 0),
        ];
    }

    /**
     * Processes records and fetches pages until the batch, page, or time budget is used.
     *
     * @param array<string, mixed> $state  Run state.
     * @param float                $budget Seconds.
     *
     * @return array<string, mixed>
     */
    private function advance(array $state, float $budget): array
    {
        $preset_id = (string) $state['preset_id'];
        $preset    = $state['snapshot'];
        $deadline  = $this->clock->microtime() + max(1.0, $budget);
        $limit     = max(1, (int) $preset['batch_size']);
        $processed = 0;
        $fetched   = false;
        $context   = null;
        $buffer    = null;

        if ($this->presets->find($preset_id) === null) {
            return $this->finish($state, self::STATUS_CANCELLED, __('The preset was deleted during the run.', 'post-press-api'));
        }

        while (true) {
            $reason = $this->runs->cancel_reason($preset_id);
            if ($reason !== null) {
                return $this->finish($state, self::STATUS_CANCELLED, $reason);
            }

            if ((int) $state['resume_at'] > $this->clock->now()) {
                $state['status'] = self::STATUS_WAITING;
                break;
            }
            $state['status'] = self::STATUS_RUNNING;

            if ((int) $state['buffer_index'] < (int) $state['buffer_size']) {
                if ($processed >= $limit || $this->clock->microtime() >= $deadline) {
                    break;
                }

                $buffer ??= $this->runs->buffer($preset_id);
                if (!array_key_exists((int) $state['buffer_index'], $buffer)) {
                    return $this->finish($state, self::STATUS_FAILED, __('The buffered page was lost before it was processed. Start the run again.', 'post-press-api'));
                }

                if ($context === null) {
                    $context = $this->context($state);
                    if (is_string($context)) {
                        return $this->finish($state, self::STATUS_FAILED, $context);
                    }
                }

                $outcome = $this->processor->process($buffer[(int) $state['buffer_index']], (int) $state['processed'] + 1, $context);
                $state   = $this->record($state, $outcome);
                $state['buffer_index'] = (int) $state['buffer_index'] + 1;
                $state['updated_at']   = $this->clock->now();
                $processed++;
                $this->runs->save_active($state);
                continue;
            }

            if (!empty($state['more_pages'])) {
                if ($fetched || $this->clock->microtime() >= $deadline) {
                    break;
                }
                $fetched = true;
                $buffer  = null;
                $state   = $this->fetch_page($state);
                if (!in_array($state['status'], self::ACTIVE, true) || $state['status'] === self::STATUS_WAITING) {
                    return $state;
                }
                continue;
            }

            $status = (string) ($state['end_status'] ?? '');
            if ($status === '') {
                $status = (int) $state['counts'][RecordOutcome::FAILED] > 0 ? self::STATUS_COMPLETED_ERRORS : self::STATUS_COMPLETED;
            }

            return $this->finish($state, $status, (string) $state['message']);
        }

        $state['updated_at'] = $this->clock->now();
        $this->runs->save_active($state);

        return $state;
    }

    /**
     * Fetches the next page and buffers its records.
     *
     * @param array<string, mixed> $state Run state.
     *
     * @return array<string, mixed>
     */
    private function fetch_page(array $state): array
    {
        $preset    = $state['snapshot'];
        $preset_id = (string) $state['preset_id'];
        $secret    = null;

        if ($preset['request']['auth']['type'] !== PresetSchema::AUTH_NONE) {
            $secret = $this->secrets->value_for_origin($preset_id, Url::origin((string) $preset['request']['url']));
            if ($secret === null) {
                return $this->finish($state, self::STATUS_FAILED, __('The saved credential is missing or was entered for a different host. Re-enter it in the preset.', 'post-press-api'));
            }
        }

        $result = $this->client->fetch($preset, $secret, $this->pagination->params($preset, $state['cursor']));

        if (!$result->ok) {
            $attempts = (int) $state['fetch_attempts'];
            $retries  = (int) $preset['request']['retries'];

            if ($result->retryable && $attempts < $retries) {
                $delay = $result->retry_after ?? min(self::MAX_BACKOFF, 10 * (2 ** $attempts));
                $delay = max(1, (int) $delay);

                $state['fetch_attempts'] = $attempts + 1;
                $state['resume_at']      = $this->clock->now() + $delay;
                $state['status']         = self::STATUS_WAITING;
                $state['updated_at']     = $this->clock->now();
                $state['message']        = sprintf(
                    /* translators: 1: error, 2: seconds, 3: attempt number, 4: maximum retries. */
                    __('%1$s Retrying in %2$d seconds (retry %3$d of %4$d).', 'post-press-api'),
                    $result->error,
                    $delay,
                    $attempts + 1,
                    $retries
                );
                $this->runs->save_active($state);
                $this->logger->warning($state['message'], ['preset_id' => $preset_id, 'run_id' => $state['run_id'], 'http_status' => $result->status, 'url' => $result->url]);

                return $state;
            }

            $message = $result->error;
            if ($attempts > 0) {
                $message .= ' ' . sprintf(
                    /* translators: %d: retries. */
                    _n('Gave up after %d retry.', 'Gave up after %d retries.', $attempts, 'post-press-api'),
                    $attempts
                );
            }

            return $this->finish($state, self::STATUS_FAILED, $message, ['http_status' => $result->status, 'url' => $result->url]);
        }

        $state['fetch_attempts'] = 0;
        $state['resume_at']      = 0;
        $state['message']        = '';

        $read = $this->reader->records($result->data, $preset);
        if (!$read['ok']) {
            return $this->finish($state, self::STATUS_FAILED, $read['error']);
        }

        $records = $read['records'];
        $hash    = sha1((string) wp_json_encode($records));

        $state['pages']   = (int) $state['pages'] + 1;
        $state['fetched'] = (int) $state['fetched'] + count($records);

        $decision = $this->pagination->after_page($preset, $state['cursor'], $result->data, count($records), $hash, (array) $state['hashes'], (int) $state['pages'], (int) $state['fetched']);
        if ($decision['state'] === Pagination::ERROR) {
            return $this->finish($state, self::STATUS_FAILED, $decision['message']);
        }

        $state['hashes'] = array_slice([...(array) $state['hashes'], $hash], -self::HASH_MEMORY);
        $this->runs->save_buffer($preset_id, $records);
        $state['buffer_index'] = 0;
        $state['buffer_size']  = count($records);

        if ($decision['state'] === Pagination::CONTINUE) {
            $state['cursor']     = $decision['cursor'];
            $state['more_pages'] = true;
        } else {
            $state['more_pages'] = false;
            if ($decision['state'] === Pagination::TRUNCATED) {
                $state['end_status'] = self::STATUS_TRUNCATED;
                $state['message']    = $decision['message'];
            }
        }

        $state['updated_at'] = $this->clock->now();
        $this->runs->save_active($state);

        return $state;
    }

    /**
     * Builds the record-processing context, or explains why the run cannot proceed.
     *
     * Checks that the destination post type, every mapped destination (including
     * ACF fields and the ACF features they need), and the default author are still
     * valid. A problem stops only this preset's run.
     *
     * @param array<string, mixed> $state Run state.
     *
     * @return RunContext|string
     */
    private function context(array $state): RunContext|string
    {
        $preset    = $state['snapshot'];
        $post_type = (string) $preset['destination']['post_type'];

        if (!post_type_exists($post_type)) {
            return sprintf(
                /* translators: %s: post type. */
                __('The destination post type "%s" is no longer registered.', 'post-press-api'),
                $post_type
            );
        }

        $catalog = $this->destinations->catalog($post_type);
        foreach ((array) $preset['mappings'] as $index => $row) {
            try {
                $this->check_destination($catalog, $row, null);
            } catch (MappingError $e) {
                return sprintf(
                    /* translators: 1: mapping number, 2: problem. */
                    __('This preset cannot run until mapping %1$d is fixed: %2$s', 'post-press-api'),
                    $index + 1,
                    $e->getMessage()
                );
            }
        }

        $problems = Permissions::author_problems($post_type, PresetValidator::may_publish($preset), (int) $preset['destination']['author']);
        if ($problems !== []) {
            return implode(' ', $problems);
        }

        return new RunContext(
            $preset,
            $catalog,
            wp_timezone(),
            Fingerprint::mapping_hash($preset),
            (string) $state['run_id'],
            false,
            (bool) $state['force']
        );
    }

    /**
     * Resolves a row's destination (and nested destinations) or throws.
     *
     * @param DestinationCatalog       $catalog Catalog.
     * @param array<string, mixed>     $row     Row.
     * @param Destination|null         $parent  Parent container.
     *
     * @return void
     *
     * @throws MappingError
     */
    private function check_destination(DestinationCatalog $catalog, array $row, ?Destination $parent): void
    {
        $destination = $parent === null
            ? $catalog->resolve((string) $row['target_type'], (string) $row['target'])
            : $catalog->resolve_child($parent, (string) $row['target']);

        foreach ((array) ($row['children'] ?? []) as $child) {
            $this->check_destination($catalog, $child, $destination);
        }
    }

    /**
     * Adds an outcome to the run counters and bounded issue list.
     *
     * @param array<string, mixed> $state   Run state.
     * @param RecordOutcome        $outcome Outcome.
     *
     * @return array<string, mixed>
     */
    private function record(array $state, RecordOutcome $outcome): array
    {
        $state['counts'][$outcome->action] = (int) ($state['counts'][$outcome->action] ?? 0) + 1;
        $state['processed']                = (int) $state['processed'] + 1;

        if ($outcome->action === RecordOutcome::FAILED) {
            if (count($state['issues']) < self::ISSUE_LIMIT) {
                $state['issues'][] = $outcome->to_array();
            } else {
                $state['issue_overflow'] = (int) $state['issue_overflow'] + 1;
            }
        }

        return $state;
    }

    /**
     * Ends a run: records history, clears active state, and logs the result.
     *
     * @param array<string, mixed> $state   Run state.
     * @param string               $status  Final status.
     * @param string               $message Message.
     * @param array<string, mixed> $context Extra log context.
     *
     * @return array<string, mixed>
     */
    private function finish(array $state, string $status, string $message, array $context = []): array
    {
        $now = $this->clock->now();

        $state['status']      = $status;
        $state['message']     = $message;
        $state['finished_at'] = $now;
        $state['updated_at']  = $now;
        $state['resume_at']   = 0;

        $summary   = self::summary($state);
        $preset_id = (string) $state['preset_id'];

        $this->runs->add_history($preset_id, $summary);
        $this->runs->clear_active($preset_id);
        $this->runs->clear_cancel($preset_id);

        $level = match ($status) {
            self::STATUS_COMPLETED => Logger::INFO,
            self::STATUS_FAILED    => Logger::ERROR,
            default                => Logger::WARNING,
        };

        $counts = $summary['counts'];
        $this->logger->log($level, sprintf(
            /* translators: 1: status, 2: created, 3: updated, 4: unchanged, 5: skipped, 6: failed, 7: message. */
            __('Run %1$s: %2$d created, %3$d updated, %4$d unchanged, %5$d skipped, %6$d failed. %7$s', 'post-press-api'),
            $status,
            $counts['created'],
            $counts['updated'],
            $counts['unchanged'],
            $counts['skipped'],
            $counts['failed'],
            $message
        ), ['preset_id' => $preset_id, 'run_id' => $summary['run_id'], 'trigger' => $summary['trigger']] + $context);

        /**
         * Fires when a run finishes (any final status).
         *
         * @param string               $preset_id Preset ID.
         * @param array<string, mixed> $summary   Run summary (no payloads or secrets).
         */
        do_action('ppapi_run_finished', $preset_id, $summary);

        return $state;
    }

    /**
     * Initial run state.
     *
     * @param array<string, mixed> $preset  Preset snapshot.
     * @param string               $trigger Trigger.
     * @param bool                 $force   Ignore fingerprints.
     *
     * @return array<string, mixed>
     */
    private function new_state(array $preset, string $trigger, bool $force): array
    {
        $now = $this->clock->now();

        return [
            'run_id'         => wp_generate_uuid4(),
            'preset_id'      => (string) $preset['id'],
            'trigger'        => $trigger === self::TRIGGER_SCHEDULE ? self::TRIGGER_SCHEDULE : self::TRIGGER_MANUAL,
            'force'          => $force,
            'status'         => self::STATUS_RUNNING,
            'snapshot'       => $preset,
            'revision'       => (int) $preset['revision'],
            'cursor'         => $this->pagination->start($preset),
            'more_pages'     => true,
            'end_status'     => '',
            'buffer_index'   => 0,
            'buffer_size'    => 0,
            'pages'          => 0,
            'fetched'        => 0,
            'processed'      => 0,
            'counts'         => array_fill_keys(RecordOutcome::ACTIONS, 0),
            'issues'         => [],
            'issue_overflow' => 0,
            'hashes'         => [],
            'fetch_attempts' => 0,
            'resume_at'      => 0,
            'message'        => '',
            'started_at'     => $now,
            'updated_at'     => $now,
            'finished_at'    => 0,
            'started_by'     => get_current_user_id(),
        ];
    }

    /**
     * Whether an unfinished run has stopped making progress.
     *
     * Only called while holding the lock, so no step is running elsewhere.
     *
     * @param array<string, mixed> $state Run state.
     *
     * @return bool
     */
    private function is_stale(array $state): bool
    {
        $now = $this->clock->now();

        return (int) ($state['updated_at'] ?? 0) < $now - self::STALE_AFTER && (int) ($state['resume_at'] ?? 0) <= $now;
    }

    /**
     * Whether the preset has the credential its authentication method needs.
     *
     * @param array<string, mixed> $preset Preset.
     *
     * @return bool
     */
    private function has_usable_secret(array $preset): bool
    {
        if ($preset['request']['auth']['type'] === PresetSchema::AUTH_NONE) {
            return true;
        }

        return $this->secrets->value_for_origin((string) $preset['id'], Url::origin((string) $preset['request']['url'])) !== null;
    }

    /**
     * Caps the step budget well below PHP's execution limit.
     *
     * @param float $budget Requested seconds.
     *
     * @return float
     */
    private function effective_budget(float $budget): float
    {
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0) {
            $budget = min($budget, max(3.0, $limit * 0.5));
        }

        return $budget;
    }
}
