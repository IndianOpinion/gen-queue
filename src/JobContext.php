<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * Passed to a handler with its job. The handler reports cost through it,
 * checks for a cancel request, reads its deadline and logs lines that are
 * kept with the attempt.
 */
final class JobContext
{
    public const LOG_MAX_BYTES = 4096;

    private string $resourceKey;

    private string $log = '';

    /** @var list<array{amount_nanos: int, input_units: int, output_units: int, usage_ref: ?string, metered: bool}> */
    private array $costs = [];

    /**
     * @param \Closure(array{amount_nanos: int, input_units: int, output_units: int, usage_ref: ?string, metered: bool}): void $onCost
     * @param \Closure(): bool $cancelCheck
     * @param \Closure(): ?string $capRemaining
     */
    public function __construct(
        private readonly Job $job,
        private readonly int $attempt,
        private readonly int $deadline,
        private readonly \Closure $onCost,
        private readonly \Closure $cancelCheck,
        private readonly \Closure $capRemaining,
    ) {
        $this->resourceKey = $job->resourceKey;
    }

    public function job(): Job
    {
        return $this->job;
    }

    /** The attempt number, starting at 1. */
    public function attempt(): int
    {
        return $this->attempt;
    }

    /** Unix time by which the handler should have finished. */
    public function deadline(): int
    {
        return $this->deadline;
    }

    /**
     * Report what a provider call cost. Call it as soon as the cost is known,
     * once per call, even when the call then fails: billed failures are spend.
     *
     * @param array{input_units?: int, output_units?: int, usage_ref?: string, metered?: bool} $usage
     *        usage_ref: the id another meter uses for this call. metered: true
     *        when the call was already recorded by another meter, so it is
     *        not forwarded again.
     * @throws \InvalidArgumentException for a negative amount or bad usage data.
     */
    public function addCost(string|int|float $amount, array $usage = []): void
    {
        $nanos = Money::toNanos($amount);
        $unknown = array_diff(array_keys($usage), ['input_units', 'output_units', 'usage_ref', 'metered']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown usage key(s): ' . implode(', ', $unknown) . '.');
        }
        $input = $usage['input_units'] ?? 0;
        $output = $usage['output_units'] ?? 0;
        if (!is_int($input) || $input < 0 || !is_int($output) || $output < 0) {
            throw new \InvalidArgumentException('input_units and output_units must be non-negative integers.');
        }
        $ref = $usage['usage_ref'] ?? null;
        if ($ref !== null && (!is_string($ref) || $ref === '' || strlen($ref) > 191 || preg_match('//u', $ref) !== 1)) {
            throw new \InvalidArgumentException('usage_ref must be a string of 1 to 191 bytes.');
        }
        $metered = $usage['metered'] ?? false;
        if (!is_bool($metered)) {
            throw new \InvalidArgumentException('metered must be a boolean.');
        }
        $record = [
            'amount_nanos' => $nanos,
            'input_units' => $input,
            'output_units' => $output,
            'usage_ref' => $ref,
            'metered' => $metered,
        ];
        $this->costs[] = $record;
        ($this->onCost)($record);
    }

    /**
     * Tell the queue which resource key this attempt actually used, for
     * example a fallback model. Cooldowns are kept against this key.
     */
    public function useResource(string $key): void
    {
        $this->resourceKey = Validate::label($key, 128, 'resource key');
    }

    public function resourceKey(): string
    {
        return $this->resourceKey;
    }

    /** True once an operator has asked to cancel this job. Read fresh. */
    public function cancelRequested(): bool
    {
        return ($this->cancelCheck)();
    }

    /** What is left of today's global cap as a decimal, or null with no cap set. */
    public function capRemaining(): ?string
    {
        return ($this->capRemaining)();
    }

    /** Add a line to the attempt's log (4 KiB in all; later lines are dropped). */
    public function log(string $line): void
    {
        $line = Redactor::clean(str_replace(["\r", "\n"], ' ', $line), self::LOG_MAX_BYTES);
        $next = $this->log === '' ? $line : $this->log . "\n" . $line;
        $this->log = Redactor::cut($next, self::LOG_MAX_BYTES);
    }

    public function logText(): string
    {
        return $this->log;
    }

    /** @return list<array{amount_nanos: int, input_units: int, output_units: int, usage_ref: ?string, metered: bool}> */
    public function costs(): array
    {
        return $this->costs;
    }
}
