<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Integration;

use IndianOpinion\GenQueue\Job;
use IndianOpinion\GenQueue\JobContext;
use IndianOpinion\GenQueue\Log;
use IndianOpinion\GenQueue\Plugin;
use IndianOpinion\GenQueue\RetryableException;
use IndianOpinion\GenQueue\Storage\WpdbStore;
use IndianOpinion\GenQueue\Tests\Stubs\WpState;
use PHPUnit\Framework\TestCase;

/**
 * The database store against a real MySQL or MariaDB server.
 *
 * Set GEN_QUEUE_TEST_DB_HOST (or GEN_QUEUE_TEST_DB_SOCKET), GEN_QUEUE_TEST_DB_USER,
 * GEN_QUEUE_TEST_DB_PASSWORD and GEN_QUEUE_TEST_DB_NAME. Skipped when unset.
 */
final class WpdbStoreTest extends TestCase
{
    private WpdbStore $store;

    private Plugin $plugin;

    private \mysqli $db;

    protected function setUp(): void
    {
        $host = getenv('GEN_QUEUE_TEST_DB_HOST') ?: null;
        $socket = getenv('GEN_QUEUE_TEST_DB_SOCKET') ?: null;
        if ($host === null && $socket === null) {
            $this->markTestSkipped('No test database configured.');
        }
        require_once __DIR__ . '/MysqliWpdb.php';
        $name = getenv('GEN_QUEUE_TEST_DB_NAME') ?: 'gen_queue_test';
        $db = new \mysqli($host ?? 'localhost', getenv('GEN_QUEUE_TEST_DB_USER') ?: 'root', getenv('GEN_QUEUE_TEST_DB_PASSWORD') ?: '', '', 0, $socket);
        $db->query('CREATE DATABASE IF NOT EXISTS `' . $db->real_escape_string($name) . '`');
        $db->select_db($name);
        $db->set_charset('utf8mb4');
        $this->db = $db;
        foreach (['wptest_gen_queue_jobs', 'wptest_gen_queue_attempts', 'wptest_gen_queue_locks', 'wptest_options'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $table);
        }
        $db->query("CREATE TABLE wptest_options (
            option_id bigint unsigned NOT NULL AUTO_INCREMENT,
            option_name varchar(191) NOT NULL DEFAULT '',
            option_value longtext NOT NULL,
            autoload varchar(20) NOT NULL DEFAULT 'yes',
            PRIMARY KEY (option_id),
            UNIQUE KEY option_name (option_name)
        ) DEFAULT CHARSET=utf8mb4");

        global $wpdb;
        $wpdb = new \wpdb($db);
        WpState::reset();
        Log::setSink(static function (): void {
        });
        $this->store = new WpdbStore($wpdb);
        $this->plugin = Plugin::boot($this->store);
        $this->assertSame([], $this->plugin->installer->install());
        $this->plugin->registry->open();
    }

    protected function tearDown(): void
    {
        Log::setSink(null);
    }

    public function testSchemaVerifies(): void
    {
        $this->assertSame([], $this->plugin->installer->verify());
        $this->assertFalse($this->plugin->installer->needed());
        $this->db->query('ALTER TABLE wptest_gen_queue_attempts DROP COLUMN usage_ref');
        $this->assertSame(['attempts.usage_ref'], $this->plugin->installer->verify());
    }

    public function testOptionsAreReadFreshAndWrittenDirectly(): void
    {
        $this->assertNull($this->store->getOption('gen_queue_x'));
        $this->store->setOption('gen_queue_x', 'one');
        $this->store->setOption('gen_queue_x', 'two', true);
        $this->assertSame('two', $this->store->getOption('gen_queue_x'));
        $this->store->setOption('gen_queue_y_a', '1');
        $this->store->setOption('gen_queue_y_b', '2');
        $this->store->setOption('gen_queueXy_c', '3');
        $this->assertSame(['gen_queue_y_a' => '1', 'gen_queue_y_b' => '2'], $this->store->getOptionsLike('gen_queue_y_'));
        $this->assertSame(2, $this->store->deleteOptionsLike('gen_queue_y_'));
        $this->store->deleteOption('gen_queue_x');
        $this->assertNull($this->store->getOption('gen_queue_x'));
    }

    public function testLocksAreExclusiveUntilTheyExpire(): void
    {
        $now = $this->store->now();
        $this->assertTrue($this->store->acquireLock('worker-1', 'a', $now + 30, $now));
        $this->assertTrue($this->store->acquireLock('worker-1', 'a', $now + 30, $now));
        $this->assertFalse($this->store->acquireLock('worker-1', 'b', $now + 30, $now));
        $this->assertTrue($this->store->acquireLock('worker-1', 'b', $now + 90, $now + 31));
        $this->store->releaseLock('worker-1', 'a');
        $this->assertFalse($this->store->acquireLock('worker-1', 'a', $now + 30, $now));
        $this->store->releaseLock('worker-1', 'b');
        $this->assertTrue($this->store->acquireLock('worker-1', 'a', $now + 30, $now));
    }

    public function testEndToEndRunWithCostsRetriesAndIdempotency(): void
    {
        $this->plugin->queue->setCap('5');
        add_filter('gen_queue_backoff_delay', static fn (): int => 0);
        $fail = 1;
        $this->plugin->registry->register('it.task', static function (Job $job, JobContext $ctx) use (&$fail): array {
            $ctx->addCost('0.25', ['input_units' => 10, 'output_units' => 5, 'usage_ref' => 'r-' . $job->id]);
            if ($fail-- > 0) {
                throw new RetryableException('flaky');
            }
            return ['draft' => $job->payload['n']];
        }, ['cost_estimate' => '0.30', 'timeout' => 10]);

        $a = gen_queue_enqueue('it.task', ['n' => 1], ['idempotency_key' => 'k-1', 'priority' => 3]);
        $this->assertSame($a, gen_queue_enqueue('it.task', ['n' => 9], ['idempotency_key' => 'k-1']));
        $ids = gen_queue_enqueue_many('it.task', [['payload' => ['n' => 2]], ['payload' => ['n' => 3]]]);
        $this->assertCount(2, $ids);

        $summary = gen_queue_run(['time' => 60]);
        $this->assertSame('empty', $summary->stopReason);
        $this->assertSame(3, $summary->succeeded);
        $job = gen_queue_get($a);
        $this->assertNotNull($job);
        $this->assertSame('succeeded', $job->status);
        $this->assertSame(2, $job->attempts);
        $this->assertSame('0.50', $job->costTotal);
        $this->assertSame(['draft' => 1], $job->result);
        $this->assertSame('1.00', gen_queue_spend_today());
        $attempts = $this->store->attemptsForJob($a);
        $this->assertSame(['retry', 'success'], array_column($attempts, 'outcome'));
        $this->assertSame(250000000, $attempts[0]['cost_nanos']);
        $this->assertSame('r-' . $a, $attempts[0]['usage_ref']);
        $stats = gen_queue_stats(1);
        $this->assertSame(4, $stats[0]['attempts']);
        $this->assertSame('1.00', $stats[0]['cost']);
        $this->assertSame(['state', 'enabled'], array_slice(array_keys($this->plugin->status->snapshot()), 0, 2));
    }

    public function testConditionalUpdatesAndReclaim(): void
    {
        $this->plugin->registry->register('it.task', static fn (): array => []);
        $id = gen_queue_enqueue('it.task', []);
        $now = $this->store->now();
        $this->assertSame(1, $this->store->updateJob($id, ['status' => 'running', 'lease_token' => str_repeat('a', 32), 'lease_until' => $now - 5], ['status' => 'queued'], ['attempts' => 1]));
        $this->assertSame(0, $this->store->updateJob($id, ['status' => 'running'], ['status' => 'queued']));
        $this->assertCount(1, $this->store->leaseExpired($now, 10));
        $this->assertSame(1, $this->plugin->housekeeping->reclaim($now, microtime(true) + 1));
        $row = $this->store->getJob($id);
        $this->assertNotNull($row);
        $this->assertSame('queued', $row['status']);
        $this->assertSame('abandoned', $row['last_error_class']);
        $this->assertSame(1, $this->store->updateJob($id, [], [], ['attempts' => -5]));
        $this->assertSame(0, $this->store->getJob($id)['attempts'] ?? null);
    }

    public function testCandidatesExpiryPurgeAndCounts(): void
    {
        $this->plugin->registry->register('it.task', static fn (): array => []);
        $now = $this->store->now();
        $a = gen_queue_enqueue('it.task', [], ['priority' => 5, 'resource_key' => 'acme/x']);
        $b = gen_queue_enqueue('it.task', [], ['group' => 'held']);
        $c = gen_queue_enqueue('it.task', [], ['expires_at' => $now - 1]);
        $d = gen_queue_enqueue('it.task', []);
        $rows = $this->store->nextCandidates($now, ['it.task'], ['resources' => ['acme/x'], 'groups' => ['held'], 'ids' => [$d]], 10);
        $this->assertSame([], array_column($rows, 'id'));
        $rows = $this->store->nextCandidates($now, ['it.task'], [], 10);
        $this->assertSame([$a, $b, $d], array_column($rows, 'id'));
        $due = $this->store->expiryDue($now, 10);
        $this->assertSame([$c], array_column($due, 'id'));
        $this->assertSame(1, $this->store->markExpired([$c], $now));
        $this->assertSame(1, $this->store->countTerminalBefore($now + 1));
        $this->assertSame(1, $this->store->purgeTerminal($now + 1, 10));
        $this->assertNull($this->store->getJob($c));
        $counts = $this->store->countsByStatusType();
        $this->assertSame(3, $counts[0]['n']);
        $this->assertTrue($this->store->hasQueued());
    }

    /** Test 59, case 59: parallel runner processes never run a job twice. */
    public function testParallelProcessesNeverDoubleRunAJob(): void
    {
        $this->plugin->queue->setCap('5');
        $this->plugin->controls->updateSettings(['max_workers' => 4]);
        $this->db->query('DROP TABLE IF EXISTS it_calls');
        $this->db->query('CREATE TABLE it_calls (job_id bigint unsigned NOT NULL, worker varchar(64) NOT NULL)');
        $items = [];
        for ($i = 0; $i < 60; $i++) {
            $items[] = ['payload' => ['n' => $i], 'args' => ['allow_unregistered' => true, 'max_attempts' => 1]];
        }
        $this->plugin->queue->enqueueMany('it.parallel', $items);

        $where = getenv('GEN_QUEUE_TEST_DB_SOCKET') ?: (string) getenv('GEN_QUEUE_TEST_DB_HOST');
        $name = getenv('GEN_QUEUE_TEST_DB_NAME') ?: 'gen_queue_test';
        $procs = [];
        foreach (['cron', 'cli-1', 'cli-2', 'cli-3'] as $worker) {
            $cmd = [PHP_BINARY, __DIR__ . '/worker.php', $where, $name, $worker];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), (string) $out . (string) $err);
        }
        $result = $this->db->query('SELECT COUNT(*) AS n, COUNT(DISTINCT job_id) AS d, COUNT(DISTINCT worker) AS w FROM it_calls');
        $this->assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        $this->assertSame(['n' => '60', 'd' => '60'], ['n' => $row['n'], 'd' => $row['d']]);
        $this->assertGreaterThan(1, (int) $row['w']);
        $this->db->query('DROP TABLE it_calls');
    }

    /** Test 9, case 9: the unique index decides a race on the real database. */
    public function testDuplicateKeyInsertReturnsNull(): void
    {
        $this->plugin->registry->register('it.task', static fn (): array => []);
        $id = gen_queue_enqueue('it.task', [], ['idempotency_key' => 'race']);
        $row = $this->store->getJob($id);
        $this->assertNotNull($row);
        unset($row['id']);
        $this->assertNull($this->store->insertJob($row));
        $row['idempotency_key'] = null;
        $this->assertIsInt($this->store->insertJob($row));
    }

    public function testUninstallDropsEverything(): void
    {
        $this->store->setOption('gen_queue_daily_cap', '1');
        \IndianOpinion\GenQueue\Uninstaller::run($this->store);
        $this->assertSame(['jobs' => [], 'attempts' => [], 'locks' => []], $this->store->columns());
        $this->assertSame([], $this->store->getOptionsLike('gen_queue_'));
    }
}
