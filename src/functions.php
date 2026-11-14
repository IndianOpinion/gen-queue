<?php

/**
 * Global wrappers so callers can test for gen-queue with function_exists().
 */

declare(strict_types=1);

use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\Money;
use IndianOpinion\GenQueue\Plugin;
use IndianOpinion\GenQueue\RunSummary;

if (!function_exists('gen_queue_register_type')) {
    /**
     * @param callable $handler called as handler(Job $job, JobContext $ctx)
     * @param array<string, mixed> $args
     * @throws \IndianOpinion\GenQueue\RegistrationException
     */
    function gen_queue_register_type(string $type, mixed $handler, array $args = []): void
    {
        Plugin::instance()->registry->register($type, $handler, $args);
    }

    /**
     * @param array<mixed> $payload
     * @param array<string, mixed> $args
     */
    function gen_queue_enqueue(string $type, array $payload, array $args = []): int
    {
        return Plugin::instance()->queue->enqueue($type, $payload, $args);
    }

    /**
     * @param list<array{payload?: array<mixed>, args?: array<string, mixed>}> $items
     * @return list<int>
     */
    function gen_queue_enqueue_many(string $type, array $items): array
    {
        return Plugin::instance()->queue->enqueueMany($type, $items);
    }

    function gen_queue_get(int $id): ?Job
    {
        return Plugin::instance()->queue->get($id);
    }

    function gen_queue_find(string $type, string $key): ?Job
    {
        return Plugin::instance()->queue->find($type, $key);
    }

    function gen_queue_cancel(int $id): bool
    {
        return Plugin::instance()->queue->cancel($id);
    }

    /**
     * @param array{type?: string, source?: string, group?: string} $where
     */
    function gen_queue_cancel_where(array $where): int
    {
        return Plugin::instance()->queue->cancelWhere($where);
    }

    function gen_queue_retry(int $id): bool
    {
        return Plugin::instance()->queue->retry($id);
    }

    function gen_queue_pause(?string $type = null): void
    {
        Plugin::instance()->queue->pause($type);
    }

    function gen_queue_resume(?string $type = null): void
    {
        Plugin::instance()->queue->resume($type);
    }

    function gen_queue_is_enabled(?string $type = null): bool
    {
        return Plugin::instance()->queue->isEnabled($type);
    }

    function gen_queue_resource_cooling(string $key): ?int
    {
        $plugin = Plugin::instance();
        return $plugin->breakers->coolingUntil($key, $plugin->store->now());
    }

    function gen_queue_spend_today(?string $type = null): string
    {
        $plugin = Plugin::instance();
        $day = \IndianOpinion\GenQueue\Days::local($plugin->store->now());
        $nanos = $type === null ? $plugin->controls->spendToday($day) : $plugin->controls->typeSpend($day, $type);
        return Money::format($nanos);
    }

    function gen_queue_cap_remaining(): ?string
    {
        return Plugin::instance()->runner->capRemaining();
    }

    /**
     * @param array{time?: int, max?: int, type?: string, worker?: string} $opts
     */
    function gen_queue_run(array $opts = []): RunSummary
    {
        return Plugin::instance()->run($opts);
    }

    /**
     * @return list<array{day: string, type: string, attempts: int, succeeded: int, failed: int, cost: string}>
     */
    function gen_queue_stats(int $days = 7): array
    {
        return Plugin::instance()->status->stats($days);
    }
}
