<?php

declare(strict_types=1);

// Rebuilds the SQLite index from storage. The index is only a cache, so
// this is always safe. Usage: php bin/reindex.php [--full]

use Wohnpilot\Config;
use Wohnpilot\Services;

$backendDir = dirname(__DIR__);
require $backendDir . '/vendor/autoload.php';

$services = new Services(Config::load($backendDir), $backendDir);
$full = in_array('--full', $argv, true);
$stats = $full ? $services->index()->rebuild($services->storage()) : $services->index()->sync($services->storage());

printf("%s: %d fetched, %d unchanged, %d removed, %d notes in index\n", $full ? 'Rebuild' : 'Sync', $stats['fetched'], $stats['unchanged'], $stats['removed'], $services->index()->count());
