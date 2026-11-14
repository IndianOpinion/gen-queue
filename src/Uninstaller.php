<?php

declare(strict_types=1);

namespace IndianOpinion\GenQueue;

use IndianOpinion\GenQueue\Storage\Store;

/**
 * Removes everything gen-queue created: both job tables, the lock table,
 * every gen_queue_ option and the scheduled tick.
 */
final class Uninstaller
{
    public static function run(Store $store): void
    {
        $store->dropSchema();
        $store->deleteOptionsLike('gen_queue_');
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(Plugin::TICK_HOOK);
        }
    }
}
