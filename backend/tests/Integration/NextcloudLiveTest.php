<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Integration;

use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Wohnpilot\Note\NoteDocument;
use Wohnpilot\Storage\ConflictException;
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
