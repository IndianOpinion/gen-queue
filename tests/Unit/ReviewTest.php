<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Unit;

use IndianOpinion\GenQueue\AccountException;
use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\JobContext;
use IndianOpinion\GenQueue\Redactor;
use IndianOpinion\GenQueue\RetryableException;
use IndianOpinion\GenQueue\Tests\Support\TestCase;

/**
 * Races, cap accounting and the idle path: the cases a stale candidate list,
 * a used-up cap or an empty queue would otherwise get wrong.
 */
final class ReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setCap('100');
    }

    /** Cases 21 and 47: a cooldown that starts after the candidates were read still blocks the claim. */
    public function testCooldownStartedAfterSelectionBlocksTheClaim(): void
    {
        $calls = 0;
        $this->type('demo.cool', function () use (&$calls): array {
            $calls++;
            return [];
        }, ['resource_key' => 'acme/m1']);
        $id = gen_queue_enqueue('demo.cool', []);
        $started = false;
        add_filter('gen_queue_spend_today', function (int $own) use (&$started): int {
            if (!$started) {
                // Another runner's two failures on the same key, between selection and claim.
                $started = true;
                $this->plugin->breakers->failure('acme/m1', $this->store->clock);
                $this->plugin->breakers->failure('acme/m1', $this->store->clock);
            }
            return $own;
        });
        $summary = $this->drain();
        $this->assertSame(0, $calls);
        $this->assertSame('waiting', $summary->stopReason);
        $this->assertSame('queued', $this->row($id)['status']);
        $this->assertSame(0, $this->row($id)['attempts']);
    }

    /** Cases 22 and 39: a candidate that ran and went into backoff since it was read is not claimed. */
    public function testStaleCandidateAfterARetryIsNotClaimed(): void
    {
        $ran = [];
        $this->type('demo.task', function (Job $job) use (&$ran): array {
            $ran[] = $job->id;
            return [];
        });
        $first = gen_queue_enqueue('demo.task', [], ['priority' => 10]);
        $second = gen_queue_enqueue('demo.task', []);
        add_filter('gen_queue_can_run', function (bool $ok, Job $job) use ($first, $second): bool {
            if ($job->id === $first) {
                // Meanwhile another runner ran the second job, which failed and is in backoff.
                $this->store->jobs[$second]['attempts'] = 1;
                $this->store->jobs[$second]['run_after'] = $this->store->clock + 600;
                return false;
            }
            return $ok;
        }, 10, 2);
        $summary = $this->drain();
        $this->assertSame([], $ran);
        $this->assertSame('waiting', $summary->stopReason);
        $this->assertSame(1, $this->row($second)['attempts']);
        $this->assertSame('queued', $this->row($second)['status']);
    }

    /** Cases 30 and 31: with the cap used up the runner stops at once instead of walking the backlog. */
    public function testUsedUpCapStopsWithoutScanningTheBacklog(): void
    {
        $this->setCap('1');
        $this->type('demo.task', null, ['cost_estimate' => '0.01']);
        for ($i = 0; $i < 60; $i++) {
            gen_queue_enqueue('demo.task', []);
        }
        $spendReads = 0;
        $vetoChecks = 0;
        add_filter('gen_queue_spend_today', static function () use (&$spendReads): int {
            $spendReads++;
            return 1000000000;
        });
        add_filter('gen_queue_can_run', static function (bool $ok) use (&$vetoChecks): bool {
            $vetoChecks++;
            return $ok;
        });
        $summary = $this->drain();
        $this->assertSame('capped', $summary->stopReason);
        $this->assertSame(0, $summary->claimed);
        $this->assertSame(1, $spendReads);
        $this->assertSame(0, $vetoChecks);
    }

    /** Case 35: a used-up type cap skips that type for the run; other types still run. */
    public function testUsedUpTypeCapSkipsThatTypeAndOthersRun(): void
    {
        $capped = 0;
        $this->type('demo.capped', function (Job $job, JobContext $ctx) use (&$capped): array {
            $capped++;
            $ctx->addCost('0.50');
            return [];
        }, ['daily_cap' => '0.50']);
        $this->type('demo.other');
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = gen_queue_enqueue('demo.capped', []);
        }
        $other = gen_queue_enqueue('demo.other', []);
        $summary = $this->drain();
        $this->assertSame(1, $capped);
        $this->assertSame('succeeded', $this->row($other)['status']);
        $this->assertSame('capped', $summary->stopReason);
        $this->assertSame(0, $this->row($ids[1])['attempts']);
        $this->assertSame(0, $this->row($ids[2])['attempts']);
    }

    /** Section 9 and cases 19, 28, 29: an idle run reads twice and still reports the right reason. */
    public function testIdleRunIsTwoReadsWithTheRightReason(): void
    {
        $this->type('demo.task');
        $this->assertSame('empty', $this->drain()->stopReason);

        $runner = $this->plugin->runner;
        $this->store->queries = 0;
        $this->assertSame('empty', $runner->run(['time' => 25])->stopReason);
        $this->assertSame(2, $this->store->queries);
        $this->assertArrayNotHasKey('housekeeping', array_filter($this->store->locks, static fn (array $l): bool => $l['owner'] !== ''));

        gen_queue_enqueue('demo.task', [], ['run_after' => $this->store->clock + 600]);
        $this->assertSame('waiting', $runner->run(['time' => 25])->stopReason);

        $this->plugin->controls->setCap(0);
        $this->assertSame('capped', $runner->run(['time' => 25])->stopReason);
        $this->plugin->controls->setCap(null);
        $this->assertSame('capped', $runner->run(['time' => 25])->stopReason);

        gen_queue_pause();
        $this->assertSame('disabled', $runner->run(['time' => 25])->stopReason);
    }

    /** Case 74: cap 0 stops before any candidate is read, so the meter is never asked. */
    public function testCapZeroReadsNoCandidates(): void
    {
        $this->plugin->controls->setCap(0);
        $this->type('demo.task');
        gen_queue_enqueue('demo.task', []);
        $checks = 0;
        add_filter('gen_queue_can_run', static function (bool $ok) use (&$checks): bool {
            $checks++;
            return $ok;
        });
        $this->assertSame('capped', $this->drain()->stopReason);
        $this->assertSame(0, $checks);
    }

    /** Cases 17 and 27: the worker slot outlives a first job whose timeout is longer than the budget. */
    public function testSlotLeaseCoversTheLongestTimeout(): void
    {
        $until = 0;
        $this->type('demo.long', function () use (&$until): array {
            $until = (int) $this->store->locks['worker-1']['locked_until'];
            return [];
        }, ['timeout' => 120]);
        gen_queue_enqueue('demo.long', []);
        $this->drain(['time' => 25]);
        $this->assertGreaterThanOrEqual($this->store->clock + 120 + 60, $until);
    }

    /** Cases 46 and 47: breaker state is changed under its own lock row, which is released after. */
    public function testBreakerChangesTakeAndReleaseTheirLock(): void
    {
        $this->plugin->breakers->failure('acme/m', $this->store->clock);
        $this->assertSame('', $this->store->locks['breakers']['owner']);
        $this->plugin->breakers->success('acme/m');
        $this->assertSame([], $this->plugin->breakers->all());
    }

    /** Case 42: the group is on hold before the job goes back to the queue. */
    public function testAccountHoldIsSetBeforeTheJobIsRequeued(): void
    {
        $statusAtHold = null;
        $this->type('demo.acct', static function (): never {
            throw new AccountException('Payment required');
        }, ['group' => 'acct']);
        $id = gen_queue_enqueue('demo.acct', []);
        add_action('gen_queue_account_hold', function () use (&$statusAtHold, $id): void {
            $statusAtHold = $this->store->jobs[$id]['status'];
        });
        $this->drain();
        $this->assertSame('running', $statusAtHold);
        $this->assertSame('queued', $this->row($id)['status']);
    }

    /** Section 8: common credential shapes never reach storage. */
    public function testRedactorCoversCommonCredentialShapes(): void
    {
        $samples = [
            'GET /v1?access_token=abc123&x=1 failed' => 'abc123',
            'header x-api-key: abc123 refused' => 'abc123',
            '{"error":"bad","client_secret":"s3cr3t"}' => 's3cr3t',
            'connect to https://user:pa55@db.example.com/x failed' => 'pa55',
            'Authorization: Basic dXNlcjpwYXNz' => 'dXNlcjpwYXNz',
            'leaked AKIA' . 'ABCDEFGHIJKLMNOP here' => 'ABCDEFGHIJKLMNOP',
        ];
        foreach ($samples as $text => $secret) {
            $this->assertStringNotContainsString($secret, Redactor::clean($text), $text);
        }
        $plain = 'Rate limited (429) on model acme/m1; retry later.';
        $this->assertSame($plain, Redactor::clean($plain));
    }

    /** Case 38 as ruled: a retryable error's message is stored redacted; an unexpected one by class only. */
    public function testUnexpectedThrowableStoresTheClassNameOnly(): void
    {
        $this->type('demo.bug', static function (): never {
            throw new \RuntimeException('token=abc123 in /var/www/secret/path');
        });
        $this->type('demo.flaky', static function (): never {
            throw new RetryableException('upstream said password=hunter2');
        }, ['max_attempts' => 1]);
        $bug = gen_queue_enqueue('demo.bug', []);
        $flaky = gen_queue_enqueue('demo.flaky', []);
        $this->drain();
        $this->assertSame('RuntimeException', $this->row($bug)['last_error']);
        $this->assertSame('RuntimeException', $this->attempts($bug)[0]['error']);
        $this->assertStringNotContainsString('hunter2', (string) $this->row($flaky)['last_error']);
    }
}
