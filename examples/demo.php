<?php

/**
 * A runnable walk-through without WordPress, on the in-memory store the
 * tests use. Run from the repository root after `composer install`:
 *
 *     php examples/demo.php
 */

declare(strict_types=1);

use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\JobContext;
use IndianOpinion\GenQueue\Plugin;
use IndianOpinion\GenQueue\RetryableException;
use IndianOpinion\GenQueue\Tests\Support\MemoryStore;

require __DIR__ . '/../tests/bootstrap.php';

$store = new MemoryStore();
$plugin = Plugin::boot($store);
$plugin->installer->install();

add_action('gen_queue_register_types', static function (): void {
    $calls = 0;
    gen_queue_register_type('demo.summary', static function (Job $job, JobContext $ctx) use (&$calls): array {
        $calls++;
        $ctx->addCost('0.02', ['input_units' => 1200, 'output_units' => 150]);
        if ($calls === 2) {
            throw new RetryableException('Truncated response');
        }
        return ['draft_id' => 1000 + $job->payload['post']];
    }, ['cost_estimate' => '0.03', 'timeout' => 10, 'backoff_base' => 0]);
});
$plugin->openRegistration();

foreach ([1, 2, 3, 4, 5] as $post) {
    gen_queue_enqueue('demo.summary', ['post' => $post], ['idempotency_key' => 'post-' . $post]);
}

$summary = gen_queue_run(['time' => 30]);
echo "No cap set:  stop reason {$summary->stopReason}, claimed {$summary->claimed}\n";

gen_queue_pause();
$plugin->queue->setCap('0.10');
$summary = gen_queue_run(['time' => 30]);
echo "Switched off: stop reason {$summary->stopReason}\n";

gen_queue_resume();
$summary = gen_queue_run(['time' => 30]);
echo "Cap 0.10:    stop reason {$summary->stopReason}, succeeded {$summary->succeeded}, retried {$summary->retried}, cost {$summary->costTotal}\n";
echo 'Spent today: ' . gen_queue_spend_today() . ', left: ' . gen_queue_cap_remaining() . "\n";

foreach ($store->jobs as $row) {
    $job = Job::fromRow($row);
    printf("  job %d  %-9s attempts %d  cost %s\n", $job->id, $job->status, $job->attempts, $job->costTotal);
}
