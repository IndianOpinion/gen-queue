<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * What a handler returns on success: a small reference to what it wrote,
 * for example a draft post id. It is stored as JSON, up to 16 KiB.
 */
final class JobResult
{
    /** @param array<mixed> $ref */
    private function __construct(private readonly array $ref)
    {
    }

    /** @param array<mixed> $ref */
    public static function ok(array $ref = []): self
    {
        return new self($ref);
    }

    /** @return array<mixed> */
    public function ref(): array
    {
        return $this->ref;
    }
}
