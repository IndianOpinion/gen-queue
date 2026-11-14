<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

use IndianOpinion\GenQueue\Storage\Store;

/**
 * The operator's switches and money settings, read fresh from the database
 * every time. Nothing here is cached: a stale value must never let a job run.
 */
final class Controls
{
    public const OPT_ENABLED = 'gen_queue_enabled';
    public const OPT_CAP = 'gen_queue_daily_cap';
    public const OPT_CURRENCY = 'gen_queue_currency';
    public const OPT_PAUSED = 'gen_queue_paused_types';
    public const OPT_SETTINGS = 'gen_queue_settings';
    public const OPT_CAP_NOTICE_DAY = 'gen_queue_cap_notice_day';
    public const OPT_LAST_TICK = 'gen_queue_last_tick';
    public const OPT_LAST_DENIAL = 'gen_queue_last_denial';
    public const OPT_PURGE_DAY = 'gen_queue_purge_day';

    public const DEFAULTS = [
        'max_workers' => 2,
        'tick_seconds' => 25,
        'retention_days' => 30,
        'cooldown_threshold' => 2,
        'cooldown_seconds' => 600,
        'account_hold' => 3600,
    ];

    public const LIMITS = [
        'max_workers' => [1, 32],
        'tick_seconds' => [1, 3600],
        'retention_days' => [0, 3650],
        'cooldown_threshold' => [1, 100],
        'cooldown_seconds' => [0, 86400],
        'account_hold' => [0, 604800],
    ];

    public function __construct(private readonly Store $store)
    {
    }

    public function store(): Store
    {
        return $this->store;
    }

    /** The global kill switch. The constant GEN_QUEUE_DISABLED forces it off. */
    public function enabled(): bool
    {
        if ($this->disabledByConstant()) {
            return false;
        }
        return $this->store->getOption(self::OPT_ENABLED) !== '0';
    }

    public function disabledByConstant(): bool
    {
        return defined('GEN_QUEUE_DISABLED') && (bool) constant('GEN_QUEUE_DISABLED');
    }

    public function setEnabled(bool $on): void
    {
        $this->store->setOption(self::OPT_ENABLED, $on ? '1' : '0');
    }

    /** @return list<string> */
    public function pausedTypes(): array
    {
        $raw = $this->store->getOption(self::OPT_PAUSED);
        $list = json_decode((string) $raw, true);
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_filter($list, static fn ($t): bool => is_string($t) && $t !== ''));
    }

    public function pauseType(string $type): void
    {
        $list = $this->pausedTypes();
        if (!in_array($type, $list, true)) {
            $list[] = $type;
            sort($list);
        }
        $this->store->setOption(self::OPT_PAUSED, (string) json_encode($list));
    }

    public function resumeType(string $type): void
    {
        $list = array_values(array_diff($this->pausedTypes(), [$type]));
        $this->store->setOption(self::OPT_PAUSED, (string) json_encode($list));
    }

    /**
     * The global daily cap in nanos, or null when none is set (nothing runs).
     * The constant GEN_QUEUE_DAILY_CAP wins over the option; the filter
     * gen_queue_daily_cap applies only when the constant is not defined.
     */
    public function capNanos(): ?int
    {
        if (defined('GEN_QUEUE_DAILY_CAP')) {
            $value = constant('GEN_QUEUE_DAILY_CAP');
            try {
                return Money::toNanos(is_int($value) || is_float($value) ? $value : (string) $value);
            } catch (\InvalidArgumentException $e) {
                Log::warning('GEN_QUEUE_DAILY_CAP is not a valid amount; treating the cap as 0 so nothing runs.');
                return 0;
            }
        }
        $option = $this->optionCap();
        $filtered = apply_filters('gen_queue_daily_cap', $option);
        if ($filtered === null || (is_int($filtered) && $filtered >= 0)) {
            return $filtered;
        }
        Log::warning('The gen_queue_daily_cap filter returned something other than null or a non-negative integer; using the stored cap.');
        return $option;
    }

    public function capSource(): string
    {
        return defined('GEN_QUEUE_DAILY_CAP') ? 'constant' : 'option';
    }

    public function optionCap(): ?int
    {
        $raw = $this->store->getOption(self::OPT_CAP);
        if ($raw === null || $raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }
        return (int) $raw;
    }

    public function setCap(?int $nanos): void
    {
        $this->store->setOption(self::OPT_CAP, $nanos === null ? '' : (string) $nanos);
    }

    public function currency(): string
    {
        return (string) $this->store->getOption(self::OPT_CURRENCY);
    }

    public function setCurrency(string $label): void
    {
        $this->store->setOption(self::OPT_CURRENCY, Redactor::cut(trim($label), 16));
    }

    /**
     * Today's spend in nanos: the queue's own attempt costs, passed through
     * the gen_queue_spend_today filter. A broken filter never lowers the figure.
     */
    public function spendToday(string $day): int
    {
        $own = $this->store->spendForDay($day);
        try {
            $value = apply_filters('gen_queue_spend_today', $own, $day);
        } catch (\Throwable $e) {
            Log::warning('The gen_queue_spend_today filter threw ' . get_class($e) . '; using the queue\'s own records.');
            return $own;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        Log::warning('The gen_queue_spend_today filter returned something other than a non-negative integer; using the larger safe value.');
        if (is_float($value) && is_finite($value) && $value >= 0 && $value < PHP_INT_MAX) {
            return max($own, (int) ceil($value));
        }
        if (is_string($value) && preg_match('/^\d{1,18}$/', $value) === 1) {
            return max($own, (int) $value);
        }
        return $own;
    }

    public function typeSpend(string $day, string $type): int
    {
        return $this->store->spendForDay($day, $type);
    }

    /** @return array<string, int> */
    public function settings(): array
    {
        $stored = json_decode((string) $this->store->getOption(self::OPT_SETTINGS), true);
        $out = self::DEFAULTS;
        if (is_array($stored)) {
            foreach (self::DEFAULTS as $key => $default) {
                if (isset($stored[$key]) && is_int($stored[$key])) {
                    [$min, $max] = self::LIMITS[$key];
                    $out[$key] = max($min, min($max, $stored[$key]));
                }
            }
        }
        if (defined('GEN_QUEUE_MAX_WORKERS')) {
            $out['max_workers'] = max(1, min(32, (int) constant('GEN_QUEUE_MAX_WORKERS')));
        }
        return $out;
    }

    public function setting(string $key): int
    {
        return $this->settings()[$key];
    }

    /**
     * @param array<string, mixed> $values
     * @throws \InvalidArgumentException
     */
    public function updateSettings(array $values): void
    {
        $stored = json_decode((string) $this->store->getOption(self::OPT_SETTINGS), true);
        $stored = is_array($stored) ? $stored : [];
        foreach ($values as $key => $value) {
            if (!isset(self::LIMITS[$key])) {
                throw new \InvalidArgumentException(sprintf('Unknown setting "%s".', $key));
            }
            [$min, $max] = self::LIMITS[$key];
            $stored[$key] = Validate::intRange($value, $min, $max, $key);
        }
        $this->store->setOption(self::OPT_SETTINGS, (string) json_encode($stored), true);
    }

    public function tickSeconds(): int
    {
        $seconds = (int) apply_filters('gen_queue_tick_seconds', $this->setting('tick_seconds'));
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0) {
            $seconds = min($seconds, $limit - 5);
        }
        return max(1, $seconds);
    }

    public function retentionDays(): int
    {
        return max(0, (int) apply_filters('gen_queue_retention_days', $this->setting('retention_days')));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function jsonOption(string $name): ?array
    {
        $value = json_decode((string) $this->store->getOption($name), true);
        return is_array($value) ? $value : null;
    }

    /** @param array<string, mixed> $value */
    public function setJsonOption(string $name, array $value): void
    {
        $this->store->setOption($name, (string) json_encode($value));
    }
}
