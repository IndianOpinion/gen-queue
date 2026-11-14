<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Cli;

/**
 * Output for the WP-CLI command, so the command can be tested without WP-CLI.
 */
interface Io
{
    public function line(string $text): void;

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $fields
     */
    public function items(string $format, array $rows, array $fields): void;

    public function success(string $text): void;

    public function warning(string $text): void;

    /** Print an error and stop with exit code 1. */
    public function error(string $text): never;

    public function halt(int $code): never;

    public function sleep(int $seconds): void;
}
