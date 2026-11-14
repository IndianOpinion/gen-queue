<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Unit;

use IndianOpinion\GenQueue\AccountException;
use IndianOpinion\GenQueue\Backoff;
use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\JobContext;
use IndianOpinion\GenQueue\PermanentException;
use IndianOpinion\GenQueue\RetryableException;
use IndianOpinion\GenQueue\Tests\Support\TestCase;

final class ErrorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setCap('100');
    }

    private function failing(string $name, \Throwable $error, array $args = []): int
    {
        $this->type($name, static function () use ($error): never {
            throw $error;
        }, $args);
        return gen_queue_enqueue($name, []);
    }

    /** Test 38, case 38, with ruling 3 for an unexpected Throwable. */
    public function testEachExceptionMapsToItsErrorClass(): void
    {
        $retry = $this->failing('demo.retry', new RetryableException('HTTP 503 from provider'));
        $perm = $this->failing('demo.perm', new PermanentException('Bad request: prompt refused'));
        $acct = $this->failing('demo.acct', new AccountException('Out of credit'));
        $plain = $this->failing('demo.plain', new \Error('Call to undefined function secret_thing() with key=abc123'));
        $this->drain();

        $this->assertSame(['queued', 'retryable', 'HTTP 503 from provider'], [$this->row($retry)['status'], $this->row($retry)['last_error_class'], $this->row($retry)['last_error']]);
        $this->assertSame(['failed', 'permanent'], [$this->row($perm)['status'], $this->row($perm)['last_error_class']]);
        $this->assertSame(['queued', 'account'], [$this->row($acct)['status'], $this->row($acct)['last_error_class']]);

        // Ruling 3: an unexpected Throwable is not retried, and only its class name is kept.
        $row = $this->row($plain);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('exception', $row['last_error_class']);
        $this->assertSame('Error', $row['last_error']);
        $this->assertSame(1, $row['attempts']);
        $attempt = $this->attempts($plain)[0];
        $this->assertSame('failed', $attempt['outcome']);
        $this->assertSame('Error', $attempt['error']);
        foreach ($this->store->jobs as $job) {
            $this->assertStringNotContainsString('abc123', (string) $job['last_error']);
        }
    }

    /** Test 39, case 39. */
    public function testBackoffIsWithinJitterBoundsAndHonoursRetryAfter(): void
    {
        $job = new Job(1, 't', 'queued', [], null, 0, 1, 5, 'g', 'r', 's', 0, null, '0.00', '0.00', null, null, null, false, 0, 0, null);
        $error = new RetryableException('x');
        for ($i = 0; $i < 200; $i++) {
            $d1 = Backoff::delay(30, 3600, 1, null, $job, $error);
            $this->assertGreaterThanOrEqual(0, $d1);
            $this->assertLessThanOrEqual(30, $d1);
            $d3 = Backoff::delay(30, 3600, 3, null, $job, $error);
            $this->assertLessThanOrEqual(120, $d3);
            $d20 = Backoff::delay(30, 3600, 20, null, $job, $error);
            $this->assertLessThanOrEqual(3600, $d20);
            $this->assertGreaterThanOrEqual(45, Backoff::delay(30, 3600, 1, 45, $job, $error));
        }
        $this->assertSame(0, Backoff::delay(0, 0, 1, null, $job, $error));
    }

    /** Test 39, case 39. */
    public function testBackoffFilterIsClamped(): void
    {
        $job = new Job(1, 't', 'queued', [], null, 0, 1, 5, 'g', 'r', 's', 0, null, '0.00', '0.00', null, null, null, false, 0, 0, null);
        $error = new RetryableException('x');
        add_filter('gen_queue_backoff_delay', static fn (): int => 999999);
        $this->assertSame(86400, Backoff::delay(30, 3600, 1, null, $job, $error));
        remove_all_filters('gen_queue_backoff_delay');
        add_filter('gen_queue_backoff_delay', static fn (): int => -50);
        $this->assertSame(0, Backoff::delay(30, 3600, 1, null, $job, $error));
        remove_all_filters('gen_queue_backoff_delay');
        add_filter('gen_queue_backoff_delay', static fn (): int => 7);
        $this->assertSame(7, Backoff::delay(30, 3600, 1, null, $job, $error));
    }

    /** Test 39, case 39: the retry is scheduled with the delay and fires its action. */
    public function testRetryIsScheduled(): void
    {
        $this->listen('gen_queue_retry_scheduled');
        add_filter('gen_queue_backoff_delay', static fn (): int => 90);
        $id = $this->failing('demo.retry', new RetryableException('Truncated output', 10));
        $start = $this->store->clock;
        $this->drain();
        $this->assertSame($start + 90, $this->row($id)['run_after']);
        $fired = $this->fired('gen_queue_retry_scheduled');
        $this->assertCount(1, $fired);
        $this->assertSame($start + 90, $fired[0][2]);
        $this->assertSame('retry', $this->attempts($id)[0]['outcome']);
    }

    /** Test 40, case 40. */
    public function testLastRetryableAttemptEndsFailed(): void
    {
        $this->listen('gen_queue_failed');
        add_filter('gen_queue_backoff_delay', static fn (): int => 0);
        $id = $this->failing('demo.retry', new RetryableException('Timeout'), ['max_attempts' => 3, 'resource_key' => 'r1']);
        $this->plugin->controls->updateSettings(['cooldown_threshold' => 100]);
        $this->drain();
        $row = $this->row($id);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(3, $row['attempts']);
        $this->assertSame(['retry', 'retry', 'failed'], array_column($this->attempts($id), 'outcome'));
        $this->assertCount(1, $this->fired('gen_queue_failed'));
    }

    /** Test 41, case 41. */
    public function testPermanentFailsAtOnceAndLeavesTheBreakerAlone(): void
    {
        $id = $this->failing('demo.perm', new PermanentException('Post no longer exists'), ['max_attempts' => 5, 'resource_key' => 'acme/m']);
        $this->drain();
        $this->assertSame('failed', $this->row($id)['status']);
        $this->assertSame(1, $this->row($id)['attempts']);
        $this->assertSame([], $this->plugin->breakers->all());
    }

    /** Test 42, case 42. */
    public function testAccountErrorRequeuesWithoutAnAttemptAndHoldsTheGroup(): void
    {
        $this->listen('gen_queue_account_hold');
        $this->type('demo.acct', static function (): never {
            throw new AccountException('Payment required: balance empty');
        }, ['group' => 'acct', 'resource_key' => 'acme/m1']);
        $this->type('demo.sibling', null, ['group' => 'acct', 'resource_key' => 'acme/m2']);
        $this->type('demo.free', null, ['group' => 'other']);
        $a = gen_queue_enqueue('demo.acct', [], ['priority' => 10]);
        $sibling = gen_queue_enqueue('demo.sibling', []);
        $free = gen_queue_enqueue('demo.free', []);
        $summary = $this->drain();

        $row = $this->row($a);
        $this->assertSame('queued', $row['status']);
        $this->assertSame(0, $row['attempts']);
        $this->assertSame('account', $this->attempts($a)[0]['outcome']);
        $this->assertSame('queued', $this->row($sibling)['status']);
        $this->assertSame('succeeded', $this->row($free)['status']);
        $this->assertSame('waiting', $summary->stopReason);
        $this->assertSame([], $this->plugin->breakers->all());
        $this->assertCount(1, $this->fired('gen_queue_account_hold'));
        $this->assertSame('acct', $this->fired('gen_queue_account_hold')[0][0]);
        $this->assertSame(1, $this->logged('account hold'));

        // Still held: nothing new is claimed and the log does not repeat.
        $this->drain();
        $this->assertSame(0, $this->row($a)['attempts']);
        $this->assertSame(1, $this->logged('account hold'));

        // After the hold the group runs again.
        $this->assertTrue(gen_queue_cancel($a));
        $this->store->clock += 3601;
        $this->drain();
        $this->assertSame('succeeded', $this->row($sibling)['status']);
    }

    /** Test 43, case 43. */
    public function testCostAddedBeforeAThrowIsRecorded(): void
    {
        $this->type('demo.billed', static function (Job $job, JobContext $ctx): never {
            $ctx->addCost('0.30', ['output_units' => 4000]);
            throw new RetryableException('Truncated response');
        }, ['max_attempts' => 1]);
        $id = gen_queue_enqueue('demo.billed', []);
        $summary = $this->drain();
        $this->assertSame('failed', $this->row($id)['status']);
        $this->assertSame(300000000, $this->row($id)['cost_nanos']);
        $this->assertSame(300000000, $this->attempts($id)[0]['cost_nanos']);
        $this->assertSame('0.30', $summary->costTotal);
        $this->assertSame('0.30', gen_queue_spend_today());
    }

    public function testRetryableExceptionClampsNegativeRetryAfter(): void
    {
        $this->assertSame(0, (new RetryableException('x', -5))->retryAfter());
        $this->assertNull((new RetryableException('x'))->retryAfter());
    }

    /** Test 73, section 8: secrets are redacted from stored errors. */
    public function testSecretsAreRedactedFromStoredErrors(): void
    {
        $id = $this->failing('demo.secret', new PermanentException('401 for Bearer abcdef.123 and key=sk-live-123456789 ' . str_repeat('y', 600)));
        $this->drain();
        $error = (string) $this->row($id)['last_error'];
        $this->assertStringNotContainsString('abcdef.123', $error);
        $this->assertStringNotContainsString('sk-live', $error);
        $this->assertStringContainsString('Bearer [redacted]', $error);
        $this->assertLessThanOrEqual(500, strlen($error));
    }
}
