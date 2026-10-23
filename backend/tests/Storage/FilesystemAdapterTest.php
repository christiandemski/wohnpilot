<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Storage;

use Wohnpilot\Storage\FilesystemAdapter;
use Wohnpilot\Storage\StorageAdapter;

final class FilesystemAdapterTest extends StorageContractTestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wohnpilot-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/objekte/efh/zaehler', 0775, true);
        file_put_contents($this->root . '/' . self::METER, self::METER_CONTENT);
        file_put_contents($this->root . '/_haushalt.md', "---\ntyp: haushalt\nid: haushalt\n---\n");
        file_put_contents($this->root . '/objekte/efh/zaehler/Notiz mit Leerzeichen.md', 'keine Wohnpilot-Notiz');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    protected function adapter(): StorageAdapter
    {
        return new FilesystemAdapter($this->root);
    }

    protected function editElsewhere(string $content): void
    {
        file_put_contents($this->root . '/' . self::METER, $content);
    }
}
