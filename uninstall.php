<?php

/**
 * Uninstall: drops the tables, deletes every gen_queue_ option and clears
 * the scheduled tick, on every site of a network.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/autoload.php';

$gen_queue_uninstall = static function (): void {
    global $wpdb;
    \IndianOpinion\GenQueue\Uninstaller::run(new \IndianOpinion\GenQueue\Storage\WpdbStore($wpdb));
};

if (function_exists('is_multisite') && is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $gen_queue_site) {
        switch_to_blog((int) $gen_queue_site);
        $gen_queue_uninstall();
        restore_current_blog();
    }
} else {
    $gen_queue_uninstall();
}
