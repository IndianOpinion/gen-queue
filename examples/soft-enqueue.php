<?php

/**
 * Enqueue without a hard dependency on gen-queue.
 *
 * The filter returns a job id when gen-queue is active, null when it is not
 * (so you run your own fallback), and a WP_Error when the enqueue failed.
 */

declare(strict_types=1);

function example_request_summary(int $postId): void
{
    $result = apply_filters('gen_queue_enqueue_job', null, 'example-summary.write', ['post_id' => $postId], [
        'idempotency_key' => 'summary-' . $postId,
        'priority' => 10,
    ]);

    if ($result === null) {
        // gen-queue is not installed: do the work your own way, or not at all.
        return;
    }
    if (is_wp_error($result)) {
        error_log('Summary not queued: ' . $result->get_error_message());
        return;
    }
    // $result is the job id. gen_queue_get($result) shows its state later.
}
