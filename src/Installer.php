<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

use IndianOpinion\GenQueue\Storage\Store;

/**
 * Creates or upgrades the tables, then checks that every expected column
 * exists. dbDelta has been known to skip a column add without a word, so
 * the check is not optional.
 */
final class Installer
{
    public const DB_VERSION = '1';
    public const OPT_DB_VERSION = 'gen_queue_db_version';
    public const OPT_INSTALL_ERROR = 'gen_queue_install_error';

    public function __construct(private readonly Store $store)
    {
    }

    public function needed(): bool
    {
        return $this->store->getOption(self::OPT_DB_VERSION) !== self::DB_VERSION;
    }

    /**
     * @return list<string> missing columns as "table.column"; empty on success
     */
    public function install(): array
    {
        $this->store->installSchema();
        $missing = $this->verify();
        if ($missing !== []) {
            $message = 'Schema check failed after install; missing column(s): ' . implode(', ', $missing) . '. Nothing will run until this is fixed.';
            Log::warning($message);
            $this->store->setOption(self::OPT_INSTALL_ERROR, $message);
            return $missing;
        }
        $this->store->deleteOption(self::OPT_INSTALL_ERROR);
        $this->store->setOption(self::OPT_DB_VERSION, self::DB_VERSION);
        if ($this->store->getOption(Controls::OPT_ENABLED) === null) {
            $this->store->setOption(Controls::OPT_ENABLED, '1');
        }
        return [];
    }

    /** @return list<string> */
    public function verify(): array
    {
        $present = $this->store->columns();
        $expected = [
            'jobs' => Store::JOB_COLUMNS,
            'attempts' => Store::ATTEMPT_COLUMNS,
            'locks' => Store::LOCK_COLUMNS,
        ];
        $missing = [];
        foreach ($expected as $table => $columns) {
            foreach (array_diff($columns, $present[$table] ?? []) as $column) {
                $missing[] = $table . '.' . $column;
            }
        }
        return $missing;
    }

    public function error(): ?string
    {
        $error = $this->store->getOption(self::OPT_INSTALL_ERROR);
        return $error === null || $error === '' ? null : $error;
    }
}
