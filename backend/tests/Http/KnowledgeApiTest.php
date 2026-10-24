<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/** Contacts, knowledge, emergency sheet, search, documents and repeated writes (today 2026-10-24). */
final class KnowledgeApiTest extends ApiTestCase
{
    private const PDF = "%PDF-1.4\n% fake letter\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    private function upload(string $uri, string $field, string $bytes, string $name, array $body = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $uri)
            ->withHeader('X-Requested-With', 'wohnpilot')
            ->withParsedBody($body)
            ->withUploadedFiles([$field => new UploadedFile((new StreamFactory())->createStream($bytes), $name, 'application/pdf', strlen($bytes))]);
        foreach ($headers as $header => $value) {
            $request = $request->withHeader($header, $value);
        }
        return $this->app->handle($request);
    }

    public function testContactsWithWhereTheyAreUsed(): void
    {
        $contacts = self::decode($this->request('GET', '/api/contacts'))['contacts'];
        self::assertSame(['vermieter', 'heizungsbau-mueller', 'stadtwerke-stoerung'], array_column($contacts, 'id'), 'by name; the example name "[Name Vermieter]" sorts first');

        $landlord = self::decode($this->request('GET', '/api/contacts/vermieter'));
        self::assertSame('+491701234567', $landlord['mobile'], 'the plus stays');
        self::assertSame(
            [['mangel', 'fenster-schlafzimmer', 'Fenster im Schlafzimmer zieht'], ['mietvertrag', 'mietvertrag-efh-musterstrasse', 'Mietvertrag']],
            array_map(static fn (array $u): array => [$u['type'], $u['id'], $u['title']], $landlord['usedIn']),
        );
        self::assertSame([['vertrag', 'strom-stadtwerke', 'Stadtwerke']], array_map(static fn (array $u): array => array_values($u), self::decode($this->request('GET', '/api/contacts/stadtwerke-stoerung'))['usedIn']));
    }

    public function testContactAsVcard(): void
    {
        $card = $this->request('GET', '/api/contacts/heizungsbau-mueller/vcard');

        self::assertStringStartsWith('text/vcard', $card->getHeaderLine('Content-Type'));
        self::assertStringContainsString('filename="heizungsbau-mueller.vcf"', $card->getHeaderLine('Content-Disposition'));
        $text = (string) $card->getBody();
        self::assertStringContainsString("FN:Heizungsbau Müller\r\n", $text);
        self::assertStringContainsString("TEL;TYPE=WORK,VOICE:0800 1234567\r\n", $text);
        self::assertStringContainsString("NOTE:Wohnpilot · heizung\r\n", $text);
        $landlordCard = (string) $this->request('GET', '/api/contacts/vermieter/vcard')->getBody();
        self::assertStringNotContainsString('[Telefon]', $landlordCard, 'placeholders stay out');
        self::assertStringContainsString("TEL;TYPE=CELL:+491701234567\r\n", $landlordCard);
    }

    public function testCreateContact(): void
    {
        self::assertSame(['email' => 'email', 'telefon' => 'phone'], self::decode($this->request('POST', '/api/contacts', ['name' => 'Elektro Schmidt', 'email' => 'keine', 'telefon' => 'ruf an']))['fields']);

        $created = self::decode($this->request('POST', '/api/contacts', ['name' => 'Elektro Schmidt', 'rolle' => 'handwerker', 'gewerk' => 'elektro', 'telefon' => '+49 30 123456', 'notfall' => ['strom']]));
        self::assertSame(['elektro-schmidt', '+49 30 123456', ''], [$created['id'], $created['phone'], $created['object']]);
        self::assertStringContainsString("telefon: +49 30 123456\n", file_get_contents("$this->root/kontakte/elektro-schmidt.md"));

        $landlord = self::decode($this->request('POST', '/api/contacts', ['name' => 'Hausmeister', 'rolle' => 'hausverwaltung', 'zuhause' => true]));
        self::assertSame('efh-musterstrasse', $landlord['object']);
    }

    public function testEntriesTakeAContact(): void
    {
        $defect = self::decode($this->request('GET', '/api/defects/wasserhahn-bad'));
        $saved = self::decode($this->request('PUT', '/api/defects/wasserhahn-bad', ['etag' => $defect['etag'], 'changes' => ['kontakt' => 'heizungsbau-mueller'], 'original' => []]));
        self::assertSame('heizungsbau-mueller', $saved['fields']['kontakt']);
        self::assertContains('wasserhahn-bad', array_column(self::decode($this->request('GET', '/api/contacts/heizungsbau-mueller'))['usedIn'], 'id'));

        self::assertSame(['kontakt' => 'id'], self::decode($this->request('POST', '/api/appointments', ['titel' => 'X', 'datum' => '2026-11-03', 'kontakt' => 'Kein Slug!']))['fields']);
    }

    public function testKnowledgeWithText(): void
    {
        $pages = self::decode($this->request('GET', '/api/knowledge'))['pages'];
        self::assertSame(['haupthahn', 'sicherungskasten', 'heizung-umstellen', 'notfall-wasser'], array_column($pages, 'id'), 'places, then operating, then emergency');

        $box = self::decode($this->request('GET', '/api/knowledge/sicherungskasten'));
        self::assertStringContainsString("| F2        | Küche         | Herd, eigener Kreis |", $box['text']);
        self::assertSame(['anhaenge/wissen/haupthahn/2026-10-24-1.jpg'], array_column(self::decode($this->request('GET', '/api/knowledge/haupthahn'))['photos'], 'path'));

        $created = self::decode($this->request('POST', '/api/knowledge', ['titel' => 'Wandfarbe Wohnzimmer', 'kategorie' => 'kennwert', 'raum' => 'wohnzimmer', 'text' => "Alpina Weiß, matt.\r\nRest im Keller."]));
        self::assertSame("Alpina Weiß, matt.\nRest im Keller.\n", $created['text']);
        self::assertSame(['szenario' => 'required'], self::decode($this->request('POST', '/api/knowledge', ['titel' => 'Gasgeruch', 'kategorie' => 'notfall']))['fields']);
    }

    public function testTextIsMergedLikeFields(): void
    {
        $page = self::decode($this->request('GET', '/api/knowledge/heizung-umstellen'));
        $original = $page['text'];
        $mine = str_replace('Vorlauf 60 °C', 'Vorlauf 55 °C', $original);

        $saved = self::decode($this->request('PUT', '/api/knowledge/heizung-umstellen', ['etag' => $page['etag'], 'changes' => ['text' => $mine, 'raum' => 'keller'], 'original' => ['text' => $original]]));
        self::assertStringContainsString('Vorlauf 55 °C', $saved['text']);
        self::assertSame('keller', $saved['fields']['raum']);

        // Someone changed the text in the Notes app meanwhile: conflict, nothing overwritten
        $path = "$this->root/objekte/efh-musterstrasse/wissen/heizung-umstellen.md";
        file_put_contents($path, str_replace('Vorlauf 55 °C', 'Vorlauf 50 °C', file_get_contents($path)));
        $conflict = $this->request('PUT', '/api/knowledge/heizung-umstellen', ['etag' => $saved['etag'], 'changes' => ['text' => str_replace('55', '58', $saved['text'])], 'original' => ['text' => $saved['text']]]);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertStringContainsString('50 °C', self::decode($conflict)['fields']['text']['theirs']);
        self::assertStringContainsString('Vorlauf 50 °C', file_get_contents($path));
    }

    public function testEmergencySheet(): void
    {
        $sheet = array_column(self::decode($this->request('GET', '/api/emergency'))['scenarios'], null, 'id');

        self::assertSame(['wasser', 'strom', 'gas', 'heizung'], array_keys($sheet));
        self::assertStringStartsWith('1. Haupthahn zudrehen.', $sheet['wasser']['steps']['text']);
        self::assertSame([['haupthahn', 'keller', 'Hinter dem Wasserzähler an der Wand, blauer Griff.']], array_map(static fn (array $p): array => [$p['id'], $p['room'], $p['hint']], $sheet['wasser']['places']));
        self::assertSame(['vermieter', 'stadtwerke-stoerung'], array_column($sheet['wasser']['contacts'], 'id'));
        self::assertSame('08001234', $sheet['gas']['contacts'][0]['phone']);
        self::assertNull($sheet['gas']['steps'], 'no page for gas yet: the app shows its own steps');
        self::assertSame(['sicherungskasten'], array_column($sheet['strom']['places'], 'id'));
    }

    public function testSearch(): void
    {
        $search = fn (string $q, string $typ = ''): array => self::decode($this->request('GET', '/api/search?q=' . rawurlencode($q) . ($typ ? "&typ=$typ" : '')))['results'];

        self::assertSame('sicherungskasten', $search('Sicherungskasten')[0]['id'], 'title first');
        self::assertStringStartsWith('Hinter dem Wasserzähler', $search('haupthahn')[0]['snippet'], 'only in the title: the start of the text');
        self::assertContains('fenster-schlafzimmer', array_column($search('fensterbauer'), 'id'), 'text in the history table');
        self::assertSame(['waschmaschine'], array_column($search('flusensieb'), 'id'));
        self::assertStringContainsString('Herd, eigener Kreis', implode(' / ', array_column($search('herd'), 'snippet')));
        self::assertNotEmpty($search('mangel'), 'mangel finds Mängel');
        self::assertSame([], $search('herd wasserrohrbruch'), 'every word must match');
        self::assertSame(['kontakt'], array_values(array_unique(array_column($search('stadtwerke', 'kontakt'), 'type'))));
        self::assertSame([], $search('a'), 'one letter is no search');
    }

    public function testDocumentsOnAContract(): void
    {
        $added = $this->upload('/api/documents/vertrag/strom-stadtwerke', 'dokument', self::PDF, 'brief.pdf', ['titel' => 'Preiserhöhung [2027]']);
        self::assertSame(201, $added->getStatusCode());
        self::assertSame(
            [['path' => 'anhaenge/vertraege/strom-stadtwerke/2026-10-24-preiserhoehung-2027.pdf', 'title' => 'Preiserhöhung 2027', 'url' => '/api/files/anhaenge/vertraege/strom-stadtwerke/2026-10-24-preiserhoehung-2027.pdf']],
            self::decode($added)['documents'],
        );
        self::assertStringEndsWith("\n[Preiserhöhung 2027](../anhaenge/vertraege/strom-stadtwerke/2026-10-24-preiserhoehung-2027.pdf)\n", file_get_contents("$this->root/vertraege/strom-stadtwerke.md"));

        $file = $this->request('GET', '/api/files/anhaenge/vertraege/strom-stadtwerke/2026-10-24-preiserhoehung-2027.pdf');
        self::assertSame(['application/pdf', self::PDF], [$file->getHeaderLine('Content-Type'), (string) $file->getBody()]);
        self::assertStringStartsWith('inline;', $file->getHeaderLine('Content-Disposition'));

        self::assertSame(['dokument' => 'not_a_pdf'], self::decode($this->upload('/api/documents/vertrag/strom-stadtwerke', 'dokument', 'kein pdf', 'x.pdf'))['fields']);
        self::assertSame([], self::decode($this->request('DELETE', '/api/documents/vertrag/strom-stadtwerke?path=anhaenge/vertraege/strom-stadtwerke/2026-10-24-preiserhoehung-2027.pdf'))['documents']);
        self::assertFileDoesNotExist("$this->root/anhaenge/vertraege/strom-stadtwerke/2026-10-24-preiserhoehung-2027.pdf");
    }

    public function testFilesOfAnEntryListDocumentsAndPhotos(): void
    {
        $this->upload('/api/documents/vertrag/strom-stadtwerke', 'dokument', self::PDF, 'brief.pdf', ['titel' => 'Brief']);
        $photo = $this->upload('/api/photos/vertrag/strom-stadtwerke', 'foto', "\xFF\xD8\xFF\xE0letter", 'brief.jpg', ['alt' => 'Brief, Seite 2']);
        self::assertSame(201, $photo->getStatusCode(), 'a photographed letter can go to a contract');

        $files = self::decode($this->request('GET', '/api/documents/vertrag/strom-stadtwerke'));
        self::assertSame(['Brief'], array_column($files['documents'], 'title'));
        self::assertSame(['anhaenge/vertraege/strom-stadtwerke/2026-10-24-1.jpg'], array_column($files['photos'], 'path'));
        self::assertSame(404, $this->request('GET', '/api/documents/zaehler/strom-haupt')->getStatusCode());

        $notes = self::decode($this->request('GET', '/api/contracts/strom-stadtwerke'))['notes'];
        self::assertStringContainsString('Sonderkündigung prüfen', $notes);
        self::assertStringNotContainsString('](', $notes, 'files show under Unterlagen, not as Markdown in the notes');
    }

    public function testRepeatedWriteGetsTheFirstAnswer(): void
    {
        $send = fn (string $key): ResponseInterface => $this->app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/defects')
                ->withHeader('X-Requested-With', 'wohnpilot')
                ->withHeader('Idempotency-Key', $key)
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody(['titel' => 'Kellertür klemmt']),
        );

        $first = $send('offline-1');
        $again = $send('offline-1');
        self::assertSame([201, 201], [$first->getStatusCode(), $again->getStatusCode()]);
        self::assertSame('true', $again->getHeaderLine('Idempotent-Replay'));
        self::assertSame(self::decode($first)['id'], self::decode($again)['id']);
        self::assertFileDoesNotExist("$this->root/objekte/efh-musterstrasse/maengel/kellertuer-klemmt-2.md", 'no second defect');

        self::assertSame('kellertuer-klemmt-2', self::decode($send('offline-2'))['id'], 'another key is another entry');
    }
}
