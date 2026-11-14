<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

use IndianOpinion\GenQueue\Storage\Store;

/**
 * Drains the queue. WP-Cron ticks and WP-CLI runs use this same code path.
 *
 * Per job: fresh read of the switch, pause list, cap and spend; pick the
 * best eligible job; under the claim lock check the global cap, the type
 * cap, gen_queue_can_run and spend-meter; claim it with one conditional
 * UPDATE; count the attempt before the handler runs; record the outcome only
 * while the lease token still matches.
 */
final class Runner
{
    public const CLAIM_LOCK = 'claim';
    public const CANDIDATES = 25;
    public const MAX_SKIPPED = 1000;

    public function __construct(
        private readonly Store $store,
        private readonly Registry $registry,
        private readonly Controls $controls,
        private readonly Breakers $breakers,
        private readonly SpendMeter $meter,
        private readonly Housekeeping $housekeeping,
    ) {
    }

    /**
     * @param array{time?: int, max?: int, type?: string, worker?: string} $opts
     */
    public function run(array $opts = []): RunSummary
    {
        $started = microtime(true);
        $summary = new RunSummary();
        $unknown = array_diff(array_keys($opts), ['time', 'max', 'type', 'worker']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown run option(s): ' . implode(', ', $unknown) . '.');
        }
        $time = isset($opts['time']) ? Validate::intRange($opts['time'], 1, 604800, 'time') : $this->controls->tickSeconds();
        $max = isset($opts['max']) ? Validate::intRange($opts['max'], 1, PHP_INT_MAX, 'max') : null;
        $only = isset($opts['type']) ? Validate::typeName($opts['type']) : null;
        $worker = isset($opts['worker']) ? Validate::label($opts['worker'], 64, 'worker') : self::workerId();
        $this->meter->resetLog();

        // The idle fast path: two reads when there is nothing to do (section 9).
        $probe = $this->store->probe();
        $this->clockAtStart = $probe['now'];
        $raw = $this->store->getOptions([Controls::OPT_ENABLED, Controls::OPT_CAP, Controls::OPT_PURGE_DAY]);
        $enabled = $this->controls->enabledFromRaw($raw[Controls::OPT_ENABLED] ?? null);
        $idle = !$probe['due'] && !$probe['stale'] && !($enabled && $probe['expiring'])
            && ($raw[Controls::OPT_PURGE_DAY] ?? null) === Days::local($probe['now']);
        if ($idle) {
            if (!$enabled) {
                return $this->finish($summary, 'disabled', $started);
            }
            $cap = $this->controls->capFromRaw($raw[Controls::OPT_CAP] ?? null);
            if ($cap === null || $cap === 0) {
                return $this->finish($summary, 'capped', $started);
            }
            return $this->finish($summary, $probe['queued'] ? 'waiting' : 'empty', $started);
        }

        $this->housekeeping->run($worker);

        if (!$this->controls->enabled()) {
            return $this->finish($summary, 'disabled', $started);
        }
        $slot = $this->takeSlot($worker, $time);
        if ($slot === null) {
            return $this->finish($summary, 'busy', $started);
        }
        try {
            $reason = $this->loop($summary, $worker, $started + $time, $max, $only);
        } finally {
            $this->store->releaseLock($slot, $worker);
        }
        return $this->finish($summary, $reason, $started);
    }

    public static function workerId(): string
    {
        $host = function_exists('gethostname') ? (string) gethostname() : 'host';
        $host = (string) preg_replace('/[^A-Za-z0-9_.-]/', '-', $host);
        return substr($host, 0, 32) . ':' . getmypid() . ':' . bin2hex(random_bytes(3));
    }

    private function finish(RunSummary $summary, string $reason, float $started): RunSummary
    {
        $summary->stopReason = $reason;
        $summary->seconds = microtime(true) - $started;
        $this->finishedAt = $this->clockAtStart === null ? null : $this->clockAtStart + (int) round($summary->seconds);
        return $summary;
    }

    private ?int $clockAtStart = null;

    private ?int $finishedAt = null;

    /** Database time at which the last run finished (start clock plus elapsed time), or null. */
    public function lastFinishedAt(): ?int
    {
        return $this->finishedAt;
    }

    /**
     * One of max_workers slot rows, or null when all are held (busy). The
     * slot lease covers the budget or the longest registered timeout,
     * whichever is longer, since a run's first job may outlast the budget.
     */
    private function takeSlot(string $worker, int $time): ?string
    {
        $now = $this->store->now();
        $workers = $this->controls->setting('max_workers');
        $longest = $time;
        foreach ($this->registry->all() as $type) {
            $longest = max($longest, $type->timeout + 60);
        }
        for ($i = 1; $i <= $workers; $i++) {
            $name = 'worker-' . $i;
            if ($this->store->acquireLock($name, $worker, $now + $longest + 300, $now)) {
                return $name;
            }
        }
        return null;
    }

    private function loop(RunSummary $summary, string $worker, float $deadline, ?int $max, ?string $only): string
    {
        $skip = [];
        $cappedTypes = [];
        $capped = false;
        while (true) {
            if ($max !== null && $summary->claimed >= $max) {
                return 'max';
            }
            // Fresh reads, every job. Nothing here comes from a cache.
            if (!$this->controls->enabled()) {
                return 'disabled';
            }
            $cap = $this->controls->capNanos();
            if ($cap === null || $cap === 0) {
                // No cap means nothing runs (case 29, rule 14.16); never consult the meter (case 74).
                return 'capped';
            }
            $now = $this->store->now();
            $types = $this->registry->names();
            if ($only !== null) {
                $types = in_array($only, $types, true) ? [$only] : [];
            }
            $candidates = [];
            if ($types !== []) {
                $candidates = $this->store->nextCandidates($now, $types, [
                    'paused' => array_values(array_unique(array_merge($this->controls->pausedTypes(), $cappedTypes))),
                    'resources' => array_map('strval', array_keys($this->breakers->cooling($now))),
                    'groups' => array_map('strval', array_keys($this->breakers->activeHolds($now))),
                    'ids' => $skip,
                ], self::CANDIDATES);
            }
            if ($candidates === []) {
                if ($capped) {
                    return 'capped';
                }
                return $this->store->hasQueued() ? 'waiting' : 'empty';
            }

            $claim = null;
            foreach ($candidates as $row) {
                $type = $this->registry->get((string) $row['type']);
                if ($type === null) {
                    continue;
                }
                // Case 27, with one departure: a runner's first job always
                // starts, even when its timeout is longer than the whole
                // budget, or the default 120 s timeout would never fit a
                // 25 s WP-Cron tick. After the first job the rule is strict.
                $left = $deadline - microtime(true);
                if ($summary->claimed > 0 && $type->timeout > $left) {
                    return 'time';
                }
                $result = $this->tryClaim($row, $type, $worker, $cap);
                if (is_array($result)) {
                    $claim = $result + ['type' => $type];
                    break;
                }
                switch ($result) {
                    case 'busy':
                        return 'busy';
                    case 'stop':
                        return 'disabled';
                    case 'capped_all':
                        // Spent and reserved already reach the cap: nothing else fits either.
                        return 'capped';
                    case 'type_capped':
                        // This type's own cap is used up: skip its jobs for the rest of the run.
                        $capped = true;
                        $cappedTypes[] = $type->name;
                        break 2;
                    case 'capped':
                        $capped = true;
                        $skip[] = (int) $row['id'];
                        break;
                    case 'veto':
                        $summary->skipped++;
                        $skip[] = (int) $row['id'];
                        break;
                    default:
                        // Lost the race to another runner: pick again.
                        break;
                }
                if (count($skip) >= self::MAX_SKIPPED) {
                    return $capped ? 'capped' : 'waiting';
                }
            }
            if ($claim === null) {
                continue;
            }
            $stop = $this->execute($claim['row'], $claim['type'], $claim['token'], $worker, $summary);
            if ($stop !== null) {
                return $stop;
            }
        }
    }

    /**
     * Cap checks and the atomic claim, under the claim lock.
     *
     * @param array<string, mixed> $row
     * @return array{row: array<string, mixed>, token: string}|string
     */
    private function tryClaim(array $row, JobType $type, string $worker, int $cap): array|string
    {
        if (!$this->claimLock($worker)) {
            return 'busy';
        }
        try {
            $now = $this->store->now();
            $day = Days::local($now);
            $estimate = (int) $row['estimate_nanos'];

            // Re-read spend immediately before the check so concurrent runners accumulate.
            $spent = $this->controls->spendToday($day);
            $reserved = $this->store->reservedNanos();
            if (!self::fits($spent + $reserved, $estimate, $cap)) {
                $this->capReached($spent, $cap, $day);
                return self::fits($spent + $reserved, 0, $cap) ? 'capped' : 'capped_all';
            }
            if ($type->dailyCapNanos !== null) {
                $typeUsed = $this->controls->typeSpend($day, $type->name) + $this->store->reservedNanos($type->name);
                if (!self::fits($typeUsed, $estimate, $type->dailyCapNanos)) {
                    return self::fits($typeUsed, 0, $type->dailyCapNanos) ? 'capped' : 'type_capped';
                }
            }
            // The candidate list was read before this lock: a cooldown or an
            // account hold may have started since. Re-read both for this row.
            if (
                $this->breakers->coolingUntil((string) $row['resource_key'], $now) !== null
                || isset($this->breakers->activeHolds($now)[(string) $row['group_key']])
            ) {
                return 'lost';
            }
            $job = Job::fromRow($row);
            if (apply_filters('gen_queue_can_run', true, $job) !== true) {
                return 'veto';
            }
            $denial = $this->meter->check($job, $estimate, $type);
            if ($denial !== null) {
                $this->noteDenial($denial, $now);
                return $denial === 'kill_switch' ? 'stop' : 'capped';
            }

            $token = bin2hex(random_bytes(16));
            $set = [
                'status' => 'running',
                'lease_token' => $token,
                'lease_until' => $now + $type->timeout + 60,
                'worker' => $worker,
                'updated_at' => $now,
            ];
            // Claim only the row as it was selected: a job that ran, failed
            // and went back to the queue since has new attempts or a new
            // run_after (backoff), so a stale candidate never wins.
            $where = ['status' => 'queued', 'attempts' => (int) $row['attempts'], 'run_after' => (int) $row['run_after']];
            if ($this->store->updateJob((int) $row['id'], $set, $where, ['attempts' => 1]) !== 1) {
                return 'lost';
            }
            $row = array_merge($row, $set);
            $row['attempts'] = (int) $row['attempts'] + 1;
            return ['row' => $row, 'token' => $token];
        } finally {
            $this->store->releaseLock(self::CLAIM_LOCK, $worker);
        }
    }

    /**
     * spent + reserved + estimate must fit under the cap. A zero estimate
     * runs only while the total is strictly below the cap.
     */
    public static function fits(int $used, int $estimate, int $cap): bool
    {
        if ($estimate === 0) {
            return $used < $cap;
        }
        return $used + $estimate <= $cap;
    }

    private function claimLock(string $worker): bool
    {
        for ($i = 0; $i < 50; $i++) {
            $now = $this->store->now();
            if ($this->store->acquireLock(self::CLAIM_LOCK, $worker, $now + 30, $now)) {
                return true;
            }
            usleep(20000);
        }
        return false;
    }

    private function capReached(int $spent, int $cap, string $day): void
    {
        if ($this->store->getOption(Controls::OPT_CAP_NOTICE_DAY) === $day) {
            return;
        }
        $this->store->setOption(Controls::OPT_CAP_NOTICE_DAY, $day);
        Log::notice(sprintf('Daily cap reached for %s: spent %s of %s. Jobs wait for tomorrow or a higher cap.', $day, Money::format($spent), Money::format($cap)));
        do_action('gen_queue_cap_reached', Money::format($spent), Money::format($cap), $day);
    }

    /** @var array<string, true> */
    private array $deniedThisRun = [];

    private function noteDenial(string $reason, int $now): void
    {
        if (isset($this->deniedThisRun[$reason])) {
            return;
        }
        $this->deniedThisRun[$reason] = true;
        $this->controls->setJsonOption(Controls::OPT_LAST_DENIAL, ['reason' => $reason, 'at' => $now]);
    }

    /**
     * Run one claimed job. Returns a stop reason, or null to carry on.
     *
     * @param array<string, mixed> $row the claimed row
     */
    private function execute(array $row, JobType $type, string $token, string $worker, RunSummary $summary): ?string
    {
        $id = (int) $row['id'];
        $now = $this->store->now();
        $attemptNo = (int) $row['attempts'];
        $day = Days::local($now);

        // Count the attempt before the call, so a fatal cannot cause a paid retry loop.
        $attemptId = $this->store->insertAttempt([
            'job_id' => $id,
            'attempt_no' => $attemptNo,
            'type' => $type->name,
            'group_key' => $row['group_key'],
            'resource_key' => $row['resource_key'],
            'outcome' => 'started',
            'cost_nanos' => 0,
            'cost_day' => $day,
            'input_units' => 0,
            'output_units' => 0,
            'metered' => 0,
            'duration_ms' => 0,
            'started_at' => $now,
        ]);
        $job = Job::fromRow($row);
        do_action('gen_queue_claimed', $job, $attemptNo, $worker);

        // A deliberate stop never consumes an attempt.
        $enabled = $this->controls->enabled();
        if (!$enabled || in_array($type->name, $this->controls->pausedTypes(), true)) {
            $this->store->updateJob($id, [
                'status' => 'queued',
                'lease_token' => null,
                'lease_until' => null,
                'worker' => null,
                'updated_at' => $this->store->now(),
            ], ['status' => 'running', 'lease_token' => $token], ['attempts' => -1]);
            $this->store->updateAttempt($attemptId, ['outcome' => 'skipped', 'finished_at' => $this->store->now()]);
            $summary->skipped++;
            return $enabled ? null : 'disabled';
        }
        $summary->claimed++;

        $written = 0;
        $ctx = new JobContext(
            $job,
            $attemptNo,
            $now + $type->timeout,
            function (array $record) use ($attemptId, $id, &$written, $summary): void {
                $summary->addCost($record['amount_nanos']);
                try {
                    $this->store->updateAttempt($attemptId, ['metered' => $record['metered'] ? 1 : 0] + ($record['usage_ref'] !== null ? ['usage_ref' => $record['usage_ref']] : []), [
                        'cost_nanos' => $record['amount_nanos'],
                        'input_units' => $record['input_units'],
                        'output_units' => $record['output_units'],
                    ]);
                    $this->store->updateJob($id, [], [], ['cost_nanos' => $record['amount_nanos']]);
                    $written += $record['amount_nanos'];
                } catch (StorageException $e) {
                    Log::warning(sprintf('Could not write a cost for job %d yet; it is written when the attempt closes.', $id));
                }
            },
            function () use ($id): bool {
                $fresh = $this->store->getJob($id);
                return $fresh !== null && (int) $fresh['cancel_requested'] === 1;
            },
            fn (): ?string => $this->capRemaining(),
        );

        $returned = null;
        $error = null;
        $t0 = hrtime(true);
        try {
            $returned = ($type->handler)($job, $ctx);
        } catch (\Throwable $e) {
            $error = $e;
        }
        $durationMs = (int) min(4294967295, intdiv(hrtime(true) - $t0, 1000000));

        $result = null;
        if ($error === null) {
            if ($returned === null) {
                $result = JobResult::ok();
            } elseif ($returned instanceof JobResult) {
                $result = $returned;
            } elseif (is_array($returned)) {
                $result = JobResult::ok($returned);
            } else {
                $error = new \UnexpectedValueException('Handler returned ' . get_debug_type($returned) . '; return a JobResult, an array or null.');
            }
        }

        $this->close($row, $type, $token, $attemptId, $ctx, $written, $result, $error, $durationMs, $summary);
        return null;
    }

    /**
     * Record the outcome, only while the lease token still matches.
     *
     * @param array<string, mixed> $row
     */
    private function close(
        array $row,
        JobType $type,
        string $token,
        int $attemptId,
        JobContext $ctx,
        int $written,
        ?JobResult $result,
        ?\Throwable $error,
        int $durationMs,
        RunSummary $summary,
    ): void {
        $id = (int) $row['id'];
        $now = $this->store->now();
        $current = $this->store->getJob($id);
        $cancelRequested = $current !== null && (int) $current['cancel_requested'] === 1;
        $attempts = (int) $row['attempts'];
        $maxAttempts = (int) ($current['max_attempts'] ?? $row['max_attempts']);

        $set = ['lease_token' => null, 'lease_until' => null, 'worker' => null, 'updated_at' => $now];
        $increment = [];
        $resultJson = null;
        if ($result !== null) {
            $resultJson = $this->encodeResult($id, $result);
        }

        if ($error === null && !$cancelRequested) {
            $outcome = 'success';
            $set += ['status' => 'succeeded', 'result' => $resultJson, 'finished_at' => $now, 'last_error' => null, 'last_error_class' => null];
        } elseif ($error === null || $error instanceof CancelledException || $cancelRequested) {
            $outcome = 'cancelled';
            $set += ['status' => 'cancelled', 'finished_at' => $now, 'last_error_class' => 'cancelled'];
            if ($resultJson !== null) {
                $set['result'] = $resultJson;
            }
            if ($error !== null) {
                $set['last_error'] = self::message($error);
            }
        } elseif ($error instanceof AccountException) {
            $outcome = 'account';
            $set += ['status' => 'queued', 'last_error' => self::message($error), 'last_error_class' => 'account'];
            $increment['attempts'] = -1;
        } elseif ($error instanceof PermanentException) {
            $outcome = 'permanent';
            $set += ['status' => 'failed', 'finished_at' => $now, 'last_error' => self::message($error), 'last_error_class' => 'permanent'];
        } elseif ($error instanceof RetryableException) {
            $set += ['last_error' => self::message($error), 'last_error_class' => 'retryable'];
            if ($attempts < $maxAttempts) {
                $outcome = 'retry';
                $delay = Backoff::delay($type->backoffBase, $type->backoffMax, $attempts, $error->retryAfter(), Job::fromRow($current ?? $row), $error);
                $set += ['status' => 'queued', 'run_after' => $now + $delay];
            } else {
                $outcome = 'failed';
                $set += ['status' => 'failed', 'finished_at' => $now];
            }
        } else {
            // Unexpected Throwable: a programming error, not a provider fault.
            // Not retried; only the class name is stored, never the message.
            $outcome = 'failed';
            $set += ['status' => 'failed', 'finished_at' => $now, 'last_error' => get_class($error), 'last_error_class' => 'exception'];
        }

        $costs = $ctx->costs();
        $total = array_sum(array_column($costs, 'amount_nanos'));
        if ($total > $written) {
            $increment['cost_nanos'] = $total - $written;
        }

        if ($outcome === 'account' && $error !== null) {
            // Hold the group before the job goes back to the queue, so no
            // other runner claims a job of this account in between.
            $this->breakers->hold((string) $row['group_key'], $now, self::message($error));
        }
        $changed = $this->store->updateJob($id, $set, ['status' => 'running', 'lease_token' => $token], $increment);
        if ($changed !== 1) {
            // The lease expired and the job was reclaimed. Keep the money, discard the rest.
            if ($total > $written) {
                $this->store->updateJob($id, [], [], ['cost_nanos' => $total - $written]);
            }
            $this->store->updateAttempt($attemptId, self::costColumns($costs));
            Log::notice(sprintf('Discarded a late completion of job %d: its lease had expired and it was reclaimed.', $id));
            $this->forward($row, $type, $attemptId, $ctx, false);
            return;
        }

        $attemptError = $error === null ? null : ($outcome === 'failed' && !($error instanceof GenQueueException) ? get_class($error) : self::message($error));
        $this->store->updateAttempt($attemptId, [
            'outcome' => $outcome,
            'finished_at' => $now,
            'duration_ms' => $durationMs,
            'error' => $attemptError,
            'log' => $ctx->logText() === '' ? null : $ctx->logText(),
            'resource_key' => $ctx->resourceKey(),
        ] + self::costColumns($costs));

        $fresh = $this->store->getJob($id);
        $after = Job::fromRow($fresh ?? array_merge($row, $set));

        switch ($outcome) {
            case 'success':
                $summary->succeeded++;
                $this->breakers->success($ctx->resourceKey());
                do_action('gen_queue_completed', $after, $result ?? JobResult::ok());
                break;
            case 'cancelled':
                $summary->cancelled++;
                do_action('gen_queue_cancelled', $after);
                break;
            case 'account':
                $summary->retried++;
                break;
            case 'permanent':
                $summary->failed++;
                do_action('gen_queue_failed', $after, $error);
                break;
            case 'retry':
                $summary->retried++;
                $this->breakers->failure($ctx->resourceKey(), $now);
                do_action('gen_queue_retry_scheduled', $after, $error, $after->runAfter);
                break;
            default:
                $summary->failed++;
                if ($error instanceof RetryableException) {
                    $this->breakers->failure($ctx->resourceKey(), $now);
                }
                do_action('gen_queue_failed', $after, $error);
                break;
        }

        $this->forward($row, $type, $attemptId, $ctx, $outcome === 'success');
    }

    /**
     * After the attempt row is written: forward unmetered costs to
     * spend-meter, then fire gen_queue_cost_recorded for each record.
     *
     * @param array<string, mixed> $row
     */
    private function forward(array $row, JobType $type, int $attemptId, JobContext $ctx, bool $success): void
    {
        $costs = $ctx->costs();
        if ($costs === []) {
            return;
        }
        $now = $this->store->now();
        $attempt = $ctx->attempt();
        $day = null;
        foreach ($this->store->attemptsForJob((int) $row['id']) as $a) {
            if ((int) $a['id'] === $attemptId) {
                $day = (string) $a['cost_day'];
            }
        }
        $day ??= Days::local($now);
        $currency = $this->controls->currency();
        foreach ($costs as $cost) {
            $record = [
                'job_id' => (int) $row['id'],
                'attempt' => $attempt,
                'type' => $type->name,
                'group' => (string) $row['group_key'],
                'resource_key' => $ctx->resourceKey(),
                'amount' => Money::format($cost['amount_nanos']),
                'amount_nanos' => $cost['amount_nanos'],
                'currency' => $currency,
                'day' => $day,
                'input_units' => $cost['input_units'],
                'output_units' => $cost['output_units'],
                'usage_ref' => $cost['usage_ref'],
                'metered' => $cost['metered'],
                'recorded_at' => Days::utc($now),
            ];
            $this->meter->record($record, $type, $success);
            try {
                do_action('gen_queue_cost_recorded', $record);
            } catch (\Throwable $e) {
                Log::warning('A gen_queue_cost_recorded listener threw ' . get_class($e) . '.');
            }
        }
    }

    /**
     * @param list<array{amount_nanos: int, input_units: int, output_units: int, usage_ref: ?string, metered: bool}> $costs
     * @return array<string, mixed>
     */
    private static function costColumns(array $costs): array
    {
        if ($costs === []) {
            return [];
        }
        $last = $costs[count($costs) - 1];
        $ref = null;
        foreach ($costs as $cost) {
            $ref = $cost['usage_ref'] ?? $ref;
        }
        return [
            'cost_nanos' => array_sum(array_column($costs, 'amount_nanos')),
            'input_units' => array_sum(array_column($costs, 'input_units')),
            'output_units' => array_sum(array_column($costs, 'output_units')),
            'usage_ref' => $ref,
            'metered' => $last['metered'] ? 1 : 0,
        ];
    }

    private function encodeResult(int $id, JobResult $result): string
    {
        try {
            $json = json_encode($result->ref(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            Log::warning(sprintf('The result of job %d is not valid JSON data and was not stored.', $id));
            return '{"gen_queue_error":"result_not_encodable"}';
        }
        if (strlen($json) > Payload::RESULT_MAX_BYTES) {
            Log::warning(sprintf('The result of job %d is %d bytes; only references up to 16 KiB are stored.', $id, strlen($json)));
            return (string) json_encode(['gen_queue_error' => 'result_too_large', 'bytes' => strlen($json)]);
        }
        return $json;
    }

    public static function message(\Throwable $error): string
    {
        if (!$error instanceof GenQueueException) {
            return get_class($error);
        }
        return Redactor::clean($error->getMessage(), 500);
    }

    /** What is left of today's global cap, or null with no cap. */
    public function capRemaining(): ?string
    {
        $cap = $this->controls->capNanos();
        if ($cap === null) {
            return null;
        }
        $spent = $this->controls->spendToday(Days::local($this->store->now()));
        return Money::format(max(0, $cap - $spent));
    }
}
