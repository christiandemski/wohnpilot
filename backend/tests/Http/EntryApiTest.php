<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/** Deleting entries (today 2026-10-24). */
final class EntryApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    private function upload(string $uri, string $field, string $bytes, array $body = []): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $uri)
            ->withHeader('X-Requested-With', 'wohnpilot')
            ->withParsedBody($body)
            ->withUploadedFiles([$field => new UploadedFile((new StreamFactory())->createStream($bytes), 'x', 'application/octet-stream', strlen($bytes))]);
        self::assertSame(201, $this->app->handle($request)->getStatusCode());
    }

    private function delete(string $type, string $id, string $etag): int
    {
        return $this->request('DELETE', "/api/entries/$type/$id?etag=" . rawurlencode($etag))->getStatusCode();
    }

    public function testMeterGoesWithTheProofPhotosOfItsReadings(): void
    {
        $this->upload('/api/meters/strom-haupt/readings', 'foto', "\xFF\xD8\xFFzaehler", ['date' => '2026-10-24', 'value' => '23720.5', 'occasion' => 'Stichtag']);
        self::assertFileExists("$this->root/anhaenge/zaehler/strom-haupt/2026-10-24-1.jpg");
        $etag = self::decode($this->request('GET', '/api/meters/strom-haupt'))['etag'];

        self::assertSame(200, $this->delete('zaehler', 'strom-haupt', $etag));
        self::assertFileDoesNotExist("$this->root/objekte/efh-musterstrasse/zaehler/strom-haupt.md");
        self::assertFileDoesNotExist("$this->root/anhaenge/zaehler/strom-haupt/2026-10-24-1.jpg");
        self::assertSame(['wasser-haupt'], array_column(self::decode($this->request('GET', '/api/meters'))['meters'], 'id'));
    }

    public function testContractGoesWithItsDocuments(): void
    {
        $this->upload('/api/documents/vertrag/hausrat', 'dokument', "%PDF-1.4 police", ['titel' => 'Police']);
        $etag = self::decode($this->request('GET', '/api/contracts/hausrat'))['etag'];

        self::assertSame(200, $this->delete('vertrag', 'hausrat', $etag));
        self::assertFileDoesNotExist("$this->root/vertraege/hausrat.md");
        self::assertFileDoesNotExist("$this->root/anhaenge/vertraege/hausrat/2026-10-24-police.pdf");
        self::assertSame(404, $this->request('GET', '/api/contracts/hausrat')->getStatusCode());
    }

    public function testOtherKindsByTheirEtag(): void
    {
        foreach ([
            ['termin', 'schornsteinfeger-2026-11', '/api/appointments/schornsteinfeger-2026-11', 'objekte/efh-musterstrasse/termine/schornsteinfeger-2026-11.md'],
            ['mangel', 'wasserhahn-bad', '/api/defects/wasserhahn-bad', 'objekte/efh-musterstrasse/maengel/wasserhahn-bad.md'],
            ['geraet', 'gastherme', '/api/devices/gastherme', 'objekte/efh-musterstrasse/geraete/gastherme.md'],
            ['frist', 'strom-ablesung-2026', '/api/deadlines/strom-ablesung-2026', 'objekte/efh-musterstrasse/fristen/strom-ablesung-2026.md'],
            ['kontakt', 'heizungsbau-mueller', '/api/contacts/heizungsbau-mueller', 'kontakte/heizungsbau-mueller.md'],
            ['wissen', 'haupthahn', '/api/knowledge/haupthahn', 'objekte/efh-musterstrasse/wissen/haupthahn.md'],
            ['nebenkosten', 'heizkosten-2024', '/api/utilities/heizkosten-2024', 'objekte/efh-musterstrasse/nebenkosten/heizkosten-2024.md'],
        ] as [$type, $id, $detail, $file]) {
            $etag = self::decode($this->request('GET', $detail))['etag'];
            self::assertSame(200, $this->delete($type, $id, $etag), $type);
            self::assertFileDoesNotExist("$this->root/$file", $type);
        }
    }

    public function testRefusals(): void
    {
        $lease = self::decode($this->request('GET', '/api/lease'))['lease'];
        self::assertSame(404, $this->delete('mietvertrag', $lease['id'], $lease['etag']), 'the lease stays');
        self::assertSame(404, $this->delete('wartung', 'gibt-es-nicht', 'x'));
        self::assertSame(422, $this->request('DELETE', '/api/entries/geraet/gastherme')->getStatusCode(), 'no etag, no delete');
        self::assertSame(409, $this->delete('geraet', 'gastherme', 'veraltet'));
        self::assertFileExists("$this->root/objekte/efh-musterstrasse/geraete/gastherme.md");
    }
}
