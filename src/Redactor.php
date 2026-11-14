<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * Strips things that look like credentials from error text and cuts it to a
 * byte limit without breaking a UTF-8 character.
 */
final class Redactor
{
    public static function clean(string $text, int $maxBytes = 500): string
    {
        $patterns = [
            '/\bBearer\s+\S+/i' => 'Bearer [redacted]',
            '/\b(api[_-]?key|key|token|secret|password)=\S+/i' => '$1=[redacted]',
            '/\bsk-[A-Za-z0-9_-]{8,}/' => '[redacted]',
        ];
        $clean = preg_replace(array_keys($patterns), array_values($patterns), $text);
        if (!is_string($clean)) {
            $clean = '';
        }
        if (preg_match('//u', $clean) !== 1) {
            $clean = (string) preg_replace('/[\x80-\xFF]/', '?', $clean);
        }
        return self::cut($clean, $maxBytes);
    }

    public static function cut(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }
        $cut = substr($text, 0, $maxBytes);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }
        return $cut;
    }
}
