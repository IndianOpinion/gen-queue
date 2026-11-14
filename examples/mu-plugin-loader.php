<?php

/**
 * Plugin Name: gen-queue (must-use loader)
 * Description: Loads gen-queue from wp-content/mu-plugins/gen-queue/.
 *
 * Copy this file to wp-content/mu-plugins/gen-queue-loader.php and the
 * plugin folder to wp-content/mu-plugins/gen-queue/. Tables are created on
 * the first load, because must-use plugins have no activation step.
 */

declare(strict_types=1);

if (is_file(__DIR__ . '/gen-queue/gen-queue.php')) {
    require_once __DIR__ . '/gen-queue/gen-queue.php';
}
