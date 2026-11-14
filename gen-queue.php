<?php

/**
 * Plugin Name:       gen-queue
 * Plugin URI:        https://github.com/IndianOpinion/gen-queue
 * Description:       A job queue for paid generation work: daily spend cap, kill switch, retries with backoff, cooldowns, per-attempt cost records and a status page. No cap set means nothing runs.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://github.com/IndianOpinion
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       gen-queue
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (defined('GEN_QUEUE_VERSION')) {
    // Already loaded (for example as both a plugin and a must-use plugin).
    return;
}

define('GEN_QUEUE_VERSION', '0.1.0');
define('GEN_QUEUE_FILE', __FILE__);

require_once __DIR__ . '/autoload.php';

\IndianOpinion\GenQueue\Plugin::load(__FILE__);
