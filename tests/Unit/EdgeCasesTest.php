<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Unit;

use IndianOpinion\GenQueue\Days;
use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\JobContext;
use IndianOpinion\GenQueue\StorageException;
use IndianOpinion\GenQueue\Tests\Stubs\WpState;
use IndianOpinion\GenQueue\Tests\Support\TestCase;

final class EdgeCasesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setCap('100');
    }

    /** Test 44, case 44. */
    public function testJobOfADeregisteredTypeIsNeverClaimedThenExpires(): void
    {
        $id = gen_queue_enqueue('gone.plugin', [], ['allow_unregistered' => true, 'ttl' => 600]);
        $summary = $this->drain();
        $this->assertSame('waiting', $summary->stopReason);
        $this->assertSame('queued', $this->row($id)['status']);
        $this->assertSame(1, $this->plugin->status->snapshot()['no_handler']['gone.plugin']);
        $this->store->clock += 601;
        $this->drain();
        $this->assertSame('expired', $this->row($id)['status']);
        $this->assertSame(0, $this->row($id)['attempts']);
    }

    /**
     * Test 45, case 45; test 65, case 65; test 66, case 66.
     * A worker that dies mid-handler is reclaimed; its late completion is discarded.
     */
    public function testExpiredLeaseIsReclaimedAndALateCompletionIsDiscarded(): void
    {
        $this->listen('gen_queue_retry_scheduled');
        $snapshot = null;
        $mode = 'die';
        $this->type('demo.task', function (Job $job, JobContext $ctx) use (&$snapshot, &$mode): array {
            if ($mode === 'die') {
                // Everything stored up to here survives; the process then exits.
                $snapshot = $this->store->state();
                return [];
            }
            if ($mode === 'slow') {
                // Runs past its lease; meanwhile another runner reclaims the job.
                $this->store->clock += 200;
                $this->plugin->housekeeping->reclaim($this->store->clock, microtime(true) + 1);
                $ctx->addCost('0.40');
                return ['late' => true];
            }
            return ['second' => true];
        }, ['timeout' => 60]);
        add_filter('gen_queue_backoff_delay', static fn (): int => 0);
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertNotNull($snapshot);
        $this->store->restore($snapshot);
        $this->assertSame('running', $this->row($id)['status']);

        // Lease not yet passed: nothing happens.
        $this->plugin->housekeeping->reclaim($this->store->clock + 100, microtime(true) + 1);
        $this->assertSame('running', $this->row($id)['status']);

        $this->store->clock += 121;
        $mode = 'slow';
        $this->drain(['max' => 1]);
        $attempts = $this->attempts($id);
        $this->assertSame('abandoned', $attempts[0]['outcome']);
        $this->assertSame('abandoned', $attempts[1]['outcome']);
        $this->assertSame(1, $this->logged('Discarded a late completion'));
        // The late result was not stored, but its cost was: billed is billed.
        $this->assertSame('queued', $this->row($id)['status']);
        $this->assertNull($this->row($id)['result']);
        $this->assertSame(400000000, $this->row($id)['cost_nanos']);
        $this->assertSame(400000000, $attempts[1]['cost_nanos']);
        $this->assertSame(2, $this->row($id)['attempts']);
        $this->assertSame('abandoned', $this->row($id)['last_error_class']);
        $this->assertCount(2, $this->fired('gen_queue_retry_scheduled'));

        $mode = 'ok';
        $this->drain();
        $this->assertSame('succeeded', $this->row($id)['status']);
        $this->assertSame('{"second":true}', $this->row($id)['result']);
    }

    public function testAbandonedOnTheLastAttemptFails(): void
    {
        $this->type('demo.task', null, ['max_attempts' => 1]);
        $id = gen_queue_enqueue('demo.task', []);
        $this->store->jobs[$id] = array_merge($this->row($id), ['status' => 'running', 'attempts' => 1, 'lease_token' => str_repeat('a', 32), 'lease_until' => $this->store->clock - 1]);
        $this->listen('gen_queue_failed');
        $this->plugin->housekeeping->run('hk');
        $this->assertSame('failed', $this->row($id)['status']);
        $this->assertCount(1, $this->fired('gen_queue_failed'));
    }

    /** Test 63, case 63. */
    public function testFailureWhileClosingLeavesTheJobForLeaseReclaim(): void
    {
        add_filter('gen_queue_backoff_delay', static fn (): int => 0);
        $once = true;
        $this->type('demo.task', function () use (&$once): array {
            if ($once) {
                $this->store->failNext('updateJob');
                $once = false;
            }
            return [];
        }, ['timeout' => 30]);
        $id = gen_queue_enqueue('demo.task', []);
        try {
            $this->drain();
            $this->fail('No storage error');
        } catch (StorageException $e) {
            $this->assertStringContainsString('Simulated', $e->getMessage());
        }
        $this->assertSame('running', $this->row($id)['status']);
        // The worker slot was released despite the error.
        $this->assertSame('', $this->store->locks['worker-1']['owner']);
        $this->store->clock += 100;
        $this->drain();
        $this->assertSame('succeeded', $this->row($id)['status']);
        $this->assertSame(['abandoned', 'success'], array_column($this->attempts($id), 'outcome'));
    }

    /** Test 64, case 64. */
    public function testComparisonsUseDatabaseTime(): void
    {
        $this->type('demo.task');
        // The database clock is far behind PHP's: run_after in database time decides.
        $this->store->clock = 1000000;
        $id = gen_queue_enqueue('demo.task', [], ['run_after' => 1000060]);
        $this->assertSame('waiting', $this->drain()->stopReason);
        $this->store->clock = 1000060;
        $this->drain();
        $this->assertSame('succeeded', $this->row($id)['status']);
        $this->assertSame(1000060, $this->attempts($id)[0]['started_at']);
    }

    /** Test 67, case 67. */
    public function testThrowingSpendFilterFallsBackToOwnRecords(): void
    {
        add_filter('gen_queue_spend_today', static function (): never {
            throw new \RuntimeException('meter offline');
        });
        $this->type('demo.task', static function (Job $job, JobContext $ctx): array {
            $ctx->addCost('1');
            return [];
        });
        gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame('1.00', gen_queue_spend_today());
        $this->assertGreaterThan(0, $this->logged('gen_queue_spend_today filter threw RuntimeException'));
    }

    /** Test 68, case 68. */
    public function testStaleCachedCapIsIgnored(): void
    {
        WpState::$cachedOptions['gen_queue_daily_cap'] = '999000000000';
        $this->plugin->queue->setCap(null);
        $this->type('demo.task');
        $id = gen_queue_enqueue('demo.task', []);
        $this->assertSame('capped', $this->drain()->stopReason);
        $this->assertSame('queued', $this->row($id)['status']);
    }

    /** Test 69, case 69. */
    public function testTimezoneChangeMidDayKeepsStoredDays(): void
    {
        WpState::$timezone = 'UTC';
        $this->store->clock = gmmktime(22, 0, 0, 6, 1, 2026);
        $this->type('demo.task', static function (Job $job, JobContext $ctx): array {
            $ctx->addCost('1');
            return [];
        });
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame('2026-06-01', $this->attempts($id)[0]['cost_day']);
        $this->assertSame('1.00', gen_queue_spend_today());

        WpState::$timezone = 'Asia/Kolkata';
        $this->assertSame('2026-06-02', Days::local($this->store->clock));
        $this->assertSame('0.00', gen_queue_spend_today());
        $this->assertSame('2026-06-01', $this->attempts($id)[0]['cost_day']);
    }

    /** Test 70, case 70. */
    public function testPurgeRemovesOldTerminalRowsOnly(): void
    {
        $this->type('demo.task');
        $old = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $queued = gen_queue_enqueue('demo.task', [], ['run_after' => $this->store->clock + 86400 * 60, 'ttl' => 0]);
        $this->store->clock += 31 * 86400;
        $recent = gen_queue_enqueue('demo.task', []);
        gen_queue_cancel($recent);
        $this->plugin->housekeeping->run('hk');
        $this->assertArrayNotHasKey($old, $this->store->jobs);
        $this->assertSame([], $this->attempts($old));
        $this->assertArrayHasKey($queued, $this->store->jobs);
        $this->assertArrayHasKey($recent, $this->store->jobs);
        $this->store->clock += 1;
        $this->assertSame(1, $this->plugin->housekeeping->purge(0, true));
        $this->assertSame(1, $this->plugin->housekeeping->purge(0, false));
        $this->assertArrayNotHasKey($recent, $this->store->jobs);
    }

    /** Test 70, case 70. */
    public function testRetentionZeroKeepsEverything(): void
    {
        $this->plugin->controls->updateSettings(['retention_days' => 0]);
        $this->type('demo.task');
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->store->clock += 400 * 86400;
        $this->plugin->housekeeping->run('hk');
        $this->assertArrayHasKey($id, $this->store->jobs);
    }

    public function testPurgeRunsOncePerDay(): void
    {
        $this->type('demo.task');
        $this->plugin->housekeeping->run('hk');
        $this->assertSame(Days::local($this->store->clock), $this->store->options['gen_queue_purge_day']);
    }

    public function testSettingsAreValidatedAndConstantsOverride(): void
    {
        $controls = $this->plugin->controls;
        $this->assertSame(2, $controls->setting('max_workers'));
        $controls->updateSettings(['cooldown_seconds' => 30]);
        $this->assertSame(30, $controls->setting('cooldown_seconds'));
        $this->assertTrue($this->store->autoload['gen_queue_settings']);
        $this->expectException(\InvalidArgumentException::class);
        $controls->updateSettings(['max_workers' => 0]);
    }

    public function testPrivacyExporterAndEraser(): void
    {
        WpState::$userId = 7;
        $this->type('demo.task');
        $id = gen_queue_enqueue('demo.task', ['secret' => 'payload text']);
        WpState::$usersByEmail['ed@example.test'] = (object) ['ID' => 7];
        $privacy = new \IndianOpinion\GenQueue\Privacy($this->store);
        $export = $privacy->export('ed@example.test');
        $this->assertCount(1, $export['data']);
        $this->assertStringNotContainsString('payload text', (string) json_encode($export));
        $this->assertSame('gen-queue-job-' . $id, $export['data'][0]['item_id']);
        $erase = $privacy->erase('ed@example.test');
        $this->assertTrue($erase['items_removed']);
        $this->assertSame(0, $this->row($id)['created_by']);
        $this->assertSame([], $privacy->export('nobody@example.test')['data']);
        $this->assertArrayHasKey('gen-queue', $privacy->registerExporter([]));
        $this->assertArrayHasKey('gen-queue', $privacy->registerEraser([]));
    }
}
