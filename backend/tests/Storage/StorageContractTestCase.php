<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Storage\AlreadyExistsException;
use Wohnpilot\Storage\ConflictException;
use Wohnpilot\Storage\NotFoundException;
use Wohnpilot\Storage\StorageAdapter;

/** Behaviour every StorageAdapter must show. Subclasses provide the adapter. */
abstract class StorageContractTestCase extends TestCase
{
    protected const METER = 'objekte/efh/zaehler/strom-haupt.md';
    protected const METER_CONTENT = "---\ntyp: zaehler\nid: strom-haupt\n---\n| Datum | Stand |\n|---|---|\n";

    /** An adapter that already holds METER with METER_CONTENT and _haushalt.md. */
    abstract protected function adapter(): StorageAdapter;

    /** Changes METER behind the adapter's back, like an edit in the Notes app. */
    abstract protected function editElsewhere(string $content): void;

    public function testListsNotesWithoutContent(): void
    {
        $paths = array_map(static fn ($info) => $info->path, $this->adapter()->list());

        self::assertSame(['_haushalt.md', self::METER], $paths);
    }

    public function testGetReturnsContentAndEtag(): void
    {
        $adapter = $this->adapter();
        $note = $adapter->get(self::METER);

        self::assertSame(self::METER_CONTENT, $note->content);
        self::assertNotSame('', $note->etag);
        $listed = array_values(array_filter($adapter->list(), static fn ($i) => $i->path === self::METER))[0];
        self::assertSame($note->etag, $listed->etag);
    }

    public function testUpdateWithCurrentEtag(): void
    {
        $adapter = $this->adapter();
        $before = $adapter->get(self::METER);

        $after = $adapter->update(self::METER, $before->content . "| 2026-10-24 | 1 |\n", $before->etag);

        self::assertNotSame($before->etag, $after->etag);
        self::assertSame($before->content . "| 2026-10-24 | 1 |\n", $adapter->get(self::METER)->content);
    }

    public function testUpdateWithStaleEtagDoesNotOverwrite(): void
    {
        $adapter = $this->adapter();
        $before = $adapter->get(self::METER);
        $this->editElsewhere(self::METER_CONTENT . "Geändert in der Notes-App\n");

        try {
            $adapter->update(self::METER, $before->content . "| 2026-10-24 | 1 |\n", $before->etag);
            self::fail('Expected a conflict');
        } catch (ConflictException $e) {
            self::assertSame(self::METER_CONTENT . "Geändert in der Notes-App\n", $e->current->content);
            self::assertSame($e->current->etag, $adapter->get(self::METER)->etag);
        }
        self::assertSame(self::METER_CONTENT . "Geändert in der Notes-App\n", $adapter->get(self::METER)->content);
    }

    public function testCreateInNewFolder(): void
    {
        $adapter = $this->adapter();

        $created = $adapter->create('objekte/efh/zaehler/wasser-haupt.md', "---\ntyp: zaehler\nid: wasser-haupt\n---\n");

        self::assertSame('objekte/efh/zaehler/wasser-haupt.md', $created->path);
        self::assertSame("---\ntyp: zaehler\nid: wasser-haupt\n---\n", $adapter->get('objekte/efh/zaehler/wasser-haupt.md')->content);
        self::assertCount(3, $adapter->list());
    }

    public function testCreateRefusesExistingPath(): void
    {
        $this->expectException(AlreadyExistsException::class);

        $this->adapter()->create(self::METER, 'x');
    }

    public function testMissingNote(): void
    {
        $this->expectException(NotFoundException::class);

        $this->adapter()->get('objekte/efh/zaehler/gibt-es-nicht.md');
    }

    public function testPathsCannotEscapeTheRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->adapter()->get('../geheim.md');
    }
}
