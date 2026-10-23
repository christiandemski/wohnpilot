<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Storage;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Wohnpilot\Storage\AlreadyExistsException;
use Wohnpilot\Storage\NextcloudNotesAdapter;
use Wohnpilot\Storage\StorageAdapter;
use Wohnpilot\Storage\StorageException;

final class NextcloudNotesAdapterTest extends StorageContractTestCase
{
    private FakeNotesServer $server;
    private int $meterId;
    private ?NextcloudNotesAdapter $adapter = null;

    protected function setUp(): void
    {
        $this->server = new FakeNotesServer();
        $this->server->add('Wohnpilot', '_haushalt', "---\ntyp: haushalt\nid: haushalt\n---\n");
        $this->meterId = $this->server->add('Wohnpilot/objekte/efh/zaehler', 'strom-haupt', self::METER_CONTENT);
        $this->server->add('Wohnpilot/objekte/efh/zaehler', 'Notiz mit Leerzeichen', 'keine Wohnpilot-Notiz');
        $this->server->add('Rezepte', 'strom-haupt', 'liegt außerhalb von Wohnpilot');
        $this->server->add('Wohnpilot-Archiv', 'alt', 'nur ähnlicher Name');
    }

    protected function adapter(): StorageAdapter
    {
        return $this->adapter ??= new NextcloudNotesAdapter($this->server->client());
    }

    protected function editElsewhere(string $content): void
    {
        $this->server->edit($this->meterId, $content);
    }

    public function testCreateSendsCategoryAndTitle(): void
    {
        $this->adapter()->create('objekte/efh/zaehler/gas.md', 'x');

        $post = array_values(array_filter($this->server->requests, static fn ($r) => $r->getMethod() === 'POST'))[0];
        self::assertSame(
            ['title' => 'gas', 'category' => 'Wohnpilot/objekte/efh/zaehler', 'content' => 'x'],
            json_decode((string) $post->getBody(), true),
        );
    }

    public function testUpdateSendsIfMatchAndOnlyContent(): void
    {
        $adapter = $this->adapter();
        $etag = $adapter->get(self::METER)->etag;

        $adapter->update(self::METER, 'neu', $etag);

        $put = array_values(array_filter($this->server->requests, static fn ($r) => $r->getMethod() === 'PUT'))[0];
        self::assertSame('"' . $etag . '"', $put->getHeaderLine('If-Match'));
        self::assertSame(['content' => 'neu'], json_decode((string) $put->getBody(), true));
    }

    public function testListRequestsNoContent(): void
    {
        $this->adapter()->list();

        self::assertSame('exclude=content', $this->server->requests[0]->getUri()->getQuery());
    }

    public function testRenamedDuplicateIsRemovedAgain(): void
    {
        // A file the list does not show blocks the title, so Nextcloud would answer with "gas (2)".
        $this->server->hiddenFromList[] = $this->server->add('Wohnpilot/objekte/efh/zaehler', 'gas', 'x');

        try {
            $this->adapter()->create('objekte/efh/zaehler/gas.md', 'neu');
            self::fail('Expected AlreadyExistsException');
        } catch (AlreadyExistsException) {
        }
        self::assertNotContains('gas (2)', array_column($this->server->notes, 'title'));
    }

    public function testRejectedCredentialsBecomeStorageException(): void
    {
        $adapter = new NextcloudNotesAdapter(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(401)]))]));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('rejected the credentials');
        $adapter->list();
    }

    public function testSettings(): void
    {
        $adapter = new NextcloudNotesAdapter($this->server->client());

        self::assertSame('Notizen', $adapter->settings()['notesPath']);
    }
}
