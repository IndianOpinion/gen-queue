<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Support;

use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\JobContext;
use IndianOpinion\GenQueue\Log;
use IndianOpinion\GenQueue\Plugin;
use IndianOpinion\GenQueue\Tests\Stubs\WpState;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected MemoryStore $store;

    protected Plugin $plugin;

    /** @var list<string> */
    protected array $logs = [];

    /** @var array<string, list<list<mixed>>> */
    protected array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        WpState::reset();
        $this->logs = [];
        $this->events = [];
        Log::setSink(function (string $line): void {
            $this->logs[] = $line;
        });
        $this->store = new MemoryStore();
        $this->plugin = Plugin::boot($this->store);
        $this->plugin->installer->install();
        $this->plugin->registry->open();
    }

    protected function tearDown(): void
    {
        Log::setSink(null);
        parent::tearDown();
    }

    /** Record every call of an action. */
    protected function listen(string $hook): void
    {
        add_action($hook, function (mixed ...$args) use ($hook): void {
            $this->events[$hook][] = $args;
        }, 10, 10);
    }

    /** @return list<list<mixed>> */
    protected function fired(string $hook): array
    {
        return $this->events[$hook] ?? [];
    }

    /**
     * Register a type whose handler is a closure.
     *
     * @param \Closure(Job, JobContext): mixed|null $handler
     * @param array<string, mixed> $args
     */
    protected function type(string $name, ?\Closure $handler = null, array $args = []): void
    {
        $this->plugin->registry->register($name, $handler ?? static fn (): array => ['ok' => true], $args);
    }

    protected function setCap(string $amount): void
    {
        $this->plugin->queue->setCap($amount);
    }

    /** @param array<string, mixed> $opts */
    protected function drain(array $opts = []): \IndianOpinion\GenQueue\RunSummary
    {
        return $this->plugin->run($opts + ['time' => 600]);
    }

    /** @return array<string, mixed> */
    protected function row(int $id): array
    {
        $row = $this->store->jobs[$id] ?? null;
        $this->assertNotNull($row, 'job ' . $id . ' exists');
        return $row;
    }

    /** @return list<array<string, mixed>> */
    protected function attempts(int $jobId): array
    {
        return array_values(array_filter($this->store->attempts, static fn (array $a): bool => $a['job_id'] === $jobId));
    }

    protected function logged(string $needle): int
    {
        return count(array_filter($this->logs, static fn (string $l): bool => str_contains($l, $needle)));
    }
}
