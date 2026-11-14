<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * Small input checks shared by registration, enqueue and the admin screens.
 */
final class Validate
{
    public const LABEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.:\/@+-]*$/';

    public static function intRange(mixed $value, int $min, int $max, string $name): int
    {
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be an integer.', $name));
        }
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(sprintf('%s must be between %d and %d; got %d.', $name, $min, $max, $value));
        }
        return $value;
    }

    /**
     * A group, resource key or source label: printable ASCII without spaces.
     */
    public static function label(mixed $value, int $maxBytes, string $name): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $maxBytes || preg_match(self::LABEL_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('%s must be 1 to %d characters of letters, digits and . _ - : / @ +, starting with a letter or digit.', $name, $maxBytes));
        }
        return $value;
    }

    public static function amount(mixed $value, string $name): string|int|float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a decimal string or a number.', $name));
        }
        return $value;
    }

    public static function idempotencyKey(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 191) {
            throw new \InvalidArgumentException('idempotency_key must be a string of 1 to 191 bytes.');
        }
        if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('idempotency_key must be valid UTF-8 without control characters.');
        }
        return $value;
    }

    public static function typeName(mixed $value): string
    {
        if (!is_string($value) || preg_match(JobType::NAME_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException('Invalid job type name.');
        }
        return $value;
    }
}
