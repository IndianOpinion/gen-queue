<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * Thrown by a handler for a fault that may pass: transport failure, rate
 * limit, server error, an error body with a success status, an empty,
 * malformed or truncated response. The job is retried with backoff.
 */
class RetryableException extends GenQueueException
{
    private ?int $retryAfter;

    /**
     * @param int|null $retryAfter Minimum seconds before the next attempt, when the provider said so.
     */
    public function __construct(string $message = '', ?int $retryAfter = null, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        if ($retryAfter !== null && $retryAfter < 0) {
            $retryAfter = 0;
        }
        $this->retryAfter = $retryAfter;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
