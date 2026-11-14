<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * What one runner did and why it stopped.
 *
 * Stop reasons: empty (nothing queued), waiting (jobs exist but none is
 * eligible now), busy (every worker slot is held), time (budget spent),
 * max (job limit reached), capped (the cap or the meter refused), disabled
 * (kill switch off).
 */
final class RunSummary
{
    public const REASONS = ['empty', 'waiting', 'busy', 'time', 'max', 'capped', 'disabled'];

    public int $claimed = 0;
    public int $succeeded = 0;
    public int $retried = 0;
    public int $failed = 0;
    public int $cancelled = 0;
    public int $skipped = 0;
    public int $costNanos = 0;
    public string $costTotal = '0.00';
    public float $seconds = 0.0;
    public string $stopReason = 'empty';

    public function addCost(int $nanos): void
    {
        $this->costNanos += $nanos;
        $this->costTotal = Money::format($this->costNanos);
    }

    /** @return array<string, int|float|string> */
    public function toArray(): array
    {
        return [
            'claimed' => $this->claimed,
            'succeeded' => $this->succeeded,
            'retried' => $this->retried,
            'failed' => $this->failed,
            'cancelled' => $this->cancelled,
            'skipped' => $this->skipped,
            'cost_total' => $this->costTotal,
            'seconds' => round($this->seconds, 3),
            'stop_reason' => $this->stopReason,
        ];
    }
}
