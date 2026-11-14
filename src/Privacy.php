<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

use IndianOpinion\GenQueue\Storage\Store;

/**
 * Personal data: a job keeps the id of the user who created it. The
 * exporter lists those jobs (payload left out); the eraser sets the id to 0.
 */
final class Privacy
{
    private const PAGE = 100;

    public function __construct(private readonly Store $store)
    {
    }

    /**
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public function registerExporter(array $exporters): array
    {
        $exporters['gen-queue'] = ['exporter_friendly_name' => 'Generation queue jobs', 'callback' => [$this, 'export']];
        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public function registerEraser(array $erasers): array
    {
        $erasers['gen-queue'] = ['eraser_friendly_name' => 'Generation queue jobs', 'callback' => [$this, 'erase']];
        return $erasers;
    }

    /**
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if ($user === false) {
            return ['data' => [], 'done' => true];
        }
        $rows = $this->store->jobsByCreator((int) $user->ID, self::PAGE, max(0, $page - 1) * self::PAGE);
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'group_id' => 'gen-queue',
                'group_label' => 'Generation queue jobs',
                'item_id' => 'gen-queue-job-' . (int) $row['id'],
                'data' => [
                    ['name' => 'Job id', 'value' => (string) $row['id']],
                    ['name' => 'Type', 'value' => (string) $row['type']],
                    ['name' => 'Status', 'value' => (string) $row['status']],
                    ['name' => 'Created (UTC)', 'value' => Days::utc((int) $row['created_at'])],
                    ['name' => 'Finished (UTC)', 'value' => $row['finished_at'] === null ? '' : Days::utc((int) $row['finished_at'])],
                ],
            ];
        }
        return ['data' => $data, 'done' => count($rows) < self::PAGE];
    }

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if ($user === false) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        $n = $this->store->eraseCreator((int) $user->ID);
        return ['items_removed' => $n > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
    }
}
