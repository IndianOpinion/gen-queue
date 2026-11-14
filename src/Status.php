<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

use IndianOpinion\GenQueue\Storage\Store;

/**
 * One read of everything an operator needs, for the status page, the
 * admin notices and `wp gen-queue status`.
 */
final class Status
{
    public function __construct(
        private readonly Store $store,
        private readonly Registry $registry,
        private readonly Controls $controls,
        private readonly Breakers $breakers,
        private readonly Installer $installer,
    ) {
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $now = $this->store->now();
        $day = Days::local($now);
        $cap = $this->controls->capNanos();
        $spent = $this->controls->spendToday($day);
        $own = $this->store->spendForDay($day);
        $reserved = $this->store->reservedNanos();
        $enabled = $this->controls->enabled();

        $counts = [];
        $byType = [];
        $noHandler = [];
        foreach ($this->store->countsByStatusType() as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + $row['n'];
            $byType[$row['type']][$row['status']] = $row['n'];
            if ($row['status'] === 'queued' && !$this->registry->has($row['type'])) {
                $noHandler[$row['type']] = $row['n'];
            }
        }

        $state = 'running';
        if (!$enabled) {
            $state = 'disabled';
        } elseif ($cap === null) {
            $state = 'no cap';
        } elseif ($spent + $reserved >= $cap) {
            $state = 'capped';
        }

        $cooling = [];
        foreach ($this->breakers->cooling($now) as $key => $until) {
            $cooling[] = ['resource_key' => (string) $key, 'until' => Days::utc($until)];
        }
        $holds = [];
        foreach ($this->breakers->activeHolds($now) as $group => $hold) {
            $holds[] = ['group' => (string) $group, 'until' => Days::utc($hold['until']), 'message' => $hold['message']];
        }

        $slow = [];
        $since = $now - 7 * 86400;
        foreach ($this->registry->all() as $name => $type) {
            $n = $this->store->countSlowAttempts($name, $type->timeout * 1000, $since);
            if ($n > 0) {
                $slow[] = ['type' => $name, 'attempts' => $n, 'timeout' => $type->timeout];
            }
        }

        $failures = [];
        foreach ($this->store->recentFailures(50) as $row) {
            $failures[] = [
                'id' => (int) $row['id'],
                'type' => (string) $row['type'],
                'error_class' => $row['last_error_class'],
                'error' => $row['last_error'],
                'finished_at' => $row['finished_at'] === null ? null : Days::utc((int) $row['finished_at']),
            ];
        }

        $stats = [];
        foreach ($this->store->statsSince(Days::local($now - 6 * 86400)) as $row) {
            $row['cost'] = Money::format($row['cost_nanos']);
            unset($row['cost_nanos']);
            $stats[] = $row;
        }

        $lastTick = $this->controls->jsonOption(Controls::OPT_LAST_TICK);
        $denial = $this->controls->jsonOption(Controls::OPT_LAST_DENIAL);

        return [
            'state' => $state,
            'enabled' => $enabled,
            'enabled_source' => $this->controls->disabledByConstant() ? 'constant' : 'option',
            'cap' => $cap === null ? null : Money::format($cap),
            'cap_source' => $this->controls->capSource(),
            'currency' => $this->controls->currency(),
            'day' => $day,
            'spent_today' => Money::format($spent),
            'spend_source' => $spent === $own ? 'own records' : 'gen_queue_spend_today filter',
            'reserved' => Money::format($reserved),
            'cap_remaining' => $cap === null ? null : Money::format(max(0, $cap - $spent)),
            'counts' => $counts,
            'by_type' => $byType,
            'registered_types' => $this->registry->names(),
            'paused_types' => $this->controls->pausedTypes(),
            'no_handler' => $noHandler,
            'cooling' => $cooling,
            'holds' => $holds,
            'slow_types' => $slow,
            'recent_failures' => $failures,
            'stats' => $stats,
            'last_tick' => $lastTick === null ? null : [
                'at' => isset($lastTick['at']) ? Days::utc((int) $lastTick['at']) : null,
                'stop_reason' => $lastTick['stop_reason'] ?? null,
            ],
            'last_meter_denial' => $denial === null ? null : [
                'reason' => $denial['reason'] ?? null,
                'at' => isset($denial['at']) ? Days::utc((int) $denial['at']) : null,
            ],
            'spend_meter' => defined('SPEND_METER_API'),
            'install_error' => $this->installer->error(),
            'settings' => $this->controls->settings(),
        ];
    }

    /**
     * @return list<array{day: string, type: string, attempts: int, succeeded: int, failed: int, cost: string}>
     */
    public function stats(int $days): array
    {
        $days = max(1, min(366, $days));
        $now = $this->store->now();
        $out = [];
        foreach ($this->store->statsSince(Days::local($now - ($days - 1) * 86400)) as $row) {
            $out[] = [
                'day' => $row['day'],
                'type' => $row['type'],
                'attempts' => $row['attempts'],
                'succeeded' => $row['succeeded'],
                'failed' => $row['failed'],
                'cost' => Money::format($row['cost_nanos']),
            ];
        }
        return $out;
    }
}
