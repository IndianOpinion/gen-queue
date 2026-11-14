<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Unit;

use IndianOpinion\GenQueue\Plugin;
use IndianOpinion\GenQueue\RegistrationException;
use IndianOpinion\GenQueue\Tests\Support\MemoryStore;
use IndianOpinion\GenQueue\Tests\Support\TestCase;

final class RegistrationTest extends TestCase
{
    /** Test 1, case 1. */
    public function testRegisteringInsideTheActionWorks(): void
    {
        $plugin = Plugin::boot(new MemoryStore());
        add_action('gen_queue_register_types', static function (): void {
            gen_queue_register_type('demo.inside', static fn (): null => null);
        });
        $plugin->openRegistration();
        $this->assertTrue($plugin->registry->has('demo.inside'));
        $this->assertSame(0, $this->logged('registered after'));
    }

    /** Test 1, case 1. */
    public function testRegisteringBeforeTheActionThrows(): void
    {
        $plugin = Plugin::boot(new MemoryStore());
        $this->expectException(RegistrationException::class);
        $plugin->registry->register('demo.early', static fn (): null => null);
    }

    /** Test 1, case 1. */
    public function testRegisteringAfterTheActionLogsANotice(): void
    {
        $plugin = Plugin::boot(new MemoryStore());
        $plugin->openRegistration();
        $plugin->registry->register('demo.late', static fn (): null => null);
        $this->assertTrue($plugin->registry->has('demo.late'));
        $this->assertSame(1, $this->logged('"demo.late" was registered after'));
    }

    /** Test 2, case 2. */
    public function testInvalidNamesThrow(): void
    {
        foreach (['', 'Upper.case', '-leading', 'has space', str_repeat('a', 65), 'bad/slash', 'émoji'] as $name) {
            try {
                $this->type($name);
                $this->fail('Accepted invalid name ' . $name);
            } catch (RegistrationException $e) {
                $this->assertStringContainsString('Invalid job type name', $e->getMessage());
            }
        }
        $this->type(str_repeat('a', 64));
        $this->type('my-plugin.summary_v2');
        $this->assertTrue($this->plugin->registry->has('my-plugin.summary_v2'));
    }

    /** Test 3, case 3. */
    public function testDuplicateRegistrationThrowsAndTheFirstStays(): void
    {
        $first = static fn (): array => ['first' => true];
        $this->type('demo.dup', $first);
        try {
            $this->type('demo.dup', static fn (): array => ['second' => true]);
            $this->fail('Duplicate accepted');
        } catch (RegistrationException $e) {
            $this->assertStringContainsString('already registered', $e->getMessage());
        }
        $handler = $this->plugin->registry->get('demo.dup')?->handler;
        $this->assertNotNull($handler);
        $this->assertSame(['first' => true], $handler());
    }

    /** Test 4, case 4. */
    public function testNonCallableHandlerThrows(): void
    {
        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('not callable');
        gen_queue_register_type('demo.nocall', 'no_such_function_anywhere');
    }

    public function testUnknownArgumentsAndLifoAreRefused(): void
    {
        try {
            $this->type('demo.bad', null, ['colour' => 'red']);
            $this->fail('Unknown argument accepted');
        } catch (RegistrationException $e) {
            $this->assertStringContainsString('colour', $e->getMessage());
        }
        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('fifo');
        $this->type('demo.lifo', null, ['order' => 'lifo']);
    }

    /** Test 5, case 5. */
    public function testJobInheritsTypeDefaultsAndPerJobOverridesWin(): void
    {
        $this->type('demo.defaults', null, [
            'max_attempts' => 7,
            'priority' => 3,
            'ttl' => 3600,
            'group' => 'acct-a',
            'resource_key' => 'acme/model-x',
            'cost_estimate' => '0.25',
        ]);
        $type = $this->plugin->registry->get('demo.defaults');
        $this->assertNotNull($type);
        $this->assertSame(30, $type->backoffBase);
        $this->assertSame(3600, $type->backoffMax);
        $this->assertSame(120, $type->timeout);
        $this->assertNull($type->dailyCapNanos);
        $this->assertNull($type->meter);

        $id = gen_queue_enqueue('demo.defaults', ['a' => 1]);
        $row = $this->row($id);
        $this->assertSame(7, $row['max_attempts']);
        $this->assertSame(3, $row['priority']);
        $this->assertSame($this->store->clock + 3600, $row['expires_at']);
        $this->assertSame('acct-a', $row['group_key']);
        $this->assertSame('acme/model-x', $row['resource_key']);
        $this->assertSame(250000000, $row['estimate_nanos']);
        $this->assertSame('demo', $row['source']);

        $id2 = gen_queue_enqueue('demo.defaults', ['a' => 2], [
            'max_attempts' => 2,
            'priority' => -5,
            'ttl' => 0,
            'group' => 'acct-b',
            'resource_key' => 'other/model-y',
            'cost_estimate' => 1,
            'source' => 'backfill',
        ]);
        $row2 = $this->row($id2);
        $this->assertSame(2, $row2['max_attempts']);
        $this->assertSame(-5, $row2['priority']);
        $this->assertNull($row2['expires_at']);
        $this->assertSame('acct-b', $row2['group_key']);
        $this->assertSame('other/model-y', $row2['resource_key']);
        $this->assertSame(1000000000, $row2['estimate_nanos']);
        $this->assertSame('backfill', $row2['source']);
    }

    public function testFallbackDefaultsForAJobOfAnUnregisteredType(): void
    {
        $id = gen_queue_enqueue('later.plugin', [], ['allow_unregistered' => true]);
        $row = $this->row($id);
        $this->assertSame(5, $row['max_attempts']);
        $this->assertSame('later.plugin', $row['group_key']);
        $this->assertSame('later.plugin', $row['resource_key']);
        $this->assertSame('later', $row['source']);
    }
}
