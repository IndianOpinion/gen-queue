<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * Log lines go to error_log with the prefix "[gen-queue]". Callers log once
 * per event, never once per job in a loop.
 */
final class Log
{
    /** @var (callable(string): void)|null */
    private static $sink = null;

    /**
     * Replace the destination, for tests. Null restores error_log.
     *
     * @param (callable(string): void)|null $sink
     */
    public static function setSink(?callable $sink): void
    {
        self::$sink = $sink;
    }

    public static function warning(string $message): void
    {
        self::write('warning', $message);
    }

    public static function notice(string $message): void
    {
        self::write('notice', $message);
    }

    public static function info(string $message): void
    {
        self::write('info', $message);
    }

    private static function write(string $level, string $message): void
    {
        $line = '[gen-queue] ' . $level . ': ' . Redactor::clean($message, 1000);
        if (self::$sink !== null) {
            (self::$sink)($line);
            return;
        }
        error_log($line);
    }
}
