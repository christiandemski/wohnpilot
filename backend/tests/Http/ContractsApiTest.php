<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Http;

/** Contracts, lease, deadlines and "Was steht an" through the API (today 2026-10-24). */
final class ContractsApiTest extends ApiTestCase
{
    private const LEASE = 'objekte/efh-musterstrasse/mietvertrag/mietvertrag.md';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    public function testOverviewListsWhatIsComing(): void
    {
        $data = self::decode($this->request('GET', '/api/overview'));

        self::assertSame(
            [
                ['kuendigung', 'strom-stadtwerke', '2026-11-02', 9, 'soon', 'bald'],
                ['vertragsende', 'internet-glasfaser', '2026-11-14', 21, 'ok', 'bald'],
                ['frist', 'strom-ablesung-2026', '2026-12-20', 57, 'ok', 'spaeter'],
                ['sonderkuendigung', 'strom-stadtwerke', '2026-12-31', 68, 'ok', 'spaeter'],
                ['zustimmung', 'mietvertrag-efh-musterstrasse', '2026-12-31', 68, 'ok', 'spaeter'],
                ['kuendigung', 'hausrat', '2027-08-31', 311, 'ok', 'spaeter'],
            ],
            array_map(static fn (array $i): array => [$i['kind'], $i['ref']['id'], $i['date'], $i['daysUntil'], $i['status'], $i['group']], $data['agenda']),
        );
        self::assertSame('Stadtwerke', $data['agenda'][0]['name']);
        self::assertSame('2026-11-30', $data['agenda'][0]['periodEnd']);
        self::assertSame(['strom-haupt'], $data['agenda'][2]['meterIds']);
        self::assertSame('2027-01-01', $data['agenda'][3]['effective']);
        self::assertSame(1495.0, $data['agenda'][4]['coldRent']);

        self::assertSame(
            [
                'contractsMonthly' => 138.02,
                'rentMonthly' => 1730.0,
                'fixedMonthly' => 1868.02,
                'nextNotice' => ['id' => 'strom-stadtwerke', 'name' => 'Stadtwerke', 'date' => '2026-11-02', 'daysUntil' => 9, 'status' => 'soon'],
            ],
            $data['figures'],
        );
    }

    public function testOverdueDeadlineAndVoluntaryHint(): void
    {
        $this->clock('2026-12-22');

        $agenda = self::decode($this->request('GET', '/api/overview'))['agenda'];
        $groups = array_column($agenda, 'group', 'kind');

        self::assertSame('ueberfaellig', $groups['frist'], 'requested reading not done two days after the date');
        self::assertSame('hinweis', end($agenda)['group']);
        self::assertSame(['wasser-haupt'], end($agenda)['meterIds'], 'electricity has the request, so only water is a voluntary hint');
        self::assertNotContains('vertragsende', array_column($agenda, 'kind'), 'internet ended on 2026-11-14');
    }

    public function testContractListAndDetail(): void
    {
        $contracts = self::decode($this->request('GET', '/api/contracts'))['contracts'];
        self::assertSame(['strom-stadtwerke', 'internet-glasfaser', 'hausrat'], array_column($contracts, 'id'));

        $strom = self::decode($this->request('GET', '/api/contracts/strom-stadtwerke'));
        self::assertSame('running', $strom['term']['state']);
        self::assertSame('2026-11-02', $strom['term']['noticeBy']);
        self::assertSame(85.0, $strom['monthlyCost']);
        self::assertSame(['Gültig ab', 'Arbeitspreis ct/kWh', 'Grundpreis €/Monat', 'Abschlag €'], $strom['prices']['columns']);
        self::assertSame('Preiserhöhung zum 01.01.2027 angekündigt, Sonderkündigung prüfen.', $strom['notes']);
        self::assertSame(404, $this->request('GET', '/api/contracts/gibt-es-nicht')->getStatusCode());
    }

    public function testCreateContract(): void
    {
        $response = $this->request('POST', '/api/contracts', [
            'kategorie' => 'streaming',
            'anbieter' => 'Filmdienst',
            'kosten_eur' => '13,99',
            'beginn' => '2026-03-15',
            'verlaengerung_monate' => 1,
            'kuendigungsfrist_wochen' => 0,
            'kuendigungsfrist_monate' => null,
            'vertragsnummer' => '',
        ]);

        self::assertSame(201, $response->getStatusCode());
        $data = self::decode($response);
        self::assertSame('streaming-filmdienst', $data['id']);
        self::assertSame('2026-11-14', $data['term']['noticeBy']);
        self::assertSame(
            "---\ntyp: vertrag\nid: streaming-filmdienst\nkategorie: streaming\nanbieter: Filmdienst\nkosten_eur: 13.99\nrhythmus: monatlich\nbeginn: 2026-03-15\nverlaengerung_monate: 1\nkuendigungsfrist_wochen: 0\nerstellt_von: christian\n---\n",
            file_get_contents("$this->root/vertraege/streaming-filmdienst.md"),
        );
    }

    public function testInvalidContract(): void
    {
        $response = $this->request('POST', '/api/contracts', ['kategorie' => 'yacht', 'kosten_eur' => '-3', 'kuendigungsfrist_wochen' => 4, 'kuendigungsfrist_monate' => 1, 'beginn' => '01.03.2026']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ['kategorie' => 'unknown', 'anbieter' => 'required', 'kosten_eur' => 'number', 'beginn' => 'date', 'kuendigungsfrist_monate' => 'one_of'],
            self::decode($response)['fields'],
        );
    }

    public function testUpdateContractMergesAndRemovesFields(): void
    {
        $before = self::decode($this->request('GET', '/api/contracts/strom-stadtwerke'));
        $path = "$this->root/vertraege/strom-stadtwerke.md";
        file_put_contents($path, str_replace('vertragsnummer: 4711-0815', 'vertragsnummer: 4711-0816', file_get_contents($path)));

        $response = $this->request('PUT', '/api/contracts/strom-stadtwerke', [
            'changes' => ['kosten_eur' => 91, 'paperless' => ''],
            'original' => ['kosten_eur' => 85, 'paperless' => 1234],
            'etag' => $before['etag'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $text = file_get_contents($path);
        self::assertStringContainsString("vertragsnummer: 4711-0816\nkosten_eur: 91\n", $text, 'change from the Notes app kept');
        self::assertStringNotContainsString('paperless', $text);
        self::assertStringContainsString("geaendert_von: christian\n", $text);
    }

    public function testUpdateContractConflict(): void
    {
        $before = self::decode($this->request('GET', '/api/contracts/strom-stadtwerke'));
        $path = "$this->root/vertraege/strom-stadtwerke.md";
        file_put_contents($path, str_replace('kosten_eur: 85', 'kosten_eur: 89', file_get_contents($path)));

        $response = $this->request('PUT', '/api/contracts/strom-stadtwerke', ['changes' => ['kosten_eur' => 91], 'original' => ['kosten_eur' => 85], 'etag' => $before['etag']]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['kosten_eur' => ['mine' => 91, 'theirs' => 89]], self::decode($response)['fields']);
    }

    public function testAddPriceCreatesHistoryOnce(): void
    {
        $body = ['gueltig_ab' => '2027-01-01', 'values' => ['Kosten €' => '104.90', 'Hinweis' => 'Beitragsanpassung']];

        self::assertSame(201, $this->request('POST', '/api/contracts/hausrat/prices', $body)->getStatusCode());
        $this->request('POST', '/api/contracts/hausrat/prices', $body);

        $text = file_get_contents("$this->root/vertraege/hausrat.md");
        self::assertStringEndsWith(
            "geaendert_von: christian\n---\n"
            . "| Gültig ab  | Kosten € | Hinweis           |\n"
            . "|------------|----------|-------------------|\n"
            . "| 2027-01-01 | 104.90   | Beitragsanpassung |\n"
            . "\nUnterversicherungsverzicht laut Police vereinbart. Bei Umzug Wohnfläche melden.\n",
            $text,
        );
        self::assertSame(1, substr_count($text, '2027-01-01'));
        $data = self::decode($this->request('GET', '/api/contracts/hausrat'));
        self::assertSame([], $data['priceChanges'], 'a single row is the start price, not a change');
    }

    public function testPriceWithUnknownColumnIsRejected(): void
    {
        $response = $this->request('POST', '/api/contracts/strom-stadtwerke/prices', ['gueltig_ab' => '2027-04-01', 'values' => ['Kosten €' => '90']]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['values' => 'unknown_column'], self::decode($response)['fields']);
    }

    public function testLease(): void
    {
        $lease = self::decode($this->request('GET', '/api/lease'))['lease'];

        self::assertSame('mietvertrag-efh-musterstrasse', $lease['id']);
        self::assertSame(['cold' => 1450.0, 'utilities' => 280.0, 'validFrom' => '2026-01-01'], $lease['rent']);
        self::assertSame('2026-12-31', $lease['consentDeadlines'][0]['consentBy']);
        self::assertSame(['noticeBy' => '2026-11-04', 'endsOn' => '2027-01-31', 'daysUntil' => 11], $lease['tenantNotice']);
        self::assertSame(['betrag_eur' => 4350, 'art' => 'kautionskonto', 'hinterlegt' => '[Bank], Mietkautionskonto'], $lease['fields']['kaution']);
    }

    public function testAgreeToRentIncrease(): void
    {
        $response = $this->request('PUT', '/api/lease/rent/2027-01-01/agreed', ['datum' => '2026-11-02']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], self::decode($response)['lease']['consentDeadlines']);
        self::assertStringContainsString('| Mieterhöhung Mietspiegel | 2026-10-15 | 2026-11-02 |', file_get_contents("$this->root/" . self::LEASE));

        $undone = self::decode($this->request('PUT', '/api/lease/rent/2027-01-01/agreed', ['datum' => null]));
        self::assertCount(1, $undone['lease']['consentDeadlines']);
        self::assertSame(404, $this->request('PUT', '/api/lease/rent/2030-01-01/agreed', ['datum' => '2026-11-02'])->getStatusCode());
    }

    public function testAddRentChangeOnce(): void
    {
        $body = ['gueltig_ab' => '2028-01-01', 'kaltmiete_eur' => 1540, 'nebenkosten_eur' => '295,50', 'grund' => 'Mieterhöhung Modernisierung', 'eingang' => '2027-10-02'];

        $this->request('POST', '/api/lease/rent', $body);
        $lease = self::decode($this->request('POST', '/api/lease/rent', $body))['lease'];

        $text = file_get_contents("$this->root/" . self::LEASE);
        self::assertSame(1, substr_count($text, '| 2028-01-01 |'));
        self::assertMatchesRegularExpression('/\| 2028-01-01 \| 1540 +\| 295\.50 +\| Mieterhöhung Modernisierung \| 2027-10-02 \| +\|\n\| 2027-01-01/', $text);
        self::assertSame('2027-12-31', $lease['consentDeadlines'][0]['consentBy'], 'newest first');
    }

    public function testUpdateLeaseDeposit(): void
    {
        $before = self::decode($this->request('GET', '/api/lease'))['lease'];
        $kaution = $before['fields']['kaution'];

        $response = $this->request('PUT', '/api/lease', [
            'changes' => ['kaution' => ['betrag_eur' => 4485, 'art' => 'kautionskonto', 'hinterlegt' => $kaution['hinterlegt']]],
            'original' => ['kaution' => $kaution],
            'etag' => $before['etag'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('kaution: { betrag_eur: 4485, art: kautionskonto, hinterlegt: "[Bank], Mietkautionskonto" }', file_get_contents("$this->root/" . self::LEASE));
    }

    public function testCreateLease(): void
    {
        self::assertSame(409, $this->request('POST', '/api/lease', ['beginn' => '2024-12-01'])->getStatusCode(), 'one per object');
        unlink("$this->root/" . self::LEASE);
        $this->request('POST', '/api/sync');

        $response = $this->request('POST', '/api/lease', ['beginn' => '2024-12-01', 'kaltmiete_eur' => 1450, 'nebenkosten_eur' => 250, 'kaution' => ['betrag_eur' => 4350, 'art' => 'kautionskonto']]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            "---\ntyp: mietvertrag\nid: mietvertrag-efh-musterstrasse\nobjekt: efh-musterstrasse\nbeginn: 2024-12-01\nmietart: standard\nkaltmiete_eur: 1450\nnebenkosten_eur: 250\nkaution: { betrag_eur: 4350, art: kautionskonto }\nerstellt_von: christian\n---\n"
            . "| Gültig ab  | Kaltmiete € | Nebenkosten € | Grund  | Eingang | Zugestimmt |\n"
            . "|------------|-------------|---------------|--------|---------|------------|\n"
            . "| 2024-12-01 | 1450        | 250           | Einzug |         |            |\n",
            file_get_contents("$this->root/" . self::LEASE),
        );
    }

    public function testDeadlines(): void
    {
        $list = self::decode($this->request('GET', '/api/deadlines'));

        self::assertSame(['strom-ablesung-2026'], array_column($list['open'], 'id'));
        self::assertSame([], $list['done']);
        self::assertSame('2026-12-05', $list['open'][0]['windowStart']);
        self::assertSame([['id' => 'strom-haupt', 'readOn' => null]], $list['open'][0]['evaluation']['meters']);
    }

    public function testCreateDeadlineAndMarkItDone(): void
    {
        $response = $this->request('POST', '/api/deadlines', ['titel' => 'Wasserzähler melden', 'datum' => '2026-11-15', 'ab' => '2026-11-01', 'bezieht_sich_auf' => ['wasser-haupt']]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            "---\ntyp: frist\nid: wasserzaehler-melden\nobjekt: efh-musterstrasse\ntitel: Wasserzähler melden\ndatum: 2026-11-15\nab: 2026-11-01\nbezieht_sich_auf: [wasser-haupt]\nerstellt_von: christian\n---\n",
            file_get_contents("$this->root/objekte/efh-musterstrasse/fristen/wasserzaehler-melden.md"),
        );
        self::assertSame('ok', self::decode($response)['evaluation']['status'], '22 days ahead, 14 days lead');

        $done = self::decode($this->request('PUT', '/api/deadlines/wasserzaehler-melden/done', ['datum' => '2026-10-24']));
        self::assertSame(['done', '2026-10-24', 'manual'], [$done['evaluation']['status'], $done['evaluation']['doneOn'], $done['evaluation']['doneBy']]);
        self::assertSame(['wasserzaehler-melden'], array_column(self::decode($this->request('GET', '/api/deadlines'))['done'], 'id'));

        $open = self::decode($this->request('PUT', '/api/deadlines/wasserzaehler-melden/done', ['datum' => null]));
        self::assertSame('ok', $open['evaluation']['status']);
        self::assertStringNotContainsString('erledigt', file_get_contents("$this->root/objekte/efh-musterstrasse/fristen/wasserzaehler-melden.md"));
    }

    public function testHouseholdDeadlineAndValidation(): void
    {
        $created = self::decode($this->request('POST', '/api/deadlines', ['titel' => 'Rundfunkbeitrag ummelden', 'datum' => '2026-12-01', 'haushalt' => true]));
        self::assertSame('', $created['object']);
        self::assertFileExists("$this->root/fristen/rundfunkbeitrag-ummelden.md");

        $invalid = $this->request('POST', '/api/deadlines', ['titel' => ' ', 'datum' => '2026-11-01', 'ab' => '2026-11-05', 'bezieht_sich_auf' => ['Strom Haupt']]);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(['titel' => 'required', 'bezieht_sich_auf' => 'id', 'ab' => 'after_date'], self::decode($invalid)['fields']);
    }

    public function testUpdateDeadlineChecksWindowAgainstStoredDate(): void
    {
        $before = self::decode($this->request('GET', '/api/deadlines/strom-ablesung-2026'));

        $response = $this->request('PUT', '/api/deadlines/strom-ablesung-2026', ['changes' => ['ab' => '2026-12-21'], 'original' => ['ab' => '2026-12-05'], 'etag' => $before['etag']]);

        self::assertSame(['ab' => 'after_date'], self::decode($response)['fields']);
    }
}
