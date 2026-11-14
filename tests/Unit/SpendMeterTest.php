<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Unit;

use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\JobContext;
use IndianOpinion\GenQueue\RetryableException;
use IndianOpinion\GenQueue\SpendMeter;
use IndianOpinion\GenQueue\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * spend-meter is present when SPEND_METER_API is defined, so each test runs
 * in its own process.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SpendMeterTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $intents = [];

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('SPEND_METER_API')) {
            define('SPEND_METER_API', 1);
        }
        $this->intents = [];
        $this->calls = [];
        add_action('spend_meter_record', function (array $call): void {
            $this->calls[] = $call;
        });
    }

    private function meterReturns(mixed $decision): void
    {
        add_filter('spend_meter_pre_spend', function (mixed $pre, array $intent) use ($decision): mixed {
            $this->intents[] = $intent;
            if ($decision instanceof \Throwable) {
                throw $decision;
            }
            return $decision;
        }, 10, 2);
    }

    /** Test 76, case 71. */
    public function testIntentHasExactKeysDryRunAndEstimateInNanos(): void
    {
        $this->setCap('10');
        $this->meterReturns(null);
        $this->type('demo.alt-text', null, ['cost_estimate' => '0.0125']);
        $id = gen_queue_enqueue('demo.alt-text', []);
        $this->drain();
        $this->assertCount(1, $this->intents);
        $intent = $this->intents[0];
        $this->assertSame(['purpose', 'caller', 'provider', 'model', 'estimate_override', 'dry_run', 'meta'], array_keys($intent));
        $this->assertSame('demo.alt-text', $intent['purpose']);
        $this->assertSame('gen-queue', $intent['caller']);
        $this->assertSame('gen-queue', $intent['provider']);
        $this->assertSame('job', $intent['model']);
        $this->assertSame(12500000, $intent['estimate_override']);
        $this->assertTrue($intent['dry_run']);
        $this->assertSame(['job_id' => $id], $intent['meta']);
    }

    /** Test 76, case 71: the type's meter settings and a purpose made to fit. */
    public function testMeterSettingsAndOddPurposes(): void
    {
        $this->setCap('10');
        $this->meterReturns(['allowed' => true]);
        $this->type('demo.task', null, ['meter' => ['purpose' => 'Alt Text: Images!', 'provider' => 'acme', 'model' => 'vision-1']]);
        gen_queue_enqueue('demo.task', []);
        $this->drain();
        $intent = $this->intents[0];
        $this->assertSame('alt-text--images-', $intent['purpose']);
        $this->assertSame('acme', $intent['provider']);
        $this->assertSame('vision-1', $intent['model']);
        $this->assertMatchesRegularExpression(SpendMeter::PURPOSE_PATTERN, SpendMeter::purpose('__Weird Ünïcode Type__' . str_repeat('x', 80)));
        $this->assertSame(64, strlen(SpendMeter::purpose(str_repeat('a', 100))));
        $this->assertSame('gen-queue', SpendMeter::purpose('***'));
    }

    /**
     * Test 77, case 72.
     *
     * @return array<string, array{0: mixed, 1: string, 2: bool}>
     */
    public static function decisions(): array
    {
        return [
            'null' => [null, 'empty', true],
            'allowed' => [['allowed' => true, 'reason' => 'ok', 'api' => 1], 'empty', true],
            'cap_site_day' => [['allowed' => false, 'reason' => 'cap_site_day', 'message' => 'Site cap reached'], 'capped', false],
            'kill_switch' => [['allowed' => false, 'reason' => 'kill_switch'], 'disabled', false],
            'unknown code' => [['allowed' => false, 'reason' => 'something_new'], 'capped', false],
            'malformed' => [['ok' => 'sure'], 'capped', false],
            'not an array' => ['yes', 'capped', false],
            'thrown' => [new \RuntimeException('meter down'), 'capped', false],
        ];
    }

    /** Test 77, case 72. */
    #[\PHPUnit\Framework\Attributes\DataProvider('decisions')]
    public function testDecisions(mixed $decision, string $reason, bool $runs): void
    {
        $this->setCap('10');
        $this->meterReturns($decision);
        $calls = 0;
        $this->type('demo.task', static function () use (&$calls): array {
            $calls++;
            return [];
        });
        $id = gen_queue_enqueue('demo.task', []);
        $summary = $this->drain();
        $this->assertSame($reason, $summary->stopReason);
        $this->assertSame($runs ? 1 : 0, $calls);
        $row = $this->row($id);
        $this->assertSame($runs ? 'succeeded' : 'queued', $row['status']);
        if (!$runs) {
            $this->assertSame(0, $row['attempts']);
            $this->assertCount(0, $this->attempts($id));
            $this->assertNotNull($this->plugin->status->snapshot()['last_meter_denial']);
        }
    }

    /** Test 77, case 72: a denial is logged once per event, not per job. */
    public function testDenialIsLoggedOnce(): void
    {
        $this->setCap('10');
        $this->meterReturns(['allowed' => false, 'reason' => 'cap_site_day']);
        $this->type('demo.task');
        for ($i = 0; $i < 5; $i++) {
            gen_queue_enqueue('demo.task', []);
        }
        $this->drain();
        $this->assertCount(5, $this->intents);
        $this->assertSame(1, $this->logged('reason cap_site_day'));
    }

    /** Test 78, case 73. */
    public function testRecordsAreForwardedOnceUnlessMeteredOrZero(): void
    {
        $this->setCap('10');
        $this->meterReturns(null);
        $uuid = '0b6f4c1e-8f2a-4c3d-9e5b-1a2b3c4d5e6f';
        $this->type('demo.task', static function (Job $job, JobContext $ctx) use ($uuid): array {
            $ctx->addCost('0.02', ['input_units' => 120, 'output_units' => 30, 'usage_ref' => $uuid]);
            $ctx->addCost('0.03', ['usage_ref' => 'provider-gen-77']);
            $ctx->addCost('0.04', ['usage_ref' => 'res-1', 'metered' => true]);
            $ctx->addCost('0');
            return [];
        }, ['meter' => ['purpose' => 'summaries', 'provider' => 'acme', 'model' => 'm1']]);
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertCount(2, $this->calls);
        [$first, $second] = $this->calls;
        $this->assertSame([
            'purpose', 'caller', 'provider', 'model', 'provider_cost', 'input_tokens', 'output_tokens',
            'status', 'attempt', 'call_id', 'meta',
        ], array_keys($first));
        $this->assertSame('summaries', $first['purpose']);
        $this->assertSame('gen-queue', $first['caller']);
        $this->assertSame('acme', $first['provider']);
        $this->assertSame('m1', $first['model']);
        $this->assertSame(20000000, $first['provider_cost']);
        $this->assertSame(120, $first['input_tokens']);
        $this->assertSame(30, $first['output_tokens']);
        $this->assertSame('ok', $first['status']);
        $this->assertSame(1, $first['attempt']);
        $this->assertSame($uuid, $first['call_id']);
        $this->assertSame(['job_id' => $id], $first['meta']);

        $this->assertMatchesRegularExpression(SpendMeter::UUID_PATTERN, $second['call_id']);
        $this->assertNotSame('provider-gen-77', $second['call_id']);
        $this->assertSame(['job_id' => $id, 'usage_ref' => 'provider-gen-77'], $second['meta']);
        $this->assertSame(30000000, $second['provider_cost']);

        // The queue's own records keep all of it.
        $this->assertSame(90000000, $this->row($id)['cost_nanos']);
    }

    /** Test 78, case 73: a billed failure forwards as error_billed. */
    public function testBilledFailureForwardsAsErrorBilled(): void
    {
        $this->setCap('10');
        $this->meterReturns(null);
        $this->type('demo.task', static function (Job $job, JobContext $ctx): never {
            $ctx->addCost('0.05');
            throw new RetryableException('truncated');
        }, ['max_attempts' => 1]);
        gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertCount(1, $this->calls);
        $this->assertSame('error_billed', $this->calls[0]['status']);
    }

    /** Test 79, case 74. */
    public function testNoCapStillRunsNothingAndTheMeterIsNeverAsked(): void
    {
        $this->meterReturns(['allowed' => true]);
        $calls = 0;
        $this->type('demo.task', static function () use (&$calls): array {
            $calls++;
            return [];
        });
        gen_queue_enqueue('demo.task', []);
        $this->assertSame('capped', $this->drain()->stopReason);
        $this->setCap('0');
        $this->assertSame('capped', $this->drain()->stopReason);
        $this->assertSame(0, $calls);
        $this->assertSame([], $this->intents);
    }
}
