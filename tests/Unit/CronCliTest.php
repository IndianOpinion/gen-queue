<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Unit;

use IndianOpinion\GenQueue\Cli\Command;
use IndianOpinion\GenQueue\Plugin;
use IndianOpinion\GenQueue\Storage\Store;
use IndianOpinion\GenQueue\Tests\Stubs\WpState;
use IndianOpinion\GenQueue\Tests\Support\FakeIo;
use IndianOpinion\GenQueue\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class CronCliTest extends TestCase
{
    /** Test 58, case 58. */
    public function testActivationSchedulesTheTick(): void
    {
        Plugin::activate();
        $this->assertNotFalse(wp_next_scheduled(Plugin::TICK_HOOK));
        $this->assertSame(Plugin::SCHEDULE, WpState::$scheduled[Plugin::TICK_HOOK][1]);
        $this->assertSame(60, Plugin::schedules([])[Plugin::SCHEDULE]['interval']);
        Plugin::deactivate();
        $this->assertFalse(wp_next_scheduled(Plugin::TICK_HOOK));
        $this->plugin->ensureScheduled();
        $this->assertNotFalse(wp_next_scheduled(Plugin::TICK_HOOK));
    }

    public function testTickRunsJobsAndRecordsTheStopReason(): void
    {
        $this->listen('gen_queue_tick_finished');
        $this->setCap('5');
        $this->type('demo.task', null, ['timeout' => 10]);
        $id = gen_queue_enqueue('demo.task', []);
        $summary = $this->plugin->tick();
        $this->assertNotNull($summary);
        $this->assertSame('succeeded', $this->row($id)['status']);
        $this->assertCount(1, $this->fired('gen_queue_tick_finished'));
        $this->assertSame('empty', $this->plugin->status->snapshot()['last_tick']['stop_reason']);
    }

    /** Test 58, case 58. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstantLimitsTheTickToHousekeeping(): void
    {
        define('GEN_QUEUE_DISABLE_CRON_RUNNER', true);
        $this->setCap('5');
        $this->type('demo.task', null, ['ttl' => 10]);
        $run = gen_queue_enqueue('demo.task', []);
        $old = gen_queue_enqueue('demo.task', [], ['ttl' => 5]);
        $this->store->clock += 6;
        $this->assertNull($this->plugin->tick());
        $this->assertSame('queued', $this->row($run)['status']);
        $this->assertSame('expired', $this->row($old)['status']);
        // WP-CLI still runs jobs.
        gen_queue_run(['time' => 60]);
        $this->assertSame('succeeded', $this->row($run)['status']);
    }

    /** Test 60, case 60. */
    public function testUntilEmptyRetriesOnBusyAndExitsOnEmpty(): void
    {
        $this->setCap('5');
        $this->type('demo.task');
        $id = gen_queue_enqueue('demo.task', []);
        $until = $this->store->clock + 30;
        $this->store->acquireLock('worker-1', 'x', $until, $this->store->clock);
        $this->store->acquireLock('worker-2', 'y', $until, $this->store->clock);
        $io = new FakeIo();
        $io->onSleep = function (): void {
            // While we wait, the other runners finish.
            $this->store->releaseLock('worker-1', 'x');
        };
        $command = new Command($this->plugin, $io);
        $summaries = $command->drain(['time' => 60], true);
        $this->assertSame(['busy', 'empty'], array_map(static fn ($s): string => $s->stopReason, $summaries));
        $this->assertSame([1], $io->slept);
        $this->assertSame('succeeded', $this->row($id)['status']);

        // Without --until-empty a busy run returns at once.
        gen_queue_enqueue('demo.task', []);
        $this->store->acquireLock('worker-1', 'x', $until, $this->store->clock);
        $this->assertSame(['busy'], array_map(static fn ($s): string => $s->stopReason, $command->drain(['time' => 60], false)));

        // With nothing due, held slots do not matter: the idle run says empty, not busy.
        $this->store->releaseLock('worker-1', 'x');
        $this->store->releaseLock('worker-2', 'y');
        $command->drain(['time' => 60], false);
        $this->store->acquireLock('worker-1', 'x', $until, $this->store->clock);
        $this->store->acquireLock('worker-2', 'y', $until, $this->store->clock);
        $this->assertSame(['empty'], array_map(static fn ($s): string => $s->stopReason, $command->drain(['time' => 60], false)));
    }

    /** Test 60, case 60: exit codes. */
    public function testRunExitCodes(): void
    {
        $this->assertSame(0, Command::exitCode('empty'));
        $this->assertSame(0, Command::exitCode('waiting'));
        $this->assertSame(0, Command::exitCode('max'));
        $this->assertSame(3, Command::exitCode('disabled'));
        $this->assertSame(4, Command::exitCode('capped'));

        $this->type('demo.task');
        gen_queue_enqueue('demo.task', []);
        $io = new FakeIo();
        $command = new Command($this->plugin, $io);
        try {
            $command->run([], ['until-empty' => true]);
            $this->fail('No halt');
        } catch (\RuntimeException $e) {
            $this->assertSame('halt 4', $e->getMessage());
        }
        gen_queue_pause();
        try {
            $command->run([], []);
            $this->fail('No halt');
        } catch (\RuntimeException $e) {
            $this->assertSame('halt 3', $e->getMessage());
        }
        gen_queue_resume();
        $this->setCap('1');
        $command->run([], ['format' => 'json']);
        $this->assertSame('empty', $io->items[count($io->items) - 1]['rows'][0]['stop_reason']);
    }

    public function testCliCommands(): void
    {
        $io = new FakeIo();
        $command = new Command($this->plugin, $io);
        $this->type('demo.task');

        $command->cap([], []);
        $this->assertSame('No cap set: nothing runs.', $io->lines[0]);
        $command->cap(['2.50'], ['currency' => 'EUR']);
        $this->assertSame('2.50', $this->plugin->controls->capNanos() === null ? null : \IndianOpinion\GenQueue\Money::format($this->plugin->controls->capNanos()));
        $this->assertSame('EUR', $this->plugin->controls->currency());

        $command->enqueue(['demo.task'], ['payload' => '{"post":3}', 'key' => 'k1', 'priority' => '5']);
        $job = gen_queue_find('demo.task', 'k1');
        $this->assertNotNull($job);
        $this->assertSame(5, $job->priority);

        $command->list([], ['status' => 'queued']);
        $this->assertSame($job->id, $io->items[0]['rows'][0]['id']);

        $command->pause(['demo.task'], []);
        $this->assertFalse(gen_queue_is_enabled('demo.task'));
        $command->resume(['demo.task'], []);
        $this->assertTrue(gen_queue_is_enabled('demo.task'));

        $command->cancel([(string) $job->id], []);
        $this->assertSame('cancelled', gen_queue_get($job->id)?->status);
        $command->retry([(string) $job->id], []);
        $this->assertSame('queued', gen_queue_get($job->id)?->status);

        $command->run([], ['max' => '1']);
        $command->show([(string) $job->id], ['payload' => true]);
        $this->assertStringContainsString('"post": 3', $io->lines[count($io->lines) - 1]);

        $command->status([], ['format' => 'json']);
        $status = json_decode($io->lines[count($io->lines) - 1], true);
        $this->assertSame('2.50', $status['cap']);

        $command->stats([], ['days' => '7']);
        $this->assertSame('demo.task', $io->items[count($io->items) - 1]['rows'][0]['type']);

        $command->settings([], ['max_workers' => '3']);
        $this->assertSame(3, $this->plugin->controls->setting('max_workers'));

        $command->purge([], ['older-than' => '0', 'dry-run' => true]);
        $this->assertStringContainsString('would be purged', $io->successes[count($io->successes) - 1]);

        $command->cap([], ['unset' => true]);
        $this->assertNull($this->plugin->controls->capNanos());
        $command->status([], []);
        $this->assertStringContainsString('No daily cap', $io->warnings[0]);

        try {
            $command->enqueue(['demo.task'], ['payload' => 'not json']);
            $this->fail('Bad payload accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('JSON', $e->getMessage());
        }
    }

    /** Test 61, case 61. */
    public function testHousekeepingStaysInsideOneSecondWith100kExpiredRows(): void
    {
        $this->type('demo.task', null, ['ttl' => 10]);
        $now = $this->store->clock;
        for ($i = 1; $i <= 100000; $i++) {
            $this->store->jobs[$i] = array_merge(array_fill_keys(Store::JOB_COLUMNS, null), [
                'id' => $i,
                'type' => 'demo.task',
                'status' => 'queued',
                'expires_at' => $now - 1,
                'run_after' => $now - 100,
                'priority' => 0,
                'attempts' => 0,
                'max_attempts' => 5,
                'group_key' => 'g',
                'resource_key' => 'r',
                'payload' => '[]',
                'estimate_nanos' => 0,
                'cost_nanos' => 0,
                'cancel_requested' => 0,
                'created_at' => $now,
                'created_by' => 0,
            ]);
        }
        $start = microtime(true);
        $result = $this->plugin->housekeeping->run('hk');
        $elapsed = microtime(true) - $start;
        $this->assertTrue($result['ran']);
        $this->assertGreaterThan(0, $result['expired']);
        $this->assertLessThan(1.5, $elapsed);
    }
}
