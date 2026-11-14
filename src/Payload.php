<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

/**
 * Validates and encodes job payloads and handler results.
 */
final class Payload
{
    public const DEFAULT_MAX_BYTES = 65536;
    public const RESULT_MAX_BYTES = 16384;
    private const MAX_DEPTH = 64;

    /**
     * @param array<mixed> $payload
     * @throws InvalidPayloadException
     */
    public static function encode(array $payload, int $maxBytes): string
    {
        self::check($payload, 1, 'payload');
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $e) {
            throw new InvalidPayloadException('Payload cannot be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
        if (strlen($json) > $maxBytes) {
            throw new InvalidPayloadException(sprintf('Payload is %d bytes encoded; the limit is %d.', strlen($json), $maxBytes));
        }
        return $json;
    }

    /**
     * Decode stored JSON into an array; anything else becomes an empty array.
     *
     * @return array<mixed>
     */
    public static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $value = json_decode($json, true);
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<mixed> $value
     * @throws InvalidPayloadException
     */
    private static function check(array $value, int $depth, string $path): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidPayloadException('Payload is nested too deeply at ' . $path . '.');
        }
        foreach ($value as $key => $item) {
            $here = $path . '[' . $key . ']';
            if (is_string($key) && preg_match('//u', $key) !== 1) {
                throw new InvalidPayloadException('Payload key is not valid UTF-8 at ' . $path . '.');
            }
            if ($item === null || is_bool($item) || is_int($item)) {
                continue;
            }
            if (is_float($item)) {
                if (!is_finite($item)) {
                    throw new InvalidPayloadException('Payload holds a non-finite number at ' . $here . '.');
                }
                continue;
            }
            if (is_string($item)) {
                if (preg_match('//u', $item) !== 1) {
                    throw new InvalidPayloadException('Payload string is not valid UTF-8 at ' . $here . '.');
                }
                continue;
            }
            if (is_array($item)) {
                self::check($item, $depth + 1, $here);
                continue;
            }
            throw new InvalidPayloadException(sprintf('Payload holds a %s at %s; only scalars, nulls and arrays are allowed.', get_debug_type($item), $here));
        }
    }
}
