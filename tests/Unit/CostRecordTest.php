<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Unit;

use IndianOpinion\GenQueue\Days;
use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\JobContext;
use IndianOpinion\GenQueue\Tests\Support\TestCase;

final class CostRecordTest extends TestCase
{
    /** Test 71, section 5.6. */
    public function testEachRecordArrivesOnceWithMeteredAndUsageRef(): void
    {
        $this->setCap('10');
        $this->plugin->controls->setCurrency('EUR');
        $this->listen('gen_queue_cost_recorded');
        $this->type('demo.task', static function (Job $job, JobContext $ctx): array {
            $ctx->addCost('0.10', ['input_units' => 100, 'output_units' => 50, 'usage_ref' => 'call-a', 'metered' => true]);
            $ctx->addCost('0.05');
            return [];
        }, ['group' => 'acct', 'resource_key' => 'acme/m']);
        $id = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $records = array_map(static fn (array $args): array => $args[0], $this->fired('gen_queue_cost_recorded'));
        $this->assertCount(2, $records);
        $first = $records[0];
        $this->assertSame([
            'job_id', 'attempt', 'type', 'group', 'resource_key', 'amount', 'amount_nanos', 'currency', 'day',
            'input_units', 'output_units', 'usage_ref', 'metered', 'recorded_at',
        ], array_keys($first));
        $this->assertSame($id, $first['job_id']);
        $this->assertSame(1, $first['attempt']);
        $this->assertSame('acct', $first['group']);
        $this->assertSame('acme/m', $first['resource_key']);
        $this->assertSame('0.10', $first['amount']);
        $this->assertSame(100000000, $first['amount_nanos']);
        $this->assertSame('EUR', $first['currency']);
        $this->assertSame(Days::local($this->store->clock), $first['day']);
        $this->assertSame('call-a', $first['usage_ref']);
        $this->assertTrue($first['metered']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $this->store->clock), $first['recorded_at']);
        $this->assertFalse($records[1]['metered']);
        $this->assertNull($records[1]['usage_ref']);
    }

    /** Test 71: with no listener nothing breaks, and a throwing listener is contained. */
    public function testNoListenerOrABrokenOneBreaksNothing(): void
    {
        $this->setCap('10');
        $this->type('demo.task', static function (Job $job, JobContext $ctx): array {
            $ctx->addCost('0.10');
            return [];
        });
        $a = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame('succeeded', $this->row($a)['status']);

        add_action('gen_queue_cost_recorded', static function (): never {
            throw new \RuntimeException('listener bug');
        });
        $b = gen_queue_enqueue('demo.task', []);
        $this->drain();
        $this->assertSame('succeeded', $this->row($b)['status']);
        $this->assertSame(1, $this->logged('gen_queue_cost_recorded listener threw'));
    }
}
