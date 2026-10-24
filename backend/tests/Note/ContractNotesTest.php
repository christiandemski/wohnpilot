<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Note;

use PHPUnit\Framework\TestCase;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Tests\Fixtures;

/** Parser checks for the stage 2 notes: contracts, insurance, lease and deadlines. */
final class ContractNotesTest extends TestCase
{
    private const HAUSRAT = 'vertraege/hausrat.md';
    private const INTERNET = 'vertraege/internet-glasfaser.md';
    private const MIETVERTRAG = 'objekte/efh-musterstrasse/mietvertrag/mietvertrag.md';
    private const FRIST = 'objekte/efh-musterstrasse/fristen/strom-ablesung-2026.md';

    public function testReadsInsuranceContract(): void
    {
        $data = NoteDocument::parse(Fixtures::read(self::HAUSRAT))->frontmatter()->all();

        self::assertSame('vertrag', $data['typ']);
        self::assertSame('versicherung', $data['kategorie']);
        self::assertSame(96.4, $data['kosten_eur']);
        self::assertSame('jaehrlich', $data['rhythmus']);
        self::assertSame('2024-12-01', $data['beginn']);
        self::assertSame(3, $data['kuendigungsfrist_monate']);
        self::assertSame(78000, $data['versicherungssumme_eur']);
        self::assertSame(0, $data['selbstbeteiligung_eur']);
    }

    public function testReadsCancelledContractWithPriceHistory(): void
    {
        $note = NoteDocument::parse(Fixtures::read(self::INTERNET));

        self::assertSame('2026-09-20', $note->frontmatter()->get('gekuendigt_am'));
        self::assertSame('2026-11-14', $note->frontmatter()->get('gekuendigt_zum'));
        self::assertSame(['Gültig ab', 'Kosten €', 'Hinweis'], $note->body()->table()->headers());
        self::assertSame(['Gültig ab' => '2024-11-15', 'Kosten €' => '29.99', 'Hinweis' => ''], $note->body()->table()->row(1));
    }

    public function testReadsLeaseWithDepositMap(): void
    {
        $fm = NoteDocument::parse(Fixtures::read(self::MIETVERTRAG))->frontmatter();

        self::assertSame('efh-musterstrasse', $fm->get('objekt'));
        self::assertSame('index', $fm->get('mietart'));
        self::assertSame(
            ['betrag_eur' => 4350, 'art' => 'kautionskonto', 'hinterlegt' => '[Bank], Mietkautionskonto'],
            $fm->get('kaution'),
        );
    }

    public function testReadsRentHistoryNewestFirst(): void
    {
        $table = NoteDocument::parse(Fixtures::read(self::MIETVERTRAG))->body()->table();

        self::assertSame(['Gültig ab', 'Kaltmiete €', 'Nebenkosten €', 'Grund', 'Eingang', 'Zugestimmt'], $table->headers());
        self::assertSame(3, $table->count());
        self::assertSame('2026-10-15', $table->row(0)['Eingang']);
        self::assertSame('', $table->row(0)['Zugestimmt']);
        self::assertSame('Einzug', $table->row(2)['Grund']);
    }

    public function testAgreeingToRentIncreaseChangesOneLine(): void
    {
        $original = Fixtures::read(self::MIETVERTRAG);
        $note = NoteDocument::parse($original);

        $note->body()->table()->replaceRow(0, ['Zugestimmt' => '2026-11-02']);

        self::assertSame(
            [[
                '| 2027-01-01 | 1495        | 280           | Indexanpassung VPI       | 2026-10-15 |            |',
                '| 2027-01-01 | 1495        | 280           | Indexanpassung VPI       | 2026-10-15 | 2026-11-02 |',
            ]],
            self::changedLines($original, $note->toString()),
        );
    }

    public function testDepositChangeKeepsInlineMap(): void
    {
        $original = Fixtures::read(self::MIETVERTRAG);
        $note = NoteDocument::parse($original);
        $kaution = $note->frontmatter()->get('kaution');
        $kaution['betrag_eur'] = 4485;

        $note->frontmatter()->set('kaution', $kaution);

        self::assertSame(
            [[
                'kaution: { betrag_eur: 4350, art: kautionskonto, hinterlegt: "[Bank], Mietkautionskonto" }',
                'kaution: { betrag_eur: 4485, art: kautionskonto, hinterlegt: "[Bank], Mietkautionskonto" }',
            ]],
            self::changedLines($original, $note->toString()),
        );
    }

    public function testReadsDeadlineLinkedToMeters(): void
    {
        $data = NoteDocument::parse(Fixtures::read(self::FRIST))->frontmatter()->all();

        self::assertSame('frist', $data['typ']);
        self::assertSame('Zählerstand Strom an die Stadtwerke melden', $data['titel']);
        self::assertSame('2026-12-05', $data['ab']);
        self::assertSame('2026-12-20', $data['datum']);
        self::assertSame(14, $data['vorlauf_tage']);
        self::assertSame(['strom-haupt'], $data['bezieht_sich_auf']);
        self::assertArrayNotHasKey('erledigt', $data);
    }

    public function testMarkingDeadlineDoneAppendsOneLine(): void
    {
        $original = Fixtures::read(self::FRIST);
        $note = NoteDocument::parse($original);

        $note->frontmatter()->set('erledigt', '2026-12-10');

        self::assertSame(
            str_replace("erstellt_von: christian\n", "erstellt_von: christian\nerledigt: 2026-12-10\n", $original),
            $note->toString(),
        );
        self::assertSame('2026-12-10', NoteDocument::parse($note->toString())->frontmatter()->get('erledigt'));
    }

    /** @return list<array{string, string}> */
    private static function changedLines(string $before, string $after): array
    {
        $old = explode("\n", $before);
        $new = explode("\n", $after);
        self::assertCount(count($old), $new, 'line count changed');
        $changes = [];
        foreach ($old as $i => $line) {
            if ($line !== $new[$i]) {
                $changes[] = [$line, $new[$i]];
            }
        }
        return $changes;
    }
}
