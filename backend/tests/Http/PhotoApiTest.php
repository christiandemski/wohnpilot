<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Wohnpilot\Storage\FilesystemAdapter;

/** Photos of defects and devices: upload, show, remove (today 2026-10-24). */
final class PhotoApiTest extends ApiTestCase
{
    private const NOTE = 'objekte/efh-musterstrasse/maengel/fenster-schlafzimmer.md';
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00fake-photo-bytes";

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    private function upload(string $uri, string $bytes, string $alt = ''): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $uri)
            ->withHeader('X-Requested-With', 'wohnpilot')
            ->withParsedBody(['alt' => $alt])
            ->withUploadedFiles(['foto' => new UploadedFile((new StreamFactory())->createStream($bytes), 'foto.jpg', 'image/jpeg', strlen($bytes))]);
        return $this->app->handle($request);
    }

    public function testUploadAddsFileAndImageLine(): void
    {
        $response = $this->upload('/api/photos/mangel/fenster-schlafzimmer', self::JPEG, 'Rahmen nach dem Regen');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            [
                'anhaenge/maengel/fenster-schlafzimmer/2026-10-05-1.jpg',
                'anhaenge/maengel/fenster-schlafzimmer/2026-10-24-1.jpg',
            ],
            array_column(self::decode($response)['photos'], 'path'),
        );
        self::assertSame(self::JPEG, (new FilesystemAdapter($this->root))->getFile('anhaenge/maengel/fenster-schlafzimmer/2026-10-24-1.jpg'));
        self::assertStringEndsWith(
            "![Fenster Schlafzimmer, Rahmen unten links](../../../anhaenge/maengel/fenster-schlafzimmer/2026-10-05-1.jpg)\n![Rahmen nach dem Regen](../../../anhaenge/maengel/fenster-schlafzimmer/2026-10-24-1.jpg)\n",
            file_get_contents("$this->root/" . self::NOTE),
        );

        $second = self::decode($this->upload('/api/photos/mangel/fenster-schlafzimmer', self::JPEG));
        self::assertSame('anhaenge/maengel/fenster-schlafzimmer/2026-10-24-2.jpg', end($second['photos'])['path'], 'same day: next number');
    }

    public function testPhotoIsServedOnlyWhenSignedIn(): void
    {
        $this->upload('/api/photos/geraet/gastherme', self::JPEG);

        $file = $this->request('GET', '/api/files/anhaenge/geraete/gastherme/2026-10-24-1.jpg');
        self::assertSame(200, $file->getStatusCode());
        self::assertSame('image/jpeg', $file->getHeaderLine('Content-Type'));
        self::assertSame('private, max-age=86400', $file->getHeaderLine('Cache-Control'));
        self::assertSame(self::JPEG, (string) $file->getBody());

        self::assertSame(404, $this->request('GET', '/api/files/anhaenge/../../etc/passwd.jpg')->getStatusCode());
        self::assertSame(404, $this->request('GET', '/api/files/objekte/efh-musterstrasse/_objekt.md')->getStatusCode(), 'notes are no files');
        self::assertSame('no-store', $this->request('GET', '/api/overview')->getHeaderLine('Cache-Control'));

        $this->session->destroy();
        self::assertSame(401, $this->request('GET', '/api/files/anhaenge/geraete/gastherme/2026-10-24-1.jpg')->getStatusCode());
    }

    public function testRejectsWhatIsNoPhoto(): void
    {
        $text = $this->upload('/api/photos/mangel/fenster-schlafzimmer', '<?php echo 1;');
        self::assertSame(422, $text->getStatusCode());
        self::assertSame(['foto' => 'not_an_image'], self::decode($text)['fields']);
        self::assertFileDoesNotExist("$this->root/anhaenge/maengel/fenster-schlafzimmer/2026-10-24-1.jpg");

        self::assertSame(404, $this->upload('/api/photos/zaehler/strom-haupt', self::JPEG)->getStatusCode(), 'meters keep their photos in the readings');
        self::assertSame(404, $this->upload('/api/photos/mangel/gibt-es-nicht', self::JPEG)->getStatusCode());
    }

    public function testRemoveTakesLineAndFile(): void
    {
        $this->upload('/api/photos/mangel/fenster-schlafzimmer', self::JPEG);
        $before = file_get_contents("$this->root/" . self::NOTE);

        $response = $this->request('DELETE', '/api/photos/mangel/fenster-schlafzimmer?path=anhaenge/maengel/fenster-schlafzimmer/2026-10-24-1.jpg');

        self::assertSame(['anhaenge/maengel/fenster-schlafzimmer/2026-10-05-1.jpg'], array_column(self::decode($response)['photos'], 'path'));
        self::assertFileDoesNotExist("$this->root/anhaenge/maengel/fenster-schlafzimmer/2026-10-24-1.jpg");
        self::assertSame(str_replace("![](../../../anhaenge/maengel/fenster-schlafzimmer/2026-10-24-1.jpg)\n", '', $before), file_get_contents("$this->root/" . self::NOTE));
        self::assertSame(404, $this->request('DELETE', '/api/photos/mangel/fenster-schlafzimmer?path=anhaenge/fremd.jpg')->getStatusCode());
    }
}
