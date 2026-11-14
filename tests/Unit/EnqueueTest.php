<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Unit;

use IndianOpinion\GenQueue\InvalidPayloadException;
use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\Plugin;
use IndianOpinion\GenQueue\StorageException;
use IndianOpinion\GenQueue\Tests\Support\MemoryStore;
use IndianOpinion\GenQueue\Tests\Support\TestCase;
use IndianOpinion\GenQueue\UnknownTypeException;

final class EnqueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->type('demo.task');
    }

    /** Test 6, case 6. */
    public function testEnqueueReturnsIdStoresQueuedAndFiresOnce(): void
    {
        $this->listen('gen_queue_enqueued');
        $id = gen_queue_enqueue('demo.task', ['post' => 12]);
        $this->assertGreaterThan(0, $id);
        $this->assertSame('queued', $this->row($id)['status']);
        $this->assertSame('{"post":12}', $this->row($id)['payload']);
        $fired = $this->fired('gen_queue_enqueued');
        $this->assertCount(1, $fired);
        $this->assertInstanceOf(Job::class, $fired[0][0]);
        $this->assertSame($id, $fired[0][0]->id);
        $this->assertFalse($fired[0][1]);
    }

    /** Test 7, case 7. */
    public function testUnknownTypeThrowsUnlessAllowed(): void
    {
        try {
            gen_queue_enqueue('other.task', []);
            $this->fail('Unknown type accepted');
        } catch (UnknownTypeException $e) {
            $this->assertCount(0, $this->store->jobs);
        }
        $id = gen_queue_enqueue('other.task', ['x' => 1], ['allow_unregistered' => true]);
        $this->assertSame('queued', $this->row($id)['status']);
    }

    /** Test 8, case 8. */
    public function testBadPayloadsThrowAndStoreNothing(): void
    {
        $bad = [
            'object' => ['o' => new \stdClass()],
            'closure' => ['c' => static fn (): int => 1],
            'nested object' => ['a' => ['b' => [new \ArrayObject()]]],
            'invalid utf-8' => ['s' => "\xB1\x31"],
            'invalid utf-8 key' => ["\xB1" => 1],
            'nan' => ['n' => NAN],
            'oversized' => ['big' => str_repeat('x', 70000)],
        ];
        $resource = fopen('php://memory', 'r');
        $bad['resource'] = ['r' => $resource];
        foreach ($bad as $label => $payload) {
            try {
                gen_queue_enqueue('demo.task', $payload);
                $this->fail('Accepted payload: ' . $label);
            } catch (InvalidPayloadException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        if (is_resource($resource)) {
            fclose($resource);
        }
        $this->assertCount(0, $this->store->jobs);
    }

    public function testPayloadLimitIsFilterable(): void
    {
        add_filter('gen_queue_payload_max_bytes', static fn (): int => 10);
        $this->expectException(InvalidPayloadException::class);
        gen_queue_enqueue('demo.task', ['text' => 'longer than ten bytes']);
    }

    /** Test 9, case 9. */
    public function testSameKeyTwiceReturnsTheSameId(): void
    {
        $a = gen_queue_enqueue('demo.task', ['v' => 1], ['idempotency_key' => 'post-1']);
        $b = gen_queue_enqueue('demo.task', ['v' => 2], ['idempotency_key' => 'post-1']);
        $this->assertSame($a, $b);
        $this->assertCount(1, $this->store->jobs);
        $this->assertSame('{"v":1}', $this->row($a)['payload']);
        $this->assertSame($a, gen_queue_find('demo.task', 'post-1')?->id);
    }

    /** Test 9, case 9: the unique index decides a race. */
    public function testTwoRacingInsertsLeaveOneRow(): void
    {
        $winner = 0;
        $this->store->beforeInsertJob = function (array $row) use (&$winner): void {
            // Another process inserts the same (type, key) between our checks and our insert.
            $winner = (int) $this->store->insertJob($row);
        };
        $id = gen_queue_enqueue('demo.task', ['v' => 1], ['idempotency_key' => 'race']);
        $this->assertSame($winner, $id);
        $this->assertCount(1, $this->store->jobs);
    }

    public function testInvalidKeysAreRefused(): void
    {
        foreach (['', str_repeat('k', 192), "bad\nkey", "\xB1"] as $key) {
            try {
                gen_queue_enqueue('demo.task', [], ['idempotency_key' => $key]);
                $this->fail('Accepted key');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('idempotency_key', $e->getMessage());
            }
        }
        $id = gen_queue_enqueue('demo.task', [], ['idempotency_key' => str_repeat('k', 191)]);
        $this->assertGreaterThan(0, $id);
    }

    /** Test 10, case 10. */
    public function testTerminalJobWithReturnIsUnchangedAndRequeueRequeues(): void
    {
        $this->listen('gen_queue_enqueued');
        $id = gen_queue_enqueue('demo.task', ['v' => 1], ['idempotency_key' => 'k', 'max_attempts' => 2]);
        $this->store->jobs[$id]['status'] = 'failed';
        $this->store->jobs[$id]['attempts'] = 2;
        $this->store->jobs[$id]['finished_at'] = $this->store->clock;

        $this->assertSame($id, gen_queue_enqueue('demo.task', ['v' => 2], ['idempotency_key' => 'k']));
        $this->assertSame('failed', $this->row($id)['status']);
        $this->assertSame('{"v":1}', $this->row($id)['payload']);

        $this->assertSame($id, gen_queue_enqueue('demo.task', ['v' => 3], ['idempotency_key' => 'k', 'on_conflict' => 'requeue', 'max_attempts' => 3]));
        $row = $this->row($id);
        $this->assertSame('queued', $row['status']);
        $this->assertSame('{"v":3}', $row['payload']);
        $this->assertSame(5, $row['max_attempts']);
        $this->assertNull($row['finished_at']);
        $last = $this->fired('gen_queue_enqueued')[1];
        $this->assertTrue($last[1]);
    }

    /** Test 10, case 10. */
    public function testSucceededJobIsNeverRequeuedByEnqueue(): void
    {
        $id = gen_queue_enqueue('demo.task', ['v' => 1], ['idempotency_key' => 'done']);
        $this->store->jobs[$id]['status'] = 'succeeded';
        $this->assertSame($id, gen_queue_enqueue('demo.task', ['v' => 2], ['idempotency_key' => 'done', 'on_conflict' => 'requeue']));
        $this->assertSame('succeeded', $this->row($id)['status']);
        $this->assertSame('{"v":1}', $this->row($id)['payload']);
    }

    /** Test 11, case 11. */
    public function testNoKeyTwoCallsTwoRows(): void
    {
        $a = gen_queue_enqueue('demo.task', ['v' => 1]);
        $b = gen_queue_enqueue('demo.task', ['v' => 1]);
        $this->assertNotSame($a, $b);
        $this->assertCount(2, $this->store->jobs);
    }

    /** Test 12, case 12 (range). */
    public function testPriorityOutOfRangeThrows(): void
    {
        foreach ([101, -101] as $priority) {
            try {
                gen_queue_enqueue('demo.task', [], ['priority' => $priority]);
                $this->fail('Accepted priority ' . $priority);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('priority', $e->getMessage());
            }
        }
        $this->assertCount(0, $this->store->jobs);
    }

    /** Test 13, case 13 (storage side). */
    public function testRunAfterInThePastMeansNow(): void
    {
        $id = gen_queue_enqueue('demo.task', [], ['run_after' => 1000]);
        $this->assertSame($this->store->clock, $this->row($id)['run_after']);
        $later = gen_queue_enqueue('demo.task', [], ['run_after' => $this->store->clock + 600]);
        $this->assertSame($this->store->clock + 600, $this->row($later)['run_after']);
    }

    /** Test 14, case 14. */
    public function testExpirySetFromTtlAndTtlZeroMeansNever(): void
    {
        $a = gen_queue_enqueue('demo.task', [], ['ttl' => 120]);
        $this->assertSame($this->store->clock + 120, $this->row($a)['expires_at']);
        $b = gen_queue_enqueue('demo.task', [], ['ttl' => 0]);
        $this->assertNull($this->row($b)['expires_at']);
        $c = gen_queue_enqueue('demo.task', [], ['expires_at' => $this->store->clock + 99]);
        $this->assertSame($this->store->clock + 99, $this->row($c)['expires_at']);
        $d = gen_queue_enqueue('demo.task', []);
        $this->assertSame($this->store->clock + 604800, $this->row($d)['expires_at']);
    }

    /** Test 15, case 15. */
    public function testEnqueueManyInsertsInBatchesInInputOrder(): void
    {
        $items = [];
        for ($i = 0; $i < 1200; $i++) {
            $items[] = ['payload' => ['n' => $i]];
        }
        $before = $this->store->transactions;
        $ids = gen_queue_enqueue_many('demo.task', $items);
        $this->assertSame(3, $this->store->transactions - $before);
        $this->assertCount(1200, $ids);
        foreach ($ids as $i => $id) {
            $this->assertSame('{"n":' . $i . '}', $this->row($id)['payload']);
        }
    }

    /** Test 15, case 15. */
    public function testEnqueueManyWithOneBadItemInsertsNothing(): void
    {
        $items = [];
        for ($i = 0; $i < 600; $i++) {
            $items[] = ['payload' => ['n' => $i]];
        }
        $items[550] = ['payload' => ['bad' => new \stdClass()]];
        try {
            gen_queue_enqueue_many('demo.task', $items);
            $this->fail('Bad item accepted');
        } catch (InvalidPayloadException $e) {
            $this->assertStringContainsString('Item 550', $e->getMessage());
        }
        $this->assertCount(0, $this->store->jobs);
    }

    public function testEnqueueManyAppliesIdempotencyPerItem(): void
    {
        $first = gen_queue_enqueue('demo.task', ['v' => 0], ['idempotency_key' => 'a']);
        $ids = gen_queue_enqueue_many('demo.task', [
            ['payload' => ['v' => 1], 'args' => ['idempotency_key' => 'a']],
            ['payload' => ['v' => 2], 'args' => ['idempotency_key' => 'b']],
            ['payload' => ['v' => 3], 'args' => ['idempotency_key' => 'b']],
        ]);
        $this->assertSame($first, $ids[0]);
        $this->assertSame($ids[1], $ids[2]);
        $this->assertCount(2, $this->store->jobs);
    }

    /** Test 16, case 16. */
    public function testEnqueueWorksWithTheSwitchOffAndTheCapReached(): void
    {
        gen_queue_pause();
        $this->setCap('0');
        $id = gen_queue_enqueue('demo.task', []);
        $this->assertSame('queued', $this->row($id)['status']);
    }

    /** Test 62, case 62. */
    public function testStorageFailureOnEnqueueThrows(): void
    {
        $this->store->failNext('insertJob');
        $this->expectException(StorageException::class);
        gen_queue_enqueue('demo.task', []);
    }

    /** Test 72, section 5.1. */
    public function testSoftEnqueueFilter(): void
    {
        // Without gen-queue loaded nothing hooks the filter: the default comes back.
        $this->assertNull(apply_filters('gen_queue_enqueue_job', null, 'demo.task', [], []));

        $this->plugin->hook(__FILE__);
        $id = apply_filters('gen_queue_enqueue_job', null, 'demo.task', ['v' => 1], []);
        $this->assertIsInt($id);
        $this->assertSame('queued', $this->row($id)['status']);

        $error = apply_filters('gen_queue_enqueue_job', null, 'missing.type', [], []);
        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertSame('gen_queue_enqueue_failed', $error->get_error_code());
    }

    public function testUnknownEnqueueArgumentsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        gen_queue_enqueue('demo.task', [], ['prio' => 1]);
    }

    public function testFunctionsFailClearlyBeforeLoad(): void
    {
        $plugin = Plugin::boot(new MemoryStore());
        $this->assertTrue(Plugin::loaded());
        $this->assertSame($plugin, Plugin::instance());
    }
}
