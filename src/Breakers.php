<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

use IndianOpinion\GenQueue\Storage\Store;

/**
 * Cooldowns per resource key and holds per group.
 *
 * Cooldown state lives in one option per provider, keyed by model: a
 * resource key "acme/model-x" is kept in the option for provider "acme"
 * under the key "acme/model-x". A key without a slash belongs to the
 * provider "default". Holds live in one option keyed by group.
 */
final class Breakers
{
    public const OPT_PREFIX = 'gen_queue_breakers_';
    public const OPT_HOLDS = 'gen_queue_holds';

    public function __construct(private readonly Store $store, private readonly Controls $controls)
    {
    }

    public static function provider(string $resourceKey): string
    {
        $slash = strpos($resourceKey, '/');
        $provider = $slash === false || $slash === 0 ? 'default' : substr($resourceKey, 0, $slash);
        $slug = (string) preg_replace('/[^a-z0-9_-]/', '-', strtolower($provider));
        if ($slug !== strtolower($provider) || strlen($slug) > 48) {
            $slug = substr($slug, 0, 40) . '-' . substr(md5($provider), 0, 7);
        }
        return $slug;
    }

    public static function optionName(string $resourceKey): string
    {
        return self::OPT_PREFIX . self::provider($resourceKey);
    }

    /**
     * Every resource key with state: key => [count, until].
     *
     * @return array<string, array{count: int, until: int}>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->store->getOptionsLike(self::OPT_PREFIX) as $raw) {
            $state = json_decode($raw, true);
            if (!is_array($state)) {
                continue;
            }
            foreach ($state as $key => $entry) {
                if (is_string($key) && is_array($entry)) {
                    $out[$key] = ['count' => (int) ($entry['count'] ?? 0), 'until' => (int) ($entry['until'] ?? 0)];
                }
            }
        }
        return $out;
    }

    /** @return array<string, int> key => Unix time the cooldown ends */
    public function cooling(int $now): array
    {
        $out = [];
        foreach ($this->all() as $key => $entry) {
            if ($entry['until'] > $now) {
                $out[$key] = $entry['until'];
            }
        }
        return $out;
    }

    public function coolingUntil(string $key, int $now): ?int
    {
        $state = $this->read($key);
        $until = isset($state[$key]) ? $state[$key]['until'] : 0;
        return $until > $now ? $until : null;
    }

    /** A retryable failure on this key. Starts a cooldown at the threshold. */
    public function failure(string $key, int $now): void
    {
        $state = $this->read($key);
        $entry = $state[$key] ?? ['count' => 0, 'until' => 0];
        $entry['count']++;
        $started = false;
        $settings = $this->controls->settings();
        if ($entry['count'] >= $settings['cooldown_threshold'] && $entry['until'] <= $now && $settings['cooldown_seconds'] > 0) {
            $entry['until'] = $now + $settings['cooldown_seconds'];
            $started = true;
        }
        $state[$key] = $entry;
        $this->write($key, $state);
        if ($started) {
            Log::notice(sprintf('Resource "%s" is cooling for %d s after %d consecutive failures.', $key, $settings['cooldown_seconds'], $entry['count']));
            do_action('gen_queue_cooldown_started', $key, $entry['until']);
        }
    }

    /** A success on this key clears its counter. */
    public function success(string $key): void
    {
        $state = $this->read($key);
        if (!isset($state[$key])) {
            return;
        }
        unset($state[$key]);
        $this->write($key, $state);
    }

    /** @return array<string, array{until: int, message: string}> */
    public function holds(): array
    {
        $raw = json_decode((string) $this->store->getOption(self::OPT_HOLDS), true);
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $group => $entry) {
                if (is_string($group) && is_array($entry)) {
                    $out[$group] = ['until' => (int) ($entry['until'] ?? 0), 'message' => (string) ($entry['message'] ?? '')];
                }
            }
        }
        return $out;
    }

    /** @return array<string, array{until: int, message: string}> groups held now */
    public function activeHolds(int $now): array
    {
        return array_filter($this->holds(), static fn (array $h): bool => $h['until'] > $now);
    }

    /** Put a group on account hold. Logs and fires once per hold. */
    public function hold(string $group, int $now, string $message): void
    {
        $holds = array_filter($this->holds(), static fn (array $h): bool => $h['until'] > $now);
        $seconds = $this->controls->setting('account_hold');
        $until = $now + $seconds;
        $fresh = !isset($holds[$group]);
        $holds[$group] = ['until' => max($until, $holds[$group]['until'] ?? 0), 'message' => $message];
        $this->store->setOption(self::OPT_HOLDS, (string) json_encode($holds));
        if ($fresh) {
            Log::warning(sprintf('Group "%s" is on account hold for %d s: %s', $group, $seconds, $message));
            do_action('gen_queue_account_hold', $group, $until, $message);
        }
    }

    public function release(string $group): void
    {
        $holds = $this->holds();
        unset($holds[$group]);
        $this->store->setOption(self::OPT_HOLDS, (string) json_encode($holds));
    }

    /** @return array<string, array{count: int, until: int}> */
    private function read(string $key): array
    {
        $raw = json_decode((string) $this->store->getOption(self::optionName($key)), true);
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $k => $entry) {
                if (is_string($k) && is_array($entry)) {
                    $out[$k] = ['count' => (int) ($entry['count'] ?? 0), 'until' => (int) ($entry['until'] ?? 0)];
                }
            }
        }
        return $out;
    }

    /** @param array<string, array{count: int, until: int}> $state */
    private function write(string $key, array $state): void
    {
        $name = self::optionName($key);
        if ($state === []) {
            $this->store->deleteOption($name);
            return;
        }
        $this->store->setOption($name, (string) json_encode($state));
    }
}
