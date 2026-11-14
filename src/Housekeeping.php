<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

use IndianOpinion\GenQueue\Storage\Store;

/**
 * Lease reclaim, expiry and the daily retention purge, bounded in time.
 */
final class Housekeeping
{
    public const LOCK = 'housekeeping';
    public const RECLAIM_BATCH = 100;
    public const EXPIRY_BATCH = 1000;
    public const PURGE_BATCH = 1000;

    public function __construct(
        private readonly Store $store,
        private readonly Registry $registry,
        private readonly Controls $controls,
    ) {
    }

    /**
     * @return array{reclaimed: int, expired: int, purged: int, ran: bool}
     */
    public function run(string $owner, float $budget = 1.0): array
    {
        $out = ['reclaimed' => 0, 'expired' => 0, 'purged' => 0, 'ran' => false];
        $stop = microtime(true) + $budget;
        $now = $this->store->now();
        if (!$this->store->acquireLock(self::LOCK, $owner, $now + 30, $now)) {
            return $out;
        }
        try {
            $out['ran'] = true;
            $out['reclaimed'] = $this->reclaim($now, $stop);
            if ($this->controls->enabled()) {
                // A deliberate pause keeps its backlog: no expiry while switched off.
                $out['expired'] = $this->expire($now, $stop);
            }
            $out['purged'] = $this->dailyPurge($now, $stop);
        } finally {
            $this->store->releaseLock(self::LOCK, $owner);
        }
        return $out;
    }

    /** Running jobs whose lease passed: the worker died. */
    public function reclaim(int $now, float $stop): int
    {
        $count = 0;
        while (microtime(true) < $stop) {
            $rows = $this->store->leaseExpired($now, self::RECLAIM_BATCH);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                if ($this->reclaimOne($row, $now)) {
                    $count++;
                }
            }
            if (count($rows) < self::RECLAIM_BATCH) {
                break;
            }
        }
        return $count;
    }

    /** @param array<string, mixed> $row */
    private function reclaimOne(array $row, int $now): bool
    {
        $id = (int) $row['id'];
        $job = Job::fromRow($row);
        $type = $this->registry->get($job->type);
        $error = new RetryableException('Lease expired before the attempt finished; the worker is presumed dead.');
        $set = [
            'lease_token' => null,
            'lease_until' => null,
            'worker' => null,
            'last_error' => $error->getMessage(),
            'last_error_class' => 'abandoned',
            'updated_at' => $now,
        ];
        if ($job->cancelRequested) {
            $status = 'cancelled';
            $set += ['status' => 'cancelled', 'finished_at' => $now];
            $set['last_error_class'] = 'cancelled';
        } elseif ($job->attempts < $job->maxAttempts) {
            $status = 'queued';
            $delay = Backoff::delay($type->backoffBase ?? 30, $type->backoffMax ?? 3600, max(1, $job->attempts), null, $job, $error);
            $set += ['status' => 'queued', 'run_after' => $now + $delay];
        } else {
            $status = 'failed';
            $set += ['status' => 'failed', 'finished_at' => $now];
        }
        $changed = $this->store->updateJob($id, $set, ['status' => 'running', 'lease_token' => $row['lease_token']]);
        if ($changed !== 1) {
            return false;
        }
        $open = $this->store->openAttempt($id);
        if ($open !== null) {
            $started = (int) ($open['started_at'] ?? $now);
            $this->store->updateAttempt((int) $open['id'], [
                'outcome' => 'abandoned',
                'finished_at' => $now,
                'duration_ms' => max(0, $now - $started) * 1000,
                'error' => $error->getMessage(),
            ]);
        }
        $fresh = $this->store->getJob($id);
        $after = $fresh === null ? $job : Job::fromRow($fresh);
        if ($status === 'queued') {
            do_action('gen_queue_retry_scheduled', $after, $error, $after->runAfter);
        } elseif ($status === 'failed') {
            do_action('gen_queue_failed', $after, $error);
        } else {
            do_action('gen_queue_cancelled', $after);
        }
        return true;
    }

    /** Queued jobs past their expiry become expired, in batches. */
    public function expire(int $now, float $stop): int
    {
        $count = 0;
        $listening = !function_exists('has_action') || has_action('gen_queue_expired') !== false;
        while (microtime(true) < $stop) {
            $rows = $this->store->expiryDue($now, self::EXPIRY_BATCH);
            if ($rows === []) {
                break;
            }
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $count += $this->store->markExpired($ids, $now);
            if ($listening) {
                foreach ($rows as $row) {
                    $row['status'] = 'expired';
                    $row['finished_at'] = $now;
                    do_action('gen_queue_expired', Job::fromRow($row));
                }
            }
            if (count($rows) < self::EXPIRY_BATCH) {
                break;
            }
        }
        return $count;
    }

    /** Once a day, purge terminal jobs older than the retention period. */
    private function dailyPurge(int $now, float $stop): int
    {
        $today = Days::local($now);
        if ($this->store->getOption(Controls::OPT_PURGE_DAY) === $today) {
            return 0;
        }
        $days = $this->controls->retentionDays();
        if ($days === 0) {
            $this->store->setOption(Controls::OPT_PURGE_DAY, $today);
            return 0;
        }
        $count = 0;
        $done = false;
        while (microtime(true) < $stop) {
            $n = $this->store->purgeTerminal($now - $days * 86400, self::PURGE_BATCH);
            $count += $n;
            if ($n < self::PURGE_BATCH) {
                $done = true;
                break;
            }
        }
        if ($done) {
            $this->store->setOption(Controls::OPT_PURGE_DAY, $today);
        }
        return $count;
    }

    /**
     * Purge on demand (WP-CLI). Returns the number of jobs removed, or that
     * would be removed with $dryRun.
     */
    public function purge(int $olderThanDays, bool $dryRun): int
    {
        $before = $this->store->now() - max(0, $olderThanDays) * 86400;
        if ($dryRun) {
            return $this->store->countTerminalBefore($before);
        }
        $count = 0;
        do {
            $n = $this->store->purgeTerminal($before, self::PURGE_BATCH);
            $count += $n;
        } while ($n === self::PURGE_BATCH);
        return $count;
    }
}
