<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Unit;

use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\JobContext;
use IndianOpinion\GenQueue\JobResult;
use IndianOpinion\GenQueue\Tests\Stubs\WpState;
use IndianOpinion\GenQueue\Tests\Support\TestCase;

final class RunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setCap('100');
    }

    /** Test 17, case 17. */
    public function testAllSlotsHeldStopsWithBusyNotEmpty(): void
    {
        $this->type('demo.task');
        gen_queue_enqueue('demo.task', []);
        $until = $this->store->clock + 600;
        $this->store->acquireLock('worker-1', 'other-a', $until, $this->store->clock);
        $this->store->acquireLock('worker-2', 'other-b', $until, $this->store->clock);
        $summary = $this->drain();
        $this->assertSame('busy', $summary->stopReason);
        $this->assertSame(0, $summary->claimed);

        // An expired slot is free again.
        $this->store->clock = $until + 1;
        $this->assertSame('empty', $this->drain()->stopReason);
    }

    /** Test 18, case 18 and test 68, case 68. */
    public function testSwitchingOffBetweenJobsStopsBeforeTheNextEvenWithAPrimedCache(): void
    {
        WpState::$cachedOptions['gen_queue_enabled'] = '1';
        $calls = 0;
        $this->type('demo.task', function () use (&$calls): array {
            $calls++;
            // Flip the switch in the database only; the object cache still says on.
            $this->store->options['gen_queue_enabled'] = '0';
            return [];
        });
        gen_queue_enqueue('demo.task', []);
        gen_queue_enqueue('demo.task', []);
        $summary = $this->drain();
        $this->assertSame(1, $calls);
        $this->assertSame('disabled', $summary->stopReason);
        $this->assertSame('1', get_option('gen_queue_enabled'));
    }

    /** Test 19, case 19. */
    public function testSwitchOffClaimsNothing(): void
    {
        $this->type('demo.task');
        $id = gen_queue_enqueue('demo.task', []);
        gen_queue_pause();
        $summary = $this->drain();
        $this->assertSame('disabled', $summary->stopReason);
        $this->assertSame('queued', $this->row($id)['status']);
        $this->assertSame(0, $this->row($id)['attempts']);
        $this->assertFalse(gen_queue_is_enabled());
        gen_queue_resume();
        $this->assertTrue(gen_queue_is_enabled());
        $this->assertSame('empty', $this->drain()->stopReason);
        $this->assertSame('succeeded', $this->row($id)['status']);
    }

    /** Test 20, case 20 and test 57, case 57. */
    public function testPausedTypeIsSkippedAndKeepsItsAttempts(): void
    {
        $this->type('demo.a');
        $this->type('demo.b');
        $a = gen_queue_enqueue('demo.a', []);
        $b = gen_queue_enqueue('demo.b', []);
        gen_queue_pause('demo.a');
        $this->assertFalse(gen_queue_is_enabled('demo.a'));
        $this->assertTrue(gen_queue_is_enabled('demo.b'));
        $summary = $this->drain();
        $this->assertSame('waiting', $summary->stopReason);
        $this->assertSame('queued', $this->row($a)['status']);
        $this->assertSame(0, $this->row($a)['attempts']);
        $this->assertSame('succeeded', $this->row($b)['status']);
        gen_queue_resume('demo.a');
        $this->drain();
        $this->assertSame('succeeded', $this->row($a)['status']);
    }

    /** Test 21, case 21. */
    public function testEachIneligibilityPreventsAClaim(): void
    {
        $calls = [];
        $this->type('demo.task', function (Job $job) use (&$calls): array {
            $calls[] = $job->payload['why'];
            return [];
        }, ['cost_estimate' => '1']);
        $now = $this->store->clock;
        gen_queue_enqueue('demo.task', ['why' => 'backoff'], ['run_after' => $now + 300]);
        gen_queue_enqueue('demo.task', ['why' => 'cooling'], ['resource_key' => 'acme/cool']);
        gen_queue_enqueue('demo.task', ['why' => 'hold'], ['group' => 'held-group']);
        gen_queue_enqueue('demo.task', ['why' => 'capped'], ['cost_estimate' => '1000']);
        $expired = gen_queue_enqueue('demo.task', ['why' => 'expired'], ['expires_at' => $now + 1]);
        gen_queue_enqueue('lost.plugin', ['why' => 'no handler'], ['allow_unregistered' => true]);
        $this->type('demo.paused');
        gen_queue_enqueue('demo.paused', ['why' => 'paused']);
        gen_queue_pause('demo.paused');

        $this->store->setOption('gen_queue_breakers_acme', (string) json_encode(['acme/cool' => ['count' => 2, 'until' => $now + 600]]));
        $this->plugin->breakers->hold('held-group', $now, 'test');
        $this->store->clock = $now + 2;

        $summary = $this->drain();
        $this->assertSame([], $calls);
        $this->assertSame('capped', $summary->stopReason);
        $this->assertSame('expired', $this->row($expired)['status']);
        foreach ($this->store->jobs as $row) {
            $this->assertSame(0, $row['attempts']);
        }
    }

    /** Test 22, case 22 and test 59, case 59. */
    public function testTwoRunnersOneJobExactlyOneHandlerCall(): void
    {
        $calls = 0;
        $nested = null;
        $this->type('demo.task', function () use (&$calls, &$nested): array {
            $calls++;
            // A second runner (think: WP-CLI while WP-Cron runs) starts mid-job.
            $nested ??= gen_queue_run(['time' => 600, 'worker' => 'cli-runner']);
            return [];
        });
        $id = gen_queue_enqueue('demo.task', []);
        $summary = $this->drain(['worker' => 'cron-runner']);
        $this->assertSame(1, $calls);
        $this->assertSame(1, $summary->succeeded);
        $this->assertNotNull($nested);
        $this->assertSame(0, $nested->claimed);
        $this->assertSame('succeeded', $this->row($id)['status']);
        $this->assertCount(1, $this->attempts($id));
    }

    /** Test 22, case 22: a conditional update that loses picks again. */
    public function testLosingAClaimPicksAgain(): void
    {
        $seen = [];
        $this->type('demo.task', function (Job $job) use (&$seen): array {
            $seen[] = $job->id;
            return [];
        });
        $first = gen_queue_enqueue('demo.task', [], ['priority' => 5]);
        $second = gen_queue_enqueue('demo.task', []);
        // Another runner claims the first job between our select and our update.
        add_filter('gen_queue_can_run', function (bool $ok, Job $job) use ($first): bool {
            if ($job->id === $first && $this->store->jobs[$first]['status'] === 'queued') {
                $this->store->jobs[$first]['status'] = 'running';
                $this->store->jobs[$first]['lease_until'] = $this->store->clock + 600;
            }
            return $ok;
        }, 10, 2);
        $summary = $this->drain(['max' => 1]);
        $this->assertSame([$second], $seen);
        $this->assertSame(1, $summary->claimed);
    }

    /** Test 23, case 23. */
    public function testAttemptIsCountedAndCommittedBeforeTheHandlerRuns(): void
    {
        $inside = null;
        $this->type('demo.task', function (Job $job) use (&$inside): array {
            // A fatal here would end the process with exactly this state stored.
            $inside = [
                'attempts' => $this->store->jobs[$job->id]['attempts'],
                'status' => $this->store->jobs[$job->id]['status'],
                'attempt_rows' => array_column($this->attempts($job->id), 'outcome'),
            ];
            return [];
        });
        gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame(['attempts' => 1, 'status' => 'running', 'attempt_rows' => ['started']], $inside);
    }

    /** Test 24, case 24. */
    public function testSwitchFlippedInsideClaimedRestoresTheAttempt(): void
    {
        $calls = 0;
        $this->type('demo.task', function () use (&$calls): array {
            $calls++;
            return [];
        });
        $id = gen_queue_enqueue('demo.task', []);
        add_action('gen_queue_claimed', static function (): void {
            gen_queue_pause();
        });
        $summary = $this->drain();
        $this->assertSame(0, $calls);
        $this->assertSame('disabled', $summary->stopReason);
        $this->assertSame(1, $summary->skipped);
        $row = $this->row($id);
        $this->assertSame('queued', $row['status']);
        $this->assertSame(0, $row['attempts']);
        $this->assertNull($row['lease_token']);
        $this->assertSame(['skipped'], array_column($this->attempts($id), 'outcome'));
    }

    /** Test 24, case 24: a type paused inside the claim is released and the run goes on. */
    public function testTypePausedInsideClaimedIsReleased(): void
    {
        $this->type('demo.task');
        $id = gen_queue_enqueue('demo.task', []);
        add_action('gen_queue_claimed', static function (): void {
            gen_queue_pause('demo.task');
        });
        $summary = $this->drain();
        $this->assertSame('waiting', $summary->stopReason);
        $this->assertSame(0, $this->row($id)['attempts']);
    }

    /** Test 25, case 25. */
    public function testContextExposesAttemptDeadlineCostCancelAndLog(): void
    {
        $seen = [];
        $this->type('demo.task', function (Job $job, JobContext $ctx) use (&$seen): array {
            $seen['attempt'] = $ctx->attempt();
            $seen['deadline'] = $ctx->deadline();
            $seen['cancel'] = $ctx->cancelRequested();
            $seen['cap'] = $ctx->capRemaining();
            $ctx->addCost('0.5', ['input_units' => 10, 'output_units' => 20, 'usage_ref' => 'ref-1']);
            $ctx->addCost(0.25);
            $ctx->log('first line');
            $ctx->log("second\nline Bearer abc.def");
            $seen['job'] = $ctx->job()->id;
            return [];
        }, ['timeout' => 90]);
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame(1, $seen['attempt']);
        $this->assertSame($this->store->clock + 90, $seen['deadline']);
        $this->assertFalse($seen['cancel']);
        $this->assertSame('100.00', $seen['cap']);
        $this->assertSame($id, $seen['job']);
        $attempt = $this->attempts($id)[0];
        $this->assertSame(750000000, $attempt['cost_nanos']);
        $this->assertSame(10, $attempt['input_units']);
        $this->assertSame(20, $attempt['output_units']);
        $this->assertSame('ref-1', $attempt['usage_ref']);
        $this->assertSame("first line\nsecond line Bearer [redacted]", $attempt['log']);
        $this->assertSame(750000000, $this->row($id)['cost_nanos']);
        $this->assertSame('0.75', gen_queue_get($id)?->costTotal);
    }

    public function testContextLogIsCappedAt4KiB(): void
    {
        $this->type('demo.task', static function (Job $job, JobContext $ctx): array {
            for ($i = 0; $i < 100; $i++) {
                $ctx->log(str_repeat('x', 100));
            }
            return [];
        });
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertLessThanOrEqual(4096, strlen((string) $this->attempts($id)[0]['log']));
    }

    public function testAddCostRejectsBadInput(): void
    {
        $errors = [];
        $this->type('demo.task', static function (Job $job, JobContext $ctx) use (&$errors): array {
            foreach ([['-1', []], ['1', ['input_units' => -1]], ['1', ['metered' => 'yes']], ['1', ['colour' => 1]], ['abc', []]] as [$amount, $usage]) {
                try {
                    $ctx->addCost($amount, $usage);
                } catch (\InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                }
            }
            return [];
        });
        gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertCount(5, $errors);
    }

    /** Test 26, case 26. */
    public function testSuccessStoresResultClosesAttemptClearsCounterAndFires(): void
    {
        $this->listen('gen_queue_completed');
        $this->type('demo.task', static fn (): JobResult => JobResult::ok(['post_id' => 42, 'status' => 'draft']), ['resource_key' => 'acme/model']);
        $this->store->setOption('gen_queue_breakers_acme', (string) json_encode(['acme/model' => ['count' => 1, 'until' => 0]]));
        $id = gen_queue_enqueue('demo.task', []);
        $summary = $this->drain();
        $row = $this->row($id);
        $this->assertSame('succeeded', $row['status']);
        $this->assertSame('{"post_id":42,"status":"draft"}', $row['result']);
        $this->assertSame(['post_id' => 42, 'status' => 'draft'], gen_queue_get($id)?->result);
        $this->assertNotNull($row['finished_at']);
        $this->assertSame('success', $this->attempts($id)[0]['outcome']);
        $this->assertArrayNotHasKey('gen_queue_breakers_acme', $this->store->options);
        $fired = $this->fired('gen_queue_completed');
        $this->assertCount(1, $fired);
        $this->assertSame($id, $fired[0][0]->id);
        $this->assertInstanceOf(JobResult::class, $fired[0][1]);
        $this->assertSame(1, $summary->succeeded);
    }

    public function testArrayAndNullReturnsAreSuccess(): void
    {
        $this->type('demo.array', static fn (): array => ['a' => 1]);
        $this->type('demo.null', static fn (): null => null);
        $a = gen_queue_enqueue('demo.array', []);
        $b = gen_queue_enqueue('demo.null', []);
        $this->drain();
        $this->assertSame('{"a":1}', $this->row($a)['result']);
        $this->assertSame('[]', $this->row($b)['result']);
    }

    public function testOversizedResultIsReplacedByAMarker(): void
    {
        $this->type('demo.task', static fn (): array => ['big' => str_repeat('x', 20000)]);
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame('succeeded', $this->row($id)['status']);
        $this->assertStringContainsString('result_too_large', (string) $this->row($id)['result']);
    }

    public function testAnInvalidReturnTypeFailsTheJob(): void
    {
        $this->type('demo.task', static fn (): string => 'not a result');
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame('failed', $this->row($id)['status']);
        $this->assertSame('exception', $this->row($id)['last_error_class']);
        $this->assertSame('UnexpectedValueException', $this->row($id)['last_error']);
    }

    /** Test 27, case 27. */
    public function testJobLongerThanTheTimeLeftIsNotStarted(): void
    {
        $calls = 0;
        $this->type('demo.slow', function () use (&$calls): array {
            $calls++;
            usleep(1100000);
            return [];
        }, ['timeout' => 1]);
        $a = gen_queue_enqueue('demo.slow', []);
        $b = gen_queue_enqueue('demo.slow', []);
        $summary = $this->drain(['time' => 2]);
        $this->assertSame('time', $summary->stopReason);
        $this->assertSame(1, $calls);
        $this->assertSame('succeeded', $this->row($a)['status']);
        $this->assertSame('queued', $this->row($b)['status']);
        $this->assertSame(0, $this->row($b)['attempts']);
    }

    /** Test 27, case 27: one oversized job may run as a runner's first job. */
    public function testATimeoutLongerThanTheWholeBudgetRunsOnlyAsTheFirstJob(): void
    {
        $this->type('demo.long', null, ['timeout' => 120]);
        $a = gen_queue_enqueue('demo.long', []);
        $b = gen_queue_enqueue('demo.long', []);
        $summary = $this->drain(['time' => 25]);
        $this->assertSame('time', $summary->stopReason);
        $this->assertSame('succeeded', $this->row($a)['status']);
        $this->assertSame('queued', $this->row($b)['status']);
    }

    /** Test 28, case 28. */
    public function testStopReasonsMaxEmptyAndWaiting(): void
    {
        $this->type('demo.task');
        $this->assertSame('empty', $this->drain()->stopReason);
        gen_queue_enqueue('demo.task', []);
        gen_queue_enqueue('demo.task', []);
        $max = $this->drain(['max' => 1]);
        $this->assertSame('max', $max->stopReason);
        $this->assertSame(1, $max->claimed);
        gen_queue_enqueue('demo.task', [], ['run_after' => $this->store->clock + 60]);
        $waiting = $this->drain();
        $this->assertSame('waiting', $waiting->stopReason);
        $this->assertSame(1, $waiting->succeeded);
    }

    /** Test 12, case 21: higher priority is claimed first; FIFO within a priority. */
    public function testHigherPriorityFirstThenFifo(): void
    {
        $order = [];
        $this->type('demo.task', function (Job $job) use (&$order): array {
            $order[] = $job->payload['n'];
            return [];
        });
        gen_queue_enqueue('demo.task', ['n' => 'low-old'], ['priority' => -1]);
        gen_queue_enqueue('demo.task', ['n' => 'mid-old']);
        $this->store->clock += 10;
        gen_queue_enqueue('demo.task', ['n' => 'mid-new']);
        gen_queue_enqueue('demo.task', ['n' => 'high'], ['priority' => 100]);
        $this->drain();
        $this->assertSame(['high', 'mid-old', 'mid-new', 'low-old'], $order);
    }

    /** Test 13, case 13. */
    public function testFutureRunAfterIsNotClaimedUntilDue(): void
    {
        $this->type('demo.task');
        $id = gen_queue_enqueue('demo.task', [], ['run_after' => $this->store->clock + 120]);
        $this->assertSame('waiting', $this->drain()->stopReason);
        $this->assertSame('queued', $this->row($id)['status']);
        $this->store->clock += 120;
        $this->drain();
        $this->assertSame('succeeded', $this->row($id)['status']);
    }

    public function testTypeOptionRunsOnlyThatType(): void
    {
        $this->type('demo.a');
        $this->type('demo.b');
        $a = gen_queue_enqueue('demo.a', []);
        $b = gen_queue_enqueue('demo.b', []);
        $this->drain(['type' => 'demo.b']);
        $this->assertSame('queued', $this->row($a)['status']);
        $this->assertSame('succeeded', $this->row($b)['status']);
    }
}
