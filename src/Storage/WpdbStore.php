<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Storage;

use IndianOpinion\GenQueue\StorageException;

/**
 * The production store, on WordPress's $wpdb.
 *
 * Every statement goes through $wpdb->prepare(). Column names are checked
 * against fixed lists before use. Times are stored as UTC DATETIME and
 * compared using values derived from the database clock (see now()).
 */
final class WpdbStore implements Store
{
    private const INT_COLUMNS = [
        'id', 'job_id', 'attempt_no', 'priority', 'attempts', 'max_attempts', 'estimate_nanos', 'cost_nanos',
        'cancel_requested', 'created_by', 'input_units', 'output_units', 'metered', 'duration_ms',
    ];

    private string $jobs;
    private string $attempts;
    private string $locks;

    public function __construct(private readonly \wpdb $db)
    {
        $this->jobs = $db->prefix . 'gen_queue_jobs';
        $this->attempts = $db->prefix . 'gen_queue_attempts';
        $this->locks = $db->prefix . 'gen_queue_locks';
    }

    /** @return array{jobs: string, attempts: string, locks: string} */
    public function tables(): array
    {
        return ['jobs' => $this->jobs, 'attempts' => $this->attempts, 'locks' => $this->locks];
    }

    public function now(): int
    {
        $value = $this->db->get_var('SELECT UNIX_TIMESTAMP()');
        $this->check();
        if (!is_numeric($value)) {
            throw new StorageException('Could not read the database clock.');
        }
        return (int) $value;
    }

    private function check(): void
    {
        if ($this->db->last_error !== '') {
            throw new StorageException('Database error: ' . $this->db->last_error);
        }
    }

    /**
     * @param array<mixed> $args
     */
    private function prepare(string $sql, array $args = []): string
    {
        if ($args === []) {
            return $sql;
        }
        $prepared = $this->db->prepare($sql, ...$args);
        if (!is_string($prepared) || $prepared === '') {
            throw new StorageException('Could not prepare a statement.');
        }
        return $prepared;
    }

    /** @param array<mixed> $args */
    private function exec(string $sql, array $args = []): int
    {
        $result = $this->db->query($this->prepare($sql, $args));
        if ($result === false) {
            throw new StorageException('Database error: ' . ($this->db->last_error !== '' ? $this->db->last_error : 'query failed'));
        }
        return (int) $this->db->rows_affected;
    }

    /**
     * @param array<mixed> $args
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $args = []): array
    {
        $rows = $this->db->get_results($this->prepare($sql, $args), ARRAY_A);
        $this->check();
        if (!is_array($rows)) {
            return [];
        }
        return array_values(array_map(fn (array $r): array => $this->fromDb($r), $rows));
    }

    /** @param array<mixed> $args */
    private function scalar(string $sql, array $args = []): ?string
    {
        $value = $this->db->get_var($this->prepare($sql, $args));
        $this->check();
        return $value === null ? null : (string) $value;
    }

    private static function datetime(?int $timestamp): ?string
    {
        return $timestamp === null ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    private static function timestamp(?string $value): ?int
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function fromDb(array $row): array
    {
        foreach ($row as $column => $value) {
            if (in_array($column, self::TIME_COLUMNS, true)) {
                $row[$column] = self::timestamp($value === null ? null : (string) $value);
            } elseif (in_array($column, self::INT_COLUMNS, true) && $value !== null) {
                $row[$column] = (int) $value;
            }
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $allowed
     * @return array{0: list<string>, 1: list<mixed>} SQL fragments and their arguments
     */
    private static function assignments(array $values, array $allowed): array
    {
        $parts = [];
        $args = [];
        foreach ($values as $column => $value) {
            if (!in_array($column, $allowed, true) || $column === 'id') {
                throw new StorageException('Unknown column ' . $column . '.');
            }
            if (in_array($column, self::TIME_COLUMNS, true)) {
                $value = self::datetime($value === null ? null : (int) $value);
            }
            if ($value === null) {
                $parts[] = "`$column` = NULL";
            } elseif (is_int($value)) {
                $parts[] = "`$column` = %d";
                $args[] = $value;
            } else {
                $parts[] = "`$column` = %s";
                $args[] = (string) $value;
            }
        }
        return [$parts, $args];
    }

    /**
     * @param list<string|int> $values
     * @return array{0: string, 1: list<string|int>}
     */
    private static function in(array $values, string $format): array
    {
        return [implode(', ', array_fill(0, count($values), $format)), $values];
    }

    // Options.

    public function getOption(string $name): ?string
    {
        return $this->scalar("SELECT option_value FROM {$this->db->options} WHERE option_name = %s LIMIT 1", [$name]);
    }

    public function getOptions(array $names): array
    {
        if ($names === []) {
            return [];
        }
        [$list, $values] = self::in($names, '%s');
        $rows = $this->db->get_results($this->prepare(
            "SELECT option_name, option_value FROM {$this->db->options} WHERE option_name IN ($list)",
            $values
        ), ARRAY_A);
        $this->check();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['option_name']] = (string) $row['option_value'];
        }
        return $out;
    }

    public function getOptionsLike(string $prefix): array
    {
        $rows = $this->db->get_results($this->prepare(
            "SELECT option_name, option_value FROM {$this->db->options} WHERE option_name LIKE %s",
            [$this->db->esc_like($prefix) . '%']
        ), ARRAY_A);
        $this->check();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['option_name']] = (string) $row['option_value'];
        }
        return $out;
    }

    public function setOption(string $name, string $value, bool $autoload = false): void
    {
        // Written directly: update_option() compares with a possibly stale
        // cached value and can skip the write.
        $this->exec(
            "INSERT INTO {$this->db->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
            [$name, $value, self::autoloadValue($autoload)]
        );
        $this->forget($name);
    }

    private static function autoloadValue(bool $autoload): string
    {
        global $wp_version;
        $modern = is_string($wp_version) && version_compare($wp_version, '6.6', '>=');
        if ($modern) {
            return $autoload ? 'on' : 'off';
        }
        return $autoload ? 'yes' : 'no';
    }

    private function forget(string $name): void
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($name, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }

    public function deleteOption(string $name): void
    {
        $this->exec("DELETE FROM {$this->db->options} WHERE option_name = %s", [$name]);
        $this->forget($name);
    }

    public function deleteOptionsLike(string $prefix): int
    {
        $names = array_keys($this->getOptionsLike($prefix));
        foreach ($names as $name) {
            $this->deleteOption((string) $name);
        }
        return count($names);
    }

    // Locks.

    public function acquireLock(string $name, string $owner, int $until, int $now): bool
    {
        $this->exec("INSERT IGNORE INTO {$this->locks} (name, owner, locked_until) VALUES (%s, '', 0)", [$name]);
        return $this->exec(
            "UPDATE {$this->locks} SET owner = %s, locked_until = %d
             WHERE name = %s AND (owner = '' OR owner = %s OR locked_until < %d)",
            [$owner, $until, $name, $owner, $now]
        ) === 1 || $this->lockHeldBy($name, $owner, $until);
    }

    /** MySQL reports 0 changed rows when the same owner re-takes with the same values. */
    private function lockHeldBy(string $name, string $owner, int $until): bool
    {
        $row = $this->scalar("SELECT owner FROM {$this->locks} WHERE name = %s AND owner = %s AND locked_until = %d", [$name, $owner, $until]);
        return $row !== null;
    }

    public function releaseLock(string $name, string $owner): void
    {
        $this->exec("UPDATE {$this->locks} SET owner = '', locked_until = 0 WHERE name = %s AND owner = %s", [$name, $owner]);
    }

    // Transactions.

    public function begin(): void
    {
        $this->exec('START TRANSACTION');
    }

    public function commit(): void
    {
        $this->exec('COMMIT');
    }

    public function rollback(): void
    {
        $this->db->query('ROLLBACK');
    }

    // Jobs.

    public function insertJob(array $row): ?int
    {
        [$parts, $args] = self::assignments($row, self::JOB_COLUMNS);
        $sql = $this->prepare("INSERT INTO {$this->jobs} SET " . implode(', ', $parts), $args);
        // A duplicate key here is an expected race; do not print it as a database error.
        $quiet = isset($row['idempotency_key']);
        $previous = $quiet ? $this->db->suppress_errors(true) : false;
        $result = $this->db->query($sql);
        if ($quiet) {
            $this->db->suppress_errors($previous);
        }
        if ($result === false) {
            $error = $this->db->last_error;
            if (isset($row['idempotency_key'], $row['type']) && stripos($error, 'Duplicate') !== false) {
                return null;
            }
            throw new StorageException('Could not store the job: ' . ($error !== '' ? $error : 'insert failed'));
        }
        return (int) $this->db->insert_id;
    }

    public function getJob(int $id): ?array
    {
        return $this->rows("SELECT * FROM {$this->jobs} WHERE id = %d", [$id])[0] ?? null;
    }

    public function findJobByKey(string $type, string $key): ?array
    {
        return $this->rows("SELECT * FROM {$this->jobs} WHERE type = %s AND idempotency_key = %s", [$type, $key])[0] ?? null;
    }

    public function updateJob(int $id, array $set, array $where = [], array $increment = []): int
    {
        return $this->update($this->jobs, self::JOB_COLUMNS, $id, $set, $where, $increment);
    }

    /**
     * @param list<string> $allowed
     * @param array<string, mixed> $set
     * @param array<string, mixed> $where
     * @param array<string, int> $increment
     */
    private function update(string $table, array $allowed, int $id, array $set, array $where, array $increment): int
    {
        [$parts, $args] = self::assignments($set, $allowed);
        foreach ($increment as $column => $delta) {
            if (!in_array($column, $allowed, true)) {
                throw new StorageException('Unknown column ' . $column . '.');
            }
            $parts[] = "`$column` = GREATEST(CAST(`$column` AS SIGNED) + %d, 0)";
            $args[] = $delta;
        }
        if ($parts === []) {
            return 0;
        }
        $conditions = ['id = %d'];
        $args[] = $id;
        foreach ($where as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                throw new StorageException('Unknown column ' . $column . '.');
            }
            if (in_array($column, self::TIME_COLUMNS, true)) {
                $value = self::datetime($value === null ? null : (int) $value);
            }
            if ($value === null) {
                $conditions[] = "`$column` IS NULL";
            } else {
                $conditions[] = "`$column` = " . (is_int($value) ? '%d' : '%s');
                $args[] = $value;
            }
        }
        // Report a matched row even when no value changed (CLIENT_FOUND_ROWS is not set by wpdb).
        $changed = $this->exec("UPDATE {$table} SET " . implode(', ', $parts) . ' WHERE ' . implode(' AND ', $conditions), $args);
        if ($changed === 0 && $where !== []) {
            return 0;
        }
        return $changed > 0 ? 1 : ($where === [] ? 1 : 0);
    }

    public function nextCandidates(int $now, array $types, array $exclude, int $limit): array
    {
        if ($types === []) {
            return [];
        }
        $time = self::datetime($now);
        $sql = "SELECT * FROM {$this->jobs} WHERE status = 'queued' AND run_after <= %s AND (expires_at IS NULL OR expires_at > %s)";
        $args = [$time, $time];
        [$list, $values] = self::in($types, '%s');
        $sql .= " AND type IN ($list)";
        $args = array_merge($args, $values);
        $map = ['paused' => ['type', '%s'], 'resources' => ['resource_key', '%s'], 'groups' => ['group_key', '%s'], 'ids' => ['id', '%d']];
        foreach ($map as $key => [$column, $format]) {
            $values = $exclude[$key] ?? [];
            if ($values !== []) {
                [$list, $values] = self::in($values, $format);
                $sql .= " AND $column NOT IN ($list)";
                $args = array_merge($args, $values);
            }
        }
        $sql .= ' ORDER BY priority DESC, run_after ASC, id ASC LIMIT %d';
        $args[] = $limit;
        return $this->rows($sql, $args);
    }

    public function hasQueued(): bool
    {
        return $this->scalar("SELECT 1 FROM {$this->jobs} WHERE status = 'queued' LIMIT 1") !== null;
    }

    public function probe(): array
    {
        // UNIX_TIMESTAMP() and UTC_TIMESTAMP() read the same statement clock.
        $rows = $this->db->get_results(
            "SELECT UNIX_TIMESTAMP() AS now,
                EXISTS(SELECT 1 FROM {$this->jobs} WHERE status = 'queued' AND run_after <= UTC_TIMESTAMP()) AS due,
                EXISTS(SELECT 1 FROM {$this->jobs} WHERE status = 'running' AND lease_until < UTC_TIMESTAMP()) AS stale,
                EXISTS(SELECT 1 FROM {$this->jobs} WHERE status = 'queued' AND expires_at <= UTC_TIMESTAMP()) AS expiring,
                EXISTS(SELECT 1 FROM {$this->jobs} WHERE status = 'queued') AS queued",
            ARRAY_A
        );
        $this->check();
        $row = is_array($rows) ? ($rows[0] ?? null) : null;
        if (!is_array($row) || !is_numeric($row['now'] ?? null)) {
            throw new StorageException('Could not read the database clock.');
        }
        return [
            'now' => (int) $row['now'],
            'due' => (int) $row['due'] === 1,
            'stale' => (int) $row['stale'] === 1,
            'expiring' => (int) $row['expiring'] === 1,
            'queued' => (int) $row['queued'] === 1,
        ];
    }

    public function reservedNanos(?string $type = null): int
    {
        if ($type === null) {
            return (int) $this->scalar("SELECT COALESCE(SUM(estimate_nanos), 0) FROM {$this->jobs} WHERE status = 'running'");
        }
        return (int) $this->scalar("SELECT COALESCE(SUM(estimate_nanos), 0) FROM {$this->jobs} WHERE status = 'running' AND type = %s", [$type]);
    }

    public function leaseExpired(int $now, int $limit): array
    {
        return $this->rows("SELECT * FROM {$this->jobs} WHERE status = 'running' AND lease_until < %s ORDER BY id LIMIT %d", [self::datetime($now), $limit]);
    }

    public function expiryDue(int $now, int $limit): array
    {
        return $this->rows("SELECT * FROM {$this->jobs} WHERE status = 'queued' AND expires_at IS NOT NULL AND expires_at <= %s ORDER BY id LIMIT %d", [self::datetime($now), $limit]);
    }

    public function markExpired(array $ids, int $now): int
    {
        if ($ids === []) {
            return 0;
        }
        [$list, $values] = self::in($ids, '%d');
        $time = self::datetime($now);
        return $this->exec(
            "UPDATE {$this->jobs} SET status = 'expired', finished_at = %s, updated_at = %s WHERE status = 'queued' AND id IN ($list)",
            array_merge([$time, $time], $values)
        );
    }

    public function purgeTerminal(int $before, int $limit): int
    {
        $ids = $this->db->get_col($this->prepare(
            "SELECT id FROM {$this->jobs} WHERE status IN ('succeeded', 'failed', 'cancelled', 'expired') AND finished_at < %s ORDER BY id LIMIT %d",
            [self::datetime($before), $limit]
        ));
        $this->check();
        $ids = array_values(array_map('intval', is_array($ids) ? $ids : []));
        if ($ids === []) {
            return 0;
        }
        [$list, $values] = self::in($ids, '%d');
        $this->exec("DELETE FROM {$this->attempts} WHERE job_id IN ($list)", $values);
        return $this->exec("DELETE FROM {$this->jobs} WHERE id IN ($list)", $values);
    }

    public function countTerminalBefore(int $before): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM {$this->jobs} WHERE status IN ('succeeded', 'failed', 'cancelled', 'expired') AND finished_at < %s",
            [self::datetime($before)]
        );
    }

    public function listJobs(array $filter, int $limit, int $afterId = 0): array
    {
        $map = ['status' => 'status', 'type' => 'type', 'source' => 'source', 'group' => 'group_key'];
        $conditions = ['id > %d'];
        $args = [$afterId];
        foreach ($filter as $key => $value) {
            if (!isset($map[$key])) {
                throw new StorageException('Unknown filter ' . $key . '.');
            }
            $conditions[] = $map[$key] . ' = %s';
            $args[] = $value;
        }
        $args[] = $limit;
        return $this->rows("SELECT * FROM {$this->jobs} WHERE " . implode(' AND ', $conditions) . ' ORDER BY id LIMIT %d', $args);
    }

    public function countsByStatusType(): array
    {
        $rows = $this->db->get_results("SELECT status, type, COUNT(*) AS n FROM {$this->jobs} GROUP BY status, type", ARRAY_A);
        $this->check();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = ['status' => (string) $row['status'], 'type' => (string) $row['type'], 'n' => (int) $row['n']];
        }
        return $out;
    }

    public function recentFailures(int $limit): array
    {
        return $this->rows("SELECT * FROM {$this->jobs} WHERE status = 'failed' ORDER BY finished_at DESC, id DESC LIMIT %d", [$limit]);
    }

    public function jobsByCreator(int $userId, int $limit, int $offset): array
    {
        return $this->rows("SELECT id, type, status, created_at, finished_at FROM {$this->jobs} WHERE created_by = %d ORDER BY id LIMIT %d OFFSET %d", [$userId, $limit, $offset]);
    }

    public function eraseCreator(int $userId): int
    {
        return $this->exec("UPDATE {$this->jobs} SET created_by = 0 WHERE created_by = %d", [$userId]);
    }

    // Attempts.

    public function insertAttempt(array $row): int
    {
        [$parts, $args] = self::assignments($row, self::ATTEMPT_COLUMNS);
        $this->exec("INSERT INTO {$this->attempts} SET " . implode(', ', $parts), $args);
        return (int) $this->db->insert_id;
    }

    public function updateAttempt(int $id, array $set, array $increment = []): int
    {
        return $this->update($this->attempts, self::ATTEMPT_COLUMNS, $id, $set, [], $increment);
    }

    public function attemptsForJob(int $jobId): array
    {
        return $this->rows("SELECT * FROM {$this->attempts} WHERE job_id = %d ORDER BY id", [$jobId]);
    }

    public function openAttempt(int $jobId): ?array
    {
        return $this->rows("SELECT * FROM {$this->attempts} WHERE job_id = %d AND outcome = 'started' ORDER BY id DESC LIMIT 1", [$jobId])[0] ?? null;
    }

    public function spendForDay(string $day, ?string $type = null): int
    {
        if ($type === null) {
            return (int) $this->scalar("SELECT COALESCE(SUM(cost_nanos), 0) FROM {$this->attempts} WHERE cost_day = %s", [$day]);
        }
        return (int) $this->scalar("SELECT COALESCE(SUM(cost_nanos), 0) FROM {$this->attempts} WHERE cost_day = %s AND type = %s", [$day, $type]);
    }

    public function statsSince(string $sinceDay): array
    {
        $rows = $this->db->get_results($this->prepare(
            "SELECT cost_day AS day, type, COUNT(*) AS attempts,
                SUM(outcome = 'success') AS succeeded,
                SUM(outcome IN ('retry', 'failed', 'permanent', 'abandoned')) AS failed,
                COALESCE(SUM(cost_nanos), 0) AS cost_nanos
             FROM {$this->attempts} WHERE cost_day >= %s AND outcome <> 'skipped'
             GROUP BY cost_day, type ORDER BY cost_day, type",
            [$sinceDay]
        ), ARRAY_A);
        $this->check();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'day' => (string) $row['day'],
                'type' => (string) $row['type'],
                'attempts' => (int) $row['attempts'],
                'succeeded' => (int) $row['succeeded'],
                'failed' => (int) $row['failed'],
                'cost_nanos' => (int) $row['cost_nanos'],
            ];
        }
        return $out;
    }

    public function countSlowAttempts(string $type, int $ms, int $since): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM {$this->attempts} WHERE type = %s AND started_at >= %s AND duration_ms > %d",
            [$type, self::datetime($since), $ms]
        );
    }

    // Schema.

    public function installSchema(): void
    {
        $charset = $this->db->get_charset_collate();
        $sql = "CREATE TABLE {$this->jobs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(64) NOT NULL,
  idempotency_key varchar(191) DEFAULT NULL,
  status varchar(16) NOT NULL DEFAULT 'queued',
  priority smallint(6) NOT NULL DEFAULT 0,
  payload longtext NOT NULL,
  group_key varchar(64) NOT NULL,
  resource_key varchar(128) NOT NULL,
  source varchar(64) NOT NULL DEFAULT '',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  max_attempts smallint(5) unsigned NOT NULL DEFAULT 5,
  run_after datetime NOT NULL,
  expires_at datetime DEFAULT NULL,
  lease_token char(32) DEFAULT NULL,
  lease_until datetime DEFAULT NULL,
  worker varchar(64) DEFAULT NULL,
  estimate_nanos bigint(20) unsigned NOT NULL DEFAULT 0,
  cost_nanos bigint(20) unsigned NOT NULL DEFAULT 0,
  cancel_requested tinyint(1) NOT NULL DEFAULT 0,
  result longtext DEFAULT NULL,
  last_error varchar(500) DEFAULT NULL,
  last_error_class varchar(16) DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  finished_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY type_key (type,idempotency_key),
  KEY status_priority_run (status,priority,run_after),
  KEY type_status (type,status),
  KEY resource_status (resource_key,status),
  KEY status_finished (status,finished_at),
  KEY created_by (created_by)
) $charset;
CREATE TABLE {$this->attempts} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id bigint(20) unsigned NOT NULL,
  attempt_no smallint(5) unsigned NOT NULL DEFAULT 0,
  type varchar(64) NOT NULL,
  group_key varchar(64) NOT NULL,
  resource_key varchar(128) NOT NULL,
  outcome varchar(16) NOT NULL,
  cost_nanos bigint(20) unsigned NOT NULL DEFAULT 0,
  cost_day date NOT NULL,
  input_units bigint(20) unsigned NOT NULL DEFAULT 0,
  output_units bigint(20) unsigned NOT NULL DEFAULT 0,
  usage_ref varchar(191) DEFAULT NULL,
  metered tinyint(1) NOT NULL DEFAULT 0,
  duration_ms int(10) unsigned NOT NULL DEFAULT 0,
  error varchar(500) DEFAULT NULL,
  log text DEFAULT NULL,
  started_at datetime NOT NULL,
  finished_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY job_id (job_id),
  KEY day_type (cost_day,type),
  KEY type_started (type,started_at)
) $charset;
CREATE TABLE {$this->locks} (
  name varchar(64) NOT NULL,
  owner varchar(64) NOT NULL DEFAULT '',
  locked_until bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (name)
) $charset;";
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($sql);
    }

    public function columns(): array
    {
        $out = [];
        foreach ($this->tables() as $role => $table) {
            $exists = $this->scalar('SHOW TABLES LIKE %s', [$this->db->esc_like($table)]);
            if ($exists === null) {
                $out[$role] = [];
                continue;
            }
            $columns = $this->db->get_col("SHOW COLUMNS FROM {$table}");
            $this->check();
            $out[$role] = array_values(array_map('strval', is_array($columns) ? $columns : []));
        }
        return $out;
    }

    public function dropSchema(): void
    {
        foreach ($this->tables() as $table) {
            $this->exec("DROP TABLE IF EXISTS {$table}");
        }
    }
}
