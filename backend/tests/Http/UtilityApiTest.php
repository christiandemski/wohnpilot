<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Http;

/** Utility statements over the API (today 2026-10-24). */
final class UtilityApiTest extends ApiTestCase
{
    private const FOLDER = 'objekte/efh-musterstrasse/nebenkosten';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    private function agenda(): array
    {
        $items = self::decode($this->request('GET', '/api/overview'))['agenda'];
        return array_values(array_filter($items, static fn (array $i): bool => in_array($i['kind'], ['nachzahlung', 'einwand', 'abrechnung'], true)));
    }

    public function testListNewestFirst(): void
    {
        $list = self::decode($this->request('GET', '/api/utilities'))['statements'];
        self::assertSame(['betriebskosten-2025', 'heizkosten-2025', 'betriebskosten-2024', 'heizkosten-2024'], array_column($list, 'id'));
        self::assertSame([665.8, 3665.8], [$list[0]['balance'], $list[0]['total']]);
        self::assertSame(['date' => '2026-10-30', 'daysUntil' => 6], $list[0]['payment']);
        self::assertSame('betriebskosten-2025', $list[1]['billedIn']);
    }

    public function testDetailWithComparisonHeatingAndChecks(): void
    {
        $d = self::decode($this->request('GET', '/api/utilities/betriebskosten-2025'));

        self::assertSame('betriebskosten-2024', $d['previous']['id']);
        self::assertCount(9, $d['comparison']);
        self::assertSame([['id' => 'heizkosten-2025', 'title' => 'Heizkosten 2025', 'total' => 1780.0]], $d['heating']);
        self::assertSame('wasser-haupt', $d['checks'][0]['meterId']);
        self::assertNull($d['checks'][0]['measured'], 'one reading does not cover the year');
        self::assertFalse($d['prepaymentAdopted'], 'the lease still has 280 € from November');
        self::assertStringContainsString('Messdienstes', $d['notes']);
        self::assertSame(404, $this->request('GET', '/api/utilities/gibt-es-nicht')->getStatusCode());
    }

    public function testBackPaymentIsDueAndGoneWhenPaid(): void
    {
        self::assertSame([['nachzahlung', '2026-10-30', 6, 'soon', 665.8]], array_map(static fn (array $i): array => [$i['kind'], $i['date'], $i['daysUntil'], $i['status'], $i['amount']], $this->agenda()));

        $paid = self::decode($this->request('PUT', '/api/utilities/betriebskosten-2025/paid', ['datum' => '2026-10-24']));
        self::assertNull($paid['payment']);
        self::assertStringContainsString("bezahlt: 2026-10-24\n", file_get_contents("$this->root/" . self::FOLDER . '/betriebskosten-2025.md'));
        self::assertSame([], $this->agenda());

        $this->request('PUT', '/api/utilities/betriebskosten-2025/paid', ['datum' => null]);
        self::assertStringNotContainsString('bezahlt:', file_get_contents("$this->root/" . self::FOLDER . '/betriebskosten-2025.md'));
    }

    public function testBackPaymentInTheCalendar(): void
    {
        $path = parse_url(self::decode($this->request('POST', '/api/calendar'))['url'], PHP_URL_PATH);
        $feed = (string) $this->request('GET', $path, header: false)->getBody();
        self::assertStringContainsString('SUMMARY:Nachzahlung Betriebskosten 2025 überweisen', $feed);
        self::assertStringContainsString('665\,80 € an den Vermieter', str_replace("\r\n ", '', $feed), 'commas are escaped in iCalendar');
    }

    public function testObjectionDeadlineInItsLastMonth(): void
    {
        $this->clock('2026-11-05');
        $this->request('PUT', '/api/utilities/betriebskosten-2025/paid', ['datum' => '2026-10-28']);
        self::assertSame([['einwand', 'betriebskosten-2024', '2026-11-30', 25]], array_map(static fn (array $i): array => [$i['kind'], $i['ref']['id'], $i['date'], $i['daysUntil']], $this->agenda()));
    }

    public function testMissingStatementOfTheNextYear(): void
    {
        $this->clock('2027-12-10');
        $missing = array_values(array_filter($this->agenda(), static fn (array $i): bool => $i['kind'] === 'abrechnung'));
        self::assertSame([['Betriebskosten 2026', '2027-12-31', 21, '2026-01-01', '2026-12-31']], array_map(static fn (array $i): array => [$i['name'], $i['date'], $i['daysUntil'], $i['periodFrom'], $i['periodTo']], $missing));
    }

    public function testNewPrepaymentGoesIntoTheLease(): void
    {
        $adopted = self::decode($this->request('POST', '/api/utilities/betriebskosten-2025/prepayment'));
        self::assertTrue($adopted['prepaymentAdopted']);
        $lease = file_get_contents("$this->root/objekte/efh-musterstrasse/mietvertrag/mietvertrag.md");
        self::assertStringContainsString("| 2026-11-01 | 1450        | 310           | Betriebskosten 2025      |            |            |\n", $lease);
        self::assertStringContainsString("| 2027-01-01 | 1495        | 310           | Mieterhöhung Mietspiegel | 2026-10-15 |            |\n", $lease, 'the later row does not jump back to 280');

        $this->request('POST', '/api/utilities/betriebskosten-2025/prepayment');
        self::assertSame($lease, file_get_contents("$this->root/objekte/efh-musterstrasse/mietvertrag/mietvertrag.md"), 'twice is once');
        self::assertSame(422, $this->request('POST', '/api/utilities/heizkosten-2025/prepayment')->getStatusCode());
    }

    public function testCreateWithPositions(): void
    {
        $response = $this->request('POST', '/api/utilities', [
            'art' => 'betriebskosten',
            'von' => '2026-01-01',
            'bis' => '2026-12-31',
            'zugang' => '2026-10-20',
            'vorauszahlungen_eur' => 3360,
            'kontakt' => 'vermieter',
            'verbrauch' => ['wasser_m3' => 108.5],
            'positionen' => [
                ['kostenart' => 'Grundsteuer', 'betrag' => 420],
                ['kostenart' => 'Schornsteinfeger', 'betrag' => 98.5, 'steuer' => 'handwerker', 'steuerbetrag' => 72],
                ['kostenart' => '', 'betrag' => ''],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $d = self::decode($response);
        self::assertSame(['betriebskosten-2026', 518.5, -2841.5], [$d['id'], $d['total'], $d['balance']]);
        self::assertSame(
            "---\ntyp: nebenkosten\nid: betriebskosten-2026\nobjekt: efh-musterstrasse\nart: betriebskosten\nvon: 2026-01-01\nbis: 2026-12-31\nzugang: 2026-10-20\nkontakt: vermieter\nvorauszahlungen_eur: 3360\nverbrauch: { wasser_m3: 108.5 }\nerstellt_von: christian\n---\n"
            . "| Kostenart   | Betrag € | § 35a | davon § 35a € |\n|-------------|----------|-------|---------------|\n"
            . "| Grundsteuer | 420.00   |       |               |\n| Schornsteinfeger | 98.50    | handwerker | 72.00         |\n",
            file_get_contents("$this->root/" . self::FOLDER . '/betriebskosten-2026.md'),
        );

        $invalid = $this->request('POST', '/api/utilities', ['art' => 'betriebskosten', 'von' => '2026-12-31', 'bis' => '2026-01-01', 'positionen' => [['kostenart' => 'Wasser', 'betrag' => 'viel']]]);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(['bis' => 'after_start', 'positionen.0.betrag' => 'number'], self::decode($invalid)['fields'], 'all problems at once');
    }

    public function testPositionsAreReplacedUnlessChangedElsewhere(): void
    {
        $d = self::decode($this->request('GET', '/api/utilities/heizkosten-2024'));
        $original = $d['positions'];
        $mine = [...$original, ['kind' => 'Wartung Gastherme', 'amount' => 160, 'taxKind' => 'handwerker', 'taxAmount' => 120]];

        $saved = self::decode($this->request('PUT', '/api/utilities/heizkosten-2024/positions', ['positionen' => $mine, 'original' => $original]));
        self::assertSame(365.3, $saved['total']);
        $text = file_get_contents("$this->root/" . self::FOLDER . '/heizkosten-2024.md');
        self::assertStringEndsWith(
            "| Brennstoff Gas                    | 172.30   |            |               |\n"
            . "| Betriebsstrom                     | 3.40     |            |               |\n"
            . "| Verbrauchserfassung               | 29.60    |            |               |\n"
            . "| Wartung Gastherme                 | 160.00   | handwerker | 120.00        |\n",
            $text,
            'rows before the change stay as written',
        );

        $stale = $this->request('PUT', '/api/utilities/heizkosten-2024/positions', ['positionen' => [], 'original' => $original]);
        self::assertSame(409, $stale->getStatusCode());
        self::assertArrayHasKey('positionen', self::decode($stale)['fields']);
    }
}
