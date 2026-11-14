<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

use IndianOpinion\GenQueue\Storage\Store;

/**
 * Enqueue, look up, cancel, retry and pause. Running jobs is the Runner's job.
 */
final class Queue
{
    public const ENQUEUE_ARGS = [
        'idempotency_key', 'on_conflict', 'priority', 'run_after', 'ttl', 'expires_at', 'max_attempts',
        'cost_estimate', 'group', 'resource_key', 'source', 'allow_unregistered',
    ];

    public const BATCH_SIZE = 500;

    public function __construct(
        private readonly Store $store,
        private readonly Registry $registry,
        private readonly Controls $controls,
    ) {
    }

    /**
     * @param array<mixed> $payload
     * @param array<string, mixed> $args
     * @throws UnknownTypeException|InvalidPayloadException|StorageException|\InvalidArgumentException
     */
    public function enqueue(string $type, array $payload, array $args = []): int
    {
        $prepared = $this->prepare($type, $payload, $args);
        return $this->insert($prepared);
    }

    /**
     * Enqueue many jobs of one type. Every item is validated before anything
     * is inserted; rows go in batches of 500, one transaction per batch.
     *
     * @param list<array{payload?: array<mixed>, args?: array<string, mixed>}> $items
     * @return list<int> job ids in input order
     */
    public function enqueueMany(string $type, array $items): array
    {
        $prepared = [];
        foreach (array_values($items) as $i => $item) {
            if (!is_array($item) || !isset($item['payload']) || !is_array($item['payload'])) {
                throw new InvalidPayloadException(sprintf('Item %d has no payload array.', $i));
            }
            $args = $item['args'] ?? [];
            if (!is_array($args)) {
                throw new \InvalidArgumentException(sprintf('Item %d: args must be an array.', $i));
            }
            try {
                $prepared[] = $this->prepare($type, $item['payload'], $args);
            } catch (GenQueueException | \InvalidArgumentException $e) {
                $class = get_class($e);
                throw new $class(sprintf('Item %d: %s', $i, $e->getMessage()), 0, $e);
            }
        }

        $ids = [];
        foreach (array_chunk($prepared, self::BATCH_SIZE) as $batch) {
            $this->store->begin();
            $events = [];
            try {
                foreach ($batch as $one) {
                    $ids[] = $this->insert($one, $events);
                }
                $this->store->commit();
            } catch (\Throwable $e) {
                $this->store->rollback();
                throw $e;
            }
            foreach ($events ?? [] as [$job, $requeued]) {
                do_action('gen_queue_enqueued', $job, $requeued);
            }
        }
        return $ids;
    }

    /**
     * @param array<mixed> $payload
     * @param array<string, mixed> $args
     * @return array{row: array<string, mixed>, on_conflict: string, max_attempts: int, ttl: int}
     */
    private function prepare(string $type, array $payload, array $args): array
    {
        $unknown = array_diff(array_keys($args), self::ENQUEUE_ARGS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown enqueue argument(s): ' . implode(', ', $unknown) . '.');
        }
        $definition = $this->registry->get($type);
        if ($definition === null) {
            if (empty($args['allow_unregistered'])) {
                throw new UnknownTypeException(sprintf('Job type "%s" is not registered. Pass allow_unregistered to store it until its plugin loads.', $type));
            }
            if (preg_match(JobType::NAME_PATTERN, $type) !== 1) {
                throw new UnknownTypeException(sprintf('Invalid job type name "%s".', $type));
            }
            $defaults = JobType::fallbackDefaults($type);
        } else {
            $defaults = [
                'max_attempts' => $definition->maxAttempts,
                'priority' => $definition->priority,
                'ttl' => $definition->ttl,
                'group' => $definition->group,
                'resource_key' => $definition->resourceKey,
                'cost_estimate' => $definition->costEstimateNanos,
            ];
        }

        $maxBytes = (int) apply_filters('gen_queue_payload_max_bytes', Payload::DEFAULT_MAX_BYTES);
        $json = Payload::encode($payload, max(1, $maxBytes));

        $now = $this->store->now();
        $key = isset($args['idempotency_key']) ? Validate::idempotencyKey($args['idempotency_key']) : null;
        $onConflict = $args['on_conflict'] ?? 'return';
        if ($onConflict !== 'return' && $onConflict !== 'requeue') {
            throw new \InvalidArgumentException('on_conflict must be "return" or "requeue".');
        }
        $priority = Validate::intRange($args['priority'] ?? $defaults['priority'], -100, 100, 'priority');
        $maxAttempts = Validate::intRange($args['max_attempts'] ?? $defaults['max_attempts'], 1, 1000, 'max_attempts');
        $runAfter = $now;
        if (isset($args['run_after'])) {
            $runAfter = max($now, Validate::intRange($args['run_after'], 0, PHP_INT_MAX, 'run_after'));
        }
        $ttl = Validate::intRange($args['ttl'] ?? $defaults['ttl'], 0, 315360000, 'ttl');
        $expiresAt = $ttl === 0 ? null : $runAfter + $ttl;
        if (isset($args['expires_at'])) {
            $expiresAt = Validate::intRange($args['expires_at'], 0, PHP_INT_MAX, 'expires_at');
        }
        $estimate = isset($args['cost_estimate'])
            ? Money::toNanos(Validate::amount($args['cost_estimate'], 'cost_estimate'))
            : (int) $defaults['cost_estimate'];
        $group = Validate::label($args['group'] ?? $defaults['group'], 64, 'group');
        $resource = Validate::label($args['resource_key'] ?? $defaults['resource_key'], 128, 'resource_key');
        $dot = strpos($type, '.');
        $source = Validate::label($args['source'] ?? substr($dot === false ? $type : substr($type, 0, $dot), 0, 64), 64, 'source');

        return [
            'row' => [
                'type' => $type,
                'idempotency_key' => $key,
                'status' => 'queued',
                'priority' => $priority,
                'payload' => $json,
                'group_key' => $group,
                'resource_key' => $resource,
                'source' => $source,
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'run_after' => $runAfter,
                'expires_at' => $expiresAt,
                'estimate_nanos' => $estimate,
                'cost_nanos' => 0,
                'cancel_requested' => 0,
                'created_by' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            'on_conflict' => $onConflict,
            'max_attempts' => $maxAttempts,
            'ttl' => $ttl,
        ];
    }

    /**
     * @param array{row: array<string, mixed>, on_conflict: string, max_attempts: int, ttl: int} $prepared
     * @param list<array{0: Job, 1: bool}>|null $events collect events instead of firing them (inside a transaction)
     */
    private function insert(array $prepared, ?array &$events = null): int
    {
        $row = $prepared['row'];
        $id = null;
        $existing = null;
        if ($row['idempotency_key'] !== null) {
            $existing = $this->store->findJobByKey((string) $row['type'], (string) $row['idempotency_key']);
        }
        if ($existing === null) {
            $id = $this->store->insertJob($row);
        }
        if ($id !== null) {
            $row['id'] = $id;
            $this->emit(Job::fromRow($row), false, $events);
            return $id;
        }

        // The unique (type, idempotency_key) index decided: read the winner.
        $existing ??= $this->store->findJobByKey((string) $row['type'], (string) $row['idempotency_key']);
        if ($existing === null) {
            throw new StorageException('Insert was refused but no existing job with that idempotency key was found.');
        }
        $existingId = (int) $existing['id'];
        if ($prepared['on_conflict'] === 'requeue' && in_array($existing['status'], ['failed', 'cancelled', 'expired'], true)) {
            $now = $this->store->now();
            $changed = $this->store->updateJob($existingId, [
                'status' => 'queued',
                'payload' => $row['payload'],
                'priority' => $row['priority'],
                'max_attempts' => (int) $existing['attempts'] + $prepared['max_attempts'],
                'run_after' => $row['run_after'],
                'expires_at' => $row['expires_at'],
                'estimate_nanos' => $row['estimate_nanos'],
                'cancel_requested' => 0,
                'lease_token' => null,
                'lease_until' => null,
                'worker' => null,
                'finished_at' => null,
                'updated_at' => $now,
            ], ['status' => $existing['status']]);
            if ($changed === 1) {
                $fresh = $this->store->getJob($existingId);
                if ($fresh !== null) {
                    $this->emit(Job::fromRow($fresh), true, $events);
                }
            }
        }
        return $existingId;
    }

    /** @param list<array{0: Job, 1: bool}>|null $events */
    private function emit(Job $job, bool $requeued, ?array &$events): void
    {
        if ($events !== null) {
            $events[] = [$job, $requeued];
            return;
        }
        do_action('gen_queue_enqueued', $job, $requeued);
    }

    public function get(int $id): ?Job
    {
        $row = $this->store->getJob($id);
        return $row === null ? null : Job::fromRow($row);
    }

    public function find(string $type, string $key): ?Job
    {
        $row = $this->store->findJobByKey($type, $key);
        return $row === null ? null : Job::fromRow($row);
    }

    /**
     * Cancel one job. Queued: cancelled now. Running: a cancel request the
     * handler can see; the job ends cancelled. Terminal: false.
     */
    public function cancel(int $id): bool
    {
        $row = $this->store->getJob($id);
        if ($row === null) {
            return false;
        }
        $now = $this->store->now();
        if ($row['status'] === 'queued') {
            $changed = $this->store->updateJob($id, [
                'status' => 'cancelled',
                'last_error_class' => 'cancelled',
                'finished_at' => $now,
                'updated_at' => $now,
            ], ['status' => 'queued']);
            if ($changed === 1) {
                $fresh = $this->store->getJob($id);
                if ($fresh !== null) {
                    do_action('gen_queue_cancelled', Job::fromRow($fresh));
                }
                return true;
            }
            $row = $this->store->getJob($id) ?? $row;
        }
        if ($row['status'] === 'running') {
            return $this->store->updateJob($id, ['cancel_requested' => 1, 'updated_at' => $now], ['status' => 'running']) === 1;
        }
        return false;
    }

    /**
     * Cancel queued jobs and request cancel on running ones, by type, source
     * or group. Returns how many jobs were cancelled or asked to cancel.
     *
     * @param array{type?: string, source?: string, group?: string} $where
     */
    public function cancelWhere(array $where): int
    {
        $unknown = array_diff(array_keys($where), ['type', 'source', 'group']);
        if ($unknown !== [] || $where === []) {
            throw new \InvalidArgumentException('cancel_where takes one or more of type, source and group.');
        }
        foreach ($where as $key => $value) {
            if (!is_string($value) || $value === '') {
                throw new \InvalidArgumentException($key . ' must be a non-empty string.');
            }
        }
        $count = 0;
        foreach (['queued', 'running'] as $status) {
            $after = 0;
            do {
                $rows = $this->store->listJobs($where + ['status' => $status], 500, $after);
                foreach ($rows as $row) {
                    $after = (int) $row['id'];
                    if ($this->cancel((int) $row['id'])) {
                        $count++;
                    }
                }
            } while (count($rows) === 500);
        }
        return $count;
    }

    /**
     * Put a failed, cancelled or expired job back in the queue, with
     * max_attempts raised to its attempts plus the type default.
     */
    public function retry(int $id): bool
    {
        $row = $this->store->getJob($id);
        if ($row === null || !in_array($row['status'], ['failed', 'cancelled', 'expired'], true)) {
            return false;
        }
        $type = $this->registry->get((string) $row['type']);
        $extra = $type->maxAttempts ?? JobType::DEFAULT_MAX_ATTEMPTS;
        $ttl = $type->ttl ?? JobType::DEFAULT_TTL;
        $now = $this->store->now();
        $expires = $row['expires_at'];
        if ($expires !== null && (int) $expires <= $now) {
            $expires = $ttl === 0 ? null : $now + $ttl;
        }
        $changed = $this->store->updateJob($id, [
            'status' => 'queued',
            'max_attempts' => min(65535, (int) $row['attempts'] + $extra),
            'run_after' => $now,
            'expires_at' => $expires,
            'cancel_requested' => 0,
            'lease_token' => null,
            'lease_until' => null,
            'worker' => null,
            'finished_at' => null,
            'updated_at' => $now,
        ], ['status' => $row['status']]);
        if ($changed !== 1) {
            return false;
        }
        $fresh = $this->store->getJob($id);
        if ($fresh !== null) {
            do_action('gen_queue_enqueued', Job::fromRow($fresh), true);
        }
        return true;
    }

    public function pause(?string $type = null): void
    {
        if ($type === null) {
            $this->controls->setEnabled(false);
            return;
        }
        $this->controls->pauseType(Validate::typeName($type));
    }

    public function resume(?string $type = null): void
    {
        if ($type === null) {
            $this->controls->setEnabled(true);
            return;
        }
        $this->controls->resumeType(Validate::typeName($type));
    }

    public function isEnabled(?string $type = null): bool
    {
        if (!$this->controls->enabled()) {
            return false;
        }
        return $type === null || !in_array($type, $this->controls->pausedTypes(), true);
    }

    /**
     * @param string|int|float|null $amount null unsets the cap, so nothing runs.
     */
    public function setCap(string|int|float|null $amount): void
    {
        $this->controls->setCap($amount === null ? null : Money::toNanos($amount));
    }
}
