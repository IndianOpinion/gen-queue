<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * Exponential backoff with full jitter.
 */
final class Backoff
{
    public const MAX_DELAY = 86400;

    /**
     * A random delay between 0 and min(max, base * 2^(attempt - 1)), never
     * below $retryAfter when given, then passed through the
     * gen_queue_backoff_delay filter and clamped to 0..86400.
     */
    public static function delay(int $base, int $max, int $attempt, ?int $retryAfter, Job $job, \Throwable $error): int
    {
        $ceiling = (float) $base * (2 ** max(0, min(62, $attempt - 1)));
        $ceiling = (int) min((float) $max, $ceiling, (float) self::MAX_DELAY);
        $delay = $ceiling > 0 ? random_int(0, $ceiling) : 0;
        if ($retryAfter !== null) {
            $delay = max($delay, min($retryAfter, self::MAX_DELAY));
        }
        $filtered = apply_filters('gen_queue_backoff_delay', $delay, $job, $error);
        if (is_int($filtered) || (is_float($filtered) && is_finite($filtered))) {
            $delay = (int) $filtered;
        } elseif (is_string($filtered) && is_numeric($filtered)) {
            $delay = (int) $filtered;
        }
        return max(0, min(self::MAX_DELAY, $delay));
    }
}
