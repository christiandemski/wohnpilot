<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Note;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Tests\Fixtures;

final class RoundtripTest extends TestCase
{
    public static function fixtureNotes(): array
    {
        return Fixtures::allNotes();
    }

    public function testFixturesAreFound(): void
    {
        self::assertGreaterThanOrEqual(6, count(Fixtures::allNotes()));
    }

    #[DataProvider('fixtureNotes')]
    public function testParseAndWriteIsByteIdentical(string $path): void
    {
        $original = Fixtures::read($path);

        self::assertSame($original, NoteDocument::parse($original)->toString());
    }

    #[DataProvider('fixtureNotes')]
    public function testReadingEverythingKeepsBytes(string $path): void
    {
        $original = Fixtures::read($path);
        $note = NoteDocument::parse($original);

        $note->frontmatter()->all();
        foreach ($note->body()->tables() as $table) {
            $table->rows();
        }
        $note->body()->text();

        self::assertSame($original, $note->toString());
    }

    #[DataProvider('fixtureNotes')]
    public function testSettingUnchangedValuesKeepsBytes(string $path): void
    {
        $original = Fixtures::read($path);
        $note = NoteDocument::parse($original);

        foreach ($note->frontmatter()->all() as $key => $value) {
            $note->frontmatter()->set($key, $value);
        }
        foreach ($note->body()->tables() as $table) {
            foreach ($table->rows() as $index => $row) {
                $table->replaceRow($index, $row);
            }
        }

        self::assertSame($original, $note->toString());
    }

    #[DataProvider('fixtureNotes')]
    public function testEveryFixtureHasRequiredFields(string $path): void
    {
        $data = NoteDocument::parse(Fixtures::read($path))->frontmatter()->all();

        self::assertArrayHasKey('typ', $data);
        self::assertArrayHasKey('id', $data);
    }
}
