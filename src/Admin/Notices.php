<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue\Admin;

use IndianOpinion\GenQueue\Controls;
use IndianOpinion\GenQueue\Log;
use IndianOpinion\GenQueue\Plugin;

/**
 * Admin notices: no cap set, switch off, a group on account hold, or
 * WP-Cron not ticking for 10 minutes.
 */
final class Notices
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function hook(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    /** @return list<string> */
    public function messages(): array
    {
        $out = [];
        $controls = $this->plugin->controls;
        $store = $this->plugin->store;
        $now = $store->now();
        if ($this->plugin->installer->error() !== null) {
            $out[] = 'gen-queue: the database check failed, so nothing runs. See Tools > Generation queue.';
        }
        if (!$controls->enabled()) {
            $out[] = 'gen-queue is switched off, so no generation job runs.';
        }
        if ($controls->capNanos() === null) {
            $out[] = 'gen-queue has no daily cap, so no generation job runs. Set one in Tools > Generation queue or with: wp gen-queue cap <amount>';
        }
        foreach ($this->plugin->breakers->activeHolds($now) as $group => $hold) {
            $out[] = sprintf('gen-queue: group "%s" is on account hold until %s UTC: %s', $group, gmdate('Y-m-d H:i', $hold['until']), $hold['message']);
        }
        $tick = $controls->jsonOption(Controls::OPT_LAST_TICK);
        $last = is_array($tick) && isset($tick['at']) ? (int) $tick['at'] : 0;
        $disabledCron = defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON');
        if (!$disabledCron && $last > 0 && $now - $last > 600) {
            $out[] = 'gen-queue: WP-Cron has not ticked for over 10 minutes. Check that cron runs, or use system cron with: wp gen-queue run';
        }
        return $out;
    }

    public function render(): void
    {
        if (!current_user_can(StatusPage::capability())) {
            return;
        }
        try {
            $messages = $this->messages();
        } catch (\Throwable $e) {
            Log::warning('Could not build the admin notices: ' . get_class($e) . '.');
            return;
        }
        foreach ($messages as $message) {
            echo '<div class="notice notice-warning"><p>' . esc_html($message) . '</p></div>';
        }
    }
}
