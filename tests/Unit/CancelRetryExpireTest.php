<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Unit;

use IndianOpinion\GenQueue\CancelledException;
use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\JobContext;
use IndianOpinion\GenQueue\JobResult;
use IndianOpinion\GenQueue\RetryableException;
use IndianOpinion\GenQueue\Tests\Support\TestCase;

final class CancelRetryExpireTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setCap('100');
    }

    /** Test 51, case 51. */
    public function testCancellingAQueuedJob(): void
    {
        $this->listen('gen_queue_cancelled');
        $this->type('demo.task');
        $id = gen_queue_enqueue('demo.task', []);
        $this->assertTrue(gen_queue_cancel($id));
        $this->assertSame('cancelled', $this->row($id)['status']);
        $this->assertNotNull($this->row($id)['finished_at']);
        $this->assertCount(1, $this->fired('gen_queue_cancelled'));
        $this->drain();
        $this->assertSame('cancelled', $this->row($id)['status']);
        $this->assertFalse(gen_queue_cancel(99999));
    }

    /** Test 52, case 52: the handler stops by throwing CancelledException. */
    public function testCancellingARunningJobThatChecks(): void
    {
        $this->listen('gen_queue_cancelled');
        $this->type('demo.task', static function (Job $job, JobContext $ctx): never {
            $ctx->addCost('0.20');
            gen_queue_cancel($job->id);
            if ($ctx->cancelRequested()) {
                throw new CancelledException('Stopped on request');
            }
            throw new \LogicException('unreachable');
        });
        $id = gen_queue_enqueue('demo.task', []);
        $summary = $this->drain();
        $row = $this->row($id);
        $this->assertSame('cancelled', $row['status']);
        $this->assertSame(200000000, $row['cost_nanos']);
        $this->assertSame('cancelled', $this->attempts($id)[0]['outcome']);
        $this->assertSame(1, $summary->cancelled);
        $this->assertCount(1, $this->fired('gen_queue_cancelled'));
    }

    /** Test 52, case 52: a result returned after the request is stored, status stays cancelled. */
    public function testCancellingARunningJobThatIgnoresIt(): void
    {
        $this->type('demo.task', static function (Job $job, JobContext $ctx): JobResult {
            gen_queue_cancel($job->id);
            $ctx->addCost('0.10');
            return JobResult::ok(['draft' => 7]);
        });
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $row = $this->row($id);
        $this->assertSame('cancelled', $row['status']);
        $this->assertSame('{"draft":7}', $row['result']);
        $this->assertSame(100000000, $row['cost_nanos']);
    }

    /** Test 52, case 52: a retryable failure after the request still ends cancelled. */
    public function testCancelRequestWinsOverARetry(): void
    {
        $this->type('demo.task', static function (Job $job): never {
            gen_queue_cancel($job->id);
            throw new RetryableException('flaky');
        });
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame('cancelled', $this->row($id)['status']);
    }

    /** Test 53, case 53. */
    public function testCancellingATerminalJobReturnsFalse(): void
    {
        $this->type('demo.task');
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame('succeeded', $this->row($id)['status']);
        $this->assertFalse(gen_queue_cancel($id));
        $this->assertSame('succeeded', $this->row($id)['status']);
    }

    /** Test 54, case 54. */
    public function testBulkCancelReturnsTheRightCount(): void
    {
        $this->type('demo.a');
        $this->type('demo.b');
        for ($i = 0; $i < 3; $i++) {
            gen_queue_enqueue('demo.a', [], ['source' => 'backfill', 'group' => 'g1']);
        }
        gen_queue_enqueue('demo.b', [], ['source' => 'backfill', 'group' => 'g2']);
        $running = gen_queue_enqueue('demo.b', [], ['source' => 'live', 'group' => 'g2']);
        $this->store->jobs[$running]['status'] = 'running';
        $done = gen_queue_enqueue('demo.a', [], ['source' => 'backfill', 'group' => 'g1']);
        $this->store->jobs[$done]['status'] = 'succeeded';

        $this->assertSame(3, gen_queue_cancel_where(['type' => 'demo.a']));
        $this->assertSame(1, gen_queue_cancel_where(['source' => 'backfill']));
        $this->assertSame(1, gen_queue_cancel_where(['group' => 'g2']));
        $this->assertSame(1, $this->row($running)['cancel_requested']);
        $this->assertSame('running', $this->row($running)['status']);
        $this->assertSame('succeeded', $this->row($done)['status']);
        $this->expectException(\InvalidArgumentException::class);
        gen_queue_cancel_where(['colour' => 'red']);
    }

    /** Test 55, case 55. */
    public function testRetryRaisesMaxAttemptsAndKeepsHistory(): void
    {
        add_filter('gen_queue_backoff_delay', static fn (): int => 0);
        $fail = true;
        $this->type('demo.task', static function () use (&$fail): array {
            if ($fail) {
                throw new RetryableException('flaky');
            }
            return [];
        }, ['max_attempts' => 2, 'resource_key' => 'x/y']);
        $this->plugin->controls->updateSettings(['cooldown_threshold' => 50]);
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame('failed', $this->row($id)['status']);
        $this->assertTrue(gen_queue_retry($id));
        $row = $this->row($id);
        $this->assertSame('queued', $row['status']);
        $this->assertSame(4, $row['max_attempts']);
        $this->assertCount(2, $this->attempts($id));
        $fail = false;
        $this->drain();
        $this->assertSame('succeeded', $this->row($id)['status']);
        $this->assertCount(3, $this->attempts($id));
        $this->assertFalse(gen_queue_retry($id));
    }

    public function testRetryOfAnExpiredJobGetsAFreshExpiry(): void
    {
        $this->type('demo.task', null, ['ttl' => 60]);
        $id = gen_queue_enqueue('demo.task', []);
        gen_queue_pause('demo.task');
        $this->store->clock += 61;
        $this->drain();
        $this->assertSame('expired', $this->row($id)['status']);
        $this->assertTrue(gen_queue_retry($id));
        $this->assertSame($this->store->clock + 60, $this->row($id)['expires_at']);
        gen_queue_resume('demo.task');
        $this->drain();
        $this->assertSame('succeeded', $this->row($id)['status']);
    }

    /** Test 56, case 56. */
    public function testExpiryRunsOnTickUsesExpiredAndIsSuspendedWhileOff(): void
    {
        $this->listen('gen_queue_expired');
        $this->type('demo.task', null, ['ttl' => 60]);
        $a = gen_queue_enqueue('demo.task', [], ['run_after' => $this->store->clock + 3600, 'expires_at' => $this->store->clock + 60]);
        gen_queue_pause();
        $this->store->clock += 120;
        $this->drain();
        $this->assertSame('queued', $this->row($a)['status']);
        $this->assertCount(0, $this->fired('gen_queue_expired'));

        gen_queue_resume();
        $this->setCap('0');
        $this->drain();
        $this->assertSame('expired', $this->row($a)['status']);
        $this->assertSame('expired', $this->fired('gen_queue_expired')[0][0]->status);
    }

    /** Test 56, case 56: a running job never expires mid-run. */
    public function testRunningJobDoesNotExpire(): void
    {
        $this->type('demo.task', function (): array {
            $this->store->clock += 120;
            $this->plugin->housekeeping->expire($this->store->clock, microtime(true) + 1);
            return [];
        }, ['ttl' => 60]);
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame('succeeded', $this->row($id)['status']);
    }
}
