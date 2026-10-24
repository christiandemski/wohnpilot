<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Http;

/** The year in review and the § 35a list (today 2026-10-24). */
final class ReportApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    public function testYearsFromTheFirstData(): void
    {
        self::assertSame([2026, 2025, 2024], self::decode($this->request('GET', '/api/reports'))['years'], 'the lease and the first statement start in 2024');
        self::assertSame(404, $this->request('GET', '/api/reports/2030')->getStatusCode());
    }

    public function testYearWithRentWorkAndMeters(): void
    {
        $r = self::decode($this->request('GET', '/api/reports/2026'));

        self::assertFalse($r['complete']);
        self::assertSame(['months' => 12, 'cold' => 17400.0, 'utilities' => 3360.0], $r['housing']['rent']);
        self::assertSame([['Hecke zurückschneiden', '2026-02-14', 'Thuja an der Straße']], array_map(static fn (array $m): array => [$m['title'], $m['date'], $m['note']], $r['maintenance']));
        self::assertSame(['wasserhahn-bad'], array_column($r['defects'], 'id'));
        $strom = array_values(array_filter($r['meters'], static fn (array $m): bool => $m['id'] === 'strom-haupt'))[0];
        self::assertFalse($strom['year']['complete'], 'read from August only');
        self::assertNull($strom['cost']);
        self::assertSame(['betriebskosten-2025', 'heizkosten-2025'], array_column(self::decode($this->request('GET', '/api/reports/2025'))['statements'], 'id'));
    }

    public function testTaxFromStatementsAndMinorRepairs(): void
    {
        $tax = self::decode($this->request('GET', '/api/reports/2026'))['tax'];

        self::assertSame(
            [
                ['2026-08-14', 'Wartung Gastherme', 130.0, false],
                ['2026-08-14', 'Abgasmessung', 35.0, false],
                ['2026-09-20', 'Wasserhahn im Bad tropft', 38.5, true],
                ['2026-09-30', 'Schornsteinfeger', 71.2, false],
            ],
            array_map(static fn (array $i): array => [$i['date'], $i['title'], $i['amount'], $i['check']], $tax['items']),
        );
        self::assertSame(['handwerker' => ['amount' => 274.7, 'reduction' => 54.94, 'cap' => 1200.0]], $tax['totals']);
        self::assertSame(54.94, $tax['reduction']);
        self::assertSame([], self::decode($this->request('GET', '/api/reports/2025'))['tax']['items'], 'statements count in the year they arrived');
    }

    public function testLabourShareOfARepair(): void
    {
        $defect = self::decode($this->request('GET', '/api/defects/wasserhahn-bad'));
        $this->request('PUT', '/api/defects/wasserhahn-bad', ['etag' => $defect['etag'], 'changes' => ['arbeitskosten_eur' => 25], 'original' => ['arbeitskosten_eur' => null]]);

        $repair = array_values(array_filter(self::decode($this->request('GET', '/api/reports/2026'))['tax']['items'], static fn (array $i): bool => $i['source']['type'] === 'mangel'))[0];
        self::assertSame([25.0, false], [$repair['amount'], $repair['check']]);
    }

    public function testTaxListAsCsv(): void
    {
        $response = $this->request('GET', '/api/reports/2026/steuer.csv');

        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('filename="wohnpilot-35a-2026.csv"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame(
            "\u{FEFF}Datum;Art;Leistung;Herkunft;Arbeitskosten €;Hinweis\r\n"
            . "14.08.2026;Handwerkerleistung;Wartung Gastherme;Heizkosten 2025;130,00;\r\n"
            . "14.08.2026;Handwerkerleistung;Abgasmessung;Heizkosten 2025;35,00;\r\n"
            . "20.09.2026;Handwerkerleistung;Wasserhahn im Bad tropft;Kleinreparatur;38,50;Arbeitsanteil prüfen\r\n"
            . "30.09.2026;Handwerkerleistung;Schornsteinfeger;Betriebskosten 2025;71,20;\r\n"
            . ";Handwerkerleistung;Summe;;274,70;Steuerermäßigung 54,94 €\r\n",
            (string) $response->getBody(),
        );
    }
}
