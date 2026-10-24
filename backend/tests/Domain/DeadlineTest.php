<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Wohnpilot\Domain\Deadline;
use Wohnpilot\Domain\Meter;
use Wohnpilot\Domain\Urgency;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Tests\Fixtures;

final class DeadlineTest extends TestCase
{
    private static function fixture(): Deadline
    {
        return Deadline::fromNote(NoteDocument::parse(Fixtures::read('objekte/efh-musterstrasse/fristen/strom-ablesung-2026.md')));
    }

    /** @param list<array{0: string, 1: string}> $rows newest first */
    private static function meter(string $id, array $rows): Meter
    {
        return MeterFactory::withRows($rows, id: $id);
    }

    public function testReadsTheDeadline(): void
    {
        $deadline = self::fixture();

        self::assertSame('Zählerstand Strom an die Stadtwerke melden', $deadline->title);
        self::assertSame('2026-12-20', $deadline->date);
        self::assertSame('2026-12-05', $deadline->windowStart());
        self::assertSame(14, $deadline->leadDays);
        self::assertSame(['strom-haupt'], $deadline->refersTo);
        self::assertSame('efh-musterstrasse', $deadline->object);
    }

    public function testUrgencyFollowsLeadTime(): void
    {
        $meters = ['strom-haupt' => MeterFactory::strom()];

        self::assertSame(Urgency::OK, self::fixture()->evaluate('2026-10-24', $meters)['status']);
        self::assertSame(57, self::fixture()->evaluate('2026-10-24', $meters)['daysUntil']);
        self::assertSame(Urgency::SOON, self::fixture()->evaluate('2026-12-06', $meters)['status']);
        self::assertSame(Urgency::DUE, self::fixture()->evaluate('2026-12-20', $meters)['status']);
        self::assertSame(-1, self::fixture()->evaluate('2026-12-21', $meters)['daysUntil']);
    }

    public function testReadingBeforeWindowDoesNotCount(): void
    {
        $result = self::fixture()->evaluate('2026-12-06', ['strom-haupt' => MeterFactory::strom()]);

        self::assertNull($result['doneOn']);
        self::assertSame([['id' => 'strom-haupt', 'readOn' => null]], $result['meters']);
    }

    public function testReadingInWindowCompletesIt(): void
    {
        $meters = ['strom-haupt' => self::meter('strom-haupt', [['2026-12-08', '24100'], ['2026-10-24', '23702']])];

        $result = self::fixture()->evaluate('2026-12-10', $meters);

        self::assertSame(Deadline::DONE, $result['status']);
        self::assertSame('2026-12-08', $result['doneOn']);
        self::assertSame('readings', $result['doneBy']);
    }

    public function testLateReadingCompletesItToo(): void
    {
        $meters = ['strom-haupt' => self::meter('strom-haupt', [['2026-12-28', '24200']])];

        self::assertSame(Deadline::DONE, self::fixture()->evaluate('2026-12-30', $meters)['status']);
    }

    public function testAllLinkedMetersMustBeRead(): void
    {
        $deadline = new Deadline('ablesung', 'Ablesen', '2026-12-20', from: '2026-12-05', refersTo: ['strom-haupt', 'wasser-haupt', 'strom-stadtwerke']);
        $strom = self::meter('strom-haupt', [['2026-12-08', '24100']]);
        $wasserOld = self::meter('wasser-haupt', [['2026-10-01', '1234.5']]);
        $wasserNew = self::meter('wasser-haupt', [['2026-12-12', '1250.0']]);

        $partly = $deadline->evaluate('2026-12-10', ['strom-haupt' => $strom, 'wasser-haupt' => $wasserOld]);
        self::assertNull($partly['doneOn']);
        self::assertSame([['id' => 'strom-haupt', 'readOn' => '2026-12-08'], ['id' => 'wasser-haupt', 'readOn' => null]], $partly['meters'], 'the contract id is no meter');

        $complete = $deadline->evaluate('2026-12-13', ['strom-haupt' => $strom, 'wasser-haupt' => $wasserNew]);
        self::assertSame('2026-12-12', $complete['doneOn'], 'done when the last meter was read');
    }

    public function testManualDone(): void
    {
        $note = NoteDocument::parse(Fixtures::read('objekte/efh-musterstrasse/fristen/strom-ablesung-2026.md'));
        $note->frontmatter()->set('erledigt', '2026-12-01');

        $result = Deadline::fromNote($note)->evaluate('2026-12-21', ['strom-haupt' => MeterFactory::strom()]);

        self::assertSame(Deadline::DONE, $result['status']);
        self::assertSame('2026-12-01', $result['doneOn']);
        self::assertSame('manual', $result['doneBy']);
    }

    public function testDeadlineWithoutMetersOrWindow(): void
    {
        $deadline = Deadline::fromNote(NoteDocument::parse("---\ntyp: frist\nid: brief\ntitel: Antwort an Vermieter\ndatum: 2026-11-10\n---\n"));

        self::assertSame('2026-10-11', $deadline->windowStart());
        self::assertSame(Deadline::DEFAULT_LEAD_DAYS, $deadline->leadDays);
        self::assertSame(Urgency::SOON, $deadline->evaluate('2026-10-27')['status']);
        self::assertSame([], $deadline->evaluate('2026-10-27')['meters']);
    }

    public function testNoteWithoutDateIsNoDeadline(): void
    {
        self::assertNull(Deadline::fromNote(NoteDocument::parse("---\ntyp: frist\nid: x\ndatum: bald\n---\n")));
    }
}
