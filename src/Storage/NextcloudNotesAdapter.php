<?php

declare(strict_types=1);

namespace Wohnpilot\Storage;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * Notes in Nextcloud via the Notes API (v1).
 *
 * A path maps to a Notes category below the root category plus the note
 * title: "objekte/x/zaehler/strom.md" ↔ category "Wohnpilot/objekte/x/zaehler",
 * title "strom". Notes outside the root, or with titles that are no slug,
 * are not Wohnpilot notes and are ignored.
 */
final class NextcloudNotesAdapter implements StorageAdapter
{
    public const API_PATH = '/index.php/apps/notes/api/v1/';

    /** @var array<string, int>|null path => note id */
    private ?array $ids = null;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $rootCategory = 'Wohnpilot',
    ) {
    }

    public static function connect(string $baseUrl, string $user, string $appPassword, string $rootCategory = 'Wohnpilot'): self
    {
        return new self(new Client([
            'base_uri' => rtrim($baseUrl, '/') . self::API_PATH,
            RequestOptions::AUTH => [$user, $appPassword],
            RequestOptions::HEADERS => ['Accept' => 'application/json'],
            RequestOptions::TIMEOUT => 20,
            RequestOptions::CONNECT_TIMEOUT => 5,
        ]), trim($rootCategory, '/'));
    }

    /** Notes app settings, e.g. ['notesPath' => 'Notizen', 'fileSuffix' => '.md']. */
    public function settings(): array
    {
        return $this->json($this->request('GET', 'settings'));
    }

    public function list(): array
    {
        $notes = $this->json($this->request('GET', 'notes', ['query' => ['exclude' => 'content']]));
        $this->ids = [];
        $infos = [];
        foreach ($notes as $note) {
            $path = $this->pathOf($note);
            if ($path === null || isset($this->ids[$path])) {
                continue;
            }
            $this->ids[$path] = (int) $note['id'];
            $infos[] = new NoteInfo($path, (string) $note['etag'], (int) $note['modified']);
        }
        usort($infos, static fn (NoteInfo $a, NoteInfo $b): int => strcmp($a->path, $b->path));
        return $infos;
    }

    public function get(string $path): StoredNote
    {
        $id = $this->idOf($path) ?? throw NotFoundException::forPath($path);
        try {
            return $this->toStored($path, $this->json($this->request('GET', "notes/$id")));
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                unset($this->ids[$path]);
                throw NotFoundException::forPath($path);
            }
            throw $e;
        }
    }

    public function create(string $path, string $content): StoredNote
    {
        NotePath::assertValid($path);
        $this->ids = null;
        if ($this->idOf($path) !== null) {
            throw AlreadyExistsException::forPath($path);
        }
        $title = NotePath::name($path);
        $category = $this->categoryOf($path);
        $note = $this->json($this->request('POST', 'notes', ['json' => [
            'title' => $title,
            'category' => $category,
            'content' => $content,
        ]]));
        if (($note['title'] ?? null) !== $title || ($note['category'] ?? null) !== $category) {
            // Nextcloud renamed the note ("strom (2)") because a file was in the way.
            $this->request('DELETE', 'notes/' . (int) $note['id']);
            throw AlreadyExistsException::forPath($path);
        }
        $this->ids[$path] = (int) $note['id'];
        return $this->toStored($path, $note);
    }

    public function update(string $path, string $content, string $etag): StoredNote
    {
        $id = $this->idOf($path) ?? throw NotFoundException::forPath($path);
        try {
            $note = $this->json($this->request('PUT', "notes/$id", [
                'json' => ['content' => $content],
                'headers' => ['If-Match' => '"' . trim($etag, '"') . '"'],
            ]));
        } catch (ClientException $e) {
            $status = $e->getResponse()->getStatusCode();
            if ($status === 412) {
                throw new ConflictException($this->get($path));
            }
            if ($status === 404) {
                unset($this->ids[$path]);
                throw NotFoundException::forPath($path);
            }
            throw $e;
        }
        return $this->toStored($path, $note);
    }

    /** Not part of StorageAdapter yet; used to clean up after live tests. */
    public function delete(string $path): void
    {
        $id = $this->idOf($path) ?? throw NotFoundException::forPath($path);
        $this->request('DELETE', "notes/$id");
        unset($this->ids[$path]);
    }

    private function idOf(string $path): ?int
    {
        NotePath::assertValid($path);
        if ($this->ids === null || !isset($this->ids[$path])) {
            $this->list();
        }
        return $this->ids[$path] ?? null;
    }

    private function pathOf(array $note): ?string
    {
        $category = (string) ($note['category'] ?? '');
        if ($category === $this->rootCategory) {
            $folder = '';
        } elseif (str_starts_with($category, $this->rootCategory . '/')) {
            $folder = substr($category, strlen($this->rootCategory) + 1);
        } else {
            return null;
        }
        $path = NotePath::join($folder, (string) ($note['title'] ?? ''));
        return NotePath::isValid($path) ? $path : null;
    }

    private function categoryOf(string $path): string
    {
        $folder = NotePath::folder($path);
        return $folder === '' ? $this->rootCategory : $this->rootCategory . '/' . $folder;
    }

    private function toStored(string $path, array $note): StoredNote
    {
        return new StoredNote($path, (string) $note['content'], (string) $note['etag'], (int) $note['modified']);
    }

    private function request(string $method, string $uri, array $options = []): ResponseInterface
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
            // Message only: request details may carry the auth header.
            throw new StorageException('Nextcloud is not reachable: ' . $e->getMessage(), 0);
        }
    }

    private function json(ResponseInterface $response): array
    {
        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new StorageException('Unexpected answer from Nextcloud: ' . $e->getMessage());
        }
        return is_array($data) ? $data : [];
    }
}
