<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * A registered kind of job with one handler and its defaults.
 */
final class JobType
{
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';

    /** Keys accepted by gen_queue_register_type(). */
    public const ARGS = [
        'max_attempts', 'backoff_base', 'backoff_max', 'timeout', 'priority', 'order', 'ttl',
        'group', 'resource_key', 'cost_estimate', 'daily_cap', 'meter',
    ];

    public const DEFAULT_MAX_ATTEMPTS = 5;
    public const DEFAULT_TTL = 604800;

    /**
     * @param \Closure(Job, JobContext): mixed $handler
     * @param array{purpose?: string, provider?: string, model?: string}|null $meter
     */
    public function __construct(
        public readonly string $name,
        public readonly \Closure $handler,
        public readonly int $maxAttempts,
        public readonly int $backoffBase,
        public readonly int $backoffMax,
        public readonly int $timeout,
        public readonly int $priority,
        public readonly int $ttl,
        public readonly string $group,
        public readonly string $resourceKey,
        public readonly int $costEstimateNanos,
        public readonly ?int $dailyCapNanos,
        public readonly ?array $meter,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @throws RegistrationException
     */
    public static function create(string $name, mixed $handler, array $args): self
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new RegistrationException(sprintf(
                'Invalid job type name "%s": use lower case letters, digits, dot, dash and underscore, 1 to 64 characters, for example "my-plugin.summary".',
                $name
            ));
        }
        if (!is_callable($handler)) {
            throw new RegistrationException(sprintf('The handler for job type "%s" is not callable.', $name));
        }
        $unknown = array_diff(array_keys($args), self::ARGS);
        if ($unknown !== []) {
            throw new RegistrationException(sprintf('Unknown argument(s) for job type "%s": %s.', $name, implode(', ', $unknown)));
        }
        $order = $args['order'] ?? 'fifo';
        if ($order !== 'fifo') {
            throw new RegistrationException(sprintf('Job type "%s": only "fifo" order is supported in 0.1.0; use priority to run newer work first.', $name));
        }

        try {
            $maxAttempts = Validate::intRange($args['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS, 1, 1000, 'max_attempts');
            $backoffBase = Validate::intRange($args['backoff_base'] ?? 30, 0, 86400, 'backoff_base');
            $backoffMax = Validate::intRange($args['backoff_max'] ?? 3600, 0, 86400, 'backoff_max');
            $timeout = Validate::intRange($args['timeout'] ?? 120, 1, 86400, 'timeout');
            $priority = Validate::intRange($args['priority'] ?? 0, -100, 100, 'priority');
            $ttl = Validate::intRange($args['ttl'] ?? self::DEFAULT_TTL, 0, 31536000 * 10, 'ttl');
            $group = Validate::label($args['group'] ?? $name, 64, 'group');
            $resourceKey = Validate::label($args['resource_key'] ?? $name, 128, 'resource_key');
            $estimate = Money::toNanos(Validate::amount($args['cost_estimate'] ?? 0, 'cost_estimate'));
            $cap = null;
            if (isset($args['daily_cap'])) {
                $cap = Money::toNanos(Validate::amount($args['daily_cap'], 'daily_cap'));
            }
            $meter = null;
            if (isset($args['meter'])) {
                $meter = self::meter($args['meter']);
            }
        } catch (\InvalidArgumentException $e) {
            throw new RegistrationException(sprintf('Job type "%s": %s', $name, $e->getMessage()), 0, $e);
        }

        return new self(
            $name,
            \Closure::fromCallable($handler),
            $maxAttempts,
            $backoffBase,
            max($backoffBase, $backoffMax),
            $timeout,
            $priority,
            $ttl,
            $group,
            $resourceKey,
            $estimate,
            $cap,
            $meter,
        );
    }

    /**
     * @return array{purpose?: string, provider?: string, model?: string}
     */
    private static function meter(mixed $meter): array
    {
        if (!is_array($meter)) {
            throw new \InvalidArgumentException('meter must be an array with purpose, provider and model.');
        }
        $out = [];
        foreach ($meter as $key => $value) {
            if (!in_array($key, ['purpose', 'provider', 'model'], true)) {
                throw new \InvalidArgumentException('meter accepts only purpose, provider and model.');
            }
            if (!is_string($value) || $value === '' || strlen($value) > 128) {
                throw new \InvalidArgumentException('meter ' . $key . ' must be a non-empty string up to 128 bytes.');
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * The defaults used for a job whose type is not (yet) registered.
     *
     * @return array{max_attempts: int, priority: int, ttl: int, group: string, resource_key: string, cost_estimate: int}
     */
    public static function fallbackDefaults(string $name): array
    {
        return [
            'max_attempts' => self::DEFAULT_MAX_ATTEMPTS,
            'priority' => 0,
            'ttl' => self::DEFAULT_TTL,
            'group' => substr($name, 0, 64),
            'resource_key' => substr($name, 0, 128),
            'cost_estimate' => 0,
        ];
    }
}
