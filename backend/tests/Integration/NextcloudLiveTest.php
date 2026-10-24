<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Integration;

use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Domain\Contract;
use Wohnpilot\Domain\Lease;
use Wohnpilot\Domain\Meter;
use Wohnpilot\Domain\MeterNote;
use Wohnpilot\Domain\Reading;
use Wohnpilot\Note\HistoryTable;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\ConflictException;
use Wohnpilot\Storage\NextcloudFiles;
use Wohnpilot\Storage\NotFoundException;
use Wohnpilot\Storage\NextcloudNotesAdapter;

/**
 * Runs against the real Nextcloud from .env. Only with `phpunit --group live`.
 * Creates notes below Wohnpilot/_test/ and removes them again.
 */
#[Group('live')]
final class NextcloudLiveTest extends TestCase
{
    private NextcloudNotesAdapter $adapter;
    private string $path;
    /** @var list<string> further notes this test created */
    private array $created = [];

    protected function setUp(): void
    {
        Dotenv::createImmutable(dirname(__DIR__, 3))->safeLoad();
        $url = $_ENV['NEXTCLOUD_URL'] ?? '';
        $user = $_ENV['NEXTCLOUD_USER'] ?? '';
        $password = $_ENV['NEXTCLOUD_APP_PASSWORD'] ?? '';
        if ($url === '' || $user === '' || $password === '') {
            self::markTestSkipped('NEXTCLOUD_URL, NEXTCLOUD_USER and NEXTCLOUD_APP_PASSWORD are not set in .env');
        }
        $this->adapter = NextcloudNotesAdapter::connect($url, $user, $password, $_ENV['NEXTCLOUD_FOLDER'] ?? 'Wohnpilot');
        $this->path = '_test/roundtrip-' . bin2hex(random_bytes(3)) . '.md';
    }

    protected function tearDown(): void
    {
        if (isset($this->adapter)) {
            try {
                $this->adapter->delete($this->path);
            } catch (\Throwable) {
            }
            foreach ($this->created as $path) {
                try {
                    $this->adapter->delete($path);
                } catch (\Throwable) {
                }
            }
        }
    }

    public function testSettingsAreReadable(): void
    {
        $settings = $this->adapter->settings();

        self::assertArrayHasKey('notesPath', $settings);
        fwrite(STDERR, "\nNotes folder: {$settings['notesPath']}, suffix: " . ($settings['fileSuffix'] ?? '?') . "\n");
    }

    public function testFixtureSurvivesTheRoundTripThroughNextcloud(): void
    {
        $content = file_get_contents(dirname(__DIR__, 3) . '/fixtures/Wohnpilot/objekte/efh-musterstrasse/zaehler/strom-haupt.md');

        $this->adapter->create($this->path, $content);
        $read = $this->adapter->get($this->path);

        self::assertSame($content, $read->content, 'Nextcloud changed the note content');
        self::assertSame($content, NoteDocument::parse($read->content)->toString());
    }

    /** @return array<string, array{string}> the note types of stage 2 */
    public static function stageTwoFixtures(): array
    {
        return [
            'contract' => ['vertraege/strom-stadtwerke.md'],
            'insurance' => ['vertraege/hausrat.md'],
            'cancelled contract' => ['vertraege/internet-glasfaser.md'],
            'lease' => ['objekte/efh-musterstrasse/mietvertrag/mietvertrag.md'],
            'deadline' => ['objekte/efh-musterstrasse/fristen/strom-ablesung-2026.md'],
            // stage 3
            'maintenance with season' => ['objekte/efh-musterstrasse/wartung/heckenschnitt.md'],
            'maintenance never done' => ['objekte/efh-musterstrasse/wartung/heizungswartung.md'],
            'appointment' => ['objekte/efh-musterstrasse/termine/schornsteinfeger-2026-11.md'],
            'waste' => ['objekte/efh-musterstrasse/termine/muell.md'],
            'defect with photo' => ['objekte/efh-musterstrasse/maengel/fenster-schlafzimmer.md'],
            'device' => ['objekte/efh-musterstrasse/geraete/waschmaschine.md'],
            // stage 4
            'landlord contact with objekt' => ['kontakte/vermieter.md'],
            'tradesman contact' => ['kontakte/heizungsbau-mueller.md'],
            'utility hotline' => ['kontakte/stadtwerke-stoerung.md'],
            'knowledge place with table' => ['objekte/efh-musterstrasse/wissen/sicherungskasten.md'],
            'knowledge place' => ['objekte/efh-musterstrasse/wissen/haupthahn.md'],
            'knowledge how-to' => ['objekte/efh-musterstrasse/wissen/heizung-umstellen.md'],
            'emergency steps' => ['objekte/efh-musterstrasse/wissen/notfall-wasser.md'],
        ];
    }

    #[DataProvider('stageTwoFixtures')]
    public function testStageTwoNotesSurviveInNestedFolders(string $fixture): void
    {
        $content = file_get_contents(dirname(__DIR__, 3) . "/fixtures/Wohnpilot/$fixture");
        $path = $this->track('_test/' . bin2hex(random_bytes(3)) . '/' . $fixture);

        $this->adapter->create($path, $content);
        $read = $this->adapter->get($path);

        self::assertSame($content, $read->content, "Nextcloud changed $fixture");
        self::assertContains($path, array_map(static fn ($i) => $i->path, $this->adapter->list()), 'found again below its folders');
    }

    public function testAppEditsComeBackAsWritten(): void
    {
        $root = dirname(__DIR__, 3) . '/fixtures/Wohnpilot';
        $folder = '_test/' . bin2hex(random_bytes(3));

        // A price change as ContractService::addPrice writes it
        $contractPath = $this->track("$folder/vertraege/strom-stadtwerke.md");
        $contract = $this->adapter->create($contractPath, file_get_contents("$root/vertraege/strom-stadtwerke.md"));
        $note = NoteDocument::parse($contract->content);
        HistoryTable::insertNewestFirst($note->body()->table(), Contract::PRICE_COLUMN, ['Gültig ab' => '2027-07-01', 'Arbeitspreis ct/kWh' => '35.1', 'Grundpreis €/Monat' => '14.2']);
        $note->frontmatter()->set('geaendert_von', 'live-test');
        $written = $this->adapter->update($contractPath, $note->toString(), $contract->etag);
        self::assertSame($note->toString(), $this->adapter->get($contractPath)->content);
        self::assertSame(['2027-01-01', '2027-07-01'], array_column(Contract::fromNote(NoteDocument::parse($written->content))->priceChanges('2026-10-24'), 'effective'));

        // Consent to the rent increase as LeaseService::agree writes it
        $leasePath = $this->track("$folder/objekte/efh-musterstrasse/mietvertrag/mietvertrag.md");
        $lease = $this->adapter->create($leasePath, file_get_contents("$root/objekte/efh-musterstrasse/mietvertrag/mietvertrag.md"));
        $note = NoteDocument::parse($lease->content);
        $table = $note->body()->table();
        $table->replaceRow(HistoryTable::find($table, 'Gültig ab', '2027-01-01'), ['Zugestimmt' => '2026-10-24']);
        $this->adapter->update($leasePath, $note->toString(), $lease->etag);
        $read = $this->adapter->get($leasePath)->content;
        self::assertSame($note->toString(), $read);
        self::assertSame([], Lease::fromNote(NoteDocument::parse($read))->consentDeadlines('2026-10-24'), 'agreed: no open consent deadline');
    }

    public function testPhotosGoNextToTheNotesViaWebDav(): void
    {
        $files = NextcloudFiles::connect($_ENV['NEXTCLOUD_URL'], $_ENV['NEXTCLOUD_USER'], $_ENV['NEXTCLOUD_APP_PASSWORD'], $this->adapter, $_ENV['NEXTCLOUD_FOLDER'] ?? 'Wohnpilot');
        $folder = 'live-test-' . bin2hex(random_bytes(3));
        $path = "anhaenge/$folder/foto.jpg";
        $bytes = "\xFF\xD8\xFF\xE0" . random_bytes(2048);

        try {
            $files->putFile($path, $bytes);
            self::assertSame($bytes, $files->getFile($path), 'same bytes back');
            $files->putFile($path, $bytes . 'x');
            self::assertSame($bytes . 'x', $files->getFile($path), 'replaced');

            $files->deleteFile($path);
            $files->deleteFile($path);
            $this->expectException(NotFoundException::class);
            $files->getFile($path);
        } finally {
            // Remove the empty test folder again (not part of AttachmentStore).
            $settings = $this->adapter->settings();
            $dav = new \GuzzleHttp\Client(['base_uri' => rtrim($_ENV['NEXTCLOUD_URL'], '/') . NextcloudFiles::DAV_PATH, 'auth' => [$_ENV['NEXTCLOUD_USER'], $_ENV['NEXTCLOUD_APP_PASSWORD']], 'http_errors' => false]);
            $dav->request('DELETE', implode('/', array_map('rawurlencode', [$settings['notesPath'], $_ENV['NEXTCLOUD_FOLDER'] ?? 'Wohnpilot', 'anhaenge', $folder])));
        }
    }

    public function testHouseEditsComeBackAsWritten(): void
    {
        $root = dirname(__DIR__, 3) . '/fixtures/Wohnpilot';
        $folder = '_test/' . bin2hex(random_bytes(3));

        // A status row and a new follow-up day, as DefectService::addStatus writes them
        $defectPath = $this->track("$folder/maengel/fenster-schlafzimmer.md");
        $defect = $this->adapter->create($defectPath, file_get_contents("$root/objekte/efh-musterstrasse/maengel/fenster-schlafzimmer.md"));
        $note = NoteDocument::parse($defect->content);
        HistoryTable::insertNewestFirst($note->body()->table(), 'Datum', ['Datum' => '2026-10-24', 'Status' => 'termin', 'Kanal' => 'Telefon', 'Notiz' => 'Fensterbauer am 03.11.']);
        $note->frontmatter()->set('nachfassen', '2026-11-04');
        $this->adapter->update($defectPath, $note->toString(), $defect->etag);
        self::assertSame($note->toString(), $this->adapter->get($defectPath)->content);
        self::assertSame('termin', \Wohnpilot\Domain\Defect::fromNote(NoteDocument::parse($note->toString()))->status());

        // A setting written into the block, as SettingsService does
        $householdPath = $this->track("$folder/haushalt.md");
        $household = $this->adapter->create($householdPath, file_get_contents("$root/_haushalt.md"));
        $note = NoteDocument::parse($household->content);
        $note->frontmatter()->setIn('einstellungen', 'paperless_url', 'https://paperless.example.org');
        $this->adapter->update($householdPath, $note->toString(), $household->etag);
        $read = $this->adapter->get($householdPath)->content;
        self::assertSame($note->toString(), $read);
        self::assertStringContainsString("  tonnen_selbst_rausstellen: false
  paperless_url: https://paperless.example.org
", $read);
    }

    public function testReadingWithPhotoAndADocumentComeBackAsWritten(): void
    {
        $root = dirname(__DIR__, 3) . '/fixtures/Wohnpilot';
        $folder = '_test/' . bin2hex(random_bytes(3));

        // A reading with its proof photo, as MeterService::addReading writes it
        $meterPath = $this->track("$folder/zaehler/strom-haupt.md");
        $meter = $this->adapter->create($meterPath, file_get_contents("$root/objekte/efh-musterstrasse/zaehler/strom-haupt.md"));
        $note = MeterNote::addReading(NoteDocument::parse($meter->content), new Reading('2026-10-25', 23731.4, 'regulär', 'anhaenge/zaehler/strom-haupt/2026-10-25-1.jpg'), 'live-test');
        $this->adapter->update($meterPath, $note->toString(), $meter->etag);
        $read = $this->adapter->get($meterPath)->content;
        self::assertSame($note->toString(), $read);
        self::assertSame('anhaenge/zaehler/strom-haupt/2026-10-25-1.jpg', Meter::fromNote(NoteDocument::parse($read))->latest()?->photo);

        // A PDF through WebDAV, byte for byte
        $files = NextcloudFiles::connect($_ENV['NEXTCLOUD_URL'], $_ENV['NEXTCLOUD_USER'], $_ENV['NEXTCLOUD_APP_PASSWORD'], $this->adapter, $_ENV['NEXTCLOUD_FOLDER'] ?? 'Wohnpilot');
        $dir = 'live-test-' . bin2hex(random_bytes(3));
        $pdf = "anhaenge/$dir/2026-10-25-brief.pdf";
        $bytes = "%PDF-1.4\n" . random_bytes(4096) . "\n%%EOF\n";
        try {
            $files->putFile($pdf, $bytes);
            self::assertSame($bytes, $files->getFile($pdf));
            $files->deleteFile($pdf);
        } finally {
            $settings = $this->adapter->settings();
            $dav = new \GuzzleHttp\Client(['base_uri' => rtrim($_ENV['NEXTCLOUD_URL'], '/') . NextcloudFiles::DAV_PATH, 'auth' => [$_ENV['NEXTCLOUD_USER'], $_ENV['NEXTCLOUD_APP_PASSWORD']], 'http_errors' => false]);
            $dav->request('DELETE', implode('/', array_map('rawurlencode', [$settings['notesPath'], $_ENV['NEXTCLOUD_FOLDER'] ?? 'Wohnpilot', 'anhaenge', $dir])));
        }
    }

    private function track(string $path): string
    {
        $this->created[] = $path;
        return $path;
    }

    public function testStaleEtagIsRejected(): void
    {
        $created = $this->adapter->create($this->path, "---\ntyp: test\nid: x\n---\nEins\n");
        $updated = $this->adapter->update($this->path, "---\ntyp: test\nid: x\n---\nZwei\n", $created->etag);
        self::assertNotSame($created->etag, $updated->etag);

        try {
            $this->adapter->update($this->path, "---\ntyp: test\nid: x\n---\nDrei\n", $created->etag);
            self::fail('Nextcloud accepted a write based on an old version');
        } catch (ConflictException $e) {
            self::assertSame("---\ntyp: test\nid: x\n---\nZwei\n", $e->current->content);
        }
    }

    public function testCreatedNoteKeepsItsPathInTheList(): void
    {
        $this->adapter->create($this->path, "---\ntyp: test\nid: x\n---\n");

        $paths = array_map(static fn ($i) => $i->path, $this->adapter->list());

        self::assertContains($this->path, $paths);
    }
}
