<?php

/**
 * Just enough of WordPress's wpdb, on mysqli, to run the database store
 * against a real MySQL or MariaDB server in the integration tests.
 */

declare(strict_types=1);

if (!class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wptest_';
        public string $options = 'wptest_options';
        public string $last_error = '';
        public int $insert_id = 0;
        public int $rows_affected = 0;
        public int $num_queries = 0;

        public function __construct(private mysqli $db)
        {
            mysqli_report(MYSQLI_REPORT_OFF);
        }

        public function prepare(string $query, mixed ...$args): string
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
            $i = 0;
            return (string) preg_replace_callback('/%[sdF]/', function (array $m) use (&$i, $args): string {
                $value = $args[$i++] ?? null;
                return match ($m[0]) {
                    '%d' => (string) (int) $value,
                    '%F' => (string) (float) $value,
                    default => "'" . $this->db->real_escape_string((string) $value) . "'",
                };
            }, $query);
        }

        public function suppress_errors(bool $suppress = true): bool
        {
            return false;
        }

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        public function query(string $sql): int|bool
        {
            $this->num_queries++;
            $this->last_error = '';
            $result = $this->db->query($sql);
            if ($result === false) {
                $this->last_error = $this->db->error;
                return false;
            }
            $this->insert_id = (int) $this->db->insert_id;
            $this->rows_affected = (int) $this->db->affected_rows;
            if ($result instanceof mysqli_result) {
                $n = $result->num_rows;
                $result->free();
                return (int) $n;
            }
            return $this->rows_affected;
        }

        /** @return list<array<string, mixed>>|null */
        public function get_results(string $sql, string $output = 'OBJECT'): ?array
        {
            $this->num_queries++;
            $this->last_error = '';
            $result = $this->db->query($sql);
            if ($result === false) {
                $this->last_error = $this->db->error;
                return null;
            }
            if ($result === true) {
                return [];
            }
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
            return array_values($rows);
        }

        public function get_var(string $sql): ?string
        {
            $rows = $this->get_results($sql);
            if ($rows === null || $rows === []) {
                return null;
            }
            $first = array_values($rows[0])[0] ?? null;
            return $first === null ? null : (string) $first;
        }

        /** @return list<string|null> */
        public function get_col(string $sql): array
        {
            $rows = $this->get_results($sql) ?? [];
            return array_map(static fn (array $r): ?string => ($v = array_values($r)[0] ?? null) === null ? null : (string) $v, $rows);
        }
    }
}

if (!function_exists('dbDelta')) {
    /**
     * Minimal stand-in: create each table if it does not exist.
     *
     * @return list<string>
     */
    function dbDelta(string $sql): array
    {
        global $wpdb;
        $done = [];
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $statement = (string) preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $statement);
            $wpdb->query($statement);
            $done[] = $statement;
        }
        return $done;
    }
}
