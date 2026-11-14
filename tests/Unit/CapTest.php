<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Unit;

use IndianOpinion\GenQueue\Days;
use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\JobContext;
use IndianOpinion\GenQueue\Money;
use IndianOpinion\GenQueue\Runner;
use IndianOpinion\GenQueue\Tests\Stubs\WpState;
use IndianOpinion\GenQueue\Tests\Support\TestCase;

final class CapTest extends TestCase
{
    /** Test 29, case 29. */
    public function testNoCapAndCapZeroRunNothing(): void
    {
        $calls = 0;
        $this->type('demo.task', function () use (&$calls): array {
            $calls++;
            return [];
        });
        $id = gen_queue_enqueue('demo.task', []);
        $summary = $this->drain();
        $this->assertSame('capped', $summary->stopReason);
        $this->assertNull(gen_queue_cap_remaining());
        $this->assertSame('no cap', $this->plugin->status->snapshot()['state']);

        $this->setCap('0');
        $this->assertSame('capped', $this->drain()->stopReason);
        $this->assertSame('0.00', gen_queue_cap_remaining());
        $this->assertSame(0, $calls);
        $this->assertSame(0, $this->row($id)['attempts']);
    }

    /** Test 30, case 30. */
    public function testJobThatWouldExceedTheCapIsLeftUntouched(): void
    {
        $this->setCap('1.00');
        $this->type('demo.task', static function (Job $job, JobContext $ctx): array {
            $ctx->addCost('0.70');
            return [];
        }, ['cost_estimate' => '0.40']);
        $a = gen_queue_enqueue('demo.task', []);
        $b = gen_queue_enqueue('demo.task', []);
        $summary = $this->drain();
        $this->assertSame('capped', $summary->stopReason);
        $this->assertSame('succeeded', $this->row($a)['status']);
        $before = $this->row($b);
        $this->assertSame('queued', $before['status']);
        $this->assertSame(0, $before['attempts']);
        $this->assertCount(0, $this->attempts($b));

        // The next day it runs.
        $this->store->clock += 86400;
        $this->drain();
        $this->assertSame('succeeded', $this->row($b)['status']);
    }

    /** Test 31, case 31. */
    public function testEstimateZeroRunsBelowTheCapAndNotAtIt(): void
    {
        $this->assertTrue(Runner::fits(99, 0, 100));
        $this->assertFalse(Runner::fits(100, 0, 100));
        $this->assertTrue(Runner::fits(60, 40, 100));
        $this->assertFalse(Runner::fits(61, 40, 100));

        $this->setCap('1');
        $this->type('demo.task', static function (Job $job, JobContext $ctx): array {
            $ctx->addCost('0.5');
            return [];
        });
        $ids = [gen_queue_enqueue('demo.task', []), gen_queue_enqueue('demo.task', []), gen_queue_enqueue('demo.task', [])];
        $this->drain();
        $this->assertSame(['succeeded', 'succeeded', 'queued'], array_map(fn (int $id): string => $this->row($id)['status'], $ids));
    }

    /** Test 32, case 32. */
    public function testReservationHeldWhileRunningAndReleasedAfter(): void
    {
        $this->setCap('1.00');
        $reservedDuring = null;
        $this->type('demo.task', function (Job $job, JobContext $ctx) use (&$reservedDuring): array {
            $reservedDuring = $this->store->reservedNanos();
            $ctx->addCost('0.65');
            return [];
        }, ['cost_estimate' => '0.50']);
        gen_queue_enqueue('demo.task', []);
        gen_queue_enqueue('demo.task', []);
        $summary = $this->drain();
        $this->assertSame(500000000, $reservedDuring);
        $this->assertSame(0, $this->store->reservedNanos());
        // First job: 0 + 0.50 <= 1.00 runs and costs 0.65. Second: 0.65 + 0.50 > 1.00 waits.
        $this->assertSame(1, $summary->succeeded);
        $spent = $this->store->spendForDay(Days::local($this->store->clock));
        $this->assertSame(650000000, $spent);
        // Overshoot never exceeds actual minus estimate.
        $this->assertLessThanOrEqual(1000000000 + (650000000 - 500000000), $spent);
    }

    /** Test 32, case 30: a second runner sees the first runner's reservation. */
    public function testConcurrentRunnerSeesTheReservation(): void
    {
        $this->setCap('1.00');
        $nested = null;
        $this->type('demo.task', function () use (&$nested): array {
            $nested ??= gen_queue_run(['time' => 600, 'worker' => 'second']);
            return [];
        }, ['cost_estimate' => '0.60']);
        gen_queue_enqueue('demo.task', []);
        gen_queue_enqueue('demo.task', []);
        $this->drain(['worker' => 'first']);
        $this->assertNotNull($nested);
        $this->assertSame(0, $nested->claimed);
        $this->assertSame('capped', $nested->stopReason);
    }

    /** Test 33, case 33. */
    public function testAttemptStartedBeforeMidnightCountsOnTheFirstDay(): void
    {
        WpState::$timezone = 'Europe/London';
        $this->setCap('10');
        $start = (new \DateTimeImmutable('2026-03-10 23:59:00', new \DateTimeZone('Europe/London')))->getTimestamp();
        $this->store->clock = $start;
        $this->type('demo.task', function (Job $job, JobContext $ctx): array {
            $this->store->clock += 120; // finishes at 00:01 the next day
            $ctx->addCost('2');
            return [];
        });
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame('2026-03-10', $this->attempts($id)[0]['cost_day']);
        $this->assertSame(2000000000, $this->store->spendForDay('2026-03-10'));
        $this->assertSame(0, $this->store->spendForDay('2026-03-11'));
        $this->assertSame('0.00', gen_queue_spend_today());
    }

    /** Test 33, case 33: DST change days. */
    public function testLocalDaysAcrossDaylightSavingChanges(): void
    {
        WpState::$timezone = 'Europe/London';
        // Clocks go forward at 01:00 UTC on 29 March 2026 and back at 01:00 UTC on 25 October 2026.
        $this->assertSame('2026-03-28', Days::local(gmmktime(23, 30, 0, 3, 28, 2026)));
        $this->assertSame('2026-03-29', Days::local(gmmktime(0, 30, 0, 3, 29, 2026)));
        $this->assertSame('2026-03-29', Days::local(gmmktime(22, 59, 0, 3, 29, 2026)));
        $this->assertSame('2026-03-30', Days::local(gmmktime(23, 0, 0, 3, 29, 2026)));
        $this->assertSame('2026-10-25', Days::local(gmmktime(23, 59, 0, 10, 25, 2026)));
        $this->assertSame('2026-10-25', Days::local(gmmktime(0, 30, 0, 10, 25, 2026)));
        $this->assertSame(23 * 3600, Days::startOf('2026-03-30') - Days::startOf('2026-03-29'));
        $this->assertSame(25 * 3600, Days::startOf('2026-10-26') - Days::startOf('2026-10-25'));
    }

    /** Test 34, case 34. */
    public function testSpendFilterCanRaiseTheFigure(): void
    {
        $this->setCap('1');
        $this->type('demo.task');
        $id = gen_queue_enqueue('demo.task', []);
        add_filter('gen_queue_spend_today', static fn (int $own): int => $own + Money::toNanos('1'));
        $this->assertSame('capped', $this->drain()->stopReason);
        $this->assertSame('queued', $this->row($id)['status']);
        $this->assertSame('1.00', gen_queue_spend_today());
    }

    /** Test 34, case 34. */
    public function testJunkFromTheSpendFilterIsHandledSafely(): void
    {
        $controls = $this->plugin->controls;
        $day = Days::local($this->store->clock);
        $this->store->insertAttempt(['job_id' => 1, 'type' => 'x', 'cost_nanos' => 500, 'cost_day' => $day, 'outcome' => 'success']);
        $cases = [
            'negative' => [-5, 500],
            'string number larger' => ['900', 900],
            'string number smaller' => ['100', 500],
            'float' => [700.2, 701],
            'garbage string' => ['lots', 500],
            'array' => [[1], 500],
            'null' => [null, 500],
        ];
        foreach ($cases as $label => [$return, $expected]) {
            remove_all_filters('gen_queue_spend_today');
            add_filter('gen_queue_spend_today', static fn (): mixed => $return);
            $this->assertSame($expected, $controls->spendToday($day), $label);
        }
        $this->assertGreaterThan(0, $this->logged('gen_queue_spend_today filter returned'));
    }

    /** Test 35, case 35. */
    public function testPerTypeCapStopsThatTypeOnlyAndTheStricterWins(): void
    {
        $this->setCap('10');
        $cost = static function (Job $job, JobContext $ctx): array {
            $ctx->addCost('1');
            return [];
        };
        $this->type('demo.small', $cost, ['daily_cap' => '2', 'cost_estimate' => '1']);
        $this->type('demo.other', $cost, ['cost_estimate' => '1']);
        $small = [];
        $other = [];
        for ($i = 0; $i < 4; $i++) {
            $small[] = gen_queue_enqueue('demo.small', []);
            $other[] = gen_queue_enqueue('demo.other', []);
        }
        $this->drain();
        $this->assertSame(2, count(array_filter($small, fn (int $id): bool => $this->row($id)['status'] === 'succeeded')));
        $this->assertSame(4, count(array_filter($other, fn (int $id): bool => $this->row($id)['status'] === 'succeeded')));
        $this->assertSame('2.00', gen_queue_spend_today('demo.small'));

        // A global cap stricter than the type cap wins.
        $this->store->clock += 86400;
        $this->setCap('1');
        $more = gen_queue_enqueue('demo.small', []);
        $this->drain();
        $done = array_filter([...$small, $more], fn (int $id): bool => $this->row($id)['status'] === 'succeeded');
        $this->assertCount(3, $done);
    }

    /** Test 36, case 36. */
    public function testCanRunFilterVetoesButCannotBeatTheCap(): void
    {
        $this->setCap('1');
        $this->type('demo.task', null, ['cost_estimate' => '2']);
        $this->type('demo.cheap');
        $big = gen_queue_enqueue('demo.task', []);
        add_filter('gen_queue_can_run', static fn (): bool => true);
        $this->drain();
        $this->assertSame('queued', $this->row($big)['status']);

        remove_all_filters('gen_queue_can_run');
        add_filter('gen_queue_can_run', static fn (bool $ok, Job $job): bool => $job->type !== 'demo.cheap', 10, 2);
        $cheap = gen_queue_enqueue('demo.cheap', []);
        $summary = $this->drain();
        $this->assertSame('queued', $this->row($cheap)['status']);
        $this->assertSame(0, $this->row($cheap)['attempts']);
        $this->assertSame(1, $summary->skipped);
    }

    /** Test 36: the switch cannot be overridden by the filter either. */
    public function testCanRunFilterCannotBeatTheSwitch(): void
    {
        $this->setCap('1');
        $this->type('demo.task');
        $id = gen_queue_enqueue('demo.task', []);
        add_filter('gen_queue_can_run', static fn (): bool => true);
        gen_queue_pause();
        $this->drain();
        $this->assertSame('queued', $this->row($id)['status']);
    }

    /** Test 37, case 37. */
    public function testCapReachedFiresOncePerDay(): void
    {
        $this->listen('gen_queue_cap_reached');
        $this->setCap('1');
        $this->type('demo.task', static function (Job $job, JobContext $ctx): array {
            $ctx->addCost('1');
            return [];
        });
        for ($i = 0; $i < 4; $i++) {
            gen_queue_enqueue('demo.task', []);
        }
        $this->drain();
        $this->drain();
        $this->drain();
        $fired = $this->fired('gen_queue_cap_reached');
        $this->assertCount(1, $fired);
        $this->assertSame(['1.00', '1.00', Days::local($this->store->clock)], $fired[0]);
        $this->assertSame(1, $this->logged('Daily cap reached'));
        $this->assertSame('capped', $this->plugin->status->snapshot()['state']);

        $this->store->clock += 86400;
        $this->drain();
        $this->drain();
        $this->assertCount(2, $this->fired('gen_queue_cap_reached'));
    }

    public function testCapAmountsAndFilter(): void
    {
        $this->setCap('2.5');
        $this->assertSame(2500000000, $this->plugin->controls->capNanos());
        add_filter('gen_queue_daily_cap', static fn (?int $cap): int => 7);
        $this->assertSame(7, $this->plugin->controls->capNanos());
        remove_all_filters('gen_queue_daily_cap');
        add_filter('gen_queue_daily_cap', static fn (): string => 'junk');
        $this->assertSame(2500000000, $this->plugin->controls->capNanos());
        $this->plugin->queue->setCap(null);
        remove_all_filters('gen_queue_daily_cap');
        $this->assertNull($this->plugin->controls->capNanos());
        $this->expectException(\InvalidArgumentException::class);
        $this->setCap('-1');
    }
}
