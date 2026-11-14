<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Storage;

use IndianOpinion\GenQueue\StorageException;

/**
 * Everything gen-queue keeps in the database goes through this interface.
 *
 * Rows are plain arrays keyed by column name. Time columns are Unix
 * timestamps (UTC) or null; the store converts them to and from the
 * database's DATETIME columns. Every method throws StorageException when
 * the database refuses the read or the write.
 *
 * Option reads bypass every cache: they go to the options table directly,
 * because the kill switch, the pause list and the cap must never be read
 * from a stale object cache.
 *
 * @phpstan-type JobRow array<string, mixed>
 * @phpstan-type AttemptRow array<string, mixed>
 */
interface Store
{
    public const JOB_COLUMNS = [
        'id', 'type', 'idempotency_key', 'status', 'priority', 'payload', 'group_key', 'resource_key',
        'source', 'attempts', 'max_attempts', 'run_after', 'expires_at', 'lease_token', 'lease_until',
        'worker', 'estimate_nanos', 'cost_nanos', 'cancel_requested', 'result', 'last_error',
        'last_error_class', 'created_by', 'created_at', 'updated_at', 'finished_at',
    ];

    public const ATTEMPT_COLUMNS = [
        'id', 'job_id', 'attempt_no', 'type', 'group_key', 'resource_key', 'outcome', 'cost_nanos',
        'cost_day', 'input_units', 'output_units', 'usage_ref', 'metered', 'duration_ms', 'error',
        'log', 'started_at', 'finished_at',
    ];

    public const LOCK_COLUMNS = ['name', 'owner', 'locked_until'];

    public const TIME_COLUMNS = ['run_after', 'expires_at', 'lease_until', 'created_at', 'updated_at', 'finished_at', 'started_at'];

    public const TERMINAL = ['succeeded', 'failed', 'cancelled', 'expired'];

    /** Current Unix time according to the database. */
    public function now(): int;

    // Options, always read fresh.

    public function getOption(string $name): ?string;

    /**
     * @return array<string, string> option name => raw value, for names starting with $prefix
     */
    public function getOptionsLike(string $prefix): array;

    public function setOption(string $name, string $value, bool $autoload = false): void;

    public function deleteOption(string $name): void;

    /** @return int number of options deleted */
    public function deleteOptionsLike(string $prefix): int;

    // Locks: rows in the plugin's own lock table, claimed by one atomic UPDATE.

    public function acquireLock(string $name, string $owner, int $until, int $now): bool;

    public function releaseLock(string $name, string $owner): void;

    // Transactions.

    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    // Jobs.

    /**
     * Insert one job. Returns the new id, or null when (type, idempotency_key) already exists.
     *
     * @param array<string, mixed> $row
     */
    public function insertJob(array $row): ?int;

    /** @return array<string, mixed>|null */
    public function getJob(int $id): ?array;

    /** @return array<string, mixed>|null */
    public function findJobByKey(string $type, string $key): ?array;

    /**
     * Conditional update of one job.
     *
     * @param array<string, mixed> $set column => new value
     * @param array<string, mixed> $where column => required current value (null means IS NULL)
     * @param array<string, int> $increment column => signed delta, floored at zero
     * @return int rows changed (0 or 1)
     */
    public function updateJob(int $id, array $set, array $where = [], array $increment = []): int;

    /**
     * Eligible queued jobs, best first: priority descending, run_after ascending, id ascending.
     *
     * @param list<string> $types only these types (the registered ones)
     * @param array{paused?: list<string>, resources?: list<string>, groups?: list<string>, ids?: list<int>} $exclude
     * @return list<array<string, mixed>>
     */
    public function nextCandidates(int $now, array $types, array $exclude, int $limit): array;

    public function hasQueued(): bool;

    /** Sum of estimate_nanos of running jobs, optionally of one type. */
    public function reservedNanos(?string $type = null): int;

    /**
     * Running jobs whose lease has passed.
     *
     * @return list<array<string, mixed>>
     */
    public function leaseExpired(int $now, int $limit): array;

    /**
     * Queued jobs whose expiry has passed.
     *
     * @return list<array<string, mixed>>
     */
    public function expiryDue(int $now, int $limit): array;

    /**
     * Mark the given queued jobs expired, where they are still queued.
     *
     * @param list<int> $ids
     */
    public function markExpired(array $ids, int $now): int;

    /**
     * Delete up to $limit terminal jobs finished before $before, with their attempts.
     */
    public function purgeTerminal(int $before, int $limit): int;

    public function countTerminalBefore(int $before): int;

    /**
     * @param array{status?: string, type?: string, source?: string, group?: string} $filter
     * @return list<array<string, mixed>>
     */
    public function listJobs(array $filter, int $limit, int $afterId = 0): array;

    /** @return list<array{status: string, type: string, n: int}> */
    public function countsByStatusType(): array;

    /** @return list<array<string, mixed>> */
    public function recentFailures(int $limit): array;

    /** @return list<array<string, mixed>> */
    public function jobsByCreator(int $userId, int $limit, int $offset): array;

    public function eraseCreator(int $userId): int;

    // Attempts.

    /** @param array<string, mixed> $row */
    public function insertAttempt(array $row): int;

    /**
     * @param array<string, mixed> $set
     * @param array<string, int> $increment
     */
    public function updateAttempt(int $id, array $set, array $increment = []): int;

    /** @return list<array<string, mixed>> */
    public function attemptsForJob(int $jobId): array;

    /** @return array<string, mixed>|null the latest attempt still marked started */
    public function openAttempt(int $jobId): ?array;

    /** Sum of attempt costs on a local day, optionally for one type. */
    public function spendForDay(string $day, ?string $type = null): int;

    /**
     * Per day and type, from $sinceDay on.
     *
     * @return list<array{day: string, type: string, attempts: int, succeeded: int, failed: int, cost_nanos: int}>
     */
    public function statsSince(string $sinceDay): array;

    /** Attempts of a type since a time that ran longer than $ms milliseconds. */
    public function countSlowAttempts(string $type, int $ms, int $since): int;

    // Schema.

    /** Create or upgrade the tables. */
    public function installSchema(): void;

    /**
     * @return array<string, list<string>> table role (jobs, attempts, locks) => column names present
     */
    public function columns(): array;

    public function dropSchema(): void;
}
