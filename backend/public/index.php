<?php

declare(strict_types=1);

use Wohnpilot\Config;
use Wohnpilot\Http\Api;
use Wohnpilot\Services;

$backendDir = dirname(__DIR__);
require $backendDir . '/vendor/autoload.php';

// Built-in dev server: serve existing files directly.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

Api::create(new Services(Config::load($backendDir), $backendDir))->run();
