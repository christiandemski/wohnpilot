<?php

declare(strict_types=1);

namespace Wohnpilot\Storage;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Attachments in Nextcloud via WebDAV, in the same folder as the notes:
 * <notes folder>/Wohnpilot/anhaenge/…. The legacy endpoint
 * /remote.php/webdav/ always means the signed-in user, also if the login
 * is an e-mail address. The notes folder ("Notes", "Notizen") comes from
 * the Notes app settings.
 */
final class NextcloudFiles implements AttachmentStore
{
    public const DAV_PATH = '/remote.php/webdav/';

    /** @var array<string, true> folders known to exist */
    private array $folders = [];

    /** @param \Closure(): string $notesFolder the Notes app folder, asked only when needed */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly \Closure $notesFolder,
        private readonly string $rootCategory = 'Wohnpilot',
    ) {
    }

    public static function connect(string $baseUrl, string $user, string $appPassword, NextcloudNotesAdapter $notes, string $rootCategory = 'Wohnpilot'): self
    {
        return new self(
            new Client([
                'base_uri' => rtrim($baseUrl, '/') . self::DAV_PATH,
                RequestOptions::AUTH => [$user, $appPassword],
                RequestOptions::TIMEOUT => 60,
                RequestOptions::CONNECT_TIMEOUT => 5,
            ]),
            static fn (): string => (string) ($notes->settings()['notesPath'] ?? 'Notes'),
            trim($rootCategory, '/'),
        );
    }

    public function putFile(string $path, string $bytes): void
    {
        AttachmentPath::assertWritable($path);
        $this->ensureFolders(dirname($path));
        $this->request('PUT', $this->uri($path), [
            RequestOptions::BODY => $bytes,
            RequestOptions::HEADERS => ['Content-Type' => AttachmentPath::type($path) ?? 'application/octet-stream'],
        ]);
    }

    public function getFile(string $path): string
    {
        AttachmentPath::assertReadable($path);
        try {
            return (string) $this->request('GET', $this->uri($path))->getBody();
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                throw NotFoundException::forPath($path);
            }
            throw new StorageException('Nextcloud: ' . $e->getResponse()->getStatusCode());
        }
    }

    public function deleteFile(string $path): void
    {
        AttachmentPath::assertWritable($path);
        try {
            $this->request('DELETE', $this->uri($path));
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                throw new StorageException('Nextcloud: ' . $e->getResponse()->getStatusCode());
            }
        }
    }

    /** MKCOL from the top; 405 means the folder is there already. */
    private function ensureFolders(string $folder): void
    {
        $current = '';
        foreach (explode('/', $folder) as $segment) {
            $current = $current === '' ? $segment : "$current/$segment";
            if (isset($this->folders[$current])) {
                continue;
            }
            try {
                $this->request('MKCOL', $this->uri($current));
            } catch (ClientException $e) {
                if ($e->getResponse()->getStatusCode() !== 405) {
                    throw new StorageException("Nextcloud: cannot create folder $current (HTTP {$e->getResponse()->getStatusCode()})");
                }
            }
            $this->folders[$current] = true;
        }
    }

    private function uri(string $path): string
    {
        $segments = array_merge(explode('/', trim(($this->notesFolder)(), '/')), explode('/', $this->rootCategory), explode('/', $path));
        return implode('/', array_map('rawurlencode', array_filter($segments, static fn (string $s): bool => $s !== '')));
    }

    private function request(string $method, string $uri, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        try {
            return $this->http->request($method, $uri, $options);
        } catch (ClientException $e) {
            $status = $e->getResponse()->getStatusCode();
            if ($status === 401 || $status === 403) {
                throw new StorageException("Nextcloud rejected the credentials (HTTP $status).");
            }
            throw $e;
        } catch (GuzzleException $e) {
            throw new StorageException('Nextcloud is not reachable: ' . $e->getMessage(), 0);
        }
    }
}
