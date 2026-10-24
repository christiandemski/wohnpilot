<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Integration;

use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Domain\Contract;
use Wohnpilot\Domain\Lease;
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
