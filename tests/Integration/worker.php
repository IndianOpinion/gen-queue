<?php

/**
 * One runner process for the parallel-claims integration test.
 * Usage: php worker.php <socket-or-host> <db-name> <worker-name>
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/MysqliWpdb.php';

[, $where, $name, $worker] = $argv;
$isSocket = str_starts_with($where, '/');
$db = new mysqli($isSocket ? 'localhost' : $where, getenv('GEN_QUEUE_TEST_DB_USER') ?: 'root', getenv('GEN_QUEUE_TEST_DB_PASSWORD') ?: '', $name, 0, $isSocket ? $where : null);
$db->set_charset('utf8mb4');
$wpdb = new wpdb($db);
$GLOBALS['wpdb'] = $wpdb;
\IndianOpinion\GenQueue\Log::setSink(static function (): void {
});
$plugin = \IndianOpinion\GenQueue\Plugin::boot(new \IndianOpinion\GenQueue\Storage\WpdbStore($wpdb));
$plugin->registry->open();
$plugin->registry->register('it.parallel', static function (\IndianOpinion\GenQueue\Job $job) use ($db, $worker): array {
    $db->query(sprintf("INSERT INTO it_calls (job_id, worker) VALUES (%d, '%s')", $job->id, $db->real_escape_string($worker)));
    usleep(20000);
    return [];
}, ['timeout' => 5]);
$summary = $plugin->run(['time' => 30, 'worker' => $worker]);
echo json_encode($summary->toArray()), "\n";
