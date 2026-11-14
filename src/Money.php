<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * Money is held as integer nanos: one billionth of the operator's currency
 * unit. Amounts come in as decimal strings or numbers and go out as decimal
 * strings. There is no currency conversion and no price table here.
 */
final class Money
{
    public const SCALE = 1000000000;

    /**
     * Convert a decimal amount to nanos, rounding half up at the ninth place.
     *
     * @throws \InvalidArgumentException for a negative, non-numeric or too large amount.
     */
    public static function toNanos(string|int|float $amount): int
    {
        if (is_int($amount)) {
            if ($amount < 0) {
                throw new \InvalidArgumentException('Amounts cannot be negative.');
            }
            if ($amount > intdiv(PHP_INT_MAX, self::SCALE)) {
                throw new \InvalidArgumentException('Amount is too large.');
            }
            return $amount * self::SCALE;
        }
        if (is_float($amount)) {
            if (!is_finite($amount)) {
                throw new \InvalidArgumentException('Amount must be a finite number.');
            }
            if ($amount < 0) {
                throw new \InvalidArgumentException('Amounts cannot be negative.');
            }
            $amount = sprintf('%.10F', $amount);
        }

        $text = trim($amount);
        if ($text !== '' && $text[0] === '-') {
            if (preg_match('/^-0*(\.0*)?$/', $text) !== 1) {
                throw new \InvalidArgumentException('Amounts cannot be negative.');
            }
            $text = substr($text, 1);
        }
        if ($text !== '' && $text[0] === '+') {
            $text = substr($text, 1);
        }
        if (preg_match('/^(\d*)(?:\.(\d*))?$/', $text, $m) !== 1 || ($m[1] === '' && ($m[2] ?? '') === '')) {
            throw new \InvalidArgumentException('Amount must be a decimal number such as 5 or 0.25.');
        }
        $whole = ltrim($m[1], '0');
        $fraction = $m[2] ?? '';
        if (strlen($whole) > 9) {
            throw new \InvalidArgumentException('Amount is too large.');
        }
        $digits = str_pad(substr($fraction, 0, 10), 10, '0');
        $nanos = (int) substr($digits, 0, 9);
        if ((int) $digits[9] >= 5) {
            $nanos++;
        }
        return ((int) ($whole === '' ? '0' : $whole)) * self::SCALE + $nanos;
    }

    /**
     * Format nanos as a decimal string with at least two decimals and no
     * trailing zeros beyond them, for example "0.00", "1.50", "0.000123".
     */
    public static function format(int $nanos): string
    {
        $sign = '';
        if ($nanos < 0) {
            $sign = '-';
            $nanos = -$nanos;
        }
        $whole = intdiv($nanos, self::SCALE);
        $fraction = rtrim(str_pad((string) ($nanos % self::SCALE), 9, '0', STR_PAD_LEFT), '0');
        if (strlen($fraction) < 2) {
            $fraction = str_pad($fraction, 2, '0');
        }
        return $sign . $whole . '.' . $fraction;
    }
}
