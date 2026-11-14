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
            // Authorization schemes: "Bearer abc", "Basic abc", "Token abc".
            '/\b(Bearer|Basic|Token)\s+[A-Za-z0-9._~+\/=-]+/i' => '$1 [redacted]',
            // Credentials in URLs: scheme://user:password@host.
            '#\b([a-z][a-z0-9+.-]*://)[^/\s:@]+:[^/\s@]+@#i' => '$1[redacted]@',
            // Name and value pairs in query strings, headers and JSON:
            // key=..., access_token=..., "client_secret": "...", x-api-key: ...
            '/([A-Za-z0-9_-]*(?:key|token|secret|password|passwd|signature|credentials?|authorization))(["\']?\s*[=:]\s*["\']?)[^\s"\'&,;]+/i' => '$1$2[redacted]',
            // Well-known key shapes, wherever they appear.
            '/\bsk-[A-Za-z0-9_-]{8,}/' => '[redacted]',
            '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/' => '[redacted]',
            '/\b(?:ghp|gho|ghu|ghs|github_pat)_[A-Za-z0-9_]{16,}/' => '[redacted]',
            '/\bxox[abprs]-[A-Za-z0-9-]{8,}/' => '[redacted]',
            '/\bAIza[0-9A-Za-z_-]{30,}/' => '[redacted]',
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
