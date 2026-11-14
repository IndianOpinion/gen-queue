<?php

/**
 * A small stub layer for the WordPress functions gen-queue calls, so the
 * tests run without WordPress.
 */

declare(strict_types=1);

use IndianOpinion\GenQueue\Tests\Stubs\WpDieException;
use IndianOpinion\GenQueue\Tests\Stubs\WpState;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    WpState::$hooks[$hook][$priority][] = [$callback, $args];
    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function remove_all_filters(string $hook): bool
{
    unset(WpState::$hooks[$hook]);
    return true;
}

function has_action(string $hook): bool
{
    return !empty(WpState::$hooks[$hook]);
}

function has_filter(string $hook): bool
{
    return has_action($hook);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    if (empty(WpState::$hooks[$hook])) {
        return $value;
    }
    $byPriority = WpState::$hooks[$hook];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $value = $callback(...array_slice([$value, ...$args], 0, max(1, $accepted)));
        }
    }
    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    WpState::$actionCounts[$hook] = (WpState::$actionCounts[$hook] ?? 0) + 1;
    if (empty(WpState::$hooks[$hook])) {
        return;
    }
    $byPriority = WpState::$hooks[$hook];
    ksort($byPriority);
    WpState::$running[] = $hook;
    try {
        foreach ($byPriority as $callbacks) {
            foreach ($callbacks as [$callback, $accepted]) {
                $callback(...array_slice($args, 0, $accepted));
            }
        }
    } finally {
        array_pop(WpState::$running);
    }
}

function did_action(string $hook): int
{
    return WpState::$actionCounts[$hook] ?? 0;
}

function doing_action(string $hook): bool
{
    return in_array($hook, WpState::$running, true);
}

function get_option(string $name, mixed $default = false): mixed
{
    return WpState::$cachedOptions[$name] ?? $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    WpState::$cachedOptions[$name] = $value;
    return true;
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    return true;
}

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone(WpState::$timezone);
}

function get_current_user_id(): int
{
    return WpState::$userId;
}

function current_user_can(string $capability): bool
{
    return WpState::$userCan;
}

function wp_verify_nonce(string $nonce, string $action = ''): int|false
{
    return $nonce === WpState::$validNonce ? 1 : false;
}

function wp_nonce_field(string $action = '', string $name = '_wpnonce'): string
{
    $html = '<input type="hidden" name="' . $name . '" value="' . WpState::$validNonce . '">';
    echo $html;
    return $html;
}

/**
 * @param array<string, mixed> $args
 */
function wp_die(string $message = '', string $title = '', array $args = []): never
{
    throw new WpDieException($message);
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . $path;
}

/**
 * @param array<string, mixed> $args
 */
function add_query_arg(array $args, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

function wp_unslash(mixed $value): mixed
{
    return $value;
}

function sanitize_text_field(string $text): string
{
    return trim(strip_tags($text));
}

function absint(mixed $value): int
{
    return abs((int) $value);
}

function is_admin(): bool
{
    return WpState::$isAdmin;
}

function wp_next_scheduled(string $hook): int|false
{
    return isset(WpState::$scheduled[$hook]) ? WpState::$scheduled[$hook][0] : false;
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
{
    WpState::$scheduled[$hook] = [$timestamp, $recurrence];
    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    $had = isset(WpState::$scheduled[$hook]) ? 1 : 0;
    unset(WpState::$scheduled[$hook]);
    return $had;
}

function get_user_by(string $field, string $value): object|false
{
    return WpState::$usersByEmail[$value] ?? false;
}

function add_management_page(string $pageTitle, string $menuTitle, string $capability, string $slug, callable $callback): string
{
    return $slug;
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @param array<string, mixed>|null $data */
        public function __construct(public string $code = '', public string $message = '', public ?array $data = null)
        {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}
