<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Cli;

use IndianOpinion\GenQueue\GenQueueException;
use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\Money;
use IndianOpinion\GenQueue\Plugin;
use IndianOpinion\GenQueue\RunSummary;

/**
 * Run, inspect and steer the generation queue.
 *
 * Exit codes: 0 on success (including a run that stopped because the queue
 * was empty or every job was waiting), 1 on error, 3 when a run stopped
 * because the queue is switched off, 4 when it stopped at the cap.
 */
final class Command
{
    public const EXIT_DISABLED = 3;
    public const EXIT_CAPPED = 4;

    private Io $io;

    public function __construct(private readonly Plugin $plugin, ?Io $io = null)
    {
        $this->io = $io ?? new WpCliIo();
    }

    public static function exitCode(string $reason): int
    {
        return match ($reason) {
            'disabled' => self::EXIT_DISABLED,
            'capped' => self::EXIT_CAPPED,
            default => 0,
        };
    }

    /**
     * Drain the queue and print the summary.
     *
     * ## OPTIONS
     *
     * [--time=<seconds>]
     * : Time budget for this run. Default 300.
     *
     * [--max=<n>]
     * : Stop after this many jobs.
     *
     * [--type=<type>]
     * : Only run jobs of this type.
     *
     * [--until-empty]
     * : Keep going while the run stops for busy or time; return on empty, waiting, capped, disabled or max.
     *
     * [--format=<format>]
     * : table or json. Default table.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        $opts = ['time' => (int) ($assoc['time'] ?? 300)];
        if (isset($assoc['max'])) {
            $opts['max'] = (int) $assoc['max'];
        }
        if (isset($assoc['type'])) {
            $opts['type'] = (string) $assoc['type'];
        }
        $untilEmpty = !empty($assoc['until-empty']);
        $format = (string) ($assoc['format'] ?? 'table');
        try {
            $summaries = $this->drain($opts, $untilEmpty);
        } catch (GenQueueException | \InvalidArgumentException $e) {
            $this->io->error($e->getMessage());
        }
        $last = $summaries[count($summaries) - 1];
        $rows = array_map(static fn (RunSummary $s): array => $s->toArray(), $summaries);
        $this->io->items($format, $rows, array_keys($rows[0]));
        $code = self::exitCode($last->stopReason);
        if ($code !== 0) {
            $this->io->halt($code);
        }
    }

    /**
     * The run loop behind `run`. With $untilEmpty it repeats while the stop
     * reason is busy or time, so busy is never mistaken for empty.
     *
     * @param array{time?: int, max?: int, type?: string} $opts
     * @return non-empty-list<RunSummary>
     */
    public function drain(array $opts, bool $untilEmpty): array
    {
        $summaries = [];
        $done = 0;
        $busyWait = 1;
        while (true) {
            $runOpts = $opts;
            if (isset($opts['max'])) {
                $runOpts['max'] = max(1, $opts['max'] - $done);
            }
            $summary = $this->plugin->run($runOpts);
            $summaries[] = $summary;
            $done += $summary->claimed;
            if (!$untilEmpty) {
                return $summaries;
            }
            if ($summary->stopReason === 'busy') {
                $this->io->sleep($busyWait);
                $busyWait = min(30, $busyWait * 2);
                continue;
            }
            if ($summary->stopReason === 'time') {
                $busyWait = 1;
                continue;
            }
            return $summaries;
        }
    }

    /**
     * Show the switch, cap, spend, counts, cooling keys and holds.
     *
     * [--format=<format>]
     * : table or json. Default table.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $snapshot = $this->plugin->status->snapshot();
        if (($assoc['format'] ?? 'table') === 'json') {
            $this->io->line((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        if ($snapshot['cap'] === null) {
            $this->io->warning('No daily cap is set, so nothing runs. Set one with: wp gen-queue cap <amount>');
        }
        $rows = [];
        foreach (['state', 'enabled', 'enabled_source', 'cap', 'cap_source', 'spent_today', 'spend_source', 'reserved', 'cap_remaining', 'currency', 'day'] as $key) {
            $value = $snapshot[$key];
            $rows[] = ['key' => $key, 'value' => is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? '')];
        }
        foreach ($snapshot['counts'] as $status => $n) {
            $rows[] = ['key' => 'jobs ' . $status, 'value' => (string) $n];
        }
        foreach ($snapshot['cooling'] as $c) {
            $rows[] = ['key' => 'cooling ' . $c['resource_key'], 'value' => 'until ' . $c['until'] . ' UTC'];
        }
        foreach ($snapshot['holds'] as $h) {
            $rows[] = ['key' => 'hold ' . $h['group'], 'value' => 'until ' . $h['until'] . ' UTC: ' . $h['message']];
        }
        foreach ($snapshot['paused_types'] as $type) {
            $rows[] = ['key' => 'paused', 'value' => $type];
        }
        foreach ($snapshot['no_handler'] as $type => $n) {
            $rows[] = ['key' => 'no handler', 'value' => $type . ' (' . $n . ' queued)'];
        }
        $this->io->items('table', $rows, ['key', 'value']);
    }

    /**
     * List jobs.
     *
     * [--status=<status>]
     * [--type=<type>]
     * [--limit=<n>]
     * [--format=<format>]
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function list(array $args, array $assoc): void
    {
        $filter = [];
        foreach (['status', 'type'] as $key) {
            if (isset($assoc[$key])) {
                $filter[$key] = (string) $assoc[$key];
            }
        }
        $limit = max(1, min(1000, (int) ($assoc['limit'] ?? 50)));
        $rows = [];
        foreach ($this->plugin->store->listJobs($filter, $limit) as $row) {
            $job = Job::fromRow($row)->toArray();
            $rows[] = array_intersect_key($job, array_flip(['id', 'type', 'status', 'priority', 'attempts', 'max_attempts', 'cost_total', 'last_error_class', 'run_after']));
        }
        $this->io->items((string) ($assoc['format'] ?? 'table'), $rows, ['id', 'type', 'status', 'priority', 'attempts', 'max_attempts', 'cost_total', 'last_error_class', 'run_after']);
    }

    /**
     * Show one job with its attempts.
     *
     * <id>
     *
     * [--payload]
     * : Print the payload and result too.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function show(array $args, array $assoc): void
    {
        $job = $this->plugin->queue->get((int) ($args[0] ?? 0));
        if ($job === null) {
            $this->io->error('No such job.');
        }
        $this->io->line((string) json_encode($job->toArray(!empty($assoc['payload'])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $rows = [];
        foreach ($this->plugin->store->attemptsForJob($job->id) as $a) {
            $rows[] = [
                'attempt' => $a['attempt_no'],
                'outcome' => $a['outcome'],
                'resource_key' => $a['resource_key'],
                'cost' => Money::format((int) $a['cost_nanos']),
                'day' => $a['cost_day'],
                'duration_ms' => $a['duration_ms'],
                'error' => (string) ($a['error'] ?? ''),
            ];
        }
        $this->io->items('table', $rows, ['attempt', 'outcome', 'resource_key', 'cost', 'day', 'duration_ms', 'error']);
    }

    /**
     * Enqueue a job, for testing and backfills.
     *
     * <type>
     * --payload=<json>
     * [--key=<idempotency-key>]
     * [--priority=<n>]
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function enqueue(array $args, array $assoc): void
    {
        $payload = json_decode((string) ($assoc['payload'] ?? ''), true);
        if (!is_array($payload)) {
            $this->io->error('--payload must be a JSON object or array.');
        }
        $jobArgs = [];
        if (isset($assoc['key'])) {
            $jobArgs['idempotency_key'] = (string) $assoc['key'];
        }
        if (isset($assoc['priority'])) {
            $jobArgs['priority'] = (int) $assoc['priority'];
        }
        try {
            $id = $this->plugin->queue->enqueue((string) ($args[0] ?? ''), $payload, $jobArgs);
        } catch (GenQueueException | \InvalidArgumentException $e) {
            $this->io->error($e->getMessage());
        }
        $this->io->success(sprintf('Job %d queued.', $id));
    }

    /**
     * Cancel jobs by id or by type.
     *
     * [<id>...]
     * [--type=<type>]
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function cancel(array $args, array $assoc): void
    {
        if (isset($assoc['type'])) {
            $n = $this->plugin->queue->cancelWhere(['type' => (string) $assoc['type']]);
            $this->io->success(sprintf('%d job(s) cancelled or asked to cancel.', $n));
            return;
        }
        if ($args === []) {
            $this->io->error('Give job ids or --type.');
        }
        $n = 0;
        foreach ($args as $id) {
            $n += $this->plugin->queue->cancel((int) $id) ? 1 : 0;
        }
        $this->io->success(sprintf('%d of %d job(s) cancelled or asked to cancel.', $n, count($args)));
    }

    /**
     * Retry failed, cancelled or expired jobs.
     *
     * [<id>...]
     * [--failed]
     * [--type=<type>]
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function retry(array $args, array $assoc): void
    {
        $ids = array_map('intval', $args);
        if (!empty($assoc['failed'])) {
            $filter = ['status' => 'failed'];
            if (isset($assoc['type'])) {
                $filter['type'] = (string) $assoc['type'];
            }
            $after = 0;
            do {
                $rows = $this->plugin->store->listJobs($filter, 500, $after);
                foreach ($rows as $row) {
                    $ids[] = (int) $row['id'];
                    $after = (int) $row['id'];
                }
            } while (count($rows) === 500);
        }
        if ($ids === []) {
            $this->io->error('Give job ids or --failed.');
        }
        $n = 0;
        foreach ($ids as $id) {
            $n += $this->plugin->queue->retry($id) ? 1 : 0;
        }
        $this->io->success(sprintf('%d of %d job(s) queued again.', $n, count($ids)));
    }

    /**
     * Switch the queue off, or pause one type.
     *
     * [<type>]
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function pause(array $args, array $assoc): void
    {
        try {
            $this->plugin->queue->pause($args[0] ?? null);
        } catch (\InvalidArgumentException $e) {
            $this->io->error($e->getMessage());
        }
        $this->io->success(isset($args[0]) ? sprintf('Type %s paused.', $args[0]) : 'Queue switched off. Running jobs finish their current attempt.');
    }

    /**
     * Switch the queue on, or resume one type.
     *
     * [<type>]
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function resume(array $args, array $assoc): void
    {
        try {
            $this->plugin->queue->resume($args[0] ?? null);
        } catch (\InvalidArgumentException $e) {
            $this->io->error($e->getMessage());
        }
        if (!isset($args[0]) && $this->plugin->controls->disabledByConstant()) {
            $this->io->warning('GEN_QUEUE_DISABLED is defined, so the queue stays off.');
        }
        $this->io->success(isset($args[0]) ? sprintf('Type %s resumed.', $args[0]) : 'Queue switched on.');
    }

    /**
     * Set or unset the daily cap. With no cap set, nothing runs.
     *
     * [<amount>]
     * [--unset]
     * [--currency=<label>]
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function cap(array $args, array $assoc): void
    {
        if (isset($assoc['currency'])) {
            $this->plugin->controls->setCurrency((string) $assoc['currency']);
        }
        if (!empty($assoc['unset'])) {
            $this->plugin->queue->setCap(null);
            $this->io->success('Cap removed. Nothing runs until a cap is set.');
            return;
        }
        if (!isset($args[0])) {
            $cap = $this->plugin->controls->capNanos();
            $this->io->line($cap === null ? 'No cap set: nothing runs.' : Money::format($cap));
            return;
        }
        try {
            $this->plugin->queue->setCap($args[0]);
        } catch (\InvalidArgumentException $e) {
            $this->io->error($e->getMessage());
        }
        if (defined('GEN_QUEUE_DAILY_CAP')) {
            $this->io->warning('GEN_QUEUE_DAILY_CAP is defined and overrides this value.');
        }
        $this->io->success(sprintf('Daily cap set to %s.', Money::format(Money::toNanos($args[0]))));
    }

    /**
     * Purge terminal jobs older than the retention period.
     *
     * [--older-than=<days>]
     * [--dry-run]
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function purge(array $args, array $assoc): void
    {
        $days = (int) ($assoc['older-than'] ?? $this->plugin->controls->retentionDays());
        $dry = !empty($assoc['dry-run']);
        $n = $this->plugin->housekeeping->purge($days, $dry);
        $this->io->success(sprintf($dry ? '%d job(s) would be purged.' : '%d job(s) purged.', $n));
    }

    /**
     * Attempts, successes, failures and cost per day and type.
     *
     * [--days=<n>]
     * [--format=<format>]
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function stats(array $args, array $assoc): void
    {
        $rows = $this->plugin->status->stats((int) ($assoc['days'] ?? 7));
        $this->io->items((string) ($assoc['format'] ?? 'table'), $rows, ['day', 'type', 'attempts', 'succeeded', 'failed', 'cost']);
    }

    /**
     * Show or change settings: max_workers, tick_seconds, retention_days,
     * cooldown_threshold, cooldown_seconds, account_hold.
     *
     * [--<setting>=<value>]
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function settings(array $args, array $assoc): void
    {
        $values = array_diff_key($assoc, ['format' => true]);
        if ($values !== []) {
            try {
                $this->plugin->controls->updateSettings($values);
            } catch (\InvalidArgumentException $e) {
                $this->io->error($e->getMessage());
            }
        }
        $rows = [];
        foreach ($this->plugin->controls->settings() as $key => $value) {
            $rows[] = ['setting' => $key, 'value' => $value];
        }
        $this->io->items((string) ($assoc['format'] ?? 'table'), $rows, ['setting', 'value']);
    }
}
