<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Support;

use IndianOpinion\GenQueue\Storage\Store;
use IndianOpinion\GenQueue\StorageException;

/**
 * In-memory table double with the same semantics as the database store:
 * conditional updates, unique (type, idempotency_key), atomic lock rows and
 * a clock that stands in for database time.
 */
final class MemoryStore implements Store
{
    public int $clock = 1767225600; // 2026-01-01 00:00:00 UTC

    /** @var array<int, array<string, mixed>> */
    public array $jobs = [];

    /** @var array<int, array<string, mixed>> */
    public array $attempts = [];

    /** @var array<string, array{owner: string, locked_until: int}> */
    public array $locks = [];

    /** @var array<string, string> */
    public array $options = [];

    /** @var array<string, bool> */
    public array $autoload = [];

    public int $transactions = 0;

    public int $queries = 0;

    /** @var array<string, int> method => calls left before it throws */
    private array $failures = [];

    /** @var (callable(array<string, mixed>): void)|null */
    public $beforeInsertJob = null;

    /** @var array<string, list<string>>|null */
    public ?array $columnsOverride = null;

    public bool $schemaInstalled = false;

    private int $nextJobId = 1;

    private int $nextAttemptId = 1;

    /** @var array<string, mixed>|null */
    private ?array $snapshot = null;

    /** Make the named method throw StorageException on its next call. */
    public function failNext(string $method): void
    {
        $this->failures[$method] = 1;
    }

    private function tick(string $method): void
    {
        $this->queries++;
        if (isset($this->failures[$method])) {
            unset($this->failures[$method]);
            throw new StorageException('Simulated database failure in ' . $method . '.');
        }
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return [
            'jobs' => $this->jobs,
            'attempts' => $this->attempts,
            'locks' => $this->locks,
            'options' => $this->options,
            'nextJobId' => $this->nextJobId,
            'nextAttemptId' => $this->nextAttemptId,
        ];
    }

    /** @param array<string, mixed> $state */
    public function restore(array $state): void
    {
        $this->jobs = $state['jobs'];
        $this->attempts = $state['attempts'];
        $this->locks = $state['locks'];
        $this->options = $state['options'];
        $this->nextJobId = $state['nextJobId'];
        $this->nextAttemptId = $state['nextAttemptId'];
    }

    public function now(): int
    {
        return $this->clock;
    }

    public function getOption(string $name): ?string
    {
        $this->tick(__FUNCTION__);
        return $this->options[$name] ?? null;
    }

    public function getOptionsLike(string $prefix): array
    {
        $this->tick(__FUNCTION__);
        $out = [];
        foreach ($this->options as $name => $value) {
            if (str_starts_with($name, $prefix)) {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    public function setOption(string $name, string $value, bool $autoload = false): void
    {
        $this->tick(__FUNCTION__);
        $this->options[$name] = $value;
        $this->autoload[$name] = $autoload;
    }

    public function deleteOption(string $name): void
    {
        $this->tick(__FUNCTION__);
        unset($this->options[$name], $this->autoload[$name]);
    }

    public function deleteOptionsLike(string $prefix): int
    {
        $this->tick(__FUNCTION__);
        $n = 0;
        foreach (array_keys($this->options) as $name) {
            if (str_starts_with($name, $prefix)) {
                unset($this->options[$name], $this->autoload[$name]);
                $n++;
            }
        }
        return $n;
    }

    public function acquireLock(string $name, string $owner, int $until, int $now): bool
    {
        $this->tick(__FUNCTION__);
        $lock = $this->locks[$name] ?? ['owner' => '', 'locked_until' => 0];
        if ($lock['owner'] !== '' && $lock['locked_until'] >= $now && $lock['owner'] !== $owner) {
            return false;
        }
        $this->locks[$name] = ['owner' => $owner, 'locked_until' => $until];
        return true;
    }

    public function releaseLock(string $name, string $owner): void
    {
        $this->tick(__FUNCTION__);
        if (isset($this->locks[$name]) && $this->locks[$name]['owner'] === $owner) {
            $this->locks[$name] = ['owner' => '', 'locked_until' => 0];
        }
    }

    public function begin(): void
    {
        $this->tick(__FUNCTION__);
        $this->transactions++;
        $this->snapshot = $this->state();
    }

    public function commit(): void
    {
        $this->tick(__FUNCTION__);
        $this->snapshot = null;
    }

    public function rollback(): void
    {
        $this->tick(__FUNCTION__);
        if ($this->snapshot !== null) {
            $this->restore($this->snapshot);
            $this->snapshot = null;
        }
    }

    public function insertJob(array $row): ?int
    {
        $this->tick(__FUNCTION__);
        if ($this->beforeInsertJob !== null) {
            $callback = $this->beforeInsertJob;
            $this->beforeInsertJob = null;
            $callback($row);
        }
        if (($row['idempotency_key'] ?? null) !== null) {
            foreach ($this->jobs as $job) {
                if ($job['type'] === $row['type'] && $job['idempotency_key'] === $row['idempotency_key']) {
                    return null;
                }
            }
        }
        $id = $this->nextJobId++;
        $full = array_fill_keys(self::JOB_COLUMNS, null);
        foreach ($row as $key => $value) {
            $full[$key] = $value;
        }
        $full['id'] = $id;
        foreach (['attempts', 'estimate_nanos', 'cost_nanos', 'cancel_requested', 'priority', 'created_by'] as $int) {
            $full[$int] = (int) ($full[$int] ?? 0);
        }
        $this->jobs[$id] = $full;
        return $id;
    }

    public function getJob(int $id): ?array
    {
        $this->tick(__FUNCTION__);
        return $this->jobs[$id] ?? null;
    }

    public function findJobByKey(string $type, string $key): ?array
    {
        $this->tick(__FUNCTION__);
        foreach ($this->jobs as $job) {
            if ($job['type'] === $type && $job['idempotency_key'] === $key) {
                return $job;
            }
        }
        return null;
    }

    public function updateJob(int $id, array $set, array $where = [], array $increment = []): int
    {
        $this->tick(__FUNCTION__);
        if (!isset($this->jobs[$id])) {
            return 0;
        }
        foreach ($where as $column => $value) {
            if ($this->jobs[$id][$column] !== $value) {
                return 0;
            }
        }
        foreach ($set as $column => $value) {
            $this->jobs[$id][$column] = $value;
        }
        foreach ($increment as $column => $delta) {
            $this->jobs[$id][$column] = max(0, (int) $this->jobs[$id][$column] + $delta);
        }
        return 1;
    }

    public function nextCandidates(int $now, array $types, array $exclude, int $limit): array
    {
        $this->tick(__FUNCTION__);
        $rows = [];
        foreach ($this->jobs as $job) {
            if ($job['status'] !== 'queued' || $job['run_after'] > $now) {
                continue;
            }
            if ($job['expires_at'] !== null && $job['expires_at'] <= $now) {
                continue;
            }
            if (
                !in_array($job['type'], $types, true)
                || in_array($job['type'], $exclude['paused'] ?? [], true)
                || in_array($job['resource_key'], $exclude['resources'] ?? [], true)
                || in_array($job['group_key'], $exclude['groups'] ?? [], true)
                || in_array($job['id'], $exclude['ids'] ?? [], true)
            ) {
                continue;
            }
            $rows[] = $job;
        }
        usort($rows, static function (array $a, array $b): int {
            return [$b['priority'], $a['run_after'], $a['id']] <=> [$a['priority'], $b['run_after'], $b['id']];
        });
        return array_slice($rows, 0, $limit);
    }

    public function hasQueued(): bool
    {
        $this->tick(__FUNCTION__);
        foreach ($this->jobs as $job) {
            if ($job['status'] === 'queued') {
                return true;
            }
        }
        return false;
    }

    public function reservedNanos(?string $type = null): int
    {
        $this->tick(__FUNCTION__);
        $sum = 0;
        foreach ($this->jobs as $job) {
            if ($job['status'] === 'running' && ($type === null || $job['type'] === $type)) {
                $sum += (int) $job['estimate_nanos'];
            }
        }
        return $sum;
    }

    public function leaseExpired(int $now, int $limit): array
    {
        $this->tick(__FUNCTION__);
        $rows = [];
        foreach ($this->jobs as $job) {
            if ($job['status'] === 'running' && $job['lease_until'] !== null && $job['lease_until'] < $now) {
                $rows[] = $job;
                if (count($rows) >= $limit) {
                    break;
                }
            }
        }
        return $rows;
    }

    public function expiryDue(int $now, int $limit): array
    {
        $this->tick(__FUNCTION__);
        $rows = [];
        foreach ($this->jobs as $job) {
            if ($job['status'] === 'queued' && $job['expires_at'] !== null && $job['expires_at'] <= $now) {
                $rows[] = $job;
                if (count($rows) >= $limit) {
                    break;
                }
            }
        }
        return $rows;
    }

    public function markExpired(array $ids, int $now): int
    {
        $this->tick(__FUNCTION__);
        $n = 0;
        foreach ($ids as $id) {
            if (isset($this->jobs[$id]) && $this->jobs[$id]['status'] === 'queued') {
                $this->jobs[$id]['status'] = 'expired';
                $this->jobs[$id]['finished_at'] = $now;
                $this->jobs[$id]['updated_at'] = $now;
                $n++;
            }
        }
        return $n;
    }

    public function purgeTerminal(int $before, int $limit): int
    {
        $this->tick(__FUNCTION__);
        $ids = [];
        foreach ($this->jobs as $id => $job) {
            if (in_array($job['status'], self::TERMINAL, true) && $job['finished_at'] !== null && $job['finished_at'] < $before) {
                $ids[] = $id;
                if (count($ids) >= $limit) {
                    break;
                }
            }
        }
        foreach ($ids as $id) {
            unset($this->jobs[$id]);
        }
        foreach ($this->attempts as $aid => $attempt) {
            if (in_array($attempt['job_id'], $ids, true)) {
                unset($this->attempts[$aid]);
            }
        }
        return count($ids);
    }

    public function countTerminalBefore(int $before): int
    {
        $this->tick(__FUNCTION__);
        $n = 0;
        foreach ($this->jobs as $job) {
            if (in_array($job['status'], self::TERMINAL, true) && $job['finished_at'] !== null && $job['finished_at'] < $before) {
                $n++;
            }
        }
        return $n;
    }

    public function listJobs(array $filter, int $limit, int $afterId = 0): array
    {
        $this->tick(__FUNCTION__);
        $map = ['status' => 'status', 'type' => 'type', 'source' => 'source', 'group' => 'group_key'];
        $rows = [];
        foreach ($this->jobs as $job) {
            if ($afterId > 0 && $job['id'] <= $afterId) {
                continue;
            }
            foreach ($filter as $key => $value) {
                if ($job[$map[$key]] !== $value) {
                    continue 2;
                }
            }
            $rows[] = $job;
        }
        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        return array_slice($rows, 0, $limit);
    }

    public function countsByStatusType(): array
    {
        $this->tick(__FUNCTION__);
        $counts = [];
        foreach ($this->jobs as $job) {
            $key = $job['status'] . "\0" . $job['type'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $out = [];
        foreach ($counts as $key => $n) {
            [$status, $type] = explode("\0", $key);
            $out[] = ['status' => $status, 'type' => $type, 'n' => $n];
        }
        return $out;
    }

    public function recentFailures(int $limit): array
    {
        $this->tick(__FUNCTION__);
        $rows = array_values(array_filter($this->jobs, static fn (array $j): bool => $j['status'] === 'failed'));
        usort($rows, static fn (array $a, array $b): int => [$b['finished_at'], $b['id']] <=> [$a['finished_at'], $a['id']]);
        return array_slice($rows, 0, $limit);
    }

    public function jobsByCreator(int $userId, int $limit, int $offset): array
    {
        $this->tick(__FUNCTION__);
        $rows = array_values(array_filter($this->jobs, static fn (array $j): bool => (int) $j['created_by'] === $userId));
        return array_slice($rows, $offset, $limit);
    }

    public function eraseCreator(int $userId): int
    {
        $this->tick(__FUNCTION__);
        $n = 0;
        foreach ($this->jobs as $id => $job) {
            if ((int) $job['created_by'] === $userId) {
                $this->jobs[$id]['created_by'] = 0;
                $n++;
            }
        }
        return $n;
    }

    public function insertAttempt(array $row): int
    {
        $this->tick(__FUNCTION__);
        $id = $this->nextAttemptId++;
        $full = array_fill_keys(self::ATTEMPT_COLUMNS, null);
        foreach ($row as $key => $value) {
            $full[$key] = $value;
        }
        foreach (['cost_nanos', 'input_units', 'output_units', 'metered', 'duration_ms'] as $int) {
            $full[$int] = (int) ($full[$int] ?? 0);
        }
        $full['id'] = $id;
        $this->attempts[$id] = $full;
        return $id;
    }

    public function updateAttempt(int $id, array $set, array $increment = []): int
    {
        $this->tick(__FUNCTION__);
        if (!isset($this->attempts[$id])) {
            return 0;
        }
        foreach ($set as $column => $value) {
            $this->attempts[$id][$column] = $value;
        }
        foreach ($increment as $column => $delta) {
            $this->attempts[$id][$column] = max(0, (int) $this->attempts[$id][$column] + $delta);
        }
        return 1;
    }

    public function attemptsForJob(int $jobId): array
    {
        $this->tick(__FUNCTION__);
        return array_values(array_filter($this->attempts, static fn (array $a): bool => $a['job_id'] === $jobId));
    }

    public function openAttempt(int $jobId): ?array
    {
        $this->tick(__FUNCTION__);
        $open = null;
        foreach ($this->attempts as $attempt) {
            if ($attempt['job_id'] === $jobId && $attempt['outcome'] === 'started') {
                $open = $attempt;
            }
        }
        return $open;
    }

    public function spendForDay(string $day, ?string $type = null): int
    {
        $this->tick(__FUNCTION__);
        $sum = 0;
        foreach ($this->attempts as $attempt) {
            if ($attempt['cost_day'] === $day && ($type === null || $attempt['type'] === $type)) {
                $sum += (int) $attempt['cost_nanos'];
            }
        }
        return $sum;
    }

    public function statsSince(string $sinceDay): array
    {
        $this->tick(__FUNCTION__);
        $stats = [];
        foreach ($this->attempts as $a) {
            if ($a['cost_day'] < $sinceDay || $a['outcome'] === 'skipped') {
                continue;
            }
            $key = $a['cost_day'] . "\0" . $a['type'];
            $stats[$key] ??= ['day' => $a['cost_day'], 'type' => $a['type'], 'attempts' => 0, 'succeeded' => 0, 'failed' => 0, 'cost_nanos' => 0];
            $stats[$key]['attempts']++;
            if ($a['outcome'] === 'success') {
                $stats[$key]['succeeded']++;
            }
            if (in_array($a['outcome'], ['retry', 'failed', 'permanent', 'abandoned'], true)) {
                $stats[$key]['failed']++;
            }
            $stats[$key]['cost_nanos'] += (int) $a['cost_nanos'];
        }
        ksort($stats);
        return array_values($stats);
    }

    public function countSlowAttempts(string $type, int $ms, int $since): int
    {
        $this->tick(__FUNCTION__);
        $n = 0;
        foreach ($this->attempts as $a) {
            if ($a['type'] === $type && $a['started_at'] >= $since && $a['duration_ms'] > $ms) {
                $n++;
            }
        }
        return $n;
    }

    public function installSchema(): void
    {
        $this->tick(__FUNCTION__);
        $this->schemaInstalled = true;
    }

    public function columns(): array
    {
        $this->tick(__FUNCTION__);
        if ($this->columnsOverride !== null) {
            return $this->columnsOverride;
        }
        if (!$this->schemaInstalled) {
            return ['jobs' => [], 'attempts' => [], 'locks' => []];
        }
        return ['jobs' => self::JOB_COLUMNS, 'attempts' => self::ATTEMPT_COLUMNS, 'locks' => self::LOCK_COLUMNS];
    }

    public function dropSchema(): void
    {
        $this->tick(__FUNCTION__);
        $this->schemaInstalled = false;
        $this->jobs = [];
        $this->attempts = [];
        $this->locks = [];
    }
}
