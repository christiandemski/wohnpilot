<?php

declare(strict_types=1);

use Wohnpilot\Config;
use Wohnpilot\Http\Api;
use Wohnpilot\Services;

$backendDir = dirname(__DIR__);
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Built-in dev server: serve existing files directly.
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

// Everything outside /api is the single-page app (routes like /zaehler/strom-haupt).
if (!str_starts_with($path, '/api/') && is_file(__DIR__ . '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    readfile(__DIR__ . '/index.html');
    return;
}

require $backendDir . '/vendor/autoload.php';

Api::create(new Services(Config::load($backendDir), $backendDir))->run();
