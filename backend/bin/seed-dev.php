<?php

declare(strict_types=1);

// Copies fixtures/Wohnpilot into var/notes for local development with
// STORAGE=filesystem. Existing files there are left alone.

$source = dirname(__DIR__, 2) . '/fixtures/Wohnpilot';
$target = dirname(__DIR__) . '/var/notes';
$copied = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $to = $target . substr($file->getPathname(), strlen($source));
    if (is_file($to)) {
        continue;
    }
    @mkdir(dirname($to), 0775, true);
    copy($file->getPathname(), $to);
    $copied++;
}
echo "$copied notes copied to $target\n";
