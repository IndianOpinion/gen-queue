<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Unit;

use IndianOpinion\GenQueue\AccountException;
use IndianOpinion\GenQueue\Breakers;
use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\JobContext;
use IndianOpinion\GenQueue\PermanentException;
use IndianOpinion\GenQueue\RetryableException;
use IndianOpinion\GenQueue\Tests\Support\TestCase;

final class CooldownTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setCap('100');
        add_filter('gen_queue_backoff_delay', static fn (): int => 0);
    }

    /** Test 46, case 46. */
    public function testCounterIncrementsOnRetryableClearsOnSuccessIgnoresOthers(): void
    {
        $breakers = $this->plugin->breakers;
        $now = $this->store->clock;
        $breakers->failure('acme/m', $now);
        $this->assertSame(1, $breakers->all()['acme/m']['count']);
        $breakers->success('acme/m');
        $this->assertArrayNotHasKey('acme/m', $breakers->all());

        $mode = 'perm';
        $this->type('demo.task', static function () use (&$mode): array {
            throw match ($mode) {
                'perm' => new PermanentException('no'),
                'acct' => new AccountException('no credit'),
                default => new RetryableException('flaky'),
            };
        }, ['resource_key' => 'acme/m', 'max_attempts' => 1]);
        gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame([], $breakers->all());

        $mode = 'acct';
        $held = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame([], $breakers->all());
        $this->assertTrue(gen_queue_cancel($held));
        $breakers->release('demo.task');

        $mode = 'retry';
        gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame(1, $breakers->all()['acme/m']['count']);
    }

    /** Test 47, case 47. */
    public function testTwoFailuresCoolTheKeyWithOneLogLine(): void
    {
        $this->listen('gen_queue_cooldown_started');
        $calls = 0;
        $this->type('demo.task', static function () use (&$calls): never {
            $calls++;
            throw new RetryableException('Server error');
        }, ['resource_key' => 'acme/model-x', 'max_attempts' => 10]);
        $a = gen_queue_enqueue('demo.task', []);
        $b = gen_queue_enqueue('demo.task', []);
        $c = gen_queue_enqueue('demo.task', []);
        $summary = $this->drain();
        $this->assertSame(2, $calls);
        $this->assertSame('waiting', $summary->stopReason);
        $until = gen_queue_resource_cooling('acme/model-x');
        $this->assertSame($this->store->clock + 600, $until);
        $this->assertCount(1, $this->fired('gen_queue_cooldown_started'));
        $this->assertSame(['acme/model-x', $until], $this->fired('gen_queue_cooldown_started')[0]);
        $this->assertSame(1, $this->logged('is cooling'));
        $this->assertSame(0, $this->row($c)['attempts']);
        $this->assertSame(1, $this->row($a)['attempts'] + $this->row($b)['attempts'] - 1);
        $this->assertArrayHasKey('gen_queue_breakers_acme', $this->store->options);
    }

    /** Test 48, case 48. */
    public function testAfterTheCooldownASingleFailureCoolsAgain(): void
    {
        $this->listen('gen_queue_cooldown_started');
        $this->type('demo.task', static function (): never {
            throw new RetryableException('Server error');
        }, ['resource_key' => 'acme/model-x', 'max_attempts' => 10]);
        for ($i = 0; $i < 4; $i++) {
            gen_queue_enqueue('demo.task', []);
        }
        $this->drain();
        $this->assertCount(1, $this->fired('gen_queue_cooldown_started'));
        $this->store->clock += 601;
        $this->assertNull(gen_queue_resource_cooling('acme/model-x'));
        $this->drain();
        $this->assertCount(2, $this->fired('gen_queue_cooldown_started'));
        $this->assertSame(3, $this->plugin->breakers->all()['acme/model-x']['count']);
    }

    /** Test 49, case 49. */
    public function testPerJobResourceKeyCoolsOnlyThatKey(): void
    {
        $this->type('demo.task', static function (Job $job): array {
            if ($job->resourceKey === 'sandbox/model-x') {
                throw new RetryableException('Sandbox down');
            }
            return [];
        }, ['resource_key' => 'acme/model-x', 'max_attempts' => 10]);
        gen_queue_enqueue('demo.task', [], ['resource_key' => 'sandbox/model-x']);
        gen_queue_enqueue('demo.task', [], ['resource_key' => 'sandbox/model-x']);
        $live = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertNotNull(gen_queue_resource_cooling('sandbox/model-x'));
        $this->assertNull(gen_queue_resource_cooling('acme/model-x'));
        $this->assertSame('succeeded', $this->row($live)['status']);
        // One option per provider, keyed by model.
        $this->assertArrayHasKey('gen_queue_breakers_sandbox', $this->store->options);
        $this->assertArrayNotHasKey('gen_queue_breakers_acme', $this->store->options);
    }

    /** Test 50, case 50. */
    public function testResourceCoolingAndUseResource(): void
    {
        $this->plugin->breakers->failure('acme/primary', $this->store->clock);
        $this->plugin->breakers->failure('acme/primary', $this->store->clock);
        $this->type('demo.task', static function (Job $job, JobContext $ctx): array {
            $key = gen_queue_resource_cooling('acme/primary') !== null ? 'acme/fallback' : 'acme/primary';
            $ctx->useResource($key);
            if ($key === 'acme/fallback') {
                throw new RetryableException('fallback flaky');
            }
            return [];
        }, ['resource_key' => 'acme/other', 'max_attempts' => 1]);
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame('acme/fallback', $this->attempts($id)[0]['resource_key']);
        $this->assertSame(1, $this->plugin->breakers->all()['acme/fallback']['count']);
        $this->assertArrayNotHasKey('acme/other', $this->plugin->breakers->all());
    }

    public function testProviderNamesAreSafeOptionSuffixes(): void
    {
        $this->assertSame('default', Breakers::provider('plain-model'));
        $this->assertSame('acme', Breakers::provider('acme/model'));
        $this->assertSame('default', Breakers::provider('/odd'));
        $odd = Breakers::provider('Big Co:eu/model');
        $this->assertMatchesRegularExpression('/^[a-z0-9_-]+$/', $odd);
        $this->assertNotSame(Breakers::provider('a-b/x'), Breakers::provider('a:b/x'));
    }
}
