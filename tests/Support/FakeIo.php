<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Support;

use IndianOpinion\GenQueue\Cli\Io;

final class FakeIo implements Io
{
    /** @var list<string> */
    public array $lines = [];

    /** @var list<array{format: string, rows: list<array<string, mixed>>, fields: list<string>}> */
    public array $items = [];

    /** @var list<string> */
    public array $successes = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<int> */
    public array $slept = [];

    /** @var (\Closure(): void)|null */
    public ?\Closure $onSleep = null;

    public function line(string $text): void
    {
        $this->lines[] = $text;
    }

    public function items(string $format, array $rows, array $fields): void
    {
        $this->items[] = ['format' => $format, 'rows' => $rows, 'fields' => $fields];
    }

    public function success(string $text): void
    {
        $this->successes[] = $text;
    }

    public function warning(string $text): void
    {
        $this->warnings[] = $text;
    }

    public function error(string $text): never
    {
        throw new \RuntimeException($text);
    }

    public function halt(int $code): never
    {
        throw new \RuntimeException('halt ' . $code);
    }

    public function sleep(int $seconds): void
    {
        $this->slept[] = $seconds;
        if ($this->onSleep !== null) {
            ($this->onSleep)();
        }
    }
}
