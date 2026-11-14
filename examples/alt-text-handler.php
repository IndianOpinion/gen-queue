<?php

/**
 * Plugin Name: Example: alt text suggestions through gen-queue
 * Description: Registers a job type whose handler asks a model for alt text and saves it as a suggestion for an editor to accept. It never publishes.
 *
 * Drop this file into wp-content/plugins/ next to gen-queue to try it.
 * The provider call is a placeholder: replace my_alt_text_call() with your
 * own client. Set a daily cap first: wp gen-queue cap 1.00
 */

declare(strict_types=1);

use IndianOpinion\GenQueue\AccountException;
use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\JobContext;
use IndianOpinion\GenQueue\JobResult;
use IndianOpinion\GenQueue\PermanentException;
use IndianOpinion\GenQueue\RetryableException;

add_action('gen_queue_register_types', static function (): void {
    gen_queue_register_type('example-alt.suggest', 'example_alt_text_handler', [
        'timeout' => 60,
        'max_attempts' => 4,
        'resource_key' => 'my-provider/vision-small',
        'group' => 'my-provider',
        'cost_estimate' => '0.002',
        'daily_cap' => '0.50',
    ]);
});

/**
 * @throws RetryableException|PermanentException|AccountException
 */
function example_alt_text_handler(Job $job, JobContext $ctx): JobResult
{
    $attachmentId = (int) ($job->payload['attachment_id'] ?? 0);
    if ($attachmentId <= 0 || get_post($attachmentId) === null) {
        throw new PermanentException('The attachment no longer exists.');
    }

    // Your provider call. It returns text, a cost in your currency and usage.
    $response = my_alt_text_call(wp_get_attachment_url($attachmentId));

    if ($response['http_status'] === 401 || $response['http_status'] === 402) {
        throw new AccountException('Provider refused the account: HTTP ' . $response['http_status']);
    }
    // Billed even when the body is unusable, so report the cost first.
    $ctx->addCost($response['cost'], [
        'input_units' => $response['input_tokens'],
        'output_units' => $response['output_tokens'],
        'usage_ref' => $response['id'],
    ]);
    if ($response['http_status'] === 429 || $response['http_status'] >= 500) {
        throw new RetryableException('Provider busy: HTTP ' . $response['http_status'], $response['retry_after']);
    }
    if (trim($response['text']) === '') {
        throw new RetryableException('Empty response body.');
    }

    // A suggestion for a human to accept, not the live alt text.
    update_post_meta($attachmentId, '_example_alt_suggestion', sanitize_text_field($response['text']));
    $ctx->log('Suggestion saved for attachment ' . $attachmentId);

    return JobResult::ok(['attachment_id' => $attachmentId]);
}

/**
 * Placeholder for your own provider client.
 *
 * @return array{http_status: int, text: string, cost: string, input_tokens: int, output_tokens: int, id: string, retry_after: ?int}
 */
function my_alt_text_call(string|false $imageUrl): array
{
    return ['http_status' => 200, 'text' => 'A placeholder description.', 'cost' => '0.001', 'input_tokens' => 900, 'output_tokens' => 12, 'id' => 'demo', 'retry_after' => null];
}

// Enqueue one job per new image upload. The key stops duplicates.
add_action('add_attachment', static function (int $attachmentId): void {
    if (!function_exists('gen_queue_enqueue') || !wp_attachment_is_image($attachmentId)) {
        return;
    }
    gen_queue_enqueue('example-alt.suggest', ['attachment_id' => $attachmentId], [
        'idempotency_key' => 'attachment-' . $attachmentId,
    ]);
});
