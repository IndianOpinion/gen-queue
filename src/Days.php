<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * Converts database UTC time into the site's local day.
 */
final class Days
{
    public static function local(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(self::zone())->format('Y-m-d');
    }

    /**
     * Unix time of local midnight starting the given local day.
     */
    public static function startOf(string $day): int
    {
        return (new \DateTimeImmutable($day . ' 00:00:00', self::zone()))->getTimestamp();
    }

    public static function zone(): \DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }
        return new \DateTimeZone('UTC');
    }

    public static function utc(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
