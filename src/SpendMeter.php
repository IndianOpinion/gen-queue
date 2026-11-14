<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * Soft integration with spend-meter, contract version 1.
 *
 * spend-meter is present when the constant SPEND_METER_API is defined. The
 * queue uses only its documented filter (spend_meter_pre_spend) and action
 * (spend_meter_record), never its functions. Money crosses in nanos.
 */
final class SpendMeter
{
    public const PURPOSE_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';
    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @var array<string, true> reasons already logged in this process */
    private array $logged = [];

    public function present(): bool
    {
        return defined('SPEND_METER_API');
    }

    /**
     * Make a string fit the purpose pattern: lower case, other characters
     * to "-", leading separators dropped, cut to 64.
     */
    public static function purpose(string $text): string
    {
        $slug = (string) preg_replace('/[^a-z0-9_.-]/', '-', strtolower($text));
        $slug = ltrim($slug, '_.-');
        $slug = substr($slug, 0, 64);
        return $slug === '' ? 'gen-queue' : $slug;
    }

    /**
     * @return array{purpose: string, caller: string, provider: string, model: string}
     */
    public static function identity(JobType $type): array
    {
        $meter = $type->meter ?? [];
        return [
            'purpose' => self::purpose($meter['purpose'] ?? $type->name),
            'caller' => 'gen-queue',
            'provider' => $meter['provider'] ?? 'gen-queue',
            'model' => $meter['model'] ?? 'job',
        ];
    }

    /**
     * The dry-run intent sent to spend_meter_pre_spend.
     *
     * @return array<string, mixed>
     */
    public static function intent(Job $job, int $estimateNanos, JobType $type): array
    {
        $id = self::identity($type);
        return [
            'purpose' => $id['purpose'],
            'caller' => $id['caller'],
            'provider' => $id['provider'],
            'model' => $id['model'],
            'estimate_override' => $estimateNanos,
            'dry_run' => true,
            'meta' => ['job_id' => $job->id],
        ];
    }

    /**
     * Ask spend-meter whether this job may run. Returns null to carry on,
     * or the reason code of a denial. A thrown error or a malformed decision
     * is a denial: this path guards money, so it fails closed.
     */
    public function check(Job $job, int $estimateNanos, JobType $type): ?string
    {
        if (!$this->present()) {
            return null;
        }
        try {
            $decision = apply_filters('spend_meter_pre_spend', null, self::intent($job, $estimateNanos, $type));
        } catch (\Throwable $e) {
            $this->logOnce('error', 'spend_meter_pre_spend threw ' . get_class($e) . '; the job waits.');
            return 'error';
        }
        if ($decision === null) {
            return null;
        }
        if (!is_array($decision) || !array_key_exists('allowed', $decision) || !is_bool($decision['allowed'])) {
            $this->logOnce('malformed', 'spend_meter_pre_spend returned a malformed decision; the job waits.');
            return 'malformed';
        }
        if ($decision['allowed'] === true) {
            return null;
        }
        $reason = $decision['reason'] ?? null;
        if (!is_string($reason) || preg_match('/^[a-z0-9_]{1,64}$/', $reason) !== 1) {
            $reason = 'unknown';
        }
        $this->logOnce($reason, sprintf('spend-meter denied a job (reason %s); jobs wait.', $reason));
        return $reason;
    }

    /**
     * Forward one unmetered cost record to spend-meter. Records that are
     * metered or zero are not forwarded.
     *
     * @param array<string, mixed> $record a gen_queue_cost_recorded record
     */
    public function record(array $record, JobType $type, bool $success): void
    {
        if (!$this->present() || $record['metered'] === true || (int) $record['amount_nanos'] <= 0) {
            return;
        }
        $id = self::identity($type);
        $meta = ['job_id' => $record['job_id']];
        $ref = $record['usage_ref'];
        if (is_string($ref) && preg_match(self::UUID_PATTERN, $ref) === 1) {
            $callId = strtolower($ref);
        } else {
            $callId = self::uuid4();
            if (is_string($ref) && $ref !== '') {
                $meta['usage_ref'] = $ref;
            }
        }
        $call = [
            'purpose' => $id['purpose'],
            'caller' => $id['caller'],
            'provider' => $id['provider'],
            'model' => $id['model'],
            'provider_cost' => (int) $record['amount_nanos'],
            'input_tokens' => (int) $record['input_units'],
            'output_tokens' => (int) $record['output_units'],
            'status' => $success ? 'ok' : 'error_billed',
            'attempt' => (int) $record['attempt'],
            'call_id' => $callId,
            'meta' => $meta,
        ];
        try {
            do_action('spend_meter_record', $call);
        } catch (\Throwable $e) {
            $this->logOnce('record-error', 'A spend_meter_record listener threw ' . get_class($e) . '; the cost stays in the queue\'s own records.');
        }
    }

    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    public function resetLog(): void
    {
        $this->logged = [];
    }

    private function logOnce(string $key, string $message): void
    {
        if (isset($this->logged[$key])) {
            return;
        }
        $this->logged[$key] = true;
        Log::warning($message);
    }
}
