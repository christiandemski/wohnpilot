<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Index;

use PDO;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Index\NoteIndex;
use Wohnpilot\Storage\StoredNote;
use Wohnpilot\Tests\Fixtures;
use Wohnpilot\Tests\InMemoryStorage;

final class NoteIndexTest extends TestCase
{
    public function testSyncReadsAllFixturesAndFindsThemByType(): void
    {
        $index = new NoteIndex(new PDO('sqlite::memory:'));
        $storage = InMemoryStorage::fromFixtures();

        self::assertSame(['fetched' => count(Fixtures::allNotes()), 'removed' => 0, 'unchanged' => 0], $index->sync($storage));

        $meters = $index->byType('zaehler', 'efh-musterstrasse');
        self::assertSame(
            ['objekte/efh-musterstrasse/zaehler/strom-haupt.md', 'objekte/efh-musterstrasse/zaehler/wasser-haupt.md'],
            array_map(static fn ($n) => $n->path, $meters),
        );
        self::assertSame(Fixtures::read('_haushalt.md'), $index->findById('haushalt', 'haushalt')->content);
    }

    public function testSecondSyncFetchesOnlyChangedNotes(): void
    {
        $index = new NoteIndex(new PDO('sqlite::memory:'));
        $storage = InMemoryStorage::fromFixtures();
        $index->sync($storage);

        $storage->notes['objekte/efh-musterstrasse/zaehler/strom-haupt.md'] .= "Neue Zeile\n";
        unset($storage->notes['vertraege/strom-stadtwerke.md']);
        $storage->gets = 0;

        self::assertSame(['fetched' => 1, 'removed' => 1, 'unchanged' => count(Fixtures::allNotes()) - 2], $index->sync($storage));
        self::assertSame(1, $storage->gets);
        self::assertStringEndsWith("Neue Zeile\n", $index->get('objekte/efh-musterstrasse/zaehler/strom-haupt.md')->content);
        self::assertNull($index->get('vertraege/strom-stadtwerke.md'));
    }

    public function testBrokenYamlIsKeptWithoutFields(): void
    {
        $index = new NoteIndex(new PDO('sqlite::memory:'));

        $index->put(new StoredNote('wissen/kaputt.md', "---\ntyp: [nicht geschlossen\n---\n", 'e', 1));

        self::assertNotNull($index->get('wissen/kaputt.md'));
        self::assertSame(1, $index->count());
    }

    public function testRebuildStartsFromScratch(): void
    {
        $index = new NoteIndex(new PDO('sqlite::memory:'));
        $index->put(new StoredNote('alt/weg.md', "---\ntyp: x\nid: y\n---\n", 'e', 1));

        $index->rebuild(InMemoryStorage::fromFixtures());

        self::assertNull($index->get('alt/weg.md'));
        self::assertSame(count(Fixtures::allNotes()), $index->count());
    }
}
