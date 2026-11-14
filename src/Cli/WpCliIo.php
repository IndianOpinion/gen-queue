<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Cli;

/**
 * The real WP-CLI output.
 */
final class WpCliIo implements Io
{
    public function line(string $text): void
    {
        \WP_CLI::line($text);
    }

    public function items(string $format, array $rows, array $fields): void
    {
        \WP_CLI\Utils\format_items($format, $rows, $fields);
    }

    public function success(string $text): void
    {
        \WP_CLI::success($text);
    }

    public function warning(string $text): void
    {
        \WP_CLI::warning($text);
    }

    public function error(string $text): never
    {
        \WP_CLI::error($text);
        exit(1);
    }

    public function halt(int $code): never
    {
        \WP_CLI::halt($code);
    }

    public function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
