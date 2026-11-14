<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * Holds the registered job types for this request.
 *
 * Types are registered on the action gen_queue_register_types, which fires
 * on init at priority 20. Registering before it throws; registering after it
 * works and logs a notice.
 */
final class Registry
{
    private const BEFORE = 0;
    private const OPEN = 1;
    private const AFTER = 2;

    private int $phase = self::BEFORE;

    /** @var array<string, JobType> */
    private array $types = [];

    public function open(): void
    {
        $this->phase = self::OPEN;
    }

    public function close(): void
    {
        $this->phase = self::AFTER;
    }

    /**
     * @param array<string, mixed> $args
     * @throws RegistrationException
     */
    public function register(string $name, mixed $handler, array $args = []): JobType
    {
        if ($this->phase === self::BEFORE) {
            throw new RegistrationException(sprintf('Job type "%s" was registered before gen_queue_register_types fired. Register it inside that action.', $name));
        }
        $type = JobType::create($name, $handler, $args);
        if (isset($this->types[$name])) {
            throw new RegistrationException(sprintf('Job type "%s" is already registered; the first registration stays.', $name));
        }
        if ($this->phase === self::AFTER) {
            Log::notice(sprintf('Job type "%s" was registered after gen_queue_register_types; register inside that action.', $name));
        }
        $this->types[$name] = $type;
        return $type;
    }

    public function get(string $name): ?JobType
    {
        return $this->types[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->types);
    }

    /** @return array<string, JobType> */
    public function all(): array
    {
        return $this->types;
    }
}
