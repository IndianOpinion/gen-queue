<?php

declare(strict_types=1);

// Constants the plugin reads with defined()/constant(); declared here so PHPStan knows them.
if (!defined('GEN_QUEUE_VERSION')) {
    define('GEN_QUEUE_VERSION', '0.1.0');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
