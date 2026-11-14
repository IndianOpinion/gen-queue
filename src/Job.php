<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * A read-only view of one job. Times are Unix timestamps (UTC).
 */
final class Job
{
    /**
     * @param array<mixed> $payload
     * @param array<mixed>|null $result
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $status,
        public readonly array $payload,
        public readonly ?string $idempotencyKey,
        public readonly int $priority,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly string $group,
        public readonly string $resourceKey,
        public readonly string $source,
        public readonly int $runAfter,
        public readonly ?int $expiresAt,
        public readonly string $costEstimate,
        public readonly string $costTotal,
        public readonly ?array $result,
        public readonly ?string $lastError,
        public readonly ?string $lastErrorClass,
        public readonly bool $cancelRequested,
        public readonly int $createdBy,
        public readonly int $createdAt,
        public readonly ?int $finishedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $result = null;
        if (isset($row['result']) && is_string($row['result']) && $row['result'] !== '') {
            $decoded = json_decode($row['result'], true);
            $result = is_array($decoded) ? $decoded : null;
        }
        return new self(
            (int) $row['id'],
            (string) $row['type'],
            (string) $row['status'],
            Payload::decode(isset($row['payload']) ? (string) $row['payload'] : null),
            isset($row['idempotency_key']) ? (string) $row['idempotency_key'] : null,
            (int) ($row['priority'] ?? 0),
            (int) ($row['attempts'] ?? 0),
            (int) ($row['max_attempts'] ?? 0),
            (string) ($row['group_key'] ?? ''),
            (string) ($row['resource_key'] ?? ''),
            (string) ($row['source'] ?? ''),
            (int) ($row['run_after'] ?? 0),
            isset($row['expires_at']) ? (int) $row['expires_at'] : null,
            Money::format((int) ($row['estimate_nanos'] ?? 0)),
            Money::format((int) ($row['cost_nanos'] ?? 0)),
            $result,
            isset($row['last_error']) ? (string) $row['last_error'] : null,
            isset($row['last_error_class']) ? (string) $row['last_error_class'] : null,
            (bool) ($row['cancel_requested'] ?? false),
            (int) ($row['created_by'] ?? 0),
            (int) ($row['created_at'] ?? 0),
            isset($row['finished_at']) ? (int) $row['finished_at'] : null,
        );
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'cancelled', 'expired'], true);
    }

    /**
     * A plain array for CLI and JSON output. The payload is left out unless asked for.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $withPayload = false): array
    {
        $out = [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'priority' => $this->priority,
            'attempts' => $this->attempts,
            'max_attempts' => $this->maxAttempts,
            'group' => $this->group,
            'resource_key' => $this->resourceKey,
            'source' => $this->source,
            'idempotency_key' => $this->idempotencyKey,
            'run_after' => Days::utc($this->runAfter),
            'expires_at' => $this->expiresAt === null ? null : Days::utc($this->expiresAt),
            'cost_estimate' => $this->costEstimate,
            'cost_total' => $this->costTotal,
            'last_error_class' => $this->lastErrorClass,
            'last_error' => $this->lastError,
            'created_at' => Days::utc($this->createdAt),
            'finished_at' => $this->finishedAt === null ? null : Days::utc($this->finishedAt),
        ];
        if ($withPayload) {
            $out['payload'] = $this->payload;
            $out['result'] = $this->result;
        }
        return $out;
    }
}
