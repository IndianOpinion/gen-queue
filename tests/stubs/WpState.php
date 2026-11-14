<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Tests\Stubs;

/**
 * State behind the WordPress function stubs. Reset before every test.
 */
final class WpState
{
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $hooks = [];

    /** @var array<string, int> */
    public static array $actionCounts = [];

    /** @var list<string> hooks currently running */
    public static array $running = [];

    /** @var array<string, mixed> object-cache style option values, deliberately separate from the store */
    public static array $cachedOptions = [];

    /** @var array<string, array{0: int, 1: string}> */
    public static array $scheduled = [];

    public static bool $userCan = true;

    public static int $userId = 0;

    public static string $timezone = 'UTC';

    public static string $validNonce = 'valid-nonce';

    /** @var array<string, object> */
    public static array $usersByEmail = [];

    public static bool $isAdmin = false;

    public static function reset(): void
    {
        self::$hooks = [];
        self::$actionCounts = [];
        self::$running = [];
        self::$cachedOptions = [];
        self::$scheduled = [];
        self::$userCan = true;
        self::$userId = 0;
        self::$timezone = 'UTC';
        self::$usersByEmail = [];
        self::$isAdmin = false;
    }
}

/**
 * Thrown by the wp_die() stub so tests can assert a refusal.
 */
final class WpDieException extends \RuntimeException
{
}
