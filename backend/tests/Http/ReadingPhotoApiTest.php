<?php

declare(strict_types=1);

namespace Wohnpilot\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/** A reading with the photo of the meter as proof (today 2026-10-24). */
final class ReadingPhotoApiTest extends ApiTestCase
{
    private const STROM = 'objekte/efh-musterstrasse/zaehler/strom-haupt.md';
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00meter-photo";

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    /** As the app sends it: fields as text, the photo as file */
    private function read(array $fields, string $bytes): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/meters/strom-haupt/readings')
            ->withHeader('X-Requested-With', 'wohnpilot')
            ->withParsedBody($fields)
            ->withUploadedFiles(['foto' => new UploadedFile((new StreamFactory())->createStream($bytes), 'zaehler.jpg', 'image/jpeg', strlen($bytes))]);
        return $this->app->handle($request);
    }

    public function testPhotoLandsInTheFotoColumn(): void
    {
        $response = $this->read(['date' => '2026-10-24', 'value' => '23710.5', 'occasion' => 'Stichtag'], self::JPEG);

        self::assertSame(201, $response->getStatusCode());
        $path = 'anhaenge/zaehler/strom-haupt/2026-10-24-1.jpg';
        self::assertSame(self::JPEG, file_get_contents("$this->root/$path"));
        self::assertStringContainsString("| 2026-10-24 | 23710.5 | Stichtag | $path | christian |", file_get_contents("$this->root/" . self::STROM));
        $reading = self::decode($response)['readings'][0];
        self::assertSame([23710.5, $path], [$reading['value'], $reading['photo']]);

        $file = $this->request('GET', "/api/files/$path");
        self::assertSame(200, $file->getStatusCode());
    }

    public function testSecondPhotoOfTheSameDayGetsTheNextNumber(): void
    {
        $this->read(['date' => '2026-10-24', 'value' => '23710.5', 'occasion' => 'Stichtag'], self::JPEG);
        $this->read(['date' => '2026-10-24', 'value' => '23711', 'occasion' => 'Stichtag'], self::JPEG);

        self::assertFileExists("$this->root/anhaenge/zaehler/strom-haupt/2026-10-24-2.jpg");
    }

    public function testSendingTwiceKeepsOneRowAndOneFile(): void
    {
        $this->read(['date' => '2026-10-24', 'value' => '23710.5', 'occasion' => 'Stichtag'], self::JPEG);
        $again = $this->read(['date' => '2026-10-24', 'value' => '23710.5', 'occasion' => 'Stichtag'], self::JPEG);

        self::assertSame(201, $again->getStatusCode());
        self::assertSame(1, substr_count(file_get_contents("$this->root/" . self::STROM), '23710.5'));
        self::assertFileDoesNotExist("$this->root/anhaenge/zaehler/strom-haupt/2026-10-24-2.jpg");
    }

    public function testRejectsWhatIsNoPhotoAndWritesNothing(): void
    {
        $before = file_get_contents("$this->root/" . self::STROM);
        $response = $this->read(['date' => '2026-10-24', 'value' => '23710.5', 'occasion' => 'Stichtag'], '<?php echo 1;');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['foto' => 'not_an_image'], self::decode($response)['fields']);
        self::assertSame($before, file_get_contents("$this->root/" . self::STROM));
        self::assertDirectoryDoesNotExist("$this->root/anhaenge/zaehler");
    }

    public function testTableWithoutFotoColumnRefusesThePhoto(): void
    {
        $note = file_get_contents("$this->root/" . self::STROM);
        $note = str_replace(
            ["| Datum      | Stand   | Anlass  | Foto                         | Von       |", "|------------|---------|---------|------------------------------|-----------|"],
            ["| Datum      | Stand   | Anlass  | Von       |", "|------------|---------|---------|-----------|"],
            $note,
        );
        $note = preg_replace('/^(\| \d{4}-\d{2}-\d{2} \| [\d.]+ +\| \S+ +\|)[^|]*\|/m', '$1', $note);
        file_put_contents("$this->root/" . self::STROM, $note);

        $response = $this->read(['date' => '2026-10-24', 'value' => '23710.5', 'occasion' => 'Stichtag'], self::JPEG);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['foto' => 'no_column'], self::decode($response)['fields']);
    }
}
